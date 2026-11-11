<?php

/**
 * modules/sewa/proses_sewa.php
 * TAHAP 1 alur sewa "ambil di toko": membuat pesanan dan MENAHAN unit.
 * Tahap berikutnya (file terpisah): proses_bayar.php -> upload bukti bayar.
 *
 * KONTRAK FORM (form_sewa.php harus mengirim POST dengan field berikut):
 *   csrf_token     <?= csrf_field() ?>
 *   id_paket       token terenkripsi dari URL (?id_paket=...), kirim apa adanya
 *   tipe_sewa      'harian' | 'jam'
 *   durasi         angka. harian = jumlah hari; jam = kelipatan 12 (12, 24, 36, ...)
 *   mulai          format datetime-local, contoh 2026-11-05T10:00
 *   metode_terima  'ambil'   (pengantaran ke rumah belum didukung)
 * Field lain (total, harga, nama, hp, dst.) DIABAIKAN: harga dihitung server
 * dari tabel paket_sewa, nama dan nomor HP diambil dari akun.
 *
 * Hasil:
 *   sukses -> redirect ke pelanggan/pembayaran.php?kode=<kode terenkripsi>
 *   gagal  -> redirect ke form_sewa.php; pesan di $_SESSION['error_sewa'],
 *             isian lama di $_SESSION['old_sewa'] (form wajib membaca lalu unset).
 *
 * Paket combo: satu pesanan menahan SEMUA unit komponennya (mis. 1 PS4 + 1 TV),
 * diambil dari stok yang sama dengan sewa satuan (tabel penyewaan_unit).
 *
 * Lapis pengaman jadwal:
 *   1. kunci baris paket (paket yang dipesan + komponennya, urutan id naik)
 *   2. pencarian unit kosong pada rentang waktu itu (fungsi unit_kosong)
 *   3. batas pesanan yang belum lunas/diverifikasi per pelanggan
 *   4. exclusion constraint di database (migrasi 002), pengaman terakhir
 */

require_once __DIR__ . '/../../config/koneksi.php';
require_once __DIR__ . '/../../config/sewa.php';   // juga mendefinisikan class SewaGagal
require_once __DIR__ . '/../../includes/auth.php';

function sewa_hitung_biaya(string $tipe, int $durasi, int $h12, int $h24): int
{
    if ($tipe === 'harian') {
        return $h24 * $durasi;
    }
    $blok = intdiv($durasi, 12);
    return intdiv($blok, 2) * $h24 + ($blok % 2) * $h12;
}

function sewa_pg_waktu(DateTimeImmutable $dt): string
{
    return $dt->format('Y-m-d H:i:sP');
}

/**
 * Apakah jam $t berada dalam jam operasional cabang?
 * $buka / $tutup berformat 'HH:MM[:SS]' (kolom TIME); null = buka 24 jam.
 * Mendukung jam tutup lewat tengah malam (mis. 18:00-02:00).
 */
function sewa_dalam_jam_buka(DateTimeImmutable $t, ?string $buka, ?string $tutup): bool
{
    if ($buka === null || $tutup === null) {
        return true;
    }
    $ke_menit = static fn(string $hms): int => ((int)substr($hms, 0, 2)) * 60 + (int)substr($hms, 3, 2);

    $sekarang = ((int)$t->format('G')) * 60 + (int)$t->format('i');
    $b = $ke_menit($buka);
    $c = $ke_menit($tutup);

    if ($b === $c) {
        return true;                                   // dianggap 24 jam
    }
    return $b < $c
        ? ($sekarang >= $b && $sekarang < $c)
        : ($sekarang >= $b || $sekarang < $c);         // melewati tengah malam
}

/** Batalkan transaksi (jika ada), simpan pesan error + isian lama, kembali ke form. */
function sewa_kembali_dengan_error(PDO $pdo, string $pesan, array $old, string $url): void
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error_sewa'] = $pesan;
    $_SESSION['old_sewa']   = $old;
    header('Location: ' . $url);
    exit;
}

/* ============================== Pintu masuk ============================== */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../../index.php');
    exit;
}

require_role(['pelanggan']);
$idUser = (int)(current_user()['id_user'] ?? 0);

$tokenPaket = (string)($_POST['id_paket'] ?? '');
if (strlen($tokenPaket) > 200) {
    $tokenPaket = '';
}
$urlForm = $tokenPaket !== ''
    ? '../../pelanggan/form_sewa.php?id_paket=' . rawurlencode($tokenPaket)
    : '../../index.php';

$old = [
    'tipe_sewa' => substr((string)($_POST['tipe_sewa'] ?? ''), 0, 10),
    'durasi'    => substr((string)($_POST['durasi'] ?? ''), 0, 6),
    'mulai'     => substr((string)($_POST['mulai'] ?? ''), 0, 16),
];

try {
    if (!verify_csrf_token()) {
        throw new SewaGagal('Form sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }

    $idPaketRaw = decrypt_id($tokenPaket);
    if ($idPaketRaw === false || !ctype_digit((string)$idPaketRaw) || strlen((string)$idPaketRaw) > 9) {
        throw new SewaGagal('Link paket tidak valid. Silakan pilih ulang paket dari beranda.');
    }
    $idPaket = (int)$idPaketRaw;

    if (($_POST['metode_terima'] ?? '') !== 'ambil') {
        throw new SewaGagal('Saat ini hanya tersedia layanan ambil di toko. Pengantaran ke rumah belum dibuka.');
    }

    $tipeSewa = (string)($_POST['tipe_sewa'] ?? '');
    if (!in_array($tipeSewa, ['harian', 'jam'], true)) {
        throw new SewaGagal('Pilih tipe sewa harian atau per jam.');
    }

    $durasi = filter_var($_POST['durasi'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($durasi === false) {
        throw new SewaGagal('Durasi harus berupa angka bulat positif.');
    }
    if ($tipeSewa === 'harian') {
        if ($durasi > SEWA_MAKS_JAM / 24) {
            throw new SewaGagal('Sewa harian maksimal ' . (SEWA_MAKS_JAM / 24) . ' hari.');
        }
        $jamTotal = $durasi * 24;
    } else {
        if ($durasi % 12 !== 0 || $durasi > SEWA_MAKS_JAM) {
            throw new SewaGagal('Durasi per jam harus kelipatan 12 (12, 24, 36, ...) dan maksimal ' . SEWA_MAKS_JAM . ' jam.');
        }
        $jamTotal = $durasi;
    }

    $tz        = new DateTimeZone(SEWA_TZ);
    $mulaiRaw  = trim((string)($_POST['mulai'] ?? ''));
    $mulai     = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $mulaiRaw, $tz);
    if (!$mulai || $mulai->format('Y-m-d\TH:i') !== $mulaiRaw) {   // tolak tanggal tak valid (mis. 31 Feb)
        throw new SewaGagal('Tanggal dan waktu mulai sewa tidak valid.');
    }
    $sekarang = new DateTimeImmutable('now', $tz);
    if ($mulai < $sekarang->modify('-' . SEWA_TOLERANSI_MENIT . ' minutes')) {
        throw new SewaGagal('Waktu mulai sewa tidak boleh di masa lalu.');
    }
    if ($mulai > $sekarang->modify('+' . SEWA_MAKS_HARI_DEPAN . ' days')) {
        throw new SewaGagal('Booking maksimal ' . SEWA_MAKS_HARI_DEPAN . ' hari ke depan.');
    }

    $selesai    = $mulai->add(new DateInterval('PT' . $jamTotal . 'H'));
    $jenisTarif = (int)$mulai->format('N') >= 5 ? 'weekend' : 'weekday';   // Jumat-Minggu = weekend

    $pdo->query('SELECT batalkan_pesanan_kedaluwarsa()')->fetchColumn();

    $pdo->beginTransaction();

    // Kunci akun pelanggan: mencegah dua tab memesan bersamaan melewati batas pesanan.
    $stmt = $pdo->prepare('SELECT id_user FROM users WHERE id_user = :id AND is_active FOR NO KEY UPDATE');
    $stmt->execute(['id' => $idUser]);
    if (!$stmt->fetchColumn()) {
        throw new SewaGagal('Akun tidak ditemukan atau dinonaktifkan. Silakan login ulang.');
    }

    // Unit apa yang dibutuhkan paket ini (paket biasa = dirinya sendiri, combo = komponennya).
    $stmt = $pdo->prepare(
        'SELECT id_paket_unit, jumlah FROM v_kebutuhan_unit WHERE id_paket = :p ORDER BY id_paket_unit'
    );
    $stmt->execute(['p' => $idPaket]);
    $kebutuhan = $stmt->fetchAll();

    // Kunci baris paket yang dipesan + paket komponennya, SELALU urut id naik
    // (satu per satu, karena ORDER BY + FOR UPDATE tidak menjamin urutan kunci) agar tidak deadlock.
    $idKunci = [$idPaket];
    foreach ($kebutuhan as $k) {
        $idKunci[] = (int)$k['id_paket_unit'];
    }
    $idKunci = array_values(array_unique($idKunci));
    sort($idKunci);

    $kunci = $pdo->prepare('SELECT id_paket FROM paket_sewa WHERE id_paket = :id FOR NO KEY UPDATE');
    foreach ($idKunci as $idK) {
        $kunci->execute(['id' => $idK]);
        if ($kunci->fetchColumn() === false && $idK === $idPaket) {
            throw new SewaGagal('Paket tidak ditemukan.');
        }
    }

    $stmt = $pdo->prepare(
        'SELECT p.id_paket, p.id_cabang, p.override_status,
                ROUND(p.harga_wk_12h)::int AS wk12, ROUND(p.harga_wk_24h)::int AS wk24,
                ROUND(p.harga_wn_12h)::int AS wn12, ROUND(p.harga_wn_24h)::int AS wn24,
                c.jam_buka, c.jam_tutup
           FROM paket_sewa p
           JOIN cabang c ON c.id_cabang = p.id_cabang
          WHERE p.id_paket = :id AND p.is_active AND c.is_active'
    );
    $stmt->execute(['id' => $idPaket]);
    $paket = $stmt->fetch();
    if (!$paket) {
        throw new SewaGagal('Paket tidak ditemukan atau sedang tidak tersedia.');
    }
    if ($paket['override_status'] !== 'auto') {
        throw new SewaGagal('Paket ini sedang tidak tersedia (maintenance).');
    }
    if (!$kebutuhan) {
        throw new SewaGagal('Paket ini belum dikonfigurasi lengkap. Hubungi pengelola.');
    }
    if (!sewa_dalam_jam_buka($mulai, $paket['jam_buka'], $paket['jam_tutup'])) {
        throw new SewaGagal(
            'Jam mulai sewa di luar jam operasional toko (' .
            substr((string)$paket['jam_buka'], 0, 5) . ' - ' . substr((string)$paket['jam_tutup'], 0, 5) . ').'
        );
    }

    // Kirim ulang form yang sama (klik dua kali / refresh) -> pakai pesanan yang sudah ada.
    $stmt = $pdo->prepare(
        "SELECT id_sewa FROM penyewaan
          WHERE id_user = :u AND id_paket = :p AND tipe_sewa = :t AND durasi = :d
            AND waktu_mulai = CAST(:m AS timestamptz) AND status_sewa = 'menunggu'
          LIMIT 1"
    );
    $stmt->execute(['u' => $idUser, 'p' => $idPaket, 't' => $tipeSewa, 'd' => $durasi, 'm' => sewa_pg_waktu($mulai)]);
    $kode = $stmt->fetchColumn();

    if ($kode === false) {
        // Batas pesanan yang belum "selesai urusan pembayarannya". Pesanan COD (bayar di toko)
        // ikut dihitung, supaya satu akun tidak bisa menahan banyak unit tanpa membayar.
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM penyewaan p
              WHERE p.id_user = :u AND p.status_sewa = 'menunggu'
                AND NOT EXISTS (
                    SELECT 1 FROM pembayaran b
                     WHERE b.id_sewa = p.id_sewa
                       AND b.status_bayar IN ('menunggu_verifikasi', 'lunas')
                )"
        );
        $stmt->execute(['u' => $idUser]);
        if ((int)$stmt->fetchColumn() >= SEWA_MAKS_BELUM_BAYAR) {
            throw new SewaGagal(
                'Anda masih punya ' . SEWA_MAKS_BELUM_BAYAR . ' pesanan yang belum dibayar atau masih menunggu pembayaran di toko. ' .
                'Selesaikan dulu, atau tunggu hingga kedaluwarsa.'
            );
        }

        $h12 = (int)$paket[$jenisTarif === 'weekend' ? 'wn12' : 'wk12'];
        $h24 = (int)$paket[$jenisTarif === 'weekend' ? 'wn24' : 'wk24'];
        if ($h12 <= 0 || $h24 <= 0) {
            throw new SewaGagal('Tarif paket ini belum diatur. Hubungi pengelola.');
        }
        $biaya = sewa_hitung_biaya($tipeSewa, $durasi, $h12, $h24);

        // Cari unit kosong untuk SETIAP komponen. Kurang satu saja -> seluruh pesanan gagal.
        $stmt = $pdo->prepare(
            'SELECT id_unit FROM unit_kosong(:p, CAST(:m AS timestamptz), CAST(:s AS timestamptz))'
        );
        $alokasi = [];
        foreach ($kebutuhan as $k) {
            $stmt->execute([
                'p' => (int)$k['id_paket_unit'],
                'm' => sewa_pg_waktu($mulai),
                's' => sewa_pg_waktu($selesai),
            ]);
            $bebas  = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $jumlah = (int)$k['jumlah'];
            if (count($bebas) < $jumlah) {
                throw new SewaGagal('Maaf, unit belum tersedia pada jadwal tersebut. Coba ganti tanggal, jam, atau durasi.');
            }
            foreach (array_slice($bebas, 0, $jumlah) as $idUnit) {
                $alokasi[] = (int)$idUnit;
            }
        }

        $stmt = $pdo->prepare(
            "INSERT INTO penyewaan
                (id_user, id_cabang, id_paket, tipe_layanan, tipe_sewa, durasi, jenis_tarif,
                 waktu_mulai, waktu_kembali_rencana, harga_12h_snapshot, harga_24h_snapshot,
                 biaya_sewa, total_biaya, status_sewa, batas_bayar)
             VALUES
                (:u, :c, :p, 'pickup', :t, :d, :jt,
                 CAST(:m AS timestamptz), CAST(:s AS timestamptz), :h12, :h24,
                 :biaya, :total, 'menunggu', CAST(:batas AS timestamptz))
             RETURNING id_sewa"
        );
        $stmt->execute([
            'u'     => $idUser,
            'c'     => (int)$paket['id_cabang'],
            'p'     => $idPaket,
            't'     => $tipeSewa,
            'd'     => $durasi,
            'jt'    => $jenisTarif,
            'm'     => sewa_pg_waktu($mulai),
            's'     => sewa_pg_waktu($selesai),
            'h12'   => $h12,
            'h24'   => $h24,
            'biaya' => $biaya,
            'total' => $biaya,
            'batas' => sewa_pg_waktu($sekarang->modify('+' . SEWA_BATAS_BAYAR_MENIT . ' minutes')),
        ]);
        $kode = $stmt->fetchColumn();

        // Catat unit yang ditahan (jadwal & status disalin otomatis oleh trigger database).
        $stmt = $pdo->prepare('INSERT INTO penyewaan_unit (id_sewa, id_unit) VALUES (:k, :u)');
        foreach ($alokasi as $idUnit) {
            $stmt->execute(['k' => $kode, 'u' => $idUnit]);
        }
    }

    $pdo->commit();

    unset($_SESSION['error_sewa'], $_SESSION['old_sewa']);
    header('Location: ../../pelanggan/pembayaran.php?kode=' . rawurlencode(encrypt_id($kode)));
    exit;
} catch (SewaGagal $e) {
    sewa_kembali_dengan_error($pdo, $e->getMessage(), $old, $urlForm);
} catch (PDOException $e) {
    $sqlState = (string)($e->errorInfo[0] ?? $e->getCode());
    error_log('proses_sewa gagal [' . $sqlState . ']: ' . $e->getMessage());   // detail hanya ke log server

    $pesan = match ($sqlState) {
        '23P01'        => 'Unit baru saja dipesan orang lain. Silakan coba lagi atau pilih jadwal lain.',
        '40001', '40P01' => 'Server sedang melayani banyak pesanan. Silakan coba lagi.',
        default        => 'Terjadi gangguan sistem. Silakan coba lagi beberapa saat.',
    };
    sewa_kembali_dengan_error($pdo, $pesan, $old, $urlForm);
} catch (Throwable $e) {
    error_log('proses_sewa error tak terduga: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    sewa_kembali_dengan_error($pdo, 'Terjadi gangguan sistem. Silakan coba lagi beberapa saat.', $old, $urlForm);
}x
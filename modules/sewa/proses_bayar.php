<?php
require_once __DIR__ . '/../../config/koneksi.php';
require_once __DIR__ . '/../../config/sewa.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/bukti.php';

const BAYAR_MAKS_UNGGAH_USER = 10;    
const BAYAR_MAKS_UNGGAH_IP   = 30;    
const BAYAR_JENDELA_DETIK    = 600;  
function bayar_kode_dari_referer(): string
{
    $p = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    $hostSaya = parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    if (!$p || ($p['host'] ?? null) !== $hostSaya) {
        return '';
    }
    if (substr((string) ($p['path'] ?? ''), -strlen('/pelanggan/pembayaran.php')) !== '/pelanggan/pembayaran.php') {
        return '';
    }
    parse_str((string) ($p['query'] ?? ''), $q);
    return is_string($q['kode'] ?? null) ? $q['kode'] : '';
}

function bayar_bersihkan(string $s): string
{
    return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $s)));
}

function bayar_audit(PDO $pdo, int $idUser, string $aksi, string $kode, array $detail): void
{
    try {
        $pdo->prepare(
            "INSERT INTO audit_log (id_user, aksi, tabel, id_target, detail, ip)
             VALUES (:u, :a, 'pembayaran', :t, CAST(:d AS jsonb), :ip)"
        )->execute([
            'u'  => $idUser,
            'a'  => $aksi,
            't'  => $kode,
            'd'  => json_encode($detail, JSON_UNESCAPED_UNICODE),
            'ip' => client_ip(),
        ]);
    } catch (Throwable $e) {
        error_log('bayar_audit gagal: ' . $e->getMessage());
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../../index.php');
    exit;
}

require_role(['pelanggan']);
$idUser = (int) (current_user()['id_user'] ?? 0);

$ambil = static fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';

$terlaluBesar = empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
$tokenKode    = $terlaluBesar ? bayar_kode_dari_referer() : $ambil('kode');
if (strlen($tokenKode) > 200) {
    $tokenKode = '';
}
$urlBayar = $tokenKode !== ''
    ? '../../pelanggan/pembayaran.php?kode=' . rawurlencode($tokenKode)
    : '../../index.php';

$old = [
    'metode'        => substr($ambil('metode'), 0, 10),
    'nama_pengirim' => mb_substr($ambil('nama_pengirim'), 0, 100),
    'bank_asal'     => mb_substr($ambil('bank_asal'), 0, 50),
];

$fileBaru = null;   
$fileLama = null;   

try {
    if ($terlaluBesar) {
        throw new SewaGagal('Data yang dikirim terlalu besar. Gunakan gambar yang lebih kecil (maksimal '
            . (int) (SEWA_MAKS_BUKTI_BYTE / 1048576) . ' MB).');
    }
    if (!verify_csrf_token()) {
        throw new SewaGagal('Form sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }

    $kode = decrypt_id($tokenKode);
    if ($kode === false || !preg_match('/^P2B-\d{8}-\d{4,}$/', (string) $kode)) {
        throw new SewaGagal('Link pesanan tidak valid. Buka kembali dari halaman pesanan Anda.');
    }

    $metode = $ambil('metode');
    if (!in_array($metode, ['transfer', 'qris', 'cod'], true)) {
        throw new SewaGagal('Pilih metode pembayaran.');
    }
    $perluBukti = $metode !== 'cod';

    $namaPengirim = $bankAsal = null;
    if ($perluBukti) {
        $namaPengirim = bayar_bersihkan($ambil('nama_pengirim'));
        if (mb_strlen($namaPengirim) < 2 || mb_strlen($namaPengirim) > 100) {
            throw new SewaGagal('Isi nama pemilik rekening / akun pengirim (2-100 karakter).');
        }
        $bankAsal = bayar_bersihkan($ambil('bank_asal'));
        if (mb_strlen($bankAsal) > 50) {
            throw new SewaGagal('Nama bank / e-wallet maksimal 50 karakter.');
        }
        $bankAsal = $bankAsal === '' ? null : $bankAsal;

        if (is_array($_FILES['bukti']['error'] ?? null)) {
            throw new SewaGagal('Unggahan tidak valid. Pilih satu berkas bukti.');
        }
    }

    $pdo->query('SELECT batalkan_pesanan_kedaluwarsa()')->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT id_cabang FROM penyewaan WHERE id_sewa = :k AND id_user = :u AND status_sewa = 'menunggu'"
    );
    $stmt->execute(['k' => $kode, 'u' => $idUser]);
    $idCabang = $stmt->fetchColumn();
    if ($idCabang === false) {
        throw new SewaGagal('Pesanan tidak ditemukan atau sudah tidak dapat dibayar.');
    }

    $bukti = null;
    if ($perluBukti) {
        $stmt = $pdo->prepare('SELECT 1 FROM rekening_pembayaran WHERE id_cabang = :c AND jenis = :j AND is_active LIMIT 1');
        $stmt->execute(['c' => (int) $idCabang, 'j' => $metode]);
        if (!$stmt->fetchColumn()) {
            throw new SewaGagal('Metode pembayaran ini belum tersedia. Silakan pilih metode lain atau bayar di toko.');
        }

        $kUser = 'user:' . $idUser;
        $kIp   = 'ip:' . client_ip();
        if (
            rate_limited($pdo, 'upload', $kUser, BAYAR_MAKS_UNGGAH_USER, BAYAR_JENDELA_DETIK, false)
            || rate_limited($pdo, 'upload', $kIp, BAYAR_MAKS_UNGGAH_IP, BAYAR_JENDELA_DETIK, false)
        ) {
            throw new SewaGagal('Terlalu banyak percobaan unggah. Coba lagi beberapa menit lagi.');
        }
        rate_limit_hit($pdo, 'upload', $kUser);
        rate_limit_hit($pdo, 'upload', $kIp);

        $bukti    = bukti_simpan($_FILES['bukti'] ?? []);
        $fileBaru = $bukti['nama'];
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT status_sewa, alasan_penolakan, total_biaya,
                (batas_bayar IS NOT NULL AND batas_bayar < now()) AS lewat
           FROM penyewaan WHERE id_sewa = :k AND id_user = :u FOR NO KEY UPDATE"
    );
    $stmt->execute(['k' => $kode, 'u' => $idUser]);
    $pesanan = $stmt->fetch();
    if (!$pesanan) {
        throw new SewaGagal('Pesanan tidak ditemukan.');
    }
    if ($pesanan['status_sewa'] !== 'menunggu') {
        throw new SewaGagal(in_array($pesanan['status_sewa'], ['ditolak', 'dibatalkan'], true)
            ? 'Pesanan ini sudah dibatalkan: ' . ($pesanan['alasan_penolakan'] ?: 'tanpa keterangan') . '.'
            : 'Pesanan ini sudah diproses dan tidak dapat diubah lagi.');
    }

    $stmt = $pdo->prepare('SELECT id_pembayaran, status_bayar, file_bukti FROM pembayaran WHERE id_sewa = :k FOR UPDATE');
    $stmt->execute(['k' => $kode]);
    $ada = $stmt->fetch();
    if ($ada && in_array($ada['status_bayar'], ['menunggu_verifikasi', 'lunas'], true)) {
        throw new SewaGagal('Bukti pembayaran untuk pesanan ini sudah dikirim dan sedang diperiksa operator.');
    }
    if ($pesanan['lewat']) {
        throw new SewaGagal('Batas waktu pembayaran sudah habis. Silakan buat pesanan baru.');
    }

    $tagihan = (int) round((float) $pesanan['total_biaya']);

    if ($metode === 'cod') {
        if ($ada) {
            $fileLama = $ada['file_bukti'];
            $pdo->prepare(
                "UPDATE pembayaran SET metode_pembayaran = 'cod', nama_pengirim = NULL, bank_asal = NULL,
                        file_bukti = NULL, bukti_hash = NULL, total_tagihan = :t, status_bayar = 'belum_bayar',
                        waktu_bayar = NULL, id_operator_verifikasi = NULL, catatan_verifikasi = NULL
                  WHERE id_pembayaran = :id"
            )->execute(['t' => $tagihan, 'id' => $ada['id_pembayaran']]);
        } else {
            $pdo->prepare(
                "INSERT INTO pembayaran (id_sewa, metode_pembayaran, total_tagihan, status_bayar)
                 VALUES (:k, 'cod', :t, 'belum_bayar')"
            )->execute(['k' => $kode, 't' => $tagihan]);
        }
        $pdo->prepare(
            "UPDATE penyewaan SET batas_bayar = waktu_mulai + (CAST(:m AS int) * interval '1 minute') WHERE id_sewa = :k"
        )->execute(['m' => SEWA_TOLERANSI_COD_MENIT, 'k' => $kode]);
    } else {
        $stmt = $pdo->prepare('SELECT 1 FROM pembayaran WHERE bukti_hash = :h AND id_sewa <> :k LIMIT 1');
        $stmt->execute(['h' => $bukti['hash'], 'k' => $kode]);
        if ($stmt->fetchColumn()) {
            throw new SewaGagal('Bukti ini sudah pernah dipakai untuk pesanan lain. Unggah bukti pembayaran yang sesuai dengan pesanan ini.');
        }

        if ($ada) {
            $fileLama = $ada['file_bukti'];
            $pdo->prepare(
                "UPDATE pembayaran SET metode_pembayaran = :m, nama_pengirim = :n, bank_asal = :b, file_bukti = :f,
                        bukti_hash = :h, total_tagihan = :t, status_bayar = 'menunggu_verifikasi', waktu_bayar = now(),
                        id_operator_verifikasi = NULL, catatan_verifikasi = NULL
                  WHERE id_pembayaran = :id"
            )->execute([
                'm' => $metode,
                'n' => $namaPengirim,
                'b' => $bankAsal,
                'f' => $bukti['nama'],
                'h' => $bukti['hash'],
                't' => $tagihan,
                'id' => $ada['id_pembayaran'],
            ]);
        } else {
            $pdo->prepare(
                "INSERT INTO pembayaran (id_sewa, metode_pembayaran, nama_pengirim, bank_asal, file_bukti,
                                         bukti_hash, total_tagihan, status_bayar, waktu_bayar)
                 VALUES (:k, :m, :n, :b, :f, :h, :t, 'menunggu_verifikasi', now())"
            )->execute([
                'k' => $kode,
                'm' => $metode,
                'n' => $namaPengirim,
                'b' => $bankAsal,
                'f' => $bukti['nama'],
                'h' => $bukti['hash'],
                't' => $tagihan,
            ]);
        }
    }

    $pdo->commit();

    if ($fileLama !== null && $fileLama !== $fileBaru) {
        bukti_hapus($fileLama);
    }
    $fileBaru = null;

    bayar_audit($pdo, $idUser, $metode === 'cod' ? 'pilih_bayar_di_toko' : 'kirim_bukti_bayar', (string) $kode, [
        'metode'  => $metode,
        'tagihan' => $tagihan,
    ]);

    unset($_SESSION['error_bayar'], $_SESSION['old_bayar']);
    $_SESSION['info_bayar'] = $metode === 'cod'
        ? 'Pilihan bayar di toko tersimpan. Datang sesuai jadwal dan bayar tunai ke operator.'
        : 'Bukti pembayaran terkirim. Operator akan memeriksanya sebentar lagi.';
    header('Location: ' . $urlBayar);
    exit;
} catch (SewaGagal $e) {
    $pesan = $e->getMessage();
} catch (PDOException $e) {
    $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
    if ($sqlState === '23505') {
        $pesan = str_contains($e->getMessage(), 'bukti_hash')
            ? 'Bukti ini sudah pernah dipakai untuk pesanan lain. Unggah bukti pembayaran yang sesuai dengan pesanan ini.'
            : 'Pembayaran untuk pesanan ini sudah dikirim.';
    } else {
        error_log('proses_bayar gagal [' . $sqlState . ']: ' . $e->getMessage());
        $pesan = 'Pembayaran gagal diproses karena gangguan sistem. Silakan coba lagi.';
    }
} catch (Throwable $e) {
    error_log('proses_bayar error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $pesan = 'Pembayaran gagal diproses karena gangguan sistem. Silakan coba lagi.';
}

if ($pdo->inTransaction()) {
    $pdo->rollBack();
}
if ($fileBaru !== null) {
    bukti_hapus($fileBaru);
}
$_SESSION['error_bayar'] = $pesan;
$_SESSION['old_bayar']   = $old;
header('Location: ' . $urlBayar);
exit;

<?php

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/sewa.php';
require_once __DIR__ . '/../includes/auth.php';

require_role(['pelanggan']);
$idUser = (int) (current_user()['id_user'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!verify_csrf_token()) {
            throw new SewaGagal('Form sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.');
        }
        if (($_POST['action'] ?? '') !== 'batal') {
            throw new SewaGagal('Aksi tidak dikenal.');
        }
        $token = is_string($_POST['kode'] ?? null) && strlen($_POST['kode']) <= 200 ? $_POST['kode'] : '';
        $kode  = decrypt_id($token);
        if ($kode === false || !preg_match('/^[A-Z0-9]{2,5}-\d{8}-\d{4,}$/', (string) $kode)) {
            throw new SewaGagal('Pesanan tidak valid.');
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT s.status_sewa, b.status_bayar
               FROM penyewaan s
               LEFT JOIN pembayaran b ON b.id_sewa = s.id_sewa
              WHERE s.id_sewa = :k AND s.id_user = :u
                FOR UPDATE OF s'
        );
        $stmt->execute(['k' => $kode, 'u' => $idUser]);
        $baris = $stmt->fetch();
        if (!$baris) {
            throw new SewaGagal('Pesanan tidak ditemukan.');
        }
        if ($baris['status_sewa'] !== 'menunggu') {
            throw new SewaGagal('Pesanan ini sudah diproses dan tidak bisa dibatalkan dari sini. Hubungi toko.');
        }
        if (in_array($baris['status_bayar'], ['menunggu_verifikasi', 'lunas'], true)) {
            throw new SewaGagal('Pembayaran sudah dikirim. Hubungi toko bila ingin membatalkan.');
        }
        $pdo->prepare(
            "UPDATE penyewaan SET status_sewa = 'dibatalkan', alasan_penolakan = 'Dibatalkan oleh pelanggan', batas_bayar = NULL
              WHERE id_sewa = :k"
        )->execute(['k' => $kode]);
        $pdo->commit();

        try {
            $pdo->prepare(
                "INSERT INTO audit_log (id_user, aksi, tabel, id_target, ip) VALUES (:u, 'batal_pelanggan', 'penyewaan', :t, :ip)"
            )->execute(['u' => $idUser, 't' => (string) $kode, 'ip' => client_ip()]);
        } catch (Throwable $e) {
            error_log('Pesanan: gagal mencatat audit: ' . $e->getMessage());
        }
        $_SESSION['info_pesanan'] = 'Pesanan dibatalkan. Unit sudah dilepas kembali.';
    } catch (SewaGagal $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_pesanan'] = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Pesanan: pembatalan gagal: ' . $e->getMessage());
        $_SESSION['error_pesanan'] = 'Pembatalan gagal karena gangguan sistem. Silakan coba lagi.';
    }
    header('Location: Pesanan.php');
    exit;
}

$pdo->query('SELECT batalkan_pesanan_kedaluwarsa()')->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT s.id_sewa, s.id_paket, s.tipe_layanan, s.tipe_sewa, s.durasi, s.jenis_tarif,
            s.waktu_mulai, s.waktu_kembali_rencana, s.waktu_kembali_aktual, s.batas_bayar, s.created_at,
            s.total_biaya, s.status_sewa, s.alasan_penolakan, s.jenis_jaminan,
            CASE WHEN s.batas_bayar IS NULL THEN NULL
                 ELSE GREATEST(0, EXTRACT(EPOCH FROM (s.batas_bayar - now())))::int END AS sisa_bayar,
            p.nama_paket, p.kategori, p.gambar,
            c.nama_cabang, c.alamat,
            b.status_bayar, b.metode_pembayaran, b.file_bukti, b.catatan_verifikasi,
            d.keterlambatan_jam, d.total_denda, d.status_denda,
            (SELECT l.kondisi_unit FROM log_serah_terima l
              WHERE l.id_sewa = s.id_sewa AND l.jenis_log = 'pengembalian'
              ORDER BY l.id_log DESC LIMIT 1) AS kondisi_kembali
       FROM penyewaan s
       JOIN paket_sewa p ON p.id_paket = s.id_paket
       JOIN cabang c     ON c.id_cabang = s.id_cabang
       LEFT JOIN pembayaran b ON b.id_sewa = s.id_sewa
       LEFT JOIN denda d      ON d.id_sewa = s.id_sewa
      WHERE s.id_user = :u
      ORDER BY s.created_at DESC
      LIMIT 200"
);
$stmt->execute(['u' => $idUser]);
$pesanan = $stmt->fetchAll();

$unitPer = [];
if ($pesanan) {
    $daftar = '{' . implode(',', array_map(static fn($r) => '"' . $r['id_sewa'] . '"', $pesanan)) . '}';
    $stmt = $pdo->prepare(
        'SELECT pu.id_sewa, un.kode_barcode, un.nama_unit_ruang
           FROM penyewaan_unit pu
           JOIN unit_ps un ON un.id_unit = pu.id_unit
          WHERE pu.id_sewa = ANY(CAST(:ids AS varchar[]))
          ORDER BY pu.id_sewa, un.id_unit'
    );
    $stmt->execute(['ids' => $daftar]);
    foreach ($stmt->fetchAll() as $u) {
        $unitPer[$u['id_sewa']][] = $u['nama_unit_ruang'] . ' (' . $u['kode_barcode'] . ')';
    }
}

$info  = (string) ($_SESSION['info_pesanan'] ?? '');
$error = (string) ($_SESSION['error_pesanan'] ?? '');
unset($_SESSION['info_pesanan'], $_SESSION['error_pesanan']);

$bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
$waktu = static function (?string $s): ?DateTimeImmutable {
    if ($s === null || $s === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($s))->setTimezone(new DateTimeZone(SEWA_TZ));
    } catch (Exception $e) {
        return null;
    }
};
$tgl = static function (?string $s) use ($waktu, $bulan): string {
    $d = $waktu($s);
    return $d ? $d->format('d') . ' ' . $bulan[(int) $d->format('n') - 1] . ' ' . $d->format('Y, H:i') . ' WIB' : '-';
};
$tglPendek = static function (?string $s) use ($waktu, $bulan): string {
    $d = $waktu($s);
    return $d ? $d->format('d') . ' ' . $bulan[(int) $d->format('n') - 1] . ' ' . $d->format('Y') : '-';
};
$rp = static fn($n): string => 'Rp ' . number_format((float) $n, 0, ',', '.');

$gambarBawaan = ['ps3' => 'ps3.png', 'ps4' => 'ps4.png', 'ps5' => 'ps5-banner.png', 'combo' => 'paket-ps4.png', 'tv' => 'tv-43.png'];
$srcGambar = static function (array $r) use ($gambarBawaan): string {
    $berkas = basename(str_replace('\\', '/', trim((string) ($r['gambar'] ?? ''))));
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $berkas)) {
        $berkas = $gambarBawaan[$r['kategori']] ?? 'ps4.png';
    }
    return '../assets/img/' . $berkas;
};

$layananLabel = ['pickup' => 'Ambil di Toko', 'ditempat' => 'Main di Tempat', 'delivery' => 'Diantar ke Rumah'];
$layananIkon  = ['pickup' => 'bi-shop', 'ditempat' => 'bi-controller', 'delivery' => 'bi-truck'];
$metodeLabel  = ['transfer' => 'Transfer', 'qris' => 'QRIS', 'cod' => 'Bayar di Toko', 'cash' => 'Tunai'];
$kondisiLabel = ['baik' => 'Unit kembali baik', 'maint' => 'Perlu maintenance', 'rusak' => 'Unit rusak'];

$bayarBadge = static function (?string $status, ?string $metode): array {
    return match ($status) {
        'lunas'               => ['Lunas', 'bg-success-subtle text-success border border-success-subtle'],
        'menunggu_verifikasi' => ['Menunggu Verifikasi', 'bg-warning-subtle text-warning-emphasis border border-warning-subtle'],
        'ditolak'             => ['Bukti Ditolak', 'bg-danger-subtle text-danger border border-danger-subtle'],
        default               => $metode === 'cod'
            ? ['Bayar di Toko', 'bg-info-subtle text-info-emphasis border border-info-subtle']
            : ['Belum Dibayar', 'bg-secondary-subtle text-secondary border'],
    };
};

$aktif = $selesai = $batal = [];
foreach ($pesanan as $r) {
    if (in_array($r['status_sewa'], ['menunggu', 'siap', 'aktif', 'diantar', 'sampai'], true)) {
        $aktif[] = $r;
    } elseif ($r['status_sewa'] === 'selesai') {
        $selesai[] = $r;
    } else {
        $batal[] = $r;
    }
}
$riwayat = array_merge($selesai, $batal);
usort($riwayat, static fn($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

$pageTitle = 'PS Rental - Pesanan';
$footerLibs = ['jsbarcode'];   // tidak butuh DataTables
include __DIR__ . '/../includes/header.php';
?>
<div class="app-card">
  <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start gap-3 mb-4">
    <div>
      <h3 class="fw-bold text-dark mb-1">Pesanan Saya</h3>
      <p class="text-muted small mb-0">Pantau status pembayaran, waktu sewa yang berjalan, dan riwayat peminjaman.</p>
    </div>
    <a href="<?= e($base) ?>index.php" class="btn btn-primary btn-sm px-4 py-2 rounded-pill fw-semibold shadow-sm">
      <i class="bi bi-plus-lg me-1"></i> Sewa Unit Lagi
    </a>
  </div>

  <?php if ($info !== ''): ?>
    <div class="alert alert-success d-flex gap-2" role="alert"><i class="bi bi-check-circle-fill"></i><div><?= e($info) ?></div></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-danger d-flex gap-2" role="alert"><i class="bi bi-exclamation-triangle-fill"></i><div><?= e($error) ?></div></div>
  <?php endif; ?>

  <div class="d-flex flex-wrap gap-2" id="tabPesanan">
    <button type="button" class="btn btn-primary btn-sm px-3 rounded-pill fw-semibold" data-tab="all">Semua Pesanan (<?= count($pesanan) ?>)</button>
    <button type="button" class="btn bg-light border text-secondary btn-sm px-3 rounded-pill" data-tab="active">Sedang Berlangsung (<?= count($aktif) ?>)</button>
    <button type="button" class="btn bg-light border text-secondary btn-sm px-3 rounded-pill" data-tab="done">Selesai (<?= count($selesai) ?>)</button>
    <button type="button" class="btn bg-light border text-secondary btn-sm px-3 rounded-pill" data-tab="cancel">Dibatalkan (<?= count($batal) ?>)</button>
  </div>
</div>

<?php if (!$pesanan): ?>
  <div class="card-custom p-4 text-center text-muted mb-4">
    <i class="bi bi-bag fs-1 d-block mb-2"></i>
    Belum ada pesanan. <a href="<?= e($base) ?>index.php" class="fw-semibold">Pilih unit dan mulai sewa</a>.
  </div>
<?php endif; ?>

<?php if ($aktif): ?>
<div class="mb-5" id="sectionBerlangsung">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-3">
    <h6 class="fw-bold mb-0 d-flex align-items-center gap-2">
      <i class="bi bi-circle-fill text-primary fz-px-10"></i> Pesanan Sedang Berlangsung
      <span class="badge bg-primary-subtle text-primary rounded-pill"><?= count($aktif) ?> Pesanan</span>
    </h6>
  </div>

  <div class="row row-cols-1 row-cols-xl-2 g-4">
    <?php foreach ($aktif as $r):
        $kode  = $r['id_sewa'];
        $tokenKode = encrypt_id($kode);
        $st    = $r['status_sewa'];
        $units = $unitPer[$kode] ?? [];
        $selesaiTs = ($waktu($r['waktu_kembali_rencana']) ?? new DateTimeImmutable())->getTimestamp();
        $mulaiTs   = ($waktu($r['waktu_mulai']) ?? new DateTimeImmutable())->getTimestamp();
        $telat     = $st === 'aktif' && time() > $selesaiTs;
        [$bayarLabel, $bayarKelas] = $bayarBadge($r['status_bayar'], $r['metode_pembayaran']);
        $durasiLabel = (int) $r['durasi'] . ($r['tipe_sewa'] === 'harian' ? ' Hari' : ' Jam');
        $menungguVerif = $st === 'menunggu' && $r['status_bayar'] === 'menunggu_verifikasi';
        $bisaBatal = $st === 'menunggu' && !in_array($r['status_bayar'], ['menunggu_verifikasi', 'lunas'], true);
        $statusBadge = match (true) {
            $telat                => ['Waktu Habis · Segera Kembalikan', 'bg-danger-subtle text-danger border border-danger-subtle', 'bi-alarm'],
            $st === 'aktif'       => ['Sedang Aktif Dimainkan', 'bg-primary-subtle text-primary border border-primary-subtle', 'bi-controller'],
            $st === 'siap'        => ['Siap Diambil', 'bg-success-subtle text-success border border-success-subtle', 'bi-bag-check'],
            $menungguVerif        => ['Menunggu Verifikasi', 'bg-warning-subtle text-warning-emphasis border border-warning-subtle', 'bi-hourglass-split'],
            $r['metode_pembayaran'] === 'cod' => ['Bayar di Toko', 'bg-info-subtle text-info-emphasis border border-info-subtle', 'bi-cash-coin'],
            default               => ['Menunggu Pembayaran', 'bg-warning-subtle text-warning-emphasis border border-warning-subtle', 'bi-credit-card'],
        };
        $cari = strtolower($r['nama_paket'] . ' ' . $kode);
    ?>
    <div class="col order-card" data-group="active" data-cari="<?= e($cari) ?>">
      <div class="card-custom p-3 p-md-4 h-100 d-flex flex-column justify-content-between">
        <div>
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom pb-2 mb-3">
            <span class="text-muted small"><strong><?= e($kode) ?></strong> • Sewa <?= e($durasiLabel) ?></span>
            <span class="badge <?= $statusBadge[1] ?> rounded-pill"><i class="bi <?= $statusBadge[2] ?> me-1"></i> <?= e($statusBadge[0]) ?></span>
          </div>

          <div class="d-flex gap-3 align-items-center mb-3">
            <img src="<?= e($srcGambar($r)) ?>" alt="" class="rounded-3 border flex-shrink-0 shadow-sm obj-cover" width="90" height="70">
            <div>
              <h6 class="fw-bold mb-1 text-dark"><?= e($r['nama_paket']) ?></h6>
              <div class="d-flex flex-wrap gap-2 mt-2">
                <span class="badge bg-light text-dark border"><i class="bi <?= $layananIkon[$r['tipe_layanan']] ?? 'bi-shop' ?> text-primary me-1"></i> <?= e($layananLabel[$r['tipe_layanan']] ?? 'Ambil di Toko') ?></span>
                <span class="badge <?= $bayarKelas ?>"><?= e($bayarLabel) ?><?= $r['metode_pembayaran'] ? ' (' . e($metodeLabel[$r['metode_pembayaran']] ?? $r['metode_pembayaran']) . ')' : '' ?></span>
              </div>
              <div class="fw-bold text-dark small mt-2">Total: <?= e($rp($r['total_biaya'])) ?></div>
            </div>
          </div>

          <?php if ($st === 'aktif'): ?>
            <div class="bg-primary-subtle bg-opacity-25 p-3 rounded-4 border border-primary-subtle mb-3">
              <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-2">
                <span class="fw-bold text-dark small"><i class="bi bi-clock text-primary me-1"></i> <?= $telat ? 'Terlambat:' : 'Sisa Waktu Bermain:' ?></span>
                <span class="fw-bold fs-6 <?= $telat ? 'text-danger' : 'text-primary' ?> live-countdown" data-start="<?= $mulaiTs ?>" data-end="<?= $selesaiTs ?>">--:--:--</span>
              </div>
              <div class="progress mb-2 bg-light border border-light progress-waktu">
                <div class="progress-bar progress-animated-striped progress-bar-waktu <?= $telat ? 'bg-danger' : 'bg-primary' ?> live-progress" role="progressbar" style="width: 0%;"></div>
              </div>
              <div class="d-flex flex-wrap justify-content-between gap-1 text-muted mt-1 fz-65">
                <span>Mulai: <?= e($tgl($r['waktu_mulai'])) ?></span>
                <span>Berakhir: <?= e($tgl($r['waktu_kembali_rencana'])) ?></span>
              </div>
              <?php if ($telat): ?>
                <div class="small text-danger fw-semibold mt-2">Segera kembalikan unit ke toko. Keterlambatan dikenai denda sesuai ketentuan.</div>
              <?php endif; ?>
            </div>
          <?php elseif ($st === 'siap'): ?>
            <div class="bg-success-subtle bg-opacity-25 p-3 rounded-4 border border-success-subtle mb-3 small">
              <div class="fw-bold text-dark mb-1"><i class="bi bi-check-circle text-success me-1"></i> Pembayaran terverifikasi</div>
              Datang ke <strong><?= e($r['nama_cabang']) ?></strong> pada <strong><?= e($tgl($r['waktu_mulai'])) ?></strong>.
              Bawa jaminan asli (KTP / SIM) dan tunjukkan kode pengambilan ke operator.
            </div>
          <?php else: ?>
            <div class="bg-warning-subtle bg-opacity-25 p-3 rounded-4 border border-warning-subtle mb-3 small">
              <?php if ($menungguVerif): ?>
                <div class="fw-bold text-dark mb-1"><i class="bi bi-hourglass-split text-warning me-1"></i> Bukti sedang diperiksa operator</div>
                Setelah disetujui, status berubah menjadi "Siap Diambil". Unit tetap ditahan untuk Anda.
              <?php elseif ($r['metode_pembayaran'] === 'cod'): ?>
                <div class="fw-bold text-dark mb-1"><i class="bi bi-cash-coin text-info me-1"></i> Bayar tunai di toko</div>
                Datang pada jadwal mulai dan bayar <strong><?= e($rp($r['total_biaya'])) ?></strong>. Pesanan dibatalkan otomatis bila Anda belum datang sampai <?= e($tgl($r['batas_bayar'])) ?>.
              <?php else: ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-1">
                  <span class="fw-bold text-dark"><i class="bi bi-credit-card text-warning me-1"></i> Selesaikan pembayaran</span>
                  <?php if ($r['sisa_bayar'] !== null): ?>
                    <span class="fw-bold text-danger bayar-countdown" data-sisa="<?= (int) $r['sisa_bayar'] ?>">--:--</span>
                  <?php endif; ?>
                </div>
                <?php if ($r['status_bayar'] === 'ditolak'): ?>
                  <span class="text-danger fw-semibold">Bukti ditolak: <?= e($r['catatan_verifikasi'] ?: 'kirim ulang bukti yang jelas dan sesuai nominal.') ?></span>
                <?php else: ?>
                  Bayar sebelum waktu habis, atau unit dilepas kembali dan pesanan dibatalkan otomatis.
                <?php endif; ?>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="row g-2 mb-3 text-muted fz-75">
            <div class="col-12 col-sm-6">
              <div class="bg-light p-2 rounded-3 border h-100">
                <strong class="text-dark d-block mb-1"><i class="bi bi-geo-alt text-danger me-1"></i> Lokasi Pengambilan</strong>
                <?= e($r['nama_cabang']) ?>, <?= e($r['alamat']) ?>
              </div>
            </div>
            <div class="col-12 col-sm-6">
              <div class="bg-light p-2 rounded-3 border h-100">
                <strong class="text-dark d-block mb-1"><i class="bi bi-card-heading text-primary me-1"></i> Jaminan Identitas</strong>
                <?php if ($r['jenis_jaminan']): ?>
                  <?= e($r['jenis_jaminan']) ?> <span class="text-success fw-medium">(Diterima operator)</span>
                <?php else: ?>
                  <span class="text-danger fw-medium">Siapkan KTP / SIM asli</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2 pt-2">
          <?php if ($st === 'menunggu' && !$r['status_bayar'] || $st === 'menunggu' && in_array($r['status_bayar'], ['belum_bayar', 'ditolak', 'menunggu_verifikasi'], true)): ?>
            <a href="pembayaran.php?kode=<?= e($tokenKode) ?>" class="btn btn-primary btn-sm fw-semibold rounded-pill py-2 px-3 flex-grow-1">
              <?= $menungguVerif ? 'Lihat Status Pembayaran' : ($r['status_bayar'] === 'ditolak' ? 'Kirim Ulang Bukti' : ($r['metode_pembayaran'] === 'cod' ? 'Ubah Metode Bayar' : 'Bayar Sekarang')) ?>
            </a>
          <?php endif; ?>
          <?php if ($st === 'siap'): ?>
            <button type="button" class="btn btn-primary btn-sm fw-semibold rounded-pill py-2 px-3 flex-grow-1 btn-kode"
              data-kode="<?= e($kode) ?>" data-paket="<?= e($r['nama_paket']) ?>" data-bs-toggle="modal" data-bs-target="#modalKode">
              <i class="bi bi-qr-code me-1"></i> Kode Pengambilan
            </button>
          <?php endif; ?>
          <button type="button" class="btn btn-outline-custom btn-sm rounded-pill py-2 px-3 btn-detail" data-bs-toggle="modal" data-bs-target="#modalDetailPesanan"
            data-kode="<?= e($kode) ?>" data-paket="<?= e($r['nama_paket']) ?>" data-layanan="<?= e($layananLabel[$r['tipe_layanan']] ?? '') ?>"
            data-cabang="<?= e($r['nama_cabang'] . ', ' . $r['alamat']) ?>" data-durasi="<?= e($durasiLabel) ?>"
            data-mulai="<?= e($tgl($r['waktu_mulai'])) ?>" data-kembali="<?= e($tgl($r['waktu_kembali_rencana'])) ?>"
            data-total="<?= e($rp($r['total_biaya'])) ?>" data-bayar="<?= e($bayarLabel . ($r['metode_pembayaran'] ? ' (' . ($metodeLabel[$r['metode_pembayaran']] ?? $r['metode_pembayaran']) . ')' : '')) ?>"
            data-jaminan="<?= e($r['jenis_jaminan'] ?: '-') ?>" data-unit="<?= e(implode(', ', $units) ?: '-') ?>"
            data-bukti="<?= $r['file_bukti'] ? e('../modules/sewa/lihat_bukti.php?kode=' . rawurlencode($tokenKode)) : '' ?>"
            data-alasan="" data-denda="">
            <i class="bi bi-eye me-1"></i> Detail
          </button>
          <?php if ($bisaBatal): ?>
            <form method="post" action="Pesanan.php" class="d-inline" onsubmit="return confirm('Batalkan pesanan ini? Unit akan dilepas kembali.')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="batal">
              <input type="hidden" name="kode" value="<?= e($tokenKode) ?>">
              <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill py-2 px-3">Batalkan</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ============ RIWAYAT ============ -->
<?php if ($riwayat): ?>
<div class="card-custom p-3 p-md-4 mb-4" id="sectionRiwayat">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3 border-bottom pb-3">
    <div>
      <h6 class="fw-bold mb-1 text-dark"><i class="bi bi-clock-history me-2 text-primary"></i> Riwayat Peminjaman</h6>
      <span class="text-muted fz-75">Transaksi yang sudah selesai atau dibatalkan</span>
    </div>
    <div class="riwayat-aksi">
      <div class="input-group input-group-sm riwayat-cari">
        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
        <input type="search" class="form-control" id="cariPesanan" placeholder="Cari unit atau no. pesanan" aria-label="Cari riwayat" autocomplete="off">
      </div>
    </div>
  </div>

  <div class="riwayat-filter mb-3">
    <span class="text-muted fz-75 fw-semibold"><i class="bi bi-calendar-range me-1"></i> Tanggal pesanan</span>
    <input type="date" class="form-control form-control-sm" id="tglDariPesanan" aria-label="Dari tanggal">
    <span class="text-muted fz-75">s/d</span>
    <input type="date" class="form-control form-control-sm" id="tglSampaiPesanan" aria-label="Sampai tanggal">
    <button type="button" id="btnResetPesanan" class="btn btn-light btn-sm border rounded-pill px-3 fz-75">Reset</button>
  </div>

  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 table-riwayat fz-82" id="tabelRiwayatPesanan">
      <thead class="table-light text-muted">
        <tr>
          <th class="ps-3 border-0 rounded-start">Unit & No. Pesanan</th>
          <th class="border-0">Durasi & Tanggal</th>
          <th class="border-0">Total Biaya</th>
          <th class="border-0">Jaminan & Denda</th>
          <th class="border-0">Status</th>
          <th class="text-center pe-3 border-0 rounded-end">Tindakan</th>
        </tr>
      </thead>
      <tbody class="border-top-0">
        <?php foreach ($riwayat as $r):
            $kode   = $r['id_sewa'];
            $tokenKode = encrypt_id($kode);
            $units  = $unitPer[$kode] ?? [];
            $selesaiOk = $r['status_sewa'] === 'selesai';
            $tanggalIso = ($waktu($r['created_at']) ?? new DateTimeImmutable())->format('Y-m-d');
            $durasiLabel = (int) $r['durasi'] . ($r['tipe_sewa'] === 'harian' ? ' Hari' : ' Jam');
            [$bayarLabel] = $bayarBadge($r['status_bayar'], $r['metode_pembayaran']);
            $dendaTeks = $r['total_denda'] !== null
                ? 'Denda ' . $rp($r['total_denda']) . ' (' . ($r['status_denda'] === 'lunas' ? 'lunas' : 'belum dibayar') . ')'
                : '';
            $alasan = !$selesaiOk ? (string) ($r['alasan_penolakan'] ?: 'Pesanan tidak dilanjutkan.') : '';
        ?>
        <tr data-group="<?= $selesaiOk ? 'done' : 'cancel' ?>" data-tanggal="<?= e($tanggalIso) ?>"
            data-cari="<?= e(strtolower($r['nama_paket'] . ' ' . $kode)) ?>">
          <td class="ps-3 pt-3 pb-3">
            <div class="d-flex align-items-center gap-3">
              <img src="<?= e($srcGambar($r)) ?>" alt="" class="rounded border flex-shrink-0 obj-cover" width="45" height="35">
              <div>
                <strong class="text-dark d-block"><?= e($r['nama_paket']) ?></strong>
                <span class="text-muted fz-70"><?= e($kode) ?></span>
              </div>
            </div>
          </td>
          <td class="pt-3 pb-3">
            <strong class="text-dark d-block"><?= e($durasiLabel) ?><?= $r['jenis_tarif'] === 'weekend' ? ' (Weekend)' : '' ?></strong>
            <span class="text-muted fz-70"><?= e($tglPendek($r['waktu_mulai'])) ?></span>
          </td>
          <td class="pt-3 pb-3">
            <strong class="text-dark"><?= e($rp($r['total_biaya'])) ?></strong>
            <span class="text-muted d-block fz-70"><?= e($bayarLabel) ?><?= $r['metode_pembayaran'] ? ' · ' . e($metodeLabel[$r['metode_pembayaran']] ?? $r['metode_pembayaran']) : '' ?></span>
          </td>
          <td class="pt-3 pb-3">
            <?php if ($selesaiOk): ?>
              <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill"><?= e($r['jenis_jaminan'] ?: 'Tanpa jaminan') ?><?= $r['kondisi_kembali'] ? ' · ' . e($kondisiLabel[$r['kondisi_kembali']] ?? $r['kondisi_kembali']) : '' ?></span>
              <?php if ($dendaTeks !== ''): ?><span class="text-danger d-block fz-70 mt-1"><?= e($dendaTeks) ?></span><?php endif; ?>
            <?php else: ?>
              <span class="text-muted fz-70"><?= e($alasan) ?></span>
            <?php endif; ?>
          </td>
          <td class="pt-3 pb-3">
            <?php if ($selesaiOk): ?>
              <span class="badge bg-secondary-subtle text-secondary border rounded-pill">Selesai</span>
            <?php else: ?>
              <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill">Dibatalkan</span>
            <?php endif; ?>
          </td>
          <td class="text-center pe-3 pt-3 pb-3 text-nowrap">
            <button type="button" class="btn btn-outline-custom btn-sm px-3 py-1 rounded-pill fz-75 btn-detail" data-bs-toggle="modal" data-bs-target="#modalDetailPesanan"
              data-kode="<?= e($kode) ?>" data-paket="<?= e($r['nama_paket']) ?>" data-layanan="<?= e($layananLabel[$r['tipe_layanan']] ?? '') ?>"
              data-cabang="<?= e($r['nama_cabang'] . ', ' . $r['alamat']) ?>" data-durasi="<?= e($durasiLabel) ?>"
              data-mulai="<?= e($tgl($r['waktu_mulai'])) ?>" data-kembali="<?= e($tgl($selesaiOk ? ($r['waktu_kembali_aktual'] ?? $r['waktu_kembali_rencana']) : $r['waktu_kembali_rencana'])) ?>"
              data-total="<?= e($rp($r['total_biaya'])) ?>" data-bayar="<?= e($bayarLabel . ($r['metode_pembayaran'] ? ' (' . ($metodeLabel[$r['metode_pembayaran']] ?? $r['metode_pembayaran']) . ')' : '')) ?>"
              data-jaminan="<?= e($r['jenis_jaminan'] ?: '-') ?>" data-unit="<?= e(implode(', ', $units) ?: '-') ?>"
              data-bukti="<?= $r['file_bukti'] ? e('../modules/sewa/lihat_bukti.php?kode=' . rawurlencode($tokenKode)) : '' ?>"
              data-alasan="<?= e($alasan) ?>" data-denda="<?= e($dendaTeks) ?>">
              <i class="bi bi-eye me-1"></i> Detail
            </button>
            <a href="form_sewa.php?id_paket=<?= e(encrypt_id($r['id_paket'])) ?>" class="btn btn-primary btn-sm px-3 py-1 rounded-pill fz-75">
              <i class="bi bi-arrow-repeat me-1"></i> Sewa Lagi
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <tr id="barisKosongRiwayat" class="d-none">
          <td colspan="6" class="text-center text-muted py-4">Tidak ada riwayat yang cocok dengan pencarian atau filter tanggal.</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ================= MODAL: DETAIL PESANAN ================= -->
<div class="modal fade" id="modalDetailPesanan" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content border-0 rounded-4 shadow-lg">
      <div class="modal-header border-bottom-0">
        <h5 class="modal-title fw-bold">Detail Pesanan <span class="text-primary" id="dtKode"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3 small">
          <div class="col-md-6"><span class="text-muted d-block">Paket</span><strong id="dtPaket"></strong></div>
          <div class="col-md-6"><span class="text-muted d-block">Layanan</span><strong id="dtLayanan"></strong></div>
          <div class="col-12"><span class="text-muted d-block">Unit</span><strong id="dtUnit"></strong></div>
          <div class="col-md-6"><span class="text-muted d-block">Mulai</span><strong id="dtMulai"></strong></div>
          <div class="col-md-6"><span class="text-muted d-block">Kembali</span><strong id="dtKembali"></strong></div>
          <div class="col-md-6"><span class="text-muted d-block">Durasi</span><strong id="dtDurasi"></strong></div>
          <div class="col-md-6"><span class="text-muted d-block">Jaminan</span><strong id="dtJaminan"></strong></div>
          <div class="col-12"><span class="text-muted d-block">Lokasi</span><strong id="dtCabang"></strong></div>
          <div class="col-md-6"><span class="text-muted d-block">Total</span><strong class="text-primary fs-6" id="dtTotal"></strong></div>
          <div class="col-md-6"><span class="text-muted d-block">Pembayaran</span><strong id="dtBayar"></strong></div>
          <div class="col-12 d-none" id="dtDendaWrap"><div class="alert alert-danger py-2 mb-0" id="dtDenda"></div></div>
          <div class="col-12 d-none" id="dtAlasanWrap"><div class="alert alert-secondary py-2 mb-0" id="dtAlasan"></div></div>
          <div class="col-12 d-none" id="dtBuktiWrap">
            <span class="text-muted d-block mb-1">Bukti pembayaran Anda</span>
            <a href="#" target="_blank" rel="noopener" id="dtBuktiLink"><img src="" alt="Bukti pembayaran" id="dtBuktiImg" class="img-fluid rounded-3 border" style="max-height: 280px;"></a>
          </div>
        </div>
      </div>
      <div class="modal-footer border-top-0">
        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<!-- ================= MODAL: KODE PENGAMBILAN ================= -->
<div class="modal fade" id="modalKode" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow-lg">
      <div class="modal-header border-bottom-0 pb-0">
        <div>
          <h5 class="modal-title fw-bold"><i class="bi bi-patch-check-fill text-primary me-2"></i>Kode Pengambilan</h5>
          <p class="text-muted small mb-0">Tunjukkan kode ini ke operator saat mengambil unit.</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body text-center p-4">
        <div class="text-muted small mb-1" id="kdPaket"></div>
        <div class="d-inline-flex align-items-center gap-2 bg-light border px-3 py-1 rounded-3 mb-3">
          <span class="fw-bold tracking-wider text-dark kode-lg" id="kdKode"></span>
          <button type="button" class="btn btn-sm btn-link text-muted p-0 border-0" id="kdSalin" title="Salin kode"><i class="bi bi-copy"></i></button>
        </div>
        <div class="bg-white border rounded-3 p-3"><svg id="kdBarcode" class="mw-100"></svg></div>
        <div class="bg-modal-info p-3 mt-3 text-start small">
          <div class="fw-bold text-dark mb-2">Yang perlu dibawa:</div>
          <div class="mb-1"><i class="bi bi-check-square-fill text-primary me-2"></i> Jaminan asli: KTP, SIM, atau STNK</div>
          <div><i class="bi bi-check-square-fill text-primary me-2"></i> Kode pesanan ini (tangkapan layar juga bisa)</div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    const $ = (id) => document.getElementById(id);
    const p2 = (n) => String(n).padStart(2, '0');
    const tampil = (el, ya) => el.classList.toggle('d-none', !ya);

    // 1. Tab filter (Semua / Berlangsung / Selesai / Dibatalkan)
    const cards = Array.from(document.querySelectorAll('.order-card'));
    const rows = Array.from(document.querySelectorAll('#tabelRiwayatPesanan tbody tr[data-group]'));
    const secAktif = $('sectionBerlangsung'), secRiwayat = $('sectionRiwayat');
    let tab = 'all';

    function terapkan() {
      const q = ($('cariPesanan')?.value || '').trim().toLowerCase();
      const dari = $('tglDariPesanan')?.value || '', sampai = $('tglSampaiPesanan')?.value || '';
      let terlihat = 0;
      rows.forEach((tr) => {
        const grup = tr.dataset.group;
        const cocokTab = tab === 'all' || tab === grup;
        const tgl = tr.dataset.tanggal || '';
        const cocok = cocokTab && (!q || (tr.dataset.cari || '').includes(q))
          && (!dari || tgl >= dari) && (!sampai || tgl <= sampai);
        tr.classList.toggle('d-none', !cocok);
        if (cocok) terlihat++;
      });
      if ($('barisKosongRiwayat')) tampil($('barisKosongRiwayat'), terlihat === 0);
      if (secAktif) tampil(secAktif, tab === 'all' || tab === 'active');
      if (secRiwayat) tampil(secRiwayat, tab === 'all' || tab === 'done' || tab === 'cancel');
    }
    document.querySelectorAll('#tabPesanan [data-tab]').forEach((btn) => btn.addEventListener('click', () => {
      tab = btn.dataset.tab;
      document.querySelectorAll('#tabPesanan [data-tab]').forEach((b) => {
        const aktif = b === btn;
        b.classList.toggle('btn-primary', aktif);
        b.classList.toggle('fw-semibold', aktif);
        b.classList.toggle('bg-light', !aktif);
        b.classList.toggle('border', !aktif);
        b.classList.toggle('text-secondary', !aktif);
      });
      terapkan();
    }));
    ['cariPesanan', 'tglDariPesanan', 'tglSampaiPesanan'].forEach((id) => $(id)?.addEventListener('input', terapkan));
    $('btnResetPesanan')?.addEventListener('click', () => {
      ['cariPesanan', 'tglDariPesanan', 'tglSampaiPesanan'].forEach((id) => { if ($(id)) $(id).value = ''; });
      terapkan();
    });
    terapkan();

    // 2. Hitung mundur sisa waktu sewa (+ progress bar) dan batas bayar
    const bayarAwal = performance.now();
    function tick() {
      const sekarang = Math.floor(Date.now() / 1000);
      document.querySelectorAll('.live-countdown').forEach((el) => {
        const mulai = parseInt(el.dataset.start, 10), akhir = parseInt(el.dataset.end, 10);
        let selisih = akhir - sekarang;
        const telat = selisih < 0;
        selisih = Math.abs(selisih);
        el.textContent = (telat ? '- ' : '') + `${p2(Math.floor(selisih / 3600))}:${p2(Math.floor((selisih % 3600) / 60))}:${p2(selisih % 60)}`;
        const bar = el.closest('.bg-primary-subtle')?.querySelector('.live-progress');
        if (bar) {
          const total = Math.max(1, akhir - mulai);
          bar.style.width = Math.min(100, Math.max(0, ((sekarang - mulai) / total) * 100)) + '%';
        }
      });
      document.querySelectorAll('.bayar-countdown').forEach((el) => {
        const sisa = Math.max(0, (parseInt(el.dataset.sisa, 10) || 0) - Math.floor((performance.now() - bayarAwal) / 1000));
        el.textContent = sisa >= 3600
          ? `${Math.floor(sisa / 3600)}:${p2(Math.floor((sisa % 3600) / 60))}:${p2(sisa % 60)}`
          : `${p2(Math.floor(sisa / 60))}:${p2(sisa % 60)}`;
      });
    }
    tick();
    setInterval(tick, 1000);

    // 3. Modal detail (diisi dari atribut data-*; semua lewat textContent)
    document.querySelectorAll('.btn-detail').forEach((btn) => btn.addEventListener('click', () => {
      const d = btn.dataset;
      $('dtKode').textContent = d.kode;
      $('dtPaket').textContent = d.paket;
      $('dtLayanan').textContent = d.layanan;
      $('dtUnit').textContent = d.unit;
      $('dtMulai').textContent = d.mulai;
      $('dtKembali').textContent = d.kembali;
      $('dtDurasi').textContent = d.durasi;
      $('dtJaminan').textContent = d.jaminan;
      $('dtCabang').textContent = d.cabang;
      $('dtTotal').textContent = d.total;
      $('dtBayar').textContent = d.bayar;
      $('dtDenda').textContent = d.denda || '';
      tampil($('dtDendaWrap'), !!d.denda);
      $('dtAlasan').textContent = d.alasan ? 'Alasan: ' + d.alasan : '';
      tampil($('dtAlasanWrap'), !!d.alasan);
      tampil($('dtBuktiWrap'), !!d.bukti);
      if (d.bukti) {
        $('dtBuktiImg').src = d.bukti;
        $('dtBuktiLink').href = d.bukti;
      } else {
        $('dtBuktiImg').removeAttribute('src');
      }
    }));

    // 4. Modal kode pengambilan (barcode dibuat di browser, tanpa layanan pihak ketiga)
    document.querySelectorAll('.btn-kode').forEach((btn) => btn.addEventListener('click', () => {
      $('kdPaket').textContent = btn.dataset.paket;
      $('kdKode').textContent = btn.dataset.kode;
      if (window.JsBarcode) {
        try {
          window.JsBarcode('#kdBarcode', btn.dataset.kode, { format: 'CODE128', displayValue: false, height: 60, margin: 0 });
        } catch (err) {
          console.error('[Pesanan] barcode gagal dibuat:', err);
        }
      }
    }));
    $('kdSalin')?.addEventListener('click', async (e) => {
      const tombol = e.currentTarget, awal = tombol.innerHTML;
      try { await navigator.clipboard.writeText($('kdKode').textContent); tombol.textContent = 'Tersalin'; }
      catch (err) { tombol.textContent = 'Salin manual'; }
      setTimeout(() => (tombol.innerHTML = awal), 1500);
    });
  });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
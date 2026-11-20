<?php

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/sewa.php';
require_once __DIR__ . '/../includes/auth.php';

require_role(['pelanggan']);
$idUser = (int)(current_user()['id_user'] ?? 0);

$tokenPaket = (string)($_GET['id_paket'] ?? '');
$idPaket    = strlen($tokenPaket) <= 200 ? decrypt_id($tokenPaket) : false;
if ($idPaket === false || !ctype_digit((string)$idPaket) || strlen((string)$idPaket) > 9) {
    header('Location: ../index.php?error=invalid_link');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT p.id_paket, p.nama_paket, p.kategori, p.deskripsi, p.gambar,
            ROUND(p.harga_wk_12h)::int AS wk12, ROUND(p.harga_wk_24h)::int AS wk24,
            ROUND(p.harga_wn_12h)::int AS wn12, ROUND(p.harga_wn_24h)::int AS wn24,
            c.nama_cabang, c.alamat, c.jam_buka, c.jam_tutup
       FROM paket_sewa p
       JOIN cabang c ON c.id_cabang = p.id_cabang
      WHERE p.id_paket = :id AND p.override_status = 'auto' AND p.is_active AND c.is_active"
);
$stmt->execute(['id' => (int)$idPaket]);
$paket = $stmt->fetch();
if (!$paket) {
    header('Location: ../index.php?error=not_found');
    exit;
}
$tarifSiap = $paket['wk12'] > 0 && $paket['wk24'] > 0 && $paket['wn12'] > 0 && $paket['wn24'] > 0;

$stmt = $pdo->prepare('SELECT COUNT(*) FROM v_kebutuhan_unit WHERE id_paket = :id');
$stmt->execute(['id' => (int)$idPaket]);
$paketSiap = (int)$stmt->fetchColumn() > 0;

$stmt = $pdo->prepare('SELECT nama, no_wa FROM users WHERE id_user = :id');
$stmt->execute(['id' => $idUser]);
$akun = $stmt->fetch() ?: ['nama' => '', 'no_wa' => ''];

$error = (string)($_SESSION['error_sewa'] ?? '');
$old   = is_array($_SESSION['old_sewa'] ?? null) ? $_SESSION['old_sewa'] : [];
unset($_SESSION['error_sewa'], $_SESSION['old_sewa']);

$tipeAwal   = in_array($old['tipe_sewa'] ?? '', ['harian', 'jam'], true) ? $old['tipe_sewa'] : 'harian';
$durasiAwal = preg_replace('/\D/', '', (string)($old['durasi'] ?? '')) ?: '';
$mulaiAwal  = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string)($old['mulai'] ?? '')) ? $old['mulai'] : '';

$sekarang  = new DateTimeImmutable('now', new DateTimeZone(SEWA_TZ));
$minMulai  = $sekarang->format('Y-m-d\TH:i');
$maxMulai  = $sekarang->modify('+' . SEWA_MAKS_HARI_DEPAN . ' days')->format('Y-m-d\TH:i');
$tarifAwal = in_array($_GET['tarif'] ?? '', ['weekday', 'weekend'], true)
    ? $_GET['tarif']
    : ((int)$sekarang->format('N') >= 5 ? 'weekend' : 'weekday');

$rp = static fn(int $n): string => 'Rp ' . number_format($n, 0, ',', '.');

$infoJam = ($paket['jam_buka'] !== null && $paket['jam_tutup'] !== null)
    ? 'Buka ' . substr($paket['jam_buka'], 0, 5) . ' - ' . substr($paket['jam_tutup'], 0, 5) . ' WIB. Jam mulai sewa harus dalam jam operasional.'
    : 'Buka 24 jam.';

$pageTitle = 'PS Rental - Form Penyewaan';
include __DIR__ . '/../includes/header.php';

$gambarBawaan = [
    'ps3'   => 'ps3.png',
    'ps4'   => 'ps4.png',
    'ps5'   => 'ps5-banner.png',
    'combo' => 'paket-ps4.png',
    'tv'    => 'tv-43.png',
];
$berkasGambar = basename(str_replace('\\', '/', trim((string)($paket['gambar'] ?? ''))));
if (!preg_match('/^[A-Za-z0-9._-]+$/', $berkasGambar)) {
    $berkasGambar = $gambarBawaan[$paket['kategori']] ?? 'ps4.png';
}
$srcGambar = $base . 'assets/img/' . $berkasGambar;

$h12Awal   = $tarifAwal === 'weekend' ? $paket['wn12'] : $paket['wk12'];
$h24Awal   = $tarifAwal === 'weekend' ? $paket['wn24'] : $paket['wk24'];
$bisaPesan = $tarifSiap && $paketSiap;
?>
<div class="mobile-bar d-lg-none mb-3 d-flex align-items-center gap-2">
    <button type="button" class="btn btn-light" id="menuBtn" aria-label="Buka menu"><i class="bi bi-list fs-4"></i></button>
    <span class="fw-bold text-dark">PS Rental</span>
</div>

<div class="mb-4">
    <a href="<?= e($base) ?>index.php" class="text-decoration-none fw-semibold">
        <i class="bi bi-arrow-left me-2"></i> Kembali ke Beranda
    </a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger d-flex gap-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div><?= e($error) ?></div>
    </div>
<?php endif; ?>

<form id="formSewa" method="POST" action="<?= e($base) ?>modules/sewa/proses_sewa.php" autocomplete="off"
    data-wk12="<?= (int)$paket['wk12'] ?>" data-wk24="<?= (int)$paket['wk24'] ?>"
    data-wn12="<?= (int)$paket['wn12'] ?>" data-wn24="<?= (int)$paket['wn24'] ?>"
    data-maks-jam="<?= (int)SEWA_MAKS_JAM ?>" data-tarif-awal="<?= e($tarifAwal) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id_paket" value="<?= e($tokenPaket) ?>">
    <input type="hidden" name="metode_terima" value="ambil">

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 p-3 p-md-4">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-semibold" for="namaAkun">Nama Pemesan</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control bg-light" id="namaAkun" value="<?= e($akun['nama']) ?>" readonly>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-semibold" for="waAkun">Nomor WhatsApp</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="bi bi-telephone"></i></span>
                            <input type="text" class="form-control bg-light" id="waAkun" value="<?= e($akun['no_wa']) ?>" readonly>
                        </div>
                    </div>
                    <div class="col-12">
                        <small class="text-muted">Data diambil dari akun Anda.</small>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label text-muted small fw-semibold">Unit yang Disewa</label>
                    <div class="d-flex align-items-center p-3 border rounded-3 bg-light">
                        <img src="<?= e($srcGambar) ?>" alt="<?= e($paket['nama_paket']) ?>" width="48" class="me-3">
                        <div>
                            <h6 class="mb-0 fw-bold"><?= e($paket['nama_paket']) ?></h6>
                            <small class="text-muted" id="unitHarga"><?= e($rp($h12Awal)) ?> / 12 jam &bull; <?= e($rp($h24Awal)) ?> / 24 jam</small>
                        </div>
                    </div>
                    <?php if (!$tarifSiap): ?>
                        <div class="alert alert-warning small mt-3 mb-0">Tarif paket ini belum lengkap diatur. Silakan hubungi pengelola.</div>
                    <?php elseif (!$paketSiap): ?>
                        <div class="alert alert-warning small mt-3 mb-0">Paket ini belum memiliki unit yang bisa disewa. Silakan hubungi pengelola.</div>
                    <?php endif; ?>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-semibold">Tipe Sewa</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="tipe_sewa" id="tipeHarian" value="harian" <?= $tipeAwal === 'harian' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary" for="tipeHarian">Sewa Harian</label>
                            <input type="radio" class="btn-check" name="tipe_sewa" id="tipeJam" value="jam" <?= $tipeAwal === 'jam' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary" for="tipeJam">Sewa Per Jam</label>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-semibold" for="mulai">Tanggal &amp; Waktu Mulai Sewa <span class="text-danger">*</span></label>
                        <input type="datetime-local" class="form-control" id="mulai" name="mulai" required
                            min="<?= e($minMulai) ?>" max="<?= e($maxMulai) ?>" value="<?= e($mulaiAwal) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-semibold" for="durasi">Durasi</label>
                        <div class="input-group">
                            <button class="btn btn-outline-secondary px-3" type="button" id="btnKurang" aria-label="Kurangi durasi">-</button>
                            <input type="text" inputmode="numeric" maxlength="3" class="form-control text-center"
                                id="durasi" name="durasi" value="<?= e($durasiAwal !== '' ? $durasiAwal : ($tipeAwal === 'jam' ? '12' : '1')) ?>" required>
                            <span class="input-group-text bg-white" id="satuanDurasi"><?= $tipeAwal === 'jam' ? 'Jam' : 'Hari' ?></span>
                            <button class="btn btn-primary px-3" type="button" id="btnTambah" aria-label="Tambah durasi">+</button>
                        </div>
                        <small class="text-muted d-block mt-1" id="infoDurasi"></small>
                    </div>
                </div>

                <div class="d-flex gap-3 p-3 border rounded-3 bg-light">
                    <i class="bi bi-geo-alt-fill text-danger fs-4"></i>
                    <div>
                        <h6 class="mb-1 fw-bold">Ambil di Toko - <?= e($paket['nama_cabang']) ?></h6>
                        <p class="mb-1 small"><?= e($paket['alamat']) ?></p>
                        <small class="text-muted"><i class="bi bi-clock me-1"></i> <?= e($infoJam) ?> Bawa jaminan asli (KTP / SIM) saat mengambil unit.</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card glass-card card-ringkasan rounded-4 p-3 p-md-4 shadow-sm border-0">
                <h5 class="fw-bold mb-4">Ringkasan Penyewaan</h5>

                <div class="d-flex align-items-center border-bottom pb-3 mb-3">
                    <img src="<?= e($srcGambar) ?>" alt="" width="40" class="me-3">
                    <div>
                        <h6 class="mb-0 fw-bold"><?= e($paket['nama_paket']) ?></h6>
                        <small class="text-muted" id="sumHarga"></small>
                    </div>
                </div>

                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Tarif</span>
                    <span class="fw-semibold text-end" id="sumTarif"></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Durasi</span>
                    <span class="fw-semibold" id="sumDurasi"></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Mulai</span>
                    <span class="fw-semibold text-end" id="sumMulai"></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Kembali</span>
                    <span class="fw-semibold text-end" id="sumKembali"></span>
                </div>
                <div class="d-flex justify-content-between mb-4 pb-3 border-bottom">
                    <span class="text-muted">Penerimaan</span>
                    <span class="fw-semibold text-end">Ambil di Toko</span>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <span class="fw-bold fs-5">Total Estimasi</span>
                    <span class="fw-bold fs-5 text-primary" id="sumTotal"></span>
                </div>

                <div class="alert alert-primary bg-primary-subtle border-0 d-flex gap-2" role="alert">
                    <i class="bi bi-info-circle-fill text-primary"></i>
                    <small>Setelah menekan tombol, unit ditahan untuk Anda selama <?= (int)SEWA_BATAS_BAYAR_MENIT ?> menit.
                        Selesaikan pembayaran di langkah berikutnya sebelum waktu habis. Harga final dihitung sistem.</small>
                </div>

                <button type="submit" id="btnSubmit" class="btn btn-primary w-100 py-2 fw-semibold" <?= $bisaPesan ? '' : 'disabled' ?>>
                    Pesan &amp; Lanjut ke Pembayaran
                </button>
            </div>
        </div>
    </div>
</form>

<script src="<?= e($base) ?>assets/js/form_sewa.js" defer></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
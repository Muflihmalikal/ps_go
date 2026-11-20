<?php

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/sewa.php';
require_once __DIR__ . '/../includes/auth.php';

require_role(['pelanggan']);
$idUser = (int)(current_user()['id_user'] ?? 0);

$tokenKode = (string)($_GET['kode'] ?? '');
$kode      = strlen($tokenKode) <= 200 ? decrypt_id($tokenKode) : false;
if ($kode === false || !preg_match('/^P2B-\d{8}-\d{4,}$/', (string)$kode)) {
    header('Location: ../index.php?error=invalid_link');
    exit;
}

$pdo->query('SELECT batalkan_pesanan_kedaluwarsa()')->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT s.id_sewa, s.id_cabang, s.tipe_sewa, s.durasi, s.jenis_tarif,
            s.waktu_mulai, s.waktu_kembali_rencana, s.total_biaya,
            s.status_sewa, s.alasan_penolakan, s.batas_bayar,
            GREATEST(0, EXTRACT(EPOCH FROM (s.batas_bayar - now())))::int AS sisa_detik,
            p.nama_paket, p.kategori, p.gambar,
            c.nama_cabang, c.alamat,
            b.metode_pembayaran, b.status_bayar, b.catatan_verifikasi, b.nama_pengirim, b.bank_asal
       FROM penyewaan s
       JOIN paket_sewa p ON p.id_paket = s.id_paket
       JOIN cabang c     ON c.id_cabang = s.id_cabang
       LEFT JOIN pembayaran b ON b.id_sewa = s.id_sewa
      WHERE s.id_sewa = :k AND s.id_user = :u"
);
$stmt->execute(['k' => $kode, 'u' => $idUser]);
$o = $stmt->fetch();
if (!$o) {
    header('Location: ../index.php?error=not_found');
    exit;
}

$stmt = $pdo->prepare(
    'SELECT jenis, nama_bank, no_rekening, atas_nama, file_qris
       FROM rekening_pembayaran
      WHERE id_cabang = :c AND is_active
      ORDER BY id_rekening'
);
$stmt->execute(['c' => (int)$o['id_cabang']]);
$rekeningTransfer = [];
$qris = null;
foreach ($stmt->fetchAll() as $r) {
    if ($r['jenis'] === 'transfer') {
        $rekeningTransfer[] = $r;
    } elseif ($r['jenis'] === 'qris' && $qris === null) {
        $qris = $r;
    }
}

$info  = (string)($_SESSION['info_bayar'] ?? '');
$error = (string)($_SESSION['error_bayar'] ?? '');
$old   = is_array($_SESSION['old_bayar'] ?? null) ? $_SESSION['old_bayar'] : [];
unset($_SESSION['info_bayar'], $_SESSION['error_bayar'], $_SESSION['old_bayar']);

$statusSewa  = (string)$o['status_sewa'];
$statusBayar = $o['status_bayar'];                       
if (in_array($statusSewa, ['ditolak', 'dibatalkan'], true)) {
    $tampilan = 'batal';
} elseif ($statusBayar === 'lunas' || $statusSewa !== 'menunggu') {
    $tampilan = 'lunas';
} elseif ($statusBayar === 'menunggu_verifikasi') {
    $tampilan = 'verifikasi';
} else {
    $tampilan = 'bayar';                                 
}

$nominal = (int)round((float)$o['total_biaya']);
$sisaDetik = $o['sisa_detik'] !== null ? (int)$o['sisa_detik'] : null;

$tersedia = [
    'transfer' => !empty($rekeningTransfer),
    'qris'     => $qris !== null,
    'cod'      => true,
];
$metodeAwal = (string)($old['metode'] ?? ($o['metode_pembayaran'] ?? ''));
if (!isset($tersedia[$metodeAwal]) || !$tersedia[$metodeAwal]) {
    $metodeAwal = $tersedia['transfer'] ? 'transfer' : ($tersedia['qris'] ? 'qris' : 'cod');
}
$namaAwal = (string)($old['nama_pengirim'] ?? ($o['nama_pengirim'] ?? ''));
$bankAwal = (string)($old['bank_asal'] ?? ($o['bank_asal'] ?? ''));

$bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
$tgl = static function (?string $s) use ($bulan): string {
    if ($s === null || $s === '') {
        return '-';
    }
    try {
        $d = (new DateTimeImmutable($s))->setTimezone(new DateTimeZone(SEWA_TZ));
    } catch (Exception $e) {
        return '-';
    }
    return $d->format('d') . ' ' . $bulan[(int)$d->format('n') - 1] . ' ' . $d->format('Y, H:i') . ' WIB';
};
$rp = static fn($n): string => 'Rp ' . number_format((float)$n, 0, ',', '.');
$durasiTeks = (int)$o['durasi'] . ($o['tipe_sewa'] === 'harian' ? ' Hari' : ' Jam');
$tarifTeks  = $o['jenis_tarif'] === 'weekend' ? 'Weekend (Jum-Min)' : 'Weekday (Sen-Kam)';

$pageTitle = 'PS Rental - Pembayaran';
include __DIR__ . '/../includes/header.php';

// Kolom gambar berisi nama file di assets/img.
$gambarBawaan = ['ps3' => 'ps3.png', 'ps4' => 'ps4.png', 'ps5' => 'ps5-banner.png', 'combo' => 'paket-ps4.png', 'tv' => 'tv-43.png'];
$berkas = basename(str_replace('\\', '/', trim((string)($o['gambar'] ?? ''))));
if (!preg_match('/^[A-Za-z0-9._-]+$/', $berkas)) {
    $berkas = $gambarBawaan[$o['kategori']] ?? 'ps4.png';
}
$srcGambar = $base . 'assets/img/' . $berkas;

$srcQris = null;
if ($qris !== null) {
    $berkasQris = basename(str_replace('\\', '/', trim((string)$qris['file_qris'])));
    if (preg_match('/^[A-Za-z0-9._-]+$/', $berkasQris)) {
        $srcQris = $base . 'assets/img/' . $berkasQris;
    }
}
?>
<div class="mobile-bar d-lg-none mb-3 d-flex align-items-center gap-2">
    <button type="button" class="btn btn-light" id="menuBtn" aria-label="Buka menu"><i class="bi bi-list fs-4"></i></button>
    <span class="fw-bold text-dark">PS Rental</span>
</div>

<?php if ($info !== ''): ?>
    <div class="alert alert-success d-flex gap-2" role="alert">
        <i class="bi bi-check-circle-fill"></i>
        <div><?= e($info) ?></div>
    </div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger d-flex gap-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div><?= e($error) ?></div>
    </div>
<?php endif; ?>

<?php if ($tampilan === 'bayar'): ?>
    <div class="mb-4">
        <a href="<?= e($base) ?>pelanggan/Pesanan.php" class="text-decoration-none fw-semibold">
            <i class="bi bi-arrow-left me-2"></i> Pesanan Saya
        </a>
    </div>

    <form id="formBayar" method="POST" action="<?= e($base) ?>modules/sewa/proses_bayar.php"
        enctype="multipart/form-data" autocomplete="off" data-maks-bukti="<?= (int)SEWA_MAKS_BUKTI_BYTE ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="kode" value="<?= e($tokenKode) ?>">

        <div class="row g-4">
            <div class="col-lg-8">
                <?php if ($statusBayar === 'ditolak'): ?>
                    <div class="alert alert-warning d-flex gap-2" role="alert">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <div>
                            <strong>Pembayaran sebelumnya ditolak.</strong>
                            <?= e($o['catatan_verifikasi'] ?: 'Silakan kirim ulang bukti yang jelas dan sesuai nominal.') ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card border-0 shadow-sm rounded-4 p-3 p-md-4 mb-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div>
                            <h5 class="fw-bold mb-1">Metode Pembayaran</h5>
                            <p class="text-muted small mb-0">Pembayaran diverifikasi langsung oleh operator toko.</p>
                        </div>
                        <?php if ($sisaDetik !== null): ?>
                            <div class="text-end">
                                <small class="text-muted d-block">Sisa waktu bayar</small>
                                <span class="fw-bold text-danger fs-5" id="hitungMundur" data-sisa="<?= $sisaDetik ?>"></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!$tersedia['transfer'] && !$tersedia['qris']): ?>
                        <div class="alert alert-info small border-0">
                            Pembayaran transfer dan QRIS belum diaktifkan oleh toko. Silakan pilih bayar di toko.
                        </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <input type="radio" class="btn-check" name="metode" id="mTransfer" value="transfer"
                                <?= $metodeAwal === 'transfer' ? 'checked' : '' ?> <?= $tersedia['transfer'] ? '' : 'disabled' ?>>
                            <label class="btn btn-outline-primary w-100 text-start p-3" for="mTransfer">
                                <i class="bi bi-bank fs-5 d-block mb-1"></i><span class="fw-semibold">Transfer Bank</span>
                            </label>
                        </div>
                        <div class="col-md-4">
                            <input type="radio" class="btn-check" name="metode" id="mQris" value="qris"
                                <?= $metodeAwal === 'qris' ? 'checked' : '' ?> <?= $tersedia['qris'] ? '' : 'disabled' ?>>
                            <label class="btn btn-outline-primary w-100 text-start p-3" for="mQris">
                                <i class="bi bi-qr-code fs-5 d-block mb-1"></i><span class="fw-semibold">QRIS</span>
                            </label>
                        </div>
                        <div class="col-md-4">
                            <input type="radio" class="btn-check" name="metode" id="mCod" value="cod"
                                <?= $metodeAwal === 'cod' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary w-100 text-start p-3" for="mCod">
                                <i class="bi bi-cash-coin fs-5 d-block mb-1"></i><span class="fw-semibold">Bayar di Toko</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-4 p-3 p-md-4 mb-4 <?= $metodeAwal === 'transfer' ? '' : 'd-none' ?>" id="panelTransfer">
                    <h6 class="fw-bold mb-3"><i class="bi bi-bank me-2 text-primary"></i>Transfer ke Rekening Toko</h6>
                    <div class="d-flex flex-column gap-2 mb-3">
                        <?php foreach ($rekeningTransfer as $r): ?>
                            <div class="d-flex justify-content-between align-items-center p-3 border rounded-3 bg-light">
                                <div>
                                    <div class="fw-bold"><?= e($r['nama_bank']) ?></div>
                                    <div class="fs-5 fw-semibold"><?= e($r['no_rekening']) ?></div>
                                    <small class="text-muted">a.n. <?= e($r['atas_nama']) ?></small>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill"
                                    data-salin="<?= e(preg_replace('/\D/', '', (string)$r['no_rekening'])) ?>">
                                    <i class="bi bi-clipboard me-1"></i>Salin
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex justify-content-between align-items-center p-3 rounded-3 bg-primary-subtle">
                        <div>
                            <small class="text-muted d-block">Nominal transfer</small>
                            <span class="fw-bold fs-5 text-primary"><?= e($rp($nominal)) ?></span>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill" data-salin="<?= $nominal ?>">
                            <i class="bi bi-clipboard me-1"></i>Salin
                        </button>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-4 p-3 p-md-4 mb-4 <?= $metodeAwal === 'qris' ? '' : 'd-none' ?>" id="panelQris">
                    <h6 class="fw-bold mb-3"><i class="bi bi-qr-code me-2 text-primary"></i>Bayar dengan QRIS</h6>
                    <div class="row g-3 align-items-center">
                        <div class="col-md-5 text-center">
                            <div class="border rounded-3 p-2 bg-white d-inline-block">
                                <?php if ($srcQris !== null): ?>
                                    <img src="<?= e($srcQris) ?>" alt="QRIS toko" class="img-fluid qris-img" id="imgQris" />
                                <?php endif; ?>
                                <div class="small text-muted p-4 <?= $srcQris !== null ? 'd-none' : '' ?>" id="qrisKosong">
                                    Gambar QRIS belum tersedia. Hubungi pengelola.
                                </div>
                            </div>
                        </div>
                        <div class="col-md-7">
                            <ol class="small ps-3 mb-3">
                                <li class="mb-1">Buka aplikasi m-banking atau e-wallet, pilih <b>Scan QRIS</b>.</li>
                                <li class="mb-1">Scan kode di samping, lalu masukkan nominal <b><?= e($rp($nominal)) ?></b>.</li>
                                <li>Simpan screenshot bukti bayar, lalu unggah di bawah.</li>
                            </ol>
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill" data-salin="<?= $nominal ?>">
                                <i class="bi bi-clipboard me-1"></i>Salin nominal
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-4 p-3 p-md-4 mb-4 <?= $metodeAwal === 'cod' ? '' : 'd-none' ?>" id="panelCod">
                    <h6 class="fw-bold mb-3"><i class="bi bi-cash-coin me-2 text-primary"></i>Bayar di Toko</h6>
                    <ul class="small mb-0 ps-3">
                        <li class="mb-1">Bayar tunai <b><?= e($rp($nominal)) ?></b> di toko saat mengambil unit.</li>
                        <li class="mb-1">Datang sesuai jadwal mulai sewa (<?= e($tgl($o['waktu_mulai'])) ?>). Pesanan dibatalkan otomatis bila Anda tidak datang dalam <?= (int)SEWA_TOLERANSI_COD_MENIT ?> menit setelah jadwal.</li>
                        <li class="mb-1">Bawa jaminan asli (KTP / SIM) dan tunjukkan kode pesanan ke operator.</li>
                        <li>Pesanan bayar di toko ikut dihitung pada batas pesanan belum lunas Anda.</li>
                    </ul>
                </div>

                <div class="card border-0 shadow-sm rounded-4 p-3 p-md-4 mb-4 <?= $metodeAwal === 'cod' ? 'd-none' : '' ?>" id="blokBukti">
                    <h6 class="fw-bold mb-3"><i class="bi bi-receipt-cutoff me-2 text-primary"></i>Kirim Bukti Pembayaran</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-semibold" for="namaPengirim">Nama pemilik rekening / akun <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="namaPengirim" name="nama_pengirim" maxlength="100"
                                value="<?= e($namaAwal) ?>" placeholder="Sesuai yang tertera di bukti">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-semibold" for="bankAsal">Bank / e-wallet asal</label>
                            <input type="text" class="form-control" id="bankAsal" name="bank_asal" maxlength="50"
                                value="<?= e($bankAwal) ?>" placeholder="Contoh: BCA, DANA, GoPay">
                        </div>
                    </div>
                    <label class="form-label text-muted small fw-semibold">Bukti pembayaran <span class="text-danger">*</span></label>
                    <label class="upload-area d-block text-center p-4 w-100" for="fileBuktiBayar">
                        <input type="file" id="fileBuktiBayar" name="bukti" accept="image/jpeg,image/png,image/webp" class="d-none">
                        <i class="bi bi-cloud-arrow-up fs-3 text-primary d-block mb-1"></i>
                        <span class="small fw-semibold d-block" id="namaFileBukti">Klik untuk memilih bukti transfer / screenshot QRIS</span>
                        <img id="previewBukti" class="upload-preview rounded-3 border mt-3 d-none" alt="Pratinjau bukti">
                    </label>
                    <small class="text-muted d-block mt-2">Format JPG, PNG, atau WebP, maksimal <?= (int)(SEWA_MAKS_BUKTI_BYTE / 1048576) ?> MB.</small>
                </div>

                <div class="alert alert-danger small d-none" id="errBayarKlien" role="alert"></div>
                <button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill fw-semibold mb-4" id="btnBayar">
                    <?= $metodeAwal === 'cod' ? 'Konfirmasi Bayar di Toko' : 'Kirim Bukti Pembayaran' ?>
                </button>
            </div>

            <div class="col-lg-4">
                <div class="card glass-card card-ringkasan rounded-4 p-3 p-md-4 shadow-sm border-0">
                    <h5 class="fw-bold mb-4">Ringkasan Pesanan</h5>
                    <div class="text-center p-2 rounded-3 bg-light mb-3">
                        <small class="text-muted d-block">Kode Pesanan</small>
                        <span class="fw-bold"><?= e($o['id_sewa']) ?></span>
                    </div>
                    <div class="d-flex align-items-center border-bottom pb-3 mb-3">
                        <img src="<?= e($srcGambar) ?>" alt="" width="40" class="me-3">
                        <div>
                            <h6 class="mb-0 fw-bold"><?= e($o['nama_paket']) ?></h6>
                            <small class="text-muted"><?= e($tarifTeks) ?></small>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">Durasi</span><span class="fw-semibold"><?= e($durasiTeks) ?></span></div>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">Mulai</span><span class="fw-semibold text-end"><?= e($tgl($o['waktu_mulai'])) ?></span></div>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">Kembali</span><span class="fw-semibold text-end"><?= e($tgl($o['waktu_kembali_rencana'])) ?></span></div>
                    <div class="d-flex justify-content-between mb-3 pb-3 border-bottom"><span class="text-muted">Penerimaan</span><span class="fw-semibold text-end">Ambil di <?= e($o['nama_cabang']) ?></span></div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold fs-5">Total Bayar</span>
                        <span class="fw-bold fs-5 text-primary"><?= e($rp($nominal)) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </form>

<?php else: ?>
    <div class="row justify-content-center">
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5">
                <div class="text-center mb-4">
                    <?php if ($tampilan === 'batal'): ?>
                        <i class="bi bi-x-circle-fill text-danger fs-1"></i>
                        <h4 class="fw-bold mt-2 mb-1">Pesanan Dibatalkan</h4>
                        <p class="text-muted mb-0"><?= e($o['alasan_penolakan'] ?: 'Pesanan ini sudah tidak berlaku.') ?></p>
                    <?php elseif ($tampilan === 'verifikasi'): ?>
                        <i class="bi bi-hourglass-split text-warning fs-1"></i>
                        <h4 class="fw-bold mt-2 mb-1">Menunggu Verifikasi</h4>
                        <p class="text-muted mb-0">Bukti pembayaran Anda sudah terkirim dan sedang diperiksa operator.</p>
                    <?php else: ?>
                        <i class="bi bi-check-circle-fill text-success fs-1"></i>
                        <h4 class="fw-bold mt-2 mb-1">Pembayaran Terverifikasi</h4>
                        <p class="text-muted mb-0">Pesanan Anda sedang diproses. Tunjukkan kode di bawah ke operator.</p>
                    <?php endif; ?>
                </div>

                <div class="text-center p-3 rounded-4 bg-light mb-4">
                    <small class="text-muted d-block">Kode Pesanan</small>
                    <span class="fw-bold fs-4"><?= e($o['id_sewa']) ?></span>
                    <?php if ($tampilan !== 'batal'): ?>
                        <svg id="barcodePesanan" class="d-block mx-auto mt-2 mw-100" data-kode="<?= e($o['id_sewa']) ?>"></svg>
                        <small class="text-muted">Tunjukkan kode ini ke operator saat unit diserahkan.</small>
                    <?php endif; ?>
                </div>

                <div class="row g-2 small mb-4">
                    <div class="col-6 text-muted">Paket</div>
                    <div class="col-6 text-end fw-semibold"><?= e($o['nama_paket']) ?></div>
                    <div class="col-6 text-muted">Durasi</div>
                    <div class="col-6 text-end fw-semibold"><?= e($durasiTeks) ?></div>
                    <div class="col-6 text-muted">Mulai</div>
                    <div class="col-6 text-end fw-semibold"><?= e($tgl($o['waktu_mulai'])) ?></div>
                    <div class="col-6 text-muted">Kembali</div>
                    <div class="col-6 text-end fw-semibold"><?= e($tgl($o['waktu_kembali_rencana'])) ?></div>
                    <div class="col-6 text-muted">Total</div>
                    <div class="col-6 text-end fw-bold text-primary"><?= e($rp($nominal)) ?></div>
                </div>

                <?php if ($tampilan === 'verifikasi'): ?>
                    <p class="small text-muted">Unit tetap ditahan untuk Anda selama proses verifikasi. Bila bukti ditolak, Anda bisa mengirim ulang dari halaman ini.</p>
                <?php elseif ($tampilan === 'lunas'): ?>
                    <p class="small text-muted">Datang ke <?= e($o['nama_cabang']) ?> (<?= e($o['alamat']) ?>) pada jadwal mulai sewa dan bawa jaminan asli (KTP / SIM).</p>
                <?php endif; ?>

                <div class="d-flex gap-2 flex-wrap">
                    <a href="<?= e($base) ?>pelanggan/Pesanan.php" class="btn btn-primary rounded-pill px-4">Lihat Pesanan Saya</a>
                    <a href="<?= e($base) ?>index.php" class="btn btn-light rounded-pill px-4">Kembali ke Beranda</a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script src="<?= e($base) ?>assets/js/pembayaran_sewa.js" defer></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
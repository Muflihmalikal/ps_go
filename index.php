<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = "PS Rental - Beranda Pelanggan";
include __DIR__ . '/includes/header.php';

// Stok dihitung database (view v_stok_paket, migrasi 002):
//  - paket biasa : unit berstatus 'tersedia' yang tidak sedang dialokasikan ke pesanan aktif
//  - paket combo : jumlah SET lengkap yang bisa dibentuk dari komponen yang masih bebas
$stmt = $pdo->prepare("
    SELECT p.id_paket, p.nama_paket, p.kategori, p.deskripsi, p.gambar,
           p.harga_wk_12h, p.harga_wk_24h, p.harga_wn_12h, p.harga_wn_24h,
           p.label_badge, p.tag_highlight,
           s.stok_tersedia
      FROM paket_sewa p
      JOIN cabang c       ON c.id_cabang = p.id_cabang
      JOIN v_stok_paket s ON s.id_paket  = p.id_paket
     WHERE p.override_status = 'auto' AND p.is_active AND c.is_active
     ORDER BY p.id_paket
");
$stmt->execute();
$katalog = $stmt->fetchAll();

// Nama tombol filter per kategori (kunci = nilai kolom paket_sewa.kategori).
$namaKategori = [
  'ps3'   => 'PlayStation 3',
  'ps4'   => 'PlayStation 4',
  'ps5'   => 'PlayStation 5',
  'combo' => 'Paket Combo',
  'tv'    => 'TV LED',
];
// Gambar cadangan bila kolom gambar kosong / tidak valid (semua file ini ada di assets/img).
$gambarBawaan = [
  'ps3'   => 'assets/img/ps3.png',
  'ps4'   => 'assets/img/ps4.png',
  'ps5'   => 'assets/img/ps5-banner.png',
  'combo' => 'assets/img/paket-ps4.png',
  'tv'    => 'assets/img/tv-43.png',
];
$jumlahKategori = array_count_values(array_column($katalog, 'kategori'));
?>

<div class="app-card">
  <div class="katalog-header mb-4">
    <div>
      <small class="text-muted fw-bold d-block mb-1"><i class="bi bi-grid-fill me-1"></i> KATALOG KONSOL & PAKET</small>
      <h3 class="fw-bold text-dark mb-1">Pilih Unit & Paket Rental</h3>
      <p class="text-muted small mb-4">
        Cek status ketersediaan unit secara real-time dan booking sebelum kehabisan.
      </p>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-primary btn-sm px-3 rounded-pill fw-semibold tombol-kategori"
          onclick="ubahKategori(this, 'semua')">
          Semua Unit (<?= count($katalog) ?>)
        </button>
        <?php foreach ($namaKategori as $kode => $label): ?>
          <?php if (empty($jumlahKategori[$kode])) continue; ?>
          <button class="btn bg-light border text-secondary btn-sm px-3 rounded-pill tombol-kategori"
            onclick="ubahKategori(this, '<?= e($kode) ?>')">
            <?= e($label) ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="katalog-aksi">
      <div class="wadah-toggle">
        <div id="sliderTarif" class="slider-biru"></div>
        <button id="teksWeekday" class="btn tombol-toggle fw-bold text-white" onclick="geserTarif(false)">
          Tarif Weekday (Sen-Kam)
        </button>
        <button id="teksWeekend" class="btn tombol-toggle fw-semibold text-muted" onclick="geserTarif(true)">
          Tarif Weekend (Jum-Min)
        </button>
      </div>

      <label class="kotak-sedia bg-light border rounded-3 px-3 py-2 d-flex align-items-center cursor-pointer">
        <input class="form-check-input me-2 mt-0 border-secondary" type="checkbox" id="cekSedia" />
        <span class="fw-semibold text-dark">Hanya yang Tersedia</span>
      </label>
    </div>
  </div>

  <?php if (!$katalog): ?>
    <div class="alert alert-light border text-center text-muted mb-0">
      <i class="bi bi-box-seam me-1"></i> Belum ada paket yang tersedia saat ini.
    </div>
  <?php endif; ?>

  <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-3 g-md-4">
    <?php foreach ($katalog as $item): ?>
      <?php
      $kat    = (string)$item['kategori'];
      $stok   = (int)$item['stok_tersedia'];
      $satuan = $kat === 'combo' ? 'Set' : 'Unit';

      // Hanya terima path relatif sederhana (tanpa URL luar, tanpa "..").
      $gambar = (string)($item['gambar'] ?? '');
      if (
        $gambar === '' || !preg_match('#^[A-Za-z0-9_./-]+$#', $gambar)
        || str_contains($gambar, '..') || str_starts_with($gambar, '/')
      ) {
        $gambar = $gambarBawaan[$kat] ?? $gambarBawaan['ps4'];
      }
      ?>
      <div data-unit="<?= e($kat) ?>"
        class="col item-produk <?= e($kat) ?>"
        data-wk12="<?= e($item['harga_wk_12h']) ?>"
        data-wk24="<?= e($item['harga_wk_24h']) ?>"
        data-wn12="<?= e($item['harga_wn_12h']) ?>"
        data-wn24="<?= e($item['harga_wn_24h']) ?>"
        data-stok="<?= $stok ?>">

        <div class="card kartu-gerak h-100 border-0 shadow-sm rounded-4 p-2 bg-light">

          <div class="d-flex justify-content-between px-2 pt-2">
            <?php if ($stok > 0): ?>
              <span class="text-success small fw-bold"><i class="bi bi-circle-fill me-1 fz-px-8"></i> Tersedia (<?= $stok ?> <?= $satuan ?>)</span>
            <?php else: ?>
              <span class="text-danger small fw-bold"><i class="bi bi-x-circle-fill me-1 fz-px-8"></i> Habis saat ini</span>
            <?php endif; ?>
            <span class="text-secondary small fw-bold fz-px-10"><?= e($item['label_badge']) ?></span>
          </div>

          <div class="bg-white border rounded-3 d-flex justify-content-center align-items-center my-2 position-relative produk-thumb">
            <span class="badge bg-dark position-absolute bottom-0 start-0 m-2 opacity-75">
              <?= e($item['tag_highlight'] ?? 'Included') ?>
            </span>
            <img class="produk-img" src="assets/img/<?= e($gambar) ?>" alt="<?= e($item['nama_paket']) ?>" />
          </div>

          <div class="card-body p-2 d-flex flex-column">
            <h6 class="fw-bold text-dark mb-1"><?= e($item['nama_paket']) ?></h6>
            <p class="text-muted mb-4 fz-75"><?= e($item['deskripsi']) ?></p>

            <div class="mt-auto">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-muted small">Sewa 12 Jam:</span>
                <span class="fw-semibold small" data-harga="j12">Rp <?= number_format((float)$item['harga_wk_12h'], 0, ',', '.') ?></span>
              </div>
              <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="text-muted small">Sewa 24 Jam:</span>
                <span class="fw-bold text-primary fs-6" data-harga="j24">Rp <?= number_format((float)$item['harga_wk_24h'], 0, ',', '.') ?></span>
              </div>

              <a href="pelanggan/form_sewa.php?id_paket=<?= e(encrypt_id($item['id_paket'])) ?>&tarif=weekday"
                class="btn btn-sewa <?= $stok > 0 ? 'btn-primary' : 'btn-outline-primary' ?> w-100 rounded-3 py-2 fw-semibold fz-90">
                <i class="bi bi-bag-plus me-1"></i> <?= $stok > 0 ? 'Sewa Sekarang' : 'Booking Jadwal Lain' ?>
              </a>
            </div>
          </div>

        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
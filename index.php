<?php
$pageTitle = "PS Rental - Beranda Pelanggan";
require_once __DIR__ . '/includes/koneksi.php';
include __DIR__ . '/includes/header.php';

$query = "
    SELECT p.*, 
           (SELECT COUNT(u.id_unit) 
            FROM unit_ps u 
            WHERE u.id_paket = p.id_paket AND u.status_unit = 'tersedia'
           ) as stok_tersedia
    FROM paket_sewa p
    WHERE p.override_status = 'auto'
";
$stmt = $pdo->prepare($query);
$stmt->execute();
$katalog = $stmt->fetchAll();
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
          Semua Unit (6)
        </button>
        <button class="btn bg-light border text-secondary btn-sm px-3 rounded-pill tombol-kategori"
          onclick="ubahKategori(this, 'ps4')">
          PlayStation 4
        </button>
        <button class="btn bg-light border text-secondary btn-sm px-3 rounded-pill tombol-kategori"
          onclick="ubahKategori(this, 'ps3')">
          PlayStation 3
        </button>
        <button class="btn bg-light border text-secondary btn-sm px-3 rounded-pill tombol-kategori"
          onclick="ubahKategori(this, 'playbox')">
          Paket Playbox
        </button>
        <button class="btn bg-light border text-secondary btn-sm px-3 rounded-pill tombol-kategori"
          onclick="ubahKategori(this, 'tv')">
          TV LED
        </button>
        <button class="btn bg-light border text-secondary btn-sm px-3 rounded-pill tombol-kategori"
          onclick="ubahKategori(this, 'paket')">
          Paket
        </button>
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

  <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-3 g-md-4">
    <?php foreach ($katalog as $item): ?>
      <div data-unit="<?= htmlspecialchars($item['kategori']) ?>"
        class="col item-produk <?= htmlspecialchars($item['kategori']) ?>"
        data-wk12="<?= $item['harga_wk_12h'] ?>"
        data-wk24="<?= $item['harga_wk_24h'] ?>"
        data-wn12="<?= $item['harga_wn_12h'] ?>"
        data-wn24="<?= $item['harga_wn_24h'] ?>"
        data-stok="<?= $item['stok_tersedia'] ?>">

        <div class="card kartu-gerak h-100 border-0 shadow-sm rounded-4 p-2 bg-light">

          <div class="d-flex justify-content-between px-2 pt-2">
            <?php if ($item['stok_tersedia'] > 0): ?>
              <span class="text-success small fw-bold"><i class="bi bi-circle-fill me-1 fz-px-8"></i> Tersedia (<?= $item['stok_tersedia'] ?> Unit)</span>
            <?php else: ?>
              <span class="text-danger small fw-bold"><i class="bi bi-x-circle-fill me-1 fz-px-8"></i> Habis</span>
            <?php endif; ?>
            <span class="text-secondary small fw-bold fz-px-10"><?= htmlspecialchars($item['label_badge']) ?></span>
          </div>

          <div class="bg-white border rounded-3 d-flex justify-content-center align-items-center my-2 position-relative produk-thumb">
            <span class="badge bg-dark position-absolute bottom-0 start-0 m-2 opacity-75">
              <?= htmlspecialchars($item['tag_highlight'] ?? 'Included') ?>
            </span>
            <img class="produk-img" src="<?= htmlspecialchars($item['gambar'] ?? 'assets/img/default.png') ?>" alt="<?= htmlspecialchars($item['nama_paket']) ?>" />
          </div>

          <div class="card-body p-2 d-flex flex-column">
            <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($item['nama_paket']) ?></h6>
            <p class="text-muted mb-4 fz-75"><?= htmlspecialchars($item['deskripsi']) ?></p>

            <div class="mt-auto">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-muted small">Sewa 12 Jam:</span>
                <span class="fw-semibold small" data-harga="j12">Rp <?= number_format($item['harga_wk_12h'], 0, ',', '.') ?></span>
              </div>
              <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="text-muted small">Sewa 24 Jam:</span>
                <span class="fw-bold text-primary fs-6" data-harga="j24">Rp <?= number_format($item['harga_wk_24h'], 0, ',', '.') ?></span>
              </div>

              <a href="pelanggan/form_sewa.php?id_paket=<?= $item['id_paket'] ?>&tarif=weekday" class="btn <?= $item['stok_tersedia'] > 0 ? 'btn-primary' : 'btn-secondary disabled' ?> w-100 rounded-3 py-2 fw-semibold fz-90">
                <i class="bi bi-bag-plus me-1"></i> <?= $item['stok_tersedia'] > 0 ? 'Sewa Sekarang' : 'Antre / Booking' ?>
              </a>
            </div>
          </div>

        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
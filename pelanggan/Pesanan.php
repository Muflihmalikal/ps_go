<?php
$pageTitle = "PS Rental - Pesanan";
// require_once __DIR__ . '/../includes/koneksi.php';
// session_start();
// $id_user_login = $_SESSION['id_user'] ?? 0;
// $id_sewa_input = $_GET['id_sewa'] ?? '';

// $stmt = $pdo->prepare("
//     SELECT p.*, pb.status_bayar 
//     FROM penyewaan p
//     LEFT JOIN pembayaran pb ON p.id_sewa = pb.id_sewa
//     WHERE p.id_sewa = :id_sewa 
//       AND p.id_user = :id_user_login
// ");

// $stmt->execute([
//   'id_sewa' => $id_sewa_input,
//   'id_user_login' => $id_user_login
// ]);

// $pesanan = $stmt->fetch();

// if (!$pesanan) {
//   die("Akses Ditolak: Anda tidak memiliki akses ke pesanan ini.");
// }
include __DIR__ . '/../includes/header.php';
?>
<div class="app-card">
  <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start gap-3 mb-4">
    <div>
      <h3 class="fw-bold text-dark mb-1">Pesanan Saya</h3>
      <p class="text-muted small mb-0">Pantau status rental konsol PlayStation aktif, countdown waktu bermain,
        dan riwayat peminjaman.</p>
    </div>
    <a href="form_sewa.html" class="btn btn-primary btn-sm px-4 py-2 rounded-pill fw-semibold shadow-sm">
      <i class="bi bi-plus-lg me-1"></i> Sewa Unit Lagi
    </a>
  </div>

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="d-flex flex-wrap gap-2">
      <button class="btn bg-light border text-secondary btn-sm px-3 rounded-pill" id="filterAll"
        onclick="filterTab('all')">Semua Pesanan (4)</button>
      <button class="btn btn-primary btn-sm px-3 rounded-pill fw-semibold" id="filterActive"
        onclick="filterTab('active')">Sedang Berlangsung (2)</button>
      <button class="btn bg-light border text-secondary btn-sm px-3 rounded-pill" id="filterDone"
        onclick="filterTab('done')">Selesai (2)</button>
      <button class="btn bg-light border text-secondary btn-sm px-3 rounded-pill" id="filterCancel"
        onclick="filterTab('cancel')">Dibatalkan (0)</button>
    </div>
  </div>
</div>

<div class="mb-5" id="sectionBerlangsung">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-3">
    <h6 class="fw-bold mb-0 d-flex align-items-center gap-2">
      <i class="bi bi-circle-fill text-primary fz-px-10"></i> Pesanan Sedang Berlangsung
      <span class="badge bg-primary-subtle text-primary rounded-pill">2 Konsol</span>
    </h6>
  </div>

  <div class="row row-cols-1 row-cols-xl-2 g-4">

    <div class="col order-card" data-title="ps4 slim playstation 4 #gg-20251024-0089">
      <div class="card-custom p-3 p-md-4 h-100 d-flex flex-column justify-content-between">
        <div>
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom pb-2 mb-3">
            <span class="text-muted small"><strong>#GG-20251024-0089</strong> • Sewa 24 Jam</span>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">
              <i class="bi bi-controller me-1"></i> Sedang Aktif Dimainkan
            </span>
          </div>

          <div class="d-flex gap-3 align-items-center mb-3">
            <img src="https://images.unsplash.com/photo-1606813907291-d86efa9b94db?w=300&auto=format&fit=crop&q=80"
              class="rounded-3 border flex-shrink-0 shadow-sm obj-cover" width="90" height="70">
            <div>
              <h6 class="fw-bold mb-1 text-dark">PlayStation 4 Slim 500GB</h6>
              <div class="d-flex flex-wrap gap-2 mt-2">
                <span class="badge bg-light text-dark border"><i class="bi bi-truck text-primary me-1"></i>
                  Diantar ke Rumah</span>
                <span class="badge bg-success-subtle text-success border border-success-subtle"><i
                    class="bi bi-check-lg me-1"></i> Lunas (QRIS)</span>
              </div>
            </div>
          </div>

          <div class="bg-primary-subtle bg-opacity-25 p-3 rounded-4 border border-primary-subtle mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-2">
              <span class="fw-bold text-dark small"><i class="bi bi-clock text-primary me-1"></i> Sisa Waktu
                Bermain:</span>
              <span class="fw-bold fs-6 text-primary" id="liveCountdownPlay">19 Jam 19 Menit 53 Detik</span>
            </div>
            <div class="progress mb-2 bg-light border border-light progress-waktu">
              <div class="progress-bar progress-animated-striped progress-bar-waktu bg-primary" id="liveProgressBar"
                role="progressbar" style="width: 80%;"></div>
            </div>
            <div class="d-flex flex-wrap justify-content-between gap-1 text-muted mt-1 fz-65">
              <span>Mulai: 24 Okt 2025, 14:00</span>
              <span>Berakhir: Besok, 25 Okt 2025, 14:00</span>
            </div>
          </div>

          <div class="row g-2 mb-3 text-muted fz-75">
            <div class="col-12 col-sm-6">
              <div class="bg-light p-2 rounded-3 border h-100">
                <strong class="text-dark d-block mb-1"><i class="bi bi-geo-alt text-danger me-1"></i> Alamat
                  Pengiriman</strong>
                Rogotrunan, Jl. Suwandak No. 45, Lumajang
              </div>
            </div>
            <div class="col-12 col-sm-6">
              <div class="bg-light p-2 rounded-3 border h-100">
                <strong class="text-dark d-block mb-1"><i class="bi bi-card-heading text-primary me-1"></i>
                  Jaminan Identitas</strong>
                KTP Fisik Asli <span class="text-success fw-medium">(Diverifikasi Kurir)</span>
              </div>
            </div>
          </div>
        </div>

        <div class="d-flex align-items-center justify-content-between gap-2 pt-2">
          <button class="btn btn-danger btn-sm w-50 fw-semibold rounded-pill py-2" data-bs-toggle="modal"
            data-bs-target="#modalStopKembalikan">Stop & Kembalikan</button>
          <button class="btn btn-primary btn-sm w-50 fw-semibold rounded-pill py-2"
            onclick="tambahWaktuSewa()">Perpanjang Sewa</button>
        </div>
      </div>
    </div>

    <div class="col order-card" data-title="paket playbox ps4 tv led #gg-20251023-0074">
      <div class="card-custom p-3 p-md-4 h-100 d-flex flex-column justify-content-between" id="cardPaketPlaybox">
        <div>
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom pb-2 mb-3">
            <span class="text-muted small"><strong>#GG-20251023-0074</strong> • Paket Playbox 24 Jam</span>
            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill"
              id="statusBadgeDelivery">
              <i class="bi bi-truck me-1"></i> Sedang Dikirim Kurir
            </span>
          </div>

          <div class="d-flex gap-3 align-items-center mb-3">
            <img src="https://images.unsplash.com/photo-1550745165-9bc0b252726f?w=300&auto=format&fit=crop&q=80"
              class="rounded-3 border flex-shrink-0 shadow-sm obj-cover" width="90" height="70">
            <div>
              <h6 class="fw-bold mb-1 text-dark">Paket Playbox PS4 + TV LED 43"</h6>
              <div class="fw-bold text-dark small mt-2">Total: Rp 120.000 <span
                  class="text-muted fw-normal">(COD)</span></div>
            </div>
          </div>

          <div class="stepper-box mb-3 border shadow-sm stepper-box-kurir">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-3">
              <span class="fw-bold text-primary small" id="titleEstimasi"><i class="bi bi-scooter me-1"></i>
                Estimasi Sampai Lokasi:</span>
              <span class="fw-bold fs-6 text-primary" id="liveCountdownDelivery">± 12 Menit</span>
            </div>

            <div class="d-flex justify-content-between text-center position-relative pt-1 px-3">
              <div class="stepper-line-bg"></div>
              <div class="stepper-line-progress" id="kurirProgressBar"></div>

              <div class="d-flex flex-column align-items-center">
                <div class="stepper-dot bg-primary text-white" id="dotToko"><i class="bi bi-check"></i></div>
                <span class="text-muted mt-2 fz-65">Dicek Toko</span>
              </div>

              <div class="d-flex flex-column align-items-center">
                <div class="stepper-dot bg-primary text-white dot-animating border border-2 border-white" id="dotKurir">
                  <i class="bi bi-scooter fs-6" id="iconKurir"></i>
                </div>
                <span class="fw-bold text-primary mt-2 fz-65" id="textKurir">Otw
                  Kurir</span>
              </div>

              <div class="d-flex flex-column align-items-center">
                <div class="stepper-dot bg-secondary-subtle text-muted" id="dotTiba"><i class="bi bi-house"
                    id="iconTiba"></i></div>
                <span class="text-muted mt-2 fz-65" id="textTiba">Tiba & Pasang</span>
              </div>
            </div>
          </div>

          <div class="row g-2 mb-3 text-muted fz-75">
            <div class="col-12 col-sm-6">
              <div class="bg-light p-2 rounded-3 border d-flex align-items-center gap-2 h-100">
                <img src="https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=100&auto=format&fit=crop&q=80"
                  class="btn-circle-fixed shadow-sm">
                <div class="lh-sm">
                  <strong class="text-dark d-block">Mas Dimas</strong>
                  <span class="fz-65">Honda Vario (N 4381 YD)</span>
                </div>
              </div>
            </div>
            <div class="col-12 col-sm-6">
              <div class="bg-light p-2 rounded-3 border h-100">
                <strong class="text-dark d-block">Persiapan Jaminan</strong>
                <span class="text-danger fw-medium fz-65">Siapkan STNK / KTP</span>
              </div>
            </div>
          </div>
        </div>

        <div class="d-flex gap-2 pt-2">
          <button class="btn btn-outline-custom btn-sm w-40 fw-semibold rounded-pill py-2" data-bs-toggle="modal"
            data-bs-target="#modalQRTerima">
            <i class="bi bi-qr-code me-1"></i> QR Terima
          </button>
          <button class="btn btn-primary btn-sm w-60 fw-semibold rounded-pill py-2 shadow-sm" data-bs-toggle="modal"
            data-bs-target="#trackingModal">
            <i class="bi bi-geo-alt me-1"></i> Lacak Pengantaran
          </button>
        </div>
      </div>
    </div>

  </div>
</div>

<div class="card-custom p-3 p-md-4 mb-4" id="sectionSelesai">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3 border-bottom pb-3">
    <div>
      <h6 class="fw-bold mb-1 text-dark"><i class="bi bi-clock-history me-2 text-primary"></i> Riwayat Peminjaman
        Selesai</h6>
      <span class="text-muted fz-75">Daftar transaksi konsol yang telah berhasil
        dikembalikan</span>
    </div>
    <div class="riwayat-aksi">
      <div class="input-group input-group-sm riwayat-cari">
        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
        <input type="search" class="form-control" id="cariRiwayat" placeholder="Cari unit atau no. pesanan"
          aria-label="Cari riwayat peminjaman" autocomplete="off">
      </div>
      <button type="button" id="btnUnduhRiwayat"
        class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-semibold fz-75">
        Unduh Semua <i class="bi bi-download ms-1"></i>
      </button>
    </div>
  </div>

  <div class="riwayat-filter mb-3">
    <span class="text-muted fz-75 fw-semibold"><i class="bi bi-calendar-range me-1"></i> Tanggal selesai</span>
    <input type="date" class="form-control form-control-sm" id="tglDari" aria-label="Dari tanggal">
    <span class="text-muted fz-75">s/d</span>
    <input type="date" class="form-control form-control-sm" id="tglSampai" aria-label="Sampai tanggal">
    <button type="button" id="btnResetRiwayat" class="btn btn-light btn-sm border rounded-pill px-3 fz-75">
      Reset
    </button>
  </div>

  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 table-riwayat fz-82" id="tabelRiwayat">
      <thead class="table-light text-muted">
        <tr>
          <th class="ps-3 border-0 rounded-start">Unit & No. Pesanan</th>
          <th class="border-0">Durasi & Tanggal</th>
          <th class="border-0">Total Biaya</th>
          <th class="border-0">Status Jaminan</th>
          <th class="border-0">Status Sewa</th>
          <th class="text-center pe-3 border-0 rounded-end">Tindakan</th>
        </tr>
      </thead>
      <tbody class="border-top-0">
        <tr data-tanggal="2025-10-19">
          <td class="ps-3 pt-3">
            <div class="d-flex align-items-center gap-3">
              <img src="https://images.unsplash.com/photo-1526509867162-5b0c0d1b4b33?w=100&auto=format&fit=crop&q=80"
                class="rounded border flex-shrink-0 obj-cover" width="45" height="35">
              <div>
                <strong class="text-dark d-block">PlayStation 3 Super Slim</strong>
                <span class="text-muted fz-70">#GG-20251018-0052</span>
              </div>
            </div>
          </td>
          <td class="pt-3">
            <strong class="text-dark d-block">24 Jam (1 Hari)</strong>
            <span class="text-muted fz-70">Selesai: 19 Okt 2025</span>
          </td>
          <td class="pt-3">
            <strong class="text-dark">Rp 50.000</strong>
            <span class="text-muted d-block fz-70">Tunai / Selesai</span>
          </td>
          <td class="pt-3"><span
              class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">✓ KTP
              Utuh</span></td>
          <td class="pt-3"><span class="badge bg-secondary-subtle text-secondary border rounded-pill">Selesai</span>
          </td>
          <td class="text-center pe-3 pt-3">
            <a href="form_sewa.html" class="btn btn-primary btn-sm px-3 py-1 rounded-pill fz-75"><i
                class="bi bi-arrow-repeat me-1"></i> Sewa Lagi</a>
          </td>
        </tr>
        <tr data-tanggal="2025-10-11">
          <td class="ps-3 pt-3 pb-3">
            <div class="d-flex align-items-center gap-3">
              <img src="https://images.unsplash.com/photo-1606813907291-d86efa9b94db?w=100&auto=format&fit=crop&q=80"
                class="rounded border flex-shrink-0 obj-cover" width="45" height="35">
              <div>
                <strong class="text-dark d-block">PlayStation 4 Slim</strong>
                <span class="text-muted fz-70">#GG-20251010-0031</span>
              </div>
            </div>
          </td>
          <td class="pt-3 pb-3">
            <strong class="text-dark d-block">24 Jam (Weekend)</strong>
            <span class="text-muted fz-70">Selesai: 11 Okt 2025</span>
          </td>
          <td class="pt-3 pb-3">
            <strong class="text-dark">Rp 80.000</strong>
            <span class="text-muted d-block fz-70">Transfer / Lunas</span>
          </td>
          <td class="pt-3 pb-3"><span
              class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">✓ SIM C
              Utuh</span></td>
          <td class="pt-3 pb-3"><span
              class="badge bg-secondary-subtle text-secondary border rounded-pill">Selesai</span></td>
          <td class="text-center pe-3 pt-3 pb-3">
            <a href="form_sewa.html" class="btn btn-primary btn-sm px-3 py-1 rounded-pill fz-75"><i
                class="bi bi-arrow-repeat me-1"></i> Sewa Lagi</a>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

</main>

<!-- ================= MODAL 1: VALIDASI SERAH TERIMA UNIT ================= -->
<div class="modal fade" id="modalQRTerima" tabindex="-1" aria-labelledby="modalQRTerimaLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden">

      <div class="modal-header border-bottom-0 pb-0 pt-4 px-4 align-items-start">
        <div>
          <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
            <i class="bi bi-patch-check-fill text-primary fs-4"></i>
            <h5 class="modal-title fw-bold text-dark" id="modalQRTerimaLabel">Validasi Serah Terima Unit</h5>
            <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-1 fw-medium fz-75">
              <i class="bi bi-circle-fill text-primary me-1 fz-px-6"></i> Siap Validasi / Scan
            </span>
          </div>
          <p class="text-muted small mb-0">Tunjukkan barcode/QR code ini kepada kurir atau petugas Galaxy Game saat
            penyerahan unit di lokasi.</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body p-3 p-sm-4">

        <div class="bg-modal-info p-3 mb-4">
          <div class="row g-3 text-start fz-80">
            <div class="col-md-4 border-end-md">
              <span class="text-muted d-block mb-1">No. Pesanan</span>
              <strong class="text-primary d-block fw-bold fs-6">#GG-20251023-0074</strong>
              <span class="text-muted small">Playbox PS4 + TV 43"</span>
            </div>
            <div class="col-md-4 border-end-md">
              <span class="text-muted d-block mb-1">Nama Pelanggan</span>
              <strong class="text-dark d-block fw-bold fs-6">Alya Pratama</strong>
              <span class="text-muted small">Rogotrunan, Lumajang</span>
            </div>
            <div class="col-md-4">
              <span class="text-muted d-block mb-1">Kurir Bertugas</span>
              <strong class="text-primary d-block fw-bold fs-6">Mas Dimas Prasetyo</strong>
              <span class="text-muted small">Honda Vario (N 4381 YD)</span>
            </div>
          </div>
        </div>

        <div class="card border rounded-4 p-3 p-sm-4 text-center shadow-sm mb-4 qr-scan-area"
          onclick="handleAdminScan('terima')" title="Klik untuk simulasi scan admin">
          <span class="badge bg-success-subtle text-success position-absolute top-0 end-0 m-3 rounded-pill fz-68">
            <i class="bi bi-qr-code-scan me-1"></i> Simulasi Scan Admin
          </span>
          <div class="row align-items-center g-4">

            <div class="col-md-5 border-end-md">
              <div class="p-2 d-inline-block bg-white rounded-3 border shadow-xs">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=GG-SRH-7492-LUM"
                  alt="QR Code Serah Terima" class="img-fluid" width="150" height="150">
              </div>
            </div>

            <div class="col-md-7">
              <div class="barcode-line-container mb-2 px-3">
                <div class="barcode-bar bar-4"></div>
                <div class="barcode-bar bar-2"></div>
                <div class="barcode-bar bar-6"></div>
                <div class="barcode-bar bar-1"></div>
                <div class="barcode-bar bar-5"></div>
                <div class="barcode-bar bar-3"></div>
                <div class="barcode-bar bar-2"></div>
                <div class="barcode-bar bar-8"></div>
                <div class="barcode-bar bar-2"></div>
                <div class="barcode-bar bar-4"></div>
                <div class="barcode-bar bar-1"></div>
                <div class="barcode-bar bar-6"></div>
                <div class="barcode-bar bar-3"></div>
                <div class="barcode-bar bar-2"></div>
                <div class="barcode-bar bar-5"></div>
                <div class="barcode-bar bar-2"></div>
                <div class="barcode-bar bar-4"></div>
                <div class="barcode-bar bar-7"></div>
                <div class="barcode-bar bar-1"></div>
                <div class="barcode-bar bar-3"></div>
              </div>

              <div class="d-inline-flex align-items-center gap-2 bg-light border px-3 py-1 rounded-3 mb-3">
                <span class="fw-bold tracking-wider text-dark kode-lg">GG-SRH-7492-LUM</span>
                <button class="btn btn-sm btn-link text-muted p-0 border-0"
                  onclick="event.stopPropagation(); navigator.clipboard.writeText('GG-SRH-7492-LUM')"
                  title="Salin Kode">
                  <i class="bi bi-copy"></i>
                </button>
              </div>

              <div class="text-muted small">
                <i class="bi bi-clock me-1"></i> Berlaku sampai kurir selesai serah terima unit
              </div>
            </div>

          </div>
        </div>

        <div class="bg-modal-info p-3 mb-2">
          <div class="fw-bold text-dark small mb-2 text-uppercase ls-05 fz-72">
            Checklist Serah Terima Bersama Kurir:
          </div>
          <div class="d-flex flex-column gap-2 fz-80">
            <div class="d-flex align-items-center text-dark">
              <i class="bi bi-check-square-fill text-primary me-2 fs-6"></i> Unit Konsol PS4, Monitor LED 43", & Kabel
              HDMI Lengkap
            </div>
            <div class="d-flex align-items-center text-dark">
              <i class="bi bi-check-square-fill text-primary me-2 fs-6"></i> 4 Stik DualShock Wireless (Sudah Ditest
              Normal & Baterai Siap)
            </div>
            <div class="d-flex align-items-center text-dark">
              <i class="bi bi-check-square-fill text-primary me-2 fs-6"></i> Jaminan Fisik KTP/STNK Asli Diserahkan
              Aman ke Kurir
            </div>
          </div>
        </div>

      </div>

      <div class="modal-footer border-top-0 px-4 pb-4 pt-0 d-flex justify-content-center">
        <a href="https://wa.me/6288973539946" target="_blank"
          class="btn btn-light btn-md rounded-pill px-4 fw-semibold border">
          <i class="bi bi-chat-left-text me-2"></i> Hubungi Kurir via WA
        </a>
      </div>

    </div>
  </div>
</div>

<!-- ================= MODAL 2: VALIDASI PENGEMBALIAN UNIT & JAMINAN ================= -->
<div class="modal fade" id="modalStopKembalikan" tabindex="-1" aria-labelledby="modalStopKembalikanLabel"
  aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden">

      <div class="modal-header border-bottom-0 pb-0 pt-4 px-4 align-items-start">
        <div>
          <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-1 mb-2 fw-medium fz-75">
            <i class="bi bi-box-arrow-in-down me-1"></i> Tahap Selesai Sewa / Pengembalian
          </span>
          <h5 class="modal-title fw-bold text-dark" id="modalStopKembalikanLabel">Validasi Pengembalian Unit & Jaminan
          </h5>
          <p class="text-muted small mb-0 mt-1">Tunjukkan QR code atau barcode ini kepada kurir penjemput atau kasir
            cabang untuk verifikasi serah terima pengembalian konsol dan pengambilan kembali jaminan Anda.</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body p-3 p-sm-4">

        <div class="bg-modal-info p-3 mb-4">
          <div class="row g-3 text-start fz-80">
            <div class="col-md-6 border-end-md">
              <span class="text-muted d-block mb-1">No. Pesanan / Konsol:</span>
              <strong class="text-primary d-block fw-bold fs-6">#GG-20251024-0089</strong>
              <span class="text-dark fw-medium small">PlayStation 4 Slim 500GB (4 Stik)</span>
            </div>
            <div class="col-md-6">
              <span class="text-muted d-block mb-1">Kurir Penjemput / Toko:</span>
              <strong class="text-primary d-block fw-bold fs-6"><i class="bi bi-scooter me-1"></i> Mas Dimas
                Prasetyo</strong>
              <span class="text-muted small">Honda Vario (N 4381 YD) • 0889-7353-9946</span>
            </div>
          </div>
        </div>

        <div class="qr-dashed-box p-3 p-sm-4 text-center mb-4 qr-scan-area" onclick="handleAdminScan('kembali')"
          title="Klik untuk simulasi scan admin">
          <span class="badge bg-success-subtle text-success rounded-pill mb-2 fz-68">
            <i class="bi bi-qr-code-scan me-1"></i> Simulasi Scan Admin
          </span>
          <div class="p-2 d-block bg-white rounded-3 mb-3">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=KEMBALI-PS4-889-LUM"
              alt="QR Pengembalian" class="img-fluid" width="160" height="160">
          </div>

          <div class="d-flex flex-wrap align-items-center justify-content-center gap-2 mb-2">
            <span class="fw-bold text-dark fs-5 kode-sm">KEMBALI-PS4-889-LUM</span>
            <button class="btn btn-sm btn-link text-muted p-0 border-0"
              onclick="event.stopPropagation(); navigator.clipboard.writeText('KEMBALI-PS4-889-LUM')"
              title="Salin Kode">
              <i class="bi bi-copy"></i>
            </button>
          </div>

          <div class="text-muted small">
            <span class="text-success fw-bold me-1">●</span> Scan oleh kurir/petugas Galaxy Game untuk menyelesaikan
            sewa
          </div>
        </div>

        <div class="bg-modal-info p-3 border border-primary-subtle rounded-3 mb-3">
          <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-2">
            <div class="d-flex align-items-center gap-3">
              <div class="bg-primary text-white p-2 rounded-3">
                <i class="bi bi-card-heading fs-4"></i>
              </div>
              <div>
                <h6 class="fw-bold text-primary mb-0">Pengembalian Dokumen Jaminan Fisik</h6>
                <strong class="text-dark small">KTP Asli an. Alya Pratama <span class="text-muted font-normal">(NIK:
                    350801xxxxxx0004)</span></strong>
              </div>
            </div>
            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1">
              <i class="bi bi-check-circle-fill me-1"></i> Tersimpan Kasir
            </span>
          </div>
          <p class="text-muted mb-0 fz-73">
            Wajib diserahkan kembali secara utuh dan diperiksa bersama saat scan pengembalian unit berhasil
            diverifikasi oleh kurir/kasir.
          </p>
        </div>

        <div class="bg-light p-3 rounded-3 border">
          <div class="fw-bold text-muted small mb-2 text-uppercase ls-05 fz-70">
            Checklist Serah Terima Fisik:
          </div>
          <div class="d-flex flex-column gap-2 fz-78">
            <div class="d-flex align-items-center text-dark">
              <i class="bi bi-check-square-fill text-primary me-2"></i> Unit PS4 Slim + 4 Kabel & Aksesori Terpasang
              Rapi
            </div>
            <div class="d-flex align-items-center text-dark">
              <i class="bi bi-check-square-fill text-primary me-2"></i> Dokumen KTP Fisik Diserahkan Kembali ke
              Pemilik
            </div>
          </div>
        </div>

      </div>

      <div class="modal-footer border-top-0 px-4 pb-4 pt-0 d-flex justify-content-center">
        <a href="https://wa.me/6288973539946" target="_blank"
          class="btn btn-light btn-md rounded-pill px-4 fw-semibold border">
          <i class="bi bi-chat-left-text me-2"></i> Hubungi Kurir / Petugas Toko
        </a>
      </div>

    </div>
  </div>
</div>

<!-- ================= MODAL SUCCESS NOTIFICATION (SESUAI GAMBAR) ================= -->
<div class="modal fade" id="modalSuccessStatus" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow-lg bg-transparent">
      <div class="custom-success-box text-center shadow-lg">
        <div class="custom-success-icon shadow-sm">
          <i class="bi bi-check-lg"></i>
        </div>
        <h4 class="fw-bold text-dark mb-2" id="successModalTitle">Pesanan Anda telah diterima</h4>
        <p class="text-muted mb-4 fz-95" id="successModalSub">Penjual sedang menyiapkan
          pesananmu</p>
        <button type="button" class="btn btn-success rounded-pill px-5 py-2 fw-semibold shadow-sm"
          data-bs-dismiss="modal">
          Selesai
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ================= MODAL TRACKING SHOPEE STYLE ================= -->
<div class="modal fade" id="trackingModal" tabindex="-1" aria-labelledby="trackingModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow">
      <div class="modal-header border-bottom-0 pb-0">
        <h6 class="modal-title fw-bold" id="trackingModalLabel"><i class="bi bi-truck text-primary me-2"></i> Lacak
          Status Pengantaran</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-3 p-sm-4">

        <div class="bg-light p-3 rounded-3 d-flex align-items-center justify-content-between mb-4 border">
          <div class="d-flex align-items-center gap-3">
            <img src="https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=100&auto=format&fit=crop&q=80"
              class="btn-circle-fixed">
            <div>
              <div class="fw-bold text-dark">Mas Dimas Prasetyo</div>
              <div class="text-muted small">Kurir Pengantar Galaxy Game</div>
              <div class="badge bg-primary-subtle text-primary fz-65">Honda Vario (N 4381 YD)
              </div>
            </div>
          </div>

          <a href="https://wa.me/6288973539946" target="_blank" class="btn btn-success btn-circle-fixed text-white"
            title="Hubungi WA">
            <i class="bi bi-whatsapp fs-6"></i>
          </a>
        </div>

        <h6 class="fw-bold mb-3 text-dark small">Rincian Perjalanan Pengiriman</h6>
        <div class="timeline-shopee">
          <div class="timeline-item active">
            <div class="fw-bold text-success small" id="shopeeStatusHead">Pesanan Sedang Dalam Perjalanan</div>
            <div class="text-muted fz-70" id="shopeeStatusSub">Kurir sedang mengarah ke rumah
              tujuan (Jl. Suwandak No. 45)</div>
          </div>
          <div class="timeline-item">
            <div class="fw-bold text-dark small">Pesanan Diserahkan ke Kurir</div>
            <div class="text-muted fz-70">14:00 WIB • Unit Paket Playbox diserahkan toko ke Mas
              Dimas</div>
          </div>
          <div class="timeline-item">
            <div class="fw-bold text-dark small">Pengecekan Unit & Packing</div>
            <div class="text-muted fz-70">13:45 WIB • Penyiapan konsol PS4 + TV LED 43" & testing
              kelengkapan</div>
          </div>
        </div>

        <div class="border-top pt-3 mt-3">
          <div class="fw-bold text-dark small mb-2"><i class="bi bi-camera me-1"></i> Bukti Serah Terima / Foto Kurir:
          </div>
          <div class="d-flex gap-2">
            <img src="https://images.unsplash.com/photo-1550745165-9bc0b252726f?w=300&auto=format&fit=crop&q=80"
              class="rounded border object-fit-cover flex-shrink-0" width="100" height="80" alt="Bukti Foto">
            <div class="bg-light p-2 rounded border flex-grow-1 fz-70">
              <span class="text-muted d-block">Status Foto:</span>
              <span class="badge bg-success-subtle text-success mb-1">Terverifikasi Sistem</span>
              <p class="text-muted mb-0">Foto otomatis dikirim oleh kurir saat unit diserahterimakan.</p>
            </div>
          </div>
        </div>

      </div>
      <div class="modal-footer border-top-0 pt-0">
        <button type="button" class="btn btn-secondary btn-sm rounded-pill w-100" data-bs-dismiss="modal">Tutup
          Lacak</button>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
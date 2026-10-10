<?php
$pageTitle = "PS Rental - Profil";
include __DIR__ . '/../includes/header.php';
?>

<div class="app-card">
  <div class="mb-4">
    <small class="text-muted fw-bold d-block mb-1"><i class="bi bi-person-vcard-fill me-1"></i> MANAJEMEN AKUN</small>
    <h3 class="fw-bold text-dark mb-1">Profil Saya</h3>
    <p class="text-muted small mb-0">Kelola informasi data diri, alamat pengiriman, dan keamanan akun Anda.</p>
  </div>

  <div class="row g-4">
    <div class="col-12 col-lg-4">
      <div class="card border shadow-sm rounded-4 h-100">
        <div class="card-body p-4 text-center">
          <div class="rounded-circle fw-bold d-flex align-items-center justify-content-center mx-auto mb-3 shadow-sm"
            style="width: 110px; height: 110px; flex-shrink: 0; background-color: #fff3cd; border: 4px solid #ffc107; color: #111827; font-size: 2.5rem;">
            AP
          </div>
          <h5 class="fw-bold text-dark mb-1">Alya Pratama</h5>
          <p class="text-muted small mb-3">alyapratama@email.com</p>

          <button id="btnEditProfile" class="btn btn-primary px-4 py-2 rounded-pill fw-semibold mb-4 w-100"
            onclick="mulaiEdit()">
            <i class="bi bi-pencil-square me-1"></i> Edit Profile
          </button>

          <div class="mb-4">
            <span
              class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-2 w-100 text-start d-block">
              <i class="bi bi-patch-check-fill me-1"></i> Akun Terverifikasi
            </span>
          </div>

          <hr class="text-muted opacity-25">

          <div class="d-flex justify-content-between align-items-center text-start small mb-3">
            <span class="text-muted fw-semibold">Nomor WhatsApp</span>
            <span class="fw-bold text-dark">0812-3456-7890</span>
          </div>
          <div class="d-flex justify-content-between align-items-center text-start small mb-3">
            <span class="text-muted fw-semibold">Total Pesanan</span>
            <span class="fw-bold text-dark">4 Transaksi</span>
          </div>
        </div>
      </div>
    </div>

    <!-- KOLOM KANAN -->
    <div class="col-12 col-lg-8">
      <div class="card border shadow-sm rounded-4 h-100">
        <div class="card-body p-3 p-md-4">
          <ul class="nav nav-tabs mb-4" id="profilTabs" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active fw-semibold" id="data-diri-tab" data-bs-toggle="tab"
                data-bs-target="#data-diri" type="button" role="tab">Data Diri</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link fw-semibold text-muted" id="alamat-tab" data-bs-toggle="tab"
                data-bs-target="#alamat" type="button" role="tab">Alamat Pengiriman</button>
            </li>
          </ul>

          <div class="tab-content" id="profilTabsContent">
            <div class="tab-pane fade show active" id="data-diri" role="tabpanel">
              <form>
                <div class="row g-3 mb-3">
                  <div class="col-12 col-sm-6">
                    <label class="form-label small fw-bold text-muted"><i
                        class="bi bi-person-fill text-primary me-1"></i> Nama Lengkap</label>
                    <input type="text" class="form-control form-readonly input-profil" value="Alya Pratama" readonly>
                  </div>
                  <div class="col-12 col-sm-6">
                    <label class="form-label small fw-bold text-muted"><i
                        class="bi bi-envelope-fill text-primary me-1"></i> Alamat Email</label>
                    <input type="email" class="form-control form-readonly input-profil" value="alyapratama@email.com"
                      readonly>
                  </div>
                </div>
                <div class="row g-3 mb-4">
                  <div class="col-12 col-sm-6">
                    <label class="form-label small fw-bold text-muted"><i
                        class="bi bi-telephone-fill text-primary me-1"></i> Nomor Telepon</label>
                    <input type="text" class="form-control form-readonly input-profil" value="081234567890" readonly>
                  </div>
                  <div class="col-12 col-sm-6">
                    <label class="form-label small fw-bold text-muted"><i
                        class="bi bi-calendar-event-fill text-primary me-1"></i> Tanggal Lahir</label>
                    <input type="date" class="form-control form-readonly input-profil" value="2000-05-14" readonly>
                  </div>
                </div>

                <h6 class="fw-bold text-dark mb-3 border-top pt-4">Dokumen Jaminan (Identitas)</h6>
                <div class="d-flex align-items-center gap-3 bg-light p-3 rounded-3 border">
                  <div class="text-success fs-3"><i class="bi bi-file-earmark-check-fill"></i></div>
                  <div class="lh-sm">
                    <span class="d-block fw-bold text-dark small">KTP Pribadi Asli</span>
                    <small class="text-success fw-medium" style="font-size: 0.7rem;">Sudah Diunggah &
                      Diverifikasi</small>
                  </div>
                </div>

                <div class="action-buttons d-none justify-content-end gap-2 mt-4">
                  <button type="button"
                    class="btn btn-light border px-4 rounded-pill fw-semibold text-muted w-100 w-sm-auto"
                    onclick="batalEdit()">Batal</button>
                  <button type="button" class="btn btn-primary px-4 rounded-pill fw-semibold shadow-sm w-100 w-sm-auto"
                    onclick="simpanEdit()">Simpan Perubahan</button>
                </div>
              </form>
            </div>

            <div class="tab-pane fade" id="alamat" role="tabpanel">
              <form>
                <div class="mb-3">
                  <label class="form-label small fw-bold text-muted"><i
                      class="bi bi-geo-alt-fill text-primary me-1"></i> Alamat Lengkap</label>
                  <textarea class="form-control form-readonly input-profil" rows="3"
                    readonly>Jl. Suwandak No. 45, RT 02 / RW 05, Kelurahan Rogotrunan</textarea>
                </div>
                <div class="row g-3 mb-4">
                  <div class="col-12 col-sm-6">
                    <label class="form-label small fw-bold text-muted"><i class="bi bi-map-fill text-primary me-1"></i>
                      Kecamatan / Kota</label>
                    <input type="text" class="form-control form-readonly input-profil" value="Lumajang" readonly>
                  </div>
                  <div class="col-12 col-sm-6">
                    <label class="form-label small fw-bold text-muted"><i
                        class="bi bi-signpost-2-fill text-primary me-1"></i> Detail Patokan</label>
                    <input type="text" class="form-control form-readonly input-profil"
                      value="Pagar hitam depan warung soto" readonly>
                  </div>
                </div>

                <div class="action-buttons d-none justify-content-end gap-2 mt-4">
                  <button type="button"
                    class="btn btn-light border px-4 rounded-pill fw-semibold text-muted w-100 w-sm-auto"
                    onclick="batalEdit()">Batal</button>
                  <button type="button" class="btn btn-primary px-4 rounded-pill fw-semibold shadow-sm w-100 w-sm-auto"
                    onclick="simpanEdit()">Simpan Alamat</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
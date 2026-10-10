<?php
$pageTitle = "PS Rental - Pembayaran";
include __DIR__ . '/../includes/header.php';
?>
<div class="mobile-bar d-lg-none mb-3 d-flex align-items-center gap-2">
    <button type="button" class="btn btn-light" id="menuBtn" aria-label="Buka menu"><i
            class="bi bi-list fs-4"></i></button>
    <span class="fw-bold text-dark">PS Rental</span>
</div>

<!-- Belum ada data pesanan -->
<div id="viewKosong" class="d-none">
    <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5 text-center">
        <i class="bi bi-inbox fs-1 text-muted"></i>
        <h5 class="fw-bold mt-2">Belum ada pesanan yang perlu dibayar</h5>
        <p class="text-muted">Isi formulir penyewaan dulu, lalu lanjutkan ke pembayaran.</p>
        <div><a href="../index.php" class="btn btn-primary rounded-pill px-4">Pilih Unit</a></div>
    </div>
</div>

<!-- Form pembayaran -->
<div id="viewForm" class="d-none">
    <div class="mb-4">
        <a href="form_sewa.php" id="linkKembali" class="text-decoration-none fw-semibold">
            <i class="bi bi-arrow-left me-2"></i> Kembali ke Formulir
        </a>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 p-3 p-md-4 mb-4">
                <h5 class="fw-bold mb-1">Metode Pembayaran</h5>
                <p class="text-muted small mb-3">Pembayaran diproses manual dan diverifikasi langsung oleh
                    operator toko, tanpa pihak ketiga.</p>
                <div class="row g-3" id="daftarMetode"></div>
                <div class="alert alert-warning small border-0 mt-3 mb-0 d-none" id="infoCod">
                    <i class="bi bi-info-circle-fill me-1"></i> COD tidak tersedia untuk pengantaran ke
                    rumah. Pembayaran harus dikirim dulu sebelum unit diantar.
                </div>
            </div>

            <!-- Transfer bank -->
            <div class="card border-0 shadow-sm rounded-4 p-3 p-md-4 mb-4" id="panelTransfer">
                <h6 class="fw-bold mb-3"><i class="bi bi-bank me-2 text-primary"></i>Transfer ke Rekening
                    Toko</h6>
                <div id="listRekening" class="d-flex flex-column gap-2 mb-3"></div>
                <div class="d-flex justify-content-between align-items-center p-3 rounded-3 bg-primary-subtle">
                    <div>
                        <small class="text-muted d-block">Nominal transfer</small>
                        <span class="fw-bold fs-5 text-primary nominal-bayar"></span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill"
                        data-salin="nominal"><i class="bi bi-clipboard me-1"></i>Salin</button>
                </div>
            </div>

            <!-- QRIS -->
            <div class="card border-0 shadow-sm rounded-4 p-3 p-md-4 mb-4" id="panelQris">
                <h6 class="fw-bold mb-3"><i class="bi bi-qr-code me-2 text-primary"></i>Bayar dengan QRIS
                </h6>
                <div class="row g-3 align-items-center">
                    <div class="col-md-5 text-center">
                        <div class="border rounded-3 p-2 bg-white d-inline-block">
                            <img src="../assets/img/qris.png" alt="QRIS toko" class="img-fluid qris-img"
                                id="imgQris" />
                            <div class="small text-muted d-none p-4" id="qrisKosong">Gambar QRIS belum
                                dipasang.<br />Simpan di <code>assets/img/qris.png</code></div>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <ol class="small ps-3 mb-3">
                            <li class="mb-1">Buka aplikasi m-banking atau e-wallet, pilih <b>Scan QRIS</b>.
                            </li>
                            <li class="mb-1">Scan kode di samping, lalu masukkan nominal <b
                                    class="nominal-bayar"></b>.</li>
                            <li>Simpan screenshot bukti bayar, lalu unggah di bawah.</li>
                        </ol>
                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill"
                            data-salin="nominal"><i class="bi bi-clipboard me-1"></i>Salin nominal</button>
                    </div>
                </div>
            </div>

            <!-- COD (hanya ambil di toko) -->
            <div class="card border-0 shadow-sm rounded-4 p-3 p-md-4 mb-4" id="panelCod">
                <h6 class="fw-bold mb-3"><i class="bi bi-cash-coin me-2 text-primary"></i>Bayar di Tempat
                    (COD)</h6>
                <ul class="small mb-0 ps-3">
                    <li class="mb-1">Bayar tunai <b class="nominal-bayar"></b> di toko saat mengambil unit.
                    </li>
                    <li class="mb-1">Bawa jaminan asli (KTP / STNK) dan tunjukkan kode pesanan ke operator.
                    </li>
                    <li>Operator tetap memverifikasi pesanan dan jaminan sebelum unit diserahkan.</li>
                </ul>
            </div>

            <!-- Bukti pembayaran -->
            <div class="card border-0 shadow-sm rounded-4 p-3 p-md-4 mb-4" id="formBukti">
                <h6 class="fw-bold mb-3"><i class="bi bi-receipt-cutoff me-2 text-primary"></i>Kirim Bukti
                    Pembayaran</h6>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-semibold" for="namaPengirim">Nama
                            pemilik rekening / akun <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="namaPengirim"
                            placeholder="Sesuai yang tertera di bukti" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-semibold" for="bankAsal">Bank /
                            e-wallet asal</label>
                        <input type="text" class="form-control" id="bankAsal"
                            placeholder="Contoh: BCA, DANA, GoPay" />
                    </div>
                </div>
                <label class="form-label text-muted small fw-semibold">Bukti pembayaran <span
                        class="text-danger">*</span></label>
                <label class="upload-area d-block text-center p-4 w-100" for="fileBukti">
                    <input type="file" id="fileBukti" accept="image/*,application/pdf" class="d-none" />
                    <i class="bi bi-cloud-arrow-up fs-3 text-primary d-block mb-1"></i>
                    <span class="small fw-semibold d-block" id="namaFileBukti">Klik untuk pilih bukti
                        transfer / screenshot QRIS</span>
                    <img id="previewBukti" class="upload-preview rounded-3 border mt-3 d-none"
                        alt="Pratinjau bukti" />
                </label>
                <small class="text-muted d-block mt-2">Format JPG, PNG, atau PDF, maksimal 5 MB.</small>
            </div>

            <div class="alert alert-danger small d-none" id="errBayar" role="alert"></div>
            <button type="button" class="btn btn-primary btn-lg w-100 rounded-pill fw-semibold mb-4"
                id="btnKirim"></button>
        </div>

        <!-- Ringkasan -->
        <div class="col-lg-4">
            <div class="card glass-card card-ringkasan rounded-4 p-3 p-md-4 shadow-sm border-0">
                <h5 class="fw-bold mb-4">Ringkasan Pesanan</h5>
                <div class="d-flex align-items-center border-bottom pb-3 mb-3">
                    <img src="../assets/img/ps4.png" alt="" width="40" class="me-3" id="rGambar" />
                    <div>
                        <h6 class="mb-0 fw-bold" id="rUnit"></h6>
                        <small class="text-muted" id="rTarif"></small>
                    </div>
                </div>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Durasi</span><span
                        class="fw-semibold" id="rDurasi"></span></div>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Mulai</span><span
                        class="fw-semibold text-end" id="rMulai"></span></div>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Penerimaan</span><span
                        class="fw-semibold text-end" id="rPenerimaan"></span></div>
                <div class="d-flex justify-content-between mb-3 pb-3 border-bottom"><span
                        class="text-muted">Jaminan</span><span class="fw-semibold text-end"
                        id="rJaminan"></span></div>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Biaya
                        sewa</span><span class="fw-semibold" id="rSewa"></span></div>
                <div class="d-flex justify-content-between mb-3 d-none" id="rOngkirBaris"><span
                        class="text-muted">Ongkir</span><span class="fw-semibold" id="rOngkir"></span></div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold fs-5">Total Bayar</span>
                    <span class="fw-bold fs-5 text-primary nominal-bayar"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Berhasil -->
<div id="viewSukses" class="d-none">
    <div class="row justify-content-center">
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5">
                <div class="text-center mb-4">
                    <i class="bi bi-check-circle-fill text-success ikon-sukses"></i>
                    <h4 class="fw-bold mt-2 mb-1" id="sJudul"></h4>
                    <p class="text-muted mb-0" id="sSub"></p>
                </div>
                <div class="text-center p-3 rounded-4 bg-light mb-4">
                    <small class="text-muted d-block">Kode Pesanan</small>
                    <span class="fw-bold fs-4" id="sId"></span>
                    <svg id="barcodePesanan" class="d-block mx-auto mt-2 mw-100"></svg>
                    <small class="text-muted">Tunjukkan kode ini ke operator saat unit diserahkan.</small>
                </div>
                <h6 class="fw-bold mb-3">Langkah selanjutnya</h6>
                <div id="sLangkah" class="mb-3"></div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="pesanan.php" class="btn btn-primary rounded-pill px-4">Lihat Pesanan Saya</a>
                    <a href="../index.php" class="btn btn-light rounded-pill px-4">Kembali ke Beranda</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
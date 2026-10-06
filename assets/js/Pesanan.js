// Pesanan.html - countdown sewa, tracking kurir, scan QR, filter & pencarian pesanan

let totalPlaySeconds = (19 * 3600) + (19 * 60) + 53;
const maxPlaySeconds = 24 * 3600;

setInterval(() => {
    if (totalPlaySeconds > 0) {
        totalPlaySeconds--;
        let hours = Math.floor(totalPlaySeconds / 3600);
        let minutes = Math.floor((totalPlaySeconds % 3600) / 60);
        let seconds = totalPlaySeconds % 60;

        document.getElementById('liveCountdownPlay').innerText =
            `${hours} Jam ${minutes < 10 ? '0' : ''}${minutes} Menit ${seconds < 10 ? '0' : ''}${seconds} Detik`;

        let pct = (totalPlaySeconds / maxPlaySeconds) * 100;
        let bar = document.getElementById('liveProgressBar');
        if (bar) {
            bar.style.width = pct.toFixed(2) + '%';
            if (totalPlaySeconds < 3600) {
                bar.className = 'progress-bar progress-animated-striped progress-bar-waktu bg-danger';
            } else if (totalPlaySeconds < 7200) {
                bar.className = 'progress-bar progress-animated-striped progress-bar-waktu bg-warning text-dark';
            }
        }
    }
}, 1000);

let deliverySeconds = (11 * 60) + 21;
const maxDeliverySeconds = 30 * 60;

const deliveryTimer = setInterval(() => {
    if (deliverySeconds > 0) {
        deliverySeconds--;

        let mins = Math.ceil(deliverySeconds / 60);

        document.getElementById('liveCountdownDelivery').innerText = `± ${mins} Menit`;

        let progressRatio = (maxDeliverySeconds - deliverySeconds) / maxDeliverySeconds;
        let barWidthPercent = progressRatio * 64;
        if (barWidthPercent > 64) barWidthPercent = 64;

        let progressBar = document.getElementById('kurirProgressBar');
        if (progressBar) {
            progressBar.style.width = barWidthPercent.toFixed(2) + '%';
        }
    } else {
        clearInterval(deliveryTimer);
        simulasiTiba();
    }
}, 1000);

function simulasiTiba() {
    clearInterval(deliveryTimer);

    const badge = document.getElementById('statusBadgeDelivery');
    if (badge) {
        badge.className = 'badge bg-success-subtle text-success border border-success-subtle rounded-pill';
        badge.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Pesanan Tiba & Pasang';
    }

    const liveCountdown = document.getElementById('liveCountdownDelivery');
    if (liveCountdown) {
        liveCountdown.innerText = 'Pesanan Telah Diterima';
        liveCountdown.className = 'fw-bold fs-6 text-success';
    }

    const progressBar = document.getElementById('kurirProgressBar');
    if (progressBar) {
        progressBar.style.width = '64%';
        progressBar.style.backgroundColor = '#10b981';
        progressBar.style.boxShadow = '0 0 8px rgba(16, 185, 129, 0.5)';
    }

    const dotKurir = document.getElementById('dotKurir');
    if (dotKurir) {
        dotKurir.className = 'stepper-dot bg-success text-white border border-2 border-white';
    }

    const dotTiba = document.getElementById('dotTiba');
    if (dotTiba) {
        dotTiba.className = 'stepper-dot bg-success text-white';
        dotTiba.innerHTML = '<i class="bi bi-check-lg fs-6"></i>';
    }

    const textTiba = document.getElementById('textTiba');
    if (textTiba) {
        textTiba.className = 'fw-bold text-success mt-2';
    }

    const shopeeHead = document.getElementById('shopeeStatusHead');
    if (shopeeHead) shopeeHead.innerText = 'Pesanan Telah Tiba & Diserahterimakan';

    const shopeeSub = document.getElementById('shopeeStatusSub');
    if (shopeeSub) shopeeSub.innerText = 'Unit paket Playbox telah terpasang dan diverifikasi oleh pelanggan.';
}

function handleAdminScan(type) {
    if (type === 'terima') {
        let activeModalEl = document.getElementById('modalQRTerima');
        let activeModal = bootstrap.Modal.getInstance(activeModalEl);
        if (activeModal) activeModal.hide();

        simulasiTiba();

        document.getElementById('successModalTitle').innerText = 'Pesanan Anda telah diterima';
        document.getElementById('successModalSub').innerText = 'Penjual sedang menyiapkan pesananmu';

    } else if (type === 'kembali') {
        let activeModalEl = document.getElementById('modalStopKembalikan');
        let activeModal = bootstrap.Modal.getInstance(activeModalEl);
        if (activeModal) activeModal.hide();

        document.getElementById('successModalTitle').innerText = 'Pengembalian Unit Berhasil';
        document.getElementById('successModalSub').innerText = 'Unit konsol dan dokumen jaminan fisik Anda telah selesai diverifikasi oleh kurir/petugas.';
    }

    setTimeout(() => {
        let successModal = new bootstrap.Modal(document.getElementById('modalSuccessStatus'));
        successModal.show();
    }, 400);
}

function tambahWaktuSewa() {
    alert('Membuka halaman perpanjangan sewa...');
}

const kelasFilterAktif = 'btn btn-primary btn-sm px-3 rounded-pill fw-semibold';
const kelasFilterNonaktif = 'btn bg-light border text-secondary btn-sm px-3 rounded-pill';

function filterTab(type) {
    const tombol = { all: 'filterAll', active: 'filterActive', done: 'filterDone', cancel: 'filterCancel' };
    Object.entries(tombol).forEach(([key, id]) => {
        document.getElementById(id).className = key === type ? kelasFilterAktif : kelasFilterNonaktif;
    });

    document.getElementById('sectionBerlangsung').style.display =
        type === 'all' || type === 'active' ? 'block' : 'none';
    document.getElementById('sectionSelesai').style.display =
        type === 'all' || type === 'done' ? 'block' : 'none';
}

function searchOrders() {
    let query = document.getElementById('globalSearchInput').value.toLowerCase();
    let cards = document.querySelectorAll('.order-card');
    cards.forEach(card => {
        let title = card.getAttribute('data-title') || '';
        if (title.toLowerCase().includes(query)) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
}

// Kolom cari di topbar dipakai untuk menyaring pesanan
document.getElementById('globalSearchInput').addEventListener('input', searchOrders);
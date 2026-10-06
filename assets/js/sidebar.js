/* Sidebar responsif (off-canvas di layar < 992px).
   Dipakai halaman pelanggan (index, form_sewa, pembayaran, Pesanan) dan admin/unit-ps.html.
   Elemen yang dibutuhkan: #sidebar, #backdrop, dan tombol #menuBtn. */
(function () {
    'use strict';

    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    var backdrop = document.getElementById('backdrop');
    var menuBtn = document.getElementById('menuBtn');

    function buka(terbuka) {
        sidebar.classList.toggle('show', terbuka);
        if (backdrop) backdrop.classList.toggle('show', terbuka);
        document.body.classList.toggle('sidebar-open', terbuka);
    }

    if (menuBtn) menuBtn.addEventListener('click', function () { buka(true); });
    if (backdrop) backdrop.addEventListener('click', function () { buka(false); });

    sidebar.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('click', function () { buka(false); });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') buka(false);
    });

    // Kalau layar diperlebar sampai sidebar tampil permanen, pastikan state "terbuka" dibersihkan
    var lebar = window.matchMedia('(min-width: 992px)');
    var tutupSaatLebar = function (e) { if (e.matches) buka(false); };
    if (lebar.addEventListener) lebar.addEventListener('change', tutupSaatLebar);
    else if (lebar.addListener) lebar.addListener(tutupSaatLebar);
})();

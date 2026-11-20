(function () {
    'use strict';

    if (document.documentElement.dataset.pembayaranSiap === '1') return;
    document.documentElement.dataset.pembayaranSiap = '1';

    const $ = (id) => document.getElementById(id);
    const p2 = (n) => String(n).padStart(2, '0');

    const svg = $('barcodePesanan');
    if (svg && window.JsBarcode) {
        try {
            window.JsBarcode(svg, svg.dataset.kode, { format: 'CODE128', displayValue: false, height: 50, margin: 0 });
        } catch (err) {
            console.error('[Pembayaran] barcode gagal dibuat:', err);
        }
    }

    const timer = $('hitungMundur');
    if (timer) {
        const sisaAwal = Number(timer.dataset.sisa) || 0;
        const mulai = performance.now();
        const format = (sisa) => {
            if (sisa >= 86400) {
                return `${Math.floor(sisa / 86400)} hari ${Math.floor((sisa % 86400) / 3600)} jam`;
            }
            const j = Math.floor(sisa / 3600);
            const m = Math.floor((sisa % 3600) / 60);
            return (j > 0 ? `${j}:${p2(m)}` : p2(m)) + `:${p2(sisa % 60)}`;
        };
        const muatUlangSekali = () => {
            const kunci = 'bayar_muat_ulang:' + location.search;
            try {
                if (sessionStorage.getItem(kunci)) return;
                sessionStorage.setItem(kunci, '1');
            } catch (err) {
                return;
            }
            setTimeout(() => window.location.reload(), 1500);
        };
        const tick = () => {
            const sisa = Math.max(0, sisaAwal - Math.floor((performance.now() - mulai) / 1000));
            timer.textContent = format(sisa);
            if (sisa === 0) {
                clearInterval(id);
                timer.textContent = 'Waktu habis';
                muatUlangSekali();
            }
        };
        const id = setInterval(tick, 1000);
        tick();
    }

    const form = $('formBayar');
    if (!form) return;

    document.addEventListener('click', async (e) => {
        const tombol = e.target.closest('[data-salin]');
        if (!tombol) return;
        const awal = tombol.innerHTML;
        try {
            await navigator.clipboard.writeText(tombol.dataset.salin);
            tombol.textContent = 'Tersalin';
        } catch (err) {
            tombol.textContent = 'Salin manual';
        }
        setTimeout(() => (tombol.innerHTML = awal), 1500);
    });

    /* ---------- Gambar QRIS ---------- */
    const imgQris = $('imgQris');
    if (imgQris) {
        const gagal = () => {
            imgQris.classList.add('d-none');
            $('qrisKosong').classList.remove('d-none');
        };
        imgQris.addEventListener('error', gagal);
        if (imgQris.complete && imgQris.naturalWidth === 0) gagal();
    }

    const metode = () => form.querySelector('input[name="metode"]:checked').value;
    const berkas = $('fileBuktiBayar');
    const tampilkanError = (teks) => {
        const el = $('errBayarKlien');
        el.textContent = teks;
        el.classList.toggle('d-none', !teks);
    };

    function terapkanMetode() {
        const m = metode();
        $('panelTransfer').classList.toggle('d-none', m !== 'transfer');
        $('panelQris').classList.toggle('d-none', m !== 'qris');
        $('panelCod').classList.toggle('d-none', m !== 'cod');
        $('blokBukti').classList.toggle('d-none', m === 'cod');
        berkas.required = m !== 'cod';
        $('namaPengirim').required = m !== 'cod';
        $('btnBayar').textContent = m === 'cod' ? 'Konfirmasi Bayar di Toko' : 'Kirim Bukti Pembayaran';
        tampilkanError('');
    }
    form.querySelectorAll('input[name="metode"]').forEach((el) => el.addEventListener('change', terapkanMetode));

    const MAKS = Number(form.dataset.maksBukti) || 5 * 1024 * 1024;
    const JENIS = ['image/jpeg', 'image/png', 'image/webp'];
    let urlPratinjau = null;
    const awalNama = $('namaFileBukti').textContent;

    berkas.addEventListener('change', () => {
        if (urlPratinjau) URL.revokeObjectURL(urlPratinjau);
        urlPratinjau = null;
        $('previewBukti').classList.add('d-none');
        const f = berkas.files[0];
        if (!f) {
            $('namaFileBukti').textContent = awalNama;
            return;
        }
        if (!JENIS.includes(f.type)) {
            tampilkanError('Format bukti harus JPG, PNG, atau WebP.');
        } else if (f.size > MAKS) {
            tampilkanError(`Ukuran bukti maksimal ${Math.floor(MAKS / 1048576)} MB.`);
        } else {
            tampilkanError('');
            $('namaFileBukti').textContent = f.name;
            urlPratinjau = URL.createObjectURL(f);
            $('previewBukti').src = urlPratinjau;
            $('previewBukti').classList.remove('d-none');
            return;
        }
        berkas.value = '';
        $('namaFileBukti').textContent = awalNama;
    });

    const tombol = $('btnBayar');
    form.addEventListener('submit', () => {
        tombol.disabled = true;
        tombol.textContent = 'Memproses...';
    });
    window.addEventListener('pageshow', () => {
        tombol.disabled = false;
        terapkanMetode();
    });

    terapkanMetode();
})();
(function () {
    'use strict';

    /* ---------- 1. Utilitas & konstanta bersama (dideklarasikan SEKALI di sini) ---------- */
    const TARIF_DENDA = 10000; // Rp per jam keterlambatan (pickup/delivery)
    const TOLERANSI = 10; // menit
    const KEY_UNIT = 'psr_katalog_admin_v3'; // katalog admin: dipakai Unit PS & Penyewaan
    const KEY_PUBLIK = 'psr_katalog_publik'; // katalog untuk pelanggan (disinkron Unit PS)
    const BASE = window.APP_BASE ?? '../'; // dari footer.php ($base); '../' bila halaman ada di /pelanggan

    const $ = (id) => document.getElementById(id);
    const p2 = (n) => String(n).padStart(2, '0');
    const hm = (d) => p2(d.getHours()) + ':' + p2(d.getMinutes());
    const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    const tglLengkap = (d) => `${p2(d.getDate())} ${BULAN[d.getMonth()]} ${d.getFullYear()}, ${hm(d)} WIB`;
    const rupiah = (n) => 'Rp ' + Number(n).toLocaleString('id-ID');
    const rp = rupiah; // alias, dipakai di kode admin
    const esc = (s) =>
        String(s ?? '').replace(
            /[&<>"']/g,
            (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
        );
    const baca = (store, key, awal) => {
        try {
            return JSON.parse(store.getItem(key)) ?? awal;
        } catch {
            return awal;
        }
    };

    function hitungDenda(sisaDetik) {
        const m = Math.max(0, Math.ceil(-sisaDetik / 60));
        if (m <= TOLERANSI) return { m, j: 0, t: 0 };
        const j = Math.ceil(m / 60);
        return { m, j, t: j * TARIF_DENDA };
    }

    /* ---------- 2. Data tarif (dulu tarif.js) ---------- */
    const buatTarif = (j12, j24) => ({ j12, j24 });

    const UNIT = {
        ps4: {
            nama: 'PlayStation 4 Slim / Pro',
            gambar: 'assets/img/ps4.png',
            tarif: {
                weekday: buatTarif(40000, 70000),
                weekend: buatTarif(40000, 70000),
            },
        },
        ps3: {
            nama: 'PlayStation 3 Super Slim',
            gambar: 'assets/img/ps3.png',
            tarif: {
                weekday: buatTarif(30000, 50000),
                weekend: buatTarif(30000, 50000),
            },
        },
        'playbox-ps4': {
            nama: 'Paket Playbox PS4 + TV LED 43"',
            gambar: 'assets/img/paket-ps4.png',
            tarif: {
                weekday: buatTarif(70000, 100000),
                weekend: buatTarif(70000, 100000),
            },
        },
        'playbox-ps3': {
            nama: 'Paket Playbox PS3 + TV LED',
            gambar: 'assets/img/paket-ps3.png',
            tarif: {
                weekday: buatTarif(50000, 70000),
                weekend: buatTarif(50000, 70000),
            },
        },
        'tv-43': {
            nama: 'Smart TV LED 43 Inch',
            gambar: 'assets/img/tv-43.png',
            tarif: {
                weekday: buatTarif(30000, 40000),
                weekend: buatTarif(30000, 40000),
            },
        },
        'tv-32': {
            nama: 'TV LED 32 / 40 Inch HD',
            gambar: 'assets/img/tv-32.png',
            tarif: {
                weekday: buatTarif(20000, 30000),
                weekend: buatTarif(20000, 30000),
            },
        },
    };

    const ONGKIR = 10000;

    function jenisTarifUntuk(tanggal) {
        const hari = tanggal.getDay();
        return hari === 0 || hari >= 5 ? 'weekend' : 'weekday';
    }

    function hitungHarga(unitId, jenis, mode, jumlah) {
        const t = UNIT[unitId].tarif[jenis];
        if (mode === 'harian') return t.j24 * jumlah;
        const blok = jumlah / 12;
        return Math.floor(blok / 2) * t.j24 + (blok % 2) * t.j12;
    }

    /* ---------- 3. Sidebar responsif (dulu sidebar.js) ----------
   Off-canvas di layar < 992px. Butuh #sidebar, #backdrop, dan tombol #menuBtn. */
    function initSidebar() {
        const sidebar = $('sidebar');
        if (!sidebar) return;
        const backdrop = $('backdrop');

        function buka(terbuka) {
            sidebar.classList.toggle('show', terbuka);
            if (backdrop) backdrop.classList.toggle('show', terbuka);
            document.body.classList.toggle('sidebar-open', terbuka);
        }

        // Mendukung beberapa tombol #menuBtn (Topbar maupun Mobile Bar)
        document.querySelectorAll('#menuBtn').forEach((btn) => btn.addEventListener('click', () => buka(true)));
        if (backdrop) backdrop.addEventListener('click', () => buka(false));
        sidebar.querySelectorAll('a').forEach((a) => a.addEventListener('click', () => buka(false)));
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') buka(false);
        });

        const lebar = window.matchMedia('(min-width: 992px)');
        const tutupSaatLebar = (e) => {
            if (e.matches) buka(false);
        };
        if (lebar.addEventListener) lebar.addEventListener('change', tutupSaatLebar);
        else if (lebar.addListener) lebar.addListener(tutupSaatLebar);
    }

    /* ---------- 4. Pelanggan: Beranda / katalog & profil (dulu app.js) ---------- */
    let kategoriAktif = 'semua';
    function filterKatalog() {
        const hanyaTersedia = $('cekSedia') ? $('cekSedia').checked : false;
        const semuaKartu = document.querySelectorAll('.item-produk');

        semuaKartu.forEach((kartu) => {
            // 1. Cek Kategori
            const cocokKategori = (kategoriAktif === 'semua') || kartu.classList.contains(kategoriAktif);

            // 2. Cek Stok (dari data-stok)
            const stok = parseInt(kartu.dataset.stok || '0', 10);
            const cocokTersedia = !hanyaTersedia || stok > 0;

            // Tampilkan jika memenuhi kedua kriteria
            if (cocokKategori && cocokTersedia) {
                kartu.style.display = 'block';
            } else {
                kartu.style.display = 'none';
            }
        });
    }
    function ubahKategori(tombolAktif, kategori) {
        kategoriAktif = kategori;

        let semuaTombol = document.querySelectorAll('.tombol-kategori');
        semuaTombol.forEach((btn) => {
            btn.className = 'btn bg-light border text-secondary btn-sm px-3 rounded-pill tombol-kategori';
        });
        tombolAktif.className = 'btn btn-primary btn-sm px-3 rounded-pill fw-semibold tombol-kategori';

        filterKatalog();
    }

    function perbaruiHarga(jenis) {
        document.querySelectorAll('[data-unit]').forEach((kartu) => {
            const isWeekend = (jenis === 'weekend');
            const wk12 = kartu.dataset.wk12;
            const wk24 = kartu.dataset.wk24;
            const wn12 = kartu.dataset.wn12;
            const wn24 = kartu.dataset.wn24;

            const elJ12 = kartu.querySelector('[data-harga="j12"]');
            const elJ24 = kartu.querySelector('[data-harga="j24"]');
            if (wk12 !== undefined && wn12 !== undefined) {
                if (elJ12) elJ12.textContent = rupiah(isWeekend ? wn12 : wk12);
                if (elJ24) elJ24.textContent = rupiah(isWeekend ? wn24 : wk24);
            } else {
                const id = kartu.dataset.unit;
                if (UNIT[id]) {
                    const t = UNIT[id].tarif[jenis];
                    if (elJ12) elJ12.textContent = rupiah(t.j12);
                    if (elJ24) elJ24.textContent = rupiah(t.j24);
                }
            }
            const link = kartu.querySelector('a[href*="form_sewa"]');
            if (link) {
                let href = link.getAttribute('href');
                if (href.includes('tarif=')) {
                    href = href.replace(/tarif=(weekday|weekend)/, 'tarif=' + jenis);
                } else if (href.includes('?')) {
                    href += '&tarif=' + jenis;
                } else {
                    href += '?tarif=' + jenis;
                }
                link.setAttribute('href', href);
            }
        });
    }

    function geserTarif(keWeekend) {
        perbaruiHarga(keWeekend ? 'weekend' : 'weekday');
        const slider = document.getElementById('sliderTarif');
        const teksWeekday = document.getElementById('teksWeekday');
        const teksWeekend = document.getElementById('teksWeekend');

        if (!slider || !teksWeekday || !teksWeekend) return;

        if (keWeekend) {
            slider.style.transform = 'translateX(100%)';
            teksWeekend.classList.remove('text-muted', 'fw-semibold');
            teksWeekend.classList.add('text-white', 'fw-bold');
            teksWeekday.classList.remove('text-white', 'fw-bold');
            teksWeekday.classList.add('text-muted', 'fw-semibold');
        } else {
            slider.style.transform = 'translateX(0)';
            teksWeekday.classList.remove('text-muted', 'fw-semibold');
            teksWeekday.classList.add('text-white', 'fw-bold');
            teksWeekend.classList.remove('text-white', 'fw-bold');
            teksWeekend.classList.add('text-muted', 'fw-semibold');
        }
    }

    function mulaiEdit() {
        document.getElementById('btnEditProfile').classList.add('d-none');
        let inputs = document.querySelectorAll('.input-profil');
        inputs.forEach((input) => {
            input.removeAttribute('readonly');
            input.classList.remove('form-readonly');
            input.classList.add('form-editing');
        });
        let actions = document.querySelectorAll('.action-buttons');
        actions.forEach((action) => {
            action.classList.remove('d-none');
            action.classList.add('d-flex', 'flex-column', 'flex-sm-row');
        });
    }

    function batalEdit() {
        document.getElementById('btnEditProfile').classList.remove('d-none');
        let inputs = document.querySelectorAll('.input-profil');
        inputs.forEach((input) => {
            input.setAttribute('readonly', true);
            input.classList.remove('form-editing');
            input.classList.add('form-readonly');
        });
        let actions = document.querySelectorAll('.action-buttons');
        actions.forEach((action) => {
            action.classList.remove('d-flex', 'flex-column', 'flex-sm-row');
            action.classList.add('d-none');
        });
    }

    function simpanEdit() {
        alert('Data profil berhasil diperbarui!');
        batalEdit();
    }

    function initBeranda() {
        if ($('sliderTarif')) geserTarif(jenisTarifUntuk(new Date()) === 'weekend');

        const cekSedia = $('cekSedia');
        if (cekSedia) {
            cekSedia.addEventListener('change', filterKatalog);
        }
    }

    /* ---------- 5. Pelanggan: Form sewa (dulu form-sewa.js) ----------
   Unit dari index (?unit=), tarif weekday/weekend, durasi, metode penerimaan, upload jaminan. */
    function initFormSewa() {
        if (!$('btnLanjut')) return;

        const parameter = new URLSearchParams(location.search);
        const unitId = UNIT[parameter.get('unit')] ? parameter.get('unit') : 'ps4';
        const unit = UNIT[unitId];

        // Tarif awal: dari pilihan di index, kalau tidak ada pakai hari ini. Setelah tanggal mulai dipilih, ikut tanggal itu.
        let jenisTarif = ['weekday', 'weekend'].includes(parameter.get('tarif'))
            ? parameter.get('tarif')
            : jenisTarifUntuk(new Date());

        const MODE = {
            harian: { satuan: 'Hari', langkah: 1, info: '1 hari = 24 jam' },
            jam: { satuan: 'Jam', langkah: 12, info: 'Kelipatan 12 jam (12, 24, 36, ...)' },
        };

        const tipeSewa = () => document.querySelector('input[name="tipeSewa"]:checked').id;
        const antarKeRumah = () => $('metodeTerima').value === 'antar';

        function jumlahDurasi() {
            const { langkah } = MODE[tipeSewa()];
            const n = Math.max(langkah, parseInt($('durasi').value, 10) || langkah);
            return Math.ceil(n / langkah) * langkah;
        }

        function perbaruiRingkasan() {
            const tipe = tipeSewa();
            const mode = MODE[tipe];
            const jumlah = jumlahDurasi();
            const antar = antarKeRumah();
            const t = unit.tarif[jenisTarif];

            $('satuanDurasi').textContent = mode.satuan;
            $('infoDurasi').textContent = mode.info;
            $('unitHarga').textContent = `${rupiah(t.j12)} / 12 jam • ${rupiah(t.j24)} / 24 jam`;
            $('sumHarga').textContent = `${rupiah(t.j12)} / 12 jam • ${rupiah(t.j24)} / 24 jam`;
            $('sumTarif').textContent = jenisTarif === 'weekend' ? 'Weekend (Jum-Min)' : 'Weekday (Sen-Kam)';
            $('sumDurasi').textContent = `${jumlah} ${mode.satuan}`;
            const sewa = hitungHarga(unitId, jenisTarif, tipe, jumlah);
            const ongkir = antar ? ONGKIR : 0;
            $('sumOngkirBaris').classList.toggle('d-none', !antar);
            $('sumOngkir').textContent = rupiah(ongkir);
            $('sumTotal').textContent = rupiah(sewa + ongkir);
            $('sumPenerimaan').textContent = antar ? 'Antar ke Rumah (+ ongkir)' : 'Ambil di Toko';
            $('sumJaminan').textContent = antar
                ? document.querySelector('input[name="jaminan"]:checked').value.toUpperCase()
                : 'Diserahkan di toko';
        }

        function perbaruiMetode() {
            const antar = antarKeRumah();
            $('blokToko').classList.toggle('d-none', antar);
            $('blokAntar').classList.toggle('d-none', !antar);
        }

        function ubahDurasi(arah) {
            const { langkah } = MODE[tipeSewa()];
            $('durasi').value = Math.max(langkah, jumlahDurasi() + arah * langkah);
            perbaruiRingkasan();
        }

        // Tampilkan unit yang dipilih di index
        document.title = `PS Rental - Sewa ${unit.nama}`;
        ['unit', 'sum'].forEach((awalan) => {
            $(awalan + 'Gambar').src = BASE + unit.gambar;
            $(awalan + 'Gambar').alt = unit.nama;
            $(awalan + 'Nama').textContent = unit.nama;
        });

        // Waktu mulai: tidak boleh sebelum sekarang
        const sekarang = new Date();
        sekarang.setMinutes(sekarang.getMinutes() - sekarang.getTimezoneOffset());
        $('mulaiSewa').min = sekarang.toISOString().slice(0, 16);
        $('mulaiSewa').addEventListener('change', (e) => {
            if (e.target.value) jenisTarif = jenisTarifUntuk(new Date(e.target.value));
            perbaruiRingkasan();
        });

        document.querySelectorAll('input[name="tipeSewa"]').forEach((el) => {
            el.addEventListener('change', () => {
                $('durasi').value = MODE[tipeSewa()].langkah;
                perbaruiRingkasan();
            });
        });
        document.querySelectorAll('input[name="jaminan"]').forEach((el) => {
            el.addEventListener('change', perbaruiRingkasan);
        });

        $('metodeTerima').addEventListener('change', () => {
            perbaruiMetode();
            perbaruiRingkasan();
        });

        $('btnKurang').addEventListener('click', () => ubahDurasi(-1));
        $('btnTambah').addEventListener('click', () => ubahDurasi(1));
        $('durasi').addEventListener('input', (e) => {
            e.target.value = e.target.value.replace(/\D/g, '');
            perbaruiRingkasan();
        });
        $('durasi').addEventListener('blur', (e) => {
            e.target.value = jumlahDurasi();
            perbaruiRingkasan();
        });

        // Upload foto jaminan + pratinjau
        $('fileJaminan').addEventListener('change', (e) => {
            const file = e.target.files[0];
            const preview = $('previewJaminan');
            if (!file) {
                preview.classList.add('d-none');
                $('namaFileJaminan').textContent = 'Klik untuk pilih foto KTP / STNK (JPG atau PNG)';
                return;
            }
            preview.src = URL.createObjectURL(file);
            preview.classList.remove('d-none');
            $('namaFileJaminan').textContent = file.name;
        });

        // Lanjut ke pembayaran: cek isian wajib, simpan draft pesanan
        function validasiForm() {
            const e = [];
            if (!$('namaPelanggan').value.trim()) e.push('Nama pelanggan');
            if (!/^(\+62|62|0)8\d{7,12}$/.test($('hpPelanggan').value.replace(/[\s-]/g, '')))
                e.push('Nomor HP yang valid (contoh 08123456789)');
            if (!$('mulaiSewa').value) e.push('Tanggal & waktu mulai sewa');
            if (antarKeRumah()) {
                if (!$('alamatAntar').value.trim()) e.push('Alamat pengantaran');
                if (!$('kecamatan').value.trim()) e.push('Kecamatan / kelurahan');
                if (!$('fileJaminan').files.length) e.push('Foto jaminan');
            }
            return e;
        }

        $('btnLanjut').addEventListener('click', (e) => {
            e.preventDefault();
            const salah = validasiForm(),
                box = $('errForm');
            box.classList.toggle('d-none', !salah.length);
            if (salah.length) {
                box.innerHTML = '<b>Lengkapi dulu:</b><br>' + salah.join('<br>');
                return;
            }

            const tipe = tipeSewa(),
                jumlah = jumlahDurasi(),
                antar = antarKeRumah();
            const sewa = hitungHarga(unitId, jenisTarif, tipe, jumlah),
                ongkir = antar ? ONGKIR : 0;
            sessionStorage.setItem(
                'psr_draft_pesanan',
                JSON.stringify({
                    unitId,
                    unitNama: unit.nama,
                    gambar: unit.gambar,
                    tipe,
                    jumlah,
                    satuan: MODE[tipe].satuan,
                    jenisTarif,
                    mulai: $('mulaiSewa').value,
                    metode: antar ? 'antar' : 'ambil',
                    nama: $('namaPelanggan').value.trim(),
                    hp: $('hpPelanggan').value.trim(),
                    alamat: antar ? $('alamatAntar').value.trim() : '',
                    kecamatan: antar ? $('kecamatan').value.trim() : '',
                    patokan: antar ? $('patokan').value.trim() : '',
                    jaminan: antar ? document.querySelector('input[name="jaminan"]:checked').value : null,
                    fileJaminan: antar ? $('fileJaminan').files[0].name : null,
                    sewa,
                    ongkir,
                    total: sewa + ongkir,
                }),
            );
            // Perbaikan redirect ke pembayaran.php
            location.href = 'pembayaran.php';
        });

        perbaruiMetode();
        perbaruiRingkasan();
    }

    /* ---------- 6. Pelanggan: Pembayaran (dulu Pembayaran.js) ----------
   Pilih metode (transfer / QRIS / COD), kirim bukti, simpan pesanan.
   Data pesanan datang dari form sewa lewat sessionStorage. */
    function initPembayaran() {
        if (!$('viewForm')) return;
        // TODO: isi data rekening toko yang sebenarnya (boleh lebih dari satu bank)
        const REKENING = [
            { bank: '[Nama Bank]', nomor: '[isi nomor rekening]', atasNama: '[isi nama pemilik rekening]' },
        ];
        const KEY_DRAFT = 'psr_draft_pesanan';
        const KEY_PESANAN = 'psr_pesanan';
        const MAKS_BUKTI = 5 * 1024 * 1024;

        const NAMA_METODE = { transfer: 'Transfer Bank', qris: 'QRIS', cod: 'Bayar di Tempat (COD)' };
        const MODE_LABEL = { harian: 'Hari', jam: 'Jam' };

        const draft = baca(sessionStorage, KEY_DRAFT, null);
        let metode = 'transfer';

        function tampil(view) {
            ['viewKosong', 'viewForm', 'viewSukses'].forEach((id) => $(id).classList.toggle('d-none', id !== view));
        }

        function init() {
            const idSelesai = new URLSearchParams(location.search).get('pesanan');
            if (idSelesai) {
                const o = baca(localStorage, KEY_PESANAN, []).find((x) => x.id === idSelesai);
                if (o) return tampilSukses(o);
            }
            if (!draft) return tampil('viewKosong');
            isiRingkasan();
            renderMetode();
            renderRekening();
            aturPanel();
            tampil('viewForm');
        }

        /* ---------- Ringkasan ---------- */
        function isiRingkasan() {
            const d = draft,
                antar = d.metode === 'antar';
            $('rGambar').src = BASE + d.gambar;
            $('rUnit').textContent = d.unitNama;
            $('rTarif').textContent = d.jenisTarif === 'weekend' ? 'Tarif Weekend' : 'Tarif Weekday';
            $('rDurasi').textContent = `${d.jumlah} ${d.satuan}`;
            $('rMulai').textContent = tglLengkap(new Date(d.mulai));
            $('rPenerimaan').textContent = antar ? 'Antar ke Rumah' : 'Ambil di Toko';
            $('rJaminan').textContent = antar ? d.jaminan.toUpperCase() : 'Dibawa saat ambil unit';
            $('rSewa').textContent = rupiah(d.sewa);
            $('rOngkirBaris').classList.toggle('d-none', !antar);
            $('rOngkir').textContent = rupiah(d.ongkir);
            document.querySelectorAll('.nominal-bayar').forEach((el) => {
                el.textContent = rupiah(d.total);
            });
            $('linkKembali').addEventListener('click', (e) => {
                if (history.length > 1) {
                    e.preventDefault();
                    history.back();
                }
            });
        }

        /* ---------- Pilihan metode: COD hanya untuk ambil di toko ---------- */
        function renderMetode() {
            const daftar = [
                { id: 'transfer', ikon: 'bi-bank', judul: 'Transfer Bank', sub: 'Kirim bukti transfer' },
                { id: 'qris', ikon: 'bi-qr-code', judul: 'QRIS', sub: 'Scan lalu kirim bukti' },
            ];
            if (draft.metode === 'ambil')
                daftar.push({ id: 'cod', ikon: 'bi-cash-coin', judul: 'COD', sub: 'Bayar tunai di toko' });
            $('infoCod').classList.toggle('d-none', draft.metode === 'ambil');
            $('daftarMetode').innerHTML = daftar
                .map(
                    (m) => `
    <div class="${daftar.length === 3 ? 'col-4' : 'col-6'}">
      <label class="radio-card w-100">
        <input type="radio" name="metodeBayar" value="${m.id}" ${m.id === metode ? 'checked' : ''} />
        <div class="card p-3 text-center border-2">
          <i class="bi ${m.ikon} fs-4 mb-2"></i>
          <span class="fw-semibold d-block">${m.judul}</span>
          <small class="text-muted">${m.sub}</small>
        </div>
      </label>
    </div>`,
                )
                .join('');
            document.querySelectorAll('input[name="metodeBayar"]').forEach((el) => {
                el.addEventListener('change', () => {
                    metode = el.value;
                    aturPanel();
                });
            });
        }

        function renderRekening() {
            $('listRekening').innerHTML = REKENING.map(
                (r) => `
    <div class="d-flex justify-content-between align-items-center p-3 border rounded-3 bg-light">
      <div>
        <small class="text-muted d-block">${esc(r.bank)} · a.n. ${esc(r.atasNama)}</small>
        <span class="fw-bold fs-5">${esc(r.nomor)}</span>
      </div>
      <button type="button" class="btn btn-sm btn-outline-primary rounded-pill" data-salin="${esc(r.nomor.replace(/\s/g, ''))}"><i class="bi bi-clipboard me-1"></i>Salin</button>
    </div>`,
            ).join('');
        }

        function aturPanel() {
            $('panelTransfer').classList.toggle('d-none', metode !== 'transfer');
            $('panelQris').classList.toggle('d-none', metode !== 'qris');
            $('panelCod').classList.toggle('d-none', metode !== 'cod');
            $('formBukti').classList.toggle('d-none', metode === 'cod');
            $('errBayar').classList.add('d-none');
            $('btnKirim').innerHTML =
                metode === 'cod'
                    ? '<i class="bi bi-check2-circle me-1"></i> Konfirmasi Pesanan (Bayar di Tempat)'
                    : '<i class="bi bi-send-check me-1"></i> Kirim Bukti & Selesaikan Pesanan';
        }

        /* ---------- Salin ---------- */
        document.addEventListener('click', async (e) => {
            const b = e.target.closest('[data-salin]');
            if (!b) return;
            const teks = b.dataset.salin === 'nominal' ? String(draft.total) : b.dataset.salin;
            try {
                await navigator.clipboard.writeText(teks);
            } catch {
                const t = document.createElement('textarea');
                t.value = teks;
                document.body.appendChild(t);
                t.select();
                document.execCommand('copy');
                t.remove();
            }
            const awal = b.innerHTML;
            b.innerHTML = '<i class="bi bi-check2 me-1"></i>Tersalin';
            setTimeout(() => {
                b.innerHTML = awal;
            }, 1500);
        });

        /* ---------- QRIS & upload bukti ---------- */
        const imgQris = $('imgQris');
        const qrisGagal = () => {
            imgQris.classList.add('d-none');
            $('qrisKosong').classList.remove('d-none');
        };
        imgQris.addEventListener('error', qrisGagal);
        if (imgQris.complete && imgQris.naturalWidth === 0) qrisGagal();

        $('fileBukti').addEventListener('change', (e) => {
            const f = e.target.files[0],
                prev = $('previewBukti');
            if (!f) {
                prev.classList.add('d-none');
                $('namaFileBukti').textContent = 'Klik untuk pilih bukti transfer / screenshot QRIS';
                return;
            }
            $('namaFileBukti').textContent = f.name;
            if (f.type.startsWith('image/')) {
                prev.src = URL.createObjectURL(f);
                prev.classList.remove('d-none');
            } else prev.classList.add('d-none');
        });

        /* ---------- Kirim ---------- */
        function validasiBayar() {
            if (metode === 'cod') return [];
            const e = [],
                f = $('fileBukti').files[0];
            if (!$('namaPengirim').value.trim()) e.push('Nama pemilik rekening / akun pengirim');
            if (!f) e.push('Bukti pembayaran (foto atau PDF)');
            else if (!(f.type.startsWith('image/') || f.type === 'application/pdf'))
                e.push('Bukti harus berupa gambar atau PDF');
            else if (f.size > MAKS_BUKTI) e.push('Ukuran bukti maksimal 5 MB');
            return e;
        }

        $('btnKirim').addEventListener('click', () => {
            const salah = validasiBayar(),
                box = $('errBayar');
            box.classList.toggle('d-none', !salah.length);
            if (salah.length) {
                box.innerHTML = '<b>Lengkapi dulu:</b><br>' + salah.map(esc).join('<br>');
                return;
            }

            const now = new Date(),
                f = $('fileBukti').files[0];
            const order = {
                ...draft,
                id: `#GG-${now.getFullYear()}${p2(now.getMonth() + 1)}${p2(now.getDate())}-${Math.floor(1000 + Math.random() * 9000)}`,
                dibuat: now.toISOString(),
                metodeBayar: metode,
                pengirim: metode === 'cod' ? '' : $('namaPengirim').value.trim(),
                bankAsal: $('bankAsal').value.trim(),
                buktiNama: metode === 'cod' ? null : f.name,
                statusBayar: metode === 'cod' ? 'COD (bayar di toko)' : 'Menunggu verifikasi',
                status: 'menunggu',
            };
            const semua = baca(localStorage, KEY_PESANAN, []);
            semua.push(order);
            try {
                localStorage.setItem(KEY_PESANAN, JSON.stringify(semua));
            } catch { }
            sessionStorage.removeItem(KEY_DRAFT);
            history.replaceState(null, '', location.pathname + '?pesanan=' + encodeURIComponent(order.id));
            tampilSukses(order);
        });

        /* ---------- Halaman berhasil ---------- */
        function tampilSukses(o) {
            const antar = o.metode === 'antar',
                cod = o.metodeBayar === 'cod';
            $('sJudul').textContent = cod ? 'Pesanan dikonfirmasi' : 'Bukti pembayaran terkirim';
            $('sSub').textContent = cod
                ? 'Bayar tunai di toko saat mengambil unit.'
                : 'Menunggu verifikasi dari operator.';
            $('sId').textContent = o.id;
            if (window.JsBarcode) {
                try {
                    JsBarcode('#barcodePesanan', o.id.replace('#', ''), {
                        format: 'CODE128',
                        height: 50,
                        displayValue: false,
                    });
                } catch { }
            }
            const langkah = [
                [
                    true,
                    cod ? 'Pesanan dikonfirmasi' : 'Bukti pembayaran terkirim',
                    cod
                        ? `Siapkan uang pas ${rupiah(o.total)} untuk dibayar di toko.`
                        : `${rupiah(o.total)} via ${NAMA_METODE[o.metodeBayar]}.`,
                ],
                [
                    false,
                    'Operator memverifikasi pembayaran dan jaminan',
                    'Dilakukan manual oleh operator. Unit baru bisa diambil atau diantar setelah terverifikasi.',
                ],
                [
                    false,
                    antar ? 'Unit diantar ke alamat kamu' : 'Ambil unit di toko',
                    antar
                        ? 'Siapkan jaminan asli untuk diserahkan ke kurir. Status pengantaran bisa dipantau di Pesanan Saya.'
                        : 'Tunjukkan kode pesanan dan bawa jaminan asli (KTP / STNK).',
                ],
                [
                    false,
                    'Kembalikan unit tepat waktu',
                    'Keterlambatan dikenakan denda Rp 10.000 per jam (toleransi 10 menit).',
                ],
            ];
            $('sLangkah').innerHTML = langkah
                .map(
                    ([selesai, judul, ket], i) => `
    <div class="timeline-line position-relative${i === langkah.length - 1 ? ' timeline-line-akhir' : ''}">
      <span class="timeline-dot position-absolute rounded-circle ${selesai ? 'bg-success' : 'bg-secondary-subtle border border-secondary'}"></span>
      <div class="fw-semibold">${esc(judul)}</div>
      <small class="text-muted">${esc(ket)}</small>
    </div>`,
                )
                .join('');
            tampil('viewSukses');
        }

        init();
    }

    /* ---------- 7. Pelanggan: Pesanan Saya (dulu Pesanan.js) ----------
   Countdown sewa, tracking kurir, scan QR, filter & pencarian pesanan. */
    const kelasFilterAktif = 'btn btn-primary btn-sm px-3 rounded-pill fw-semibold';
    const kelasFilterNonaktif = 'btn bg-light border text-secondary btn-sm px-3 rounded-pill';
    let deliveryTimer = null;

    const setTeks = (id, teks) => {
        const el = $(id);
        if (el) el.innerText = teks;
    };
    const tutupModal = (id) => {
        const el = $(id);
        const instance = el && bootstrap.Modal.getInstance(el);
        if (instance) instance.hide();
    };

    function simulasiTiba() {
        clearInterval(deliveryTimer);

        const badge = $('statusBadgeDelivery');
        if (badge) {
            badge.className = 'badge bg-success-subtle text-success border border-success-subtle rounded-pill';
            badge.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Pesanan Tiba & Pasang';
        }

        const liveCountdown = $('liveCountdownDelivery');
        if (liveCountdown) {
            liveCountdown.innerText = 'Pesanan Telah Diterima';
            liveCountdown.className = 'fw-bold fs-6 text-success';
        }

        const progressBar = $('kurirProgressBar');
        if (progressBar) {
            progressBar.style.width = '64%';
            progressBar.style.backgroundColor = '#10b981';
            progressBar.style.boxShadow = '0 0 8px rgba(16, 185, 129, 0.5)';
        }

        const dotKurir = $('dotKurir');
        if (dotKurir) dotKurir.className = 'stepper-dot bg-success text-white border border-2 border-white';

        const dotTiba = $('dotTiba');
        if (dotTiba) {
            dotTiba.className = 'stepper-dot bg-success text-white';
            dotTiba.innerHTML = '<i class="bi bi-check-lg fs-6"></i>';
        }

        const textTiba = $('textTiba');
        if (textTiba) textTiba.className = 'fw-bold text-success mt-2';

        setTeks('shopeeStatusHead', 'Pesanan Telah Tiba & Diserahterimakan');
        setTeks('shopeeStatusSub', 'Unit paket Playbox telah terpasang dan diverifikasi oleh pelanggan.');
    }

    function handleAdminScan(type) {
        if (type === 'terima') {
            tutupModal('modalQRTerima');
            simulasiTiba();
            setTeks('successModalTitle', 'Pesanan Anda telah diterima');
            setTeks('successModalSub', 'Penjual sedang menyiapkan pesananmu');
        } else if (type === 'kembali') {
            tutupModal('modalStopKembalikan');
            setTeks('successModalTitle', 'Pengembalian Unit Berhasil');
            setTeks(
                'successModalSub',
                'Unit konsol dan dokumen jaminan fisik Anda telah selesai diverifikasi oleh kurir/petugas.',
            );
        }

        setTimeout(() => {
            const successModal = new bootstrap.Modal($('modalSuccessStatus'));
            successModal.show();
        }, 400);
    }

    function tambahWaktuSewa() {
        alert('Membuka halaman perpanjangan sewa...');
    }

    function filterTab(type) {
        const tombol = { all: 'filterAll', active: 'filterActive', done: 'filterDone', cancel: 'filterCancel' };
        Object.entries(tombol).forEach(([key, id]) => {
            const el = $(id);
            if (el) el.className = key === type ? kelasFilterAktif : kelasFilterNonaktif;
        });

        const berlangsung = $('sectionBerlangsung');
        const selesai = $('sectionSelesai');
        if (berlangsung) berlangsung.style.display = type === 'all' || type === 'active' ? 'block' : 'none';
        if (selesai) selesai.style.display = type === 'all' || type === 'done' ? 'block' : 'none';
    }

    function searchOrders() {
        const input = $('globalSearchInput');
        const query = (input ? input.value : '').toLowerCase();
        document.querySelectorAll('.order-card').forEach((card) => {
            const title = card.getAttribute('data-title') || '';
            card.style.display = title.toLowerCase().includes(query) ? '' : 'none';
        });
    }

    function initPesanan() {
        if (!$('liveCountdownPlay') && !$('filterAll')) return;


        // Hitung mundur sisa waktu main
        let totalPlaySeconds = 19 * 3600 + 19 * 60 + 53;
        const maxPlaySeconds = 24 * 3600;
        const elPlay = $('liveCountdownPlay');
        if (elPlay) {
            setInterval(() => {
                if (totalPlaySeconds <= 0) return;
                totalPlaySeconds--;
                const hours = Math.floor(totalPlaySeconds / 3600);
                const minutes = Math.floor((totalPlaySeconds % 3600) / 60);
                const seconds = totalPlaySeconds % 60;
                elPlay.innerText = `${hours} Jam ${p2(minutes)} Menit ${p2(seconds)} Detik`;

                const bar = $('liveProgressBar');
                if (bar) {
                    bar.style.width = ((totalPlaySeconds / maxPlaySeconds) * 100).toFixed(2) + '%';
                    if (totalPlaySeconds < 3600) {
                        bar.className = 'progress-bar progress-animated-striped progress-bar-waktu bg-danger';
                    } else if (totalPlaySeconds < 7200) {
                        bar.className =
                            'progress-bar progress-animated-striped progress-bar-waktu bg-warning text-dark';
                    }
                }
            }, 1000);
        }

        // Hitung mundur kedatangan kurir
        let deliverySeconds = 11 * 60 + 21;
        const maxDeliverySeconds = 30 * 60;
        const elDelivery = $('liveCountdownDelivery');
        if (elDelivery) {
            deliveryTimer = setInterval(() => {
                if (deliverySeconds > 0) {
                    deliverySeconds--;
                    elDelivery.innerText = `± ${Math.ceil(deliverySeconds / 60)} Menit`;
                    const lebar = Math.min(((maxDeliverySeconds - deliverySeconds) / maxDeliverySeconds) * 64, 64);
                    const progressBar = $('kurirProgressBar');
                    if (progressBar) progressBar.style.width = lebar.toFixed(2) + '%';
                } else {
                    clearInterval(deliveryTimer);
                    simulasiTiba();
                }
            }, 1000);
        }

        // Kolom cari di topbar dipakai untuk menyaring pesanan (header.php: beri id="globalSearchInput")
        const cari = $('globalSearchInput');
        if (cari) cari.addEventListener('input', searchOrders);
    }

    /* Riwayat peminjaman (Pesanan.php): DataTables + pencarian + filter tanggal + ekspor PDF.
       Butuh jQuery, DataTables, Buttons (html5) dan pdfmake dari footer.php.
       Di file ini `$` = getElementById, jadi jQuery dipakai lewat window.jQuery. */
    function initRiwayat() {
        const el = $('tabelRiwayat');
        if (!el) return;

        const jq = window.jQuery;
        if (!jq || !jq.fn.DataTable || !jq.fn.dataTable.ext.buttons.pdfHtml5 || !window.pdfMake) {
            console.warn('[PS Rental] DataTables/Buttons/pdfmake belum dimuat, riwayat tampil sebagai tabel biasa.');
            return;
        }

        const inCari = $('cariRiwayat');
        const inDari = $('tglDari');
        const inSampai = $('tglSampai');
        const teks = (n) => (n ? n.textContent.replace(/\s+/g, ' ').trim() : '');
        const tglIndo = (iso) => {
            const [y, m, d] = String(iso).split('-');
            return `${d} ${BULAN[+m - 1]} ${y}`;
        };

        // Filter tanggal: dibandingkan sebagai teks Y-m-d (urutannya sama dengan urutan tanggal).
        // Daftar filter DataTables berlaku untuk semua tabel, jadi dibatasi ke tabel ini saja.
        jq.fn.dataTable.ext.search.push((settings, data, idx) => {
            if (settings.nTable !== el) return true;
            const iso = settings.aoData[idx].nTr.dataset.tanggal;
            if (!iso) return true;
            if (inDari.value && iso < inDari.value) return false;
            if (inSampai.value && iso > inSampai.value) return false;
            return true;
        });

        const tabel = jq(el).DataTable({
            dom: 't<"d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 fz-75"ip>',
            autoWidth: false,
            ordering: false,
            pageLength: 10,
            columnDefs: [{ targets: 5, searchable: false }], // kolom "Tindakan" tidak ikut dicari
            language: {
                info: 'Menampilkan _START_–_END_ dari _TOTAL_ pesanan',
                infoEmpty: 'Tidak ada pesanan',
                infoFiltered: '(disaring dari _MAX_ pesanan)',
                zeroRecords: 'Tidak ada riwayat yang cocok dengan filter.',
                emptyTable: 'Belum ada riwayat peminjaman.',
                paginate: { previous: '‹', next: '›' },
            },
            // Tombol bawaan DataTables tidak ditampilkan; dipicu dari tombol "Unduh Semua" di bawah.
            buttons: [
                {
                    extend: 'pdfHtml5',
                    title: 'Riwayat Peminjaman Selesai',
                    pageSize: 'A4',
                    orientation: 'portrait',
                    filename: () => {
                        const d = new Date();
                        return `riwayat-pesanan-${d.getFullYear()}-${p2(d.getMonth() + 1)}-${p2(d.getDate())}`;
                    },
                    messageTop: () => {
                        const info = [];
                        if (inDari.value || inSampai.value) {
                            info.push(
                                `Periode selesai: ${inDari.value ? tglIndo(inDari.value) : 'awal'} s/d ${inSampai.value ? tglIndo(inSampai.value) : 'sekarang'}`,
                            );
                        }
                        if (inCari.value.trim()) info.push(`Pencarian: "${inCari.value.trim()}"`);
                        return info.join('  |  ') || 'Semua riwayat peminjaman selesai';
                    },
                    messageBottom: () => `Dicetak: ${tglLengkap(new Date())}`,
                    exportOptions: {
                        columns: [0, 1, 2, 3, 4], // tanpa kolom "Tindakan"
                        format: {
                            // Ambil teks dari isi sel (gambar dibuang, nama unit & keterangan dipisah baris)
                            body: (data, row, col, node) => {
                                if (!node) return String(data).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                                if (col <= 2) {
                                    const atas = teks(node.querySelector('strong'));
                                    const bawah = teks(node.querySelector('span'));
                                    return bawah ? `${atas}\n${bawah}` : atas;
                                }
                                return teks(node).replace(/^✓\s*/, ''); // font bawaan PDF tidak punya simbol ✓
                            },
                        },
                    },
                    customize: (doc) => {
                        doc.pageMargins = [32, 40, 32, 40];
                        doc.defaultStyle.fontSize = 9;
                        doc.styles.title.alignment = 'left';
                        doc.styles.title.fontSize = 14;
                        doc.styles.tableHeader.alignment = 'left';
                        doc.styles.tableHeader.fillColor = '#0d6efd';
                        const isi = doc.content.find((c) => c.table);
                        if (isi) isi.table.widths = ['*', 100, 80, 80, 65];
                    },
                },
            ],
        });

        inCari.addEventListener('input', () => tabel.search(inCari.value).draw());

        [inDari, inSampai].forEach((inp) =>
            inp.addEventListener('change', () => {
                inSampai.min = inDari.value; // rentang tidak boleh terbalik
                inDari.max = inSampai.value;
                tabel.draw();
            }),
        );

        $('btnResetRiwayat').addEventListener('click', () => {
            inCari.value = inDari.value = inSampai.value = '';
            inSampai.min = inDari.max = '';
            tabel.search('').draw();
        });

        $('btnUnduhRiwayat').addEventListener('click', () => {
            if (tabel.rows({ search: 'applied' }).count() === 0) {
                alert('Tidak ada data riwayat untuk diunduh. Ubah pencarian atau filter tanggal dulu.');
                return;
            }
            tabel.button('.buttons-pdf').trigger();
        });
    }

    /* ---------- 8. Admin: Penyewaan (dulu Penyewaan.js) ----------
   Halaman operator: verifikasi, scan serah/terima, status delivery, sewa di tempat.
   Menggantikan bagian admin lama di app.js (id elemennya sama, jadi tidak boleh jalan bersamaan). */
    const angka = (s) => parseInt(String(s || '').replace(/\D/g, ''), 10) || 0;
    const norm = (s) =>
        String(s || '')
            .trim()
            .toUpperCase()
            .replace(/^#/, '');
    const detikDurasi = (t) => {
        const m = /(\d+)\s*(hari|jam)/i.exec(t || '');
        return m ? +m[1] * (m[2].toLowerCase() === 'hari' ? 86400 : 3600) : 0;
    };
    const modal = (id) => bootstrap.Modal.getOrCreateInstance($(id));
    const toast = (msg) => {
        $('toastMsg').textContent = msg;
        bootstrap.Toast.getOrCreateInstance($('toastApp')).show();
    };

    // label & warna badge per status
    const STATE = {
        menunggu: ['Menunggu Verifikasi', 'bg-warning text-dark'],
        siap: ['Siap', 'bg-info text-dark'],
        diantar: ['Sedang Diantar', 'bg-primary'],
        sampai: ['Sudah Sampai Lokasi', 'bg-primary'],
        aktif: ['Aktif', 'bg-success'],
        habis: ['Waktu Habis', 'bg-danger'],
        terlambat: ['Terlambat', 'bg-danger'],
        selesai: ['Selesai', 'bg-secondary'],
        ditolak: ['Ditolak', 'bg-dark'],
    };
    // filter status -> kumpulan state
    const GRUP = {
        menunggu: ['menunggu'],
        proses: ['siap', 'diantar', 'sampai'],
        aktif: ['aktif', 'habis', 'terlambat'],
        lewat: ['habis', 'terlambat'],
        selesai: ['selesai'],
        ditolak: ['ditolak'],
    };
    const CEK = {
        serah: [
            'Kelengkapan unit sesuai (stik, kabel, aksesori)',
            'Unit menyala dan berfungsi normal',
            'Jaminan asli diterima dan sesuai identitas pelanggan',
        ],
        terima: ['Kelengkapan unit lengkap (stik, kabel, aksesori)', 'Jaminan asli sudah dikembalikan ke pelanggan'],
    };
    const KONDISI = { baik: 'kondisi baik', maint: 'perlu maintenance', rusak: 'rusak' };

    // ---------- Sinkron status unit ke halaman Unit PS (localStorage milik unit.js) ----------
    function statusUnitTersimpan() {
        const m = {};
        try {
            JSON.parse(localStorage.getItem(KEY_UNIT)).forEach((p) =>
                p.unit.forEach((u) => {
                    m[u.kode] = u.status;
                }),
            );
        } catch { }
        return m;
    }
    function setStatusUnit(serial, status, catatan = '') {
        if (!serial) return;
        try {
            const d = JSON.parse(localStorage.getItem(KEY_UNIT));
            if (!Array.isArray(d)) return;
            d.forEach((p) =>
                p.unit.forEach((u) => {
                    if (u.kode === serial) {
                        u.status = status;
                        u.catatan = status === 'tersedia' ? '' : catatan;
                    }
                }),
            );
            localStorage.setItem(KEY_UNIT, JSON.stringify(d));
        } catch { }
    }

    function initPenyewaan() {
        if (!$('modalVerifikasi')) return;

        const semua = () => [...document.querySelectorAll('tr[data-id]')];
        let cur = null,
            scanTr = null,
            scanMode = '',
            stream = null,
            camLoop = null;

        const catat = (tr, teks, foto) => {
            (tr._log = tr._log || []).push({ teks, waktu: tglLengkap(new Date()), foto });
        };

        /* ---------- Filter, statistik, total pembayaran ---------- */
        function apply() {
            const fEl = $('fStatus'),
                lEl = $('fLayanan'),
                jEl = $('fJenis'),
                qEl = $('fCari');
            if (!fEl || !lEl || !jEl || !qEl) return;
            const f = fEl.value,
                l = lEl.value,
                j = jEl.value,
                q = qEl.value.trim().toLowerCase();
            const rows = semua();
            let n = 0;
            rows.forEach((r) => {
                const s = r.dataset.state || '';
                const ok =
                    (f === 'all' || (GRUP[f] || []).includes(s)) &&
                    (l === 'all' || l === r.dataset.layanan) &&
                    (j === 'all' || j === r.dataset.jenis) &&
                    ((r.dataset.id || '') + ' ' + (r.dataset.nama || '')).toLowerCase().includes(q);
                r.classList.toggle('d-none', !ok);
                if (ok) n++;
            });
            $('kosong').classList.toggle('d-none', n > 0);
            const c = (ks) => rows.filter((r) => ks.includes(r.dataset.state)).length;
            $('sMenunggu').textContent = c(GRUP.menunggu);
            $('sKeluar').textContent = c(GRUP.aktif);
            $('sTelat').textContent = c(GRUP.lewat);

            // Total pembayaran sewa: terverifikasi/lunas vs. masih menunggu
            let total = 0,
                tunda = 0;
            rows.forEach((r) => {
                const s = r.dataset.state;
                if (s === 'ditolak') return;
                const v = angka(r.dataset.total || r.dataset.sewa);
                if (s === 'menunggu' || r.dataset.statusBayar === 'Belum Bayar') tunda += v;
                else total += v;
            });
            $('sTotalBayar').textContent = rp(total);
            $('sTotalTunda').textContent = tunda
                ? `Belum terverifikasi/lunas: ${rp(tunda)}`
                : 'Semua pembayaran terverifikasi';
        }

        /* ---------- Status baris & tombol aksi ---------- */
        const btn = (cls, ikon, teks, aksi) =>
            `<button type="button" class="btn btn-sm ${cls}" data-aksi="${aksi}"><i class="bi ${ikon} me-1"></i>${teks}</button>`;
        function renderAksi(tr) {
            const s = tr.dataset.state,
                l = tr.dataset.layanan;
            let x = '';
            if (s === 'menunggu')
                x =
                    btn('btn-success', 'bi-patch-check', 'Verifikasi', 'verifikasi') +
                    btn('btn-outline-danger', 'bi-x-lg', 'Tolak', 'tolak');
            else if (s === 'siap')
                x =
                    l === 'delivery'
                        ? btn('btn-primary', 'bi-truck', 'Berangkat Antar', 'berangkat')
                        : btn('btn-primary', 'bi-upc-scan', 'Serah Unit', 'serah');
            else if (s === 'diantar') x = btn('btn-primary', 'bi-geo-alt-fill', 'Sudah Sampai', 'sampai');
            else if (s === 'sampai') x = btn('btn-primary', 'bi-upc-scan', 'Serah Unit', 'serah');
            else if (GRUP.aktif.includes(s))
                x =
                    l === 'ditempat'
                        ? btn('btn-primary', 'bi-box-arrow-in-down-left', 'Check-Out', 'checkout')
                        : btn('btn-primary', 'bi-upc-scan', 'Terima Unit', 'terima');
            const detail =
                '<button type="button" class="btn btn-sm btn-outline-primary rounded-pill btn-detail" data-bs-toggle="modal" data-bs-target="#modalDetail"><i class="bi bi-eye me-1"></i> Detail</button>';
            tr.querySelector('td:last-child').innerHTML = `<div class="d-flex gap-2">${detail}${x}</div>`;
        }
        function setState(tr, s) {
            tr.dataset.state = s;
            let [label, cls] = STATE[s];
            if (s === 'siap') label = tr.dataset.layanan === 'delivery' ? 'Siap Diantar' : 'Siap Diambil';
            const st = tr.querySelector('.st');
            st.className = 'st badge px-3 py-2 rounded-pill ' + cls;
            st.textContent = label;
            renderAksi(tr);
            apply();
        }

        /* ---------- Timer: ditempat jadi "Waktu Habis" (tanpa denda), pickup/delivery "Terlambat" ---------- */
        function tick() {
            semua().forEach((r) => {
                if (r._s === undefined || r.dataset.done || !GRUP.aktif.includes(r.dataset.state)) return;
                r._s--;
                const a = Math.abs(r._s),
                    late = r._s < 0;
                const s = !late ? 'aktif' : r.dataset.layanan === 'ditempat' ? 'habis' : 'terlambat';
                const el = r.querySelector('.sisa');
                if (el) {
                    el.textContent =
                        (late ? '+' : '') +
                        p2(Math.floor(a / 3600)) +
                        ':' +
                        p2(Math.floor((a % 3600) / 60)) +
                        ':' +
                        p2(a % 60);
                    el.className = 'sisa fw-bold' + (late ? ' text-danger' : '');
                }
                if (r.dataset.state !== s) setState(r, s);
            });
        }

        function mulaiSewa(tr) {
            // unit diserahkan -> hitung mundur dimulai
            const now = new Date(),
                dtk = detikDurasi(tr.dataset.durasi);
            const habis = new Date(now.getTime() + dtk * 1000);
            tr._s = dtk;
            tr.dataset.jamAwal = tglLengkap(now);
            tr.dataset.jamHabis = tglLengkap(habis);
            tr.cells[5].className = 'sisa fw-bold';
            setState(tr, 'aktif');
        }
        function selesaikan(tr) {
            tr.dataset.done = '1';
            tr.cells[5].textContent = '—';
            tr.cells[5].className = 'sisa fw-bold text-muted';
            setState(tr, 'selesai');
        }

        /* ---------- Verifikasi pembayaran & jaminan ---------- */
        function bukaVerif(tr) {
            cur = tr;
            $('vId').textContent = tr.dataset.id;
            $('vNama').textContent = `${tr.dataset.nama} · ${tr.dataset.hp || '-'}`;
            $('vLayanan').textContent = tr.dataset.layanan === 'delivery' ? 'Delivery' : 'Pickup (ambil di toko)';
            $('vTotal').textContent = tr.dataset.total || tr.dataset.sewa || 'Rp 0';
            $('vJaminan').textContent = tr.dataset.jaminan || '-';
            $('vBayar').checked = $('vJamin').checked = false;
            $('vConfirm').disabled = true;
            modal('modalVerifikasi').show();
        }
        ['vBayar', 'vJamin'].forEach((id) =>
            $(id).addEventListener('change', () => {
                $('vConfirm').disabled = !($('vBayar').checked && $('vJamin').checked);
            }),
        );
        $('vConfirm').onclick = () => {
            catat(cur, 'Pembayaran dan jaminan diverifikasi operator');
            setState(cur, 'siap');
            modal('modalVerifikasi').hide();
            toast(
                `${cur.dataset.id} terverifikasi. ${cur.dataset.layanan === 'delivery' ? 'Siap diantar.' : 'Siap diambil pelanggan.'}`,
            );
        };

        /* ---------- Scan barcode: serah & terima unit ---------- */
        function bukaScan(tr, mode) {
            scanTr = tr;
            scanMode = mode;
            const serah = mode === 'serah',
                d = !serah && tr._s < 0 ? hitungDenda(tr._s) : null;
            $('scanJudul').textContent = `${serah ? 'Serah' : 'Terima'} Unit ${tr.dataset.id}`;
            $('scanSub').textContent =
                `${tr.dataset.nama} · ${tr.dataset.unit} · ${tr.dataset.layanan === 'delivery' ? 'Delivery' : 'Pickup'}`;
            $('scanHarapPesanan').textContent = tr.dataset.id;
            $('scanHarapUnit').textContent = tr.dataset.serial || '(serial belum diisi)';
            ['scanPesanan', 'scanUnit', 'scanCatatan'].forEach((i) => {
                $(i).value = '';
            });
            $('scanFoto').value = '';
            $('scanPreview').classList.add('d-none');
            $('scanKondisi').value = 'baik';
            $('scanKondisiWrap').classList.toggle('d-none', serah);
            $('scanDenda').classList.toggle('d-none', !(d && d.t));
            if (d && d.t) $('scanDendaInfo').textContent = `Terlambat ${d.m} menit → denda ${rp(d.t)}`;
            const daftar = CEK[mode].concat(d && d.t ? [`Denda keterlambatan ${rp(d.t)} sudah dibayar pelanggan`] : []);
            $('scanCek').innerHTML = daftar
                .map(
                    (t, i) =>
                        `<div class="form-check"><input class="form-check-input cek-validasi" type="checkbox" id="cek${i}"><label class="form-check-label small" for="cek${i}">${esc(t)}</label></div>`,
                )
                .join('');
            cekScan();
            modal('modalScan').show();
        }
        function tandai(id, ok) {
            const el = $(id);
            el.classList.toggle('is-valid', !!el.value && ok);
            el.classList.toggle('is-invalid', !!el.value && !ok);
        }
        function cekScan() {
            const t = scanTr;
            if (!t) return;
            const okP = norm($('scanPesanan').value) === norm(t.dataset.id);
            const okU = !!t.dataset.serial && norm($('scanUnit').value) === norm(t.dataset.serial);
            tandai('scanPesanan', okP);
            tandai('scanUnit', okU);
            const cek = [...document.querySelectorAll('#modalScan .cek-validasi')].every((c) => c.checked);
            const catatanOk =
                scanMode === 'serah' || $('scanKondisi').value === 'baik' || $('scanCatatan').value.trim();
            $('scanConfirm').disabled = !(okP && okU && cek && catatanOk && $('scanFoto').files.length);
        }
        $('modalScan').addEventListener('input', cekScan);
        $('modalScan').addEventListener('change', (e) => {
            if (e.target.id === 'scanFoto') {
                const f = e.target.files[0];
                if (f) {
                    $('scanPreview').src = URL.createObjectURL(f);
                    $('scanPreview').classList.remove('d-none');
                }
            }
            cekScan();
        });
        ['scanPesanan', 'scanUnit'].forEach((id, i) =>
            $(id).addEventListener('keydown', (e) => {
                // alat scan biasanya mengirim Enter
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (i === 0) $('scanUnit').focus();
                }
            }),
        );
        $('modalScan').addEventListener('shown.bs.modal', () => $('scanPesanan').focus());
        $('modalScan').addEventListener('hidden.bs.modal', stopKamera);

        async function mulaiKamera(target) {
            if (!('BarcodeDetector' in window))
                return toast('Browser ini belum mendukung scan kamera. Pakai alat scan barcode atau ketik kodenya.');
            try {
                stopKamera();
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                const v = $('scanVideo');
                v.srcObject = stream;
                v.classList.remove('d-none');
                await v.play();
                const det = new BarcodeDetector();
                camLoop = setInterval(async () => {
                    const r = await det.detect(v).catch(() => []);
                    if (r.length) {
                        target.value = r[0].rawValue;
                        stopKamera();
                        cekScan();
                    }
                }, 400);
            } catch {
                toast('Kamera tidak bisa dibuka. Izinkan akses kamera atau ketik kodenya.');
            }
        }
        function stopKamera() {
            clearInterval(camLoop);
            if (stream) stream.getTracks().forEach((t) => t.stop());
            stream = null;
            $('scanVideo').classList.add('d-none');
        }
        document.querySelectorAll('[data-cam]').forEach((b) => {
            b.onclick = () => mulaiKamera($(b.dataset.cam));
        });

        $('scanConfirm').onclick = () => {
            const tr = scanTr,
                foto = URL.createObjectURL($('scanFoto').files[0]);
            if (scanMode === 'serah') {
                mulaiSewa(tr);
                setStatusUnit(tr.dataset.serial, 'disewa');
                catat(tr, 'Unit diserahkan ke pelanggan (tervalidasi, bukti dikirim)', foto);
            } else {
                const k = $('scanKondisi').value,
                    d = tr._s < 0 ? hitungDenda(tr._s) : { t: 0 };
                if (d.t) tr.dataset.denda = d.t;
                selesaikan(tr);
                setStatusUnit(
                    tr.dataset.serial,
                    { baik: 'tersedia', maint: 'maintenance', rusak: 'rusak' }[k],
                    $('scanCatatan').value.trim(),
                );
                catat(
                    tr,
                    `Unit diterima kembali, ${KONDISI[k]} (tervalidasi, bukti dikirim)` +
                    (d.t ? `, denda ${rp(d.t)}` : ''),
                    foto,
                );
            }
            modal('modalScan').hide();
            toast(
                `Bukti ${scanMode === 'serah' ? 'serah' : 'terima'} unit ${tr.dataset.id} dikirim ke ${tr.dataset.nama}.`,
            );
        };

        /* ---------- Check-out sewa di tempat (tanpa denda) ---------- */
        function bukaCheckout(tr) {
            cur = tr;
            const now = new Date(),
                s = tr._s || 0;
            $('coId').textContent = tr.dataset.id;
            $('coNama').textContent = `${tr.dataset.nama} · ${tr.dataset.unit}`;
            $('coRencana').textContent = hm(new Date(now.getTime() + s * 1000));
            $('coAktual').textContent = hm(now);
            $('coTelat').textContent =
                s < 0 ? `Waktu habis ${Math.ceil(-s / 60)} menit lalu` : 'Masih dalam waktu sewa';
            modal('modalCheckout').show();
        }
        $('coConfirm').onclick = () => {
            selesaikan(cur);
            setStatusUnit(cur.dataset.serial, 'tersedia');
            modal('modalCheckout').hide();
            toast(`${cur.dataset.id} selesai. Unit kembali tersedia.`);
        };

        /* ---------- Aksi di tabel ---------- */
        const AKSI = {
            verifikasi: bukaVerif,
            tolak: (tr) => {
                const a = prompt(`Alasan menolak ${tr.dataset.id} (dikirim ke pelanggan):`);
                if (a === null) return;
                catat(tr, 'Pesanan ditolak: ' + (a.trim() || 'tanpa keterangan'));
                setState(tr, 'ditolak');
                toast(`${tr.dataset.id} ditolak.`);
            },
            berangkat: (tr) => {
                catat(tr, 'Unit berangkat diantar ke alamat pelanggan');
                setState(tr, 'diantar');
                toast(`${tr.dataset.id}: status diperbarui, sedang diantar.`);
            },
            sampai: (tr) => {
                catat(tr, 'Unit sudah sampai di lokasi pelanggan');
                setState(tr, 'sampai');
                toast(`${tr.dataset.id}: status diperbarui, sudah sampai lokasi.`);
            },
            serah: (tr) => bukaScan(tr, 'serah'),
            terima: (tr) => bukaScan(tr, 'terima'),
            checkout: bukaCheckout,
        };
        document.querySelector('tbody').addEventListener('click', (e) => {
            const b = e.target.closest('[data-aksi]');
            if (b) AKSI[b.dataset.aksi](b.closest('tr'));
        });

        /* ---------- Tambah sewa (khusus di tempat) ---------- */
        const modalTambahEl = $('modalTambahSewa');
        modalTambahEl.addEventListener('show.bs.modal', () => {
            $('addWaktuOtomatis').value = hm(new Date()) + ' WIB (Hari ini)';
            const st = statusUnitTersimpan(),
                sel = $('addUnitRuang');
            [...sel.options].forEach((o) => {
                // unit maintenance/rusak/disewa tidak bisa dipilih
                if (!o.dataset.serial) return;
                o.dataset.label = o.dataset.label || o.textContent.trim().replace(/\s+/g, ' ');
                const s = st[o.dataset.serial],
                    mati = !!s && s !== 'tersedia';
                o.disabled = mati;
                o.textContent = o.dataset.label + (mati ? ` — ${s}` : '');
            });
            if (sel.selectedOptions[0] && sel.selectedOptions[0].disabled) sel.value = '';
            hitungTotalOtomatis();
        });
        function hitungTotalOtomatis() {
            const opt = $('addUnitRuang').selectedOptions[0];
            const tarif = opt && opt.dataset.tarif ? +opt.dataset.tarif : 0;
            $('addTotalOtomatis').value = rp(tarif * (+$('addDurasiJam').value || 1));
        }
        $('addUnitRuang').onchange = hitungTotalOtomatis;
        $('addDurasiJam').oninput = hitungTotalOtomatis;

        $('formTambahSewa').onsubmit = (e) => {
            e.preventDefault();
            const opt = $('addUnitRuang').selectedOptions[0],
                durasi = Math.min(24, Math.max(1, +$('addDurasiJam').value || 1));
            const m = /^(.*?)\s*\((.*)\)$/.exec(opt.value) || [0, opt.value, ''];
            const now = new Date(),
                total = rp((+opt.dataset.tarif || 0) * durasi);
            const nomor = Math.max(100, ...semua().map((r) => angka(r.dataset.id))) + 1;
            const tr = document.createElement('tr');
            Object.assign(tr.dataset, {
                id: '#TRX-' + nomor,
                nama: $('addNama').value.trim(),
                unit: opt.value,
                serial: opt.dataset.serial || '',
                jenis: m[1].slice(0, 3).toLowerCase(),
                layanan: 'ditempat',
                state: 'aktif',
                sisa: durasi * 3600,
                jamAwal: hm(now) + ' WIB',
                jamHabis: hm(new Date(now.getTime() + durasi * 3600000)) + ' WIB',
                durasi: durasi + ' Jam',
                sewa: total,
                total,
                statusBayar: $('addStatusBayar').value,
            });
            tr._s = durasi * 3600;
            tr.innerHTML = `<td class="fw-bold">#TRX-${nomor}</td><td class="fw-bold text-dark">${esc(tr.dataset.nama)}</td>
            <td>${esc(m[1])} <small class="d-block text-muted">${esc(m[2])}</small></td>
            <td><span class="badge bg-success-subtle text-success-emphasis rounded-pill px-3 py-2">Di tempat</span></td>
            <td>${total}</td><td class="sisa fw-bold">--:--:--</td><td><span class="st badge px-3 py-2 rounded-pill"></span></td><td></td>`;
            document.querySelector('tbody').insertBefore(tr, $('kosong'));
            setStatusUnit(tr.dataset.serial, 'disewa');
            setState(tr, 'aktif');
            modal('modalTambahSewa').hide();
            e.target.reset();
            toast(`Sewa di tempat ${tr.dataset.id} dimulai.`);
        };

        /* ---------- Detail pesanan ---------- */
        $('modalDetail').addEventListener('show.bs.modal', (e) => {
            const tr = e.relatedTarget && e.relatedTarget.closest('tr');
            if (!tr) return;
            const l = tr.dataset.layanan || 'ditempat',
                s = tr.dataset.state || '',
                ditempat = l === 'ditempat';
            const set = (id, v) => {
                $(id).textContent = v;
            };
            set('dtlId', tr.dataset.id || '-');
            set('dtlNama', tr.dataset.nama || '-');
            set('dtlUnit', tr.dataset.unit || '-');
            set('dtlJamAwal', tr.dataset.jamAwal || tr.dataset.tgl || '-');
            set('dtlJamHabis', tr.dataset.jamHabis || '-');
            set('dtlDurasi', tr.dataset.durasi || '-');
            set('dtlBiayaSewa', tr.dataset.sewa || 'Rp 0');
            $('dtlStatusBayar').innerHTML =
                `<span class="badge ${tr.dataset.statusBayar === 'Belum Bayar' ? 'bg-warning text-dark' : 'bg-success'}">${esc(tr.dataset.statusBayar || 'Lunas')}</span>`;
            const [lbl, cls] = STATE[s] || ['-', 'bg-secondary'];
            $('dtlBadgeState').className = `badge ${cls} px-3 py-2 rounded-pill`;
            $('dtlBadgeState').textContent = tr.querySelector('.st').textContent || lbl;
            set('dtlBadgeLayanan', ditempat ? 'Di tempat' : l.charAt(0).toUpperCase() + l.slice(1));
            $('secUserOnly').style.display = ditempat ? 'none' : 'block';
            $('secBukti').style.display = ditempat ? 'none' : 'block';
            $('rowOngkir').style.display = l === 'delivery' ? 'flex' : 'none';
            if (!ditempat) {
                set('dtlHp', tr.dataset.hp || '-');
                set('dtlJaminan', tr.dataset.jaminan || '-');
                set('dtlAlamat', tr.dataset.alamat || '-');
                set('dtlOngkir', tr.dataset.ongkir || 'Rp 0');
                $('dtlBukti').innerHTML =
                    (tr._log || [])
                        .map(
                            (
                                x,
                            ) => `<div class="d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill text-success"></i>
                <span class="flex-grow-1">${esc(x.teks)}<small class="d-block text-muted">${esc(x.waktu)}</small></span>
                ${x.foto ? `<a href="${x.foto}" target="_blank"><img src="${x.foto}" width="44" height="44" class="rounded border obj-cover" alt="Bukti"></a>` : ''}</div>`,
                        )
                        .join('') || '<span class="text-muted">Belum ada catatan verifikasi atau serah terima.</span>';
            }
            set('dtlTotalTagihan', (ditempat ? tr.dataset.sewa : tr.dataset.total || tr.dataset.sewa) || 'Rp 0');

            const telat = !ditempat && tr._s !== undefined && tr._s < 0 && !tr.dataset.done,
                final = angka(tr.dataset.denda);
            const d = telat ? hitungDenda(tr._s) : null;
            $('secDenda').style.display = telat || final ? 'block' : 'none';
            if (telat) {
                set('dtlDendaNominal', rp(d.t));
                set('dtlDendaKeterangan', `Terlambat ${d.m} menit (${d.j} jam denda @ Rp 10.000/jam)`);
            } else if (final) {
                set('dtlDendaNominal', rp(final));
                set('dtlDendaKeterangan', 'Denda keterlambatan tercatat saat unit diterima');
            }
            $('secHabis').style.display = ditempat && s === 'habis' ? 'block' : 'none';
        });

        /* ---------- Filter & init ---------- */
        ['fStatus', 'fLayanan', 'fJenis'].forEach((id) => {
            $(id).onchange = apply;
        });
        $('fCari').oninput = apply;
        document.querySelectorAll('[data-f]').forEach((c) => {
            c.onclick = () => {
                $('fStatus').value = c.dataset.f;
                apply();
            };
        });

        semua().forEach((r) => {
            if (r.dataset.sisa !== undefined) r._s = parseInt(r.dataset.sisa, 10) || 0;
        });
        semua().forEach((r) => setState(r, r.dataset.state));
        tick();
        setInterval(tick, 1000);
    }

    /* ---------- 9. Admin: Unit PS (dulu unit.js v2) ----------
   Katalog admin PS Rental (data dummy di localStorage).
   Paket katalog (parent) -> unit fisik (child). Paket Combo tidak punya unit sendiri:
   stoknya dihitung dari komponen (paket PS / TV yang digabung). */
    function initUnitPS() {
        if (!$('tbodyUnit')) return;
        const KAT = {
            ps3: 'PlayStation 3',
            ps4: 'PlayStation 4',
            ps5: 'PlayStation 5',
            combo: 'Paket Playbox + TV LED',
            tv: 'TV LED Only',
        };
        const ST = {
            tersedia: ['Tersedia', 'bi-check-circle-fill'],
            disewa: ['Disewa', 'bi-person-fill'],
            maintenance: ['Maintenance', 'bi-tools'],
            rusak: ['Rusak', 'bi-exclamation-triangle-fill'],
        };
        const IKON = {
            ps3: ['bi-controller', 'blue'],
            ps4: ['bi-controller', 'blue'],
            ps5: ['bi-playstation', 'violet'],
            combo: ['bi-box-seam', 'orange'],
            tv: ['bi-tv', 'teal'],
        };
        const SET = { maint: 'maintenance', selesai: 'tersedia', rusak: 'rusak', pulih: 'tersedia' };
        const PER = 4;
        const NEXT = { tersedia: 'maintenance', maintenance: 'tersedia', rusak: 'maintenance' }; // klik badge
        const TABS = [
            ['all', 'Semua'],
            ['tersedia', 'Tersedia'],
            ['disewa', 'Disewa'],
            ['maintenance', 'Maintenance'],
            ['rusak', 'Rusak'],
        ];
        const U = (pre, arr) =>
            arr.map((a, i) => ({
                kode: `${pre}-${String(i + 1).padStart(3, '0')}`,
                status: a[0],
                catatan: a[1] || '',
            }));
        const seed = () => [
            {
                id: 1,
                kode: 'KAT-PS4-SLIM',
                nama: 'PlayStation 4 Slim',
                kategori: 'ps4',
                badge: 'KONSOL ONLY',
                tag: '2 Stik DS4',
                deskripsi: '2 Stik DS4 + TV LED 43"',
                wk12: 40000,
                wk24: 70000,
                wn12: 50000,
                wn24: 85000,
                override: 'auto',
                komponen: [],
                unit: U('PS4', [['tersedia'], ['tersedia'], ['disewa', 'Rina S.'], ['maintenance', 'Stik drift']]),
            },
            {
                id: 2,
                kode: 'KAT-PS5-DISC',
                nama: 'PlayStation 5 Disc Edition',
                kategori: 'ps5',
                badge: 'BEST VALUE',
                tag: '2 DualSense',
                deskripsi: '2 DualSense + TV OLED 55"',
                wk12: 70000,
                wk24: 120000,
                wn12: 85000,
                wn24: 150000,
                override: 'auto',
                komponen: [],
                unit: U('PS5', [['tersedia'], ['disewa', 'Bagas W.']]),
            },
            {
                id: 3,
                kode: 'KAT-PLAYBOX-01',
                nama: 'Paket Playbox Outdoor + TV LED',
                kategori: 'combo',
                badge: 'PAKET LENGKAP',
                tag: 'PS4 + TV 32"',
                deskripsi: 'PS4 + 2 Stik + TV LED 32" + Tas Koper',
                wk12: 70000,
                wk24: 100000,
                wn12: 85000,
                wn24: 125000,
                override: 'auto',
                komponen: [1, 5],
                unit: [],
            },
            {
                id: 4,
                kode: 'KAT-PS3-SLIM',
                nama: 'PlayStation 3 Super Slim',
                kategori: 'ps3',
                badge: 'KONSOL ONLY',
                tag: '2 Stik DS3',
                deskripsi: '2 Stik DS3 + TV LED 32"',
                wk12: 30000,
                wk24: 50000,
                wn12: 40000,
                wn24: 65000,
                override: 'auto',
                komponen: [],
                unit: U('PS3', [
                    ['disewa', 'Ahmad R.'],
                    ['disewa', 'Dimas P.'],
                    ['maintenance', 'Kabel HDMI'],
                    ['rusak', 'YLOD'],
                    ['maintenance', 'Ganti Pasta'],
                ]),
            },
            {
                id: 5,
                kode: 'KAT-TV-LED',
                nama: 'TV LED 43"',
                kategori: 'tv',
                badge: 'TV LED ONLY',
                tag: '',
                deskripsi: 'TV LED 43", kabel HDMI + remote',
                wk12: 25000,
                wk24: 40000,
                wn12: 30000,
                wn24: 50000,
                override: 'auto',
                komponen: [],
                unit: U('TV', [['tersedia'], ['maintenance', 'Bracket patah'], ['maintenance', 'Kabel power']]),
            },
        ];

        let data;
        try {
            data = JSON.parse(localStorage.getItem(KEY_UNIT));
        } catch {
            data = null;
        }
        if (!Array.isArray(data)) data = seed();
        let kerja = [],
            komp = [],
            editId = null,
            hapusId = null,
            kat = 'all',
            sf = 'all',
            halaman = 1,
            weekend = false;
        const terbuka = new Set();

        /* ---------- Turunan: stok & status dihitung, tidak diketik ---------- */
        const cari = (id) => data.find((p) => p.id === id);
        const n = (p, s) => p.unit.filter((x) => x.status === s).length;
        const fisik = () => data.filter((p) => p.kategori !== 'combo');
        function hitung(p) {
            let h;
            if (p.kategori === 'combo') {
                const k = p.komponen.map(cari).filter(Boolean);
                h = {
                    total: k.length ? Math.min(...k.map((x) => x.unit.length)) : 0,
                    tersedia: k.length
                        ? Math.min(...k.map((x) => (x.override === 'Maintenance' ? 0 : n(x, 'tersedia'))))
                        : 0,
                    disewa: 0,
                    mnt: 0,
                    rsk: 0,
                };
            } else
                h = {
                    total: p.unit.length,
                    tersedia: n(p, 'tersedia'),
                    disewa: n(p, 'disewa'),
                    mnt: n(p, 'maintenance'),
                    rsk: n(p, 'rusak'),
                };
            h.status = p.override === 'Maintenance' ? 'Maintenance' : h.tersedia > 0 ? 'Tersedia' : 'Habis';
            return h;
        }

        /* ---------- Sinkron ke katalog pelanggan (simulasi) ---------- */
        function sinkron() {
            const items = data
                .filter((p) => p.override !== 'Maintenance')
                .map((p) => {
                    const h = hitung(p);
                    return {
                        id: p.id,
                        nama: p.nama,
                        kategori: p.kategori,
                        badge: p.badge,
                        tag: p.tag,
                        deskripsi: p.deskripsi,
                        komponen: p.komponen.map((i) => cari(i)?.nama).filter(Boolean),
                        tarif: { weekday: { j12: p.wk12, j24: p.wk24 }, weekend: { j12: p.wn12, j24: p.wn24 } },
                        stokTersedia: h.tersedia,
                        tersedia: h.tersedia > 0,
                    };
                });
            try {
                localStorage.setItem(KEY_UNIT, JSON.stringify(data));
                localStorage.setItem(KEY_PUBLIK, JSON.stringify({ versi: Date.now(), items }));
            } catch (e) {
                console.warn('Penyimpanan lokal gagal', e);
            }
        }

        /* ---------- Tabel: baris utama + panel rincian (accordion) ---------- */
        const tarif = (p, jam) => p[(weekend ? 'wn' : 'wk') + jam];
        const sp = (p, u) => {
            const isi = `<i class="bi ${ST[u.status][1]}"></i>${ST[u.status][0]}${u.catatan ? ` (${esc(u.catatan)})` : ''}`;
            return u.status === 'disewa'
                ? `<span class="sp sp-disewa">${isi}</span>`
                : `<button type="button" class="sp sp-${u.status}" data-aksi="toggle" data-id="${p.id}" data-kode="${esc(u.kode)}" title="Klik untuk ubah status">${isi}</button>`;
        };
        const tombol = (a, p, u, ikon, teks) =>
            `<button type="button" class="btn btn-sm btn-outline-secondary rounded-pill ms-1" data-aksi="${a}" data-id="${p.id}" data-kode="${esc(u.kode)}"><i class="bi ${ikon} me-1"></i>${teks}</button>`;
        const aksiUnit = (p, u) =>
            ({
                disewa: '<em class="text-muted small">Sedang digunakan</em>',
                tersedia: tombol('maint', p, u, 'bi-tools', 'Maintenance'),
                maintenance:
                    tombol('selesai', p, u, 'bi-check2-circle', 'Selesai servis') +
                    tombol('rusak', p, u, 'bi-x-octagon', 'Tandai rusak'),
                rusak: tombol('pulih', p, u, 'bi-arrow-counterclockwise', 'Pulihkan unit'),
            })[u.status];

        function rincian(p) {
            let kepala, isi;
            if (p.kategori === 'combo') {
                const k = p.komponen.map(cari).filter(Boolean);
                kepala = `<div><span class="fw-bold small"><i class="bi bi-circle-fill text-warning me-2 fz-50"></i>KOMPONEN PAKET</span><span class="text-muted small ms-2">${k.length} komponen</span></div>`;
                isi =
                    `<thead><tr><th>Komponen</th><th>Tipe</th><th>Stok komponen</th></tr></thead><tbody>` +
                    (k
                        .map(
                            (x) =>
                                `<tr><td class="fw-semibold">1 &times; ${esc(x.nama)}</td><td>${KAT[x.kategori]}</td><td>${n(x, 'tersedia')} / ${x.unit.length} tersedia</td></tr>`,
                        )
                        .join('') || '<tr><td colspan="3" class="text-muted">Belum ada komponen.</td></tr>') +
                    '</tbody>';
            } else {
                const u = p.unit.filter((x) => sf === 'all' || x.status === sf);
                kepala = `<div><span class="fw-bold small"><i class="bi bi-circle-fill text-primary me-2 fz-50"></i>DAFTAR UNIT FISIK &amp; SERIAL (${esc(p.nama)})</span><span class="text-muted small ms-2">Total ${p.unit.length} Perangkat</span></div>
        <button type="button" class="btn btn-sm btn-light text-primary fw-bold rounded-pill" data-aksi="serial" data-id="${p.id}"><i class="bi bi-plus-lg me-1"></i>Tambah Nomor Serial</button>`;
                isi =
                    `<thead><tr><th>ID Unit (Serial)</th><th>Status Perangkat</th><th class="text-end">Aksi</th></tr></thead><tbody>` +
                    (u
                        .map(
                            (x) => `<tr>
        <td class="fw-semibold small">${esc(x.kode)}</td><td>${sp(p, x)}</td><td class="text-end text-nowrap">${aksiUnit(p, x)}</td></tr>`,
                        )
                        .join('') ||
                        '<tr><td colspan="3" class="text-muted">Tidak ada unit dengan status ini.</td></tr>') +
                    '</tbody>';
            }
            return `<tr><td colspan="6" class="pt-0 pb-3 border-0"><div class="panel-unit"><div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">${kepala}</div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">${isi}</table></div></div></td></tr>`;
        }

        function baris(p) {
            const h = hitung(p),
                buka = sf !== 'all' || terbuka.has(p.id),
                [ik, wr] = IKON[p.kategori],
                lbl = weekend ? 'weekend' : 'weekday';
            const kls =
                p.override === 'Maintenance'
                    ? 'off'
                    : h.tersedia === 0
                        ? 'zero'
                        : h.tersedia / h.total <= 0.34
                            ? 'low'
                            : 'ok';
            return `<tr>
      <td><div class="d-flex align-items-center gap-2">
        <button type="button" class="btn-ex" data-aksi="expand" data-id="${p.id}" aria-expanded="${buka}" aria-label="Rincian unit ${esc(p.nama)}"><i class="bi bi-chevron-${buka ? 'down' : 'right'}"></i></button>
        <span class="ikon ik-${wr}"><i class="bi ${ik}"></i></span>
        <div><div class="fw-bold">${esc(p.nama)}</div><small class="text-muted">ID: ${esc(p.kode)}</small></div></div></td>
      <td class="kelengkapan">${esc(p.deskripsi)}</td>
      <td class="text-end"><div class="fw-bold">${rp(tarif(p, 12))}</div><small class="text-muted">/12j &middot; ${lbl}</small></td>
      <td class="text-end"><div class="fw-bold">${rp(tarif(p, 24))}</div><small class="text-muted">/24j &middot; ${lbl}</small></td>
      <td class="text-center"><span class="pill-stok ${kls}">${h.tersedia} / ${h.total}</span><div class="small text-muted mt-1">${p.override === 'Maintenance' ? 'dinonaktifkan' : `${h.tersedia} tersedia dari ${h.total} unit`}</div></td>
      <td class="text-end text-nowrap">
        <button class="btn btn-sm btn-link text-primary" data-aksi="edit" data-id="${p.id}" aria-label="Edit ${esc(p.nama)}"><i class="bi bi-pencil-square"></i></button>
        <button class="btn btn-sm btn-link text-danger" data-aksi="hapus" data-id="${p.id}" aria-label="Hapus ${esc(p.nama)}"><i class="bi bi-trash"></i></button></td></tr>${buka ? rincian(p) : ''}`;
        }

        function render() {
            const q = $('cariUnit').value.trim().toLowerCase(),
                f = fisik();
            $('statTotalUnit').textContent = data.length;
            $('statTersedia').textContent = f.reduce((a, p) => a + n(p, 'tersedia'), 0);
            $('statDisewa').textContent = f.reduce((a, p) => a + n(p, 'disewa'), 0);
            $('statPerbaikan').textContent = f.reduce((a, p) => a + n(p, 'maintenance') + n(p, 'rusak'), 0);

            $('tabStatusUnit').innerHTML = [
                ['all', 'Semua'],
                ['tersedia', 'Tersedia'],
                ['disewa', 'Disewa'],
                ['maintenance', 'Maintenance'],
                ['rusak', 'Rusak'],
            ]
                .map(([k, l]) => {
                    const c = f.reduce((a, p) => a + (k === 'all' ? p.unit.length : n(p, k)), 0);
                    return `<li><button type="button" class="stab ${k === sf ? 'active' : ''}" data-sf="${k}">${l} <span class="cnt cnt-${k}">${c}</span></button></li>`;
                })
                .join('');

            const hasil = data.filter((p) => {
                const cocok = [
                    p.kode,
                    p.nama,
                    p.deskripsi,
                    p.tag,
                    p.badge,
                    KAT[p.kategori],
                    ...p.unit.map((x) => x.kode),
                ]
                    .join(' ')
                    .toLowerCase()
                    .includes(q);
                return (
                    (kat === 'all' || p.kategori === kat) &&
                    cocok &&
                    (sf === 'all' || (p.kategori !== 'combo' && n(p, sf) > 0))
                );
            });
            const hal = (halaman = Math.min(halaman, Math.max(1, Math.ceil(hasil.length / PER)))),
                dari = (hal - 1) * PER,
                tampil = hasil.slice(dari, dari + PER);
            $('tbodyUnit').innerHTML =
                tampil.map(baris).join('') ||
                '<tr><td colspan="6" class="text-center text-muted py-4">Tidak ada unit yang cocok dengan filter.</td></tr>';
            $('infoHal').textContent = hasil.length
                ? `Menampilkan ${dari + 1} sampai ${dari + tampil.length} dari ${hasil.length} entri unit & paket`
                : '';
            const total = Math.ceil(hasil.length / PER),
                pg = (i, isi, off, on) =>
                    `<li class="page-item ${off ? 'disabled' : ''} ${on ? 'active' : ''}"><button type="button" class="page-link" data-hal="${i}">${isi}</button></li>`;
            $('paging').innerHTML =
                total > 1
                    ? pg(hal - 1, '<i class="bi bi-chevron-left"></i>', hal === 1) +
                    Array.from({ length: total }, (_, i) => pg(i + 1, i + 1, false, i + 1 === hal)).join('') +
                    pg(hal + 1, '<i class="bi bi-chevron-right"></i>', hal === total)
                    : '';
        }

        function ubahStatus(u, st) {
            if (st === 'maintenance' || st === 'rusak') {
                const c = prompt(`Catatan untuk ${u.kode} (opsional, contoh: Kabel HDMI)`, u.catatan || '');
                if (c === null) return;
                u.catatan = c.trim();
            } else u.catatan = '';
            u.status = st;
            sinkron();
            render();
        }

        $('tabStatusUnit').addEventListener('click', (e) => {
            const b = e.target.closest('[data-sf]');
            if (b) {
                sf = b.dataset.sf;
                halaman = 1;
                render();
            }
        });
        $('paging').addEventListener('click', (e) => {
            const b = e.target.closest('[data-hal]');
            if (b && !b.parentElement.classList.contains('disabled')) {
                halaman = +b.dataset.hal;
                render();
            }
        });

        $('tbodyUnit').addEventListener('click', (e) => {
            const b = e.target.closest('[data-aksi]');
            if (!b) return;
            const id = +b.dataset.id,
                p = cari(id),
                a = b.dataset.aksi;
            if (a === 'expand') {
                terbuka.has(id) ? terbuka.delete(id) : terbuka.add(id);
                return render();
            }
            if (a === 'toggle' || a in SET) {
                // quick switch per unit, langsung tersinkron
                const u = p.unit.find((x) => x.kode === b.dataset.kode);
                if (u && u.status !== 'disewa') ubahStatus(u, a === 'toggle' ? NEXT[u.status] : SET[a]);
                return;
            }
            if (a === 'serial') {
                const pakai = new Set(data.flatMap((x) => x.unit.map((y) => y.kode)));
                let i = p.unit.length + 1,
                    s;
                do {
                    s = `${p.kategori.toUpperCase()}-${String(i++).padStart(3, '0')}`;
                } while (pakai.has(s));
                const k = prompt('ID unit baru (nomor serial):', s);
                if (k === null) return;
                const kode = k.trim().toUpperCase().replace(/\s+/g, '');
                if (!/^[A-Z0-9-]+$/.test(kode)) return alert('ID hanya boleh huruf, angka, dan tanda minus.');
                if (pakai.has(kode)) return alert(`ID ${kode} sudah dipakai.`);
                p.unit.push({ kode, status: 'tersedia', catatan: '' });
                terbuka.add(id);
                sinkron();
                return render();
            }
            if (a === 'edit') return bukaForm(id);
            const sewa = hitung(p).disewa,
                dipakai = data.filter((x) => x.komponen.includes(id)).map((x) => x.nama);
            if (sewa)
                return alert(
                    `${p.nama} tidak bisa dihapus: ${sewa} unit sedang disewa. Nonaktifkan lewat Status Utama "Maintenance" sampai semua unit kembali.`,
                );
            if (dipakai.length)
                return alert(
                    `${p.nama} dipakai sebagai komponen di: ${dipakai.join(', ')}. Lepas dari paket tersebut dulu.`,
                );
            hapusId = id;
            bootstrap.Modal.getOrCreateInstance($('modalHapusUnit')).show();
        });
        $('btnConfirmHapus').addEventListener('click', () => {
            data = data.filter((p) => p.id !== hapusId);
            sinkron();
            render();
            bootstrap.Modal.getOrCreateInstance($('modalHapusUnit')).hide();
        });

        /* ---------- Modal: stok otomatis, unit fisik, komponen combo ---------- */
        $('uStokTotal').previousElementSibling.textContent = 'Stok Total (otomatis)';
        $('uStokTersedia').previousElementSibling.textContent = 'Stok Tersedia (otomatis)';
        ['uStokTotal', 'uStokTersedia'].forEach((id) => {
            const i = $(id);
            i.readOnly = true;
            i.tabIndex = -1;
            i.classList.add('bg-light');
            i.removeAttribute('min');
        });
        document
            .querySelector('#modalUnitForm .modal-body')
            .insertAdjacentHTML('afterbegin', '<div id="uError" class="alert alert-danger d-none small mb-3"></div>');
        document.querySelector('#modalUnitForm .modal-body .row').insertAdjacentHTML(
            'beforeend',
            `
    <div class="col-12" id="secFisik"><div class="p-3 bg-light rounded-3 border">
      <div class="fw-bold small text-muted mb-2">Kelola Unit Fisik (Serial Number) &middot; <span id="uJml">0</span> unit</div>
      <div id="listFisik" class="d-flex flex-column gap-2 mb-3"></div>
      <div class="input-group input-group-sm"><input type="text" class="form-control" id="uSerialBaru" placeholder="Contoh: PS3-006" aria-label="ID unit baru">
        <button type="button" class="btn btn-outline-primary" id="btnTambahFisik"><i class="bi bi-plus-lg me-1"></i>Tambah Unit Fisik</button></div>
      <small class="text-danger d-block" id="errSerial"></small>
      <small class="text-muted d-block mt-1">Stok mengikuti daftar ini. Status "Disewa" hanya berubah lewat proses sewa dan pengembalian.</small>
    </div></div>
    <div class="col-12 d-none" id="secKomponen"><div class="p-3 bg-light rounded-3 border">
      <div class="fw-bold small text-muted mb-2">Komponen Paket Combo</div>
      <div class="dropdown"><button type="button" class="btn btn-outline-secondary w-100 text-start dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside">Pilih aset yang digabungkan</button>
        <div class="dropdown-menu w-100 p-2" id="menuKomp"></div></div>
      <div id="chipKomp" class="d-flex flex-wrap gap-1 mt-2"></div>
      <small class="text-muted d-block mt-2">Stok combo = stok terendah di antara komponen. Pilih minimal 1 konsol dan 1 TV.</small>
    </div></div>`,
        );

        const semuaKode = () =>
            new Set(
                data
                    .filter((p) => p.id !== editId)
                    .flatMap((p) => p.unit.map((x) => x.kode))
                    .concat(kerja.map((x) => x.kode)),
            );
        function saranKode() {
            const pre = $('uKategori').value.toUpperCase(),
                pakai = semuaKode();
            let i = kerja.length + 1,
                k;
            do {
                k = `${pre}-${String(i++).padStart(3, '0')}`;
            } while (pakai.has(k));
            return k;
        }
        function segarkanStok() {
            const h = hitung(
                $('uKategori').value === 'combo'
                    ? { kategori: 'combo', komponen: komp, override: 'auto' }
                    : { kategori: 'x', unit: kerja, override: 'auto' },
            );
            $('uStokTotal').value = h.total;
            $('uStokTersedia').value = h.tersedia;
            $('uJml').textContent = kerja.length;
            if ($('uStatus').value !== 'Maintenance') $('uStatus').value = h.tersedia > 0 ? 'Tersedia' : 'Habis';
        }
        function renderFisik() {
            const combo = $('uKategori').value === 'combo';
            $('secFisik').classList.toggle('d-none', combo);
            $('secKomponen').classList.toggle('d-none', !combo);
            $('listFisik').innerHTML =
                kerja
                    .map((x, i) => {
                        const sewa = x.status === 'disewa';
                        const opsi = sewa
                            ? '<option>Sedang disewa</option>'
                            : ['tersedia', 'maintenance', 'rusak']
                                .map(
                                    (s) =>
                                        `<option value="${s}" ${s === x.status ? 'selected' : ''}>${ST[s][0]}</option>`,
                                )
                                .join('');
                        const perlu = x.status === 'maintenance' || x.status === 'rusak';
                        return `<div class="d-flex flex-wrap gap-2 align-items-center"><code class="flex-grow-1">${esc(x.kode)}</code>
        ${perlu ? `<input type="text" class="form-control form-control-sm w-auto" data-cat="${i}" placeholder="Catatan, mis. Kabel HDMI" aria-label="Catatan ${esc(x.kode)}" value="${esc(x.catatan || '')}">` : ''}
        <select class="form-select form-select-sm w-auto" data-i="${i}" aria-label="Status ${esc(x.kode)}" ${sewa ? 'disabled' : ''}>${opsi}</select>
        <button type="button" class="btn btn-sm btn-outline-danger" data-hapus="${i}" aria-label="Hapus ${esc(x.kode)}" ${sewa ? 'disabled title="Unit sedang disewa"' : ''}><i class="bi bi-trash"></i></button></div>`;
                    })
                    .join('') || '<small class="text-muted">Belum ada unit fisik.</small>';
            $('uSerialBaru').placeholder = 'Contoh: ' + saranKode();
            $('menuKomp').innerHTML = fisik()
                .filter((p) => p.id !== editId)
                .map(
                    (p) => `<label class="dropdown-item d-flex gap-2 align-items-center">
      <input type="checkbox" class="form-check-input mt-0" value="${p.id}" ${komp.includes(p.id) ? 'checked' : ''}> ${esc(p.nama)} <small class="text-muted ms-auto">${KAT[p.kategori]}</small></label>`,
                )
                .join('');
            $('chipKomp').innerHTML =
                komp
                    .map((i) => cari(i))
                    .filter(Boolean)
                    .map(
                        (p) =>
                            `<span class="badge bg-primary bg-opacity-10 text-primary border">1 &times; ${esc(p.nama)}</span>`,
                    )
                    .join('') || '<small class="text-muted">Belum ada komponen dipilih.</small>';
            segarkanStok();
        }
        $('listFisik').addEventListener('change', (e) => {
            const i = e.target.dataset.i;
            if (i !== undefined) {
                kerja[i].status = e.target.value;
                if (e.target.value === 'tersedia') kerja[i].catatan = '';
                renderFisik();
            }
        });
        $('listFisik').addEventListener('input', (e) => {
            const i = e.target.dataset.cat;
            if (i !== undefined) kerja[i].catatan = e.target.value;
        });
        $('listFisik').addEventListener('click', (e) => {
            const b = e.target.closest('[data-hapus]');
            if (b) {
                kerja.splice(+b.dataset.hapus, 1);
                renderFisik();
            }
        });
        $('menuKomp').addEventListener('change', (e) => {
            const id = +e.target.value;
            komp = e.target.checked ? [...komp, id] : komp.filter((x) => x !== id);
            renderFisik();
        });
        $('btnTambahFisik').addEventListener('click', () => {
            const k = ($('uSerialBaru').value.trim() || saranKode()).toUpperCase().replace(/\s+/g, '');
            const err = !/^[A-Z0-9-]+$/.test(k)
                ? 'ID hanya boleh huruf, angka, dan tanda minus.'
                : semuaKode().has(k)
                    ? `ID ${k} sudah dipakai.`
                    : '';
            $('errSerial').textContent = err;
            if (err) return;
            kerja.push({ kode: k, status: 'tersedia', catatan: '' });
            $('uSerialBaru').value = '';
            renderFisik();
        });
        $('uKategori').addEventListener('change', renderFisik);
        $('uStatus').addEventListener('change', segarkanStok);

        const mForm = () => bootstrap.Modal.getOrCreateInstance($('modalUnitForm'));
        function bukaForm(id) {
            editId = id;
            const p = cari(id);
            $('formUnit').reset();
            $('uError').classList.add('d-none');
            $('errSerial').textContent = '';
            $('unitIdIndex').value = id ?? '';
            $('modalUnitTitle').innerHTML =
                `<i class="bi bi-box-seam text-primary me-2"></i>${p ? 'Edit unit / paket' : 'Tambah unit / paket baru'}`;
            const v = p || {
                kode: '',
                nama: '',
                kategori: 'ps4',
                badge: 'KONSOL ONLY',
                tag: '',
                deskripsi: '',
                wk12: '',
                wk24: '',
                wn12: '',
                wn24: '',
                override: 'auto',
                komponen: [],
                unit: [],
            };
            Object.entries({
                uKode: v.kode,
                uNama: v.nama,
                uKategori: v.kategori,
                uBadge: v.badge,
                uTag: v.tag,
                uDeskripsi: v.deskripsi,
                uWk12: v.wk12,
                uWk24: v.wk24,
                uWn12: v.wn12,
                uWn24: v.wn24,
            }).forEach(([k, val]) => {
                $(k).value = val;
            });
            $('uStatus').value = v.override === 'Maintenance' ? 'Maintenance' : 'Tersedia';
            kerja = v.unit.map((x) => ({ ...x }));
            komp = [...v.komponen];
            renderFisik();
            mForm().show();
        }

        function validasi(f) {
            const e = [];
            if (data.some((p) => p.id !== editId && p.kode === f.kode))
                e.push(`Kode ${f.kode} sudah dipakai paket lain.`);
            [
                ['weekday', f.wk12, f.wk24],
                ['weekend', f.wn12, f.wn24],
            ].forEach(([nm, a, b]) => {
                if (!(a > 0 && b > 0)) e.push(`Tarif ${nm} harus lebih dari 0.`);
                else if (b < a) e.push(`Tarif ${nm} 24 jam tidak boleh lebih murah dari 12 jam.`);
            });
            if (f.kategori === 'combo') {
                const k = komp.map(cari).filter(Boolean);
                if (!k.some((x) => ['ps3', 'ps4', 'ps5'].includes(x.kategori)) || !k.some((x) => x.kategori === 'tv'))
                    e.push('Combo harus berisi minimal 1 konsol PS dan 1 TV LED.');
                if (editId && data.some((p) => p.komponen.includes(editId)))
                    e.push('Paket ini dipakai sebagai komponen combo lain, tidak bisa diubah menjadi combo.');
            } else if (!kerja.length) e.push('Tambahkan minimal 1 unit fisik.');
            return e;
        }

        $('formUnit').addEventListener('submit', (ev) => {
            ev.preventDefault();
            const f = {
                kode: $('uKode').value.trim().toUpperCase(),
                nama: $('uNama').value.trim(),
                kategori: $('uKategori').value,
                badge: $('uBadge').value,
                tag: $('uTag').value.trim(),
                deskripsi: $('uDeskripsi').value.trim(),
                wk12: +$('uWk12').value,
                wk24: +$('uWk24').value,
                wn12: +$('uWn12').value,
                wn24: +$('uWn24').value,
                override: $('uStatus').value === 'Maintenance' ? 'Maintenance' : 'auto',
            };
            const err = validasi(f);
            if (err.length) {
                $('uError').innerHTML = err.map(esc).join('<br>');
                $('uError').classList.remove('d-none');
                $('uError').scrollIntoView({ block: 'nearest' });
                return;
            }
            const combo = f.kategori === 'combo',
                ekstra = { unit: combo ? [] : kerja, komponen: combo ? komp : [] };
            if (editId) Object.assign(cari(editId), f, ekstra);
            else data.push({ id: Math.max(0, ...data.map((x) => x.id)) + 1, ...f, ...ekstra });
            sinkron();
            render();
            mForm().hide();
        });

        /* ---------- Filter kategori, pencarian, init ---------- */
        $('filterKategori').addEventListener('click', (e) => {
            const b = e.target.closest('.btn-filter');
            if (!b) return;
            kat = b.dataset.kat;
            halaman = 1;
            document.querySelectorAll('.btn-filter').forEach((x) => x.classList.toggle('active', x === b));
            render();
        });
        $('cariUnit').addEventListener('input', () => {
            halaman = 1;
            render();
        });
        $('checkWeekend').addEventListener('change', (e) => {
            weekend = e.target.checked;
            render();
        });
        $('btnTambahUnit').addEventListener('click', () => bukaForm(null));

        sinkron();
        render();
    }
    /* ---------- 10. Penjalan & fungsi global ---------- */
    function jalankan() {
        // Tiap modul punya guard sendiri (hanya jalan bila elemen halamannya ada),
        // dan error di satu modul tidak menghentikan modul lain.
        [
            initSidebar,
            initBeranda,
            initFormSewa,
            initPembayaran,
            initPesanan,
            initRiwayat,
            initPenyewaan,
            initUnitPS,
        ].forEach(
            (fn) => {
                try {
                    fn();
                } catch (err) {
                    console.error(`[PS Rental] ${fn.name} gagal dijalankan:`, err);
                }
            },
        );
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', jalankan);
    else jalankan();

    // Fungsi yang dipanggil lewat atribut onclick="..." / oninput="..." di HTML harus ada di scope global
    Object.assign(window, {
        ubahKategori,
        geserTarif,
        mulaiEdit,
        batalEdit,
        simpanEdit,
        filterTab,
        handleAdminScan,
        tambahWaktuSewa,
        simulasiTiba,
        searchOrders,
    });
})();
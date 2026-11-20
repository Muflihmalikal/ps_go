(function () {
    'use strict';

    const form = document.getElementById('formSewa');
    if (!form) return;

    if (form.dataset.siap === '1') return;
    form.dataset.siap = '1';

    const $ = (id) => document.getElementById(id);
    const p2 = (n) => String(n).padStart(2, '0');
    const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    const rupiah = (n) => (Number.isFinite(Number(n)) ? 'Rp ' + Number(n).toLocaleString('id-ID') : '-');
    const tglLengkap = (d) =>
        `${p2(d.getDate())} ${BULAN[d.getMonth()]} ${d.getFullYear()}, ${p2(d.getHours())}:${p2(d.getMinutes())} WIB`;

    const HARGA = {
        weekday: { j12: Number(form.dataset.wk12), j24: Number(form.dataset.wk24) },
        weekend: { j12: Number(form.dataset.wn12), j24: Number(form.dataset.wn24) },
    };
    const MAKS_JAM = Number(form.dataset.maksJam);
    const MODE = {
        harian: {
            satuan: 'Hari',
            langkah: 1,
            maks: Math.floor(MAKS_JAM / 24),
            info: `1 hari = 24 jam (maksimal ${Math.floor(MAKS_JAM / 24)} hari)`,
        },
        jam: {
            satuan: 'Jam',
            langkah: 12,
            maks: MAKS_JAM,
            info: `Kelipatan 12 jam: 12, 24, 36, ... (maksimal ${MAKS_JAM} jam)`,
        },
    };

    const tipe = () => form.querySelector('input[name="tipe_sewa"]:checked').value;

    function parseMulai(nilai) {
        const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(nilai || '');
        return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) : null;
    }

    function jenisTarif(mulai) {
        if (!mulai) return form.dataset.tarifAwal === 'weekend' ? 'weekend' : 'weekday';
        const hari = mulai.getDay();
        return hari === 0 || hari >= 5 ? 'weekend' : 'weekday';
    }

    function durasiRapi() {
        const { langkah, maks } = MODE[tipe()];
        let n = parseInt($('durasi').value, 10);
        if (!Number.isFinite(n) || n < langkah) n = langkah;
        n = Math.ceil(n / langkah) * langkah;
        return Math.min(n, maks);
    }

    function hitungBiaya(mode, jumlah, t) {
        if (mode === 'harian') return t.j24 * jumlah;
        const blok = jumlah / 12;
        return Math.floor(blok / 2) * t.j24 + (blok % 2) * t.j12;
    }

    function render() {
        const mode = tipe();
        const cfg = MODE[mode];
        const jumlah = durasiRapi();
        const mulai = parseMulai($('mulai').value);
        const jenis = jenisTarif(mulai);
        const t = HARGA[jenis];
        const teksHarga = `${rupiah(t.j12)} / 12 jam • ${rupiah(t.j24)} / 24 jam`;

        $('satuanDurasi').textContent = cfg.satuan;
        $('infoDurasi').textContent = cfg.info;
        $('unitHarga').textContent = teksHarga;
        $('sumHarga').textContent = teksHarga;
        $('sumTarif').textContent = jenis === 'weekend' ? 'Weekend (Jum-Min)' : 'Weekday (Sen-Kam)';
        $('sumDurasi').textContent = `${jumlah} ${cfg.satuan}`;

        if (mulai) {
            const jamTotal = mode === 'harian' ? jumlah * 24 : jumlah;
            const kembali = new Date(mulai);
            kembali.setHours(kembali.getHours() + jamTotal);
            $('sumMulai').textContent = tglLengkap(mulai);
            $('sumKembali').textContent = tglLengkap(kembali);
        } else {
            $('sumMulai').textContent = 'Pilih tanggal & jam';
            $('sumKembali').textContent = '-';
        }
        $('sumTotal').textContent = rupiah(hitungBiaya(mode, jumlah, t));
    }

    function geserDurasi(arah) {
        const { langkah, maks } = MODE[tipe()];
        const baru = durasiRapi() + arah * langkah;
        $('durasi').value = Math.min(Math.max(baru, langkah), maks);
        render();
    }

    document.querySelectorAll('input[name="tipe_sewa"]').forEach((el) =>
        el.addEventListener('change', () => {
            $('durasi').value = MODE[tipe()].langkah;
            render();
        }),
    );
    $('mulai').addEventListener('input', render);
    $('mulai').addEventListener('change', render);
    $('btnKurang').addEventListener('click', () => geserDurasi(-1));
    $('btnTambah').addEventListener('click', () => geserDurasi(1));
    $('durasi').addEventListener('input', (e) => {
        e.target.value = e.target.value.replace(/\D/g, '');
        render();
    });
    $('durasi').addEventListener('blur', () => {
        $('durasi').value = durasiRapi();
        render();
    });

    const tombol = $('btnSubmit');
    const teksTombol = tombol.textContent;
    const tombolSiapAwal = !tombol.disabled;
    form.addEventListener('submit', () => {
        $('durasi').value = durasiRapi();
        tombol.disabled = true;
        tombol.textContent = 'Memproses...';
    });
    window.addEventListener('pageshow', () => {
        tombol.disabled = !tombolSiapAwal;
        tombol.textContent = teksTombol;
    });

    $('durasi').value = durasiRapi();
    render();
})();
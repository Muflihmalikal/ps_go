// form_sewa.html - unit dari index (?unit=), tarif weekday/weekend, durasi, metode penerimaan, upload jaminan
// Bergantung pada tarif.js (UNIT, rupiah, jenisTarifUntuk, hitungHarga)

const $ = (id) => document.getElementById(id);

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
    $(awalan + 'Gambar').src = '../' + unit.gambar;
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
        $('durasi').value = MODE[tipeSewa()].langkah; // 1 hari / 12 jam
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
    if (!/^(\+62|62|0)8\d{7,12}$/.test($('hpPelanggan').value.replace(/[\s-]/g, ''))) e.push('Nomor HP yang valid (contoh 08123456789)');
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
    const salah = validasiForm(), box = $('errForm');
    box.classList.toggle('d-none', !salah.length);
    if (salah.length) { box.innerHTML = '<b>Lengkapi dulu:</b><br>' + salah.join('<br>'); return; }

    const tipe = tipeSewa(), jumlah = jumlahDurasi(), antar = antarKeRumah();
    const sewa = hitungHarga(unitId, jenisTarif, tipe, jumlah), ongkir = antar ? ONGKIR : 0;
    sessionStorage.setItem('psr_draft_pesanan', JSON.stringify({
        unitId, unitNama: unit.nama, gambar: unit.gambar, tipe, jumlah, satuan: MODE[tipe].satuan, jenisTarif,
        mulai: $('mulaiSewa').value, metode: antar ? 'antar' : 'ambil',
        nama: $('namaPelanggan').value.trim(), hp: $('hpPelanggan').value.trim(),
        alamat: antar ? $('alamatAntar').value.trim() : '', kecamatan: antar ? $('kecamatan').value.trim() : '',
        patokan: antar ? $('patokan').value.trim() : '',
        jaminan: antar ? document.querySelector('input[name="jaminan"]:checked').value : null,
        fileJaminan: antar ? $('fileJaminan').files[0].name : null,
        sewa, ongkir, total: sewa + ongkir,
    }));
    location.href = 'pembayaran.html';
});

perbaruiMetode();
perbaruiRingkasan();
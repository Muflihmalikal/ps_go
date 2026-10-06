// Data tarif bersama (dipakai index.html dan form_sewa.html)
// Harga per unit: j12 = sewa 12 jam, j24 = sewa 24 jam.

const tarif = (j12, j24) => ({ j12, j24 });

const UNIT = {
    ps4: {
        nama: 'PlayStation 4 Slim / Pro',
        gambar: 'assets/img/ps4.png',
        tarif: {
            weekday: tarif(40000, 70000),
            weekend: tarif(40000, 70000),
        },
    },
    ps3: {
        nama: 'PlayStation 3 Super Slim',
        gambar: 'assets/img/ps3.png',
        tarif: {
            weekday: tarif(30000, 50000),
            weekend: tarif(30000, 50000),
        },
    },
    'playbox-ps4': {
        nama: 'Paket Playbox PS4 + TV LED 43"',
        gambar: 'assets/img/paket-ps4.png',
        tarif: {
            weekday: tarif(70000, 100000),
            weekend: tarif(70000, 100000),
        },
    },
    'playbox-ps3': {
        nama: 'Paket Playbox PS3 + TV LED',
        gambar: 'assets/img/paket-ps3.png',
        tarif: {
            weekday: tarif(50000, 70000),
            weekend: tarif(50000, 70000),
        },
    },
    'tv-43': {
        nama: 'Smart TV LED 43 Inch',
        gambar: 'assets/img/tv-43.png',
        tarif: {
            weekday: tarif(30000, 40000),
            weekend: tarif(30000, 40000),
        },
    },
    'tv-32': {
        nama: 'TV LED 32 / 40 Inch HD',
        gambar: 'assets/img/tv-32.png',
        tarif: {
            weekday: tarif(20000, 30000),
            weekend: tarif(20000, 30000),
        },
    },
};

// Ongkir tetap untuk antar ke rumah.
// TODO: ganti dengan tarif ongkir yang sebenarnya (sementara mengikuti data contoh operator).
const ONGKIR = 10000;

const rupiah = (n) => 'Rp ' + n.toLocaleString('id-ID');

// Senin-Kamis = weekday, Jumat-Minggu = weekend
function jenisTarifUntuk(tanggal) {
    const hari = tanggal.getDay(); // 0 = Minggu
    return hari === 0 || hari >= 5 ? 'weekend' : 'weekday';
}

// mode 'harian': jumlah = hari (per 24 jam)
// mode 'jam'   : jumlah = jam, kelipatan 12 (tiap 24 jam dihitung harga 24 jam, sisanya harga 12 jam)
function hitungHarga(unitId, jenis, mode, jumlah) {
    const t = UNIT[unitId].tarif[jenis];
    if (mode === 'harian') return t.j24 * jumlah;
    const blok = jumlah / 12;
    return Math.floor(blok / 2) * t.j24 + (blok % 2) * t.j12;
}
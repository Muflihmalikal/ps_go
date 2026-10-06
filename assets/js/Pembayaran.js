// pembayaran.html - pilih metode (transfer / QRIS / COD), kirim bukti, simpan pesanan
// Bergantung pada tarif.js (rupiah). Data pesanan datang dari form_sewa.html lewat sessionStorage.

// TODO: isi data rekening toko yang sebenarnya (boleh lebih dari satu bank)
const REKENING = [
    { bank: '[Nama Bank]', nomor: '[isi nomor rekening]', atasNama: '[isi nama pemilik rekening]' },
];
const KEY_DRAFT = 'psr_draft_pesanan';
const KEY_PESANAN = 'psr_pesanan';
const MAKS_BUKTI = 5 * 1024 * 1024;

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const baca = (store, key, awal) => { try { return JSON.parse(store.getItem(key)) ?? awal; } catch { return awal; } };
const p2 = (n) => String(n).padStart(2, '0');
const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const tgl = (d) => `${p2(d.getDate())} ${BULAN[d.getMonth()]} ${d.getFullYear()}, ${p2(d.getHours())}:${p2(d.getMinutes())} WIB`;

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
    const d = draft, antar = d.metode === 'antar';
    $('rGambar').src = '../' + d.gambar;
    $('rUnit').textContent = d.unitNama;
    $('rTarif').textContent = d.jenisTarif === 'weekend' ? 'Tarif Weekend' : 'Tarif Weekday';
    $('rDurasi').textContent = `${d.jumlah} ${d.satuan}`;
    $('rMulai').textContent = tgl(new Date(d.mulai));
    $('rPenerimaan').textContent = antar ? 'Antar ke Rumah' : 'Ambil di Toko';
    $('rJaminan').textContent = antar ? d.jaminan.toUpperCase() : 'Dibawa saat ambil unit';
    $('rSewa').textContent = rupiah(d.sewa);
    $('rOngkirBaris').classList.toggle('d-none', !antar);
    $('rOngkir').textContent = rupiah(d.ongkir);
    document.querySelectorAll('.nominal-bayar').forEach((el) => { el.textContent = rupiah(d.total); });
    $('linkKembali').addEventListener('click', (e) => { if (history.length > 1) { e.preventDefault(); history.back(); } });
}

/* ---------- Pilihan metode: COD hanya untuk ambil di toko ---------- */
function renderMetode() {
    const daftar = [
        { id: 'transfer', ikon: 'bi-bank', judul: 'Transfer Bank', sub: 'Kirim bukti transfer' },
        { id: 'qris', ikon: 'bi-qr-code', judul: 'QRIS', sub: 'Scan lalu kirim bukti' },
    ];
    if (draft.metode === 'ambil') daftar.push({ id: 'cod', ikon: 'bi-cash-coin', judul: 'COD', sub: 'Bayar tunai di toko' });
    $('infoCod').classList.toggle('d-none', draft.metode === 'ambil');
    $('daftarMetode').innerHTML = daftar.map((m) => `
    <div class="${daftar.length === 3 ? 'col-4' : 'col-6'}">
      <label class="radio-card w-100">
        <input type="radio" name="metodeBayar" value="${m.id}" ${m.id === metode ? 'checked' : ''} />
        <div class="card p-3 text-center border-2">
          <i class="bi ${m.ikon} fs-4 mb-2"></i>
          <span class="fw-semibold d-block">${m.judul}</span>
          <small class="text-muted">${m.sub}</small>
        </div>
      </label>
    </div>`).join('');
    document.querySelectorAll('input[name="metodeBayar"]').forEach((el) => {
        el.addEventListener('change', () => { metode = el.value; aturPanel(); });
    });
}

function renderRekening() {
    $('listRekening').innerHTML = REKENING.map((r) => `
    <div class="d-flex justify-content-between align-items-center p-3 border rounded-3 bg-light">
      <div>
        <small class="text-muted d-block">${esc(r.bank)} · a.n. ${esc(r.atasNama)}</small>
        <span class="fw-bold fs-5">${esc(r.nomor)}</span>
      </div>
      <button type="button" class="btn btn-sm btn-outline-primary rounded-pill" data-salin="${esc(r.nomor.replace(/\s/g, ''))}"><i class="bi bi-clipboard me-1"></i>Salin</button>
    </div>`).join('');
}

function aturPanel() {
    $('panelTransfer').classList.toggle('d-none', metode !== 'transfer');
    $('panelQris').classList.toggle('d-none', metode !== 'qris');
    $('panelCod').classList.toggle('d-none', metode !== 'cod');
    $('formBukti').classList.toggle('d-none', metode === 'cod');
    $('errBayar').classList.add('d-none');
    $('btnKirim').innerHTML = metode === 'cod'
        ? '<i class="bi bi-check2-circle me-1"></i> Konfirmasi Pesanan (Bayar di Tempat)'
        : '<i class="bi bi-send-check me-1"></i> Kirim Bukti & Selesaikan Pesanan';
}

/* ---------- Salin ---------- */
document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-salin]');
    if (!b) return;
    const teks = b.dataset.salin === 'nominal' ? String(draft.total) : b.dataset.salin;
    try { await navigator.clipboard.writeText(teks); }
    catch { const t = document.createElement('textarea'); t.value = teks; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); }
    const awal = b.innerHTML;
    b.innerHTML = '<i class="bi bi-check2 me-1"></i>Tersalin';
    setTimeout(() => { b.innerHTML = awal; }, 1500);
});

/* ---------- QRIS & upload bukti ---------- */
const imgQris = $('imgQris');
const qrisGagal = () => { imgQris.classList.add('d-none'); $('qrisKosong').classList.remove('d-none'); };
imgQris.addEventListener('error', qrisGagal);
if (imgQris.complete && imgQris.naturalWidth === 0) qrisGagal();

$('fileBukti').addEventListener('change', (e) => {
    const f = e.target.files[0], prev = $('previewBukti');
    if (!f) { prev.classList.add('d-none'); $('namaFileBukti').textContent = 'Klik untuk pilih bukti transfer / screenshot QRIS'; return; }
    $('namaFileBukti').textContent = f.name;
    if (f.type.startsWith('image/')) { prev.src = URL.createObjectURL(f); prev.classList.remove('d-none'); }
    else prev.classList.add('d-none');
});

/* ---------- Kirim ---------- */
function validasiBayar() {
    if (metode === 'cod') return [];
    const e = [], f = $('fileBukti').files[0];
    if (!$('namaPengirim').value.trim()) e.push('Nama pemilik rekening / akun pengirim');
    if (!f) e.push('Bukti pembayaran (foto atau PDF)');
    else if (!(f.type.startsWith('image/') || f.type === 'application/pdf')) e.push('Bukti harus berupa gambar atau PDF');
    else if (f.size > MAKS_BUKTI) e.push('Ukuran bukti maksimal 5 MB');
    return e;
}

$('btnKirim').addEventListener('click', () => {
    const salah = validasiBayar(), box = $('errBayar');
    box.classList.toggle('d-none', !salah.length);
    if (salah.length) { box.innerHTML = '<b>Lengkapi dulu:</b><br>' + salah.map(esc).join('<br>'); return; }

    const now = new Date(), f = $('fileBukti').files[0];
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
    try { localStorage.setItem(KEY_PESANAN, JSON.stringify(semua)); } catch { }
    sessionStorage.removeItem(KEY_DRAFT);
    history.replaceState(null, '', 'pembayaran.html?pesanan=' + encodeURIComponent(order.id));
    tampilSukses(order);
});

/* ---------- Halaman berhasil ---------- */
function tampilSukses(o) {
    const antar = o.metode === 'antar', cod = o.metodeBayar === 'cod';
    $('sJudul').textContent = cod ? 'Pesanan dikonfirmasi' : 'Bukti pembayaran terkirim';
    $('sSub').textContent = cod ? 'Bayar tunai di toko saat mengambil unit.' : 'Menunggu verifikasi dari operator.';
    $('sId').textContent = o.id;
    if (window.JsBarcode) {
        try { JsBarcode('#barcodePesanan', o.id.replace('#', ''), { format: 'CODE128', height: 50, displayValue: false }); } catch { }
    }
    const langkah = [
        [true, cod ? 'Pesanan dikonfirmasi' : 'Bukti pembayaran terkirim', cod ? `Siapkan uang pas ${rupiah(o.total)} untuk dibayar di toko.` : `${rupiah(o.total)} via ${NAMA_METODE[o.metodeBayar]}.`],
        [false, 'Operator memverifikasi pembayaran dan jaminan', 'Dilakukan manual oleh operator. Unit baru bisa diambil atau diantar setelah terverifikasi.'],
        [false, antar ? 'Unit diantar ke alamat kamu' : 'Ambil unit di toko', antar ? 'Siapkan jaminan asli untuk diserahkan ke kurir. Status pengantaran bisa dipantau di Pesanan Saya.' : 'Tunjukkan kode pesanan dan bawa jaminan asli (KTP / STNK).'],
        [false, 'Kembalikan unit tepat waktu', 'Keterlambatan dikenakan denda Rp 10.000 per jam (toleransi 10 menit).'],
    ];
    $('sLangkah').innerHTML = langkah.map(([selesai, judul, ket], i) => `
    <div class="timeline-line position-relative${i === langkah.length - 1 ? ' timeline-line-akhir' : ''}">
      <span class="timeline-dot position-absolute rounded-circle ${selesai ? 'bg-success' : 'bg-secondary-subtle border border-secondary'}"></span>
      <div class="fw-semibold">${esc(judul)}</div>
      <small class="text-muted">${esc(ket)}</small>
    </div>`).join('');
    tampil('viewSukses');
}

init();
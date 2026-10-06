// penyewaan.html - halaman operator: verifikasi, scan serah/terima, status delivery, sewa di tempat
const TARIF_DENDA = 10000, TOLERANSI = 10; // denda hanya untuk pickup/delivery
const KEY_UNIT = 'psr_katalog_admin_v3';   // sama dengan unit.js

const $ = id => document.getElementById(id);
const p2 = n => String(n).padStart(2, '0');
const hm = d => p2(d.getHours()) + ':' + p2(d.getMinutes());
const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const tglLengkap = d => `${p2(d.getDate())} ${BULAN[d.getMonth()]} ${d.getFullYear()}, ${hm(d)} WIB`;
const rp = n => 'Rp ' + n.toLocaleString('id-ID');
const angka = s => parseInt(String(s || '').replace(/\D/g, ''), 10) || 0;
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const norm = s => String(s || '').trim().toUpperCase().replace(/^#/, '');
const detikDurasi = t => { const m = /(\d+)\s*(hari|jam)/i.exec(t || ''); return m ? +m[1] * (m[2].toLowerCase() === 'hari' ? 86400 : 3600) : 0; };
const modal = id => bootstrap.Modal.getOrCreateInstance($(id));
const toast = msg => { $('toastMsg').textContent = msg; bootstrap.Toast.getOrCreateInstance($('toastApp')).show(); };

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
    ditolak: ['Ditolak', 'bg-dark']
};
// filter status -> kumpulan state
const GRUP = {
    menunggu: ['menunggu'], proses: ['siap', 'diantar', 'sampai'], aktif: ['aktif', 'habis', 'terlambat'],
    lewat: ['habis', 'terlambat'], selesai: ['selesai'], ditolak: ['ditolak']
};
const CEK = {
    serah: ['Kelengkapan unit sesuai (stik, kabel, aksesori)', 'Unit menyala dan berfungsi normal', 'Jaminan asli diterima dan sesuai identitas pelanggan'],
    terima: ['Kelengkapan unit lengkap (stik, kabel, aksesori)', 'Jaminan asli sudah dikembalikan ke pelanggan']
};
const KONDISI = { baik: 'kondisi baik', maint: 'perlu maintenance', rusak: 'rusak' };

// ---------- Sinkron status unit ke halaman Unit PS (localStorage milik unit.js) ----------
function statusUnitTersimpan() {
    const m = {};
    try { JSON.parse(localStorage.getItem(KEY_UNIT)).forEach(p => p.unit.forEach(u => { m[u.kode] = u.status; })); } catch { }
    return m;
}
function setStatusUnit(serial, status, catatan = '') {
    if (!serial) return;
    try {
        const d = JSON.parse(localStorage.getItem(KEY_UNIT));
        if (!Array.isArray(d)) return;
        d.forEach(p => p.unit.forEach(u => { if (u.kode === serial) { u.status = status; u.catatan = status === 'tersedia' ? '' : catatan; } }));
        localStorage.setItem(KEY_UNIT, JSON.stringify(d));
    } catch { }
}

function initApp() {
    const semua = () => [...document.querySelectorAll('tr[data-id]')];
    let cur = null, scanTr = null, scanMode = '', stream = null, camLoop = null;

    const hitungDenda = s => {
        const m = Math.max(0, Math.ceil(-s / 60));
        if (m <= TOLERANSI) return { m, j: 0, t: 0 };
        const j = Math.ceil(m / 60);
        return { m, j, t: j * TARIF_DENDA };
    };
    const catat = (tr, teks, foto) => { (tr._log = tr._log || []).push({ teks, waktu: tglLengkap(new Date()), foto }); };

    /* ---------- Filter, statistik, total pembayaran ---------- */
    function apply() {
        const fEl = $('fStatus'), lEl = $('fLayanan'), jEl = $('fJenis'), qEl = $('fCari');
        if (!fEl || !lEl || !jEl || !qEl) return;
        const f = fEl.value, l = lEl.value, j = jEl.value, q = qEl.value.trim().toLowerCase();
        const rows = semua();
        let n = 0;
        rows.forEach(r => {
            const s = r.dataset.state || '';
            const ok = (f === 'all' || (GRUP[f] || []).includes(s))
                && (l === 'all' || l === r.dataset.layanan) && (j === 'all' || j === r.dataset.jenis)
                && ((r.dataset.id || '') + ' ' + (r.dataset.nama || '')).toLowerCase().includes(q);
            r.classList.toggle('d-none', !ok);
            if (ok) n++;
        });
        $('kosong').classList.toggle('d-none', n > 0);
        const c = ks => rows.filter(r => ks.includes(r.dataset.state)).length;
        $('sMenunggu').textContent = c(GRUP.menunggu);
        $('sKeluar').textContent = c(GRUP.aktif);
        $('sTelat').textContent = c(GRUP.lewat);

        // Total pembayaran sewa: terverifikasi/lunas vs. masih menunggu
        let total = 0, tunda = 0;
        rows.forEach(r => {
            const s = r.dataset.state;
            if (s === 'ditolak') return;
            const v = angka(r.dataset.total || r.dataset.sewa);
            if (s === 'menunggu' || r.dataset.statusBayar === 'Belum Bayar') tunda += v; else total += v;
        });
        $('sTotalBayar').textContent = rp(total);
        $('sTotalTunda').textContent = tunda ? `Belum terverifikasi/lunas: ${rp(tunda)}` : 'Semua pembayaran terverifikasi';
    }

    /* ---------- Status baris & tombol aksi ---------- */
    const btn = (cls, ikon, teks, aksi) => `<button type="button" class="btn btn-sm ${cls}" data-aksi="${aksi}"><i class="bi ${ikon} me-1"></i>${teks}</button>`;
    function renderAksi(tr) {
        const s = tr.dataset.state, l = tr.dataset.layanan;
        let x = '';
        if (s === 'menunggu') x = btn('btn-success', 'bi-patch-check', 'Verifikasi', 'verifikasi') + btn('btn-outline-danger', 'bi-x-lg', 'Tolak', 'tolak');
        else if (s === 'siap') x = l === 'delivery' ? btn('btn-primary', 'bi-truck', 'Berangkat Antar', 'berangkat') : btn('btn-primary', 'bi-upc-scan', 'Serah Unit', 'serah');
        else if (s === 'diantar') x = btn('btn-primary', 'bi-geo-alt-fill', 'Sudah Sampai', 'sampai');
        else if (s === 'sampai') x = btn('btn-primary', 'bi-upc-scan', 'Serah Unit', 'serah');
        else if (GRUP.aktif.includes(s)) x = l === 'ditempat' ? btn('btn-primary', 'bi-box-arrow-in-down-left', 'Check-Out', 'checkout') : btn('btn-primary', 'bi-upc-scan', 'Terima Unit', 'terima');
        const detail = '<button type="button" class="btn btn-sm btn-outline-primary rounded-pill btn-detail" data-bs-toggle="modal" data-bs-target="#modalDetail"><i class="bi bi-eye me-1"></i> Detail</button>';
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
        semua().forEach(r => {
            if (r._s === undefined || r.dataset.done || !GRUP.aktif.includes(r.dataset.state)) return;
            r._s--;
            const a = Math.abs(r._s), late = r._s < 0;
            const s = !late ? 'aktif' : (r.dataset.layanan === 'ditempat' ? 'habis' : 'terlambat');
            const el = r.querySelector('.sisa');
            if (el) {
                el.textContent = (late ? '+' : '') + p2(Math.floor(a / 3600)) + ':' + p2(Math.floor((a % 3600) / 60)) + ':' + p2(a % 60);
                el.className = 'sisa fw-bold' + (late ? ' text-danger' : '');
            }
            if (r.dataset.state !== s) setState(r, s);
        });
    }

    function mulaiSewa(tr) { // unit diserahkan -> hitung mundur dimulai
        const now = new Date(), dtk = detikDurasi(tr.dataset.durasi);
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
    ['vBayar', 'vJamin'].forEach(id => $(id).addEventListener('change', () => { $('vConfirm').disabled = !($('vBayar').checked && $('vJamin').checked); }));
    $('vConfirm').onclick = () => {
        catat(cur, 'Pembayaran dan jaminan diverifikasi operator');
        setState(cur, 'siap');
        modal('modalVerifikasi').hide();
        toast(`${cur.dataset.id} terverifikasi. ${cur.dataset.layanan === 'delivery' ? 'Siap diantar.' : 'Siap diambil pelanggan.'}`);
    };

    /* ---------- Scan barcode: serah & terima unit ---------- */
    function bukaScan(tr, mode) {
        scanTr = tr; scanMode = mode;
        const serah = mode === 'serah', d = !serah && tr._s < 0 ? hitungDenda(tr._s) : null;
        $('scanJudul').textContent = `${serah ? 'Serah' : 'Terima'} Unit ${tr.dataset.id}`;
        $('scanSub').textContent = `${tr.dataset.nama} · ${tr.dataset.unit} · ${tr.dataset.layanan === 'delivery' ? 'Delivery' : 'Pickup'}`;
        $('scanHarapPesanan').textContent = tr.dataset.id;
        $('scanHarapUnit').textContent = tr.dataset.serial || '(serial belum diisi)';
        ['scanPesanan', 'scanUnit', 'scanCatatan'].forEach(i => { $(i).value = ''; });
        $('scanFoto').value = ''; $('scanPreview').classList.add('d-none');
        $('scanKondisi').value = 'baik';
        $('scanKondisiWrap').classList.toggle('d-none', serah);
        $('scanDenda').classList.toggle('d-none', !(d && d.t));
        if (d && d.t) $('scanDendaInfo').textContent = `Terlambat ${d.m} menit → denda ${rp(d.t)}`;
        const daftar = CEK[mode].concat(d && d.t ? [`Denda keterlambatan ${rp(d.t)} sudah dibayar pelanggan`] : []);
        $('scanCek').innerHTML = daftar.map((t, i) => `<div class="form-check"><input class="form-check-input cek-validasi" type="checkbox" id="cek${i}"><label class="form-check-label small" for="cek${i}">${esc(t)}</label></div>`).join('');
        cekScan();
        modal('modalScan').show();
    }
    function tandai(id, ok) {
        const el = $(id);
        el.classList.toggle('is-valid', !!el.value && ok);
        el.classList.toggle('is-invalid', !!el.value && !ok);
    }
    function cekScan() {
        const t = scanTr; if (!t) return;
        const okP = norm($('scanPesanan').value) === norm(t.dataset.id);
        const okU = !!t.dataset.serial && norm($('scanUnit').value) === norm(t.dataset.serial);
        tandai('scanPesanan', okP); tandai('scanUnit', okU);
        const cek = [...document.querySelectorAll('#modalScan .cek-validasi')].every(c => c.checked);
        const catatanOk = scanMode === 'serah' || $('scanKondisi').value === 'baik' || $('scanCatatan').value.trim();
        $('scanConfirm').disabled = !(okP && okU && cek && catatanOk && $('scanFoto').files.length);
    }
    $('modalScan').addEventListener('input', cekScan);
    $('modalScan').addEventListener('change', e => {
        if (e.target.id === 'scanFoto') {
            const f = e.target.files[0];
            if (f) { $('scanPreview').src = URL.createObjectURL(f); $('scanPreview').classList.remove('d-none'); }
        }
        cekScan();
    });
    ['scanPesanan', 'scanUnit'].forEach((id, i) => $(id).addEventListener('keydown', e => { // alat scan biasanya mengirim Enter
        if (e.key === 'Enter') { e.preventDefault(); if (i === 0) $('scanUnit').focus(); }
    }));
    $('modalScan').addEventListener('shown.bs.modal', () => $('scanPesanan').focus());
    $('modalScan').addEventListener('hidden.bs.modal', stopKamera);

    async function mulaiKamera(target) {
        if (!('BarcodeDetector' in window)) return toast('Browser ini belum mendukung scan kamera. Pakai alat scan barcode atau ketik kodenya.');
        try {
            stopKamera();
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            const v = $('scanVideo'); v.srcObject = stream; v.classList.remove('d-none'); await v.play();
            const det = new BarcodeDetector();
            camLoop = setInterval(async () => {
                const r = await det.detect(v).catch(() => []);
                if (r.length) { target.value = r[0].rawValue; stopKamera(); cekScan(); }
            }, 400);
        } catch { toast('Kamera tidak bisa dibuka. Izinkan akses kamera atau ketik kodenya.'); }
    }
    function stopKamera() {
        clearInterval(camLoop);
        if (stream) stream.getTracks().forEach(t => t.stop());
        stream = null;
        $('scanVideo').classList.add('d-none');
    }
    document.querySelectorAll('[data-cam]').forEach(b => { b.onclick = () => mulaiKamera($(b.dataset.cam)); });

    $('scanConfirm').onclick = () => {
        const tr = scanTr, foto = URL.createObjectURL($('scanFoto').files[0]);
        if (scanMode === 'serah') {
            mulaiSewa(tr);
            setStatusUnit(tr.dataset.serial, 'disewa');
            catat(tr, 'Unit diserahkan ke pelanggan (tervalidasi, bukti dikirim)', foto);
        } else {
            const k = $('scanKondisi').value, d = tr._s < 0 ? hitungDenda(tr._s) : { t: 0 };
            if (d.t) tr.dataset.denda = d.t;
            selesaikan(tr);
            setStatusUnit(tr.dataset.serial, { baik: 'tersedia', maint: 'maintenance', rusak: 'rusak' }[k], $('scanCatatan').value.trim());
            catat(tr, `Unit diterima kembali, ${KONDISI[k]} (tervalidasi, bukti dikirim)` + (d.t ? `, denda ${rp(d.t)}` : ''), foto);
        }
        modal('modalScan').hide();
        toast(`Bukti ${scanMode === 'serah' ? 'serah' : 'terima'} unit ${tr.dataset.id} dikirim ke ${tr.dataset.nama}.`);
    };

    /* ---------- Check-out sewa di tempat (tanpa denda) ---------- */
    function bukaCheckout(tr) {
        cur = tr;
        const now = new Date(), s = tr._s || 0;
        $('coId').textContent = tr.dataset.id;
        $('coNama').textContent = `${tr.dataset.nama} · ${tr.dataset.unit}`;
        $('coRencana').textContent = hm(new Date(now.getTime() + s * 1000));
        $('coAktual').textContent = hm(now);
        $('coTelat').textContent = s < 0 ? `Waktu habis ${Math.ceil(-s / 60)} menit lalu` : 'Masih dalam waktu sewa';
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
        tolak: tr => {
            const a = prompt(`Alasan menolak ${tr.dataset.id} (dikirim ke pelanggan):`);
            if (a === null) return;
            catat(tr, 'Pesanan ditolak: ' + (a.trim() || 'tanpa keterangan'));
            setState(tr, 'ditolak');
            toast(`${tr.dataset.id} ditolak.`);
        },
        berangkat: tr => { catat(tr, 'Unit berangkat diantar ke alamat pelanggan'); setState(tr, 'diantar'); toast(`${tr.dataset.id}: status diperbarui, sedang diantar.`); },
        sampai: tr => { catat(tr, 'Unit sudah sampai di lokasi pelanggan'); setState(tr, 'sampai'); toast(`${tr.dataset.id}: status diperbarui, sudah sampai lokasi.`); },
        serah: tr => bukaScan(tr, 'serah'),
        terima: tr => bukaScan(tr, 'terima'),
        checkout: bukaCheckout
    };
    document.querySelector('tbody').addEventListener('click', e => {
        const b = e.target.closest('[data-aksi]');
        if (b) AKSI[b.dataset.aksi](b.closest('tr'));
    });

    /* ---------- Tambah sewa (khusus di tempat) ---------- */
    const modalTambahEl = $('modalTambahSewa');
    modalTambahEl.addEventListener('show.bs.modal', () => {
        $('addWaktuOtomatis').value = hm(new Date()) + ' WIB (Hari ini)';
        const st = statusUnitTersimpan(), sel = $('addUnitRuang');
        [...sel.options].forEach(o => { // unit maintenance/rusak/disewa tidak bisa dipilih
            if (!o.dataset.serial) return;
            o.dataset.label = o.dataset.label || o.textContent.trim().replace(/\s+/g, ' ');
            const s = st[o.dataset.serial], mati = !!s && s !== 'tersedia';
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

    $('formTambahSewa').onsubmit = e => {
        e.preventDefault();
        const opt = $('addUnitRuang').selectedOptions[0], durasi = Math.min(24, Math.max(1, +$('addDurasiJam').value || 1));
        const m = /^(.*?)\s*\((.*)\)$/.exec(opt.value) || [0, opt.value, ''];
        const now = new Date(), total = rp((+opt.dataset.tarif || 0) * durasi);
        const nomor = Math.max(100, ...semua().map(r => angka(r.dataset.id))) + 1;
        const tr = document.createElement('tr');
        Object.assign(tr.dataset, {
            id: '#TRX-' + nomor, nama: $('addNama').value.trim(), unit: opt.value, serial: opt.dataset.serial || '',
            jenis: m[1].slice(0, 3).toLowerCase(), layanan: 'ditempat', state: 'aktif', sisa: durasi * 3600,
            jamAwal: hm(now) + ' WIB', jamHabis: hm(new Date(now.getTime() + durasi * 3600000)) + ' WIB',
            durasi: durasi + ' Jam', sewa: total, total, statusBayar: $('addStatusBayar').value
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
    $('modalDetail').addEventListener('show.bs.modal', e => {
        const tr = e.relatedTarget && e.relatedTarget.closest('tr'); if (!tr) return;
        const l = tr.dataset.layanan || 'ditempat', s = tr.dataset.state || '', ditempat = l === 'ditempat';
        const set = (id, v) => { $(id).textContent = v; };
        set('dtlId', tr.dataset.id || '-'); set('dtlNama', tr.dataset.nama || '-'); set('dtlUnit', tr.dataset.unit || '-');
        set('dtlJamAwal', tr.dataset.jamAwal || tr.dataset.tgl || '-'); set('dtlJamHabis', tr.dataset.jamHabis || '-');
        set('dtlDurasi', tr.dataset.durasi || '-'); set('dtlBiayaSewa', tr.dataset.sewa || 'Rp 0');
        $('dtlStatusBayar').innerHTML = `<span class="badge ${tr.dataset.statusBayar === 'Belum Bayar' ? 'bg-warning text-dark' : 'bg-success'}">${esc(tr.dataset.statusBayar || 'Lunas')}</span>`;
        const [lbl, cls] = STATE[s] || ['-', 'bg-secondary'];
        $('dtlBadgeState').className = `badge ${cls} px-3 py-2 rounded-pill`;
        $('dtlBadgeState').textContent = tr.querySelector('.st').textContent || lbl;
        set('dtlBadgeLayanan', ditempat ? 'Di tempat' : l.charAt(0).toUpperCase() + l.slice(1));
        $('secUserOnly').style.display = ditempat ? 'none' : 'block';
        $('secBukti').style.display = ditempat ? 'none' : 'block';
        $('rowOngkir').style.display = l === 'delivery' ? 'flex' : 'none';
        if (!ditempat) {
            set('dtlHp', tr.dataset.hp || '-'); set('dtlJaminan', tr.dataset.jaminan || '-'); set('dtlAlamat', tr.dataset.alamat || '-');
            set('dtlOngkir', tr.dataset.ongkir || 'Rp 0');
            $('dtlBukti').innerHTML = (tr._log || []).map(x => `<div class="d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill text-success"></i>
                <span class="flex-grow-1">${esc(x.teks)}<small class="d-block text-muted">${esc(x.waktu)}</small></span>
                ${x.foto ? `<a href="${x.foto}" target="_blank"><img src="${x.foto}" width="44" height="44" class="rounded border" style="object-fit:cover" alt="Bukti"></a>` : ''}</div>`).join('')
                || '<span class="text-muted">Belum ada catatan verifikasi atau serah terima.</span>';
        }
        set('dtlTotalTagihan', (ditempat ? tr.dataset.sewa : tr.dataset.total || tr.dataset.sewa) || 'Rp 0');

        const telat = !ditempat && tr._s !== undefined && tr._s < 0 && !tr.dataset.done, final = angka(tr.dataset.denda);
        const d = telat ? hitungDenda(tr._s) : null;
        $('secDenda').style.display = telat || final ? 'block' : 'none';
        if (telat) { set('dtlDendaNominal', rp(d.t)); set('dtlDendaKeterangan', `Terlambat ${d.m} menit (${d.j} jam denda @ Rp 10.000/jam)`); }
        else if (final) { set('dtlDendaNominal', rp(final)); set('dtlDendaKeterangan', 'Denda keterlambatan tercatat saat unit diterima'); }
        $('secHabis').style.display = ditempat && s === 'habis' ? 'block' : 'none';
    });

    /* ---------- Filter, sidebar, init ---------- */
    ['fStatus', 'fLayanan', 'fJenis'].forEach(id => { $(id).onchange = apply; });
    $('fCari').oninput = apply;
    document.querySelectorAll('[data-f]').forEach(c => { c.onclick = () => { $('fStatus').value = c.dataset.f; apply(); }; });

    const sb = $('sidebar'), bd = $('backdrop');
    const buka = o => { if (sb) sb.classList.toggle('show', o); if (bd) bd.classList.toggle('show', o); };
    if ($('menuBtn')) $('menuBtn').onclick = () => buka(true);
    if (bd) bd.onclick = () => buka(false);
    if (sb) sb.querySelectorAll('a').forEach(a => { a.onclick = () => buka(false); });

    semua().forEach(r => { if (r.dataset.sisa !== undefined) r._s = parseInt(r.dataset.sisa, 10) || 0; });
    semua().forEach(r => setState(r, r.dataset.state));
    tick();
    setInterval(tick, 1000);
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initApp);
else initApp();
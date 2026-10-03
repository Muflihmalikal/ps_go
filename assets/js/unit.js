/* unit.js v2 - Katalog admin PS Rental (data dummy di localStorage).
   Paket katalog (parent) -> unit fisik (child). Paket Combo tidak punya unit sendiri:
   stoknya dihitung dari komponen (paket PS / TV yang digabung). */
(() => {
    'use strict';
    const KEY_ADMIN = 'psr_katalog_admin_v3', KEY_PUBLIK = 'psr_katalog_publik';
    const KAT = { ps3: 'PlayStation 3', ps4: 'PlayStation 4', ps5: 'PlayStation 5', combo: 'Paket Playbox + TV LED', tv: 'TV LED Only' };
    const ST = { tersedia: ['Tersedia', 'bi-check-circle-fill'], disewa: ['Disewa', 'bi-person-fill'], maintenance: ['Maintenance', 'bi-tools'], rusak: ['Rusak', 'bi-exclamation-triangle-fill'] };
    const IKON = { ps3: ['bi-controller', 'blue'], ps4: ['bi-controller', 'blue'], ps5: ['bi-playstation', 'violet'], combo: ['bi-box-seam', 'orange'], tv: ['bi-tv', 'teal'] };
    const SET = { maint: 'maintenance', selesai: 'tersedia', rusak: 'rusak', pulih: 'tersedia' };
    const PER = 4;
    const NEXT = { tersedia: 'maintenance', maintenance: 'tersedia', rusak: 'maintenance' }; // klik badge
    const TABS = [['all', 'Semua'], ['tersedia', 'Tersedia'], ['disewa', 'Disewa'], ['maintenance', 'Maintenance'], ['rusak', 'Rusak']];
    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const rp = n => 'Rp ' + Number(n).toLocaleString('id-ID');
    const U = (pre, arr) => arr.map((a, i) => ({ kode: `${pre}-${String(i + 1).padStart(3, '0')}`, status: a[0], catatan: a[1] || '' }));
    const seed = () => [
        { id: 1, kode: 'KAT-PS4-SLIM', nama: 'PlayStation 4 Slim', kategori: 'ps4', badge: 'KONSOL ONLY', tag: '2 Stik DS4', deskripsi: '2 Stik DS4 + TV LED 43"', wk12: 40000, wk24: 70000, wn12: 50000, wn24: 85000, override: 'auto', komponen: [], unit: U('PS4', [['tersedia'], ['tersedia'], ['disewa', 'Rina S.'], ['maintenance', 'Stik drift']]) },
        { id: 2, kode: 'KAT-PS5-DISC', nama: 'PlayStation 5 Disc Edition', kategori: 'ps5', badge: 'BEST VALUE', tag: '2 DualSense', deskripsi: '2 DualSense + TV OLED 55"', wk12: 70000, wk24: 120000, wn12: 85000, wn24: 150000, override: 'auto', komponen: [], unit: U('PS5', [['tersedia'], ['disewa', 'Bagas W.']]) },
        { id: 3, kode: 'KAT-PLAYBOX-01', nama: 'Paket Playbox Outdoor + TV LED', kategori: 'combo', badge: 'PAKET LENGKAP', tag: 'PS4 + TV 32"', deskripsi: 'PS4 + 2 Stik + TV LED 32" + Tas Koper', wk12: 70000, wk24: 100000, wn12: 85000, wn24: 125000, override: 'auto', komponen: [1, 5], unit: [] },
        { id: 4, kode: 'KAT-PS3-SLIM', nama: 'PlayStation 3 Super Slim', kategori: 'ps3', badge: 'KONSOL ONLY', tag: '2 Stik DS3', deskripsi: '2 Stik DS3 + TV LED 32"', wk12: 30000, wk24: 50000, wn12: 40000, wn24: 65000, override: 'auto', komponen: [], unit: U('PS3', [['disewa', 'Ahmad R.'], ['disewa', 'Dimas P.'], ['maintenance', 'Kabel HDMI'], ['rusak', 'YLOD'], ['maintenance', 'Ganti Pasta']]) },
        { id: 5, kode: 'KAT-TV-LED', nama: 'TV LED 43"', kategori: 'tv', badge: 'TV LED ONLY', tag: '', deskripsi: 'TV LED 43", kabel HDMI + remote', wk12: 25000, wk24: 40000, wn12: 30000, wn24: 50000, override: 'auto', komponen: [], unit: U('TV', [['tersedia'], ['maintenance', 'Bracket patah'], ['maintenance', 'Kabel power']]) }
    ];

    let data;
    try { data = JSON.parse(localStorage.getItem(KEY_ADMIN)); } catch { data = null; }
    if (!Array.isArray(data)) data = seed();
    let kerja = [], komp = [], editId = null, hapusId = null, kat = 'all', sf = 'all', halaman = 1, weekend = false;
    const terbuka = new Set();

    /* ---------- Turunan: stok & status dihitung, tidak diketik ---------- */
    const cari = id => data.find(p => p.id === id);
    const n = (p, s) => p.unit.filter(x => x.status === s).length;
    const fisik = () => data.filter(p => p.kategori !== 'combo');
    function hitung(p) {
        let h;
        if (p.kategori === 'combo') {
            const k = p.komponen.map(cari).filter(Boolean);
            h = {
                total: k.length ? Math.min(...k.map(x => x.unit.length)) : 0,
                tersedia: k.length ? Math.min(...k.map(x => x.override === 'Maintenance' ? 0 : n(x, 'tersedia'))) : 0, disewa: 0, mnt: 0, rsk: 0
            };
        } else h = { total: p.unit.length, tersedia: n(p, 'tersedia'), disewa: n(p, 'disewa'), mnt: n(p, 'maintenance'), rsk: n(p, 'rusak') };
        h.status = p.override === 'Maintenance' ? 'Maintenance' : h.tersedia > 0 ? 'Tersedia' : 'Habis';
        return h;
    }

    /* ---------- Sinkron ke katalog pelanggan (simulasi) ---------- */
    function sinkron() {
        const items = data.filter(p => p.override !== 'Maintenance').map(p => {
            const h = hitung(p);
            return {
                id: p.id, nama: p.nama, kategori: p.kategori, badge: p.badge, tag: p.tag, deskripsi: p.deskripsi,
                komponen: p.komponen.map(i => cari(i)?.nama).filter(Boolean),
                tarif: { weekday: { j12: p.wk12, j24: p.wk24 }, weekend: { j12: p.wn12, j24: p.wn24 } }, stokTersedia: h.tersedia, tersedia: h.tersedia > 0
            };
        });
        try { localStorage.setItem(KEY_ADMIN, JSON.stringify(data)); localStorage.setItem(KEY_PUBLIK, JSON.stringify({ versi: Date.now(), items })); }
        catch (e) { console.warn('Penyimpanan lokal gagal', e); }
    }

    /* ---------- Tabel: baris utama + panel rincian (accordion) ---------- */
    const tarif = (p, jam) => p[(weekend ? 'wn' : 'wk') + jam];
    const sp = (p, u) => {
        const isi = `<i class="bi ${ST[u.status][1]}"></i>${ST[u.status][0]}${u.catatan ? ` (${esc(u.catatan)})` : ''}`;
        return u.status === 'disewa' ? `<span class="sp sp-disewa">${isi}</span>`
            : `<button type="button" class="sp sp-${u.status}" data-aksi="toggle" data-id="${p.id}" data-kode="${esc(u.kode)}" title="Klik untuk ubah status">${isi}</button>`;
    };
    const tombol = (a, p, u, ikon, teks) => `<button type="button" class="btn btn-sm btn-outline-secondary rounded-pill ms-1" data-aksi="${a}" data-id="${p.id}" data-kode="${esc(u.kode)}"><i class="bi ${ikon} me-1"></i>${teks}</button>`;
    const aksiUnit = (p, u) => ({
        disewa: '<em class="text-muted small">Sedang digunakan</em>',
        tersedia: tombol('maint', p, u, 'bi-tools', 'Maintenance'),
        maintenance: tombol('selesai', p, u, 'bi-check2-circle', 'Selesai servis') + tombol('rusak', p, u, 'bi-x-octagon', 'Tandai rusak'),
        rusak: tombol('pulih', p, u, 'bi-arrow-counterclockwise', 'Pulihkan unit')
    })[u.status];

    function rincian(p) {
        let kepala, isi;
        if (p.kategori === 'combo') {
            const k = p.komponen.map(cari).filter(Boolean);
            kepala = `<div><span class="fw-bold small"><i class="bi bi-circle-fill text-warning me-2" style="font-size:.5rem"></i>KOMPONEN PAKET</span><span class="text-muted small ms-2">${k.length} komponen</span></div>`;
            isi = `<thead><tr><th>Komponen</th><th>Tipe</th><th>Stok komponen</th></tr></thead><tbody>` + (k.map(x => `<tr><td class="fw-semibold">1 &times; ${esc(x.nama)}</td><td>${KAT[x.kategori]}</td><td>${n(x, 'tersedia')} / ${x.unit.length} tersedia</td></tr>`).join('') || '<tr><td colspan="3" class="text-muted">Belum ada komponen.</td></tr>') + '</tbody>';
        } else {
            const u = p.unit.filter(x => sf === 'all' || x.status === sf);
            kepala = `<div><span class="fw-bold small"><i class="bi bi-circle-fill text-primary me-2" style="font-size:.5rem"></i>DAFTAR UNIT FISIK &amp; SERIAL (${esc(p.nama)})</span><span class="text-muted small ms-2">Total ${p.unit.length} Perangkat</span></div>
        <button type="button" class="btn btn-sm btn-light text-primary fw-bold rounded-pill" data-aksi="serial" data-id="${p.id}"><i class="bi bi-plus-lg me-1"></i>Tambah Nomor Serial</button>`;
            isi = `<thead><tr><th>ID Unit (Serial)</th><th>Status Perangkat</th><th class="text-end">Aksi</th></tr></thead><tbody>` + (u.map(x => `<tr>
        <td class="fw-semibold small">${esc(x.kode)}</td><td>${sp(p, x)}</td><td class="text-end text-nowrap">${aksiUnit(p, x)}</td></tr>`).join('') || '<tr><td colspan="3" class="text-muted">Tidak ada unit dengan status ini.</td></tr>') + '</tbody>';
        }
        return `<tr><td colspan="6" class="pt-0 pb-3 border-0"><div class="panel-unit"><div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">${kepala}</div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">${isi}</table></div></div></td></tr>`;
    }

    function baris(p) {
        const h = hitung(p), buka = sf !== 'all' || terbuka.has(p.id), [ik, wr] = IKON[p.kategori], lbl = weekend ? 'weekend' : 'weekday';
        const kls = p.override === 'Maintenance' ? 'off' : h.tersedia === 0 ? 'zero' : h.tersedia / h.total <= 0.34 ? 'low' : 'ok';
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
        const q = $('cariUnit').value.trim().toLowerCase(), f = fisik();
        $('statTotalUnit').textContent = data.length;
        $('statTersedia').textContent = f.reduce((a, p) => a + n(p, 'tersedia'), 0);
        $('statDisewa').textContent = f.reduce((a, p) => a + n(p, 'disewa'), 0);
        $('statPerbaikan').textContent = f.reduce((a, p) => a + n(p, 'maintenance') + n(p, 'rusak'), 0);

        $('tabStatusUnit').innerHTML = [['all', 'Semua'], ['tersedia', 'Tersedia'], ['disewa', 'Disewa'], ['maintenance', 'Maintenance'], ['rusak', 'Rusak']].map(([k, l]) => {
            const c = f.reduce((a, p) => a + (k === 'all' ? p.unit.length : n(p, k)), 0);
            return `<li><button type="button" class="stab ${k === sf ? 'active' : ''}" data-sf="${k}">${l} <span class="cnt cnt-${k}">${c}</span></button></li>`;
        }).join('');

        const hasil = data.filter(p => {
            const cocok = [p.kode, p.nama, p.deskripsi, p.tag, p.badge, KAT[p.kategori], ...p.unit.map(x => x.kode)].join(' ').toLowerCase().includes(q);
            return (kat === 'all' || p.kategori === kat) && cocok && (sf === 'all' || (p.kategori !== 'combo' && n(p, sf) > 0));
        });
        const hal = halaman = Math.min(halaman, Math.max(1, Math.ceil(hasil.length / PER))), dari = (hal - 1) * PER, tampil = hasil.slice(dari, dari + PER);
        $('tbodyUnit').innerHTML = tampil.map(baris).join('') || '<tr><td colspan="6" class="text-center text-muted py-4">Tidak ada unit yang cocok dengan filter.</td></tr>';
        $('infoHal').textContent = hasil.length ? `Menampilkan ${dari + 1} sampai ${dari + tampil.length} dari ${hasil.length} entri unit & paket` : '';
        const total = Math.ceil(hasil.length / PER), pg = (i, isi, off, on) => `<li class="page-item ${off ? 'disabled' : ''} ${on ? 'active' : ''}"><button type="button" class="page-link" data-hal="${i}">${isi}</button></li>`;
        $('paging').innerHTML = total > 1 ? pg(hal - 1, '<i class="bi bi-chevron-left"></i>', hal === 1) + Array.from({ length: total }, (_, i) => pg(i + 1, i + 1, false, i + 1 === hal)).join('') + pg(hal + 1, '<i class="bi bi-chevron-right"></i>', hal === total) : '';
    }

    function ubahStatus(u, st) {
        if (st === 'maintenance' || st === 'rusak') {
            const c = prompt(`Catatan untuk ${u.kode} (opsional, contoh: Kabel HDMI)`, u.catatan || '');
            if (c === null) return; u.catatan = c.trim();
        } else u.catatan = '';
        u.status = st; sinkron(); render();
    }

    $('tabStatusUnit').addEventListener('click', e => { const b = e.target.closest('[data-sf]'); if (b) { sf = b.dataset.sf; halaman = 1; render(); } });
    $('paging').addEventListener('click', e => { const b = e.target.closest('[data-hal]'); if (b && !b.parentElement.classList.contains('disabled')) { halaman = +b.dataset.hal; render(); } });

    $('tbodyUnit').addEventListener('click', e => {
        const b = e.target.closest('[data-aksi]'); if (!b) return;
        const id = +b.dataset.id, p = cari(id), a = b.dataset.aksi;
        if (a === 'expand') { terbuka.has(id) ? terbuka.delete(id) : terbuka.add(id); return render(); }
        if (a === 'toggle' || a in SET) { // quick switch per unit, langsung tersinkron
            const u = p.unit.find(x => x.kode === b.dataset.kode);
            if (u && u.status !== 'disewa') ubahStatus(u, a === 'toggle' ? NEXT[u.status] : SET[a]);
            return;
        }
        if (a === 'serial') {
            const pakai = new Set(data.flatMap(x => x.unit.map(y => y.kode))); let i = p.unit.length + 1, s;
            do { s = `${p.kategori.toUpperCase()}-${String(i++).padStart(3, '0')}`; } while (pakai.has(s));
            const k = prompt('ID unit baru (nomor serial):', s); if (k === null) return;
            const kode = k.trim().toUpperCase().replace(/\s+/g, '');
            if (!/^[A-Z0-9-]+$/.test(kode)) return alert('ID hanya boleh huruf, angka, dan tanda minus.');
            if (pakai.has(kode)) return alert(`ID ${kode} sudah dipakai.`);
            p.unit.push({ kode, status: 'tersedia', catatan: '' }); terbuka.add(id); sinkron(); return render();
        }
        if (a === 'edit') return bukaForm(id);
        const sewa = hitung(p).disewa, dipakai = data.filter(x => x.komponen.includes(id)).map(x => x.nama);
        if (sewa) return alert(`${p.nama} tidak bisa dihapus: ${sewa} unit sedang disewa. Nonaktifkan lewat Status Utama "Maintenance" sampai semua unit kembali.`);
        if (dipakai.length) return alert(`${p.nama} dipakai sebagai komponen di: ${dipakai.join(', ')}. Lepas dari paket tersebut dulu.`);
        hapusId = id; bootstrap.Modal.getOrCreateInstance($('modalHapusUnit')).show();
    });
    $('btnConfirmHapus').addEventListener('click', () => {
        data = data.filter(p => p.id !== hapusId); sinkron(); render();
        bootstrap.Modal.getOrCreateInstance($('modalHapusUnit')).hide();
    });

    /* ---------- Modal: stok otomatis, unit fisik, komponen combo ---------- */
    $('uStokTotal').previousElementSibling.textContent = 'Stok Total (otomatis)';
    $('uStokTersedia').previousElementSibling.textContent = 'Stok Tersedia (otomatis)';
    ['uStokTotal', 'uStokTersedia'].forEach(id => { const i = $(id); i.readOnly = true; i.tabIndex = -1; i.classList.add('bg-light'); i.removeAttribute('min'); });
    document.querySelector('#modalUnitForm .modal-body').insertAdjacentHTML('afterbegin', '<div id="uError" class="alert alert-danger d-none small mb-3"></div>');
    document.querySelector('#modalUnitForm .modal-body .row').insertAdjacentHTML('beforeend', `
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
    </div></div>`);

    const semuaKode = () => new Set(data.filter(p => p.id !== editId).flatMap(p => p.unit.map(x => x.kode)).concat(kerja.map(x => x.kode)));
    function saranKode() {
        const pre = $('uKategori').value.toUpperCase(), pakai = semuaKode(); let i = kerja.length + 1, k;
        do { k = `${pre}-${String(i++).padStart(3, '0')}`; } while (pakai.has(k));
        return k;
    }
    function segarkanStok() {
        const h = hitung($('uKategori').value === 'combo' ? { kategori: 'combo', komponen: komp, override: 'auto' } : { kategori: 'x', unit: kerja, override: 'auto' });
        $('uStokTotal').value = h.total; $('uStokTersedia').value = h.tersedia; $('uJml').textContent = kerja.length;
        if ($('uStatus').value !== 'Maintenance') $('uStatus').value = h.tersedia > 0 ? 'Tersedia' : 'Habis';
    }
    function renderFisik() {
        const combo = $('uKategori').value === 'combo';
        $('secFisik').classList.toggle('d-none', combo); $('secKomponen').classList.toggle('d-none', !combo);
        $('listFisik').innerHTML = kerja.map((x, i) => {
            const sewa = x.status === 'disewa';
            const opsi = sewa ? '<option>Sedang disewa</option>' : ['tersedia', 'maintenance', 'rusak'].map(s => `<option value="${s}" ${s === x.status ? 'selected' : ''}>${ST[s][0]}</option>`).join('');
            const perlu = x.status === 'maintenance' || x.status === 'rusak';
            return `<div class="d-flex flex-wrap gap-2 align-items-center"><code class="flex-grow-1">${esc(x.kode)}</code>
        ${perlu ? `<input type="text" class="form-control form-control-sm w-auto" data-cat="${i}" placeholder="Catatan, mis. Kabel HDMI" aria-label="Catatan ${esc(x.kode)}" value="${esc(x.catatan || '')}">` : ''}
        <select class="form-select form-select-sm w-auto" data-i="${i}" aria-label="Status ${esc(x.kode)}" ${sewa ? 'disabled' : ''}>${opsi}</select>
        <button type="button" class="btn btn-sm btn-outline-danger" data-hapus="${i}" aria-label="Hapus ${esc(x.kode)}" ${sewa ? 'disabled title="Unit sedang disewa"' : ''}><i class="bi bi-trash"></i></button></div>`;
        }).join('') || '<small class="text-muted">Belum ada unit fisik.</small>';
        $('uSerialBaru').placeholder = 'Contoh: ' + saranKode();
        $('menuKomp').innerHTML = fisik().filter(p => p.id !== editId).map(p => `<label class="dropdown-item d-flex gap-2 align-items-center">
      <input type="checkbox" class="form-check-input mt-0" value="${p.id}" ${komp.includes(p.id) ? 'checked' : ''}> ${esc(p.nama)} <small class="text-muted ms-auto">${KAT[p.kategori]}</small></label>`).join('');
        $('chipKomp').innerHTML = komp.map(i => cari(i)).filter(Boolean).map(p => `<span class="badge bg-primary bg-opacity-10 text-primary border">1 &times; ${esc(p.nama)}</span>`).join('') || '<small class="text-muted">Belum ada komponen dipilih.</small>';
        segarkanStok();
    }
    $('listFisik').addEventListener('change', e => { const i = e.target.dataset.i; if (i !== undefined) { kerja[i].status = e.target.value; if (e.target.value === 'tersedia') kerja[i].catatan = ''; renderFisik(); } });
    $('listFisik').addEventListener('input', e => { const i = e.target.dataset.cat; if (i !== undefined) kerja[i].catatan = e.target.value; });
    $('listFisik').addEventListener('click', e => { const b = e.target.closest('[data-hapus]'); if (b) { kerja.splice(+b.dataset.hapus, 1); renderFisik(); } });
    $('menuKomp').addEventListener('change', e => {
        const id = +e.target.value; komp = e.target.checked ? [...komp, id] : komp.filter(x => x !== id);
        renderFisik();
    });
    $('btnTambahFisik').addEventListener('click', () => {
        const k = ($('uSerialBaru').value.trim() || saranKode()).toUpperCase().replace(/\s+/g, '');
        const err = !/^[A-Z0-9-]+$/.test(k) ? 'ID hanya boleh huruf, angka, dan tanda minus.' : semuaKode().has(k) ? `ID ${k} sudah dipakai.` : '';
        $('errSerial').textContent = err; if (err) return;
        kerja.push({ kode: k, status: 'tersedia', catatan: '' }); $('uSerialBaru').value = ''; renderFisik();
    });
    $('uKategori').addEventListener('change', renderFisik);
    $('uStatus').addEventListener('change', segarkanStok);

    const mForm = () => bootstrap.Modal.getOrCreateInstance($('modalUnitForm'));
    function bukaForm(id) {
        editId = id;
        const p = cari(id);
        $('formUnit').reset(); $('uError').classList.add('d-none'); $('errSerial').textContent = ''; $('unitIdIndex').value = id ?? '';
        $('modalUnitTitle').innerHTML = `<i class="bi bi-box-seam text-primary me-2"></i>${p ? 'Edit unit / paket' : 'Tambah unit / paket baru'}`;
        const v = p || { kode: '', nama: '', kategori: 'ps4', badge: 'KONSOL ONLY', tag: '', deskripsi: '', wk12: '', wk24: '', wn12: '', wn24: '', override: 'auto', komponen: [], unit: [] };
        Object.entries({ uKode: v.kode, uNama: v.nama, uKategori: v.kategori, uBadge: v.badge, uTag: v.tag, uDeskripsi: v.deskripsi, uWk12: v.wk12, uWk24: v.wk24, uWn12: v.wn12, uWn24: v.wn24 })
            .forEach(([k, val]) => { $(k).value = val; });
        $('uStatus').value = v.override === 'Maintenance' ? 'Maintenance' : 'Tersedia';
        kerja = v.unit.map(x => ({ ...x })); komp = [...v.komponen];
        renderFisik(); mForm().show();
    }

    function validasi(f) {
        const e = [];
        if (data.some(p => p.id !== editId && p.kode === f.kode)) e.push(`Kode ${f.kode} sudah dipakai paket lain.`);
        [['weekday', f.wk12, f.wk24], ['weekend', f.wn12, f.wn24]].forEach(([nm, a, b]) => {
            if (!(a > 0 && b > 0)) e.push(`Tarif ${nm} harus lebih dari 0.`);
            else if (b < a) e.push(`Tarif ${nm} 24 jam tidak boleh lebih murah dari 12 jam.`);
        });
        if (f.kategori === 'combo') {
            const k = komp.map(cari).filter(Boolean);
            if (!k.some(x => ['ps3', 'ps4', 'ps5'].includes(x.kategori)) || !k.some(x => x.kategori === 'tv')) e.push('Combo harus berisi minimal 1 konsol PS dan 1 TV LED.');
            if (editId && data.some(p => p.komponen.includes(editId))) e.push('Paket ini dipakai sebagai komponen combo lain, tidak bisa diubah menjadi combo.');
        } else if (!kerja.length) e.push('Tambahkan minimal 1 unit fisik.');
        return e;
    }

    $('formUnit').addEventListener('submit', ev => {
        ev.preventDefault();
        const f = {
            kode: $('uKode').value.trim().toUpperCase(), nama: $('uNama').value.trim(), kategori: $('uKategori').value, badge: $('uBadge').value,
            tag: $('uTag').value.trim(), deskripsi: $('uDeskripsi').value.trim(), wk12: +$('uWk12').value, wk24: +$('uWk24').value,
            wn12: +$('uWn12').value, wn24: +$('uWn24').value, override: $('uStatus').value === 'Maintenance' ? 'Maintenance' : 'auto'
        };
        const err = validasi(f);
        if (err.length) { $('uError').innerHTML = err.map(esc).join('<br>'); $('uError').classList.remove('d-none'); $('uError').scrollIntoView({ block: 'nearest' }); return; }
        const combo = f.kategori === 'combo', ekstra = { unit: combo ? [] : kerja, komponen: combo ? komp : [] };
        if (editId) Object.assign(cari(editId), f, ekstra);
        else data.push({ id: Math.max(0, ...data.map(x => x.id)) + 1, ...f, ...ekstra });
        sinkron(); render(); mForm().hide();
    });

    /* ---------- Filter kategori, pencarian, init ---------- */
    $('filterKategori').addEventListener('click', e => {
        const b = e.target.closest('.btn-filter'); if (!b) return;
        kat = b.dataset.kat; halaman = 1;
        document.querySelectorAll('.btn-filter').forEach(x => x.classList.toggle('active', x === b));
        render();
    });
    $('cariUnit').addEventListener('input', () => { halaman = 1; render(); });
    $('checkWeekend').addEventListener('change', e => { weekend = e.target.checked; render(); });
    $('btnTambahUnit').addEventListener('click', () => bukaForm(null));

    sinkron(); render();
})();
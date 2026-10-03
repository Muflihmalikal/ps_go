const TARIF_DENDA = 10000;
const TOLERANSI = 10;
const $ = id => document.getElementById(id);
const p2 = n => String(n).padStart(2, '0');
const hm = d => p2(d.getHours()) + ':' + p2(d.getMinutes());

function initApp() {
    const rows = [...document.querySelectorAll('tr[data-id]')];
    const timed = rows.filter(r => r.dataset.sisa !== undefined);
    timed.forEach(r => r._s = parseInt(r.dataset.sisa, 10) || 0);

    function apply() {
        const fEl = $('fStatus'), lEl = $('fLayanan'), jEl = $('fJenis'), qEl = $('fCari');
        if (!fEl || !lEl || !jEl || !qEl) return;

        const f = fEl.value;
        const l = lEl.value;
        const j = jEl.value;
        const q = qEl.value.trim().toLowerCase();

        let n = 0;
        rows.forEach(r => {
            const s = r.dataset.state || '';
            const ok = (f === 'all' || f === s || (f === 'aktif' && s === 'terlambat'))
                && (l === 'all' || l === (r.dataset.layanan || ''))
                && (j === 'all' || j === (r.dataset.jenis || ''))
                && ((r.dataset.id || '') + ' ' + (r.dataset.nama || '')).toLowerCase().includes(q);

            r.classList.toggle('d-none', !ok);
            if (ok) n++;
        });

        const kosongEl = $('kosong');
        if (kosongEl) kosongEl.classList.toggle('d-none', n > 0);

        const c = s => rows.filter(r => r.dataset.state === s).length;
        if ($('sMenunggu')) $('sMenunggu').textContent = c('menunggu');
        if ($('sKeluar')) $('sKeluar').textContent = c('aktif') + c('terlambat');
        if ($('sTelat')) $('sTelat').textContent = c('terlambat');
    }

    ['fStatus', 'fLayanan', 'fJenis'].forEach(id => {
        const el = $(id);
        if (el) el.onchange = apply;
    });
    if ($('fCari')) $('fCari').oninput = apply;

    document.querySelectorAll('[data-f]').forEach(c => {
        c.onclick = () => {
            if ($('fStatus')) $('fStatus').value = c.dataset.f;
            apply();
        };
    });

    function tick() {
        let changed = false;
        timed.forEach(r => {
            if (r.dataset.done) return;
            r._s--;
            const a = Math.abs(r._s), late = r._s < 0, s = late ? 'terlambat' : 'aktif';
            const el = r.querySelector('.sisa'), st = r.querySelector('.st');
            if (el && st) {
                el.textContent = (late ? '+' : '') + p2(Math.floor(a / 3600)) + ':' + p2(Math.floor((a % 3600) / 60)) + ':' + p2(a % 60);
                el.className = 'sisa fw-bold' + (late ? ' text-danger' : '');
                st.className = 'st badge px-3 py-2 rounded-pill ' + (late ? 'bg-danger' : 'bg-success');
                st.textContent = late ? 'Terlambat' : 'Aktif';
            }
            if (r.dataset.state !== s) {
                r.dataset.state = s;
                changed = true;
            }
        });
        if (changed) apply();
    }

    apply();
    tick();
    setInterval(tick, 1000);

    function hitungDenda(s) {
        const m = Math.max(0, Math.ceil(-s / 60));
        if (m <= TOLERANSI) return { m, j: 0, t: 0 };
        const j = Math.ceil(m / 60);
        return { m, j, t: j * TARIF_DENDA };
    }

    const modalTambahEl = $('modalTambahSewa');
    if (modalTambahEl) {
        modalTambahEl.addEventListener('show.bs.modal', () => {
            const now = new Date();
            if ($('addWaktuOtomatis')) $('addWaktuOtomatis').value = hm(now) + ' WIB (Hari ini)';
            hitungTotalOtomatis();
        });
    }

    function hitungTotalOtomatis() {
        const sel = $('addUnitRuang');
        if (!sel) return;
        const opt = sel.options[sel.selectedIndex];
        const tarif = opt && opt.dataset.tarif ? +opt.dataset.tarif : 0;
        const durasi = +($('addDurasiJam') ? $('addDurasiJam').value : 1) || 1;
        const total = tarif * durasi;
        if ($('addTotalOtomatis')) $('addTotalOtomatis').value = 'Rp ' + total.toLocaleString('id-ID');
    }

    if ($('addUnitRuang')) $('addUnitRuang').onchange = hitungTotalOtomatis;
    if ($('addDurasiJam')) $('addDurasiJam').oninput = hitungTotalOtomatis;

    if ($('formTambahSewa')) {
        $('formTambahSewa').onsubmit = (e) => {
            e.preventDefault();
            alert('Sewa Di Tempat Berhasil Ditambahkan!');
            if (window.bootstrap && modalTambahEl) {
                const modalInstance = bootstrap.Modal.getInstance(modalTambahEl);
                if (modalInstance) modalInstance.hide();
            }
            e.target.reset();
        };
    }

    const modalDetailEl = $('modalDetail');
    if (modalDetailEl) {
        modalDetailEl.addEventListener('show.bs.modal', e => {
            if (!e.relatedTarget) return;
            const tr = e.relatedTarget.closest('tr');
            if (!tr) return;

            const layanan = tr.dataset.layanan || 'ditempat';
            const state = tr.dataset.state || '';

            if ($('dtlId')) $('dtlId').textContent = tr.dataset.id || '-';
            if ($('dtlNama')) $('dtlNama').textContent = tr.dataset.nama || '-';
            if ($('dtlUnit')) $('dtlUnit').textContent = tr.dataset.unit || '-';
            if ($('dtlJamAwal')) $('dtlJamAwal').textContent = tr.dataset.jamAwal || tr.dataset.tgl || '-';
            if ($('dtlJamHabis')) $('dtlJamHabis').textContent = tr.dataset.jamHabis || '-';
            if ($('dtlDurasi')) $('dtlDurasi').textContent = tr.dataset.durasi || '-';
            if ($('dtlBiayaSewa')) $('dtlBiayaSewa').textContent = tr.dataset.sewa || 'Rp 0';
            if ($('dtlStatusBayar')) {
                $('dtlStatusBayar').innerHTML = `<span class="badge ${tr.dataset.statusBayar === 'Belum Bayar' ? 'bg-warning text-dark' : 'bg-success'}">${tr.dataset.statusBayar || 'Lunas'}</span>`;
            }
            if ($('dtlBadgeState')) {
                const bgState = state === 'terlambat' ? 'bg-danger' : (state === 'menunggu' ? 'bg-warning text-dark' : (state === 'selesai' ? 'bg-secondary' : 'bg-success'));
                $('dtlBadgeState').className = `badge ${bgState} px-3 py-2 rounded-pill`;
                $('dtlBadgeState').textContent = state ? state.toUpperCase() : 'AKTIF';
            }

            if ($('dtlBadgeLayanan')) {
                $('dtlBadgeLayanan').textContent = layanan === 'ditempat' ? 'Di tempat' : layanan.toUpperCase();
            }
            if (layanan === 'ditempat') {
                if ($('secUserOnly')) $('secUserOnly').style.display = 'none';
                if ($('secBuktiTransfer')) $('secBuktiTransfer').style.display = 'none';
                if ($('rowOngkir')) $('rowOngkir').style.display = 'none';
                if ($('dtlTotalTagihan')) $('dtlTotalTagihan').textContent = tr.dataset.sewa || 'Rp 0';
            } else {
                if ($('secUserOnly')) $('secUserOnly').style.display = 'block';
                if ($('secBuktiTransfer')) $('secBuktiTransfer').style.display = 'block';
                if ($('dtlHp')) $('dtlHp').textContent = tr.dataset.hp || '-';
                if ($('dtlJaminan')) $('dtlJaminan').textContent = tr.dataset.jaminan || '-';
                if ($('dtlAlamat')) $('dtlAlamat').textContent = tr.dataset.alamat || '-';

                if (layanan === 'delivery') {
                    if ($('rowOngkir')) $('rowOngkir').style.display = 'flex';
                    if ($('dtlOngkir')) $('dtlOngkir').textContent = tr.dataset.ongkir || 'Rp 0';
                } else {
                    if ($('rowOngkir')) $('rowOngkir').style.display = 'none';
                }
                if ($('dtlTotalTagihan')) $('dtlTotalTagihan').textContent = tr.dataset.total || tr.dataset.sewa || 'Rp 0';
            }

            if (tr._s !== undefined && tr._s < 0) {
                const d = hitungDenda(tr._s);
                if ($('secDenda')) $('secDenda').style.display = 'block';
                if ($('dtlDendaNominal')) $('dtlDendaNominal').textContent = 'Rp ' + d.t.toLocaleString('id-ID');
                if ($('dtlDendaKeterangan')) $('dtlDendaKeterangan').textContent = `Terlambat ${d.m} menit (${d.j} jam denda @ Rp 10.000/jam)`;
            } else {
                if ($('secDenda')) $('secDenda').style.display = 'none';
            }
        });
    }

    let cur = null;
    const modalEl = $('modalCheckout');
    if (modalEl) {
        modalEl.addEventListener('show.bs.modal', e => {
            if (!e.relatedTarget) return;
            cur = e.relatedTarget.closest('tr');
            if (!cur) return;

            const now = new Date();
            const sisaSec = cur._s !== undefined ? cur._s : 0;
            const d = hitungDenda(sisaSec);

            if ($('coId')) $('coId').textContent = cur.dataset.id || '-';
            if ($('coNama')) $('coNama').textContent = (cur.dataset.nama || '-') + ' · ' + (cur.dataset.unit || '-');
            if ($('coRencana')) $('coRencana').textContent = hm(new Date(now.getTime() + sisaSec * 1000));
            if ($('coAktual')) $('coAktual').textContent = hm(now);
            if ($('coTelat')) $('coTelat').textContent = d.j ? d.m + ' menit → ' + d.j + ' jam' : (d.m ? d.m + ' menit (dalam toleransi)' : 'Tepat waktu');
            if ($('coTotal')) $('coTotal').textContent = 'Rp ' + d.t.toLocaleString('id-ID');
        });
    }

    if ($('coConfirm')) {
        $('coConfirm').onclick = () => {
            if (!cur) return;
            cur.dataset.done = '1';
            cur.dataset.state = 'selesai';

            const sisaEl = cur.querySelector('.sisa');
            if (sisaEl) {
                sisaEl.textContent = '—';
                sisaEl.className = 'sisa fw-bold text-muted';
            }

            const st = cur.querySelector('.st');
            if (st) {
                st.className = 'st badge bg-secondary px-3 py-2 rounded-pill';
                st.textContent = 'Selesai';
            }

            const btnCo = cur.querySelector('.btn-co');
            if (btnCo) btnCo.remove();

            if (window.bootstrap && modalEl) {
                const modalInstance = bootstrap.Modal.getInstance(modalEl);
                if (modalInstance) modalInstance.hide();
            }
            apply();
        };
    }

    const sb = $('sidebar'), bd = $('backdrop');
    const buka = o => {
        if (sb) sb.classList.toggle('show', o);
        if (bd) bd.classList.toggle('show', o);
    };
    if ($('menuBtn')) $('menuBtn').onclick = () => buka(true);
    if (bd) bd.onclick = () => buka(false);
    if (sb) sb.querySelectorAll('a').forEach(a => a.onclick = () => buka(false));
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initApp);
} else {
    initApp();
}
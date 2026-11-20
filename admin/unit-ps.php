<?php
require_once __DIR__ . '/_common.php';

$user = current_user();
[$branches, $branchId, $branch, $branchError] = admin_branch_context($pdo, $user);
$returnUrl = 'unit-ps.php' . ($branchId ? admin_branch_query($branchId) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$branchId || !verify_csrf_token()) {
        admin_flash(!$branchId ? $branchError : 'Form kedaluwarsa. Muat ulang halaman, lalu coba lagi.');
        header('Location: ' . $returnUrl);
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    try {
        // 1. SIMPAN / UBAH PAKET & UNIT
        if ($action === 'save_package') {
            $packageId   = filter_var($_POST['id_paket'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $isUpdate    = $packageId > 0;
            $code        = strtoupper(trim((string)($_POST['kode_paket'] ?? '')));
            $name        = trim((string)($_POST['nama_paket'] ?? ''));
            $category    = (string)($_POST['kategori'] ?? '');
            $badge       = trim((string)($_POST['label_badge'] ?? 'KONSOL ONLY'));
            $highlight   = trim((string)($_POST['tag_highlight'] ?? ''));
            $description = trim((string)($_POST['deskripsi'] ?? ''));
            $override    = ($_POST['override_status'] ?? 'auto') === 'Maintenance' ? 'Maintenance' : 'auto';

            $prices = [];
            foreach (['harga_wk_12h', 'harga_wk_24h', 'harga_wn_12h', 'harga_wn_24h', 'denda_per_jam'] as $field) {
                $val = filter_var($_POST[$field] ?? 0, FILTER_VALIDATE_FLOAT);
                if ($val === false || $val < 0 || $val > 99999999) {
                    throw new InvalidArgumentException('Tarif dan denda harus berupa angka yang tidak negatif.');
                }
                $prices[$field] = $val;
            }

            if ($code === '' || strlen($code) > 50 || !preg_match('/^[A-Z0-9._-]+$/', $code)) {
                throw new InvalidArgumentException('Kode paket wajib diisi (maksimal 50 karakter; gunakan huruf kapital, angka, titik, atau tanda hubung).');
            }
            if ($name === '' || strlen($name) > 100 || !in_array($category, ['ps3', 'ps4', 'ps5', 'combo', 'tv'], true)) {
                throw new InvalidArgumentException('Nama dan kategori paket wajib diisi dengan benar.');
            }
            if ($badge === '' || strlen($badge) > 50 || strlen($highlight) > 100 || $description === '') {
                throw new InvalidArgumentException('Badge, deskripsi, atau tag highlight paket tidak valid.');
            }

            // Cek duplikasi kode paket di cabang yang sama
            $duplicate = $pdo->prepare(
                'SELECT 1 FROM paket_sewa WHERE id_cabang = :branch AND kode_paket = :code AND id_paket <> :id LIMIT 1'
            );
            $duplicate->execute(['branch' => $branchId, 'code' => $code, 'id' => $packageId]);
            if ($duplicate->fetchColumn()) {
                throw new InvalidArgumentException('Kode paket "' . $code . '" sudah digunakan di cabang ini.');
            }

            if ($isUpdate) {
                $exists = $pdo->prepare('SELECT kategori FROM paket_sewa WHERE id_paket = :id AND id_cabang = :branch');
                $exists->execute(['id' => $packageId, 'branch' => $branchId]);
                $oldCategory = $exists->fetchColumn();
                if (!$oldCategory) {
                    throw new InvalidArgumentException('Paket tidak ditemukan pada cabang ini.');
                }
            }

            // Validasi khusus berdasarkan kategori (combo vs non-combo sesuai 002_alokasi_unit.sql)
            $comboComponents = [];
            $stockToAdd = 0;
            $roomPrefix = 'Unit';

            if ($category === 'combo') {
                if ($isUpdate) {
                    $hasPhysical = $pdo->prepare('SELECT COUNT(*) FROM unit_ps WHERE id_paket = :id');
                    $hasPhysical->execute(['id' => $packageId]);
                    if ((int)$hasPhysical->fetchColumn() > 0) {
                        throw new InvalidArgumentException('Paket ini masih memiliki unit fisik terdaftar. Hapus unit fisiknya terlebih dahulu sebelum mengubah menjadi Combo.');
                    }
                }

                $rawChildIds = $_POST['komponen_id'] ?? [];
                $rawChildQty = $_POST['komponen_jumlah'] ?? [];
                if (is_array($rawChildIds) && is_array($rawChildQty)) {
                    foreach ($rawChildIds as $idx => $cidRaw) {
                        $cid = filter_var($cidRaw, FILTER_VALIDATE_INT);
                        $qty = filter_var($rawChildQty[$idx] ?? 0, FILTER_VALIDATE_INT);
                        if ($cid && $qty && $qty > 0) {
                            $comboComponents[$cid] = ($comboComponents[$cid] ?? 0) + $qty;
                        }
                    }
                }
                if (!$comboComponents) {
                    throw new InvalidArgumentException('Paket Combo wajib memiliki minimal 1 komponen unit (misalnya PS4 + TV LED).');
                }
            } else {
                $roomPrefix = trim((string)($_POST['nama_ruang'] ?? 'Unit'));
                $stockToAdd = filter_var($_POST['stok_tambah'] ?? 0, FILTER_VALIDATE_INT);
                if ($stockToAdd === false || $stockToAdd < 0 || $stockToAdd > 50 || (!$isUpdate && $stockToAdd < 1)) {
                    throw new InvalidArgumentException('Jumlah unit baru harus antara 1-50 untuk paket baru, dan 0-50 untuk paket yang sudah ada.');
                }
                if ($roomPrefix === '' || strlen($roomPrefix) > 80) {
                    throw new InvalidArgumentException('Nama ruang / lokasi wajib diisi (maksimal 80 karakter).');
                }
            }

            $pdo->beginTransaction();

            if ($isUpdate) {
                $save = $pdo->prepare(
                    'UPDATE paket_sewa
                     SET kode_paket = :code, nama_paket = :name, kategori = :category,
                         label_badge = :badge, tag_highlight = :highlight, deskripsi = :description,
                         harga_wk_12h = :wk12, harga_wk_24h = :wk24, harga_wn_12h = :wn12,
                         harga_wn_24h = :wn24, denda_per_jam = :denda, override_status = :override,
                         is_active = TRUE
                     WHERE id_paket = :id AND id_cabang = :branch'
                );
                $save->execute([
                    'code'        => $code,
                    'name'        => $name,
                    'category'    => $category,
                    'badge'       => $badge,
                    'highlight'   => $highlight !== '' ? $highlight : null,
                    'description' => $description,
                    'wk12'        => $prices['harga_wk_12h'],
                    'wk24'        => $prices['harga_wk_24h'],
                    'wn12'        => $prices['harga_wn_12h'],
                    'wn24'        => $prices['harga_wn_24h'],
                    'denda'       => $prices['denda_per_jam'],
                    'override'    => $override,
                    'id'          => $packageId,
                    'branch'      => $branchId,
                ]);
            } else {
                $save = $pdo->prepare(
                    'INSERT INTO paket_sewa
                     (id_cabang, kode_paket, nama_paket, kategori, label_badge, tag_highlight, deskripsi,
                      harga_wk_12h, harga_wk_24h, harga_wn_12h, harga_wn_24h, denda_per_jam, override_status)
                     VALUES (:branch, :code, :name, :category, :badge, :highlight, :description,
                      :wk12, :wk24, :wn12, :wn24, :denda, :override)
                     RETURNING id_paket'
                );
                $save->execute([
                    'branch'      => $branchId,
                    'code'        => $code,
                    'name'        => $name,
                    'category'    => $category,
                    'badge'       => $badge,
                    'highlight'   => $highlight !== '' ? $highlight : null,
                    'description' => $description,
                    'wk12'        => $prices['harga_wk_12h'],
                    'wk24'        => $prices['harga_wk_24h'],
                    'wn12'        => $prices['harga_wn_12h'],
                    'wn24'        => $prices['harga_wn_24h'],
                    'denda'       => $prices['denda_per_jam'],
                    'override'    => $override,
                ]);
                $packageId = (int)$save->fetchColumn();
            }

            // Sinkronisasi komponen jika Combo, atau tambah unit fisik jika Non-Combo
            if ($category === 'combo') {
                $pdo->prepare('DELETE FROM paket_komponen WHERE id_paket_parent = :id')
                    ->execute(['id' => $packageId]);

                $insComp = $pdo->prepare(
                    'INSERT INTO paket_komponen (id_paket_parent, id_paket_child, jumlah)
                     VALUES (:parent, :child, :qty)'
                );
                foreach ($comboComponents as $childId => $qty) {
                    if ($childId === $packageId) {
                        throw new InvalidArgumentException('Paket tidak dapat menjadi komponen bagi dirinya sendiri.');
                    }
                    $insComp->execute([
                        'parent' => $packageId,
                        'child'  => $childId,
                        'qty'    => $qty,
                    ]);
                }
            } else {
                // Bersihkan relasi komponen jika sebelumnya pernah bertipe combo
                $pdo->prepare('DELETE FROM paket_komponen WHERE id_paket_parent = :id')
                    ->execute(['id' => $packageId]);

                if ($stockToAdd > 0) {
                    $count = $pdo->prepare('SELECT COUNT(*) FROM unit_ps WHERE id_paket = :id');
                    $count->execute(['id' => $packageId]);
                    $firstNumber = (int)$count->fetchColumn() + 1;

                    $addUnit = $pdo->prepare(
                        "INSERT INTO unit_ps (id_cabang, id_paket, kode_barcode, nama_unit_ruang, status_unit)
                         VALUES (:branch, :package, :barcode, :room, 'tersedia')"
                    );
                    for ($i = 0; $i < $stockToAdd; $i++) {
                        $suffix = strtoupper(bin2hex(random_bytes(3)));
                        $addUnit->execute([
                            'branch'  => $branchId,
                            'package' => $packageId,
                            'barcode' => $code . '-' . $suffix,
                            'room'    => $roomPrefix . ' #' . ($firstNumber + $i),
                        ]);
                    }
                }
            }

            $pdo->commit();
            admin_flash($isUpdate ? 'Data paket & unit berhasil diperbarui.' : 'Paket / unit baru berhasil ditambahkan.');

            // 2. TAMBAH STOK UNIT FISIK CEPAT DARI MODAL KELOLA UNIT
        } elseif ($action === 'add_units') {
            $packageId  = filter_var($_POST['id_paket'] ?? 0, FILTER_VALIDATE_INT);
            $stockToAdd = filter_var($_POST['stok_tambah'] ?? 0, FILTER_VALIDATE_INT);
            $roomPrefix = trim((string)($_POST['nama_ruang'] ?? 'Unit'));

            if (!$packageId || !$stockToAdd || $stockToAdd < 1 || $stockToAdd > 50) {
                throw new InvalidArgumentException('Jumlah unit tambahan harus antara 1 sampai 50.');
            }
            if ($roomPrefix === '' || strlen($roomPrefix) > 80) {
                throw new InvalidArgumentException('Nama ruang wajib diisi.');
            }

            $pkgStmt = $pdo->prepare('SELECT kode_paket, kategori FROM paket_sewa WHERE id_paket = :id AND id_cabang = :branch');
            $pkgStmt->execute(['id' => $packageId, 'branch' => $branchId]);
            $pkg = $pkgStmt->fetch();
            if (!$pkg) {
                throw new InvalidArgumentException('Paket tidak ditemukan.');
            }
            if ($pkg['kategori'] === 'combo') {
                throw new InvalidArgumentException('Paket combo tidak memiliki unit fisik sendiri. Tambahkan stok pada paket komponennya.');
            }

            $pdo->beginTransaction();
            $count = $pdo->prepare('SELECT COUNT(*) FROM unit_ps WHERE id_paket = :id');
            $count->execute(['id' => $packageId]);
            $firstNumber = (int)$count->fetchColumn() + 1;

            $addUnit = $pdo->prepare(
                "INSERT INTO unit_ps (id_cabang, id_paket, kode_barcode, nama_unit_ruang, status_unit)
                 VALUES (:branch, :package, :barcode, :room, 'tersedia')"
            );
            for ($i = 0; $i < $stockToAdd; $i++) {
                $suffix = strtoupper(bin2hex(random_bytes(3)));
                $addUnit->execute([
                    'branch'  => $branchId,
                    'package' => $packageId,
                    'barcode' => $pkg['kode_paket'] . '-' . $suffix,
                    'room'    => $roomPrefix . ' #' . ($firstNumber + $i),
                ]);
            }
            $pdo->commit();
            admin_flash($stockToAdd . ' unit baru berhasil ditambahkan.');

            // 3. UBAH STATUS UNIT FISIK (TERSEDIA / MAINTENANCE / RUSAK)
        } elseif ($action === 'unit_status') {
            $unitId = filter_var($_POST['id_unit'] ?? 0, FILTER_VALIDATE_INT);
            $status = (string)($_POST['status_unit'] ?? '');
            $note   = trim((string)($_POST['catatan_kondisi'] ?? ''));

            if (!$unitId || !in_array($status, ['tersedia', 'maintenance', 'rusak'], true)) {
                throw new InvalidArgumentException('Status unit tidak valid.');
            }

            $save = $pdo->prepare(
                "UPDATE unit_ps
                 SET status_unit = :status, catatan_kondisi = :note
                 WHERE id_unit = :id AND id_cabang = :branch AND status_unit <> 'disewa'"
            );
            $save->execute([
                'status' => $status,
                'note'   => $status === 'tersedia' ? null : ($note !== '' ? $note : 'Ditandai oleh admin'),
                'id'     => $unitId,
                'branch' => $branchId,
            ]);
            if ($save->rowCount() !== 1) {
                throw new InvalidArgumentException('Unit tidak ditemukan atau sedang disewa pelanggan.');
            }
            admin_flash('Status fisik unit berhasil diperbarui.');

            // 4. UBAH OVERRIDE STATUS PAKET (AKTIF / MAINTENANCE)
        } elseif ($action === 'package_status') {
            $packageId = filter_var($_POST['id_paket'] ?? 0, FILTER_VALIDATE_INT);
            $override  = (string)($_POST['override_status'] ?? '');
            if (!$packageId || !in_array($override, ['auto', 'Maintenance'], true)) {
                throw new InvalidArgumentException('Status paket tidak valid.');
            }
            $save = $pdo->prepare('UPDATE paket_sewa SET override_status = :status WHERE id_paket = :id AND id_cabang = :branch');
            $save->execute(['status' => $override, 'id' => $packageId, 'branch' => $branchId]);
            admin_flash('Status ketersediaan paket diperbarui.');

            // 5. HAPUS / NONAKTIFKAN PAKET
        } elseif ($action === 'delete_package') {
            $packageId = filter_var($_POST['id_paket'] ?? 0, FILTER_VALIDATE_INT);
            if (!$packageId) {
                throw new InvalidArgumentException('ID paket tidak valid.');
            }

            // Pastikan tidak ada penyewaan aktif atau unit sedang disewa
            $activeRent = $pdo->prepare(
                "SELECT 1 FROM penyewaan
                 WHERE id_paket = :id AND id_cabang = :branch
                   AND status_sewa NOT IN ('selesai', 'ditolak', 'dibatalkan')
                 LIMIT 1"
            );
            $activeRent->execute(['id' => $packageId, 'branch' => $branchId]);
            if ($activeRent->fetchColumn()) {
                throw new InvalidArgumentException('Paket tidak dapat dihapus karena masih memiliki transaksi penyewaan yang sedang berjalan.');
            }

            $rentedUnit = $pdo->prepare(
                "SELECT 1 FROM unit_ps WHERE id_paket = :id AND id_cabang = :branch AND status_unit = 'disewa' LIMIT 1"
            );
            $rentedUnit->execute(['id' => $packageId, 'branch' => $branchId]);
            if ($rentedUnit->fetchColumn()) {
                throw new InvalidArgumentException('Masih ada unit fisik pada paket ini yang berstatus disewa.');
            }

            // Cek apakah pernah dipakai di riwayat transaksi atau menjadi bagian dari paket combo lain
            $hasHistory = $pdo->prepare('SELECT 1 FROM penyewaan WHERE id_paket = :id LIMIT 1');
            $hasHistory->execute(['id' => $packageId]);

            $usedInCombo = $pdo->prepare('SELECT 1 FROM paket_komponen WHERE id_paket_child = :id LIMIT 1');
            $usedInCombo->execute(['id' => $packageId]);

            if ($hasHistory->fetchColumn() || $usedInCombo->fetchColumn()) {
                // Soft-delete agar integritas riwayat transaksi tetap terjaga
                $pdo->prepare('UPDATE paket_sewa SET is_active = FALSE WHERE id_paket = :id AND id_cabang = :branch')
                    ->execute(['id' => $packageId, 'branch' => $branchId]);
                admin_flash('Paket dinonaktifkan dari katalog (riwayat transaksi tetap tersimpan).');
            } else {
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM unit_ps WHERE id_paket = :id AND id_cabang = :branch')
                    ->execute(['id' => $packageId, 'branch' => $branchId]);
                $pdo->prepare('DELETE FROM paket_sewa WHERE id_paket = :id AND id_cabang = :branch')
                    ->execute(['id' => $packageId, 'branch' => $branchId]);
                $pdo->commit();
                admin_flash('Paket dan unit terkait berhasil dihapus permanen.');
            }
        } else {
            throw new InvalidArgumentException('Aksi tidak dikenali.');
        }
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        admin_flash($exception->getMessage());
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Perubahan katalog unit-ps gagal: ' . $exception->getMessage());
        admin_flash('Gagal menyimpan perubahan: ' . $exception->getMessage());
    }

    header('Location: ' . $returnUrl);
    exit;
}

// AMBIL DATA STATISTIK, KATALOG PAKET, UNIT FISIK, & KOMPONEN COMBO
$packages         = [];
$unitsByPackage   = [];
$componentOptions = [];
$stats            = ['total' => 0, 'tersedia' => 0, 'disewa' => 0, 'perbaikan' => 0, 'total_paket' => 0];

if ($branchId) {
    $summary = $pdo->prepare(
        "SELECT
         COUNT(*) AS total,
         COUNT(*) FILTER (WHERE status_unit = 'tersedia') AS tersedia,
         COUNT(*) FILTER (WHERE status_unit = 'disewa') AS disewa,
         COUNT(*) FILTER (WHERE status_unit IN ('maintenance', 'rusak')) AS perbaikan
         FROM unit_ps WHERE id_cabang = :branch"
    );
    $summary->execute(['branch' => $branchId]);
    $stats = $summary->fetch();

    $pkgCount = $pdo->prepare('SELECT COUNT(*) FROM paket_sewa WHERE id_cabang = :branch AND is_active = TRUE');
    $pkgCount->execute(['branch' => $branchId]);
    $stats['total_paket'] = (int)$pkgCount->fetchColumn();

    // Query utama menggabungkan stok fisik (unit_ps), stok real-time (v_stok_paket), dan komponen combo
    $list = $pdo->prepare(
        "SELECT p.*,
                COALESCE(v.stok_tersedia, 0) AS stok_tersedia_realtime,
                COUNT(u.id_unit) AS total_unit,
                COUNT(u.id_unit) FILTER (WHERE u.status_unit = 'tersedia') AS unit_tersedia,
                COUNT(u.id_unit) FILTER (WHERE u.status_unit = 'disewa') AS unit_disewa,
                COUNT(u.id_unit) FILTER (WHERE u.status_unit IN ('maintenance', 'rusak')) AS unit_perbaikan,
                comp.komponen_teks,
                COALESCE(comp.komponen_json, '[]') AS komponen_json
         FROM paket_sewa p
         LEFT JOIN v_stok_paket v ON v.id_paket = p.id_paket
         LEFT JOIN unit_ps u ON u.id_paket = p.id_paket
         LEFT JOIN LATERAL (
             SELECT string_agg(pk.jumlah || 'x ' || cp.nama_paket, ' + ' ORDER BY cp.nama_paket) AS komponen_teks,
                    json_agg(json_build_object('id', pk.id_paket_child, 'nama', cp.nama_paket, 'jumlah', pk.jumlah))::text AS komponen_json
             FROM paket_komponen pk
             JOIN paket_sewa cp ON cp.id_paket = pk.id_paket_child
             WHERE pk.id_paket_parent = p.id_paket
         ) comp ON TRUE
         WHERE p.id_cabang = :branch AND p.is_active = TRUE
         GROUP BY p.id_paket, v.stok_tersedia, comp.komponen_teks, comp.komponen_json
         ORDER BY p.kategori, p.nama_paket"
    );
    $list->execute(['branch' => $branchId]);
    $packages = $list->fetchAll();

    // Daftar unit fisik dikelompokkan berdasarkan id_paket
    $unitList = $pdo->prepare(
        'SELECT id_unit, id_paket, kode_barcode, nama_unit_ruang, status_unit, catatan_kondisi
         FROM unit_ps
         WHERE id_cabang = :branch
         ORDER BY nama_unit_ruang, kode_barcode'
    );
    $unitList->execute(['branch' => $branchId]);
    foreach ($unitList->fetchAll() as $u) {
        $unitsByPackage[(int)$u['id_paket']][] = $u;
    }

    // Pilihan paket non-combo untuk menyusun paket combo
    $compStmt = $pdo->prepare(
        "SELECT id_paket, kode_paket, nama_paket, kategori
         FROM paket_sewa
         WHERE id_cabang = :branch AND is_active = TRUE AND kategori <> 'combo'
         ORDER BY kategori, nama_paket"
    );
    $compStmt->execute(['branch' => $branchId]);
    $componentOptions = $compStmt->fetchAll();
}

admin_page_header('Katalog Konsol & Paket', 'units', $branch, $branches, $branchId);
?>

<?php if ($branchError): ?>
    <div class="alert alert-warning rounded-4"><?= admin_escape($branchError) ?></div>
<?php else: ?>

    <!-- SUMMARY CARDS (Struktur dari unit-ps.html) -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat st-kuning">
                <div>
                    <small>TOTAL UNIT &amp; PAKET</small>
                    <h3 id="statTotalUnit"><?= (int)$stats['total'] ?> <span class="fs-6 fw-normal text-muted">/ <?= (int)$stats['total_paket'] ?> Paket</span></h3>
                </div>
                <span class="ico"><i class="bi bi-boxes"></i></span>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat st-hijau">
                <div>
                    <small>STOK TERSEDIA</small>
                    <h3 id="statTersedia"><?= (int)$stats['tersedia'] ?></h3>
                </div>
                <span class="ico"><i class="bi bi-check-circle-fill"></i></span>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat st-biru">
                <div>
                    <small>SEDANG DISEWA</small>
                    <h3 id="statDisewa"><?= (int)$stats['disewa'] ?></h3>
                </div>
                <span class="ico"><i class="bi bi-controller"></i></span>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat st-merah">
                <div>
                    <small>MAINTENANCE / RUSAK</small>
                    <h3 id="statPerbaikan"><?= (int)$stats['perbaikan'] ?></h3>
                </div>
                <span class="ico"><i class="bi bi-tools"></i></span>
            </div>
        </div>
    </div>

    <!-- MAIN CRUD CARD (Struktur dari unit-ps.html) -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="fw-bold text-dark m-0">Daftar Unit Konsol &amp; Combo Paket</h5>
                    <small class="text-muted">Kelola tipe konsol, kelengkapan, stok unit, serta tarif sewa</small>
                </div>
                <button type="button" class="btn btn-primary rounded-pill px-3 shadow-sm fw-bold" id="btnTambahUnit" data-bs-toggle="modal" data-bs-target="#modalUnitForm">
                    <i class="bi bi-plus-lg me-1"></i> Tambah Unit / Paket Baru
                </button>
            </div>

            <!-- Filter Chips Kategori -->
            <div class="d-flex flex-wrap gap-2 mb-3" id="filterKategori">
                <button type="button" class="chip btn-filter active" data-kat="all">Semua Unit</button>
                <button type="button" class="chip btn-filter" data-kat="ps4">PlayStation 4</button>
                <button type="button" class="chip btn-filter" data-kat="ps3">PlayStation 3</button>
                <button type="button" class="chip btn-filter" data-kat="combo">Paket Playbox + TV LED</button>
                <button type="button" class="chip btn-filter" data-kat="ps5">PlayStation 5</button>
                <button type="button" class="chip btn-filter" data-kat="tv">TV LED Only</button>
            </div>

            <!-- Tab Status, Pencarian, & Switch Weekend -->
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <ul class="nav nav-pills gap-1 flex-wrap" id="tabStatusUnit">
                    <li class="nav-item"><button type="button" class="nav-link active py-1 px-3 small" data-st="all">Semua</button></li>
                    <li class="nav-item"><button type="button" class="nav-link py-1 px-3 small" data-st="tersedia">Tersedia</button></li>
                    <li class="nav-item"><button type="button" class="nav-link py-1 px-3 small" data-st="habis">Habis / Disewa</button></li>
                    <li class="nav-item"><button type="button" class="nav-link py-1 px-3 small" data-st="maintenance">Maintenance</button></li>
                </ul>

                <div class="input-group input-group-sm w-auto flex-grow-1 cari-unit">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                    <input type="search" class="form-control border-start-0" id="cariUnit" placeholder="Cari unit, kode, kelengkapan...">
                </div>

                <div class="form-check form-switch ms-md-auto mb-0" title="Weekend = tarif Jumat-Minggu, selain itu tarif Weekday (Senin-Kamis)">
                    <input class="form-check-input" type="checkbox" id="checkWeekend">
                    <label class="form-check-label small fw-bold text-muted" for="checkWeekend">Weekend Aja</label>
                </div>
            </div>

            <!-- Tabel Paket & Unit -->
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="tabelUnit">
                    <thead class="table-light text-muted">
                        <tr>
                            <th>Unit &amp; Model</th>
                            <th>Kelengkapan</th>
                            <th class="text-end">Tarif 12 Jam <span class="badge bg-primary-subtle text-primary label-tarif-mode">Weekday</span></th>
                            <th class="text-end">Tarif 24 Jam <span class="badge bg-primary-subtle text-primary label-tarif-mode">Weekday</span></th>
                            <th class="text-center">Status / Stok</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyUnit">
                        <?php if (!$packages): ?>
                            <tr class="empty-server-row">
                                <td colspan="6" class="text-center text-muted py-4">Belum ada paket atau unit pada cabang ini. Klik <strong>Tambah Unit / Paket Baru</strong> untuk memulai.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($packages as $pkg):
                                $isCombo   = $pkg['kategori'] === 'combo';
                                $stokReady = (int)$pkg['stok_tersedia_realtime'];
                                $totalUnit = (int)$pkg['total_unit'];
                                $isMaint   = $pkg['override_status'] === 'Maintenance';

                                $rowStatus = $isMaint ? 'maintenance' : ($stokReady > 0 ? 'tersedia' : 'habis');
                                $statusBadgeClass = $isMaint
                                    ? 'bg-danger-subtle text-danger-emphasis'
                                    : ($stokReady > 0 ? 'bg-success-subtle text-success-emphasis' : 'bg-warning-subtle text-warning-emphasis');
                                $statusText = $isMaint
                                    ? 'Maintenance'
                                    : ($stokReady > 0 ? 'Tersedia' : 'Habis / Disewa');
                            ?>
                                <tr class="pkg-row"
                                    data-id="<?= (int)$pkg['id_paket'] ?>"
                                    data-kode="<?= admin_escape($pkg['kode_paket']) ?>"
                                    data-nama="<?= admin_escape($pkg['nama_paket']) ?>"
                                    data-kat="<?= admin_escape($pkg['kategori']) ?>"
                                    data-badge="<?= admin_escape($pkg['label_badge']) ?>"
                                    data-tag="<?= admin_escape($pkg['tag_highlight'] ?? '') ?>"
                                    data-deskripsi="<?= admin_escape($pkg['deskripsi'] ?? '') ?>"
                                    data-wk12="<?= (float)$pkg['harga_wk_12h'] ?>"
                                    data-wk24="<?= (float)$pkg['harga_wk_24h'] ?>"
                                    data-wn12="<?= (float)$pkg['harga_wn_12h'] ?>"
                                    data-wn24="<?= (float)$pkg['harga_wn_24h'] ?>"
                                    data-denda="<?= (float)$pkg['denda_per_jam'] ?>"
                                    data-override="<?= admin_escape($pkg['override_status']) ?>"
                                    data-status-filter="<?= $rowStatus ?>"
                                    data-komponen="<?= admin_escape($pkg['komponen_json']) ?>"
                                    data-search="<?= admin_escape(strtolower($pkg['kode_paket'] . ' ' . $pkg['nama_paket'] . ' ' . ($pkg['deskripsi'] ?? '') . ' ' . ($pkg['tag_highlight'] ?? '') . ' ' . ($pkg['komponen_teks'] ?? ''))) ?>">
                                    <td>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="badge bg-primary-subtle text-primary-emphasis"><?= admin_escape($pkg['label_badge']) ?></span>
                                            <small class="text-muted fw-semibold"><?= admin_escape($pkg['kode_paket']) ?></small>
                                        </div>
                                        <div class="fw-bold text-dark"><?= admin_escape($pkg['nama_paket']) ?></div>
                                        <?php if ($isCombo && $pkg['komponen_teks']): ?>
                                            <small class="text-primary d-block mt-1"><i class="bi bi-diagram-3 me-1"></i>Komponen: <?= admin_escape($pkg['komponen_teks']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-wrap" style="max-width: 280px;">
                                        <div class="small text-dark"><?= admin_escape($pkg['deskripsi'] ?? '-') ?></div>
                                        <?php if (!empty($pkg['tag_highlight'])): ?>
                                            <span class="badge bg-light text-secondary border mt-1"><?= admin_escape($pkg['tag_highlight']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap fw-semibold col-tarif-12"
                                        data-wk="<?= admin_escape(admin_money($pkg['harga_wk_12h'])) ?>"
                                        data-wn="<?= admin_escape(admin_money($pkg['harga_wn_12h'])) ?>">
                                        <?= admin_money($pkg['harga_wk_12h']) ?>
                                    </td>
                                    <td class="text-end text-nowrap fw-bold text-primary col-tarif-24"
                                        data-wk="<?= admin_escape(admin_money($pkg['harga_wk_24h'])) ?>"
                                        data-wn="<?= admin_escape(admin_money($pkg['harga_wn_24h'])) ?>">
                                        <?= admin_money($pkg['harga_wk_24h']) ?>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <span class="badge <?= $statusBadgeClass ?> rounded-pill px-3 py-1 d-inline-block mb-1"><?= $statusText ?></span>
                                        <div class="small text-muted">
                                            <?php if ($isCombo): ?>
                                                Stok Set: <strong><?= $stokReady ?></strong> tersedia
                                            <?php else: ?>
                                                Stok: <strong><?= $stokReady ?></strong> / <?= $totalUnit ?> unit
                                                <?php if ((int)$pkg['unit_perbaikan'] > 0): ?>
                                                    <span class="text-danger d-block">(<?= (int)$pkg['unit_perbaikan'] ?> perbaikan)</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <div class="d-inline-flex gap-1">
                                            <?php if (!$isCombo): ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill btn-kelola-unit"
                                                    title="Kelola Unit & Barcode"
                                                    data-bs-toggle="modal" data-bs-target="#modalKelolaUnit">
                                                    <i class="bi bi-hdd-stack me-1"></i>Unit (<?= $totalUnit ?>)
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill btn-edit-paket"
                                                title="Edit Paket"
                                                data-bs-toggle="modal" data-bs-target="#modalUnitForm">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger rounded-pill btn-hapus-paket"
                                                title="Hapus Paket"
                                                data-bs-toggle="modal" data-bs-target="#modalHapusUnit">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="emptyFilterRow" class="d-none">
                                <td colspan="6" class="text-center text-muted py-4">Tidak ada unit atau paket yang cocok dengan filter pencarian.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                <small class="text-muted" id="infoHal"></small>
                <ul class="pagination pagination-sm mb-0" id="paging"></ul>
            </div>
        </div>
    </div>

    <!-- MODAL 1: FORM CRUD UNIT / PAKET (TAMBAH & EDIT - dari unit-ps.html) -->
    <div class="modal fade" id="modalUnitForm" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold" id="modalUnitTitle">
                        <i class="bi bi-box-seam text-primary me-2"></i>Tambah Unit / Paket Baru
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <form method="post" action="<?= admin_escape($returnUrl) ?>" id="formUnit">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_package">
                    <input type="hidden" name="id_paket" id="unitIdInput" value="0">

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-muted" for="uKode">Kode Paket / Unit</label>
                                <input type="text" class="form-control" id="uKode" name="kode_paket" maxlength="50" placeholder="Contoh: PKT-PS4" required>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label fw-bold small text-muted" for="uNama">Nama Unit / Paket</label>
                                <input type="text" class="form-control" id="uNama" name="nama_paket" maxlength="100" placeholder="Contoh: PlayStation 4 Slim / Pro" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-muted" for="uKategori">Kategori Paket</label>
                                <select class="form-select" id="uKategori" name="kategori" required>
                                    <option value="ps4">PlayStation 4</option>
                                    <option value="ps3">PlayStation 3</option>
                                    <option value="ps5">PlayStation 5</option>
                                    <option value="combo">Paket Playbox + TV LED (Combo)</option>
                                    <option value="tv">TV LED Only</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-muted" for="uBadge">Label Badge Katalog</label>
                                <input type="text" class="form-control" id="uBadge" name="label_badge" maxlength="50" list="badgeSuggestions" value="KONSOL ONLY" required>
                                <datalist id="badgeSuggestions">
                                    <option value="KONSOL ONLY">
                                    <option value="BEST VALUE">
                                    <option value="PAKET LENGKAP">
                                    <option value="TV LED ONLY">
                                </datalist>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-muted" for="uTag">Tag Highlight (Opsional)</label>
                                <input type="text" class="form-control" id="uTag" name="tag_highlight" maxlength="100" placeholder="Contoh: 4 Stik Included">
                            </div>

                            <div class="col-md-12">
                                <label class="form-label fw-bold small text-muted" for="uDeskripsi">Kelengkapan / Deskripsi</label>
                                <input type="text" class="form-control" id="uDeskripsi" name="deskripsi" placeholder="Contoh: Include 4 Stik, Full Game, Kabel HDMI & Power" required>
                            </div>

                            <!-- TARIF WEEKDAY -->
                            <div class="col-md-6">
                                <div class="p-3 bg-light rounded-3 border">
                                    <span class="badge bg-primary mb-2">Tarif Weekday (Senin - Kamis)</span>
                                    <div class="mb-2">
                                        <label class="form-label small text-muted mb-1" for="uWk12">Sewa 12 Jam (Rp)</label>
                                        <input type="number" class="form-control" id="uWk12" name="harga_wk_12h" min="0" step="1000" placeholder="40000" required>
                                    </div>
                                    <div>
                                        <label class="form-label small text-muted mb-1" for="uWk24">Sewa 24 Jam (Rp)</label>
                                        <input type="number" class="form-control" id="uWk24" name="harga_wk_24h" min="0" step="1000" placeholder="70000" required>
                                    </div>
                                </div>
                            </div>

                            <!-- TARIF WEEKEND -->
                            <div class="col-md-6">
                                <div class="p-3 bg-light rounded-3 border">
                                    <span class="badge bg-warning text-dark mb-2">Tarif Weekend (Jumat - Minggu)</span>
                                    <div class="mb-2">
                                        <label class="form-label small text-muted mb-1" for="uWn12">Sewa 12 Jam (Rp)</label>
                                        <input type="number" class="form-control" id="uWn12" name="harga_wn_12h" min="0" step="1000" placeholder="50000" required>
                                    </div>
                                    <div>
                                        <label class="form-label small text-muted mb-1" for="uWn24">Sewa 24 Jam (Rp)</label>
                                        <input type="number" class="form-control" id="uWn24" name="harga_wn_24h" min="0" step="1000" placeholder="85000" required>
                                    </div>
                                </div>
                            </div>

                            <!-- BAGIAN UNIT FISIK (Untuk Kategori Non-Combo) -->
                            <div class="col-12" id="secNonCombo">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small text-muted" for="uStokTambah" id="lblStokTambah">Jumlah Unit Awal</label>
                                        <input type="number" class="form-control" id="uStokTambah" name="stok_tambah" min="1" max="50" value="1">
                                        <small class="text-muted">Otomatis dibuatkan barcode unik.</small>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small text-muted" for="uRuang">Nama Ruang / Prefix Unit</label>
                                        <input type="text" class="form-control" id="uRuang" name="nama_ruang" maxlength="80" value="Ruang">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small text-muted" for="uDenda">Denda Terlambat / Jam (Rp)</label>
                                        <input type="number" class="form-control" id="uDenda" name="denda_per_jam" min="0" step="1000" value="10000" required>
                                    </div>
                                </div>
                            </div>

                            <!-- BAGIAN KOMPONEN COMBO (Sesuai 002_alokasi_unit.sql) -->
                            <div class="col-12 d-none" id="secCombo">
                                <div class="p-3 bg-primary bg-opacity-10 border border-primary-subtle rounded-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <span class="fw-bold text-primary small d-block"><i class="bi bi-diagram-3 me-1"></i>Komponen Paket Combo</span>
                                            <small class="text-muted">Stok paket combo dihitung otomatis dari ketersediaan unit komponennya.</small>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-primary rounded-pill" id="btnTambahKomponen">
                                            <i class="bi bi-plus-lg me-1"></i>Tambah Komponen
                                        </button>
                                    </div>
                                    <div id="daftarKomponenCombo" class="d-flex flex-column gap-2"></div>
                                    <div class="mt-3 col-md-5">
                                        <label class="form-label fw-bold small text-muted" for="uDendaCombo">Denda Terlambat / Jam (Rp)</label>
                                        <input type="number" class="form-control form-control-sm" id="uDendaCombo" min="0" step="1000" value="15000">
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-muted" for="uStatus">Status Ketersediaan Paket</label>
                                <select class="form-select" id="uStatus" name="override_status">
                                    <option value="auto">Aktif (Sesuai Stok Tersedia)</option>
                                    <option value="Maintenance">Maintenance (Tutup Sementara)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer border-top-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary px-4 rounded-pill fw-bold" id="btnSimpanUnit">Simpan Paket</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL 2: KELOLA UNIT FISIK & BARCODE PER PAKET -->
    <div class="modal fade" id="modalKelolaUnit" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-bottom-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold">Kelola Unit Fisik: <span id="kuNamaPaket" class="text-primary"></span></h5>
                        <small class="text-muted">Ubah status kondisi unit atau tambahkan unit baru dengan barcode otomatis.</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <!-- Form Tambah Unit Cepat -->
                    <form method="post" action="<?= admin_escape($returnUrl) ?>" class="row g-2 align-items-end bg-light p-3 rounded-3 mb-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_units">
                        <input type="hidden" name="id_paket" id="kuAddPaketId" value="">
                        <div class="col-sm-4">
                            <label class="form-label small fw-bold text-muted mb-1">Tambah Jumlah Unit</label>
                            <input type="number" class="form-control form-control-sm" name="stok_tambah" min="1" max="50" value="1" required>
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label small fw-bold text-muted mb-1">Label Ruang / Unit</label>
                            <input type="text" class="form-control form-control-sm" name="nama_ruang" value="Ruang" maxlength="80" required>
                        </div>
                        <div class="col-sm-3">
                            <button type="submit" class="btn btn-sm btn-primary w-100 rounded-pill fw-semibold">
                                <i class="bi bi-plus-lg me-1"></i>Tambah Unit
                            </button>
                        </div>
                    </form>

                    <!-- Daftar Unit Fisik -->
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Barcode</th>
                                    <th>Ruang / Label</th>
                                    <th>Status Saat Ini</th>
                                    <th>Ubah Kondisi</th>
                                </tr>
                            </thead>
                            <tbody id="kuTbody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 3: KONFIRMASI HAPUS UNIT / PAKET (dari unit-ps.html) -->
    <div class="modal fade" id="modalHapusUnit" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content rounded-4 border-0">
                <form method="post" action="<?= admin_escape($returnUrl) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_package">
                    <input type="hidden" name="id_paket" id="delPaketId" value="">
                    <div class="modal-body text-center p-4">
                        <i class="bi bi-exclamation-triangle-fill text-danger display-4 d-block mb-3"></i>
                        <h5 class="fw-bold mb-2">Hapus Paket Ini?</h5>
                        <p class="text-muted small mb-4">Paket <strong id="delPaketNama"></strong> akan dihapus atau dinonaktifkan jika sudah memiliki riwayat transaksi.</p>
                        <div class="d-flex justify-content-center gap-2">
                            <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-danger rounded-pill px-4">Hapus</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const unitsByPackage = <?= json_encode($unitsByPackage, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const componentOptions = <?= json_encode($componentOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const returnUrl = <?= json_encode($returnUrl) ?>;
        const csrfHtml = <?= json_encode(csrf_field()) ?>;

        // 1. FILTER KATEGORI, STATUS TAB, PENCARIAN, & PAGINATION
        const allRows = Array.from(document.querySelectorAll('#tbodyUnit tr.pkg-row'));
        const emptyRow = document.getElementById('emptyFilterRow');
        const infoHal = document.getElementById('infoHal');
        const pagingEl = document.getElementById('paging');
        const searchInput = document.getElementById('cariUnit');
        let activeKat = 'all';
        let activeStatus = 'all';
        let currentPage = 1;
        const perPage = 10;

        function renderTable() {
            const q = (searchInput?.value || '').trim().toLowerCase();
            const filtered = allRows.filter(tr => {
                const matchKat = activeKat === 'all' || tr.dataset.kat === activeKat;
                const matchSt = activeStatus === 'all' || tr.dataset.statusFilter === activeStatus;
                const matchQ = !q || (tr.dataset.search || '').includes(q);
                return matchKat && matchSt && matchQ;
            });

            const totalItems = filtered.length;
            const totalPages = Math.max(1, Math.ceil(totalItems / perPage));
            if (currentPage > totalPages) currentPage = totalPages;

            const startIdx = (currentPage - 1) * perPage;
            const endIdx = startIdx + perPage;

            allRows.forEach(tr => tr.classList.add('d-none'));
            filtered.slice(startIdx, endIdx).forEach(tr => tr.classList.remove('d-none'));

            if (emptyRow) {
                emptyRow.classList.toggle('d-none', totalItems > 0);
            }
            if (infoHal) {
                infoHal.textContent = totalItems === 0 ?
                    'Menampilkan 0 data' :
                    `Menampilkan ${startIdx + 1}–${Math.min(endIdx, totalItems)} dari ${totalItems} paket`;
            }
            if (pagingEl) {
                pagingEl.innerHTML = '';
                if (totalPages > 1) {
                    for (let p = 1; p <= totalPages; p++) {
                        const li = document.createElement('li');
                        li.className = 'page-item' + (p === currentPage ? ' active' : '');
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'page-link';
                        btn.textContent = p;
                        btn.addEventListener('click', () => {
                            currentPage = p;
                            renderTable();
                        });
                        li.appendChild(btn);
                        pagingEl.appendChild(li);
                    }
                }
            }
        }

        document.querySelectorAll('#filterKategori .btn-filter').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('#filterKategori .btn-filter').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                activeKat = btn.dataset.kat || 'all';
                currentPage = 1;
                renderTable();
            });
        });

        document.querySelectorAll('#tabStatusUnit .nav-link').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('#tabStatusUnit .nav-link').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                activeStatus = btn.dataset.st || 'all';
                currentPage = 1;
                renderTable();
            });
        });

        searchInput?.addEventListener('input', () => {
            currentPage = 1;
            renderTable();
        });

        renderTable();

        // 2. SAKELAR TARIF WEEKEND AJA
        const checkWeekend = document.getElementById('checkWeekend');
        checkWeekend?.addEventListener('change', () => {
            const isWn = checkWeekend.checked;
            document.querySelectorAll('.label-tarif-mode').forEach(badge => {
                badge.textContent = isWn ? 'Weekend' : 'Weekday';
                badge.className = 'badge label-tarif-mode ' + (isWn ? 'bg-warning-subtle text-warning-emphasis' : 'bg-primary-subtle text-primary');
            });
            document.querySelectorAll('.col-tarif-12, .col-tarif-24').forEach(td => {
                td.textContent = isWn ? td.dataset.wn : td.dataset.wk;
            });
        });

        // 3. LOGIKA FORM MODAL TAMBAH / EDIT PAKET (COMBO VS NON-COMBO)
        const selKategori = document.getElementById('uKategori');
        const secNonCombo = document.getElementById('secNonCombo');
        const secCombo = document.getElementById('secCombo');
        const inpStokTambah = document.getElementById('uStokTambah');
        const inpDenda = document.getElementById('uDenda');
        const inpDendaCombo = document.getElementById('uDendaCombo');
        const daftarKompEl = document.getElementById('daftarKomponenCombo');

        function addKomponenRow(selectedId = '', qty = 1) {
            const row = document.createElement('div');
            row.className = 'd-flex gap-2 align-items-center';

            const select = document.createElement('select');
            select.name = 'komponen_id[]';
            select.className = 'form-select form-select-sm';
            select.required = true;

            const defaultOpt = document.createElement('option');
            defaultOpt.value = '';
            defaultOpt.textContent = '-- Pilih Paket Komponen --';
            select.appendChild(defaultOpt);

            componentOptions.forEach(opt => {
                const o = document.createElement('option');
                o.value = opt.id_paket;
                o.textContent = `${opt.nama_paket} (${opt.kode_paket})`;
                if (String(opt.id_paket) === String(selectedId)) o.selected = true;
                select.appendChild(o);
            });

            const inputQty = document.createElement('input');
            inputQty.type = 'number';
            inputQty.name = 'komponen_jumlah[]';
            inputQty.className = 'form-control form-control-sm';
            inputQty.style.maxWidth = '90px';
            inputQty.min = '1';
            inputQty.max = '10';
            inputQty.value = qty;
            inputQty.required = true;

            const btnDel = document.createElement('button');
            btnDel.type = 'button';
            btnDel.className = 'btn btn-sm btn-outline-danger';
            btnDel.innerHTML = '<i class="bi bi-x-lg"></i>';
            btnDel.addEventListener('click', () => row.remove());

            row.append(select, inputQty, btnDel);
            daftarKompEl.appendChild(row);
        }

        document.getElementById('btnTambahKomponen')?.addEventListener('click', () => addKomponenRow());

        function toggleCategorySection() {
            const isCombo = selKategori.value === 'combo';
            secCombo.classList.toggle('d-none', !isCombo);
            secNonCombo.classList.toggle('d-none', isCombo);
            inpStokTambah.required = !isCombo && document.getElementById('unitIdInput').value === '0';

            if (isCombo) {
                inpDenda.removeAttribute('name');
                inpDendaCombo.setAttribute('name', 'denda_per_jam');
                if (daftarKompEl.children.length === 0) addKomponenRow();
            } else {
                inpDendaCombo.removeAttribute('name');
                inpDenda.setAttribute('name', 'denda_per_jam');
            }
        }
        selKategori?.addEventListener('change', toggleCategorySection);

        // Tombol Tambah Baru
        document.getElementById('btnTambahUnit')?.addEventListener('click', () => {
            document.getElementById('modalUnitTitle').innerHTML = '<i class="bi bi-box-seam text-primary me-2"></i>Tambah Unit / Paket Baru';
            document.getElementById('unitIdInput').value = '0';
            document.getElementById('formUnit').reset();
            document.getElementById('lblStokTambah').textContent = 'Jumlah Unit Awal';
            inpStokTambah.min = '1';
            inpStokTambah.value = '1';
            daftarKompEl.innerHTML = '';
            toggleCategorySection();
        });

        // Tombol Edit Paket
        document.querySelectorAll('.btn-edit-paket').forEach(btn => {
            btn.addEventListener('click', () => {
                const tr = btn.closest('tr');
                document.getElementById('modalUnitTitle').innerHTML = '<i class="bi bi-pencil-square text-primary me-2"></i>Edit Unit / Paket';
                document.getElementById('unitIdInput').value = tr.dataset.id;
                document.getElementById('uKode').value = tr.dataset.kode;
                document.getElementById('uNama').value = tr.dataset.nama;
                selKategori.value = tr.dataset.kat;
                document.getElementById('uBadge').value = tr.dataset.badge;
                document.getElementById('uTag').value = tr.dataset.tag;
                document.getElementById('uDeskripsi').value = tr.dataset.deskripsi;
                document.getElementById('uWk12').value = tr.dataset.wk12;
                document.getElementById('uWk24').value = tr.dataset.wk24;
                document.getElementById('uWn12').value = tr.dataset.wn12;
                document.getElementById('uWn24').value = tr.dataset.wn24;
                inpDenda.value = tr.dataset.denda;
                inpDendaCombo.value = tr.dataset.denda;
                document.getElementById('uStatus').value = tr.dataset.override;

                document.getElementById('lblStokTambah').textContent = 'Tambah Unit Baru (Opsional)';
                inpStokTambah.min = '0';
                inpStokTambah.value = '0';

                daftarKompEl.innerHTML = '';
                if (tr.dataset.kat === 'combo') {
                    try {
                        const comps = JSON.parse(tr.dataset.komponen || '[]');
                        comps.forEach(c => addKomponenRow(c.id, c.jumlah));
                    } catch (e) {}
                }
                toggleCategorySection();
            });
        });

        // 4. MODAL KELOLA UNIT FISIK & BARCODE
        document.querySelectorAll('.btn-kelola-unit').forEach(btn => {
            btn.addEventListener('click', () => {
                const tr = btn.closest('tr');
                const pkgId = tr.dataset.id;
                document.getElementById('kuNamaPaket').textContent = tr.dataset.nama;
                document.getElementById('kuAddPaketId').value = pkgId;

                const tbody = document.getElementById('kuTbody');
                tbody.innerHTML = '';
                const units = unitsByPackage[pkgId] || [];

                if (units.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">Belum ada unit fisik terdaftar pada paket ini.</td></tr>';
                    return;
                }

                units.forEach(u => {
                    const row = document.createElement('tr');
                    const badgeClass = u.status_unit === 'tersedia' ?
                        'bg-success' :
                        (u.status_unit === 'disewa' ? 'bg-primary' : 'bg-danger');

                    let actionCell = '<span class="text-muted small">Sedang disewa pelanggan</span>';
                    if (u.status_unit !== 'disewa') {
                        actionCell = `
                        <form method="post" action="${returnUrl}" class="d-flex gap-1 align-items-center">
                            ${csrfHtml}
                            <input type="hidden" name="action" value="unit_status">
                            <input type="hidden" name="id_unit" value="${u.id_unit}">
                            <select name="status_unit" class="form-select form-select-sm" style="width:auto;">
                                <option value="tersedia" ${u.status_unit === 'tersedia' ? 'selected' : ''}>Tersedia</option>
                                <option value="maintenance" ${u.status_unit === 'maintenance' ? 'selected' : ''}>Maintenance</option>
                                <option value="rusak" ${u.status_unit === 'rusak' ? 'selected' : ''}>Rusak</option>
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary">Simpan</button>
                        </form>
                    `;
                    }

                    row.innerHTML = `
                    <td class="fw-semibold">${u.kode_barcode}</td>
                    <td>${u.nama_unit_ruang}</td>
                    <td><span class="badge ${badgeClass} rounded-pill">${u.status_unit.toUpperCase()}</span></td>
                    <td>${actionCell}</td>
                `;
                    tbody.appendChild(row);
                });
            });
        });

        // 5. MODAL KONFIRMASI HAPUS PAKET
        document.querySelectorAll('.btn-hapus-paket').forEach(btn => {
            btn.addEventListener('click', () => {
                const tr = btn.closest('tr');
                document.getElementById('delPaketId').value = tr.dataset.id;
                document.getElementById('delPaketNama').textContent = tr.dataset.nama;
            });
        });
    });
</script>

<?php admin_page_footer(); ?>
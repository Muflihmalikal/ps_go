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
        if ($action === 'save_package') {
            $packageId = filter_var($_POST['id_paket'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $isUpdate = $packageId > 0;
            $code = strtoupper(trim((string)($_POST['kode_paket'] ?? '')));
            $name = trim((string)($_POST['nama_paket'] ?? ''));
            $category = (string)($_POST['kategori'] ?? '');
            $badge = trim((string)($_POST['label_badge'] ?? ''));
            $highlight = trim((string)($_POST['tag_highlight'] ?? ''));
            $description = trim((string)($_POST['deskripsi'] ?? ''));
            $roomPrefix = trim((string)($_POST['nama_ruang'] ?? 'Unit'));
            $stockToAdd = filter_var($_POST['stok_tambah'] ?? 0, FILTER_VALIDATE_INT);
            $prices = [];
            foreach (['harga_wk_12h', 'harga_wk_24h', 'harga_wn_12h', 'harga_wn_24h'] as $field) {
                $value = filter_var($_POST[$field] ?? null, FILTER_VALIDATE_FLOAT);
                if ($value === false || $value < 0 || $value > 99999999) {
                    throw new InvalidArgumentException('Tarif harus berupa angka yang tidak negatif.');
                }
                $prices[$field] = $value;
            }

            if ($code === '' || strlen($code) > 40 || !preg_match('/^[A-Z0-9._-]+$/', $code)) {
                throw new InvalidArgumentException('Kode paket wajib diisi (maksimal 40 karakter; gunakan huruf, angka, titik, atau tanda hubung).');
            }
            if ($name === '' || strlen($name) > 100 || !in_array($category, ['ps3', 'ps4', 'ps5', 'combo', 'tv'], true)) {
                throw new InvalidArgumentException('Nama dan kategori paket wajib diisi dengan benar.');
            }
            if ($badge === '' || strlen($badge) > 50 || strlen($highlight) > 100 || $description === '') {
                throw new InvalidArgumentException('Badge, deskripsi, atau tag paket tidak valid.');
            }
            if ($stockToAdd === false || $stockToAdd < 0 || $stockToAdd > 50 || ($packageId === 0 && $stockToAdd < 1)) {
                throw new InvalidArgumentException('Jumlah unit yang ditambahkan harus antara 1-50 untuk paket baru, dan 0-50 untuk paket yang sudah ada.');
            }
            if ($roomPrefix === '' || strlen($roomPrefix) > 80) {
                throw new InvalidArgumentException('Nama ruang wajib diisi (maksimal 80 karakter).');
            }

            $duplicate = $pdo->prepare(
                'SELECT 1 FROM paket_sewa WHERE id_cabang = :branch AND kode_paket = :code AND id_paket <> :id LIMIT 1'
            );
            $duplicate->execute(['branch' => $branchId, 'code' => $code, 'id' => $packageId]);
            if ($duplicate->fetchColumn()) {
                throw new InvalidArgumentException('Kode paket tersebut sudah digunakan di cabang ini.');
            }

            if ($packageId > 0) {
                $exists = $pdo->prepare('SELECT 1 FROM paket_sewa WHERE id_paket = :id AND id_cabang = :branch');
                $exists->execute(['id' => $packageId, 'branch' => $branchId]);
                if (!$exists->fetchColumn()) {
                    throw new InvalidArgumentException('Paket tidak ditemukan pada cabang ini.');
                }
            }

            $pdo->beginTransaction();
            if ($packageId > 0) {
                $save = $pdo->prepare(
                    'UPDATE paket_sewa SET kode_paket = :code, nama_paket = :name, kategori = :category,
                     label_badge = :badge, tag_highlight = :highlight, deskripsi = :description,
                     harga_wk_12h = :wk12, harga_wk_24h = :wk24, harga_wn_12h = :wn12,
                     harga_wn_24h = :wn24, override_status = :override
                     WHERE id_paket = :id AND id_cabang = :branch'
                );
                $save->execute([
                    'code' => $code, 'name' => $name, 'category' => $category, 'badge' => $badge,
                    'highlight' => $highlight !== '' ? $highlight : null, 'description' => $description,
                    'wk12' => $prices['harga_wk_12h'], 'wk24' => $prices['harga_wk_24h'],
                    'wn12' => $prices['harga_wn_12h'], 'wn24' => $prices['harga_wn_24h'],
                    'override' => isset($_POST['maintenance']) ? 'Maintenance' : 'auto',
                    'id' => $packageId, 'branch' => $branchId,
                ]);
            } else {
                $save = $pdo->prepare(
                    'INSERT INTO paket_sewa
                     (id_cabang, kode_paket, nama_paket, kategori, label_badge, tag_highlight, deskripsi,
                      harga_wk_12h, harga_wk_24h, harga_wn_12h, harga_wn_24h, override_status)
                     VALUES (:branch, :code, :name, :category, :badge, :highlight, :description,
                      :wk12, :wk24, :wn12, :wn24, :override) RETURNING id_paket'
                );
                $save->execute([
                    'branch' => $branchId, 'code' => $code, 'name' => $name, 'category' => $category,
                    'badge' => $badge, 'highlight' => $highlight !== '' ? $highlight : null,
                    'description' => $description, 'wk12' => $prices['harga_wk_12h'],
                    'wk24' => $prices['harga_wk_24h'], 'wn12' => $prices['harga_wn_12h'],
                    'wn24' => $prices['harga_wn_24h'], 'override' => 'auto',
                ]);
                $packageId = (int)$save->fetchColumn();
            }

            if ($stockToAdd > 0) {
                $count = $pdo->prepare('SELECT COUNT(*) FROM unit_ps WHERE id_paket = :id');
                $count->execute(['id' => $packageId]);
                $firstNumber = (int)$count->fetchColumn() + 1;
                $addUnit = $pdo->prepare(
                    "INSERT INTO unit_ps (id_cabang, id_paket, kode_barcode, nama_unit_ruang, status_unit)
                     VALUES (:branch, :package, :barcode, :room, 'tersedia')"
                );
                for ($i = 0; $i < $stockToAdd; $i++) {
                    $suffix = strtoupper(bin2hex(random_bytes(4)));
                    $addUnit->execute([
                        'branch' => $branchId,
                        'package' => $packageId,
                        'barcode' => $code . '-' . $suffix,
                        'room' => $roomPrefix . ' #' . ($firstNumber + $i),
                    ]);
                }
            }
            $pdo->commit();
            admin_flash($isUpdate ? 'Paket berhasil disimpan dan stok unit diperbarui.' : 'Paket berhasil dibuat.');
        } elseif ($action === 'unit_status') {
            $unitId = filter_var($_POST['id_unit'] ?? 0, FILTER_VALIDATE_INT);
            $status = (string)($_POST['status_unit'] ?? '');
            if (!$unitId || !in_array($status, ['tersedia', 'maintenance', 'rusak'], true)) {
                throw new InvalidArgumentException('Status unit tidak valid.');
            }
            $save = $pdo->prepare(
                "UPDATE unit_ps SET status_unit = :status
                 WHERE id_unit = :id AND id_cabang = :branch AND status_unit <> 'disewa'"
            );
            $save->execute(['status' => $status, 'id' => $unitId, 'branch' => $branchId]);
            if ($save->rowCount() !== 1) {
                throw new InvalidArgumentException('Unit tidak ditemukan atau sedang disewa, sehingga status tidak dapat diubah.');
            }
            admin_flash('Status unit berhasil diperbarui.');
        } elseif ($action === 'package_status') {
            $packageId = filter_var($_POST['id_paket'] ?? 0, FILTER_VALIDATE_INT);
            $override = (string)($_POST['override_status'] ?? '');
            if (!$packageId || !in_array($override, ['auto', 'Maintenance'], true)) {
                throw new InvalidArgumentException('Status paket tidak valid.');
            }
            $save = $pdo->prepare('UPDATE paket_sewa SET override_status = :status WHERE id_paket = :id AND id_cabang = :branch');
            $save->execute(['status' => $override, 'id' => $packageId, 'branch' => $branchId]);
            if ($save->rowCount() !== 1) {
                throw new InvalidArgumentException('Paket tidak ditemukan pada cabang ini.');
            }
            admin_flash('Ketersediaan paket berhasil diperbarui.');
        } else {
            throw new InvalidArgumentException('Aksi tidak dikenali.');
        }
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_flash($exception->getMessage());
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Perubahan katalog admin gagal: ' . $exception->getMessage());
        admin_flash('Perubahan gagal disimpan. Periksa apakah kode paket/barcode sudah digunakan, lalu coba lagi.');
    }

    header('Location: ' . $returnUrl);
    exit;
}

$editPackage = null;
$editId = filter_input(INPUT_GET, 'edit_id', FILTER_VALIDATE_INT);
if ($branchId && $editId) {
    $statement = $pdo->prepare('SELECT * FROM paket_sewa WHERE id_paket = :id AND id_cabang = :branch');
    $statement->execute(['id' => $editId, 'branch' => $branchId]);
    $editPackage = $statement->fetch() ?: null;
}

$category = (string)($_GET['kategori'] ?? 'all');
$search = trim((string)($_GET['q'] ?? ''));
$where = ['p.id_cabang = :branch'];
$parameters = ['branch' => $branchId];
if (in_array($category, ['ps3', 'ps4', 'ps5', 'combo', 'tv'], true)) {
    $where[] = 'p.kategori = :category';
    $parameters['category'] = $category;
} else {
    $category = 'all';
}
if ($search !== '') {
    $where[] = '(p.nama_paket ILIKE :search OR p.kode_paket ILIKE :search OR p.deskripsi ILIKE :search)';
    $parameters['search'] = '%' . $search . '%';
}
$packages = [];
$unitsByPackage = [];
$stats = ['total' => 0, 'tersedia' => 0, 'disewa' => 0, 'perbaikan' => 0];
if ($branchId) {
    $summary = $pdo->prepare(
        "SELECT COUNT(*) AS total,
         COUNT(*) FILTER (WHERE status_unit = 'tersedia') AS tersedia,
         COUNT(*) FILTER (WHERE status_unit = 'disewa') AS disewa,
         COUNT(*) FILTER (WHERE status_unit IN ('maintenance', 'rusak')) AS perbaikan
         FROM unit_ps WHERE id_cabang = :branch"
    );
    $summary->execute(['branch' => $branchId]);
    $stats = $summary->fetch();
    $list = $pdo->prepare(
        'SELECT p.*,
         COUNT(u.id_unit) AS total_unit,
         COUNT(u.id_unit) FILTER (WHERE u.status_unit = \'tersedia\') AS tersedia,
         COUNT(u.id_unit) FILTER (WHERE u.status_unit = \'disewa\') AS disewa,
         COUNT(u.id_unit) FILTER (WHERE u.status_unit IN (\'maintenance\', \'rusak\')) AS perbaikan
         FROM paket_sewa p LEFT JOIN unit_ps u ON u.id_paket = p.id_paket
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY p.id_paket ORDER BY p.nama_paket'
    );
    $list->execute($parameters);
    $packages = $list->fetchAll();

    $unitList = $pdo->prepare(
        'SELECT id_unit, id_paket, kode_barcode, nama_unit_ruang, status_unit, catatan_kondisi
         FROM unit_ps WHERE id_cabang = :branch ORDER BY nama_unit_ruang, kode_barcode'
    );
    $unitList->execute(['branch' => $branchId]);
    foreach ($unitList->fetchAll() as $unit) {
        $unitsByPackage[$unit['id_paket']][] = $unit;
    }
}

admin_page_header('Kelola Unit & Paket', 'units', $branch, $branches, $branchId);
?>
<?php if ($branchError): ?>
    <div class="alert alert-warning"><?= admin_escape($branchError) ?></div>
<?php else: ?>
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm rounded-4 p-3"><small class="text-muted">TOTAL UNIT</small><strong class="fs-3"><?= (int)$stats['total'] ?></strong></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm rounded-4 p-3"><small class="text-muted">TERSEDIA</small><strong class="fs-3 text-success"><?= (int)$stats['tersedia'] ?></strong></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm rounded-4 p-3"><small class="text-muted">DISEWA</small><strong class="fs-3 text-primary"><?= (int)$stats['disewa'] ?></strong></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm rounded-4 p-3"><small class="text-muted">MAINTENANCE / RUSAK</small><strong class="fs-3 text-danger"><?= (int)$stats['perbaikan'] ?></strong></div></div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h2 class="h5 fw-bold mb-1"><?= $editPackage ? 'Ubah Paket Rental' : 'Tambah Paket Rental' ?></h2>
                    <small class="text-muted">Perubahan tarif dan stok akan langsung tersimpan di database.</small>
                </div>
                <?php if ($editPackage): ?><a class="btn btn-outline-secondary btn-sm" href="unit-ps.php<?= admin_branch_query($branchId) ?>">Paket baru</a><?php endif; ?>
            </div>
            <form method="post" action="<?= admin_escape($returnUrl) ?>" class="row g-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_package">
                <input type="hidden" name="id_paket" value="<?= (int)($editPackage['id_paket'] ?? 0) ?>">
                <div class="col-md-3"><label class="form-label" for="kode">Kode paket</label><input class="form-control" id="kode" name="kode_paket" maxlength="40" required value="<?= admin_escape($editPackage['kode_paket'] ?? '') ?>"></div>
                <div class="col-md-5"><label class="form-label" for="nama">Nama paket</label><input class="form-control" id="nama" name="nama_paket" maxlength="100" required value="<?= admin_escape($editPackage['nama_paket'] ?? '') ?>"></div>
                <div class="col-md-4"><label class="form-label" for="kategori">Kategori</label><select class="form-select" id="kategori" name="kategori" required>
                    <?php foreach (['ps3' => 'PlayStation 3', 'ps4' => 'PlayStation 4', 'ps5' => 'PlayStation 5', 'combo' => 'Paket Combo', 'tv' => 'TV LED'] as $value => $label): ?>
                        <option value="<?= $value ?>" <?= ($editPackage['kategori'] ?? 'ps4') === $value ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select></div>
                <div class="col-md-4"><label class="form-label" for="badge">Label katalog</label><input class="form-control" id="badge" name="label_badge" maxlength="50" required value="<?= admin_escape($editPackage['label_badge'] ?? 'KONSOL ONLY') ?>"></div>
                <div class="col-md-8"><label class="form-label" for="tag">Tag sorotan (opsional)</label><input class="form-control" id="tag" name="tag_highlight" maxlength="100" value="<?= admin_escape($editPackage['tag_highlight'] ?? '') ?>"></div>
                <div class="col-12"><label class="form-label" for="deskripsi">Deskripsi / kelengkapan</label><textarea class="form-control" id="deskripsi" name="deskripsi" rows="2" required><?= admin_escape($editPackage['deskripsi'] ?? '') ?></textarea></div>
                <?php foreach (['harga_wk_12h' => 'Weekday · 12 jam', 'harga_wk_24h' => 'Weekday · 24 jam', 'harga_wn_12h' => 'Weekend · 12 jam', 'harga_wn_24h' => 'Weekend · 24 jam'] as $field => $label): ?>
                    <div class="col-6 col-md-3"><label class="form-label" for="<?= $field ?>"><?= $label ?></label><input class="form-control" type="number" min="0" step="1000" id="<?= $field ?>" name="<?= $field ?>" required value="<?= admin_escape($editPackage[$field] ?? 0) ?>"></div>
                <?php endforeach; ?>
                <div class="col-md-4"><label class="form-label" for="stok">Unit baru ditambahkan</label><input class="form-control" type="number" id="stok" name="stok_tambah" min="<?= $editPackage ? 0 : 1 ?>" max="50" value="<?= $editPackage ? 0 : 1 ?>" required><div class="form-text">Setiap unit mendapat barcode unik.</div></div>
                <div class="col-md-5"><label class="form-label" for="ruang">Nama ruang / lokasi</label><input class="form-control" id="ruang" name="nama_ruang" maxlength="80" value="Ruang" required></div>
                <div class="col-md-3 d-flex align-items-end">
                    <?php if ($editPackage): ?><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="maintenance" id="maintenance" value="1" <?= ($editPackage['override_status'] ?? '') === 'Maintenance' ? 'checked' : '' ?>><label class="form-check-label" for="maintenance">Tutup paket (maintenance)</label></div><?php endif; ?>
                </div>
                <div class="col-12"><button class="btn btn-primary px-4" type="submit"><i class="bi bi-save me-1"></i>Simpan</button></div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                <div><h2 class="h5 fw-bold mb-1">Daftar Paket & Unit</h2><small class="text-muted">Unit yang sedang disewa hanya dapat diubah saat pengembalian.</small></div>
                <form method="get" class="d-flex gap-2">
                    <input type="hidden" name="branch_id" value="<?= $branchId ?>">
                    <select class="form-select" name="kategori" aria-label="Filter kategori">
                        <?php foreach (['all' => 'Semua kategori', 'ps3' => 'PS3', 'ps4' => 'PS4', 'ps5' => 'PS5', 'combo' => 'Combo', 'tv' => 'TV'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $category === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input class="form-control" type="search" name="q" placeholder="Cari paket" value="<?= admin_escape($search) ?>">
                    <button class="btn btn-outline-primary" type="submit">Cari</button>
                </form>
            </div>
            <?php if (!$packages): ?>
                <div class="alert alert-secondary mb-0">Belum ada paket yang sesuai. Tambahkan paket melalui formulir di atas.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead class="table-light"><tr><th>Paket</th><th>Kategori</th><th>Harga 12 / 24 jam</th><th>Stok</th><th>Ketersediaan</th><th>Aksi</th></tr></thead>
                        <tbody>
                        <?php foreach ($packages as $package): ?>
                            <tr>
                                <td><strong><?= admin_escape($package['nama_paket']) ?></strong><small class="d-block text-muted"><?= admin_escape($package['kode_paket']) ?> · <?= admin_escape($package['deskripsi']) ?></small></td>
                                <td><?= admin_escape(strtoupper($package['kategori'])) ?></td>
                                <td class="text-nowrap"><?= admin_money($package['harga_wk_12h']) ?> / <?= admin_money($package['harga_wk_24h']) ?><small class="d-block text-muted">Weekend: <?= admin_money($package['harga_wn_12h']) ?> / <?= admin_money($package['harga_wn_24h']) ?></small></td>
                                <td><span class="text-success"><?= (int)$package['tersedia'] ?> tersedia</span><small class="d-block text-muted"><?= (int)$package['disewa'] ?> disewa · <?= (int)$package['perbaikan'] ?> perbaikan</small></td>
                                <td>
                                    <form method="post" action="<?= admin_escape($returnUrl) ?>" class="d-flex gap-1">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="package_status"><input type="hidden" name="id_paket" value="<?= (int)$package['id_paket'] ?>">
                                        <select class="form-select form-select-sm" name="override_status" aria-label="Ketersediaan paket">
                                            <option value="auto" <?= $package['override_status'] === 'auto' ? 'selected' : '' ?>>Aktif</option>
                                            <option value="Maintenance" <?= $package['override_status'] === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                        </select><button class="btn btn-sm btn-outline-primary" type="submit">Ubah</button>
                                    </form>
                                </td>
                                <td><a class="btn btn-sm btn-outline-primary" href="unit-ps.php<?= admin_branch_query($branchId) ?>&amp;edit_id=<?= (int)$package['id_paket'] ?>">Edit</a></td>
                            </tr>
                            <tr><td colspan="6" class="pt-0 border-0">
                                <details><summary class="small text-primary">Lihat / ubah <?= (int)$package['total_unit'] ?> unit</summary>
                                    <div class="table-responsive mt-2"><table class="table table-sm table-bordered mb-2">
                                        <thead><tr><th>Barcode</th><th>Ruang</th><th>Status</th><th>Ubah status</th></tr></thead><tbody>
                                        <?php foreach ($unitsByPackage[$package['id_paket']] ?? [] as $unit): ?>
                                            <tr><td><?= admin_escape($unit['kode_barcode']) ?></td><td><?= admin_escape($unit['nama_unit_ruang']) ?></td><td><?= admin_escape(ucfirst($unit['status_unit'])) ?></td><td>
                                                <?php if ($unit['status_unit'] === 'disewa'): ?><span class="text-muted small">Tidak dapat diubah saat disewa</span>
                                                <?php else: ?><form method="post" action="<?= admin_escape($returnUrl) ?>" class="d-flex gap-2">
                                                    <?= csrf_field() ?><input type="hidden" name="action" value="unit_status"><input type="hidden" name="id_unit" value="<?= (int)$unit['id_unit'] ?>">
                                                    <select class="form-select form-select-sm" name="status_unit" aria-label="Status unit">
                                                        <?php foreach (['tersedia' => 'Tersedia', 'maintenance' => 'Maintenance', 'rusak' => 'Rusak'] as $value => $label): ?><option value="<?= $value ?>" <?= $unit['status_unit'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                                                    </select><button class="btn btn-sm btn-outline-primary" type="submit">Simpan</button>
                                                </form><?php endif; ?>
                                            </td></tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table></div>
                                </details>
                            </td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?php admin_page_footer(); ?>

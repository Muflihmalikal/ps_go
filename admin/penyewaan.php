<?php
require_once __DIR__ . '/_common.php';

$user = current_user();
[$branches, $branchId, $branch, $branchError] = admin_branch_context($pdo, $user);
$returnUrl = 'penyewaan.php' . ($branchId ? admin_branch_query($branchId) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$branchId || !verify_csrf_token()) {
        admin_flash(!$branchId ? $branchError : 'Form kedaluwarsa. Muat ulang halaman, lalu coba lagi.');
        header('Location: ' . $returnUrl);
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    $rentalId = trim((string)($_POST['id_sewa'] ?? ''));
    try {
        if ($action === 'create_onsite') {
            $customerId = filter_var($_POST['id_user'] ?? 0, FILTER_VALIDATE_INT);
            $unitId = filter_var($_POST['id_unit'] ?? 0, FILTER_VALIDATE_INT);
            $duration = filter_var($_POST['durasi'] ?? 0, FILTER_VALIDATE_INT);
            if (!$customerId || !$unitId || !$duration || $duration > 24) {
                throw new InvalidArgumentException('Pelanggan, unit, dan durasi 1-24 jam wajib dipilih.');
            }

            $pdo->beginTransaction();
            $unitQuery = $pdo->prepare(
                "SELECT u.id_unit, u.id_paket, u.status_unit,
                        p.harga_wk_12h, p.harga_wk_24h, p.harga_wn_12h, p.harga_wn_24h
                 FROM unit_ps u JOIN paket_sewa p ON p.id_paket = u.id_paket
                 WHERE u.id_unit = :unit AND u.id_cabang = :branch AND p.override_status = 'auto'
                 FOR UPDATE OF u"
            );
            $unitQuery->execute(['unit' => $unitId, 'branch' => $branchId]);
            $unit = $unitQuery->fetch();
            if (!$unit || $unit['status_unit'] !== 'tersedia') {
                throw new InvalidArgumentException('Unit tidak ditemukan atau sudah tidak tersedia.');
            }
            $customerQuery = $pdo->prepare("SELECT 1 FROM users WHERE id_user = :id AND role = 'pelanggan'");
            $customerQuery->execute(['id' => $customerId]);
            if (!$customerQuery->fetchColumn()) {
                throw new InvalidArgumentException('Pelanggan tidak ditemukan.');
            }

            $weekend = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('N') >= 5;
            $rate12 = (float)$unit[$weekend ? 'harga_wn_12h' : 'harga_wk_12h'];
            $rate24 = (float)$unit[$weekend ? 'harga_wn_24h' : 'harga_wk_24h'];
            $blocks = $duration / 12;
            $total = (int)floor($blocks / 2) * $rate24
                + (($blocks - floor($blocks / 2) * 2) * $rate12);
            $total = round($total);
            $rentalId = '#GG-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $create = $pdo->prepare(
                "INSERT INTO penyewaan
                 (id_sewa, id_user, id_operator, id_unit, id_cabang, tipe_layanan, tipe_sewa, durasi,
                  jenis_tarif, status_verifikasi_jaminan, waktu_mulai, waktu_kembali_rencana,
                  biaya_sewa, total_biaya, status_sewa)
                 VALUES (:id, :customer, :operator, :unit, :branch, 'ditempat', 'jam', :duration,
                  :tariff, 'terverifikasi', CURRENT_TIMESTAMP AT TIME ZONE 'UTC',
                  (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') + (CAST(:duration_interval AS INTEGER) * INTERVAL '1 hour'),
                  :total, :total, 'aktif')"
            );
            $create->execute([
                'id' => $rentalId, 'customer' => $customerId, 'operator' => $user['id_user'],
                'unit' => $unitId, 'branch' => $branchId, 'duration' => $duration,
                'duration_interval' => $duration, 'tariff' => $weekend ? 'weekend' : 'weekday', 'total' => $total,
            ]);
            $payment = $pdo->prepare(
                "INSERT INTO pembayaran
                 (id_sewa, id_operator_verifikasi, metode_pembayaran, total_tagihan, status_bayar, waktu_bayar)
                 VALUES (:id, :operator, 'cash', :total, 'lunas', CURRENT_TIMESTAMP)"
            );
            $payment->execute(['id' => $rentalId, 'operator' => $user['id_user'], 'total' => $total]);
            $markUnit = $pdo->prepare("UPDATE unit_ps SET status_unit = 'disewa' WHERE id_unit = :id AND status_unit = 'tersedia'");
            $markUnit->execute(['id' => $unitId]);
            if ($markUnit->rowCount() !== 1) {
                throw new InvalidArgumentException('Unit baru saja dipesan. Pilih unit lain.');
            }
            $pdo->commit();
            admin_flash('Sewa di tempat berhasil dibuat. ID sewa: ' . $rentalId);
        } elseif ($action === 'verify') {
            if (empty($_POST['confirm_payment']) || empty($_POST['confirm_guarantee'])) {
                throw new InvalidArgumentException('Konfirmasi pembayaran dan jaminan harus dicentang sebelum pesanan disetujui.');
            }
            $pdo->beginTransaction();
            $rental = $pdo->prepare('SELECT * FROM penyewaan WHERE id_sewa = :id AND id_cabang = :branch FOR UPDATE');
            $rental->execute(['id' => $rentalId, 'branch' => $branchId]);
            $booking = $rental->fetch();
            if (!$booking || $booking['status_sewa'] !== 'menunggu') {
                throw new InvalidArgumentException('Pesanan tidak ditemukan atau sudah diproses.');
            }
            $paymentQuery = $pdo->prepare('SELECT * FROM pembayaran WHERE id_sewa = :id FOR UPDATE');
            $paymentQuery->execute(['id' => $rentalId]);
            $payment = $paymentQuery->fetch();
            if (!$payment || !in_array($payment['metode_pembayaran'], ['transfer', 'qris', 'cash', 'cod'], true)) {
                throw new InvalidArgumentException('Data pembayaran pesanan tidak valid.');
            }
            if ($payment['status_bayar'] === 'ditolak') {
                throw new InvalidArgumentException('Pembayaran pesanan ini sudah ditolak.');
            }

            $unitId = (int)($booking['id_unit'] ?? 0);
            if (!$unitId && !empty($_POST['unit_assignment'])) {
                $unitId = (int)$_POST['unit_assignment'];
            }
            if (!$unitId) {
                throw new InvalidArgumentException('Pilih unit tersedia untuk pesanan ini.');
            }
            $unitQuery = $pdo->prepare('SELECT status_unit FROM unit_ps WHERE id_unit = :id AND id_cabang = :branch FOR UPDATE');
            $unitQuery->execute(['id' => $unitId, 'branch' => $branchId]);
            $unitStatus = $unitQuery->fetchColumn();
            if (!in_array($unitStatus, ['tersedia', 'disewa'], true)) {
                throw new InvalidArgumentException('Unit yang dipilih tidak tersedia.');
            }
            if (admin_unit_has_open_rental($pdo, $unitId, $rentalId)) {
                throw new InvalidArgumentException('Unit ini sudah terhubung ke pesanan lain yang masih berjalan.');
            }
            if ($booking['id_unit'] === null) {
                $assign = $pdo->prepare('UPDATE penyewaan SET id_unit = :unit WHERE id_sewa = :id AND id_cabang = :branch');
                $assign->execute(['unit' => $unitId, 'id' => $rentalId, 'branch' => $branchId]);
            }
            if ($unitStatus === 'tersedia') {
                $mark = $pdo->prepare("UPDATE unit_ps SET status_unit = 'disewa' WHERE id_unit = :id AND status_unit = 'tersedia'");
                $mark->execute(['id' => $unitId]);
                if ($mark->rowCount() !== 1) {
                    throw new InvalidArgumentException('Unit baru saja dipesan. Pilih unit lain.');
                }
            }
            $nextStatus = $booking['tipe_layanan'] === 'delivery' ? 'diantar' : 'siap';
            $update = $pdo->prepare(
                "UPDATE penyewaan SET status_sewa = :status, status_verifikasi_jaminan = 'terverifikasi',
                 id_operator = :operator, id_unit = :unit WHERE id_sewa = :id AND id_cabang = :branch"
            );
            $update->execute([
                'status' => $nextStatus, 'operator' => $user['id_user'],
                'unit' => $unitId, 'id' => $rentalId, 'branch' => $branchId,
            ]);
            $paid = $pdo->prepare(
                "UPDATE pembayaran SET status_bayar = 'lunas', id_operator_verifikasi = :operator,
                 waktu_bayar = COALESCE(waktu_bayar, CURRENT_TIMESTAMP) WHERE id_sewa = :id"
            );
            $paid->execute(['operator' => $user['id_user'], 'id' => $rentalId]);
            if ($paid->rowCount() !== 1) {
                throw new InvalidArgumentException('Data pembayaran pesanan tidak ditemukan.');
            }
            if ($booking['tipe_layanan'] === 'delivery') {
                $delivery = $pdo->prepare("UPDATE delivery_info SET status_delivery = 'masih_diantar' WHERE id_sewa = :id");
                $delivery->execute(['id' => $rentalId]);
                if ($delivery->rowCount() !== 1) {
                    throw new InvalidArgumentException('Informasi pengantaran pesanan tidak ditemukan.');
                }
            }
            $pdo->commit();
            admin_flash('Pesanan diverifikasi. Status: ' . ($nextStatus === 'diantar' ? 'sedang diantar' : 'siap diambil') . '.');
        } elseif ($action === 'advance') {
            $pdo->beginTransaction();
            $rental = $pdo->prepare('SELECT * FROM penyewaan WHERE id_sewa = :id AND id_cabang = :branch FOR UPDATE');
            $rental->execute(['id' => $rentalId, 'branch' => $branchId]);
            $booking = $rental->fetch();
            if (!$booking) {
                throw new InvalidArgumentException('Pesanan tidak ditemukan.');
            }
            if ($booking['status_sewa'] === 'diantar' && $booking['tipe_layanan'] === 'delivery') {
                $update = $pdo->prepare("UPDATE penyewaan SET status_sewa = 'sampai' WHERE id_sewa = :id AND id_cabang = :branch");
                $update->execute(['id' => $rentalId, 'branch' => $branchId]);
                $delivery = $pdo->prepare("UPDATE delivery_info SET status_delivery = 'sudah_sampai' WHERE id_sewa = :id");
                $delivery->execute(['id' => $rentalId]);
                if ($delivery->rowCount() !== 1) {
                    throw new InvalidArgumentException('Informasi pengantaran pesanan tidak ditemukan.');
                }
                $message = 'Kedatangan unit berhasil dicatat. Aktifkan sewa setelah unit diterima pelanggan.';
            } elseif (in_array($booking['status_sewa'], ['siap', 'sampai'], true)) {
                if ($booking['status_verifikasi_jaminan'] !== 'terverifikasi' || !$booking['id_unit']) {
                    throw new InvalidArgumentException('Jaminan atau unit belum terverifikasi.');
                }
                if (admin_unit_has_open_rental($pdo, $booking['id_unit'], $rentalId)) {
                    throw new InvalidArgumentException('Unit ini sudah digunakan pesanan lain.');
                }
                $paymentStatus = $pdo->prepare('SELECT status_bayar FROM pembayaran WHERE id_sewa = :id');
                $paymentStatus->execute(['id' => $rentalId]);
                if ($paymentStatus->fetchColumn() !== 'lunas') {
                    throw new InvalidArgumentException('Pembayaran belum lunas.');
                }
                $mark = $pdo->prepare("UPDATE unit_ps SET status_unit = 'disewa' WHERE id_unit = :id AND id_cabang = :branch AND status_unit IN ('tersedia', 'disewa')");
                $mark->execute(['id' => $booking['id_unit'], 'branch' => $branchId]);
                if ($mark->rowCount() !== 1) {
                    throw new InvalidArgumentException('Unit tidak tersedia untuk diserahkan.');
                }
                $update = $pdo->prepare(
                    "UPDATE penyewaan
                     SET status_sewa = 'aktif',
                         waktu_mulai = CURRENT_TIMESTAMP AT TIME ZONE 'UTC',
                         waktu_kembali_rencana = (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') + (waktu_kembali_rencana - waktu_mulai)
                     WHERE id_sewa = :id AND id_cabang = :branch"
                );
                $update->execute(['id' => $rentalId, 'branch' => $branchId]);
                $message = 'Sewa telah diaktifkan.';
            } else {
                throw new InvalidArgumentException('Tahap pesanan ini tidak dapat dilanjutkan.');
            }
            $pdo->commit();
            admin_flash($message);
        } elseif ($action === 'finish') {
            $condition = (string)($_POST['kondisi_unit'] ?? 'baik');
            if (!in_array($condition, ['baik', 'maintenance', 'rusak'], true)) {
                throw new InvalidArgumentException('Kondisi unit tidak valid.');
            }
            $pdo->beginTransaction();
            $rental = $pdo->prepare('SELECT * FROM penyewaan WHERE id_sewa = :id AND id_cabang = :branch FOR UPDATE');
            $rental->execute(['id' => $rentalId, 'branch' => $branchId]);
            $booking = $rental->fetch();
            if (!$booking || !in_array($booking['status_sewa'], ['aktif', 'habis', 'terlambat'], true)) {
                throw new InvalidArgumentException('Pesanan tidak aktif atau sudah ditutup.');
            }
            $minutesLate = 0;
            if ($booking['tipe_layanan'] !== 'ditempat') {
                $plannedReturn = new DateTimeImmutable($booking['waktu_kembali_rencana'], new DateTimeZone('UTC'));
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $minutesLate = max(0, (int)floor(($now->getTimestamp() - $plannedReturn->getTimestamp()) / 60));
            }
            $lateHours = $minutesLate <= 10 ? 0 : (int)ceil(($minutesLate - 10) / 60);
            $finish = $pdo->prepare(
                "UPDATE penyewaan SET status_sewa = 'selesai', waktu_kembali_aktual = CURRENT_TIMESTAMP
                 WHERE id_sewa = :id AND id_cabang = :branch"
            );
            $finish->execute(['id' => $rentalId, 'branch' => $branchId]);
            if ($booking['id_unit'] && !admin_unit_has_open_rental($pdo, $booking['id_unit'], $rentalId)) {
                $unitState = $condition === 'baik' ? 'tersedia' : $condition;
                $release = $pdo->prepare(
                    'UPDATE unit_ps SET status_unit = :status, catatan_kondisi = :note
                     WHERE id_unit = :unit AND id_cabang = :branch'
                );
                $release->execute([
                    'status' => $unitState,
                    'note' => $condition === 'baik' ? null : 'Kondisi saat pengembalian',
                    'unit' => $booking['id_unit'],
                    'branch' => $branchId,
                ]);
            }
            if ($lateHours > 0) {
                $fine = $pdo->prepare(
                    "INSERT INTO denda (id_sewa, keterlambatan_jam, total_denda, status_denda)
                     VALUES (:id, :hours, :total, 'belum_bayar')
                     ON CONFLICT (id_sewa) DO UPDATE SET keterlambatan_jam = EXCLUDED.keterlambatan_jam,
                     total_denda = EXCLUDED.total_denda, status_denda = 'belum_bayar'"
                );
                $fine->execute(['id' => $rentalId, 'hours' => $lateHours, 'total' => $lateHours * 10000]);
            }
            $pdo->commit();
            admin_flash('Sewa selesai.' . ($lateHours > 0 ? ' Denda keterlambatan: ' . $lateHours . ' jam, Rp ' . number_format($lateHours * 10000, 0, ',', '.') . '.' : ''));
        } elseif ($action === 'reject') {
            $reason = trim((string)($_POST['alasan'] ?? 'Pesanan ditolak oleh admin.'));
            if ($reason === '' || strlen($reason) > 500) {
                throw new InvalidArgumentException('Alasan penolakan wajib diisi (maksimal 500 karakter).');
            }
            $pdo->beginTransaction();
            $rental = $pdo->prepare('SELECT id_unit, status_sewa FROM penyewaan WHERE id_sewa = :id AND id_cabang = :branch FOR UPDATE');
            $rental->execute(['id' => $rentalId, 'branch' => $branchId]);
            $booking = $rental->fetch();
            if (!$booking || $booking['status_sewa'] !== 'menunggu') {
                throw new InvalidArgumentException('Hanya pesanan yang menunggu verifikasi yang dapat ditolak.');
            }
            $reject = $pdo->prepare("UPDATE penyewaan SET status_sewa = 'ditolak', status_verifikasi_jaminan = 'ditolak', alasan_penolakan = :reason WHERE id_sewa = :id AND id_cabang = :branch");
            $reject->execute(['reason' => $reason, 'id' => $rentalId, 'branch' => $branchId]);
            $payment = $pdo->prepare("UPDATE pembayaran SET status_bayar = 'ditolak' WHERE id_sewa = :id AND status_bayar <> 'lunas'");
            $payment->execute(['id' => $rentalId]);
            if ($booking['id_unit'] && !admin_unit_has_open_rental($pdo, $booking['id_unit'], $rentalId)) {
                $release = $pdo->prepare("UPDATE unit_ps SET status_unit = 'tersedia' WHERE id_unit = :id AND id_cabang = :branch AND status_unit = 'disewa'");
                $release->execute(['id' => $booking['id_unit'], 'branch' => $branchId]);
            }
            $pdo->commit();
            admin_flash('Pesanan ditolak dan alasan penolakan disimpan.');
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
        error_log('Proses penyewaan admin gagal: ' . $exception->getMessage());
        admin_flash('Proses gagal disimpan. Periksa data dan ketersediaan unit, lalu coba lagi.');
    }

    header('Location: ' . $returnUrl);
    exit;
}

$statusFilter = (string)($_GET['status'] ?? 'all');
$serviceFilter = (string)($_GET['layanan'] ?? 'all');
$search = trim((string)($_GET['q'] ?? ''));
$validStatus = ['all', 'menunggu', 'proses', 'aktif', 'lewat', 'selesai', 'ditolak'];
$validServices = ['all', 'ditempat', 'pickup', 'delivery'];
if (!in_array($statusFilter, $validStatus, true)) {
    $statusFilter = 'all';
}
if (!in_array($serviceFilter, $validServices, true)) {
    $serviceFilter = 'all';
}

$rentals = [];
$customers = [];
$availableUnits = [];
$stats = ['menunggu' => 0, 'aktif' => 0, 'telat' => 0, 'pendapatan' => 0];
if ($branchId) {
    $summary = $pdo->prepare(
        "SELECT
         COUNT(*) FILTER (WHERE s.status_sewa = 'menunggu') AS menunggu,
         COUNT(*) FILTER (WHERE s.status_sewa IN ('aktif', 'diantar', 'sampai')) AS aktif,
         COUNT(*) FILTER (WHERE s.status_sewa IN ('aktif', 'habis', 'terlambat') AND s.waktu_kembali_rencana < (CURRENT_TIMESTAMP AT TIME ZONE 'UTC')) AS telat,
         COALESCE(SUM(p.total_tagihan) FILTER (WHERE p.status_bayar = 'lunas'), 0) AS pendapatan
         FROM penyewaan s LEFT JOIN pembayaran p ON p.id_sewa = s.id_sewa
         WHERE s.id_cabang = :branch"
    );
    $summary->execute(['branch' => $branchId]);
    $stats = $summary->fetch();

    $filters = ['s.id_cabang = :branch'];
    $params = ['branch' => $branchId];
    if ($statusFilter === 'menunggu') {
        $filters[] = "s.status_sewa = 'menunggu'";
    } elseif ($statusFilter === 'proses') {
        $filters[] = "s.status_sewa IN ('siap', 'diantar', 'sampai')";
    } elseif ($statusFilter === 'aktif') {
        $filters[] = "s.status_sewa IN ('aktif', 'habis', 'terlambat') AND s.waktu_kembali_rencana >= (CURRENT_TIMESTAMP AT TIME ZONE 'UTC')";
    } elseif ($statusFilter === 'lewat') {
        $filters[] = "(s.status_sewa IN ('habis', 'terlambat') OR (s.status_sewa = 'aktif' AND s.waktu_kembali_rencana < (CURRENT_TIMESTAMP AT TIME ZONE 'UTC')))";
    } elseif ($statusFilter !== 'all') {
        $filters[] = 's.status_sewa = :status';
        $params['status'] = $statusFilter;
    }
    if ($serviceFilter !== 'all') {
        $filters[] = 's.tipe_layanan = :service';
        $params['service'] = $serviceFilter;
    }
    if ($search !== '') {
        $filters[] = '(s.id_sewa ILIKE :search OR u.nama ILIKE :search OR u.no_wa ILIKE :search)';
        $params['search'] = '%' . $search . '%';
    }
    $list = $pdo->prepare(
        'SELECT s.*, u.nama AS nama_pelanggan, u.no_wa, unit.kode_barcode,
         unit.nama_unit_ruang, unit.status_unit, paket.nama_paket,
         pembayaran.status_bayar, pembayaran.metode_pembayaran, pembayaran.total_tagihan,
         delivery_info.alamat_tujuan
         FROM penyewaan s
         JOIN users u ON u.id_user = s.id_user
         LEFT JOIN unit_ps unit ON unit.id_unit = s.id_unit
         LEFT JOIN paket_sewa paket ON paket.id_paket = unit.id_paket
         LEFT JOIN pembayaran ON pembayaran.id_sewa = s.id_sewa
         LEFT JOIN delivery_info ON delivery_info.id_sewa = s.id_sewa
         WHERE ' . implode(' AND ', $filters) . '
         ORDER BY s.created_at DESC LIMIT 200'
    );
    $list->execute($params);
    $rentals = $list->fetchAll();

    $customers = $pdo->query("SELECT id_user, nama, no_wa FROM users WHERE role = 'pelanggan' ORDER BY nama")->fetchAll();
    $available = $pdo->prepare(
        "SELECT u.id_unit, u.kode_barcode, u.nama_unit_ruang, p.nama_paket,
                p.harga_wk_12h, p.harga_wn_12h
         FROM unit_ps u JOIN paket_sewa p ON p.id_paket = u.id_paket
         WHERE u.id_cabang = :branch AND u.status_unit = 'tersedia' AND p.override_status = 'auto'
         ORDER BY p.nama_paket, u.nama_unit_ruang"
    );
    $available->execute(['branch' => $branchId]);
    $availableUnits = $available->fetchAll();
}

$unitOptions = [];
foreach ($availableUnits as $unit) {
    $unitOptions[$unit['id_unit']] = $unit;
}
admin_page_header('Kelola Penyewaan', 'rentals', $branch, $branches, $branchId);
?>
<?php if ($branchError): ?>
    <div class="alert alert-warning"><?= admin_escape($branchError) ?></div>
<?php else: ?>
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm rounded-4 p-3"><small class="text-muted">MENUNGGU VERIFIKASI</small><strong class="fs-3 text-warning"><?= (int)$stats['menunggu'] ?></strong></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm rounded-4 p-3"><small class="text-muted">PESANAN BERJALAN</small><strong class="fs-3 text-primary"><?= (int)$stats['aktif'] ?></strong></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm rounded-4 p-3"><small class="text-muted">TERLAMBAT</small><strong class="fs-3 text-danger"><?= (int)$stats['telat'] ?></strong></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm rounded-4 p-3"><small class="text-muted">PEMBAYARAN LUNAS</small><strong class="fs-4 text-success"><?= admin_money($stats['pendapatan']) ?></strong></div></div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3 p-md-4">
            <h2 class="h5 fw-bold">Tambah Sewa di Tempat</h2>
            <p class="small text-muted">Pilih pelanggan terdaftar dan unit tersedia. Transaksi dicatat sebagai pembayaran tunai.</p>
            <?php if (!$customers || !$availableUnits): ?>
                <div class="alert alert-warning mb-0"><?= !$customers ? 'Belum ada akun pelanggan terdaftar.' : 'Tidak ada unit tersedia untuk disewakan.' ?></div>
            <?php else: ?>
                <form method="post" action="<?= admin_escape($returnUrl) ?>" class="row g-3">
                    <?= csrf_field() ?><input type="hidden" name="action" value="create_onsite">
                    <div class="col-md-5"><label class="form-label" for="customer">Pelanggan</label><select class="form-select" id="customer" name="id_user" required><option value="">Pilih pelanggan</option><?php foreach ($customers as $customer): ?><option value="<?= (int)$customer['id_user'] ?>"><?= admin_escape($customer['nama']) ?> · <?= admin_escape($customer['no_wa']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-5"><label class="form-label" for="unit">Unit tersedia</label><select class="form-select" id="unit" name="id_unit" required><option value="">Pilih unit</option><?php foreach ($availableUnits as $unit): ?><option value="<?= (int)$unit['id_unit'] ?>"><?= admin_escape($unit['nama_paket']) ?> · <?= admin_escape($unit['nama_unit_ruang']) ?> (<?= admin_escape($unit['kode_barcode']) ?>)</option><?php endforeach; ?></select></div>
                    <div class="col-md-2"><label class="form-label" for="durasi">Durasi (jam)</label><input class="form-control" id="durasi" name="durasi" type="number" min="1" max="24" value="1" required></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">Mulai Sewa Tunai</button></div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                <div><h2 class="h5 fw-bold mb-1">Daftar Penyewaan</h2><small class="text-muted">Menampilkan maksimal 200 transaksi terbaru untuk cabang ini.</small></div>
                <form method="get" class="row g-2">
                    <input type="hidden" name="branch_id" value="<?= $branchId ?>">
                    <div class="col-auto"><select class="form-select" name="status" aria-label="Status">
                        <?php foreach (['all' => 'Semua status', 'menunggu' => 'Menunggu', 'proses' => 'Siap / diantar', 'aktif' => 'Aktif', 'lewat' => 'Terlambat', 'selesai' => 'Selesai', 'ditolak' => 'Ditolak'] as $value => $label): ?><option value="<?= $value ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="col-auto"><select class="form-select" name="layanan" aria-label="Layanan">
                        <?php foreach (['all' => 'Semua layanan', 'ditempat' => 'Di tempat', 'pickup' => 'Pickup', 'delivery' => 'Delivery'] as $value => $label): ?><option value="<?= $value ?>" <?= $serviceFilter === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="col-auto"><input class="form-control" type="search" name="q" placeholder="Cari ID / pelanggan" value="<?= admin_escape($search) ?>"></div>
                    <div class="col-auto"><button class="btn btn-outline-primary" type="submit">Filter</button></div>
                </form>
            </div>
            <?php if (!$rentals): ?>
                <div class="alert alert-secondary mb-0">Belum ada penyewaan yang sesuai filter.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light"><tr><th>ID / Waktu</th><th>Pelanggan</th><th>Unit & layanan</th><th>Total</th><th>Status bayar</th><th>Status sewa</th><th>Aksi</th></tr></thead>
                        <tbody>
                        <?php foreach ($rentals as $rental):
                            $isLate = in_array($rental['status_sewa'], ['aktif', 'habis', 'terlambat'], true)
                                && strtotime($rental['waktu_kembali_rencana'] . ' UTC') < time();
                            $statusLabel = $isLate ? 'Terlambat' : ucfirst($rental['status_sewa']);
                            $statusClass = $rental['status_sewa'] === 'selesai' ? 'success' : ($rental['status_sewa'] === 'ditolak' ? 'danger' : (in_array($rental['status_sewa'], ['menunggu', 'diantar', 'siap', 'sampai'], true) ? 'warning text-dark' : ($isLate ? 'danger' : 'primary')));
                            ?>
                            <tr>
                                <td><strong><?= admin_escape($rental['id_sewa']) ?></strong><small class="d-block text-muted"><?= admin_escape(admin_format_datetime($rental['created_at'])) ?></small></td>
                                <td><?= admin_escape($rental['nama_pelanggan']) ?><small class="d-block text-muted"><?= admin_escape($rental['no_wa']) ?></small></td>
                                <td><?= admin_escape($rental['nama_paket'] ?? 'Unit belum ditentukan') ?><small class="d-block text-muted"><?= admin_escape(strtoupper($rental['tipe_layanan'])) ?> · <?= admin_escape($rental['nama_unit_ruang'] ?? '-') ?> · <?= admin_escape($rental['kode_barcode'] ?? '-') ?></small></td>
                                <td class="text-nowrap"><?= admin_money($rental['total_tagihan'] ?? $rental['total_biaya']) ?></td>
                                <td><?= admin_escape(ucwords(str_replace('_', ' ', $rental['status_bayar'] ?? 'belum ada'))) ?><small class="d-block text-muted"><?= admin_escape(strtoupper($rental['metode_pembayaran'] ?? '-')) ?></small></td>
                                <td><span class="badge text-bg-<?= $statusClass ?>"><?= admin_escape($statusLabel) ?></span><small class="d-block text-muted">Kembali: <?= admin_escape(admin_format_datetime($rental['waktu_kembali_rencana'])) ?></small></td>
                                <td class="text-nowrap">
                                    <details><summary class="btn btn-sm btn-outline-primary">Proses</summary>
                                        <div class="position-absolute bg-white border rounded shadow p-3 mt-1 end-0 action-panel">
                                            <?php if ($rental['status_sewa'] === 'menunggu'): ?>
                                                <form method="post" action="<?= admin_escape($returnUrl) ?>" class="mb-3">
                                                    <?= csrf_field() ?><input type="hidden" name="action" value="verify"><input type="hidden" name="id_sewa" value="<?= admin_escape($rental['id_sewa']) ?>">
                                                    <?php if (!$rental['id_unit']): ?><label class="form-label small">Tugaskan unit tersedia</label><select class="form-select form-select-sm mb-2" name="unit_assignment" required><option value="">Pilih unit</option><?php foreach ($unitOptions as $unitId => $unit): ?><option value="<?= (int)$unitId ?>"><?= admin_escape($unit['nama_paket']) ?> · <?= admin_escape($unit['nama_unit_ruang']) ?></option><?php endforeach; ?></select><?php endif; ?>
                                                    <div class="form-check small"><input class="form-check-input" type="checkbox" name="confirm_payment" value="1" id="pay<?= admin_escape(md5($rental['id_sewa'])) ?>" required><label class="form-check-label" for="pay<?= admin_escape(md5($rental['id_sewa'])) ?>">Pembayaran sudah diterima dan sesuai</label></div>
                                                    <div class="form-check small mb-2"><input class="form-check-input" type="checkbox" name="confirm_guarantee" value="1" id="guarantee<?= admin_escape(md5($rental['id_sewa'])) ?>" required><label class="form-check-label" for="guarantee<?= admin_escape(md5($rental['id_sewa'])) ?>">Jaminan sudah diperiksa dan valid</label></div>
                                                    <button class="btn btn-sm btn-success w-100" type="submit">Verifikasi pesanan</button>
                                                </form>
                                                <form method="post" action="<?= admin_escape($returnUrl) ?>">
                                                    <?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id_sewa" value="<?= admin_escape($rental['id_sewa']) ?>">
                                                    <label class="form-label small" for="reason<?= admin_escape(md5($rental['id_sewa'])) ?>">Alasan penolakan</label><input class="form-control form-control-sm mb-2" id="reason<?= admin_escape(md5($rental['id_sewa'])) ?>" name="alasan" maxlength="500" required>
                                                    <button class="btn btn-sm btn-outline-danger w-100" type="submit">Tolak pesanan</button>
                                                </form>
                                            <?php elseif ($rental['status_sewa'] === 'diantar' || in_array($rental['status_sewa'], ['siap', 'sampai'], true)): ?>
                                                <form method="post" action="<?= admin_escape($returnUrl) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="advance"><input type="hidden" name="id_sewa" value="<?= admin_escape($rental['id_sewa']) ?>"><button class="btn btn-sm btn-primary w-100" type="submit"><?= $rental['status_sewa'] === 'diantar' ? 'Konfirmasi unit sampai' : 'Serahkan & mulai sewa' ?></button></form>
                                            <?php elseif (in_array($rental['status_sewa'], ['aktif', 'habis', 'terlambat'], true)): ?>
                                                <form method="post" action="<?= admin_escape($returnUrl) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="finish"><input type="hidden" name="id_sewa" value="<?= admin_escape($rental['id_sewa']) ?>"><label class="form-label small" for="condition<?= admin_escape(md5($rental['id_sewa'])) ?>">Kondisi unit saat kembali</label><select class="form-select form-select-sm mb-2" id="condition<?= admin_escape(md5($rental['id_sewa'])) ?>" name="kondisi_unit"><option value="baik">Baik / tersedia lagi</option><option value="maintenance">Perlu maintenance</option><option value="rusak">Rusak</option></select><button class="btn btn-sm btn-warning w-100" type="submit">Selesaikan sewa</button></form>
                                            <?php else: ?><span class="small text-muted">Tidak ada aksi untuk status ini.</span><?php endif; ?>
                                            <?php if ($rental['tipe_layanan'] === 'delivery' && $rental['alamat_tujuan']): ?><hr><small><strong>Alamat:</strong> <?= admin_escape($rental['alamat_tujuan']) ?></small><?php endif; ?>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<style>
    td { position: relative; }
    .action-panel { z-index: 20; width: min(320px, 85vw); }
</style>
<?php admin_page_footer(); ?>

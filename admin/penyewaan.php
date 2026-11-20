<?php
require_once __DIR__ . '/_common.php';

$user = current_user();
[$branches, $branchId, $branch, $branchError] = admin_branch_context($pdo, $user);
$returnUrl = 'penyewaan.php' . ($branchId ? admin_branch_query($branchId) : '');

// Helper lokal untuk menyinkronkan status fisik unit_ps dengan alokasi aktif di penyewaan_unit
function sync_unit_status(PDO $pdo, array $unitIds, string $preferState = 'tersedia'): void
{
    $unitIds = array_unique(array_filter(array_map('intval', $unitIds)));
    if (!$unitIds) return;

    $check = $pdo->prepare("SELECT 1 FROM penyewaan_unit WHERE id_unit = :uid AND aktif = TRUE LIMIT 1");
    $upd   = $pdo->prepare("UPDATE unit_ps SET status_unit = :st, catatan_kondisi = :note WHERE id_unit = :uid");

    foreach ($unitIds as $uid) {
        $check->execute(['uid' => $uid]);
        $hasActive = (bool)$check->fetchColumn();
        if ($hasActive) {
            $upd->execute(['st' => 'disewa', 'note' => null, 'uid' => $uid]);
        } else {
            $state = in_array($preferState, ['tersedia', 'maintenance', 'rusak'], true) ? $preferState : 'tersedia';
            $note  = $state === 'tersedia' ? null : 'Kondisi saat pengembalian';
            $upd->execute(['st' => $state, 'note' => $note, 'uid' => $uid]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$branchId || !verify_csrf_token()) {
        admin_flash(!$branchId ? $branchError : 'Form kedaluwarsa. Muat ulang halaman, lalu coba lagi.');
        header('Location: ' . $returnUrl);
        exit;
    }

    $action   = (string)($_POST['action'] ?? '');
    $rentalId = trim((string)($_POST['id_sewa'] ?? ''));

    try {
        // 1. TAMBAH SEWA DI TEMPAT (WALK-IN / PELANGGAN TERDAFTAR)
        if ($action === 'create_onsite') {
            $customerId = filter_var($_POST['id_user'] ?? 0, FILTER_VALIDATE_INT);
            $paketId    = filter_var($_POST['id_paket'] ?? 0, FILTER_VALIDATE_INT);
            $duration   = filter_var($_POST['durasi'] ?? 0, FILTER_VALIDATE_INT);
            $payStatus  = ($_POST['status_bayar'] ?? 'lunas') === 'belum_bayar' ? 'belum_bayar' : 'lunas';

            if (!$customerId || !$paketId || !$duration || $duration < 1 || $duration > 24) {
                throw new InvalidArgumentException('Pelanggan, paket, dan durasi 1-24 jam wajib dipilih.');
            }

            $pdo->beginTransaction();

            $pkgStmt = $pdo->prepare(
                "SELECT id_paket, nama_paket, harga_wk_12h, harga_wk_24h, harga_wn_12h, harga_wn_24h
                 FROM paket_sewa
                 WHERE id_paket = :paket AND id_cabang = :branch AND override_status = 'auto' AND is_active = TRUE"
            );
            $pkgStmt->execute(['paket' => $paketId, 'branch' => $branchId]);
            $pkg = $pkgStmt->fetch();
            if (!$pkg) {
                throw new InvalidArgumentException('Paket tidak ditemukan atau sedang tidak aktif.');
            }

            $custStmt = $pdo->prepare("SELECT 1 FROM users WHERE id_user = :id AND role = 'pelanggan' AND is_active = TRUE");
            $custStmt->execute(['id' => $customerId]);
            if (!$custStmt->fetchColumn()) {
                throw new InvalidArgumentException('Pelanggan tidak ditemukan.');
            }

            // Hitung tarif weekday / weekend (WIB)
            $nowJkt  = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
            $weekend = (int)$nowJkt->format('N') >= 5;
            $rate12  = (float)$pkg[$weekend ? 'harga_wn_12h' : 'harga_wk_12h'];
            $rate24  = (float)$pkg[$weekend ? 'harga_wn_24h' : 'harga_wk_24h'];

            $blocks = $duration / 12;
            $total  = round((int)floor($blocks / 2) * $rate24 + (($blocks - floor($blocks / 2) * 2) * $rate12));

            $startUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $endUtc   = $startUtc->modify("+{$duration} hours");
            $startIso = $startUtc->format('Y-m-d H:i:sP');
            $endIso   = $endUtc->format('Y-m-d H:i:sP');

            // Cek kebutuhan komponen paket & alokasi unit kosong
            $reqStmt = $pdo->prepare("SELECT id_paket_unit, jumlah FROM v_kebutuhan_unit WHERE id_paket = :p ORDER BY id_paket_unit");
            $reqStmt->execute(['p' => $paketId]);
            $requirements = $reqStmt->fetchAll();
            if (!$requirements) {
                throw new InvalidArgumentException('Komponen unit untuk paket ini belum diatur.');
            }

            $allocatedUnits = [];
            $findEmpty = $pdo->prepare(
                "SELECT id_unit FROM unit_kosong(:paket_unit, CAST(:m AS timestamptz), CAST(:s AS timestamptz))
                 WHERE id_cabang = :branch
                 LIMIT :lim"
            );

            foreach ($requirements as $req) {
                $findEmpty->bindValue(':paket_unit', (int)$req['id_paket_unit'], PDO::PARAM_INT);
                $findEmpty->bindValue(':m', $startIso);
                $findEmpty->bindValue(':s', $endIso);
                $findEmpty->bindValue(':branch', $branchId, PDO::PARAM_INT);
                $findEmpty->bindValue(':lim', (int)$req['jumlah'], PDO::PARAM_INT);
                $findEmpty->execute();
                $found = $findEmpty->fetchAll(PDO::FETCH_COLUMN);

                if (count($found) < (int)$req['jumlah']) {
                    throw new InvalidArgumentException('Stok unit untuk paket "' . $pkg['nama_paket'] . '" tidak mencukupi saat ini.');
                }
                foreach ($found as $uid) {
                    $allocatedUnits[] = (int)$uid;
                }
            }

            // Generate kode sewa otomatis dari sequence DB
            $rentalId = (string)$pdo->query("SELECT generate_kode_sewa()")->fetchColumn();

            $create = $pdo->prepare(
                "INSERT INTO penyewaan
                 (id_sewa, id_user, id_operator, id_cabang, id_paket, tipe_layanan, tipe_sewa, durasi,
                  jenis_tarif, status_verifikasi_jaminan, waktu_mulai, waktu_kembali_rencana,
                  harga_12h_snapshot, harga_24h_snapshot, biaya_sewa, total_biaya, status_sewa)
                 VALUES (:id, :customer, :operator, :branch, :paket, 'ditempat', 'jam', :duration,
                  :tariff, 'terverifikasi', CAST(:mulai AS timestamptz), CAST(:kembali AS timestamptz),
                  :h12, :h24, :total, :total, 'aktif')"
            );
            $create->execute([
                'id'       => $rentalId,
                'customer' => $customerId,
                'operator' => $user['id_user'],
                'branch'   => $branchId,
                'paket'    => $paketId,
                'duration' => $duration,
                'tariff'   => $weekend ? 'weekend' : 'weekday',
                'mulai'    => $startIso,
                'kembali'  => $endIso,
                'h12'      => $pkg['harga_wk_12h'],
                'h24'      => $pkg['harga_wk_24h'],
                'total'    => $total,
            ]);

            // Masukkan alokasi ke penyewaan_unit
            $insAlloc = $pdo->prepare("INSERT INTO penyewaan_unit (id_sewa, id_unit) VALUES (:sewa, :unit)");
            foreach ($allocatedUnits as $uid) {
                $insAlloc->execute(['sewa' => $rentalId, 'unit' => $uid]);
            }

            // Catat pembayaran cash
            $payment = $pdo->prepare(
                "INSERT INTO pembayaran
                 (id_sewa, id_operator_verifikasi, metode_pembayaran, total_tagihan, status_bayar, waktu_bayar, waktu_verifikasi)
                 VALUES (:id, :operator, 'cash', :total, :st,
                         CASE WHEN :st = 'lunas' THEN CURRENT_TIMESTAMP ELSE NULL END,
                         CASE WHEN :st = 'lunas' THEN CURRENT_TIMESTAMP ELSE NULL END)"
            );
            $payment->execute([
                'id'       => $rentalId,
                'operator' => $user['id_user'],
                'total'    => $total,
                'st'       => $payStatus,
            ]);

            sync_unit_status($pdo, $allocatedUnits, 'disewa');
            $pdo->commit();
            admin_flash('Sewa di tempat berhasil dibuat. ID Sewa: ' . $rentalId);

            // 2. VERIFIKASI PEMBAYARAN & JAMINAN
        } elseif ($action === 'verify') {
            if (empty($_POST['confirm_payment']) || empty($_POST['confirm_guarantee'])) {
                throw new InvalidArgumentException('Konfirmasi pembayaran dan jaminan wajib dicentang.');
            }

            $pdo->beginTransaction();
            $rental = $pdo->prepare('SELECT * FROM penyewaan WHERE id_sewa = :id AND id_cabang = :branch FOR UPDATE');
            $rental->execute(['id' => $rentalId, 'branch' => $branchId]);
            $booking = $rental->fetch();

            if (!$booking || $booking['status_sewa'] !== 'menunggu') {
                throw new InvalidArgumentException('Pesanan tidak ditemukan atau sudah diproses.');
            }

            $payStmt = $pdo->prepare('SELECT * FROM pembayaran WHERE id_sewa = :id FOR UPDATE');
            $payStmt->execute(['id' => $rentalId]);
            $payment = $payStmt->fetch();
            if (!$payment || $payment['status_bayar'] === 'ditolak') {
                throw new InvalidArgumentException('Data pembayaran pesanan tidak valid atau sudah ditolak.');
            }

            // Cek apakah unit sudah dialokasikan saat checkout pelanggan; jika belum, alokasikan sekarang
            $allocStmt = $pdo->prepare('SELECT id_unit FROM penyewaan_unit WHERE id_sewa = :id');
            $allocStmt->execute(['id' => $rentalId]);
            $allocatedUnits = array_map('intval', $allocStmt->fetchAll(PDO::FETCH_COLUMN));

            if (!$allocatedUnits) {
                $reqStmt = $pdo->prepare("SELECT id_paket_unit, jumlah FROM v_kebutuhan_unit WHERE id_paket = :p ORDER BY id_paket_unit");
                $reqStmt->execute(['p' => (int)$booking['id_paket']]);
                $requirements = $reqStmt->fetchAll();

                $findEmpty = $pdo->prepare(
                    "SELECT id_unit FROM unit_kosong(:paket_unit, CAST(:m AS timestamptz), CAST(:s AS timestamptz))
                     WHERE id_cabang = :branch
                     LIMIT :lim"
                );
                $insAlloc = $pdo->prepare("INSERT INTO penyewaan_unit (id_sewa, id_unit) VALUES (:sewa, :unit)");

                foreach ($requirements as $req) {
                    $findEmpty->bindValue(':paket_unit', (int)$req['id_paket_unit'], PDO::PARAM_INT);
                    $findEmpty->bindValue(':m', $booking['waktu_mulai']);
                    $findEmpty->bindValue(':s', $booking['waktu_kembali_rencana']);
                    $findEmpty->bindValue(':branch', $branchId, PDO::PARAM_INT);
                    $findEmpty->bindValue(':lim', (int)$req['jumlah'], PDO::PARAM_INT);
                    $findEmpty->execute();
                    $found = $findEmpty->fetchAll(PDO::FETCH_COLUMN);

                    if (count($found) < (int)$req['jumlah']) {
                        throw new InvalidArgumentException('Unit kosong tidak mencukupi untuk jadwal pesanan ini.');
                    }
                    foreach ($found as $uid) {
                        $insAlloc->execute(['sewa' => $rentalId, 'unit' => (int)$uid]);
                        $allocatedUnits[] = (int)$uid;
                    }
                }
            }

            $nextStatus = $booking['tipe_layanan'] === 'delivery' ? 'diantar' : 'siap';
            $update = $pdo->prepare(
                "UPDATE penyewaan
                 SET status_sewa = :status, status_verifikasi_jaminan = 'terverifikasi', id_operator = :operator
                 WHERE id_sewa = :id AND id_cabang = :branch"
            );
            $update->execute([
                'status'   => $nextStatus,
                'operator' => $user['id_user'],
                'id'       => $rentalId,
                'branch'   => $branchId,
            ]);

            $paid = $pdo->prepare(
                "UPDATE pembayaran
                 SET status_bayar = 'lunas', id_operator_verifikasi = :operator,
                     waktu_bayar = COALESCE(waktu_bayar, CURRENT_TIMESTAMP),
                     waktu_verifikasi = CURRENT_TIMESTAMP
                 WHERE id_sewa = :id"
            );
            $paid->execute(['operator' => $user['id_user'], 'id' => $rentalId]);

            if ($booking['tipe_layanan'] === 'delivery') {
                $delivery = $pdo->prepare("UPDATE delivery_info SET status_delivery = 'masih_diantar' WHERE id_sewa = :id");
                $delivery->execute(['id' => $rentalId]);
            }

            sync_unit_status($pdo, $allocatedUnits, 'disewa');
            $pdo->commit();
            admin_flash('Pesanan diverifikasi. Status: ' . ($nextStatus === 'diantar' ? 'Sedang diantar' : 'Siap diambil') . '.');

            // 3. LANJUTKAN TAHAP (DIANTAR -> SAMPAI -> AKTIF)
        } elseif ($action === 'advance') {
            $pdo->beginTransaction();
            $rental = $pdo->prepare('SELECT * FROM penyewaan WHERE id_sewa = :id AND id_cabang = :branch FOR UPDATE');
            $rental->execute(['id' => $rentalId, 'branch' => $branchId]);
            $booking = $rental->fetch();
            if (!$booking) {
                throw new InvalidArgumentException('Pesanan tidak ditemukan.');
            }

            if ($booking['status_sewa'] === 'diantar' && $booking['tipe_layanan'] === 'delivery') {
                $pdo->prepare("UPDATE penyewaan SET status_sewa = 'sampai' WHERE id_sewa = :id AND id_cabang = :branch")
                    ->execute(['id' => $rentalId, 'branch' => $branchId]);
                $pdo->prepare("UPDATE delivery_info SET status_delivery = 'sudah_sampai' WHERE id_sewa = :id")
                    ->execute(['id' => $rentalId]);
                $message = 'Kedatangan unit dicatat. Aktifkan sewa setelah diterima pelanggan.';
            } elseif (in_array($booking['status_sewa'], ['siap', 'sampai'], true)) {
                if ($booking['status_verifikasi_jaminan'] !== 'terverifikasi') {
                    throw new InvalidArgumentException('Jaminan belum terverifikasi.');
                }
                $paySt = $pdo->prepare('SELECT status_bayar FROM pembayaran WHERE id_sewa = :id');
                $paySt->execute(['id' => $rentalId]);
                if ($paySt->fetchColumn() !== 'lunas') {
                    throw new InvalidArgumentException('Pembayaran belum lunas.');
                }

                // Trigger trg_penyewaan_sinkron_unit akan otomatis menyelaraskan waktu di penyewaan_unit
                $update = $pdo->prepare(
                    "UPDATE penyewaan
                     SET status_sewa = 'aktif',
                         waktu_mulai = CURRENT_TIMESTAMP,
                         waktu_kembali_rencana = CURRENT_TIMESTAMP + (waktu_kembali_rencana - waktu_mulai)
                     WHERE id_sewa = :id AND id_cabang = :branch"
                );
                $update->execute(['id' => $rentalId, 'branch' => $branchId]);

                $allocStmt = $pdo->prepare('SELECT id_unit FROM penyewaan_unit WHERE id_sewa = :id');
                $allocStmt->execute(['id' => $rentalId]);
                sync_unit_status($pdo, $allocStmt->fetchAll(PDO::FETCH_COLUMN), 'disewa');
                $message = 'Unit diserahkan dan masa sewa telah diaktifkan.';
            } else {
                throw new InvalidArgumentException('Tahap pesanan ini tidak dapat dilanjutkan.');
            }

            $pdo->commit();
            admin_flash($message);

            // 4. CHECK-OUT / SELESAIKAN SEWA
        } elseif ($action === 'finish') {
            $condition = (string)($_POST['kondisi_unit'] ?? 'baik');
            if (!in_array($condition, ['baik', 'maintenance', 'rusak'], true)) {
                throw new InvalidArgumentException('Kondisi unit tidak valid.');
            }

            $pdo->beginTransaction();
            $rental = $pdo->prepare(
                "SELECT s.*, p.denda_per_jam
                 FROM penyewaan s
                 JOIN paket_sewa p ON p.id_paket = s.id_paket
                 WHERE s.id_sewa = :id AND s.id_cabang = :branch FOR UPDATE OF s"
            );
            $rental->execute(['id' => $rentalId, 'branch' => $branchId]);
            $booking = $rental->fetch();

            if (!$booking || !in_array($booking['status_sewa'], ['aktif', 'habis', 'terlambat'], true)) {
                throw new InvalidArgumentException('Pesanan tidak aktif atau sudah ditutup.');
            }

            $allocStmt = $pdo->prepare('SELECT id_unit FROM penyewaan_unit WHERE id_sewa = :id');
            $allocStmt->execute(['id' => $rentalId]);
            $allocatedUnits = array_map('intval', $allocStmt->fetchAll(PDO::FETCH_COLUMN));

            $minutesLate = 0;
            if ($booking['tipe_layanan'] !== 'ditempat') {
                $plannedReturn = new DateTimeImmutable($booking['waktu_kembali_rencana']);
                $now = new DateTimeImmutable('now');
                $minutesLate = max(0, (int)floor(($now->getTimestamp() - $plannedReturn->getTimestamp()) / 60));
            }
            $lateHours = $minutesLate <= 10 ? 0 : (int)ceil(($minutesLate - 10) / 60);
            $rateDenda = (float)$booking['denda_per_jam'] > 0 ? (float)$booking['denda_per_jam'] : 10000;

            // Update status_sewa -> trigger otomatis mengubah penyewaan_unit.aktif = FALSE
            $finish = $pdo->prepare(
                "UPDATE penyewaan
                 SET status_sewa = 'selesai', waktu_kembali_aktual = CURRENT_TIMESTAMP
                 WHERE id_sewa = :id AND id_cabang = :branch"
            );
            $finish->execute(['id' => $rentalId, 'branch' => $branchId]);

            $preferState = $condition === 'baik' ? 'tersedia' : $condition;
            sync_unit_status($pdo, $allocatedUnits, $preferState);

            if ($lateHours > 0) {
                $totalDenda = $lateHours * $rateDenda;
                $fine = $pdo->prepare(
                    "INSERT INTO denda (id_sewa, keterlambatan_jam, total_denda, status_denda)
                     VALUES (:id, :hours, :total, 'belum_bayar')
                     ON CONFLICT (id_sewa) DO UPDATE
                     SET keterlambatan_jam = EXCLUDED.keterlambatan_jam,
                         total_denda = EXCLUDED.total_denda,
                         status_denda = 'belum_bayar'"
                );
                $fine->execute(['id' => $rentalId, 'hours' => $lateHours, 'total' => $totalDenda]);
            }

            $pdo->commit();
            admin_flash('Check-Out berhasil.' . ($lateHours > 0 ? ' Denda keterlambatan: ' . $lateHours . ' jam (Rp ' . number_format($lateHours * $rateDenda, 0, ',', '.') . ').' : ''));

            // 5. TOLAK PESANAN
        } elseif ($action === 'reject') {
            $reason = trim((string)($_POST['alasan'] ?? 'Pesanan ditolak oleh admin.'));
            if ($reason === '' || strlen($reason) > 500) {
                throw new InvalidArgumentException('Alasan penolakan wajib diisi (maksimal 500 karakter).');
            }

            $pdo->beginTransaction();
            $rental = $pdo->prepare('SELECT status_sewa FROM penyewaan WHERE id_sewa = :id AND id_cabang = :branch FOR UPDATE');
            $rental->execute(['id' => $rentalId, 'branch' => $branchId]);
            $booking = $rental->fetch();

            if (!$booking || $booking['status_sewa'] !== 'menunggu') {
                throw new InvalidArgumentException('Hanya pesanan yang menunggu verifikasi yang dapat ditolak.');
            }

            $allocStmt = $pdo->prepare('SELECT id_unit FROM penyewaan_unit WHERE id_sewa = :id');
            $allocStmt->execute(['id' => $rentalId]);
            $allocatedUnits = array_map('intval', $allocStmt->fetchAll(PDO::FETCH_COLUMN));

            // Update status_sewa -> trigger otomatis membebaskan penyewaan_unit.aktif = FALSE
            $reject = $pdo->prepare(
                "UPDATE penyewaan
                 SET status_sewa = 'ditolak', status_verifikasi_jaminan = 'ditolak', alasan_penolakan = :reason
                 WHERE id_sewa = :id AND id_cabang = :branch"
            );
            $reject->execute(['reason' => $reason, 'id' => $rentalId, 'branch' => $branchId]);

            $payment = $pdo->prepare("UPDATE pembayaran SET status_bayar = 'ditolak' WHERE id_sewa = :id AND status_bayar <> 'lunas'");
            $payment->execute(['id' => $rentalId]);

            sync_unit_status($pdo, $allocatedUnits, 'tersedia');
            $pdo->commit();
            admin_flash('Pesanan ditolak dan stok unit telah dikembalikan.');
        } else {
            throw new InvalidArgumentException('Aksi tidak dikenali.');
        }
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        admin_flash($exception->getMessage());
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Proses penyewaan admin gagal: ' . $exception->getMessage());
        admin_flash('Proses gagal disimpan. Periksa ketersediaan unit atau integritas jadwal, lalu coba lagi.');
    }

    header('Location: ' . $returnUrl);
    exit;
}

// FILTER & DATA QUERY
$statusFilter  = (string)($_GET['status'] ?? 'all');
$serviceFilter = (string)($_GET['layanan'] ?? 'all');
$jenisFilter   = (string)($_GET['jenis'] ?? 'all');
$search        = trim((string)($_GET['q'] ?? ''));

$validStatus   = ['all', 'menunggu', 'proses', 'aktif', 'lewat', 'selesai', 'ditolak'];
$validServices = ['all', 'ditempat', 'pickup', 'delivery'];
$validJenis    = ['all', 'ps3', 'ps4', 'ps5', 'tv', 'combo'];

if (!in_array($statusFilter, $validStatus, true)) $statusFilter = 'all';
if (!in_array($serviceFilter, $validServices, true)) $serviceFilter = 'all';
if (!in_array($jenisFilter, $validJenis, true)) $jenisFilter = 'all';

$rentals           = [];
$customers         = [];
$availablePackages = [];
$stats             = ['menunggu' => 0, 'aktif' => 0, 'telat' => 0, 'pendapatan' => 0, 'tunda' => 0];

if ($branchId) {
    $summary = $pdo->prepare(
        "SELECT
         COUNT(*) FILTER (WHERE s.status_sewa = 'menunggu') AS menunggu,
         COUNT(*) FILTER (WHERE s.status_sewa IN ('aktif', 'diantar', 'sampai')) AS aktif,
         COUNT(*) FILTER (WHERE s.status_sewa IN ('aktif', 'habis', 'terlambat') AND s.waktu_kembali_rencana < now()) AS telat,
         COALESCE(SUM(p.total_tagihan) FILTER (WHERE p.status_bayar = 'lunas'), 0) AS pendapatan,
         COALESCE(SUM(p.total_tagihan) FILTER (WHERE p.status_bayar IN ('belum_bayar', 'menunggu_verifikasi')), 0) AS tunda
         FROM penyewaan s
         LEFT JOIN pembayaran p ON p.id_sewa = s.id_sewa
         WHERE s.id_cabang = :branch"
    );
    $summary->execute(['branch' => $branchId]);
    $stats = $summary->fetch();

    $filters = ['s.id_cabang = :branch'];
    $params  = ['branch' => $branchId];

    if ($statusFilter === 'menunggu') {
        $filters[] = "s.status_sewa = 'menunggu'";
    } elseif ($statusFilter === 'proses') {
        $filters[] = "s.status_sewa IN ('siap', 'diantar', 'sampai')";
    } elseif ($statusFilter === 'aktif') {
        $filters[] = "s.status_sewa IN ('aktif', 'habis', 'terlambat') AND s.waktu_kembali_rencana >= now()";
    } elseif ($statusFilter === 'lewat') {
        $filters[] = "(s.status_sewa IN ('habis', 'terlambat') OR (s.status_sewa = 'aktif' AND s.waktu_kembali_rencana < now()))";
    } elseif ($statusFilter !== 'all') {
        $filters[] = 's.status_sewa = :status';
        $params['status'] = $statusFilter;
    }

    if ($serviceFilter !== 'all') {
        $filters[] = 's.tipe_layanan = :service';
        $params['service'] = $serviceFilter;
    }
    if ($jenisFilter !== 'all') {
        $filters[] = 'paket.kategori = :jenis';
        $params['jenis'] = $jenisFilter;
    }
    if ($search !== '') {
        $filters[] = '(s.id_sewa ILIKE :search OR u.nama ILIKE :search OR u.no_wa ILIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    // Menggabungkan unit-unit yang dialokasikan lewat tabel penyewaan_unit
    $list = $pdo->prepare(
        "SELECT s.*,
                u.nama AS nama_pelanggan, u.no_wa, u.is_walkin,
                paket.nama_paket, paket.kategori, paket.denda_per_jam,
                pembayaran.status_bayar, pembayaran.metode_pembayaran, pembayaran.total_tagihan,
                pembayaran.file_bukti, pembayaran.nama_pengirim, pembayaran.bank_asal,
                delivery_info.alamat_tujuan, delivery_info.ongkir,
                alloc.daftar_serial, alloc.daftar_ruang
         FROM penyewaan s
         JOIN users u ON u.id_user = s.id_user
         JOIN paket_sewa paket ON paket.id_paket = s.id_paket
         LEFT JOIN pembayaran ON pembayaran.id_sewa = s.id_sewa
         LEFT JOIN delivery_info ON delivery_info.id_sewa = s.id_sewa
         LEFT JOIN LATERAL (
             SELECT string_agg(up.kode_barcode, ', ' ORDER BY up.id_unit) AS daftar_serial,
                    string_agg(up.nama_unit_ruang, ', ' ORDER BY up.id_unit) AS daftar_ruang
             FROM penyewaan_unit pu
             JOIN unit_ps up ON up.id_unit = pu.id_unit
             WHERE pu.id_sewa = s.id_sewa
         ) alloc ON TRUE
         WHERE " . implode(' AND ', $filters) . "
         ORDER BY s.created_at DESC LIMIT 200"
    );
    $list->execute($params);
    $rentals = $list->fetchAll();

    // Daftar pelanggan (Walk-in diletakkan paling atas agar mudah dipilih operator)
    $customers = $pdo->query(
        "SELECT id_user, nama, no_wa, is_walkin
         FROM users
         WHERE role = 'pelanggan' AND is_active = TRUE
         ORDER BY is_walkin DESC, nama ASC"
    )->fetchAll();

    // Daftar paket & stok tersedia real-time dari v_stok_paket
    $pkgStmt = $pdo->prepare(
        "SELECT p.id_paket, p.nama_paket, p.kategori, p.harga_wk_12h, p.harga_wk_24h, p.harga_wn_12h, p.harga_wn_24h,
                COALESCE(v.stok_tersedia, 0) AS stok_tersedia
         FROM paket_sewa p
         LEFT JOIN v_stok_paket v ON v.id_paket = p.id_paket
         WHERE p.id_cabang = :branch AND p.override_status = 'auto' AND p.is_active = TRUE
         ORDER BY p.kategori, p.nama_paket"
    );
    $pkgStmt->execute(['branch' => $branchId]);
    $availablePackages = $pkgStmt->fetchAll();
}

$isWeekendNow = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('N') >= 5;
admin_page_header('Penyewaan', 'rentals', $branch, $branches, $branchId);
?>

<?php if ($branchError): ?>
    <div class="alert alert-warning rounded-4"><?= admin_escape($branchError) ?></div>
<?php else: ?>

    <!-- STATS CARDS (Struktur dari penyewaan.html) -->
    <div class="row g-3 g-md-4 mb-4">
        <div class="col-6 col-lg-3">
            <a href="?branch_id=<?= $branchId ?>&status=menunggu" class="text-decoration-none">
                <div class="card card-yellow stat border-0 rounded-4 p-3 shadow-sm h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted fw-bold">MENUNGGU VERIFIKASI</small>
                            <h3 class="fw-bold mt-2 mb-0 text-warning"><?= (int)$stats['menunggu'] ?></h3>
                        </div>
                        <div class="bg-white p-2 rounded-3 text-warning d-none d-sm-block"><i class="bi bi-hourglass-split fs-3"></i></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="?branch_id=<?= $branchId ?>&status=aktif" class="text-decoration-none">
                <div class="card card-blue stat border-0 rounded-4 p-3 shadow-sm h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted fw-bold">UNIT KELUAR</small>
                            <h3 class="fw-bold mt-2 mb-0 text-primary"><?= (int)$stats['aktif'] ?></h3>
                        </div>
                        <div class="bg-white p-2 rounded-3 text-primary d-none d-sm-block"><i class="bi bi-controller fs-3"></i></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="?branch_id=<?= $branchId ?>&status=lewat" class="text-decoration-none">
                <div class="card card-pink stat border-0 rounded-4 p-3 shadow-sm h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted fw-bold">WAKTU HABIS / TERLAMBAT</small>
                            <h3 class="fw-bold mt-2 mb-0 text-danger"><?= (int)$stats['telat'] ?></h3>
                        </div>
                        <div class="bg-white p-2 rounded-3 text-danger d-none d-sm-block"><i class="bi bi-alarm fs-3"></i></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card card-green border-0 rounded-4 p-3 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted fw-bold">TOTAL PEMBAYARAN SEWA</small>
                        <h3 class="fw-bold mt-2 mb-0 text-success"><?= admin_money($stats['pendapatan']) ?></h3>
                        <?php if ((float)$stats['tunda'] > 0): ?>
                            <small class="text-muted d-block mt-1 fz-70">Tertunda: <?= admin_money($stats['tunda']) ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="bg-white p-2 rounded-3 text-success d-none d-sm-block"><i class="bi bi-graph-up-arrow fs-3"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- TABLE CARD (Struktur dari penyewaan.html) -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-bottom-0 pt-3 pt-md-4 pb-0 px-3 px-md-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="fw-bold text-dark m-0">Daftar Penyewaan</h5>
                <small class="text-muted">Menampilkan maksimal 200 transaksi terbaru cabang ini.</small>
            </div>
            <button class="btn btn-primary rounded-pill px-3 shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalTambahSewa">
                <i class="bi bi-plus-lg me-1"></i> Tambah Sewa (Di Tempat)
            </button>
        </div>

        <div class="card-body p-3 p-md-4">
            <form method="get" class="row g-2 mb-3">
                <input type="hidden" name="branch_id" value="<?= $branchId ?>">
                <div class="col-6 col-md-4 col-lg-2">
                    <select class="form-select" name="status" aria-label="Filter status" onchange="this.form.submit()">
                        <?php foreach (
                            [
                                'all'      => 'Semua status',
                                'menunggu' => 'Menunggu verifikasi',
                                'proses'   => 'Siap / diantar',
                                'aktif'    => 'Aktif (di pelanggan)',
                                'lewat'    => 'Waktu habis / terlambat',
                                'selesai'  => 'Selesai',
                                'ditolak'  => 'Ditolak'
                            ] as $val => $lbl
                        ): ?>
                            <option value="<?= $val ?>" <?= $statusFilter === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <select class="form-select" name="layanan" aria-label="Filter layanan" onchange="this.form.submit()">
                        <?php foreach (
                            [
                                'all'      => 'Semua layanan',
                                'ditempat' => 'Di tempat',
                                'pickup'   => 'Pickup',
                                'delivery' => 'Delivery'
                            ] as $val => $lbl
                        ): ?>
                            <option value="<?= $val ?>" <?= $serviceFilter === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-2">
                    <select class="form-select" name="jenis" aria-label="Filter unit" onchange="this.form.submit()">
                        <?php foreach (
                            [
                                'all'   => 'Semua kategori',
                                'ps3'   => 'PS 3',
                                'ps4'   => 'PS 4',
                                'ps5'   => 'PS 5',
                                'tv'    => 'TV LED',
                                'combo' => 'Paket Combo'
                            ] as $val => $lbl
                        ): ?>
                            <option value="<?= $val ?>" <?= $jenisFilter === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" name="q" placeholder="Cari nama, WA, atau ID sewa..." value="<?= admin_escape($search) ?>">
                        <button class="btn btn-outline-primary" type="submit">Cari</button>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle text-nowrap mb-0">
                    <thead class="table-light text-muted">
                        <tr>
                            <th>ID Sewa</th>
                            <th>Pelanggan</th>
                            <th>Paket & Unit</th>
                            <th>Layanan</th>
                            <th>Tagihan</th>
                            <th>Jadwal / Sisa</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rentals): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">Tidak ada penyewaan yang cocok dengan filter.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rentals as $r):
                                $endTs   = strtotime($r['waktu_kembali_rencana']);
                                $startTs = strtotime($r['waktu_mulai']);
                                $isActiveState = in_array($r['status_sewa'], ['aktif', 'habis', 'terlambat'], true);
                                $isLate  = $isActiveState && $endTs < time();
                                $sisaDetik = $endTs - time();

                                $statusLabel = $isLate ? 'Terlambat' : match ($r['status_sewa']) {
                                    'menunggu'   => 'Menunggu Konfirmasi',
                                    'siap'       => 'Siap Diambil',
                                    'diantar'    => 'Sedang Diantar',
                                    'sampai'     => 'Sudah Sampai',
                                    'aktif'      => 'Aktif',
                                    'selesai'    => 'Selesai',
                                    'ditolak'    => 'Ditolak',
                                    'dibatalkan' => 'Dibatalkan',
                                    default      => ucfirst($r['status_sewa']),
                                };

                                $statusBadge = match (true) {
                                    $isLate || in_array($r['status_sewa'], ['ditolak', 'dibatalkan'], true) => 'bg-danger',
                                    $r['status_sewa'] === 'selesai' => 'bg-secondary',
                                    $r['status_sewa'] === 'aktif'   => 'bg-success',
                                    default                         => 'bg-warning text-dark',
                                };

                                $layananBadge = match ($r['tipe_layanan']) {
                                    'ditempat' => 'bg-success-subtle text-success-emphasis',
                                    'delivery' => 'bg-primary-subtle text-primary-emphasis',
                                    default    => 'bg-info-subtle text-info-emphasis',
                                };

                                $layananLabel = match ($r['tipe_layanan']) {
                                    'ditempat' => 'Di tempat',
                                    'delivery' => 'Delivery',
                                    default    => 'Pickup',
                                };

                                $unitDisplay = $r['daftar_ruang']
                                    ? $r['nama_paket'] . ' (' . $r['daftar_ruang'] . ')'
                                    : $r['nama_paket'];

                                $payLabel = ucwords(str_replace('_', ' ', $r['status_bayar'] ?? 'belum_bayar'));
                                $totalVal = (float)($r['total_tagihan'] ?? $r['total_biaya']);
                                $ongkirVal = (float)($r['ongkir'] ?? 0);
                            ?>
                                <tr
                                    data-id="<?= admin_escape($r['id_sewa']) ?>"
                                    data-serial="<?= admin_escape($r['daftar_serial'] ?? 'Belum dialokasikan') ?>"
                                    data-nama="<?= admin_escape($r['nama_pelanggan']) ?>"
                                    data-hp="<?= admin_escape($r['no_wa']) ?>"
                                    data-unit="<?= admin_escape($unitDisplay) ?>"
                                    data-layanan="<?= admin_escape($r['tipe_layanan']) ?>"
                                    data-layanan-label="<?= admin_escape($layananLabel) ?>"
                                    data-state="<?= admin_escape($r['status_sewa']) ?>"
                                    data-state-label="<?= admin_escape($statusLabel) ?>"
                                    data-state-badge="<?= admin_escape($statusBadge) ?>"
                                    data-jaminan="<?= admin_escape($r['jenis_jaminan'] ?? '-') ?>"
                                    data-alamat="<?= admin_escape($r['alamat_tujuan'] ?? ($r['tipe_layanan'] === 'ditempat' ? 'Main di tempat' : 'Ambil sendiri di toko')) ?>"
                                    data-jam-awal="<?= admin_escape(admin_format_datetime($r['waktu_mulai'])) ?>"
                                    data-jam-habis="<?= admin_escape(admin_format_datetime($r['waktu_kembali_rencana'])) ?>"
                                    data-durasi="<?= (int)$r['durasi'] . ($r['tipe_sewa'] === 'harian' ? ' Hari' : ' Jam') ?>"
                                    data-sewa="<?= admin_escape(admin_money($r['biaya_sewa'])) ?>"
                                    data-ongkir="<?= admin_escape(admin_money($ongkirVal)) ?>"
                                    data-total="<?= admin_escape(admin_money($totalVal)) ?>"
                                    data-status-bayar="<?= admin_escape($payLabel) ?>"
                                    data-metode-bayar="<?= admin_escape(strtoupper($r['metode_pembayaran'] ?? '-')) ?>"
                                    data-alasan="<?= admin_escape($r['alasan_penolakan'] ?? '') ?>">
                                    <td class="fw-bold"><?= admin_escape($r['id_sewa']) ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= admin_escape($r['nama_pelanggan']) ?></div>
                                        <small class="text-muted"><?= admin_escape($r['no_wa']) ?></small>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= admin_escape($r['nama_paket']) ?></span>
                                        <small class="d-block text-muted">
                                            <?= admin_escape($r['daftar_ruang'] ?? 'Unit otomatis saat verifikasi') ?>
                                            <?= $r['daftar_serial'] ? ' · ' . admin_escape($r['daftar_serial']) : '' ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge <?= $layananBadge ?> rounded-pill px-3 py-2"><?= $layananLabel ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-primary"><?= admin_money($totalVal) ?></div>
                                        <small class="text-muted"><?= admin_escape($payLabel) ?> (<?= admin_escape(strtoupper($r['metode_pembayaran'] ?? '-')) ?>)</small>
                                    </td>
                                    <td>
                                        <?php if ($isActiveState): ?>
                                            <span class="live-countdown fw-bold <?= $isLate ? 'text-danger' : 'text-dark' ?>" data-end="<?= $endTs ?>">
                                                --:--:--
                                            </span>
                                            <small class="d-block text-muted">Kembali: <?= admin_escape(admin_format_datetime($r['waktu_kembali_rencana'])) ?></small>
                                        <?php else: ?>
                                            <span><?= (int)$r['durasi'] . ($r['tipe_sewa'] === 'harian' ? ' Hari' : ' Jam') ?></span>
                                            <small class="d-block text-muted"><?= admin_escape(admin_format_datetime($r['waktu_mulai'])) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $statusBadge ?> px-3 py-2 rounded-pill"><?= admin_escape($statusLabel) ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill btn-open-detail" data-bs-toggle="modal" data-bs-target="#modalDetail">
                                                <i class="bi bi-eye me-1"></i> Detail
                                            </button>

                                            <?php if ($r['status_sewa'] === 'menunggu'): ?>
                                                <button type="button" class="btn btn-sm btn-success btn-open-verify" title="Verifikasi" data-bs-toggle="modal" data-bs-target="#modalVerifikasi">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger btn-open-reject" title="Tolak" data-bs-toggle="modal" data-bs-target="#modalTolak">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            <?php elseif (in_array($r['status_sewa'], ['diantar', 'siap', 'sampai'], true)): ?>
                                                <form method="post" action="<?= admin_escape($returnUrl) ?>" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="advance">
                                                    <input type="hidden" name="id_sewa" value="<?= admin_escape($r['id_sewa']) ?>">
                                                    <button type="submit" class="btn btn-sm btn-primary rounded-pill">
                                                        <?= $r['status_sewa'] === 'diantar' ? 'Konfirmasi Sampai' : 'Serahkan & Mulai' ?>
                                                    </button>
                                                </form>
                                            <?php elseif ($isActiveState): ?>
                                                <button type="button" class="btn btn-sm btn-primary rounded-pill btn-open-checkout" data-bs-toggle="modal" data-bs-target="#modalCheckout">
                                                    <i class="bi bi-box-arrow-in-down-left me-1"></i> Check-Out
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL 1: TAMBAH SEWA (DI TEMPAT) -->
    <div class="modal fade" id="modalTambahSewa" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-bottom-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle text-primary me-2"></i>Tambah Sewa (Di Tempat)</h5>
                        <small class="text-muted">Alokasi unit fisik dipilih otomatis dari stok yang tersedia.</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <form method="post" action="<?= admin_escape($returnUrl) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_onsite">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-bold small text-muted" for="addCustomer">Pelanggan</label>
                                <select class="form-select" id="addCustomer" name="id_user" required>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?= (int)$c['id_user'] ?>">
                                            <?= admin_escape($c['nama']) ?><?= $c['is_walkin'] ? ' (Default Sewa Di Tempat)' : ' · ' . admin_escape($c['no_wa']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold small text-muted" for="addPaket">Pilih Paket Sewa</label>
                                <select class="form-select" id="addPaket" name="id_paket" required>
                                    <option value="" selected disabled>-- Pilih Paket --</option>
                                    <?php foreach ($availablePackages as $pkg):
                                        $stok = (int)$pkg['stok_tersedia'];
                                        $r12  = (float)$pkg[$isWeekendNow ? 'harga_wn_12h' : 'harga_wk_12h'];
                                        $r24  = (float)$pkg[$isWeekendNow ? 'harga_wn_24h' : 'harga_wk_24h'];
                                    ?>
                                        <option value="<?= (int)$pkg['id_paket'] ?>"
                                            data-r12="<?= $r12 ?>"
                                            data-r24="<?= $r24 ?>"
                                            <?= $stok < 1 ? 'disabled' : '' ?>>
                                            <?= admin_escape($pkg['nama_paket']) ?> (Stok: <?= $stok ?> · 12j: <?= admin_money($r12) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-muted" for="addDurasiJam">Durasi Sewa (Jam)</label>
                                <input type="number" class="form-control" id="addDurasiJam" name="durasi" min="1" max="24" value="1" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-muted" for="addStatusBayar">Status Pembayaran</label>
                                <select class="form-select" id="addStatusBayar" name="status_bayar">
                                    <option value="lunas" selected>Lunas (Cash)</option>
                                    <option value="belum_bayar">Belum Bayar</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold small text-muted">Estimasi Total Tagihan (Tarif <?= $isWeekendNow ? 'Weekend' : 'Weekday' ?>)</label>
                                <input type="text" class="form-control bg-light fw-bold text-primary fs-5" id="addTotalOtomatis" value="Rp 0" readonly>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-top-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary px-4 rounded-pill">Mulai Sewa</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL 2: DETAIL PESANAN -->
    <div class="modal fade" id="modalDetail" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-0 pb-0">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <h5 class="modal-title fw-bold">Detail Pesanan <span id="dtlId" class="text-primary"></span></h5>
                        <span id="dtlBadgeState" class="badge px-3 py-2 rounded-pill"></span>
                        <span id="dtlBadgeLayanan" class="badge bg-secondary-subtle text-dark px-3 py-2 rounded-pill"></span>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="p-3 bg-light rounded-4">
                                <small class="text-muted text-uppercase fw-bold d-block mb-2 fz-75"><i class="bi bi-person-vcard me-1"></i> Identitas & Pengiriman</small>
                                <div class="row g-2">
                                    <div class="col-md-6"><small class="text-muted d-block">No. WhatsApp:</small> <span class="fw-bold" id="dtlHp">-</span></div>
                                    <div class="col-md-6"><small class="text-muted d-block">Jaminan Identitas:</small> <span class="fw-bold text-dark" id="dtlJaminan">-</span></div>
                                    <div class="col-12"><small class="text-muted d-block">Alamat / Lokasi:</small> <span class="fw-semibold" id="dtlAlamat">-</span></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded-4 h-100">
                                <small class="text-muted text-uppercase fw-bold d-block mb-2 fz-75"><i class="bi bi-info-circle me-1"></i> Pelanggan & Unit</small>
                                <table class="table table-borderless table-sm mb-0">
                                    <tr>
                                        <td class="text-muted ps-0">Pelanggan:</td>
                                        <td class="fw-bold text-end" id="dtlNama">-</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted ps-0">Paket & Unit:</td>
                                        <td class="fw-bold text-end text-primary" id="dtlUnit">-</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted ps-0">Serial Unit:</td>
                                        <td class="fw-semibold text-end" id="dtlSerial">-</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted ps-0">Pembayaran:</td>
                                        <td class="fw-semibold text-end" id="dtlStatusBayar">-</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded-4 h-100">
                                <small class="text-muted text-uppercase fw-bold d-block mb-2 fz-75"><i class="bi bi-clock me-1"></i> Waktu Sewa</small>
                                <table class="table table-borderless table-sm mb-0">
                                    <tr>
                                        <td class="text-muted ps-0">Waktu Mulai:</td>
                                        <td class="fw-bold text-end" id="dtlJamAwal">-</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted ps-0">Batas Kembali:</td>
                                        <td class="fw-bold text-end text-danger" id="dtlJamHabis">-</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted ps-0">Durasi:</td>
                                        <td class="text-end fw-semibold" id="dtlDurasi">-</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="p-3 border rounded-4">
                                <small class="text-muted text-uppercase fw-bold d-block mb-2 fz-75"><i class="bi bi-receipt me-1"></i> Rincian Tagihan</small>
                                <div class="d-flex justify-content-between mb-1"><span class="text-muted">Biaya Sewa</span><span class="fw-semibold" id="dtlBiayaSewa">Rp 0</span></div>
                                <div class="d-flex justify-content-between mb-1"><span class="text-muted">Biaya Ongkir</span><span class="fw-semibold" id="dtlOngkir">Rp 0</span></div>
                                <hr class="my-2">
                                <div class="d-flex justify-content-between align-items-center"><span class="fw-bold text-dark">Total Tagihan</span><span class="fs-5 fw-bold text-primary" id="dtlTotalTagihan">Rp 0</span></div>
                            </div>
                        </div>
                        <div class="col-12 d-none" id="secAlasanTolak">
                            <div class="p-3 border border-danger bg-danger bg-opacity-10 rounded-4 small text-danger">
                                <strong>Alasan Penolakan:</strong> <span id="dtlAlasan"></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 3: CHECK-OUT / SELESAIKAN SEWA -->
    <div class="modal fade" id="modalCheckout" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold">Check-Out <span id="coId" class="text-primary"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <form method="post" action="<?= admin_escape($returnUrl) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="finish">
                    <input type="hidden" name="id_sewa" id="coInputId" value="">
                    <div class="modal-body">
                        <table class="table table-borderless table-sm mb-3">
                            <tr>
                                <td class="text-muted ps-0">Pelanggan</td>
                                <td class="text-end fw-semibold" id="coNama"></td>
                            </tr>
                            <tr>
                                <td class="text-muted ps-0">Unit</td>
                                <td class="text-end fw-semibold" id="coUnit"></td>
                            </tr>
                            <tr>
                                <td class="text-muted ps-0">Batas Kembali</td>
                                <td class="text-end" id="coRencana"></td>
                            </tr>
                        </table>
                        <div class="mb-2">
                            <label class="form-label fw-bold small text-muted" for="coKondisi">Kondisi Unit Saat Kembali</label>
                            <select class="form-select" id="coKondisi" name="kondisi_unit" required>
                                <option value="baik" selected>Baik (Unit kembali tersedia)</option>
                                <option value="maintenance">Perlu Maintenance</option>
                                <option value="rusak">Rusak</option>
                            </select>
                        </div>
                        <small class="text-muted d-block mt-2">Sistem akan otomatis menghitung denda jika keterlambatan melewati toleransi 10 menit (kecuali sewa di tempat).</small>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4">Konfirmasi Check-Out</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL 4: VERIFIKASI PEMBAYARAN & JAMINAN -->
    <div class="modal fade" id="modalVerifikasi" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold">Verifikasi <span id="vId" class="text-primary"></span></h5>
                        <small class="text-muted">Unit akan otomatis dialokasikan setelah verifikasi disetujui.</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <form method="post" action="<?= admin_escape($returnUrl) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="verify">
                    <input type="hidden" name="id_sewa" id="vInputId" value="">
                    <div class="modal-body">
                        <table class="table table-borderless table-sm mb-3">
                            <tr>
                                <td class="text-muted ps-0">Pelanggan</td>
                                <td class="text-end fw-semibold" id="vNama"></td>
                            </tr>
                            <tr>
                                <td class="text-muted ps-0">Layanan</td>
                                <td class="text-end" id="vLayanan"></td>
                            </tr>
                            <tr>
                                <td class="text-muted ps-0">Total Tagihan</td>
                                <td class="text-end fw-bold text-primary" id="vTotal"></td>
                            </tr>
                            <tr>
                                <td class="text-muted ps-0">Jaminan</td>
                                <td class="text-end fw-semibold" id="vJaminan"></td>
                            </tr>
                        </table>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="confirm_payment" value="1" id="vBayar" required>
                            <label class="form-check-label" for="vBayar">Pembayaran sudah masuk dan nominalnya sesuai</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="confirm_guarantee" value="1" id="vJamin" required>
                            <label class="form-check-label" for="vJamin">Jaminan valid dan sesuai identitas pelanggan</label>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-success rounded-pill px-4"><i class="bi bi-patch-check me-1"></i> Setujui & Verifikasi</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL 5: TOLAK PESANAN -->
    <div class="modal fade" id="modalTolak" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-danger">Tolak Pesanan <span id="rjId"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <form method="post" action="<?= admin_escape($returnUrl) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="id_sewa" id="rjInputId" value="">
                    <div class="modal-body">
                        <p class="small text-muted">Pelanggan: <strong id="rjNama"></strong>. Alokasi unit akan otomatis dibatalkan dan dikembalikan ke stok.</p>
                        <label class="form-label fw-bold small text-muted" for="rjAlasan">Alasan Penolakan</label>
                        <textarea class="form-control" id="rjAlasan" name="alasan" rows="3" maxlength="500" placeholder="Contoh: Bukti transfer buram / nominal tidak sesuai" required></textarea>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger rounded-pill px-4">Tolak Pesanan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const rupiah = (n) => 'Rp ' + Math.round(n).toLocaleString('id-ID');

        // 1. Kalkulator Otomatis Modal Tambah Sewa
        const selPaket = document.getElementById('addPaket');
        const inpDurasi = document.getElementById('addDurasiJam');
        const outTotal = document.getElementById('addTotalOtomatis');

        function hitungTotalOnsite() {
            if (!selPaket || !inpDurasi || !outTotal) return;
            const opt = selPaket.options[selPaket.selectedIndex];
            const r12 = parseFloat(opt?.dataset.r12 || 0);
            const r24 = parseFloat(opt?.dataset.r24 || 0);
            const dur = Math.max(1, Math.min(24, parseInt(inpDurasi.value || 1, 10)));
            if (!r12 && !r24) {
                outTotal.value = 'Rp 0';
                return;
            }
            const blocks = dur / 12;
            const total = Math.floor(blocks / 2) * r24 + ((blocks - Math.floor(blocks / 2) * 2) * r12);
            outTotal.value = rupiah(total);
        }
        selPaket?.addEventListener('change', hitungTotalOnsite);
        inpDurasi?.addEventListener('input', hitungTotalOnsite);

        // 2. Pengisian Data Modal Detail
        document.querySelectorAll('.btn-open-detail').forEach(btn => {
            btn.addEventListener('click', () => {
                const tr = btn.closest('tr');
                document.getElementById('dtlId').textContent = tr.dataset.id;
                const stBadge = document.getElementById('dtlBadgeState');
                stBadge.textContent = tr.dataset.stateLabel;
                stBadge.className = 'badge px-3 py-2 rounded-pill ' + tr.dataset.stateBadge;

                document.getElementById('dtlBadgeLayanan').textContent = tr.dataset.layananLabel;
                document.getElementById('dtlHp').textContent = tr.dataset.hp;
                document.getElementById('dtlJaminan').textContent = tr.dataset.jaminan;
                document.getElementById('dtlAlamat').textContent = tr.dataset.alamat;
                document.getElementById('dtlNama').textContent = tr.dataset.nama;
                document.getElementById('dtlUnit').textContent = tr.dataset.unit;
                document.getElementById('dtlSerial').textContent = tr.dataset.serial;
                document.getElementById('dtlStatusBayar').textContent = tr.dataset.statusBayar + ' (' + tr.dataset.metodeBayar + ')';
                document.getElementById('dtlJamAwal').textContent = tr.dataset.jamAwal;
                document.getElementById('dtlJamHabis').textContent = tr.dataset.jamHabis;
                document.getElementById('dtlDurasi').textContent = tr.dataset.durasi;
                document.getElementById('dtlBiayaSewa').textContent = tr.dataset.sewa;
                document.getElementById('dtlOngkir').textContent = tr.dataset.ongkir;
                document.getElementById('dtlTotalTagihan').textContent = tr.dataset.total;

                const secAlasan = document.getElementById('secAlasanTolak');
                if (tr.dataset.alasan) {
                    secAlasan.classList.remove('d-none');
                    document.getElementById('dtlAlasan').textContent = tr.dataset.alasan;
                } else {
                    secAlasan.classList.add('d-none');
                }
            });
        });

        // 3. Pengisian Modal Verifikasi
        document.querySelectorAll('.btn-open-verify').forEach(btn => {
            btn.addEventListener('click', () => {
                const tr = btn.closest('tr');
                document.getElementById('vId').textContent = tr.dataset.id;
                document.getElementById('vInputId').value = tr.dataset.id;
                document.getElementById('vNama').textContent = tr.dataset.nama;
                document.getElementById('vLayanan').textContent = tr.dataset.layananLabel;
                document.getElementById('vTotal').textContent = tr.dataset.total;
                document.getElementById('vJaminan').textContent = tr.dataset.jaminan;
            });
        });

        // 4. Pengisian Modal Check-Out
        document.querySelectorAll('.btn-open-checkout').forEach(btn => {
            btn.addEventListener('click', () => {
                const tr = btn.closest('tr');
                document.getElementById('coId').textContent = tr.dataset.id;
                document.getElementById('coInputId').value = tr.dataset.id;
                document.getElementById('coNama').textContent = tr.dataset.nama;
                document.getElementById('coUnit').textContent = tr.dataset.unit;
                document.getElementById('coRencana').textContent = tr.dataset.jamHabis;
            });
        });

        // 5. Pengisian Modal Tolak
        document.querySelectorAll('.btn-open-reject').forEach(btn => {
            btn.addEventListener('click', () => {
                const tr = btn.closest('tr');
                document.getElementById('rjId').textContent = tr.dataset.id;
                document.getElementById('rjInputId').value = tr.dataset.id;
                document.getElementById('rjNama').textContent = tr.dataset.nama;
            });
        });

        // 6. Countdown Live Sisa Waktu
        function updateCountdowns() {
            const nowSec = Math.floor(Date.now() / 1000);
            document.querySelectorAll('.live-countdown').forEach(el => {
                const endSec = parseInt(el.dataset.end || 0, 10);
                let diff = endSec - nowSec;
                const late = diff < 0;
                diff = Math.abs(diff);

                const h = String(Math.floor(diff / 3600)).padStart(2, '0');
                const m = String(Math.floor((diff % 3600) / 60)).padStart(2, '0');
                const s = String(diff % 60).padStart(2, '0');

                el.textContent = (late ? '- ' : '') + `${h}:${m}:${s}`;
                if (late) el.classList.replace('text-dark', 'text-danger');
            });
        }
        updateCountdowns();
        setInterval(updateCountdowns, 1000);
    });
</script>

<?php admin_page_footer(); ?>
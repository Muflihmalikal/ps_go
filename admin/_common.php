<?php
require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['owner', 'admin', 'operator']);

function admin_escape($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_flash($message = null)
{
    if ($message !== null) {
        $_SESSION['admin_flash'] = $message;
        return;
    }

    $current = $_SESSION['admin_flash'] ?? null;
    unset($_SESSION['admin_flash']);
    return $current;
}

function admin_branch_context(PDO $pdo, array $user)
{
    $branches = $pdo->query('SELECT id_cabang, nama_cabang FROM cabang ORDER BY nama_cabang')->fetchAll();
    $isOwner = ($user['role'] ?? '') === 'owner';
    $branchId = $isOwner
        ? filter_var($_REQUEST['branch_id'] ?? ($branches[0]['id_cabang'] ?? 0), FILTER_VALIDATE_INT)
        : filter_var($user['id_cabang'] ?? 0, FILTER_VALIDATE_INT);

    if (!$branchId || $branchId < 1) {
        return [$branches, 0, null, 'Cabang belum ditentukan. Pastikan akun admin memiliki cabang yang terdaftar.'];
    }

    $statement = $pdo->prepare('SELECT id_cabang, nama_cabang FROM cabang WHERE id_cabang = :id');
    $statement->execute(['id' => $branchId]);
    $branch = $statement->fetch();
    if (!$branch || (!$isOwner && (int)$branch['id_cabang'] !== (int)($user['id_cabang'] ?? 0))) {
        return [$branches, 0, null, 'Cabang yang dipilih tidak tersedia untuk akun ini.'];
    }

    return [$branches, (int)$branch['id_cabang'], $branch, null];
}

function admin_branch_query($branchId)
{
    return '?branch_id=' . rawurlencode((string)$branchId);
}

function admin_money($amount)
{
    return 'Rp ' . number_format((float)$amount, 0, ',', '.');
}

function admin_format_datetime($value)
{
    if (!$value) {
        return '-';
    }

    $date = new DateTimeImmutable((string)$value, new DateTimeZone('UTC'));
    return $date->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d M Y H:i') . ' WIB';
}

function admin_unit_has_open_rental(PDO $pdo, $unitId, $exceptRentalId)
{
    $statement = $pdo->prepare(
        "SELECT 1 FROM penyewaan
         WHERE id_unit = :unit AND id_sewa <> :rental
           AND status_sewa IN ('menunggu', 'siap', 'diantar', 'sampai', 'aktif', 'habis', 'terlambat')
         LIMIT 1"
    );
    $statement->execute(['unit' => $unitId, 'rental' => $exceptRentalId]);
    return (bool)$statement->fetchColumn();
}

function admin_page_header($title, $active, $branch, $branches, $branchId)
{
    $user = current_user();
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= admin_escape($title) ?> - PS Rental</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
        <link rel="stylesheet" href="../assets/css/style.css">
    </head>
    <body class="bg-light page-admin">
    <div id="backdrop"></div>
    <aside id="sidebar" class="sidebar-bg d-flex flex-column p-3 text-white">
        <a href="index.php" class="d-flex align-items-center mb-5 mt-2 text-white text-decoration-none">
            <i class="bi bi-controller fs-2 me-2"></i>
            <span class="fs-5 fw-bold">PS Rental</span>
        </a>
        <ul class="nav nav-pills flex-column mb-auto gap-2">
            <li><a href="index.php" class="nav-link text-white <?= $active === 'dashboard' ? 'active nav-active-blue' : 'opacity-75' ?>"><i class="bi bi-speedometer2 me-2"></i> Beranda</a></li>
            <li><a href="penyewaan.php<?= admin_branch_query($branchId) ?>" class="nav-link text-white <?= $active === 'rentals' ? 'active nav-active-blue' : 'opacity-75' ?>"><i class="bi bi-card-list me-2"></i> Penyewaan</a></li>
            <li><a href="unit-ps.php<?= admin_branch_query($branchId) ?>" class="nav-link text-white <?= $active === 'units' ? 'active nav-active-blue' : 'opacity-75' ?>"><i class="bi bi-box-seam me-2"></i> Unit PS</a></li>
            <li class="mt-4"><a href="../logout.php" class="nav-link text-danger bg-white bg-opacity-10 border border-danger"><i class="bi bi-box-arrow-left me-2"></i> Keluar</a></li>
        </ul>
    </aside>
    <main class="p-3 p-md-4">
        <header class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 bg-white p-3 rounded-4 shadow-sm">
            <div class="d-flex align-items-center gap-3">
                <button type="button" class="btn btn-light d-lg-none" id="menuBtn" aria-label="Buka menu"><i class="bi bi-list fs-4"></i></button>
                <div>
                    <h1 class="h4 fw-bold m-0"><?= admin_escape($title) ?></h1>
                    <small class="text-muted"><?= admin_escape($user['nama'] ?? '') ?> · <?= admin_escape(ucfirst($user['role'] ?? '')) ?></small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php if (($user['role'] ?? '') === 'owner' && count($branches) > 1): ?>
                    <form method="get" class="d-flex align-items-center gap-2">
                        <label for="branch_id" class="small text-muted">Cabang</label>
                        <select class="form-select form-select-sm" id="branch_id" name="branch_id" onchange="this.form.submit()">
                            <?php foreach ($branches as $option): ?>
                                <option value="<?= (int)$option['id_cabang'] ?>" <?= (int)$option['id_cabang'] === $branchId ? 'selected' : '' ?>><?= admin_escape($option['nama_cabang']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php elseif ($branch): ?>
                    <span class="badge bg-primary-subtle text-primary-emphasis"><i class="bi bi-geo-alt me-1"></i><?= admin_escape($branch['nama_cabang']) ?></span>
                <?php endif; ?>
            </div>
        </header>
        <?php if ($message = admin_flash()): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <?= admin_escape($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
            </div>
        <?php endif; ?>
    <?php
}

function admin_page_footer()
{
    ?>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sidebar = document.getElementById('sidebar');
        const backdrop = document.getElementById('backdrop');
        const menuButton = document.getElementById('menuBtn');
        if (menuButton) menuButton.addEventListener('click', () => {
            sidebar.classList.add('show');
            backdrop.classList.add('show');
            document.body.classList.add('sidebar-open');
        });
        if (backdrop) backdrop.addEventListener('click', () => {
            sidebar.classList.remove('show');
            backdrop.classList.remove('show');
            document.body.classList.remove('sidebar-open');
        });
    </script>
    </body>
    </html>
    <?php
}

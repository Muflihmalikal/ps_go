<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$__jobsheetRoot = dirname(__DIR__);
$__scriptDir = dirname($_SERVER['SCRIPT_FILENAME']);
$__rel = ltrim(str_replace('\\', '/', substr($__scriptDir, strlen($__jobsheetRoot))), '/');
$base = $__rel === '' ? '' : str_repeat('../', substr_count($__rel, '/') + 1);

$currentPage = basename($_SERVER['SCRIPT_FILENAME']);
$hiddenHeaderPages = ['form_sewa.php', 'pembayaran.php'];
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= isset($pageTitle) ? $pageTitle : 'PS Rental - Beranda Pelanggan'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" />
    <!-- DataTables & Buttons CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="<?php echo $base; ?>assets/css/style.css" />
</head>

<body class="bg-light page-beranda">
    <div id="backdrop"></div>

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="p-3 p-md-4">
        <?php if (!in_array($currentPage, $hiddenHeaderPages)): ?>
            <header class="app-topbar">
                <button type="button" class="btn btn-light d-lg-none flex-shrink-0" id="menuBtn" aria-label="Buka menu">
                    <i class="bi bi-list fs-4"></i>
                </button>
                <div class="input-group app-topbar-search">
                    <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control bg-light border-0" placeholder="Cari konsol PS, paket Playbox...">
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-2 app-topbar-status d-none d-md-inline-block">
                        <i class="bi bi-circle-fill text-success me-1 app-topbar-dot"></i>
                        Buka Setiap Hari (10:00 - 23:00 WIB)
                    </span>
                    <div class="d-flex align-items-center gap-2 app-topbar-user">
                        <div class="app-topbar-avatar">AP</div>
                        <div class="lh-1 d-none d-md-block">
                            <span class="d-block fw-bold app-topbar-name">Alya Pratama</span>
                            <small class="text-muted app-topbar-role">Pelanggan</small>
                        </div>
                    </div>
                </div>
            </header>
        <?php endif; ?>
<?php
require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['owner', 'admin', 'operator']);

$user = current_user();
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Dashboard Admin - PS Rental</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" />
</head>

<body class="bg-light">
    <main class="container py-5">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <span class="text-primary fw-semibold"><i class="bi bi-controller me-1"></i> PS Rental</span>
                <h1 class="h3 fw-bold mt-2 mb-1">Dashboard <?= htmlspecialchars(ucfirst($user['role']), ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="text-muted mb-0">Selamat datang, <?= htmlspecialchars($user['nama'], ENT_QUOTES, 'UTF-8') ?>.</p>
            </div>
            <a href="../logout.php" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-right me-1"></i> Logout</a>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <a href="penyewaan.html" class="card card-body h-100 text-decoration-none shadow-sm">
                    <span class="fs-2 text-primary"><i class="bi bi-receipt"></i></span>
                    <h2 class="h5 text-dark mt-3">Kelola Penyewaan</h2>
                    <span class="text-muted">Buka halaman penyewaan admin.</span>
                </a>
            </div>
            <div class="col-md-6">
                <a href="unit-ps.html" class="card card-body h-100 text-decoration-none shadow-sm">
                    <span class="fs-2 text-primary"><i class="bi bi-controller"></i></span>
                    <h2 class="h5 text-dark mt-3">Kelola Unit PS</h2>
                    <span class="text-muted">Buka halaman pengelolaan unit.</span>
                </a>
            </div>
        </div>
    </main>
</body>

</html>
<?php


require_once __DIR__ . '/config/koneksi.php';

header('Cache-Control: no-store');

$pesanError = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (verify_csrf_token()) {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();

        header('Location: login.php');
        exit;
    }
    $pesanError = 'Sesi formulir kedaluwarsa. Silakan coba lagi.';
}

if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex" />
    <title>Keluar - PS Rental</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" />
</head>

<body class="bg-light d-flex align-items-center justify-content-center min-vh-100 p-3">
    <div class="card border-0 shadow-sm rounded-4 p-4 text-center w-100" style="max-width: 380px;">
        <div class="fs-1 text-primary mb-2"><i class="bi bi-box-arrow-right"></i></div>
        <h1 class="h5 fw-bold">Keluar dari akun?</h1>
        <p class="text-muted small">Anda akan keluar dari PS Rental di perangkat ini.</p>

        <?php if ($pesanError): ?>
            <div class="alert alert-danger py-2 small"><?= e($pesanError) ?></div>
        <?php endif; ?>

        <form method="POST" action="logout.php" class="d-grid gap-2">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary rounded-3 fw-semibold">Ya, keluar</button>
            <a href="index.php" class="btn btn-light rounded-3">Batal</a>
        </form>
    </div>
</body>

</html>
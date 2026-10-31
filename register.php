<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user'])) {
    header('Location: ' . user_home_path($_SESSION['user']['role'] ?? 'pelanggan'));
    exit;
}

$error = $_SESSION['error'] ?? '';
$oldInput = $_SESSION['old_input'] ?? ['nama' => '', 'email' => '', 'no_wa' => ''];
unset($_SESSION['error'], $_SESSION['old_input']);

$nama = $oldInput['nama'] ?? '';
$email = $oldInput['email'] ?? '';
$noWa = $oldInput['no_wa'] ?? '';
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Daftar - PS Rental</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" />
    <style>
        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #eef5ff 0%, #f8f9ff 45%, #f2f4f8 100%);
            font-family: Arial, sans-serif;
            padding: 1rem;
        }

        .register-card {
            width: min(100%, 480px);
            border-radius: 24px;
            background: #fff;
            box-shadow: 0 24px 50px rgba(15, 23, 42, 0.12);
            padding: 2rem;
        }

        .brand-badge {
            width: 72px;
            height: 72px;
            border-radius: 20px;
            background: linear-gradient(135deg, #0d6efd, #3b82f6);
            color: #fff;
            display: grid;
            place-items: center;
            font-size: 2rem;
            margin: 0 auto 1rem;
        }
    </style>
</head>

<body>
    <main class="register-card">
        <div class="brand-badge"><i class="bi bi-controller"></i></div>
        <div class="text-center mb-4">
            <h3 class="fw-bold text-dark mb-1">Buat Akun PS Rental</h3>
            <p class="text-muted mb-0">Daftar sebagai pelanggan untuk mulai menyewa.</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" action="modules/auth/proses_register.php" autocomplete="on">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="nama" class="form-label fw-semibold">Nama lengkap</label>
                <input type="text" class="form-control" id="nama" name="nama" maxlength="100" value="<?= htmlspecialchars($nama, ENT_QUOTES, 'UTF-8') ?>" autocomplete="name" required />
            </div>
            <div class="mb-3">
                <label for="email" class="form-label fw-semibold">Email</label>
                <input type="email" class="form-control" id="email" name="email" maxlength="100" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" autocomplete="email" required />
            </div>
            <div class="mb-3">
                <label for="no_wa" class="form-label fw-semibold">Nomor WhatsApp</label>
                <input type="tel" class="form-control" id="no_wa" name="no_wa" maxlength="20" value="<?= htmlspecialchars($noWa, ENT_QUOTES, 'UTF-8') ?>" autocomplete="tel" required />
            </div>
            <div class="mb-3">
                <label for="password" class="form-label fw-semibold">Password</label>
                <input type="password" class="form-control" id="password" name="password" minlength="8" autocomplete="new-password" required />
                <div class="form-text">Minimal 8 karakter.</div>
            </div>
            <div class="mb-4">
                <label for="konfirmasi_password" class="form-label fw-semibold">Konfirmasi password</label>
                <input type="password" class="form-control" id="konfirmasi_password" name="konfirmasi_password" minlength="8" autocomplete="new-password" required />
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold rounded-3">
                <i class="bi bi-person-plus me-2"></i> Daftar
            </button>
        </form>
        <p class="text-center text-muted mt-4 mb-0">
            Sudah punya akun? <a href="login.php" class="fw-semibold text-decoration-none">Masuk</a>
        </p>
    </main>
</body>

</html>
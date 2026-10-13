<?php
require_once __DIR__ . '/includes/koneksi.php';
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user'])) {
    header('Location: ' . user_home_path($_SESSION['user']['role'] ?? 'pelanggan'));
    exit;
}

$error = '';
$nama = '';
$email = '';
$noWa = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama = trim((string)($_POST['nama'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $noWa = trim((string)($_POST['no_wa'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $konfirmasiPassword = (string)($_POST['konfirmasi_password'] ?? '');

    if (!verify_csrf_token()) {
        $error = 'Form sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif ($nama === '' || strlen($nama) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100 || $noWa === '' || strlen($noWa) > 20) {
        $error = 'Periksa kembali nama, email, dan nomor WhatsApp Anda.';
    } elseif (strlen($password) < 8) {
        $error = 'Password harus terdiri dari minimal 8 karakter.';
    } elseif ($password !== $konfirmasiPassword) {
        $error = 'Konfirmasi password tidak sama.';
    } else {
        $existingUser = $pdo->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
        $existingUser->execute(['email' => $email]);

        if ($existingUser->fetchColumn()) {
            $error = 'Email sudah terdaftar. Silakan gunakan email lain atau masuk.';
        } else {
            try {
                $insertUser = $pdo->prepare(
                    "INSERT INTO users (nama, email, password, no_wa, role) VALUES (:nama, :email, :password, :no_wa, 'pelanggan')"
                );
                $insertUser->execute([
                    'nama' => $nama,
                    'email' => $email,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'no_wa' => $noWa,
                ]);

                header('Location: login.php?registered=1');
                exit;
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23505') {
                    $error = 'Email sudah terdaftar. Silakan gunakan email lain atau masuk.';
                } else {
                    error_log('Registrasi gagal: ' . $exception->getMessage());
                    $error = 'Registrasi gagal karena gangguan sistem. Silakan coba lagi.';
                }
            }
        }
    }
}
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

        <form method="POST" action="register.php" autocomplete="on">
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

<?php
require_once __DIR__ . '/includes/koneksi.php';
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user'])) {
    header('Location: ' . user_home_path($_SESSION['user']['role'] ?? 'pelanggan'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!verify_csrf_token()) {
        $error = 'Form sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif ($email === '' || $password === '') {
        $error = 'Email dan password wajib diisi.';
    } else {
        $stmt = $pdo->prepare('SELECT id_user, nama, email, password, role, id_cabang, status_verifikasi FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => strtolower($email)]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id_user' => (int)$user['id_user'],
                'nama' => $user['nama'],
                'email' => $user['email'],
                'role' => $user['role'],
                'id_cabang' => $user['id_cabang'],
                'status_verifikasi' => $user['status_verifikasi'],
            ];

            $_SESSION['id_user'] = (int)$user['id_user'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['nama'];

            header('Location: ' . user_home_path($user['role']));
            exit;
        }

        $error = 'Email atau password salah.';
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login - PS Rental</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" />
    <style>
        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #eef5ff 0%, #f8f9ff 45%, #f2f4f8 100%);
            font-family: Arial, sans-serif;
        }
        .login-card {
            width: min(100%, 430px);
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
        .form-control:focus {
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
        }
        .alert-danger {
            border-radius: 14px;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand-badge"><i class="bi bi-controller"></i></div>
        <div class="text-center mb-4">
            <h3 class="fw-bold text-dark mb-1">PS Rental</h3>
            <p class="text-muted mb-0">Masuk ke akun Anda</p>
        </div>

        <?php if (isset($_GET['registered'])): ?>
            <div class="alert alert-success" role="status">Pendaftaran berhasil. Silakan masuk menggunakan akun Anda.</div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php" autocomplete="off">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="email" class="form-label fw-semibold text-dark">Email</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-envelope"></i></span>
                    <input type="email" class="form-control" id="email" name="email" placeholder="nama@email.com" required />
                </div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label fw-semibold text-dark">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" id="password" name="password" placeholder="Masukkan password" required />
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold rounded-3">
                <i class="bi bi-box-arrow-in-right me-2"></i> Masuk
            </button>
        </form>
        <p class="text-center text-muted mt-4 mb-0">
            Belum punya akun? <a href="register.php" class="fw-semibold text-decoration-none">Daftar sekarang</a>
        </p>
    </div>
</body>
</html>

<?php
require_once __DIR__ . '/../../config/koneksi.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../login.php');
    exit;
}

$email = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if (!verify_csrf_token()) {
    $_SESSION['error'] = 'Form sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    $_SESSION['old_email'] = $email;
    header('Location: ../../login.php');
    exit;
}

if ($email === '' || $password === '') {
    $_SESSION['error'] = 'Email dan password wajib diisi.';
    $_SESSION['old_email'] = $email;
    header('Location: ../../login.php');
    exit;
}

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

    $returnPath = safe_return_path($_SESSION['login_redirect'] ?? null);
    unset($_SESSION['login_redirect']);
    header('Location: ' . ($returnPath ?? user_home_path($user['role'])));
    exit;
}

$_SESSION['error'] = 'Email atau password salah.';
$_SESSION['old_email'] = $email;
header('Location: ../../login.php');
exit;

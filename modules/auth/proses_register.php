<?php
require_once __DIR__ . '/../../config/koneksi.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../register.php');
    exit;
}

$nama = trim((string)($_POST['nama'] ?? ''));
$email = strtolower(trim((string)($_POST['email'] ?? '')));
$noWa = trim((string)($_POST['no_wa'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$konfirmasiPassword = (string)($_POST['konfirmasi_password'] ?? '');

$oldInput = [
    'nama' => $nama,
    'email' => $email,
    'no_wa' => $noWa
];

if (!verify_csrf_token()) {
    $_SESSION['error'] = 'Form sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    $_SESSION['old_input'] = $oldInput;
    header('Location: ../../register.php');
    exit;
}

if ($nama === '' || strlen($nama) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100 || $noWa === '' || strlen($noWa) > 20) {
    $_SESSION['error'] = 'Periksa kembali nama, email, dan nomor WhatsApp Anda.';
    $_SESSION['old_input'] = $oldInput;
    header('Location: ../../register.php');
    exit;
}

if (strlen($password) < 8) {
    $_SESSION['error'] = 'Password harus terdiri dari minimal 8 karakter.';
    $_SESSION['old_input'] = $oldInput;
    header('Location: ../../register.php');
    exit;
}

if ($password !== $konfirmasiPassword) {
    $_SESSION['error'] = 'Konfirmasi password tidak sama.';
    $_SESSION['old_input'] = $oldInput;
    header('Location: ../../register.php');
    exit;
}

$existingUser = $pdo->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
$existingUser->execute(['email' => $email]);

if ($existingUser->fetchColumn()) {
    $_SESSION['error'] = 'Email sudah terdaftar. Silakan gunakan email lain atau masuk.';
    $_SESSION['old_input'] = $oldInput;
    header('Location: ../../register.php');
    exit;
}

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

    header('Location: ../../login.php?registered=1');
    exit;
} catch (PDOException $exception) {
    if ($exception->getCode() === '23505') {
        $_SESSION['error'] = 'Email sudah terdaftar. Silakan gunakan email lain atau masuk.';
    } else {
        error_log('Registrasi gagal: ' . $exception->getMessage());
        $_SESSION['error'] = 'Registrasi gagal karena gangguan sistem. Silakan coba lagi.';
    }
    $_SESSION['old_input'] = $oldInput;
    header('Location: ../../register.php');
    exit;
}

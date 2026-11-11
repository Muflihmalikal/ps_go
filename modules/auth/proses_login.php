<?php

require_once __DIR__ . '/../../config/koneksi.php';
require_once __DIR__ . '/../../includes/auth.php';

const LOGIN_MAKS_PER_IP       = 20;    // gagal per IP dalam jendela waktu
const LOGIN_MAKS_PER_PASANGAN = 5;     // gagal per (email + IP)
const LOGIN_MAKS_PER_EMAIL    = 30;    // gagal per email dari semua IP
const LOGIN_JENDELA_DETIK     = 900;   // 15 menit
const LOGIN_MAKS_PANJANG_PASS = 1024;

/** Simpan pesan + email lama ke sesi, kembali ke halaman login. */
function login_kembali(string $pesan, string $email): void
{
    $_SESSION['error']     = $pesan;
    $_SESSION['old_email'] = mb_substr($email, 0, 100);
    header('Location: ../../login.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../../login.php');
    exit;
}

$email    = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

if (!verify_csrf_token()) {
    login_kembali('Form sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.', $email);
}
if ($email === '' || $password === '') {
    login_kembali('Email dan password wajib diisi.', $email);
}
if (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    login_kembali('Format email tidak valid.', $email);
}
if (strlen($password) > LOGIN_MAKS_PANJANG_PASS) {
    login_kembali('Email atau password salah.', $email);
}

$ip        = client_ip();
$kIp       = 'ip:' . $ip;
$kPasangan = 'pair:' . $email . '|' . $ip;
$kEmail    = 'email:' . $email;

if (
    rate_limited($pdo, 'login', $kIp, LOGIN_MAKS_PER_IP, LOGIN_JENDELA_DETIK)
    || rate_limited($pdo, 'login', $kPasangan, LOGIN_MAKS_PER_PASANGAN, LOGIN_JENDELA_DETIK)
    || rate_limited($pdo, 'login', $kEmail, LOGIN_MAKS_PER_EMAIL, LOGIN_JENDELA_DETIK)
) {
    login_kembali('Terlalu banyak percobaan masuk. Coba lagi dalam beberapa menit.', $email);
}

try {
    $stmt = $pdo->prepare(
        'SELECT id_user, nama, email, password, role, id_cabang, status_verifikasi,
                CASE WHEN is_active THEN 1 ELSE 0 END AS aktif
           FROM users
          WHERE lower(email) = :email
          LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if ($user) {
        $cocok = password_verify($password, (string) $user['password']);
    } else {
        password_hash($password, PASSWORD_DEFAULT);   // hasil dibuang
        $cocok = false;
    }

    if (!$cocok) {
        rate_limit_hit($pdo, 'login', $kIp);
        rate_limit_hit($pdo, 'login', $kPasangan);
        rate_limit_hit($pdo, 'login', $kEmail);
        login_kembali('Email atau password salah.', $email);
    }

    // Password benar, tetapi akun dinonaktifkan pengelola.
    if ((int) $user['aktif'] !== 1) {
        login_kembali('Akun Anda dinonaktifkan. Hubungi pengelola.', $email);
    }

    // Catatan tambahan: kegagalan di sini tidak boleh menggagalkan login.
    try {
        if (password_needs_rehash((string) $user['password'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET password = :p WHERE id_user = :id')
                ->execute(['p' => password_hash($password, PASSWORD_DEFAULT), 'id' => (int) $user['id_user']]);
        }
        $pdo->prepare('UPDATE users SET last_login_at = now() WHERE id_user = :id')
            ->execute(['id' => (int) $user['id_user']]);
    } catch (PDOException $e) {
        error_log('proses_login: gagal memperbarui data akun: ' . $e->getMessage());
    }

    // Login sukses
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);           // token baru dibuat otomatis saat dibutuhkan
    $_SESSION['_mulai'] = time();             // batas 12 jam dihitung dari login

    $_SESSION['user'] = [
        'id_user'           => (int) $user['id_user'],
        'nama'              => $user['nama'],
        'email'             => $user['email'],
        'role'              => $user['role'],
        'id_cabang'         => $user['id_cabang'],
        'status_verifikasi' => $user['status_verifikasi'],
    ];
    // Kunci lama, dipertahankan untuk kode yang masih memakainya.
    $_SESSION['id_user']   = (int) $user['id_user'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['nama'];

    rate_limit_reset($pdo, 'login', $kPasangan);
    rate_limit_reset($pdo, 'login', $kEmail);

    $returnPath = safe_return_path($_SESSION['login_redirect'] ?? null);
    unset($_SESSION['login_redirect'], $_SESSION['error'], $_SESSION['old_email']);
    header('Location: ' . ($returnPath ?? user_home_path($user['role'])));
    exit;
} catch (Throwable $e) {
    error_log('proses_login error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    login_kembali('Terjadi gangguan sistem. Silakan coba lagi beberapa saat.', $email);
}

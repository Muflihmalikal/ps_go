<?php


require_once __DIR__ . '/../../config/koneksi.php';
require_once __DIR__ . '/../../includes/auth.php';

const REGISTER_MAKS_PER_IP    = 10;     // percobaan daftar (sukses atau tidak) per IP...
const REGISTER_JENDELA_DETIK  = 3600;   // ...dalam 1 jam
const REGISTER_MIN_PASS       = 8;
const REGISTER_MAKS_PASS_BYTE = 72;     // batas bcrypt; lebih dari ini akan terpotong diam-diam

/** Password yang terlalu umum untuk diterima. */
const REGISTER_PASS_UMUM = [
    '12345678',
    '123456789',
    '1234567890',
    '87654321',
    '11111111',
    '00000000',
    'password',
    'password1',
    'password123',
    'qwertyui',
    'qwerty123',
    'abcd1234',
    'admin123',
    'iloveyou',
    'kucing123',
    'rahasia123',
];

function register_kembali(string $pesan, array $oldInput): void
{
    $_SESSION['error']     = $pesan;
    $_SESSION['old_input'] = $oldInput;
    header('Location: ../../register.php');
    exit;
}

function normalisasi_no_wa(string $input): ?string
{
    $bersih = preg_replace('/[\s().-]+/', '', $input);
    if (!preg_match('/^\+?\d+$/', $bersih)) {
        return null;
    }
    $digit = ltrim($bersih, '+');
    if (str_starts_with($digit, '62')) {
        $digit = '0' . substr($digit, 2);
    } elseif (str_starts_with($digit, '8')) {
        $digit = '0' . $digit;
    }
    return preg_match('/^08[1-9]\d{7,10}$/', $digit) ? $digit : null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../../register.php');
    exit;
}

$ambil = static fn(string $kunci): string => is_string($_POST[$kunci] ?? null) ? $_POST[$kunci] : '';

$nama               = trim(preg_replace('/\s+/u', ' ', $ambil('nama')) ?? '');
$email              = strtolower(trim($ambil('email')));
$noWaInput          = trim($ambil('no_wa'));
$password           = $ambil('password');
$konfirmasiPassword = $ambil('konfirmasi_password');

// Isian yang dikembalikan ke form bila gagal (password tidak pernah dikembalikan).
$oldInput = [
    'nama'  => mb_substr($nama, 0, 100),
    'email' => mb_substr($email, 0, 100),
    'no_wa' => mb_substr($noWaInput, 0, 20),
];

if (!verify_csrf_token()) {
    register_kembali('Form sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.', $oldInput);
}

// --- Validasi isian (tanpa menyentuh database) ---
$panjangNama = mb_strlen($nama);
if ($panjangNama < 2 || $panjangNama > 100 || !preg_match('/^[\p{L}\p{M}][\p{L}\p{M} .\'-]*$/u', $nama)) {
    register_kembali('Nama lengkap hanya boleh berisi huruf, spasi, titik, apostrof, atau tanda hubung (2-100 karakter).', $oldInput);
}
if (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    register_kembali('Format email tidak valid.', $oldInput);
}
$noWa = normalisasi_no_wa($noWaInput);
if ($noWa === null) {
    register_kembali('Nomor WhatsApp tidak valid. Gunakan nomor seluler Indonesia, contoh 081234567890.', $oldInput);
}

if (strlen($password) < REGISTER_MIN_PASS) {
    register_kembali('Password harus terdiri dari minimal ' . REGISTER_MIN_PASS . ' karakter.', $oldInput);
}
if (strlen($password) > REGISTER_MAKS_PASS_BYTE) {
    register_kembali('Password terlalu panjang (maksimal ' . REGISTER_MAKS_PASS_BYTE . ' karakter).', $oldInput);
}
if ($password !== $konfirmasiPassword) {
    register_kembali('Konfirmasi password tidak sama.', $oldInput);
}
$bagianEmail = strstr($email, '@', true);
if (
    in_array(strtolower($password), REGISTER_PASS_UMUM, true)
    || count(array_unique(str_split($password))) === 1
    || strtolower($password) === $email
    || (strlen($bagianEmail) >= 5 && stripos($password, $bagianEmail) !== false)
) {
    register_kembali('Password terlalu mudah ditebak. Gunakan kombinasi yang lebih unik dan tidak memuat nama email Anda.', $oldInput);
}

// --- Pembatasan pendaftaran ---
$kIp = 'ip:' . client_ip();
if (rate_limited($pdo, 'register', $kIp, REGISTER_MAKS_PER_IP, REGISTER_JENDELA_DETIK, false)) {
    register_kembali('Terlalu banyak percobaan pendaftaran dari perangkat ini. Coba lagi nanti.', $oldInput);
}

try {
    $cek = $pdo->prepare('SELECT 1 FROM users WHERE lower(email) = :email LIMIT 1');
    $cek->execute(['email' => $email]);
    if ($cek->fetchColumn()) {
        rate_limit_hit($pdo, 'register', $kIp, false);
        register_kembali('Email sudah terdaftar. Silakan gunakan email lain atau masuk.', $oldInput);
    }

    $insert = $pdo->prepare(
        "INSERT INTO users (nama, email, password, no_wa, role) VALUES (:nama, :email, :password, :no_wa, 'pelanggan')"
    );
    $insert->execute([
        'nama'     => $nama,
        'email'    => $email,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'no_wa'    => $noWa,
    ]);

    rate_limit_hit($pdo, 'register', $kIp, true);
    unset($_SESSION['error'], $_SESSION['old_input']);
    header('Location: ../../login.php?registered=1');
    exit;
} catch (PDOException $e) {
    rate_limit_hit($pdo, 'register', $kIp, false);
    if ((string) ($e->errorInfo[0] ?? $e->getCode()) === '23505') {      // dua pendaftaran bersamaan, email sama
        register_kembali('Email sudah terdaftar. Silakan gunakan email lain atau masuk.', $oldInput);
    }
    error_log('Registrasi gagal: ' . $e->getMessage());
    register_kembali('Registrasi gagal karena gangguan sistem. Silakan coba lagi.', $oldInput);
} catch (Throwable $e) {
    error_log('Registrasi error tak terduga: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    register_kembali('Registrasi gagal karena gangguan sistem. Silakan coba lagi.', $oldInput);
}

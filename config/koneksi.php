<?php


if (!function_exists('loadEnv')) {
    function loadEnv(string $filePath): bool
    {
        if (!is_readable($filePath)) {
            return false;
        }
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if ($lines) {
            $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]);
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                continue;
            }
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
                $value = substr($value, 1, -1);                             
            }
            if (getenv($name) === false) {
                putenv($name . '=' . $value);
            }
        }
        return true;
    }
}

loadEnv(__DIR__ . '/../.env');

require_once __DIR__ . '/security.php';

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', (getenv('APP_ENV') ?: 'production') === 'local' ? '1' : '0');

$__zona = getenv('APP_TIMEZONE') ?: 'Asia/Jakarta';
if (!in_array($__zona, timezone_identifiers_list(), true)) {
    $__zona = 'Asia/Jakarta';
}
date_default_timezone_set($__zona);


const SESI_IDLE_DETIK = 7200;    // 2 jam tanpa aktivitas -> otomatis logout
const SESI_MAKS_DETIK = 43200;   // 12 jam sejak login -> wajib login ulang

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');   // tolak ID sesi buatan penyerang (session fixation)
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,                       // cookie hilang saat browser ditutup
        'path'     => '/',
        'secure'   => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (session_status() === PHP_SESSION_ACTIVE) {
    $__sekarang = time();
    $__mulai    = (int) ($_SESSION['_mulai'] ?? $__sekarang);
    $__terakhir = (int) ($_SESSION['_aktif_terakhir'] ?? $__sekarang);

    if ($__sekarang - $__terakhir > SESI_IDLE_DETIK || $__sekarang - $__mulai > SESI_MAKS_DETIK) {
        $_SESSION = [];                        // sesi kedaluwarsa: kosongkan dan beri ID baru
        session_regenerate_id(true);
        $__mulai = $__sekarang;
    }
    $_SESSION['_mulai']          = $__mulai;
    $_SESSION['_aktif_terakhir'] = $__sekarang;
}

send_security_headers();


$__host    = getenv('DB_HOST') ?: '127.0.0.1';
$__port    = getenv('DB_PORT') ?: '5432';
$__dbname  = getenv('DB_NAME');
$__user    = getenv('DB_USER');
$__pass    = getenv('DB_PASS');                // boleh kosong hanya bila memang diatur kosong (mis. lokal tanpa password)
$__lokal   = in_array($__host, ['127.0.0.1', 'localhost', '::1'], true);
$__sslmode = getenv('DB_SSLMODE') ?: ($__lokal ? 'prefer' : 'require');

if (
    $__dbname === false || $__dbname === '' || $__user === false || $__user === '' || $__pass === false
    || !preg_match('/^[A-Za-z0-9._:-]+$/', $__host)
    || !ctype_digit((string) $__port)
    || !preg_match('/^[A-Za-z0-9._-]+$/', (string) $__dbname)
    || !in_array($__sslmode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)
) {
    error_log('Konfigurasi database tidak lengkap/tidak valid. Periksa DB_NAME, DB_USER, DB_PASS, DB_PORT, DB_SSLMODE di .env');
    http_response_code(500);
    die('Konfigurasi aplikasi belum lengkap. Hubungi pengelola.');
}

try {
    $dsn = "pgsql:host={$__host};port={$__port};dbname={$__dbname};sslmode={$__sslmode};connect_timeout=5";

    $pdo = new PDO($dsn, $__user, $__pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('SET TIME ZONE ' . $pdo->quote($__zona));
} catch (PDOException $e) {
    error_log('Koneksi DB Gagal: ' . $e->getMessage());
    http_response_code(500);
    die('Terjadi gangguan sistem koneksi. Silakan coba beberapa saat lagi.');
}

// Jangan biarkan kredensial tertinggal di variabel global.
unset($__host, $__port, $__dbname, $__user, $__pass, $__lokal, $__sslmode, $dsn, $__zona, $__sekarang, $__mulai, $__terakhir);

<?php
require_once __DIR__ . '/../config/koneksi.php';

const AUTH_CEK_ULANG_DETIK = 60;   

/* ======================================================================
 * Pengalihan yang sadar subfolder
 * ====================================================================== */

/**
 * Path URL root aplikasi: '' bila proyek ada di root web server (php -S, virtual host),
 * atau '/ps_go' bila diakses lewat http://localhost/ps_go/.
 * Dihitung dari lokasi folder proyek terhadap DOCUMENT_ROOT.
 */
function app_base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $root    = realpath(dirname(__DIR__));

    if ($docRoot && $root && stripos($root, $docRoot) === 0) {
        $rel = str_replace('\\', '/', substr($root, strlen($docRoot)));
        return $base = rtrim($rel, '/');
    }
    return $base = '';
}

/* ======================================================================
 * Sesi & penyegaran data pengguna
 * ====================================================================== */

function auth_pastikan_sesi(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/**
 * Cocokkan data pengguna di sesi dengan database (maksimal sekali per permintaan,
 * dan hanya tiap AUTH_CEK_ULANG_DETIK detik per sesi):
 *  - akun dihapus / dinonaktifkan -> sesi dikosongkan (pengguna dianggap logout)
 *  - peran, cabang, nama berubah   -> sesi ikut diperbarui
 * Bila database sedang bermasalah, sesi dibiarkan apa adanya.
 */
function auth_segarkan_user(): void
{
    static $sudah = false;
    if ($sudah) {
        return;
    }
    $sudah = true;

    auth_pastikan_sesi();
    if (empty($_SESSION['user']['id_user'])) {
        return;
    }
    if (time() - (int) ($_SESSION['_user_dicek'] ?? 0) < AUTH_CEK_ULANG_DETIK) {
        return;
    }

    global $pdo;
    try {
        $stmt = $pdo->prepare(
            'SELECT nama, email, role, id_cabang, status_verifikasi,
                    CASE WHEN is_active THEN 1 ELSE 0 END AS aktif
               FROM users
              WHERE id_user = :id'
        );
        $stmt->execute(['id' => (int) $_SESSION['user']['id_user']]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('auth_segarkan_user gagal: ' . $e->getMessage());
        return;
    }

    if (!$row || (int) $row['aktif'] !== 1) {
        session_regenerate_id(true);
        $_SESSION = ['error' => 'Akun Anda sudah tidak aktif atau tidak ditemukan. Hubungi pengelola bila ini keliru.'];
        return;
    }

    $_SESSION['user']['nama']              = $row['nama'];
    $_SESSION['user']['email']             = $row['email'];
    $_SESSION['user']['role']              = $row['role'];
    $_SESSION['user']['id_cabang']         = $row['id_cabang'];
    $_SESSION['user']['status_verifikasi'] = $row['status_verifikasi'];
    $_SESSION['user_role']                 = $row['role'];
    $_SESSION['user_name']                 = $row['nama'];
    $_SESSION['_user_dicek']               = time();
}

function is_logged_in(): bool
{
    auth_segarkan_user();
    return !empty($_SESSION['user']);
}

function current_user()
{
    auth_segarkan_user();
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    auth_segarkan_user();

    if (empty($_SESSION['user'])) {
        // Hanya halaman yang dibuka dengan GET yang bisa "dilanjutkan" setelah login.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $returnPath = safe_return_path($_SERVER['REQUEST_URI'] ?? '');
            if ($returnPath !== null) {
                $_SESSION['login_redirect'] = $returnPath;
            }
        }
        header('Location: ' . app_base_url() . '/login.php', true, 302);
        exit;
    }
}

function require_role($allowedRoles): void
{
    require_login();
    $role = current_user()['role'] ?? '';

    if (!in_array($role, $allowedRoles, true)) {
        header('Location: ' . user_home_path($role), true, 302);
        exit;
    }
}

function user_home_path($role): string
{
    return app_base_url() . (in_array($role, ['owner', 'admin', 'operator'], true)
        ? '/admin/index.php'
        : '/index.php');
}

/**
 * Hanya path di dalam aplikasi ini yang boleh jadi tujuan setelah login.
 * Menolak URL luar, karakter kontrol, "..", halaman login/logout/register,
 * dan skrip pemroses (modules/) yang hanya menerima POST.
 */
function safe_return_path($path)
{
    if (
        !is_string($path)
        || $path === ''
        || strlen($path) > 500
        || $path[0] !== '/'
        || strpos($path, '//') === 0
        || strpos($path, '\\') !== false
        || preg_match('/[\x00-\x1F\x7F]/', $path)
    ) {
        return null;
    }

    $parts = parse_url($path);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || empty($parts['path'])) {
        return null;
    }

    $p = $parts['path'];
    if (!preg_match('#^[A-Za-z0-9_./-]+$#', $p) || strpos($p, '..') !== false) {
        return null;
    }

    $base = app_base_url();
    if ($base !== '' && strpos($p, $base . '/') !== 0) {
        return null;
    }
    foreach (['/login.php', '/register.php', '/logout.php', '/modules/'] as $dilarang) {
        if (strpos($p, $dilarang) !== false) {
            return null;
        }
    }

    return $path;
}

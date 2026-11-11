<?php
function get_app_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }

    $raw = (string) getenv('APP_KEY');
    if (strlen($raw) < 32) {
        error_log('APP_KEY belum diatur atau kurang dari 32 karakter (isi di .env).');
        http_response_code(500);
        exit('Konfigurasi aplikasi belum lengkap. Hubungi pengelola.');
    }
    return $key = hash('sha256', $raw, true);
}

function app_subkey(string $tujuan): string
{
    return hash_hkdf('sha256', get_app_key(), 32, 'ps-go/' . $tujuan);
}

function encrypt_id($id, string $konteks = 'id'): string
{
    if (empty($id) && $id !== 0) return '';

    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt((string) $id, 'aes-256-gcm', app_subkey('token-id'), OPENSSL_RAW_DATA, $iv, $tag, $konteks, 16);
    if ($ct === false) {
        error_log('encrypt_id gagal: ' . openssl_error_string());
        return '';
    }
    return rtrim(strtr(base64_encode($iv . $tag . $ct), '+/', '-_'), '=');
}

/** Mengembalikan isi token (string) atau false bila token rusak / dimanipulasi / salah konteks. */
function decrypt_id($encrypted_str, string $konteks = 'id'): string|false
{
    if (!is_string($encrypted_str) || $encrypted_str === '' || strlen($encrypted_str) > 512) {
        return false;
    }
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $encrypted_str)) {
        return false;
    }

    $b64 = strtr($encrypted_str, '-_', '+/');
    $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
    $bin = base64_decode($b64, true);
    if ($bin === false || strlen($bin) < 12 + 16 + 1) {
        return false;
    }

    $iv    = substr($bin, 0, 12);
    $tag   = substr($bin, 12, 16);
    $ct    = substr($bin, 28);
    $plain = openssl_decrypt($ct, 'aes-256-gcm', app_subkey('token-id'), OPENSSL_RAW_DATA, $iv, $tag, $konteks);

    return $plain === false ? false : $plain;
}


if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}


function generate_csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . e(generate_csrf_token()) . '">';
}

function verify_csrf_token($token = null)
{
    $token = $token ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}
function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return getenv('APP_TRUST_PROXY') === '1'
        && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function rate_limit_key(string $jenis, string $nilai): string
{
    return hash_hmac('sha256', $jenis . '|' . strtolower(trim($nilai)), app_subkey('rate-limit'));
}

function rate_limited(PDO $pdo, string $jenis, string $nilai, int $maks, int $jendelaDetik, bool $hanyaGagal = true): bool
{
    try {
        $sql = "SELECT COUNT(*) FROM percobaan_akses
                 WHERE jenis = :j AND kunci = :k
                   AND created_at > now() - (CAST(:d AS int) * interval '1 second')"
            . ($hanyaGagal ? ' AND NOT berhasil' : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['j' => $jenis, 'k' => rate_limit_key($jenis, $nilai), 'd' => $jendelaDetik]);
        return (int) $stmt->fetchColumn() >= $maks;
    } catch (PDOException $e) {
        error_log('rate_limited gagal: ' . $e->getMessage());
        return false;
    }
}

function rate_limit_hit(PDO $pdo, string $jenis, string $nilai, bool $berhasil = false): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO percobaan_akses (jenis, kunci, berhasil) VALUES (:j, :k, :b)');
        $stmt->bindValue(':j', $jenis);
        $stmt->bindValue(':k', rate_limit_key($jenis, $nilai));
        $stmt->bindValue(':b', $berhasil, PDO::PARAM_BOOL);
        $stmt->execute();

        if (random_int(1, 100) === 1) {
            $pdo->query('SELECT bersihkan_percobaan_akses()')->fetchColumn();
        }
    } catch (PDOException $e) {
        error_log('rate_limit_hit gagal: ' . $e->getMessage());
    }
}

function rate_limit_reset(PDO $pdo, string $jenis, string $nilai): void
{
    try {
        $stmt = $pdo->prepare('DELETE FROM percobaan_akses WHERE jenis = :j AND kunci = :k AND NOT berhasil');
        $stmt->execute(['j' => $jenis, 'k' => rate_limit_key($jenis, $nilai)]);
    } catch (PDOException $e) {
        error_log('rate_limit_reset gagal: ' . $e->getMessage());
    }
}
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=(self), payment=()');
    if (request_is_https()) {
        header('Strict-Transport-Security: max-age=15552000');
    }

    $csp = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://code.jquery.com https://cdn.datatables.net https://cdnjs.cloudflare.com",
        "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdn.datatables.net https://fonts.googleapis.com",
        "font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com",
        "img-src 'self' data: blob: https://images.unsplash.com https://api.qrserver.com",
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ]);
    header((getenv('APP_CSP') === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only') . ': ' . $csp);
}

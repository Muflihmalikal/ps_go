<?php
function get_app_key()
{
    $key = getenv('APP_KEY') ?: 'default_fallback_key_32_characters_!';
    return hash('sha256', $key, true);
}

function encrypt_id($id)
{
    if (empty($id) && $id !== 0) return '';
    $key = get_app_key();
    $iv = random_bytes(16);

    $encrypted = openssl_encrypt((string)$id, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    $combined = $iv . $encrypted;
    return rtrim(strtr(base64_encode($combined), '+/', '-_'), '=');
}

function decrypt_id($encrypted_str)
{
    if (empty($encrypted_str)) return false;
    $key = get_app_key();

    $combined = base64_decode(strtr($encrypted_str, '-_', '+/'));
    if (strlen($combined) < 17) return false;

    $iv = substr($combined, 0, 16);
    $encrypted = substr($combined, 16);

    $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    return $decrypted !== false ? $decrypted : false;
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
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

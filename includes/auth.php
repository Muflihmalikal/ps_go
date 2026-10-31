<?php
function is_logged_in()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return !empty($_SESSION['user']);
}

function require_login()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['user'])) {
        $returnPath = safe_return_path($_SERVER['REQUEST_URI'] ?? '');
        if ($returnPath !== null) {
            $_SESSION['login_redirect'] = $returnPath;
        }

        header('Location: /login.php', true, 302);
        exit;
    }
}

function safe_return_path($path)
{
    if (
        !is_string($path)
        || $path === ''
        || $path[0] !== '/'
        || strpos($path, '//') === 0
        || strpos($path, '\\') !== false
        || preg_match('/[\r\n]/', $path)
    ) {
        return null;
    }

    $parts = parse_url($path);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return null;
    }

    return $path;
}

function user_home_path($role)
{
    return in_array($role, ['owner', 'admin', 'operator'], true)
        ? '/admin/index.php'
        : '/index.php';
}

function require_role($allowedRoles)
{
    require_login();
    $user = current_user();

    if (!in_array($user['role'] ?? '', $allowedRoles, true)) {
        header('Location: /index.php', true, 302);
        exit;
    }
}

function current_user()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return $_SESSION['user'] ?? null;
}

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
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $loginUrl = (strpos($scriptName, '/pelanggan/') !== false || strpos($scriptName, '/admin/') !== false)
            ? '../login.php'
            : 'login.php';

        header('Location: ' . $loginUrl, true, 302);
        exit;
    }
}

function user_home_path($role)
{
    return in_array($role, ['owner', 'admin', 'operator'], true)
        ? 'admin/index.php'
        : 'index.php';
}

function require_role($allowedRoles)
{
    require_login();
    $user = current_user();

    if (!in_array($user['role'] ?? '', $allowedRoles, true)) {
        header('Location: ../index.php', true, 302);
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

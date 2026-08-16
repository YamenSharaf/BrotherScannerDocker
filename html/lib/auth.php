<?php
/**
 * Admin authentication (session based, single shared password).
 *
 * The password comes from the environment (ADMIN_PASSWORD, or ADMIN_PASSWORD_HASH
 * for a bcrypt hash) — never from the dashboard-editable config, so it stays the
 * trust anchor. runScanner.sh injects it into config.php ($ENV), which settings.php
 * exposes via env_str(), because lighttpd's FastCGI does not pass env to php-cgi.
 */

require_once __DIR__ . '/../settings.php'; // env_str() + $ENV (ADMIN_PASSWORD[_HASH])

if (session_status() === PHP_SESSION_NONE) {
    session_name('scanner_admin');
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    session_start();
}

/** Is an admin password configured at all? */
function admin_enabled()
{
    return env_str('ADMIN_PASSWORD') !== '' || env_str('ADMIN_PASSWORD_HASH') !== '';
}

/** Verify a submitted password (constant-time), preferring a configured hash. */
function admin_verify($password)
{
    $hash = env_str('ADMIN_PASSWORD_HASH');
    if ($hash !== '') {
        return password_verify((string) $password, $hash);
    }
    $plain = env_str('ADMIN_PASSWORD');
    if ($plain !== '') {
        return hash_equals($plain, (string) $password);
    }
    return false;
}

function is_admin()
{
    return !empty($_SESSION['is_admin']);
}

function admin_login($password)
{
    if (admin_verify($password)) {
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        return true;
    }
    return false;
}

function admin_logout()
{
    $_SESSION = array();
    session_destroy();
}

/** Guard an admin page: redirect to the login screen if not authenticated. */
function require_admin()
{
    if (!is_admin()) {
        header('Location: /admin/login.php');
        exit;
    }
}

/** CSRF token for admin forms. */
function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check($token)
{
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

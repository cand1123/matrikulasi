<?php
/**
 * Autentikasi session Rapor Matrikulasi.
 * Kompatibel PHP 7.2+.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    $isHttps = (
        !empty($_SERVER['HTTPS']) &&
        strtolower((string) $_SERVER['HTTPS']) !== 'off'
    ) || (
        isset($_SERVER['SERVER_PORT']) &&
        (int) $_SERVER['SERVER_PORT'] === 443
    );

    session_name('MATRIKULASI_RAPOR_SESSID');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => 0,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ));
    } else {
        // PHP 7.2: SameSite ditambahkan ke path cookie.
        session_set_cookie_params(0, '/; SameSite=Lax', '', $isHttps, true);
    }

    session_start();
}

function auth_csrf_token()
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function auth_verify_csrf($token)
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function auth_is_logged_in()
{
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

function auth_normalize_text($value)
{
    $value = trim((string) $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return strtolower($value);
}

function auth_login($nip, $namaWaliKelas, array $kelasList)
{
    $cleanClasses = array();
    foreach ($kelasList as $kelas) {
        $kelas = trim((string) $kelas);
        if ($kelas !== '') {
            $cleanClasses[] = $kelas;
        }
    }
    $cleanClasses = array_values(array_unique($cleanClasses));

    session_regenerate_id(true);

    $_SESSION = array(
        'authenticated' => true,
        'nip' => trim((string) $nip),
        'wali_kelas' => trim((string) $namaWaliKelas),
        'kelas' => $cleanClasses,
        'login_time' => time(),
        'last_activity' => time(),
        'csrf_token' => bin2hex(random_bytes(32))
    );
}

function auth_class_allowed($kelas)
{
    if (!auth_is_logged_in() || !isset($_SESSION['kelas']) || !is_array($_SESSION['kelas'])) {
        return false;
    }

    $target = auth_normalize_text($kelas);
    if ($target === '') {
        return false;
    }

    foreach ($_SESSION['kelas'] as $allowed) {
        if (auth_normalize_text($allowed) === $target) {
            return true;
        }
    }
    return false;
}

function auth_require_login()
{
    if (!auth_is_logged_in()) {
        header('Location: validasi_wali_kelas.php');
        exit;
    }

    $now = time();
    $lastActivity = isset($_SESSION['last_activity']) ? (int) $_SESSION['last_activity'] : 0;
    $loginTime = isset($_SESSION['login_time']) ? (int) $_SESSION['login_time'] : 0;

    $idleExpired = ($lastActivity <= 0 || ($now - $lastActivity) > 1800);
    $absoluteExpired = ($loginTime <= 0 || ($now - $loginTime) > 28800);

    if ($idleExpired || $absoluteExpired) {
        auth_destroy_session();
        header('Location: validasi_wali_kelas.php?expired=1');
        exit;
    }

    $_SESSION['last_activity'] = $now;

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

function auth_destroy_session()
{
    $_SESSION = array();

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        if (PHP_VERSION_ID >= 70300) {
            setcookie(session_name(), '', array(
                'expires' => time() - 42000,
                'path' => isset($params['path']) ? $params['path'] : '/',
                'domain' => isset($params['domain']) ? $params['domain'] : '',
                'secure' => isset($params['secure']) ? $params['secure'] : false,
                'httponly' => isset($params['httponly']) ? $params['httponly'] : true,
                'samesite' => isset($params['samesite']) ? $params['samesite'] : 'Lax'
            ));
        } else {
            setcookie(
                session_name(),
                '',
                time() - 42000,
                isset($params['path']) ? $params['path'] : '/',
                isset($params['domain']) ? $params['domain'] : '',
                isset($params['secure']) ? $params['secure'] : false,
                isset($params['httponly']) ? $params['httponly'] : true
            );
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

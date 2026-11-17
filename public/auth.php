<?php
/**
 * Autentikasi session Rapor Matrikulasi.
 * Production Vercel: session disimpan pada Upstash Redis.
 * Local development: PHP file-session bawaan bila Redis belum dikonfigurasi.
 */

$matrikulasiAppEnv = strtolower((string) (getenv('APP_ENV') ?: 'local'));
$matrikulasiIsVercel = (getenv('VERCEL') === '1' || $matrikulasiAppEnv === 'production');
$matrikulasiRedisUrl = trim((string) (getenv('UPSTASH_REDIS_REST_URL') ?: ''));
$matrikulasiRedisToken = trim((string) (getenv('UPSTASH_REDIS_REST_TOKEN') ?: ''));

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '28800');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    $isHttps = $matrikulasiIsVercel
        || (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    session_name('MATRIKULASI_RAPOR_SESSID');

    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ));

    if ($matrikulasiRedisUrl !== '' && $matrikulasiRedisToken !== '') {
        require_once __DIR__ . '/redis_session.php';

        $GLOBALS['matrikulasiSessionHandler'] = new MatrikulasiUpstashSessionHandler(
            $matrikulasiRedisUrl,
            $matrikulasiRedisToken
        );

        if (!session_set_save_handler($GLOBALS['matrikulasiSessionHandler'], true)) {
            http_response_code(503);
            exit('Layanan session tidak dapat diinisialisasi. Periksa konfigurasi Redis.');
        }
    } elseif ($matrikulasiIsVercel) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Konfigurasi session belum lengkap. Hubungkan Upstash Redis dan isi UPSTASH_REDIS_REST_URL serta UPSTASH_REDIS_REST_TOKEN di Vercel.');
    }

    if (!@session_start()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Session tidak dapat dimulai. Periksa koneksi penyimpanan session.');
    }
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
        setcookie(session_name(), '', array(
            'expires' => time() - 42000,
            'path' => isset($params['path']) ? $params['path'] : '/',
            'domain' => isset($params['domain']) ? $params['domain'] : '',
            'secure' => isset($params['secure']) ? $params['secure'] : false,
            'httponly' => isset($params['httponly']) ? $params['httponly'] : true,
            'samesite' => isset($params['samesite']) ? $params['samesite'] : 'Lax'
        ));
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

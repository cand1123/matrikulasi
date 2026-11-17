<?php
/**
 * Pengaturan tanggal tanda tangan.
 *
 * Vercel production:
 *   - Setiap wali kelas menyimpan satu dokumen JSON privat di Vercel Blob.
 *   - Nama objek memakai SHA-256 NIP, bukan NIP mentah.
 *   - Tidak memakai database relasional untuk tanggal TTD.
 *
 * Local/Laragon:
 *   - Tetap memakai storage/tanggal_ttd.json agar kompatibel dengan R6/R8.
 */

if (!defined('TTD_STORAGE_DIR')) {
    define('TTD_STORAGE_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'storage');
}
if (!defined('TTD_STORAGE_FILE')) {
    define('TTD_STORAGE_FILE', TTD_STORAGE_DIR . DIRECTORY_SEPARATOR . 'tanggal_ttd.json');
}

date_default_timezone_set('Asia/Jakarta');

$GLOBALS['ttd_last_error'] = null;

function ttd_set_error(?string $message): void
{
    $GLOBALS['ttd_last_error'] = $message;
}

function ttd_last_error(): ?string
{
    return $GLOBALS['ttd_last_error'] ?? null;
}

function ttd_user_key($nip): string
{
    return preg_replace('/[^0-9]/', '', (string) $nip);
}

function ttd_is_valid_date($date): bool
{
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $dt = DateTime::createFromFormat('!Y-m-d', $date);
    return $dt instanceof DateTime && $dt->format('Y-m-d') === $date;
}

function ttd_is_vercel_runtime(): bool
{
    return getenv('VERCEL') === '1'
        || strtolower((string) (getenv('APP_ENV') ?: 'local')) === 'production';
}

function ttd_blob_token(): string
{
    return trim((string) (getenv('BLOB_READ_WRITE_TOKEN') ?: ''));
}

function ttd_blob_store_id(): string
{
    $token = ttd_blob_token();
    if (!preg_match('/^vercel_blob_rw_([^_]+)_/', $token, $matches)) {
        return '';
    }
    return $matches[1];
}

function ttd_blob_path(string $nip): string
{
    return 'matrikulasi/tanggal-ttd/' . hash('sha256', $nip) . '.json';
}

function ttd_blob_request(string $method, string $url, ?string $body, array $headers, &$status = null): string|false
{
    if (!function_exists('curl_init')) {
        ttd_set_error('Ekstensi PHP cURL tidak tersedia untuk penyimpanan Vercel Blob.');
        return false;
    }

    $ch = curl_init($url);
    $options = array(
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    );

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = $body;
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        ttd_set_error('Koneksi Vercel Blob gagal: ' . $curlError);
        return false;
    }

    return (string) $response;
}

function ttd_blob_read_for_user(string $nip): ?array
{
    ttd_set_error(null);

    $token = ttd_blob_token();
    $storeId = ttd_blob_store_id();

    if ($token === '' || $storeId === '') {
        ttd_set_error('BLOB_READ_WRITE_TOKEN belum tersedia atau format token tidak dikenali. Hubungkan Private Vercel Blob ke project.');
        return null;
    }

    $pathname = ttd_blob_path($nip);
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $pathname)));
    $url = 'https://' . $storeId . '.private.blob.vercel-storage.com/' . $encodedPath;

    $status = 0;
    $response = ttd_blob_request(
        'GET',
        $url,
        null,
        array(
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Cache-Control: no-cache'
        ),
        $status
    );

    if ($response === false) {
        return null;
    }

    if ($status === 404) {
        return array(); // Belum pernah ada tanggal yang disimpan.
    }

    if ($status !== 200) {
        ttd_set_error('Vercel Blob gagal membaca tanggal TTD (HTTP ' . $status . ').');
        return null;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !isset($decoded['tanggal'])) {
        ttd_set_error('File JSON tanggal TTD di Vercel Blob tidak valid.');
        return null;
    }

    if (!isset($decoded['nip_hash']) || !hash_equals(hash('sha256', $nip), (string) $decoded['nip_hash'])) {
        ttd_set_error('Identitas file tanggal TTD tidak cocok dengan akun wali kelas.');
        return null;
    }

    return $decoded;
}

function ttd_blob_save_for_user(string $nip, string $date, &$error = null): bool
{
    $error = null;
    $token = ttd_blob_token();
    $storeId = ttd_blob_store_id();

    if ($token === '' || $storeId === '') {
        $error = 'BLOB_READ_WRITE_TOKEN belum tersedia atau format token tidak dikenali. Hubungkan Private Vercel Blob ke project.';
        return false;
    }

    $pathname = ttd_blob_path($nip);
    $json = json_encode(array(
        'version' => 1,
        'nip_hash' => hash('sha256', $nip),
        'tanggal' => $date,
        'updated_at' => date(DATE_ATOM),
        'timezone' => 'Asia/Jakarta'
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        $error = 'Data tanggal tidak dapat dikodekan menjadi JSON.';
        return false;
    }

    $apiUrl = 'https://vercel.com/api/blob/?pathname=' . rawurlencode($pathname);
    $requestId = $storeId . ':' . (string) round(microtime(true) * 1000) . ':' . bin2hex(random_bytes(6));

    $status = 0;
    $response = ttd_blob_request(
        'PUT',
        $apiUrl,
        $json,
        array(
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'x-vercel-blob-access: private',
            'x-vercel-blob-store-id: ' . $storeId,
            'x-api-blob-request-id: ' . $requestId,
            'x-api-blob-request-attempt: 1',
            'x-api-version: 13',
            'x-content-type: application/json',
            'x-add-random-suffix: 0',
            'x-allow-overwrite: 1',
            'x-cache-control-max-age: 60'
        ),
        $status
    );

    if ($response === false) {
        $error = ttd_last_error() ?: 'Koneksi upload Vercel Blob gagal.';
        return false;
    }

    if ($status < 200 || $status >= 300) {
        $details = json_decode($response, true);
        $message = is_array($details) && isset($details['error']['message'])
            ? (string) $details['error']['message']
            : (is_array($details) && isset($details['error']) && is_string($details['error']) ? $details['error'] : '');
        $error = 'Vercel Blob gagal menyimpan tanggal (HTTP ' . $status . ')' . ($message !== '' ? ': ' . $message : '.');
        return false;
    }

    return true;
}

function ttd_read_all(&$error = null)
{
    $error = null;

    if (!is_file(TTD_STORAGE_FILE)) {
        return array();
    }

    $raw = file_get_contents(TTD_STORAGE_FILE);
    if ($raw === false) {
        $error = 'File pengaturan tanggal tidak dapat dibaca.';
        return false;
    }

    if (trim($raw) === '') {
        return array();
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        $error = 'File pengaturan tanggal rusak atau bukan JSON yang valid.';
        return false;
    }

    return $decoded;
}

function ttd_get_saved_date($nip): string
{
    $key = ttd_user_key($nip);
    if ($key === '') {
        return '';
    }

    if (ttd_blob_token() !== '' || ttd_is_vercel_runtime()) {
        $record = ttd_blob_read_for_user($key);
        if (!is_array($record) || !isset($record['tanggal'])) {
            return '';
        }
        return ttd_is_valid_date($record['tanggal']) ? $record['tanggal'] : '';
    }

    ttd_set_error(null);
    $error = null;
    $all = ttd_read_all($error);
    if (!is_array($all)) {
        ttd_set_error($error);
        return '';
    }

    return isset($all[$key]) && is_string($all[$key]) && ttd_is_valid_date($all[$key])
        ? $all[$key]
        : '';
}

function ttd_get_date_or_today($nip): string
{
    $saved = ttd_get_saved_date($nip);
    if (ttd_last_error() !== null) {
        return '';
    }
    return $saved !== '' ? $saved : date('Y-m-d');
}

function ttd_format_date_id($date): string
{
    if (!ttd_is_valid_date($date)) {
        $date = date('Y-m-d');
    }

    $months = array(
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    );

    $parts = explode('-', $date);
    return (int) $parts[2] . ' ' . $months[(int) $parts[1]] . ' ' . $parts[0];
}

function ttd_save_date_for_user($nip, $date, &$error = null): bool
{
    $error = null;
    $key = ttd_user_key($nip);

    if ($key === '') {
        $error = 'NIP akun tidak ditemukan pada sesi login.';
        return false;
    }

    if (!ttd_is_valid_date($date)) {
        $error = 'Tanggal tidak valid. Gunakan tanggal yang benar.';
        return false;
    }

    if (ttd_blob_token() !== '' || ttd_is_vercel_runtime()) {
        return ttd_blob_save_for_user($key, $date, $error);
    }

    // Fallback lokal untuk Laragon/Docker lokal tanpa konfigurasi Blob.
    if (!is_dir(TTD_STORAGE_DIR)) {
        if (!@mkdir(TTD_STORAGE_DIR, 0755, true) && !is_dir(TTD_STORAGE_DIR)) {
            $error = 'Folder storage tidak dapat dibuat. Periksa izin tulis folder aplikasi.';
            return false;
        }
    }

    if (!is_writable(TTD_STORAGE_DIR)) {
        $error = 'Folder storage tidak memiliki izin tulis.';
        return false;
    }

    $lockPath = TTD_STORAGE_FILE . '.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        $error = 'Tidak dapat mengunci file pengaturan tanggal.';
        return false;
    }

    $readError = null;
    $all = ttd_read_all($readError);
    if (!is_array($all)) {
        flock($lock, LOCK_UN);
        fclose($lock);
        $error = $readError ?: 'Data pengaturan tidak dapat dibaca.';
        return false;
    }

    $all[$key] = $date;
    $json = json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false || file_put_contents(TTD_STORAGE_FILE, $json . PHP_EOL, LOCK_EX) === false) {
        flock($lock, LOCK_UN);
        fclose($lock);
        $error = 'Gagal menyimpan tanggal. Periksa izin tulis folder storage.';
        return false;
    }

    @chmod(TTD_STORAGE_FILE, 0640);
    flock($lock, LOCK_UN);
    fclose($lock);
    return true;
}

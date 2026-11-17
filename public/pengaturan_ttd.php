<?php
/**
 * Penyimpanan pengaturan tanda tangan berbasis file JSON.
 * Tidak menggunakan database. Data dipisahkan per NIP wali kelas.
 */
if (!defined('TTD_STORAGE_DIR')) {
    define('TTD_STORAGE_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'storage');
}
if (!defined('TTD_STORAGE_FILE')) {
    define('TTD_STORAGE_FILE', TTD_STORAGE_DIR . DIRECTORY_SEPARATOR . 'tanggal_ttd.json');
}

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Jakarta');
}

function ttd_user_key($nip)
{
    $digits = preg_replace('/[^0-9]/', '', (string) $nip);
    return $digits;
}

function ttd_is_valid_date($date)
{
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $dt = DateTime::createFromFormat('!Y-m-d', $date);
    return $dt instanceof DateTime && $dt->format('Y-m-d') === $date;
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
        $error = 'File pengaturan tanggal rusak atau bukan JSON yang valid. Jangan hapus file tersebut sebelum dicadangkan.';
        return false;
    }

    return $decoded;
}

function ttd_get_saved_date($nip)
{
    $key = ttd_user_key($nip);
    if ($key === '') {
        return '';
    }

    $error = null;
    $all = ttd_read_all($error);
    if (!is_array($all) || !isset($all[$key]) || !is_string($all[$key])) {
        return '';
    }

    return ttd_is_valid_date($all[$key]) ? $all[$key] : '';
}

function ttd_get_date_or_today($nip)
{
    $saved = ttd_get_saved_date($nip);
    return $saved !== '' ? $saved : date('Y-m-d');
}

function ttd_format_date_id($date)
{
    if (!ttd_is_valid_date($date)) {
        $date = date('Y-m-d');
    }

    $months = array(
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    );

    $parts = explode('-', $date);
    $month = (int) $parts[1];

    return (int) $parts[2] . ' ' . $months[$month] . ' ' . $parts[0];
}

function ttd_save_date_for_user($nip, $date, &$error = null)
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

    if (!is_dir(TTD_STORAGE_DIR)) {
        if (!@mkdir(TTD_STORAGE_DIR, 0755, true) && !is_dir(TTD_STORAGE_DIR)) {
            $error = 'Folder storage tidak dapat dibuat. Periksa izin tulis folder aplikasi.';
            return false;
        }
    }

    if (!is_writable(TTD_STORAGE_DIR)) {
        $error = 'Folder storage tidak memiliki izin tulis. Berikan izin tulis pada folder storage.';
        return false;
    }

    $lockPath = TTD_STORAGE_FILE . '.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        $error = 'Tidak dapat membuka file pengunci pengaturan tanggal.';
        return false;
    }

    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        $error = 'Tidak dapat mengunci file pengaturan tanggal.';
        return false;
    }

    $readError = null;
    $all = ttd_read_all($readError);
    if (!is_array($all)) {
        flock($lock, LOCK_UN);
        fclose($lock);
        $error = $readError !== null ? $readError : 'Data pengaturan tidak dapat dibaca.';
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

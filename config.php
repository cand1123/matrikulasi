<?php
/**
 * Konfigurasi koneksi Google Apps Script untuk Rapor Matrikulasi.
 * API key harus sama dengan PHP_RAPOR_API_KEY pada file Code.gs.
 */

if (!defined('GAS_URL')) {
    define('GAS_URL', 'https://script.google.com/macros/s/AKfycbx5Uqbz1PNu-2KkTP7YgCWD7hq-8-ks3z0Bn9QfzL0Xt49t9c4UWibYJ6NXQCRGLPyJ/exec');
}

if (!defined('GAS_API_KEY')) {
    define('GAS_API_KEY', '1f70094816f9aba94170f469b521e36213835984e7361bd561a95f1c6180b80e');
}

function fetchGasData(array $params = array())
{
    if (!function_exists('curl_init')) {
        return array('_error' => 'Ekstensi PHP cURL belum aktif pada server.');
    }

    // Semua endpoint API laporan dilindungi dengan kunci server-ke-server.
    $params['api_key'] = GAS_API_KEY;
    $url = GAS_URL . '?' . http_build_query($params);

    $ch = curl_init($url);
    if ($ch === false) {
        return array('_error' => 'Tidak dapat memulai koneksi ke Google Apps Script.');
    }

    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Matrikulasi-Rapor-PHP/2.0'
    ));

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return array('_error' => 'Koneksi Google Apps Script gagal: ' . $curlError);
    }

    if ($httpCode !== 200) {
        return array('_error' => 'HTTP Error ' . $httpCode . ' dari Google Apps Script.');
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return array('_error' => 'Respons Google Apps Script bukan JSON yang valid.');
    }

    return $decoded;
}

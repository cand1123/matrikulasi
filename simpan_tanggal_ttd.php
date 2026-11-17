<?php
require_once __DIR__ . '/auth.php';
auth_require_login();

require_once __DIR__ . '/pengaturan_ttd.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function ttd_json_response($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    ttd_json_response(405, array('success' => false, 'message' => 'Metode tidak diizinkan.'));
}

if (!auth_verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
    ttd_json_response(403, array('success' => false, 'message' => 'Token keamanan tidak valid. Muat ulang halaman.'));
}

$date = isset($_POST['tanggal_ttd']) ? trim((string) $_POST['tanggal_ttd']) : '';
$error = null;

if (!ttd_save_date_for_user(isset($_SESSION['nip']) ? $_SESSION['nip'] : '', $date, $error)) {
    ttd_json_response(422, array(
        'success' => false,
        'message' => $error !== null ? $error : 'Tanggal gagal disimpan.'
    ));
}

ttd_json_response(200, array(
    'success' => true,
    'message' => 'Tanggal tanda tangan berhasil disimpan.',
    'tanggal' => $date,
    'tanggal_format' => ttd_format_date_id($date)
));

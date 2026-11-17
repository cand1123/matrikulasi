<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Metode tidak diizinkan.');
}

if (!auth_verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
    http_response_code(403);
    exit('Token keamanan tidak valid.');
}

auth_destroy_session();
header('Location: validasi_wali_kelas.php');
exit;

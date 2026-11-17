<?php
/**
 * Migrasi opsional storage/tanggal_ttd.json lokal ke Private Vercel Blob.
 *
 * Jalankan dari root project:
 *   php scripts/migrate_ttd_json_to_blob.php
 *
 * Pastikan BLOB_READ_WRITE_TOKEN tersedia di environment.
 */
require_once __DIR__ . '/../public/pengaturan_ttd.php';

$source = __DIR__ . '/../public/storage/tanggal_ttd.json';
if (!is_file($source)) {
    fwrite(STDERR, "File sumber tidak ditemukan: public/storage/tanggal_ttd.json\n");
    exit(1);
}
if (ttd_blob_token() === '') {
    fwrite(STDERR, "BLOB_READ_WRITE_TOKEN belum diatur.\n");
    exit(1);
}

$raw = file_get_contents($source);
$data = json_decode((string) $raw, true);
if (!is_array($data)) {
    fwrite(STDERR, "File sumber bukan JSON yang valid.\n");
    exit(1);
}

$ok = 0;
$failed = 0;
foreach ($data as $nip => $date) {
    $error = null;
    if (ttd_save_date_for_user((string) $nip, (string) $date, $error)) {
        $ok++;
        echo "OK: NIP berakhiran " . substr(ttd_user_key($nip), -4) . "\n";
    } else {
        $failed++;
        fwrite(STDERR, "GAGAL NIP berakhiran " . substr(ttd_user_key($nip), -4) . ": " . ($error ?: 'unknown') . "\n");
    }
}

echo "Selesai. Berhasil: {$ok}; gagal: {$failed}\n";
exit($failed > 0 ? 2 : 0);

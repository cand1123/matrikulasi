<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

if (auth_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $error = 'Permintaan tidak valid. Muat ulang halaman lalu coba kembali.';
    } else {
        $nip = trim(isset($_POST['nip']) ? (string) $_POST['nip'] : '');
        $nipKonfirmasi = trim(isset($_POST['nip_konfirmasi']) ? (string) $_POST['nip_konfirmasi'] : '');

        if ($nip === '' || $nipKonfirmasi === '') {
            $error = 'NIP dan konfirmasi NIP wajib diisi.';
        } elseif (strlen($nip) > 40 || strlen($nipKonfirmasi) > 40) {
            $error = 'Format NIP tidak valid.';
        } elseif (!hash_equals($nip, $nipKonfirmasi)) {
            $error = 'NIP dan konfirmasi NIP tidak sama.';
        } else {
            $result = fetchGasData(array(
                'action' => 'auth_wali_kelas',
                'nip' => $nip
            ));

            $valid = (
                isset($result['success']) && $result['success'] === true
                && isset($result['namaWaliKelas']) && trim((string) $result['namaWaliKelas']) !== ''
                && isset($result['nipWaliKelas']) && hash_equals($nip, trim((string) $result['nipWaliKelas']))
                && isset($result['kelasList']) && is_array($result['kelasList'])
                && count($result['kelasList']) > 0
            );

            if ($valid) {
                auth_login($nip, $result['namaWaliKelas'], $result['kelasList']);
                header('Location: index.php');
                exit;
            }

            if (isset($result['_error'])) {
                $error = 'Validasi ke server gagal. ' . $result['_error'];
            } elseif (isset($result['error'])) {
                // Termasuk pesan "Akses API ditolak" jika API key PHP dan Apps Script berbeda.
                $error = 'Google Apps Script: ' . (string) $result['error'];
            } elseif (isset($result['message'])) {
                $error = (string) $result['message'];
            } else {
                $buildInfo = isset($result['build']) ? ' (Apps Script build: ' . (string) $result['build'] . ')' : '';
                $error = 'Respons validasi tidak lengkap. Periksa deployment Apps Script dan kecocokan API key.' . $buildInfo;
            }
        }
    }
}

function login_e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login Wali Kelas | Rapor Matrikulasi</title>
    <style>
        :root {
            --red: #9d3f3c;
            --red-dark: #7f302f;
            --ink: #263247;
            --muted: #6b778a;
            --line: #dce3eb;
            --surface: #fff;
            --bg: #f2f5f8;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            padding: 24px; color: var(--ink);
            background: radial-gradient(circle at 10% 5%, #f7e8e5 0, transparent 32%), var(--bg);
            font: 15px/1.5 "Segoe UI", Arial, sans-serif;
        }
        .card {
            width: min(100%, 480px); padding: 34px;
            border: 1px solid #e8edf2; border-radius: 18px;
            background: var(--surface); box-shadow: 0 18px 50px #26324714;
        }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 26px; }
        .brand-mark {
            width: 44px; height: 44px; display: grid; place-items: center;
            border-radius: 13px; background: #f7edeb; color: var(--red);
            font-weight: 800; letter-spacing: .03em;
        }
        .brand-name { font-size: 12px; font-weight: 700; color: var(--red-dark); }
        .brand-sub { color: var(--muted); font-size: 12px; }
        h1 { margin: 0; font-size: 24px; letter-spacing: -.03em; }
        .intro { margin: 7px 0 25px; color: var(--muted); }
        label { display: block; margin: 17px 0 7px; font-size: 13px; font-weight: 700; }
        input {
            width: 100%; height: 48px; padding: 0 13px;
            border: 1px solid var(--line); border-radius: 9px;
            color: var(--ink); background: #fff; font: inherit;
        }
        input:focus { outline: 3px solid #9d3f3c24; border-color: var(--red); }
        .error {
            margin: 16px 0 0; padding: 11px 13px;
            border: 1px solid #f1c8c5; border-radius: 8px;
            color: #8b302d; background: #fff5f4; font-size: 13px;
        }
        .info {
            margin: 16px 0 0; padding: 11px 13px;
            border: 1px solid #d8e5f1; border-radius: 8px;
            color: #34536e; background: #f3f8fc; font-size: 13px;
        }
        .submit {
            width: 100%; margin-top: 24px; padding: 13px 16px;
            border: 0; border-radius: 9px; color: #fff; background: var(--red);
            font: 700 15px "Segoe UI", Arial, sans-serif; cursor: pointer;
        }
        .submit:hover { background: var(--red-dark); }
        .note { margin: 18px 0 0; color: #8a95a4; font-size: 12px; text-align: center; }
        @media (max-width: 520px) {
            body { padding: 14px; }
            .card { padding: 25px 21px; border-radius: 14px; }
            h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
<main class="card">
    <div class="brand">
        <div class="brand-mark" aria-hidden="true">SR</div>
        <div>
            <div class="brand-name">SEKOLAH RAKYAT</div>
            <div class="brand-sub">Sistem Rapor Matrikulasi</div>
        </div>
    </div>

    <h1>Login Wali Kelas</h1>
    <p class="intro">Masukkan NIP yang terdaftar pada sheet verif sebagai Wali Kelas.</p>

    <?php if (isset($_GET['expired'])): ?>
        <p class="info" role="status">Sesi Anda telah berakhir. Silakan login kembali.</p>
    <?php endif; ?>

    <form method="post" action="" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= login_e(auth_csrf_token()) ?>">

        <label for="nip">NIP Wali Kelas</label>
        <input
            type="text" id="nip" name="nip" maxlength="40"
            inputmode="numeric" autocomplete="off" spellcheck="false"
            required autofocus
            value="<?= login_e(isset($_POST['nip']) ? $_POST['nip'] : '') ?>"
        >

        <label for="nip_konfirmasi">Konfirmasi NIP</label>
        <input
            type="text" id="nip_konfirmasi" name="nip_konfirmasi" maxlength="40"
            inputmode="numeric" autocomplete="off" spellcheck="false"
            required
            value="<?= login_e(isset($_POST['nip_konfirmasi']) ? $_POST['nip_konfirmasi'] : '') ?>"
        >

        <?php if ($error !== ''): ?>
            <p class="error" role="alert"><?= login_e($error) ?></p>
        <?php endif; ?>

        <button class="submit" type="submit">Masuk</button>
    </form>

    <p class="note">Nama resmi dan kelas yang dapat diakses diambil otomatis dari sheet verif.</p>
</main>
</body>
</html>

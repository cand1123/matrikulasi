<?php
require_once __DIR__ . '/auth.php';
auth_require_login();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pengaturan_ttd.php';

$result = fetchGasData(array('action' => 'list'));
$errorMsg = null;
$siswaList = array();

if (isset($result['_error'])) {
    $errorMsg = $result['_error'];
} elseif (isset($result['error'])) {
    $errorMsg = $result['error'];
} elseif (is_array($result)) {
    $requestedClass = trim(isset($_GET['kelas']) ? (string) $_GET['kelas'] : '');

    if ($requestedClass !== '' && !auth_class_allowed($requestedClass)) {
        http_response_code(403);
        exit('Akses ditolak. Kelas tersebut bukan kelas yang diampu akun ini.');
    }

    foreach ($result as $siswa) {
        if (!is_array($siswa)) {
            continue;
        }

        $kelasSiswa = isset($siswa['kelas']) ? trim((string) $siswa['kelas']) : '';
        if (!auth_class_allowed($kelasSiswa)) {
            continue;
        }
        if ($requestedClass !== '' && auth_normalize_text($requestedClass) !== auth_normalize_text($kelasSiswa)) {
            continue;
        }

        $siswaList[] = $siswa;
    }
}

function page_e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$namaWali = isset($_SESSION['wali_kelas']) ? $_SESSION['wali_kelas'] : '';
$nipWali = isset($_SESSION['nip']) ? $_SESSION['nip'] : '';
$kelasAkses = isset($_SESSION['kelas']) && is_array($_SESSION['kelas']) ? $_SESSION['kelas'] : array();
$kelasTerpilih = trim(isset($_GET['kelas']) ? (string) $_GET['kelas'] : '');
$tanggalTtdTersimpan = ttd_get_saved_date($nipWali);
$tanggalTtdForm = $tanggalTtdTersimpan !== '' ? $tanggalTtdTersimpan : date('Y-m-d');
$tanggalTtdLabel = ttd_format_date_id($tanggalTtdForm);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daftar Rapor Matrikulasi Siswa</title>
    <style>
        :root {
            --red: #9d3f3c;
            --red-dark: #7d302e;
            --red-soft: #f9ecea;
            --navy: #243247;
            --ink: #253247;
            --muted: #718096;
            --line: #e4e9f0;
            --surface: #ffffff;
            --bg: #f3f6fa;
            --green: #16794b;
            --shadow: 0 14px 36px rgba(30, 48, 72, .08);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 30px 20px 46px;
            color: var(--ink);
            background:
                radial-gradient(circle at 0 0, rgba(157,63,60,.08), transparent 28rem),
                var(--bg);
            font: 14px/1.5 "Segoe UI", Arial, sans-serif;
        }
        .container { max-width: 1180px; margin: 0 auto; }
        .page-header {
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 18px; margin-bottom: 22px;
        }
        .eyebrow { color: var(--red); font-size: 11px; font-weight: 800; letter-spacing: .13em; text-transform: uppercase; }
        h1 { margin: 5px 0 5px; color: var(--navy); font-size: clamp(23px, 3vw, 30px); letter-spacing: -.035em; }
        .subtitle { color: var(--muted); margin: 0; }
        .account-card {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 15px; border: 1px solid var(--line);
            border-radius: 14px; background: var(--surface); box-shadow: var(--shadow);
        }
        .avatar {
            width: 42px; height: 42px; flex: 0 0 42px; display: grid; place-items: center;
            border-radius: 12px; color: var(--red); background: var(--red-soft);
            font-size: 15px; font-weight: 800;
        }
        .account-name { font-weight: 750; color: var(--navy); }
        .account-meta { color: var(--muted); font-size: 12px; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 7px;
            min-height: 39px; padding: 9px 14px; border: 0; border-radius: 9px;
            color: #fff; background: var(--red); text-decoration: none;
            font: 700 13px "Segoe UI", Arial, sans-serif; cursor: pointer;
            transition: background .15s ease, transform .15s ease;
        }
        .btn:hover { background: var(--red-dark); transform: translateY(-1px); }
        .btn:disabled { opacity: .6; cursor: wait; transform: none; }
        .btn-secondary { color: var(--navy); background: #edf1f6; }
        .btn-secondary:hover { color: #fff; background: #64748b; }
        .btn-logout { min-height: 36px; background: #fff; color: var(--red); border: 1px solid #e8c9c6; }
        .btn-logout:hover { color: #fff; background: var(--red); }
        .logout-form { margin: 0; }
        .panel {
            padding: 20px; margin-bottom: 18px; border: 1px solid var(--line);
            border-radius: 16px; background: var(--surface); box-shadow: var(--shadow);
        }
        .panel-heading {
            display: flex; justify-content: space-between; align-items: center;
            gap: 12px; margin-bottom: 13px;
        }
        .panel-heading h2 { margin: 0; font-size: 16px; color: var(--navy); }
        .panel-heading p { margin: 3px 0 0; color: var(--muted); font-size: 12px; }
        .date-layout { display: flex; align-items: end; gap: 12px; flex-wrap: wrap; }
        .date-field { min-width: 220px; flex: 0 1 270px; }
        label { display: block; margin-bottom: 6px; color: #475569; font-size: 12px; font-weight: 700; }
        input[type="date"], .search-input {
            width: 100%; height: 43px; padding: 0 12px;
            border: 1px solid #d9e1eb; border-radius: 9px;
            color: var(--ink); background: #fff; font: inherit;
            outline: none; transition: border-color .15s, box-shadow .15s;
        }
        input[type="date"]:focus, .search-input:focus {
            border-color: var(--red); box-shadow: 0 0 0 3px rgba(157,63,60,.12);
        }
        .date-status { color: var(--muted); font-size: 12px; margin: 11px 0 0; }
        .date-status.success { color: var(--green); font-weight: 700; }
        .date-status.error { color: #b42318; font-weight: 700; }
        .security-note { margin-top: 8px; color: #8a96a7; font-size: 11px; }
        .list-panel { padding: 0; overflow: hidden; }
        .list-header { padding: 20px 20px 16px; border-bottom: 1px solid var(--line); }
        .list-title-row {
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 12px; margin-bottom: 15px;
        }
        .list-title-row h2 { margin: 0; color: var(--navy); font-size: 18px; }
        .count-pill {
            display: inline-flex; align-items: center; min-height: 27px; padding: 4px 10px;
            border-radius: 99px; color: #7c3835; background: var(--red-soft);
            font-size: 11px; font-weight: 800;
        }
        .search-wrap { position: relative; max-width: 540px; }
        .search-wrap .search-icon {
            position: absolute; top: 50%; left: 13px; transform: translateY(-50%);
            color: #94a3b8; pointer-events: none; font-size: 17px;
        }
        .search-input { padding-left: 40px; }
        .filter { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
        .filter-label { color: var(--muted); font-size: 12px; font-weight: 700; }
        .filter a, .filter .active-filter {
            display: inline-flex; padding: 6px 10px; border: 1px solid var(--line);
            border-radius: 99px; color: #536176; background: #fff;
            text-decoration: none; font-size: 12px; font-weight: 650;
        }
        .filter a:hover, .filter .active-filter { color: #fff; border-color: var(--red); background: var(--red); }
        .table-wrap { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 13px 15px; border-bottom: 1px solid #edf0f4; text-align: left; font-size: 13px; }
        th {
            color: #657287; background: #f8fafc; font-size: 10px;
            letter-spacing: .07em; text-transform: uppercase; font-weight: 800;
        }
        tbody tr { transition: background .12s ease; }
        tbody tr:hover { background: #fff9f8; }
        tbody tr:last-child td { border-bottom: 0; }
        .center { text-align: center; }
        .student-name { color: var(--navy); font-weight: 750; }
        .class-chip {
            display: inline-flex; padding: 4px 9px; border-radius: 7px;
            color: #44556d; background: #eef2f7; font-size: 11px; font-weight: 750;
        }
        .empty-state { padding: 30px 14px; color: var(--muted); text-align: center; }
        .alert {
            margin: 14px 20px 0; padding: 12px 14px; border: 1px solid #fecaca;
            border-radius: 10px; color: #991b1b; background: #fff5f5; font-size: 13px;
        }
        .footer-note { padding: 16px 20px; border-top: 1px solid var(--line); color: #94a0b0; font-size: 11px; }
        /* Modal rapor: tetap di halaman index, tidak membuka tab baru. */
        .rapor-modal[hidden] { display: none !important; }
        .rapor-modal {
            position: fixed; inset: 0; z-index: 9999; display: flex;
            align-items: center; justify-content: center; padding: 18px;
            background: rgba(15, 23, 42, .72); backdrop-filter: blur(3px);
        }
        .rapor-modal-panel {
            display: flex; flex-direction: column; width: min(1280px, 97vw);
            height: min(94vh, 1000px); overflow: hidden;
            border-radius: 14px; background: #fff; box-shadow: 0 24px 80px rgba(0,0,0,.28);
        }
        .rapor-modal-header {
            min-height: 48px; display: flex; align-items: center; justify-content: space-between;
            gap: 12px; padding: 8px 13px 8px 17px; color: var(--navy);
            border-bottom: 1px solid var(--line); background: #fff;
        }
        .rapor-modal-title { font-weight: 750; font-size: 13px; }
        .rapor-modal-close {
            width: 34px; height: 34px; border: 0; border-radius: 8px;
            color: #526176; background: #eef2f6; cursor: pointer; font-size: 21px;
        }
        .rapor-modal-close:hover { color: #fff; background: var(--red); }
        .rapor-frame { width: 100%; flex: 1; min-height: 0; border: 0; background: #f3ebe7; }
        @media (max-width: 760px) {
            body { padding: 18px 11px 30px; }
            .page-header { flex-direction: column; }
            .account-card { width: 100%; }
            .panel { padding: 15px; }
            .date-layout { align-items: stretch; }
            .date-field { flex: 1 1 100%; }
            .date-layout .btn { width: 100%; }
            .list-header { padding: 16px 14px; }
            th, td { padding: 11px 12px; white-space: nowrap; }
            .rapor-modal { padding: 0; }
            .rapor-modal-panel { width: 100vw; height: 100dvh; border-radius: 0; }
        }
    </style>
</head>
<body>
<div class="container">
    <header class="page-header">
        <div>
            <div class="eyebrow">Sekolah Rakyat · Sistem Rapor</div>
            <h1>Daftar Rapor Matrikulasi</h1>
            <p class="subtitle">Kelola tanggal tanda tangan dan cetak rapor siswa yang Anda ampu.</p>
        </div>
        <div class="account-card">
            <div class="avatar" aria-hidden="true">WK</div>
            <div>
                <div class="account-name"><?= page_e($namaWali) ?></div>
                <div class="account-meta">NIP <?= page_e($nipWali) ?></div>
                <div class="account-meta">Kelas: <?= page_e(implode(', ', $kelasAkses)) ?></div>
            </div>
            <form class="logout-form" method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= page_e(auth_csrf_token()) ?>">
                <button class="btn btn-logout" type="submit">Keluar</button>
            </form>
        </div>
    </header>

    <section class="panel" aria-labelledby="dateHeading">
        <div class="panel-heading">
            <div>
                <h2 id="dateHeading">Pengaturan Tanggal Tanda Tangan</h2>
                <p>Tanggal ini akan dicetak pada bagian tanda tangan rapor Anda.</p>
            </div>
            <span class="count-pill">Kota Singkawang</span>
        </div>
        <form id="tanggalTtdForm" class="date-layout">
            <input type="hidden" name="csrf_token" value="<?= page_e(auth_csrf_token()) ?>">
            <div class="date-field">
                <label for="tanggal_ttd">Tanggal TTD</label>
                <input type="date" id="tanggal_ttd" name="tanggal_ttd" value="<?= page_e($tanggalTtdForm) ?>" required>
            </div>
            <button type="submit" class="btn" id="saveDateButton">Simpan Tanggal</button>
        </form>
        <p class="date-status <?= $tanggalTtdTersimpan !== '' ? 'success' : '' ?>" id="dateStatus">
            <?php if ($tanggalTtdTersimpan !== ''): ?>
                Tanggal tersimpan: <?= page_e($tanggalTtdLabel) ?>.
            <?php else: ?>
                Belum ada tanggal tersimpan. Tanggal hari ini digunakan sampai Anda menyimpan pilihan.
            <?php endif; ?>
        </p>
        <p class="security-note">Pengaturan disimpan pada file server per NIP wali kelas, bukan database. File pengaturan terpisah dari file program.</p>
    </section>

    <section class="panel list-panel" aria-labelledby="listHeading">
        <div class="list-header">
            <div class="list-title-row">
                <h2 id="listHeading">Daftar Siswa</h2>
                <span class="count-pill"><span id="visibleCount"><?= count($siswaList) ?></span>&nbsp; siswa</span>
            </div>
            <div class="search-wrap">
                <span class="search-icon" aria-hidden="true">&#9906;</span>
                <label for="studentSearch" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;">Cari nama siswa atau kelas</label>
                <input class="search-input" type="search" id="studentSearch" placeholder="Cari nama siswa atau kelas..." autocomplete="off">
            </div>
            <div class="filter">
                <span class="filter-label">Filter kelas:</span>
                <?php if ($kelasTerpilih !== ''): ?>
                    <a href="index.php">Semua kelas</a>
                <?php else: ?>
                    <span class="active-filter">Semua kelas</span>
                <?php endif; ?>
                <?php foreach ($kelasAkses as $kelas): ?>
                    <?php if ($kelasTerpilih !== '' && auth_normalize_text($kelasTerpilih) === auth_normalize_text($kelas)): ?>
                        <span class="active-filter"><?= page_e($kelas) ?></span>
                    <?php else: ?>
                        <a href="index.php?kelas=<?= rawurlencode($kelas) ?>"><?= page_e($kelas) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($errorMsg !== null): ?>
            <div class="alert"><strong>Error:</strong> <?= page_e($errorMsg) ?></div>
        <?php endif; ?>

        <div class="table-wrap">
            <table id="studentTable">
                <thead>
                    <tr>
                        <th class="center" width="6%">No</th>
                        <th width="36%">Nama Siswa</th>
                        <th class="center" width="14%">Kelas</th>
                        <th width="25%">Wali Kelas</th>
                        <th class="center" width="19%">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($siswaList)): ?>
                        <?php foreach ($siswaList as $i => $s): ?>
                            <tr class="student-row">
                                <td class="center"><?= $i + 1 ?></td>
                                <td class="student-name"><?= page_e(isset($s['nama']) ? $s['nama'] : '-') ?></td>
                                <td class="center"><span class="class-chip"><?= page_e(isset($s['kelas']) ? $s['kelas'] : '-') ?></span></td>
                                <td><?= page_e(isset($s['wali_kelas']) ? $s['wali_kelas'] : '-') ?></td>
                                <td class="center">
                                    <button
                                        type="button"
                                        class="btn rapor-open"
                                        data-url="rapor.php?baris=<?= (int) (isset($s['baris']) ? $s['baris'] : 0) ?>"
                                    >Lihat / Cetak Rapor</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="empty-state">
                                <?= $errorMsg === null ? 'Tidak ada data siswa pada kelas yang diampu akun ini.' : 'Data siswa gagal dimuat.' ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr id="noSearchResults" hidden>
                        <td colspan="5" class="empty-state">Nama siswa tidak ditemukan. Coba kata kunci lain.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="footer-note">Data yang ditampilkan dibatasi berdasarkan kelas yang diampu akun wali kelas.</div>
    </section>
</div>

<div class="rapor-modal" id="raporModal" hidden role="dialog" aria-modal="true" aria-label="Pratinjau rapor siswa">
    <div class="rapor-modal-panel">
        <div class="rapor-modal-header">
            <span class="rapor-modal-title">Pratinjau Rapor Siswa</span>
            <button type="button" class="rapor-modal-close" id="closeRaporModal" aria-label="Tutup pratinjau">&times;</button>
        </div>
        <iframe class="rapor-frame" id="raporFrame" title="Pratinjau rapor"></iframe>
    </div>
</div>

<script>
(function () {
    'use strict';

    var searchInput = document.getElementById('studentSearch');
    var studentRows = Array.prototype.slice.call(document.querySelectorAll('#studentTable tbody tr.student-row'));
    var visibleCount = document.getElementById('visibleCount');
    var noSearchResults = document.getElementById('noSearchResults');

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var term = searchInput.value.trim().toLocaleLowerCase();
            var shown = 0;

            studentRows.forEach(function (row) {
                var text = row.textContent.toLocaleLowerCase();
                var matches = text.indexOf(term) !== -1;
                row.hidden = !matches;
                if (matches) shown++;
            });

            if (visibleCount) visibleCount.textContent = String(shown);
            if (noSearchResults) noSearchResults.hidden = shown !== 0 || studentRows.length === 0;
        });
    }

    var dateForm = document.getElementById('tanggalTtdForm');
    var dateStatus = document.getElementById('dateStatus');
    var saveDateButton = document.getElementById('saveDateButton');

    if (dateForm) {
        dateForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var dateInput = document.getElementById('tanggal_ttd');
            if (!dateInput || !dateInput.value) {
                dateStatus.textContent = 'Silakan pilih tanggal tanda tangan.';
                dateStatus.className = 'date-status error';
                return;
            }

            saveDateButton.disabled = true;
            saveDateButton.textContent = 'Menyimpan...';
            dateStatus.textContent = 'Menyimpan pengaturan tanggal...';
            dateStatus.className = 'date-status';

            var body = new URLSearchParams();
            body.append('tanggal_ttd', dateInput.value);
            body.append('csrf_token', dateForm.querySelector('[name="csrf_token"]').value);

            fetch('simpan_tanggal_ttd.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString()
            })
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || !data.success) {
                        throw new Error(data.message || 'Tanggal gagal disimpan.');
                    }
                    return data;
                });
            })
            .then(function (data) {
                dateStatus.textContent = 'Tersimpan: Kota Singkawang, ' + data.tanggal_format + '.';
                dateStatus.className = 'date-status success';
            })
            .catch(function (error) {
                dateStatus.textContent = error.message || 'Terjadi kesalahan saat menyimpan tanggal.';
                dateStatus.className = 'date-status error';
            })
            .finally(function () {
                saveDateButton.disabled = false;
                saveDateButton.textContent = 'Simpan Tanggal';
            });
        });
    }

    var modal = document.getElementById('raporModal');
    var frame = document.getElementById('raporFrame');
    var closeButton = document.getElementById('closeRaporModal');
    var previousOverflow = '';

    function openRapor(url) {
        if (!modal || !frame) return;
        previousOverflow = document.body.style.overflow;
        frame.src = url;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        if (closeButton) closeButton.focus();
    }

    function closeRapor() {
        if (!modal || modal.hidden) return;
        modal.hidden = true;
        frame.src = 'about:blank';
        document.body.style.overflow = previousOverflow;
    }

    Array.prototype.forEach.call(document.querySelectorAll('.rapor-open'), function (button) {
        button.addEventListener('click', function () {
            var url = button.getAttribute('data-url');
            if (url) openRapor(url);
        });
    });

    if (closeButton) closeButton.addEventListener('click', closeRapor);

    if (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeRapor();
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeRapor();
    });
})();
</script>
</body>
</html>

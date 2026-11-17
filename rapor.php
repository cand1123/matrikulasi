<?php
require_once __DIR__ . '/auth.php';
auth_require_login();

require_once __DIR__ . '/config.php'; // Menyediakan fetchGasData()
require_once __DIR__ . '/pengaturan_ttd.php';

$baris = filter_input(INPUT_GET, 'baris', FILTER_VALIDATE_INT);
if ($baris === false || $baris === null || $baris < 3 || $baris > 109) {
    http_response_code(400);
    exit('Nomor baris siswa tidak valid.');
}

$data = fetchGasData(['action' => 'detail', 'baris' => $baris]);
if (!is_array($data) || isset($data['_error']) || isset($data['error']) || !is_array($data['identitas'] ?? null)) {
    http_response_code(502);
    exit('Gagal mengambil data rapor siswa.');
}

function e($value): string {
    return htmlspecialchars((string)($value ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sheetValue(array $source, array $keys): string {
    foreach ($keys as $key) {
        if (isset($source[$key]) && is_scalar($source[$key]) && trim((string)$source[$key]) !== '') {
            return (string)$source[$key];
        }
    }
    return '-';
}

$id = $data['identitas'];

// Pemeriksaan otorisasi dilakukan kembali di server pada setiap permintaan rapor.
$kelasSiswa = trim((string) ($id['kelas'] ?? $id['kelas_program'] ?? ''));
if (!auth_class_allowed($kelasSiswa)) {
    http_response_code(403);
    exit('Akses ditolak. Anda tidak memiliki izin untuk membuka rapor siswa dari kelas ini.');
}

$asuh      = is_array($data['wali_asuh'] ?? null) ? $data['wali_asuh'] : [];
$asuhData  = array_merge($data, $asuh);
$sekolah   = 'SEKOLAH RAKYAT MENENGAH ATAS TERINTEGRASI 03 PROVINSI KALIMANTAN BARAT';
$tahun     = '2026/2027';
$kepala    = 'Amirudin, S.T., M.Pd.';
$nipKepala = '198104082010011015';
$waliKelas = isset($_SESSION['wali_kelas']) && $_SESSION['wali_kelas'] !== ''
    ? $_SESSION['wali_kelas']
    : ($id['wali_kelas'] ?? $data['wali_kelas'] ?? '-');
$nipWaliKelas = isset($_SESSION['nip']) && $_SESSION['nip'] !== ''
    ? $_SESSION['nip']
    : ($id['nip_wali_kelas'] ?? $id['wali_kelas_nip'] ?? $data['nip_wali_kelas'] ?? '');
$catatanWaliKelas = isset($data['catatan_wali_kelas']) ? (string) $data['catatan_wali_kelas'] : '';
$waliAsuh  = $id['wali_asuh'] ?? $data['wali_asuh_nama'] ?? $asuh['nama'] ?? '-';
$tanggalTtd = ttd_get_date_or_today(isset($_SESSION['nip']) ? $_SESSION['nip'] : '');
$titimangsa = 'Kota Singkawang, ' . ttd_format_date_id($tanggalTtd);

$aspek = [
    ['Keagamaan & Akhlak Mulia', ['aspek1', 'keagamaan', 'keagamaan_akhlak_mulia', 'Keagamaan & akhlak mulia', 'Keagamaan dan akhlak mulia']],
    ['Kepemimpinan & Kesamaptaan', ['aspek2', 'kepemimpinan', 'kepemimpinan_kesamaptaan', 'Kepemimpinan & kesamaptaan', 'Kepemimpinan dan kesamaptaan']],
    ['Keterampilan Sosial & Kepribadian', ['aspek3', 'keterampilan_sosial', 'keterampilan_sosial_kepribadian', 'Keterampilan sosial & kepribadian', 'Keterampilan sosial dan kepribadian']],
    ['Kemandirian & Keasramaan', ['aspek4', 'kemandirian', 'kemandirian_keasramaan', 'Kemandirian & keasramaan', 'Kemandirian dan keasramaan']],
];

$mapel = is_array($data['mapel'] ?? null) ? $data['mapel'] : [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rapor Matrikulasi — <?= e($id['nama'] ?? 'Siswa') ?></title>
<style>
  :root {
    /* Palet formal diselaraskan dengan identitas burgundy website */
    --red: #873936;
    --dark: #672b29;
    --gold: #873936;
    --cream: #f6f7f9;
    --blush: #f7f3f2;
    --line: #d9e0e8;
    --ink: #263244;
    --muted: #596579;
    --white: #ffffff;
  }

  * {
    box-sizing: border-box;
  }

  html {
    background: #eef1f5;
  }

  body {
    margin: 0;
    padding: 28px 16px;
    background: #eef1f5;
    color: var(--ink);
    font: 10.5pt/1.5 "Segoe UI", Arial, sans-serif;
    -webkit-font-smoothing: antialiased;
  }

  /* Toolbar aksi */
  .toolbar {
    max-width: 210mm;
    margin: 0 auto 14px;
    display: flex;
    justify-content: flex-end;
    gap: 9px;
  }

  .btn-print {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px solid var(--red);
    border-radius: 4px;
    background: var(--red);
    color: var(--white);
    padding: 9px 16px;
    font: 600 9.5pt "Segoe UI", Arial, sans-serif;
    cursor: pointer;
    box-shadow: none;
    text-decoration: none;
    transition: background .15s ease, border-color .15s ease;
  }

  .btn-print:hover {
    background: var(--dark);
    border-color: var(--dark);
  }

  .btn-print:active {
    transform: translateY(1px);
  }

  /* Halaman A4 */
  .page {
    max-width: 210mm;
    min-height: 297mm;
    margin: auto;
    padding: 14mm 15mm;
    background: var(--white);
    border-top: 4px solid var(--red);
    box-shadow: 0 8px 26px rgba(38, 50, 68, .09);
    display: flex;
    flex-direction: column;
  }

  /* Kop sekolah */
  .report-header {
    display: grid;
    grid-template-columns: 58px 1fr 58px;
    align-items: center;
    gap: 14px;
    border-bottom: 2px solid var(--red);
    padding-bottom: 12px;
  }

  .logo {
    width: 54px;
    height: 54px;
    object-fit: contain;
  }

  .school-name {
    margin: 0;
    text-align: center;
    color: var(--dark);
    font-size: 12pt;
    font-weight: 700;
    letter-spacing: .15px;
    line-height: 1.35;
  }

  /* Judul rapor */
  .title-block {
    text-align: center;
    margin: 18px 0 16px;
  }

  .report-title {
    margin: 0 0 5px;
    color: var(--dark);
    font-size: 13.5pt;
    font-weight: 750;
    letter-spacing: .15px;
    line-height: 1.3;
  }

  .kicker {
    margin: 0;
    display: inline-block;
    color: var(--muted);
    font-weight: 600;
    font-size: 9.5pt;
    letter-spacing: .35px;
    text-transform: uppercase;
  }

  /* Identitas siswa */
  .identity {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px 24px;
    background: #f7f8fa;
    border: 1px solid var(--line);
    border-left: 3px solid var(--red);
    border-radius: 2px;
    padding: 12px 15px;
    margin-bottom: 17px;
  }

  .identity-item {
    display: grid;
    grid-template-columns: 112px 10px 1fr;
    align-items: baseline;
    font-size: 9.8pt;
    line-height: 1.45;
  }

  .identity-label {
    color: var(--muted);
  }

  .identity-value {
    color: var(--ink);
    font-weight: 650;
    overflow-wrap: anywhere;
  }

  /* Judul bagian */
  h3 {
    color: var(--dark);
    background: #f4f6f8;
    border: 1px solid var(--line);
    border-left: 3px solid var(--red);
    border-radius: 2px;
    padding: 7px 11px;
    font-size: 10.2pt;
    font-weight: 700;
    line-height: 1.35;
    margin: 17px 0 8px;
  }

  /* Tabel: seluruh header dan isi menggunakan ukuran yang sama */
  .table-wrap {
    overflow-x: auto;
    border: 1px solid var(--line);
    border-radius: 2px;
  }

  table {
    border-collapse: collapse;
    width: 100%;
    table-layout: fixed;
    font-family: "Segoe UI", Arial, sans-serif;
    font-size: 9.5pt;
    line-height: 1.38;
    color: var(--ink);
  }

  th,
  td {
    font-family: inherit;
    font-size: inherit;
    line-height: inherit;
  }

  th {
    background: var(--red);
    color: var(--white);
    border-right: 1px solid rgba(255, 255, 255, .18);
    border-bottom: 1px solid var(--dark);
    padding: 8px 6px;
    text-align: center;
    font-weight: 700;
    vertical-align: middle;
  }

  th:last-child {
    border-right: 0;
  }

  td {
    border-right: 1px solid var(--line);
    border-bottom: 1px solid var(--line);
    padding: 7px 8px;
    vertical-align: top;
    overflow-wrap: anywhere;
    font-weight: 400;
  }

  tr:last-child td {
    border-bottom: 0;
  }

  td:last-child {
    border-right: 0;
  }

  tbody tr:nth-child(even) {
    background: #f7f8fa;
  }

  tbody tr:nth-child(odd) {
    background: var(--white);
  }

  .center {
    text-align: center;
  }

  .score-badge {
    display: inline;
    min-width: 0;
    color: inherit;
    background: transparent;
    border: 0;
    padding: 0;
    border-radius: 0;
    font: inherit;
    font-weight: 700;
    box-shadow: none;
  }

  .status-text {
    color: var(--dark);
    font-weight: 600;
  }

  .asuh td:first-child {
    background: #f1f3f6;
    color: var(--dark);
    font-weight: 650;
  }

  /* Catatan wali kelas */
  .teacher-note {
    min-height: 58px;
    padding: 11px 13px;
    border: 1px solid var(--line);
    border-left: 3px solid var(--red);
    border-radius: 2px;
    background: #f7f8fa;
    color: var(--ink);
    font-size: 9.8pt;
    line-height: 1.5;
    white-space: normal;
    overflow-wrap: anywhere;
  }

  /* Tanda tangan */
  .signature-section {
    margin-top: 26px;
    display: grid;
    grid-template-columns: 1fr 1fr 1.15fr;
    gap: 16px;
    align-items: start;
    break-inside: avoid;
    page-break-inside: avoid;
  }

  .signature-box {
    text-align: left;
    font-size: 9.5pt;
    line-height: 1.4;
    color: var(--ink);
  }

  .signature-header {
    min-height: 38px;
    color: var(--muted);
  }

  .signature-space {
    display: block;
    height: 68px;
  }

  .signature-name {
    display: inline-block;
    font-weight: 700;
    color: var(--ink);
    border-bottom: 1px solid var(--ink);
    padding-bottom: 2px;
    min-width: 155px;
  }

  .signature-parent {
    display: inline-block;
    font-weight: 600;
    color: var(--ink);
    letter-spacing: .2px;
  }

  .signature-nip {
    display: block;
    margin-top: 3px;
    color: var(--muted);
    font-size: 9pt;
  }

  /* Footer */
  footer {
    margin-top: auto;
    padding-top: 18px;
  }

  .footer-inner {
    border-top: 1px solid var(--line);
    color: var(--muted);
    padding-top: 8px;
    text-align: center;
    font-size: 8.5pt;
  }

  /* Responsif */
  @media (max-width: 680px) {
    body {
      padding: 10px 6px;
    }

    .page {
      padding: 16px 12px;
      min-height: auto;
    }

    .report-header {
      grid-template-columns: 42px 1fr 42px;
      gap: 8px;
    }

    .logo {
      width: 40px;
      height: 40px;
    }

    .school-name {
      font-size: 10pt;
    }

    .report-title {
      font-size: 12pt;
    }

    .identity {
      grid-template-columns: 1fr;
      padding: 10px 12px;
    }

    .academic {
      min-width: 640px;
    }

    .asuh {
      min-width: 420px;
    }

    .signature-section {
      grid-template-columns: 1fr;
      gap: 20px;
    }

    .signature-header {
      min-height: auto;
    }

    .signature-space {
      height: 56px;
    }

    .toolbar {
      flex-wrap: wrap;
    }
  }

  /* Cetak / PDF A4 */
  @media print {
    @page {
      size: A4 portrait;
      margin: 10mm;
    }

    html,
    body {
      padding: 0;
      background: #fff;
      color: #263244;
      font-size: 9.5pt;
    }

    .toolbar {
      display: none !important;
    }

    .page {
      width: 100%;
      max-width: none;
      min-height: auto;
      padding: 0;
      border: 0;
      box-shadow: none;
    }

    .table-wrap {
      overflow: visible;
      border: 0;
      border-radius: 0;
    }

    .academic,
    .asuh {
      min-width: 0;
    }

    /* Semua teks dalam tabel tetap seragam pada hasil cetak */
    table,
    th,
    td {
      font-size: 9pt !important;
      line-height: 1.32;
    }

    th {
      padding: 6px 4px;
      border: 1px solid var(--red) !important;
      background: var(--red) !important;
      color: #fff !important;
    }

    td {
      padding: 5px 6px;
      border: 1px solid var(--line) !important;
    }

    thead {
      display: table-header-group;
    }

    tr,
    .identity,
    .signature-section {
      break-inside: avoid;
      page-break-inside: avoid;
    }

    h3 {
      break-after: avoid;
      page-break-after: avoid;
      margin-top: 14px;
    }

    .signature-section {
      margin-top: 20px;
      grid-template-columns: 1fr 1fr 1.15fr;
      gap: 12px;
    }

    .signature-box {
      font-size: 9pt;
    }

    .signature-space {
      height: 60px;
    }

    .signature-name {
      min-width: 140px;
    }

    * {
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
    }
  }
</style>
</head>
<body>

<div class="toolbar">
  <a class="btn-print" href="index.php" style="text-decoration:none">Kembali ke Daftar</a>
  <button type="button" class="btn-print" onclick="window.print()">Cetak / Simpan PDF</button>
  <form method="post" action="logout.php" style="margin:0">
    <input type="hidden" name="csrf_token" value="<?= e(auth_csrf_token()) ?>">
    <button type="submit" class="btn-print">Keluar</button>
  </form>
</div>

<main class="page">
  <header class="report-header">
    <img class="logo" src="logo/logo_kemsos.png" alt="Logo Kementerian Sosial">
    <h1 class="school-name"><?= e($sekolah) ?></h1>
    <img class="logo" src="logo/sr-image.png" alt="Logo Sekolah Rakyat">
  </header>

  <div class="title-block">
    <h2 class="report-title">LAPORAN HASIL CAPAIAN MATRIKULASI SISWA</h2>
    <p class="kicker">Tahun Ajaran <?= e($tahun) ?></p>
  </div>

  <section class="identity" aria-label="Identitas siswa">
    <div class="identity-item">
      <span class="identity-label">Nama Siswa</span>
      <span>:</span>
      <span class="identity-value"><?= e($id['nama'] ?? '-') ?></span>
    </div>
    <div class="identity-item">
      <span class="identity-label">Kelas / Program</span>
      <span>:</span>
      <span class="identity-value"><?= e($id['kelas'] ?? $id['kelas_program'] ?? '-') ?></span>
    </div>
    <div class="identity-item">
      <span class="identity-label">Wali Kelas</span>
      <span>:</span>
      <span class="identity-value"><?= e($waliKelas) ?></span>
    </div>
    <div class="identity-item">
      <span class="identity-label">Wali Asuh</span>
      <span>:</span>
      <span class="identity-value"><?= e($waliAsuh) ?></span>
    </div>
  </section>

  <section>
    <h3>A. Capaian Akademik</h3>
    <div class="table-wrap">
      <table class="academic">
        <thead>
          <tr>
            <th style="width:5%">No</th>
            <th style="width:18%">Mata Pelajaran</th>
            <th style="width:8%">Nilai</th>
            <th style="width:13%">Ketuntasan</th>
            <th style="width:28%">Deskripsi Capaian Awal</th>
            <th style="width:28%">Deskripsi Capaian Akhir</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($mapel): ?>
            <?php foreach ($mapel as $index => $m): if (!is_array($m)) continue; ?>
              <tr>
                <td class="center"><?= e($index + 1) ?></td>
                <td><?= e($m['mapel'] ?? '-') ?></td>
                <td class="center">
                  <span class="score-badge"><?= e($m['rata_rata'] ?? $m['nilai'] ?? $m['skor'] ?? '-') ?></span>
                </td>
                <td class="center status-text"><?= e($m['ketuntasan'] ?? '-') ?></td>
                <td><?= nl2br(e($m['capaian_awal'] ?? '-')) ?></td>
                <td><?= nl2br(e($m['capaian_akhir'] ?? '-')) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" class="center">Belum ada data capaian akademik.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section>
    <h3>B. Catatan & Penilaian Pembinaan Wali Asuh</h3>
    <div class="table-wrap">
      <table class="asuh">
        <thead>
          <tr>
            <th style="width:32%">Aspek Pembinaan</th>
            <th>Deskripsi Capaian</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($aspek as [$label, $keys]): ?>
            <tr>
              <td><?= e($label) ?></td>
              <td><?= nl2br(e(sheetValue($asuhData, $keys))) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section>
    <h3>C. Catatan Wali Kelas</h3>
    <div class="teacher-note">
      <?= $catatanWaliKelas !== '' ? nl2br(e($catatanWaliKelas)) : 'Belum ada catatan dari Wali Kelas.' ?>
    </div>
  </section>

  <section class="signature-section" aria-label="Tanda tangan">
    <!-- Kolom 1: Orang Tua/Wali Siswa -->
    <div class="signature-box">
      <div class="signature-header">Orang Tua/Wali Siswa</div>
      <span class="signature-space"></span>
      <span class="signature-parent">(.......................................)</span>
    </div>

    <!-- Kolom 2: Wali Kelas -->
    <div class="signature-box">
      <div class="signature-header">Wali Kelas,</div>
      <span class="signature-space"></span>
      <span class="signature-name"><?= e($waliKelas) ?></span>
      <span class="signature-nip">NIP. <?= e($nipWaliKelas !== '' ? $nipWaliKelas : '-') ?></span>
    </div>

    <!-- Kolom 3: Kepala Sekolah -->
    <div class="signature-box">
      <div class="signature-header">
        <?= e($titimangsa) ?><br>
        Kepala Sekolah
      </div>
      <span class="signature-space"></span>
      <span class="signature-name"><?= e($kepala) ?></span>
      <span class="signature-nip">NIP. <?= e($nipKepala) ?></span>
    </div>
  </section>

  <footer>
    <div class="footer-inner">
      <?= e($sekolah) ?> · Rapor Matrikulasi <?= e($tahun) ?>
    </div>
  </footer>
</main>

</body>
</html>
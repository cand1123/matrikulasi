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
if (ttd_last_error() !== null) {
    http_response_code(503);
    exit('Tanggal tanda tangan belum dapat dibaca dari penyimpanan. Periksa konfigurasi Private Vercel Blob.');
}
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
    --brand: #7f3432;
    --brand-deep: #5e2726;
    --brand-soft: #f5eeee;
    --ink: #263244;
    --muted: #647084;
    --line: #d8dee7;
    --line-strong: #bdc7d4;
    --paper: #ffffff;
    --canvas: #edf1f5;
    --soft: #f6f8fa;
    --soft-warm: #faf7f6;
  }

  * { box-sizing: border-box; }

  html { background: var(--canvas); }

  body {
    margin: 0;
    padding: 28px 16px 40px;
    background: var(--canvas);
    color: var(--ink);
    font: 10.5pt/1.5 "Segoe UI", Arial, sans-serif;
    -webkit-font-smoothing: antialiased;
  }

  /* Toolbar */
  .toolbar {
    max-width: 210mm;
    margin: 0 auto 14px;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
  }

  .btn-print {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 38px;
    padding: 8px 15px;
    border: 1px solid var(--brand);
    border-radius: 5px;
    background: var(--brand);
    color: #fff;
    font: 650 9.3pt "Segoe UI", Arial, sans-serif;
    text-decoration: none;
    cursor: pointer;
    box-shadow: 0 2px 5px rgba(45, 31, 31, .08);
    transition: background .15s ease, border-color .15s ease;
  }

  .btn-print:hover {
    background: var(--brand-deep);
    border-color: var(--brand-deep);
  }

  .btn-print:focus-visible {
    outline: 3px solid rgba(127, 52, 50, .22);
    outline-offset: 2px;
  }

  /* A4 sheet */
  .page {
    position: relative;
    max-width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    padding: 13mm 14mm 12mm;
    background: var(--paper);
    border-top: 5px solid var(--brand);
    box-shadow: 0 12px 34px rgba(35, 49, 68, .10);
    display: flex;
    flex-direction: column;
  }

  /* Letterhead */
  .report-header {
    display: grid;
    grid-template-columns: 58px minmax(0, 1fr) 58px;
    align-items: center;
    gap: 13px;
    padding: 2px 0 13px;
    border-bottom: 3px double var(--brand);
  }

  .logo {
    display: block;
    width: 54px;
    height: 54px;
    object-fit: contain;
  }

  .school-name {
    margin: 0;
    color: var(--brand-deep);
    text-align: center;
    font-size: 11.8pt;
    line-height: 1.38;
    font-weight: 750;
    letter-spacing: .12px;
  }

  /* Report title */
  .title-block {
    margin: 17px 0 15px;
    text-align: center;
  }

  .report-title {
    margin: 0 0 5px;
    color: var(--ink);
    font-size: 13pt;
    line-height: 1.35;
    font-weight: 800;
    letter-spacing: .35px;
  }

  .kicker {
    display: inline-block;
    margin: 0;
    padding: 3px 10px;
    border: 1px solid #e5d8d7;
    border-radius: 3px;
    background: var(--brand-soft);
    color: var(--brand-deep);
    font-size: 9pt;
    line-height: 1.35;
    font-weight: 700;
    letter-spacing: .55px;
    text-transform: uppercase;
  }

  /* Student identity */
  .identity {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 9px 22px;
    margin: 0 0 16px;
    padding: 12px 15px;
    border: 1px solid var(--line);
    border-left: 4px solid var(--brand);
    border-radius: 3px;
    background: var(--soft);
  }

  .identity-item {
    display: grid;
    grid-template-columns: 108px 10px minmax(0, 1fr);
    align-items: baseline;
    font-size: 9.7pt;
    line-height: 1.45;
  }

  .identity-label { color: var(--muted); }

  .identity-value {
    min-width: 0;
    color: var(--ink);
    font-weight: 700;
    overflow-wrap: anywhere;
  }

  /* Section headings */
  h3 {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 17px 0 8px;
    padding: 8px 11px;
    border: 1px solid var(--line);
    border-left: 4px solid var(--brand);
    border-radius: 3px;
    background: #f5f6f8;
    color: var(--brand-deep);
    font-size: 10.2pt;
    line-height: 1.35;
    font-weight: 750;
  }

  /* Tables */
  .table-wrap {
    width: 100%;
    overflow-x: auto;
    border: 1px solid var(--line-strong);
    border-radius: 3px;
  }

  table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    color: var(--ink);
    font-family: "Segoe UI", Arial, sans-serif;
    font-size: 9.4pt;
    line-height: 1.38;
  }

  table th,
  table td {
    font-family: inherit;
    font-size: inherit;
    line-height: inherit;
  }

  th {
    padding: 8px 6px;
    border-right: 1px solid rgba(255, 255, 255, .2);
    border-bottom: 1px solid var(--brand-deep);
    background: var(--brand);
    color: #fff;
    text-align: center;
    vertical-align: middle;
    font-weight: 750;
  }

  th:last-child { border-right: 0; }

  td {
    padding: 7px 8px;
    border-right: 1px solid var(--line);
    border-bottom: 1px solid var(--line);
    vertical-align: top;
    overflow-wrap: anywhere;
    font-weight: 400;
  }

  td:last-child { border-right: 0; }
  tbody tr:last-child td { border-bottom: 0; }
  tbody tr:nth-child(even) { background: #f7f8fa; }
  tbody tr:nth-child(odd) { background: #fff; }

  .center { text-align: center; }

  .score-badge {
    display: inline;
    min-width: 0;
    padding: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
    color: inherit;
    font: inherit;
    font-weight: 750;
    box-shadow: none;
  }

  .status-text {
    color: var(--brand-deep);
    font-weight: 650;
  }

  .asuh td:first-child {
    background: #f0f2f5;
    color: var(--brand-deep);
    font-weight: 700;
  }

  /* Homeroom note */
  .teacher-note {
    min-height: 60px;
    padding: 12px 14px;
    border: 1px solid var(--line);
    border-left: 4px solid var(--brand);
    border-radius: 3px;
    background: var(--soft-warm);
    color: var(--ink);
    font-size: 9.7pt;
    line-height: 1.55;
    white-space: normal;
    overflow-wrap: anywhere;
  }

  /* Signature area */
  .signature-section {
    display: grid;
    grid-template-columns: 1fr 1fr 1.15fr;
    align-items: start;
    gap: 17px;
    margin-top: 25px;
    break-inside: avoid;
    page-break-inside: avoid;
  }

  .signature-box {
    color: var(--ink);
    text-align: left;
    font-size: 9.4pt;
    line-height: 1.4;
  }

  .signature-header {
    min-height: 38px;
    color: var(--muted);
  }

  .signature-space {
    display: block;
    height: 66px;
  }

  .signature-name {
    display: inline-block;
    min-width: 150px;
    padding-bottom: 2px;
    border-bottom: 1px solid var(--ink);
    color: var(--ink);
    font-weight: 750;
  }

  .signature-parent {
    display: inline-block;
    color: var(--ink);
    font-weight: 650;
    letter-spacing: .15px;
  }

  .signature-nip {
    display: block;
    margin-top: 4px;
    color: var(--muted);
    font-size: 8.8pt;
  }

  /* Footer */
  footer {
    margin-top: auto;
    padding-top: 17px;
  }

  .footer-inner {
    padding-top: 8px;
    border-top: 1px solid var(--line);
    color: var(--muted);
    text-align: center;
    font-size: 8.2pt;
    letter-spacing: .1px;
  }

  /* Tablet and mobile preview */
  @media (max-width: 680px) {
    body { padding: 10px 6px 24px; }

    .page {
      min-height: auto;
      padding: 15px 11px;
    }

    .report-header {
      grid-template-columns: 42px minmax(0, 1fr) 42px;
      gap: 7px;
    }

    .logo {
      width: 40px;
      height: 40px;
    }

    .school-name { font-size: 9.8pt; }
    .report-title { font-size: 11.5pt; }

    .identity {
      grid-template-columns: 1fr;
      gap: 7px;
      padding: 10px 11px;
    }

    .academic { min-width: 640px; }
    .asuh { min-width: 420px; }

    .signature-section {
      grid-template-columns: 1fr;
      gap: 18px;
    }

    .signature-header { min-height: auto; }
    .signature-space { height: 52px; }

    .toolbar { justify-content: stretch; }
    .toolbar .btn-print { flex: 1 1 auto; }
  }

  /* A4 print / PDF */
  @media print {
    @page {
      size: A4 portrait;
      margin: 10mm;
    }

    html,
    body {
      padding: 0;
      background: #fff;
      color: var(--ink);
      font-size: 9.5pt;
    }

    .toolbar { display: none !important; }

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
    .asuh { min-width: 0; }

    /* Header and body text are intentionally the same size in each table. */
    table,
    table th,
    table td {
      font-size: 9pt !important;
      line-height: 1.3;
    }

    th {
      padding: 6px 4px;
      border: 1px solid var(--brand-deep) !important;
      background: var(--brand) !important;
      color: #fff !important;
    }

    td {
      padding: 5px 6px;
      border: 1px solid var(--line) !important;
    }

    thead { display: table-header-group; }

    tr,
    .identity,
    .signature-section {
      break-inside: avoid;
      page-break-inside: avoid;
    }

    h3 {
      break-after: avoid;
      page-break-after: avoid;
      margin-top: 13px;
    }

    .signature-section {
      grid-template-columns: 1fr 1fr 1.15fr;
      gap: 12px;
      margin-top: 19px;
    }

    .signature-box { font-size: 9pt; }
    .signature-space { height: 58px; }
    .signature-name { min-width: 135px; }

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
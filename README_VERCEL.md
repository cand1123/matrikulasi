# Matrikulasi R9 — Vercel + Docker + FrankenPHP

Paket ini mengemas website PHP R8 agar dijalankan oleh FrankenPHP di dalam Docker Container Function Vercel.

## Isi paket

- `Dockerfile`: image FrankenPHP + PHP 8.4 + cURL + OPcache.
- `Caddyfile`: HTTP server pada `$PORT` dan PHP routing.
- `public/`: semua file PHP aplikasi.
- `public/redis_session.php`: session PHP menggunakan Upstash Redis REST.
- `public/pengaturan_ttd.php`: tanggal TTD memakai Private Vercel Blob pada Vercel; fallback JSON lokal tetap tersedia untuk Laragon.
- `scripts/migrate_ttd_json_to_blob.php`: migrasi opsional file tanggal lokal.
- `reference/`: referensi Apps Script yang sudah disamarkan; bukan bagian web root.

## Arsitektur penyimpanan

1. **Session login dan CSRF** disimpan di Upstash Redis, dengan TTL 8 jam. Session tidak bergantung pada filesystem container.
2. **Tanggal TTD** disimpan sebagai satu objek JSON privat per wali kelas di Vercel Blob. Nama objek memakai SHA-256 NIP, bukan NIP asli. Tidak menggunakan database relasional untuk tanggal TTD.
3. **Data siswa, nilai, catatan, dan validasi NIP** tetap diambil dari Google Apps Script seperti sebelumnya.
4. **File lokal container tidak digunakan untuk data produksi yang harus persisten.**

Vercel menjalankan image Docker sebagai Function yang dapat scale-to-zero; data lokal container dapat hilang dan tidak dibagikan antar-instance. Karena itu session dan file persisten dipindahkan ke layanan backing storage.

## A. Siapkan Upstash Redis untuk session

1. Buka Vercel Dashboard → project Matrikulasi → Storage / Marketplace.
2. Tambahkan integrasi **Upstash Redis**.
3. Buat database Redis dan hubungkan ke project Vercel.
4. Pastikan environment variables berikut tersedia di project:
   - `UPSTASH_REDIS_REST_URL`
   - `UPSTASH_REDIS_REST_TOKEN`
5. Pilih Production dan Preview sesuai kebutuhan, lalu redeploy setelah menambahkan/mengubah environment variables.

Gunakan REST URL dan token standar Upstash. Jangan pernah menaruh token di file PHP, Git, atau frontend.

## B. Siapkan Private Vercel Blob untuk tanggal TTD

1. Buka project Vercel → Storage → Create Database → Blob.
2. Pilih akses **Private**, bukan Public. Akses private tidak bisa diubah menjadi public/private setelah store dibuat.
3. Hubungkan Blob store ke project Matrikulasi.
4. Pastikan `BLOB_READ_WRITE_TOKEN` tersedia di Environment Variables project.
5. Pilih Production dan Preview sesuai kebutuhan, lalu redeploy.

Tanggal TTD ditulis sebagai JSON privat di path:
`matrikulasi/tanggal-ttd/<sha256-NIP>.json`

Setiap wali kelas mempunyai file JSON sendiri. Isi tidak menyimpan NIP mentah.

## C. Environment variables Vercel

Buka Project → Settings → Environment Variables. Tambahkan:

| Nama | Nilai |
|---|---|
| `APP_ENV` | `production` |
| `GAS_URL` | URL deployment Google Apps Script `/exec` yang sedang digunakan |
| `GAS_API_KEY` | Nilai yang sama persis dengan `PHP_RAPOR_API_KEY` pada Code.gs aktif |
| `UPSTASH_REDIS_REST_URL` | Dari integrasi Upstash Redis |
| `UPSTASH_REDIS_REST_TOKEN` | Dari integrasi Upstash Redis |
| `BLOB_READ_WRITE_TOKEN` | Otomatis dari Private Blob yang terhubung |

**PENTING — API key Google Apps Script**
- Sebelum mengganti konfigurasi lama, salin nilai `PHP_RAPOR_API_KEY` dari Code.gs aktif yang sudah di-deploy.
- Masukkan nilai yang sama ke environment variable `GAS_API_KEY`.
- Deployment ID `AKfy...` bukan API key.
- Jangan gunakan `Code.gs.reference` dari paket ini untuk mengganti Apps Script aktif; API key di file referensi sengaja disamarkan.
- `GAS_API_KEY` hanya berada di server environment, tidak lagi ditulis di `config.php`.

## D. Deploy ke Vercel

Vercel saat ini mendukung Dockerfile sebagai Container preset dan menjalankan image HTTP sebagai Vercel Function. Root project harus memiliki `Dockerfile` dan `Caddyfile`.

1. Ekstrak ZIP ini.
2. Salin folder logo asli dari website Laragon ke `public/logo/`. Rapor membutuhkan:
   - `public/logo/logo_kemsos.png`
   - `public/logo/sr-image.png`
   Kedua aset logo tidak terdapat dalam paket R8 sumber yang tersedia saat pembuatan paket ini.
3. Hubungkan folder/repository ini ke Vercel. Jangan hanya upload file PHP satu per satu.
4. Pastikan **Root Directory** Vercel menunjuk ke folder yang berisi `Dockerfile`.
5. Jika project lama masih terdeteksi sebagai Static/Other framework, jalankan dari CLI setelah `vercel link`:
   `vercel project update --framework container --yes`
6. Deploy:
   `vercel deploy --prod`
7. Buka:
   `https://DOMAIN-ANDA/healthz.php`
   Respons yang diharapkan berupa JSON dengan `status: ok` dan `runtime: FrankenPHP`.
8. Buka halaman login wali kelas, uji login, daftar siswa, rapor modal, tanggal TTD, dan PDF.

Tidak diperlukan `vercel.json` khusus untuk aplikasi ini selama project menggunakan Container preset dan tidak ada framework lain yang perlu dirutekan.

## E. Jalankan lokal dengan Docker

1. Salin `.env.example` menjadi `.env`.
2. Isi `GAS_API_KEY`. Untuk uji penyimpanan cloud, isi juga kredensial Upstash dan Blob.
3. Jalankan:
   `docker compose up --build`
4. Buka `http://localhost:8080/`.
5. Endpoint health:
   `http://localhost:8080/healthz.php`

Tanpa kredensial Redis di local, PHP menggunakan file session lokal. Tanpa Blob token dan `APP_ENV=local`, tanggal TTD menggunakan `public/storage/tanggal_ttd.json`, sehingga tetap kompatibel dengan Laragon.

## F. Migrasi tanggal TTD lama (opsional)

Jika sebelumnya ada file `storage/tanggal_ttd.json` di Laragon:

1. Salin file tersebut ke `public/storage/tanggal_ttd.json` pada paket baru.
2. Siapkan PHP CLI dengan cURL dan environment `BLOB_READ_WRITE_TOKEN`.
3. Jalankan dari root project:
   `php scripts/migrate_ttd_json_to_blob.php`
4. Pastikan output menunjukkan jumlah berhasil dan gagal.
5. Setelah verifikasi, jangan commit file JSON lama ke Git.

Jika belum pernah ada file tanggal tersimpan, langkah migrasi ini dapat dilewati.

## G. Catatan keamanan dan operasional

- Jangan commit `.env`; `.gitignore` sudah mengabaikannya.
- Jangan menaruh `Code.gs` di `public/`.
- `auth.php`, `config.php`, `pengaturan_ttd.php`, `redis_session.php`, dan `/storage/*` diblokir dari akses URL langsung oleh Caddy.
- Cookie session menggunakan Secure pada production, HttpOnly, SameSite=Lax.
- Batas idle session tetap 30 menit dan batas absolut 8 jam.
- Private Blob digunakan karena tanggal TTD terkait identitas akun wali kelas.
- Redis digunakan hanya untuk session; data siswa/nilai tetap bersumber dari Apps Script.
- Vercel tidak menjalankan `docker-compose.yml` sebagai target deployment. Compose hanya untuk pengujian lokal; di Vercel yang dibangun adalah Dockerfile root.

## H. Pemeriksaan setelah deployment

- `/healthz.php` mengembalikan status `ok`.
- Login wali kelas berhasil dan bertahan saat membuka `index.php` serta `rapor.php` dalam iframe.
- Session tetap terbaca saat request berpindah instance karena menggunakan Redis.
- Simpan tanggal TTD; buka ulang halaman dan rapor untuk memastikan tanggal tetap sama.
- Uji logout; session Redis dihapus.
- Pastikan rapor hanya dapat diakses untuk kelas pada session wali kelas.
- Uji cetak PDF dari browser.

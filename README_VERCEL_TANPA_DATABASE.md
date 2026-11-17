# Matrikulasi R10 — Perbaikan unduhan PHP di Vercel (tanpa database)

## Tujuan
Paket ini hanya memperbaiki cara Vercel menjalankan file PHP. Tidak memasang Redis, Blob, MySQL, PostgreSQL, atau layanan database lain.

## Perubahan
- Menambahkan `Dockerfile.vercel` dengan FrankenPHP + PHP 8.4.
- Menambahkan `Caddyfile` untuk menjalankan PHP melalui `php_server`.
- Menambahkan `vercel.json` dengan framework `container`.
- Semua halaman PHP ditempatkan di `public/`, yang menjadi document root.
- Tampilan rapor R8 dan kode aplikasi lainnya dipertahankan.

## Deploy
1. Ekstrak ZIP.
2. Pastikan `Dockerfile.vercel`, `Caddyfile`, `vercel.json`, dan folder `public/` berada di root repository.
3. Pastikan aset logo sekolah (jika ada) berada di lokasi yang dirujuk kode PHP.
4. Push folder ini ke repository Git yang terhubung ke Vercel, atau deploy dengan Vercel CLI dari folder root.
5. Di Vercel Project Settings → Build and Deployment, pilih **Framework Preset: Other/Container** bila framework lama masih dipilih. `vercel.json` juga menetapkan `framework: container`.
6. Deploy ulang.
7. Buka halaman utama website. Uji juga `/healthz.php` jika file health endpoint disediakan (paket ini tidak mengubah halaman bisnis untuk menambahnya).

## Catatan penting
- Jangan upload hanya file PHP satu per satu. Deploy seluruh folder project sebagai satu project.
- Jangan menambahkan Redis atau Blob untuk mengatasi file PHP yang terunduh. Keduanya bukan penyebab browser mengunduh PHP.
- Penyimpanan tanggal TTD dan session tetap memakai mekanisme lokal yang sudah ada dalam kode R8. Pada Vercel Container Functions, filesystem instance tidak boleh dianggap permanen dan tidak selalu dibagi antar-instance. Namun, itu persoalan persistensi terpisah, bukan penyebab file PHP terunduh.
- Jika aplikasi memerlukan session lintas-instance atau penyimpanan tanggal permanen, itu dapat dibahas terpisah; tidak ditambahkan pada paket ini sesuai permintaan.
- Vercel menjalankan `Dockerfile.vercel`; `docker-compose.yml` tidak diperlukan untuk deployment.

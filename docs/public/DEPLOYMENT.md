# Persiapan server Laravel tanpa Docker

Status: panduan penerapan; aplikasi belum dideploy melalui panduan ini. Target server belum dipilih. Gunakan VPS/hosting Laravel dengan PHP-FPM dan worker yang bisa dikelola, tanpa Docker.

## Komponen

- PHP 8.4, Composer 2, ekstensi pdo_pgsql, gd, zip, mbstring, intl, bcmath, sodium dan dependensi sesuai composer.lock.
- Node 22 untuk `npm ci` dan `npm run build` pada tahap build.
- Nginx/Apache; document root hanya `web/public`, bukan root repository.
- PostgreSQL cloud dan S3-compatible storage privat yang sudah diverifikasi.
- Worker Laravel yang dikelola systemd/Supervisor; proses PDF, Excel dan backup tidak selesai hanya karena halaman login hidup.
- Sertifikat HTTPS, waktu Asia/Jakarta, monitoring antrean dan backup serta kunci pemulihan yang disimpan terpisah.

## Batas konfigurasi saat ini

`DatabaseGuard` masih memeriksa nama database/role khusus Air Bersih, host `.aivencloud.com`, dan CA privat. `config/airbersih.php` serta disk cloud mempunyai lokasi CA privat. Ini harus dikonfigurasi/diperluas secara aman untuk provider dan filesystem server baru. Menyalin file lalu mengganti DB_HOST tidak cukup. Jangan mematikan guard, memakai sslmode tanpa verifikasi, atau menghapus batas test untuk melewati pemeriksaan.

`APP_ENV` operasional memakai `operational`; jangan menggantinya begitu saja dengan `production` karena guard memakai profil environment. `APP_DEBUG=false`, secure session cookie, domain HTTPS, APP_KEY yang konsisten, runtime user tanpa DDL, serta konfigurasi proxy tepercaya harus diperiksa sebelum membuka akses. Health endpoint bukan bukti seluruh aplikasi berjalan.

## Urutan penerapan

1. Siapkan target baru dan direktori release. Masukkan rahasia melalui mekanisme server; jangan taruh dalam Git atau argv. Simpan `.env`, CA dan recovery key di luar document root.
2. Verifikasi target PostgreSQL baru secara read-only: host/TLS, `current_database()`, `current_user`, versi, izin role, kapasitas dan ekstensi/trigger yang dipakai migration. Siapkan target uji terpisah.
3. Bangun dependensi dengan lockfile. `composer install --no-dev --prefer-dist --no-interaction` pada `web/`; `npm ci` lalu `npm run build`. Hook package discovery tidak menggantikan verifikasi database.
4. Verifikasi konfigurasi dan backup sebelum `php artisan migrate --force` menggunakan role migrator pada target baru yang telah diperiksa. Jangan menjalankan fresh/reset/seed contoh di operasional.
5. Jalankan PHP-FPM dan web server. Beri hak tulis hanya pada `web/storage` dan `web/bootstrap/cache`. Aktifkan HTTPS dan cookie secure, pastikan URL serta redirect benar.
6. Kelola `php artisan queue:work --sleep=3 --tries=2 --timeout=180 --max-time=3600` sebagai service dengan restart otomatis dan log terbatas; contoh unit ada di `deploy/airbersih-worker.service.example`. Baca policy job sebelum menaikkan retry.
7. Uji login dan otorisasi, upload/download privat, bacaan meter, penerbitan PDF, pembayaran/idempotensi, buku kas, laporan, backup dan pemulihan pada target uji. Baru lakukan penerimaan terbatas pengurus dengan data yang disahkan.

## Rollback

Pertahankan release sebelumnya, APP_KEY, file privat dan database sumber. Jika verifikasi target gagal, kembalikan release/konfigurasi yang diketahui bekerja; jangan mereset database. Koreksi schema/data memerlukan rencana tersendiri. Migrasi provider belum selesai tanpa rekonsiliasi jumlah/baris, file, saldo dan akses.

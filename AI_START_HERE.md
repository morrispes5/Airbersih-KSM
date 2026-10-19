# Airbersih KSM — konteks untuk manusia dan agent AI

Diperiksa 19 Oktober 2026 (Asia/Jakarta). Baca berkas ini sebelum menyimpulkan status aplikasi atau mengubah data.

## Apa aplikasi ini?

Aplikasi administrasi air KSM Banyu Bening RT05: buku pelanggan, sambungan rumah, angka/foto meter, tagihan PDF, pembayaran, buku kas, dan laporan bulanan. Pemakai utamanya pengurus utama, bendahara, dan petugas meter. Belum ada portal warga atau pembayaran otomatis.

## Mulai dari alur pekerjaan

Persiapan: pelanggan → sambungan rumah/nomor meter → angka meter pertama bertanggal dan berfoto → konfirmasi saldo kas dan bank.

Bulanan: baca meter berikutnya → bendahara memeriksa → buat rancangan tagihan → periksa PDF → terbitkan → catat pembayaran → verifikasi transfer → periksa kas dan laporan.

Contoh sintetis: meter 100 menjadi 130 berarti 30 m³. Dengan tarif awal Rp10.000/m³ dan administrasi Rp5.000 per tagihan, total Rp305.000. Angka pertama saja belum menghasilkan tagihan. Tagihan terbit belum menjadi uang diterima. Pembayaran terkonfirmasi masuk buku kas; jangan dicatat dua kali.

## Status dan batas fakta

- Stack aplikasi: PHP 8.4, Laravel 13, Livewire 4, Blade, Tailwind/Vite, PostgreSQL, private S3-compatible storage, database queue.
- Deployment yang sudah didokumentasikan sebelum publikasi ini: server aplikasi lokal, database Aiven dan storage Supabase cloud. Tidak ada Docker lokal.
- Tujuan terbaru pemilik: repository GitHub dan panduan publik; WoWSQL sebagai kandidat database PostgreSQL cloud; server aplikasi tanpa Docker.
- WoWSQL bukan MySQL. Pilihan WoWSQL dikonfirmasi kembali oleh pemilik pada 19 Oktober 2026.
- Plugin WoWSQL kini berhasil dipakai: proyek `airbersih-ksm-6bc263eb`, PostgreSQL 18.6; tabel `project_context` menyimpan empat catatan konteks nonoperasional. Ini bukan migrasi tabel pelanggan/tagihan.
- Jangan menganggap publikasi kode atau GitHub Pages berarti Laravel sudah online. Periksa `docs/CLOUD_STATUS.md` dan bukti remote terbaru.
- Ringkasan 17 Oktober menyebut 51 anggota Desember 2025 sebagai riwayat; itu bukan 51 sambungan aktif sekarang. Data awal, saldo, dan penerimaan pengurus masih memerlukan pemeriksaan manusia. Isi database tidak diperiksa ulang hanya karena kode dipublikasikan.

## Peta kode

| Bagian | Berkas/direktori |
| --- | --- |
| Route dan login | `web/routes/web.php`, `web/app/Http/Controllers/AuthController.php` |
| Halaman/form pengurus | `web/app/Livewire/Workspace.php`, `web/resources/views/livewire/workspace.blade.php` |
| Aturan transaksi | `web/app/Services/Operations.php` |
| Waktu/periode | `web/app/Support/BusinessClock.php` |
| Proteksi database | `web/app/Support/DatabaseGuard.php` |
| Bantuan pemakai | `web/app/Support/PengurusGuide.php`, `web/app/Support/PengurusWords.php` |
| Dokumen dan file privat | `web/app/Http/Controllers/DocumentsController.php`, `web/app/Services/PrivateFiles.php` |
| Import dan riwayat | `web/app/Services/SourceStaging.php`, `web/app/Services/MembershipHistory.php` |
| Struktur data | `web/database/migrations/` |
| Pemeriksaan | `web/tests/`, `.github/workflows/quality.yml` |
| Situs penjelasan publik | `site/` |

## Aturan melanjutkan

1. Baca `AGENTS.md`, `docs/CLOUD_STATUS.md`, `docs/ARCHITECTURE.md`, dan `docs/PANDUAN_PENGURUS.md`.
2. Repository publik hanya berisi kode, konfigurasi contoh, dan dokumentasi yang dipilih. `.env`, akun, kunci, sertifikat, Excel asli, foto meter, data warga, dump, serta bukti operasional tidak tersedia di sini.
3. Jangan menurunkan verifikasi TLS atau melepas DatabaseGuard agar koneksi baru tampak berhasil. Konfigurasi saat ini masih membatasi host Aiven; WoWSQL memerlukan adaptasi terarah dan pengujian sebelum cutover.
4. Jangan menjalankan `migrate:fresh`, seed contoh, atau restore terhadap operasional. Uji integrasi membutuhkan target uji terisolasi yang disediakan pemilik. CI kualitas tanpa kredensial bukan pengujian transaksi.
5. Gunakan bahasa Indonesia untuk pengurus. Setiap angka contoh harus berlabel contoh; setiap status deployment harus punya bukti. Akses browser, GitHub, dan database adalah akses berbeda; URL publik tidak memberi AI izin ke data warga.

Prompt pembuka yang bisa dipakai: “Baca AI_START_HERE.md dan docs/CLOUD_STATUS.md di repository Airbersih-KSM, jelaskan status yang terverifikasi, lalu kerjakan permintaan berikut tanpa mengubah data operasional.”

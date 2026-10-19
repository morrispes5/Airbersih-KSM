# Status cloud

Diperbarui 19 Oktober 2026, Asia/Jakarta. Status berikut menjelaskan lingkup publikasi, bukan pengesahan data lapangan.

| Bagian | Status |
| --- | --- |
| GitHub | Repository publik `morrispes5/Airbersih-KSM` terisi source dan dokumentasi; branch main berhasil diunggah dan berkas konteks terbaca melalui API GitHub |
| Panduan publik | Live di https://morrispes5.github.io/Airbersih-KSM/ ; deployment Pages sukses, HTTP 200, judul dan batas aplikasi terbaca; llms.txt juga HTTP 200 |
| Aplikasi Laravel online | Belum dideploy ke server publik |
| WoWSQL | Proyek Airbersih KSM, slug `airbersih-ksm-6bc263eb`, paket Free, region ap-south-1. Plugin terhubung; identitas PostgreSQL 18.6 dan query berhasil diverifikasi. Tabel `project_context` berisi empat catatan konteks tanpa data warga |
| Database aplikasi berjalan | Konfigurasi lama tetap PostgreSQL Aiven; belum ada cutover ke WoWSQL |
| File operasional | Konfigurasi lama memakai storage privat Supabase; tidak dipindahkan dalam publikasi kode |
| Docker | Tidak dipakai dalam jalur publikasi/server yang disiapkan |
| Data awal dan UAT | Belum disahkan hanya karena repository dibuat |

## Syarat untuk menyelesaikan aplikasi online

1. Verifikasi koneksi PostgreSQL langsung dari server, CA/TLS, hak akses runtime/migrator dan dukungan fitur SQL aplikasi. Query plugin yang berhasil belum membuktikan koneksi PDO dari server. Jangan mengganti sumber operasional sebelum salinan dan readback diverifikasi.
2. Sediakan server Laravel tanpa Docker: domain/HTTPS, PHP-FPM, web server, worker yang dikelola, secret server, backup dan pemantauan. Belum ada target server atau akses deployment yang dipilih dalam pekerjaan publikasi ini.
3. Sesuaikan guard provider dengan tetap mempertahankan penolakan target asing, identitas runtime, isolasi test, dan TLS. Jalankan migrasi aditif pada target baru, lalu uji alur meter → tagihan → pembayaran → kas.
4. Pisahkan demo sintetis dari operasional. Verifikasi akses manusia dari browser lain dan akses agent sesuai jenis tool-nya.

## Tautan untuk agent

- Kode dan konteks: https://github.com/morrispes5/Airbersih-KSM
- Titik mulai: https://github.com/morrispes5/Airbersih-KSM/blob/main/AI_START_HERE.md
- Panduan tanpa login: https://morrispes5.github.io/Airbersih-KSM/

Ketersediaan tautan publik membantu membaca penjelasan, tetapi tidak menjamin semua agent dapat merender JavaScript, login, menjalankan kode, atau mengakses database. Akses data operasional tetap memerlukan otorisasi.

## WoWSQL yang sudah dilakukan

Database baru awalnya kosong menurut `list_tables`. Dibuat satu tabel `project_context` dan empat catatan (`overview`, `workflow`, `deployment_status`, `agent_rules`), lalu dibaca kembali melalui plugin. RLS diaktifkan tanpa policy akses publik. Perintah REVOKE ditolak oleh pembatasan layanan, sehingga tidak diklaim berhasil; ini salah satu batas yang harus ditinjau sebelum data operasional masuk. Batas proyek yang dilaporkan: database 500 MB, lima koneksi bersamaan, dan 50 MB per unggahan. Belum ada migrasi tabel transaksi, akun aplikasi, ataupun cutover Aiven.

Agent yang sudah diberi akses WoWSQL dapat mulai dengan query baca: `SELECT context_key, content FROM public.project_context ORDER BY context_key` pada proyek tersebut.

## Pemeriksaan publikasi

Snapshot awal memuat 171 berkas terpilih, termasuk placeholder direktori runtime kosong. Pemeriksaan pola kredensial tidak menemukan kecocokan; nama staged file dan konfigurasi contoh diperiksa. Riwayat Git lama dan data privat tidak dikirim. Simulasi halaman memeriksa 30 m³ → Rp305.000, 50 m³ → Rp505.000, desimal 30,125 m³ → Rp306.250, dan penolakan angka turun. Browser 1280, 390, dan 320 piksel tidak melebar; console error kosong. Composer validate, Pint lokal, dan build aset lulus. CI Linux pertama menemukan masalah format WorkbookPreview.php; perbaikan format dan pemeriksaan ulang dicatat melalui commit/Actions berikutnya. Tes transaksi cloud lengkap tidak diulang untuk publikasi dokumentasi ini.

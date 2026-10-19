# Status cloud

Diperbarui 19 Oktober 2026, Asia/Jakarta. Status berikut menjelaskan lingkup publikasi, bukan pengesahan data lapangan.

| Bagian | Status |
| --- | --- |
| GitHub | Repository publik `morrispes5/Airbersih-KSM` terisi source dan dokumentasi; branch main berhasil diunggah dan berkas konteks terbaca melalui API GitHub |
| Panduan publik | Live di https://morrispes5.github.io/Airbersih-KSM/ ; deployment Pages sukses, HTTP 200, judul dan batas aplikasi terbaca; llms.txt juga HTTP 200 |
| Aplikasi Laravel online | **Live sementara:** https://airbersih-ksm.vercel.app/login ; Vercel project airbersih-ksm, scope morrizshkki, deployment dpl_6YwbBUUtP69BDyeePgBkLmdcB1wG |
| WoWSQL | Proyek Airbersih KSM, slug `airbersih-ksm-6bc263eb`, paket Free, region ap-south-1. Plugin terhubung; identitas PostgreSQL 18.6 dan query berhasil diverifikasi. Tabel `project_context` berisi empat catatan konteks tanpa data warga |
| Database aplikasi berjalan | PostgreSQL Aiven operasional yang sudah ada; TLS verify-full dipertahankan. WoWSQL belum cutover karena sertifikat endpoint pooler tidak cocok dengan hostname |
| File operasional | Konfigurasi lama memakai storage privat Supabase; tidak dipindahkan dalam publikasi kode |
| Docker | Tidak dipakai dalam jalur publikasi/server yang disiapkan |
| Data awal dan UAT | Belum disahkan hanya karena repository dibuat |

## Pekerjaan lanjutan yang belum selesai

1. Verifikasi koneksi PostgreSQL langsung dari server, CA/TLS, hak akses runtime/migrator dan dukungan fitur SQL aplikasi. Query plugin yang berhasil belum membuktikan koneksi PDO dari server. Jangan mengganti sumber operasional sebelum salinan dan readback diverifikasi.
2. Vercel dipilih sementara setelah pemilik menolak jalur VPS/domain yang terganggu pada Wi-Fi. Aplikasi memakai runtime PHP tanpa Docker, session/cache database, antrean sync, dan upload sementara ke cloud. Hosting permanen dengan worker, dukungan impor besar, backup terjadwal dan pemantauan masih perlu diputuskan untuk operasi jangka panjang.
3. Sesuaikan guard provider dengan tetap mempertahankan penolakan target asing, identitas runtime, isolasi test, dan TLS. Jalankan migrasi aditif pada target baru, lalu uji alur meter → tagihan → pembayaran → kas.
4. Pisahkan demo sintetis dari operasional. Verifikasi akses manusia dari browser lain dan akses agent sesuai jenis tool-nya.

## Tautan untuk agent

- Kode dan konteks: https://github.com/morrispes5/Airbersih-KSM
- Titik mulai: https://github.com/morrispes5/Airbersih-KSM/blob/main/AI_START_HERE.md
- Panduan tanpa login: https://morrispes5.github.io/Airbersih-KSM/

Ketersediaan tautan publik membantu membaca penjelasan, tetapi tidak menjamin semua agent dapat merender JavaScript, login, menjalankan kode, atau mengakses database. Akses data operasional tetap memerlukan otorisasi.

## Deployment aplikasi yang sudah diverifikasi

Vercel community runtime `vercel-php@0.8.0` menjalankan PHP 8.4. Source masuk melalui paket CLI terpilih, bukan upload folder privat atau Docker. Rahasia 30 variabel per lingkungan disimpan sebagai Secret Vercel melalui stdin; nilainya tidak dicetak atau disimpan di Git. File CA/trust untuk deployment berada dalam paket fungsi, di luar jalur statis. Tidak ada reset/seed/migrasi database operasional.

Alamat utama mendapat HTTP 200 tanpa login Vercel. Login aplikasi memakai akun operasional yang sudah ada dan dilanjutkan ke Beranda; 11 halaman pengurus berhasil dibaca, serta satu Livewire reset formulir berhasil diproses. Foto meter sintetis diunggah melalui presigned S3, pemeriksaan CORS lolos, Livewire menyelesaikan upload, lalu reset formulir menghapus file sementara. Tidak ada pelanggan, meter, invoice, pembayaran, atau saldo operasional yang dibuat untuk pemeriksaan ini.

Backend lulus **65 tes / 352 assertions** pada database uji Aiven. Tes baru buffer upload lulus terpisah **2 tes / 5 assertions**. Ini bukan klaim browser menjalankan seluruh alur pembayaran melalui Vercel; pengujian remote berfokus pada login, render halaman, Livewire, dan upload cloud tanpa transaksi nyata. Serverless memiliki batas request, waktu eksekusi, serta filesystem sementara; impor workbook besar dan backup besar tetap perlu validasi tersendiri. Endpoint pooler WoWSQL terdeteksi mengirim sertifikat `*.wowsql.com` yang tidak sesuai dengan host `ap-south-1.pooler.wowsql.com`; verifikasi tidak dimatikan.

## WoWSQL yang sudah dilakukan

Database baru awalnya kosong menurut `list_tables`. Dibuat satu tabel `project_context` dan empat catatan (`overview`, `workflow`, `deployment_status`, `agent_rules`), lalu dibaca kembali melalui plugin. RLS diaktifkan tanpa policy akses publik. Perintah REVOKE ditolak oleh pembatasan layanan, sehingga tidak diklaim berhasil; ini salah satu batas yang harus ditinjau sebelum data operasional masuk. Batas proyek yang dilaporkan: database 500 MB, lima koneksi bersamaan, dan 50 MB per unggahan. Belum ada migrasi tabel transaksi, akun aplikasi, ataupun cutover Aiven.

Agent yang sudah diberi akses WoWSQL dapat mulai dengan query baca: `SELECT context_key, content FROM public.project_context ORDER BY context_key` pada proyek tersebut.

## Pemeriksaan publikasi

Snapshot awal memuat 171 berkas terpilih, termasuk placeholder direktori runtime kosong. Pemeriksaan pola kredensial tidak menemukan kecocokan; nama staged file dan konfigurasi contoh diperiksa. Riwayat Git lama dan data privat tidak dikirim. Simulasi halaman memeriksa 30 m³ → Rp305.000, 50 m³ → Rp505.000, desimal 30,125 m³ → Rp306.250, dan penolakan angka turun. Browser 1280, 390, dan 320 piksel tidak melebar; console error kosong. Composer validate, Pint lokal, dan build aset lulus.

CI GitHub Linux **lulus** pada commit `d170db8` ([run 39249462269](https://github.com/morrispes5/Airbersih-KSM/actions/runs/39249462269)): validasi/install lockfile, Pint, sintaks PHP, install/build frontend, sintaks tiga skrip browser, serta audit Composer/npm. Masalah awal berupa satu format spasi pada WorkbookPreview.php; opsi diagnostik CI juga dibetulkan menjadi opsi verbose yang didukung. Pemeriksaan ini tidak menjalankan alur transaksi/database cloud. Perubahan penutup sesudah commit tersebut hanya dokumentasi dan tidak menjalankan CI ulang.

# Publikasi Airbersih KSM — 19 Oktober 2026

Pemilik mengizinkan repository baru dan penyimpanan cloud agar kode serta penjelasan dapat dibaca manusia dan agent. Nama GitHub memakai `Airbersih-KSM` karena URL repository menggunakan tanda hubung untuk nama Airbersih KSM. Target database diklarifikasi sebagai WoWSQL (PostgreSQL); tidak dilakukan konversi ke MySQL.

## Isi publik

`scripts/prepare-publication.py` memilih source aplikasi saat ini, skrip, CI kualitas, situs panduan, AGENTS.md, AI_START_HERE.md, dan dokumentasi publik yang dikurasi. Hasilnya di direktori privat `private/publication/Airbersih-KSM`, dengan Git terpisah dan commit awal baru. Riwayat repository lokal tidak dikirim. Perubahan lokal yang sudah ada tetap dipertahankan.

Dokumen sumber Drive, workbook, foto meter/bukti bayar, akun/secret, sertifikat, backup, bukti operasional, presentasi dan dataset tidak ikut publikasi. Pemeriksaan pola kredensial dijalankan sebelum staging; audit staged filenames dan contoh konfigurasi tetap diperlukan karena pencarian pola bukan jaminan tunggal.

## Publikasi ulang

1. Jalankan `python scripts/prepare-publication.py` dari checkout sumber.
2. Periksa audit lokal dan diff di snapshot publik. Jangan menyalin `.env`, private, evidence, atau hasil pengujian ke snapshot.
3. Commit hanya snapshot yang telah diperiksa lalu push normal ke repository `morrispes5/Airbersih-KSM`. Jangan force-push.
4. GitHub Pages mengambil direktori `site/` dari branch panduan khusus `gh-pages` (isinya hanya site). CI kualitas menggunakan main. Konfirmasi HTTP situs serta status deployment.

Publikasi ini menyimpan kode dan panduan di cloud. GitHub Pages tidak menjalankan Laravel. Status database/server lengkap ada di `docs/CLOUD_STATUS.md` pada repository publik atau `docs/public/CLOUD_STATUS.md` pada checkout sumber.

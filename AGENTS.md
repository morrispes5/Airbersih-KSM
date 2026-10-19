# Panduan agent Airbersih KSM

- Mulai dari `AI_START_HERE.md`. Konteks aplikasi pengurus RT memakai bahasa Indonesia.
- Pengguna melarang Docker. Jangan menambah Dockerfile, Compose, atau memasang Docker lokal. Target hosting harus menjalankan PHP/Laravel dan worker secara langsung.
- Target database yang dimaksud pengguna ialah WoWSQL (PostgreSQL), bukan MySQL. Migrasi provider belum selesai sampai koneksi, peran, TLS, data, dan transaksi diuji.
- Lindungi data warga, bukti pembayaran, workbook asli, akun, `.env`, token, sertifikat, backup, serta recovery key. Jangan memasukkannya ke Git, situs publik, output perintah, atau argumen CLI.
- Data historis bukan pelanggan, sambungan, tagihan, atau pembayaran saat ini. Angka kosong bukan nol. Jaga snapshot tagihan, audit, idempotensi pembayaran, dan saldo kas/bank.
- Periksa `docs/CLOUD_STATUS.md` sebelum menjanjikan akses online. GitHub Pages adalah panduan publik, tidak menjalankan Laravel.
- Aplikasi sementara berjalan di `https://airbersih-ksm.vercel.app` pada Vercel. Gunakan `scripts/prepare-vercel.py` untuk paket deployment dengan trust bundle privat di luar Git; jangan mengunggah `.env`. Antrean memakai sync, session/cache database, dan upload sementara cloud. Pembaruan source di GitHub tidak otomatis menerapkan deployment CLI ini.
- Target pengujian harus terpisah dari operasional. Jangan menonaktifkan DatabaseGuard/TLS, menjalankan fresh/seed uji pada operasional, atau mengganti APP_KEY operasional.
- Perubahan aditif dan bukti terarah lebih disukai. Pisahkan hasil pemeriksaan saat ini dari bukti historis. Jangan mengklaim tes cloud lulus tanpa menjalankannya.
- Publikasi memakai snapshot terpilih dari `scripts/prepare-publication.py`. Riwayat Git lokal, `private/`, bukti, presentasi, dan dokumen sumber asli tidak dipublikasikan.

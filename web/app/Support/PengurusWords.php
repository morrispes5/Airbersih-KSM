<?php

namespace App\Support;

final class PengurusWords
{
    public static function role(string $role): string
    {
        return ['superadmin' => 'Pengurus utama', 'keuangan' => 'Bendahara', 'petugas' => 'Petugas pencatat meter'][$role] ?? 'Pengurus';
    }

    public static function status(string $status): string
    {
        return [
            'active' => 'Aktif', 'waiting' => 'Menunggu pemasangan', 'inactive' => 'Tidak aktif',
            'draft' => 'Menunggu diperiksa', 'reviewed' => 'Sudah diperiksa', 'issued' => 'Belum dibayar',
            'paid' => 'Sudah dibayar', 'pending' => 'Menunggu persetujuan', 'confirmed' => 'Sudah diterima',
            'rejected' => 'Ditolak', 'reversed' => 'Sudah dikoreksi', 'void' => 'Dibatalkan',
            'ready' => 'Selesai diperiksa', 'queued' => 'Menunggu giliran', 'processing' => 'Sedang dikerjakan',
            'failed' => 'Belum berhasil', 'review' => 'Perlu diperiksa', 'reference' => 'Disimpan sebagai catatan',
            'verified_master' => 'Identitas sudah diperiksa', 'recorded_history' => 'Riwayat sudah dicatat',
            'cash' => 'Uang tunai', 'bank' => 'Rekening bank', 'payment' => 'Pembayaran pelanggan',
            'income' => 'Pemasukan lain', 'expense' => 'Pengeluaran', 'opening' => 'Uang saat mulai mencatat',
            'transfer' => 'Pemindahan uang', 'reversal' => 'Pembatalan dengan catatan',
        ][$status] ?? 'Perlu diperiksa';
    }

    public static function plain(string $text): string
    {
        return str_ireplace(
            ['Baseline bertanggal disimpan.', 'Baseline', 'workflow', 'superadmin', 'snapshot', 'invoice', 'reversal', 'draft', 'preview', 'direview', 'review', 'cloud', 'kuota storage', 'database', 'staging', 'checksum', 'master', 'workbook', 'posting', 'finalisasi', 'final', 'backup', 'Aiven', 'Supabase'],
            ['Angka meter pertama sudah disimpan.', 'angka meter pertama', 'langkah', 'pengurus utama', 'rincian yang disimpan saat tagihan dibuat', 'tagihan', 'pembatalan dengan catatan', 'rancangan', 'tampilan pemeriksaan', 'diperiksa', 'periksa', 'penyimpanan dokumen', 'ruang penyimpanan', 'penyimpanan data', 'daftar menunggu diperiksa', 'pemeriksaan keutuhan berkas', 'daftar pelanggan lama', 'berkas Excel', 'pencatatan keuangan', 'pengesahan', 'disahkan', 'salinan cadangan', 'penyimpanan cadangan', 'penyimpanan dokumen'],
            $text
        );
    }

    public static function action(string $action): string
    {
        return [
            'customer.saved' => 'Data pelanggan disimpan', 'connection.saved' => 'Sambungan air disimpan',
            'tariff.approved' => 'Tarif air disetujui', 'connection.fee.approved' => 'Biaya administrasi pelanggan disesuaikan',
            'invoice.void' => 'Tagihan dibatalkan', 'payment.recorded' => 'Pembayaran dicatat',
            'entry.posted' => 'Catatan uang disimpan', 'accounts.transferred' => 'Pemindahan uang dicatat',
            'recovery.backup.completed' => 'Salinan cadangan selesai dibuat',
            'meter.baseline.verified' => 'Angka meter pertama disahkan', 'reading.saved' => 'Angka meter dicatat',
            'meter.change.requested' => 'Penggantian meter diajukan', 'meter.change.approved' => 'Penggantian meter disetujui',
            'meter.change.rejected' => 'Penggantian meter ditolak',
            'reading.reviewed' => 'Catatan meter diperiksa', 'invoice.drafted' => 'Rancangan tagihan dibuat',
            'invoice.issued' => 'Tagihan diterbitkan', 'invoice.voided' => 'Tagihan dibatalkan',
            'payment.created' => 'Pembayaran dicatat', 'payment.confirmed' => 'Uang pembayaran dikonfirmasi',
            'payment.rejected' => 'Bukti pembayaran ditolak', 'payment.reversed' => 'Pembayaran dikoreksi',
            'entry.created' => 'Catatan uang disimpan', 'entry.reversed' => 'Catatan uang dikoreksi',
            'user.saved' => 'Akun pengurus diperbarui', 'user.access_recovered' => 'Akses masuk pengurus dipulihkan',
            'source.reviewed' => 'Catatan Excel diperiksa', 'history.membership.recorded' => 'Riwayat anggota dicatat',
            'import.mapping.saved' => 'Kolom Excel dipilih', 'operational.initialized' => 'Aplikasi disiapkan',
            'backup.completed' => 'Salinan cadangan selesai', 'backup.recovery.completed' => 'Salinan cadangan selesai',
        ][$action] ?? 'Pengurus memperbarui catatan';
    }

    public static function subject(string $subject): string
    {
        [$kind, $number] = array_pad(explode(':', $subject, 2), 2, '');
        $label = ['customer' => 'Pelanggan', 'connection' => 'Sambungan air', 'meter' => 'Meter air', 'meter_change' => 'Penggantian meter', 'reading' => 'Catatan meter', 'invoice' => 'Tagihan', 'payment' => 'Pembayaran', 'entry' => 'Catatan uang', 'transfer' => 'Pemindahan uang', 'import' => 'Berkas Excel', 'source' => 'Catatan Excel', 'user' => 'Akun pengurus', 'tariff' => 'Tarif air'][$kind] ?? 'Catatan aplikasi';

        return $label.($number !== '' && ctype_digit($number) ? ' no. '.$number : '');
    }
}

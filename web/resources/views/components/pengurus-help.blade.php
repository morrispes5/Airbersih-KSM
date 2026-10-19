@props(['finance'])
<section class="panel guide-intro">
    <h2>Baru pertama memakai aplikasi?</h2>
    @if($finance)
        <p>Mulai dengan daftar pelanggan dan rumah yang memakai air. Setelah itu catat angka meter pertama dan uang yang tersedia saat mulai mencatat.</p>
        <a class="primary" href="/app/customers" wire:navigate>Mulai: daftarkan pelanggan →</a>
    @else
        <p>Pengurus menugaskan rumah yang perlu Anda kunjungi. Buka Catat meter, pilih rumah, lalu isi angka, tanggal, dan foto meter. Jika tidak ada rumah dalam pilihan, minta pengurus memberi penugasan.</p>
        <a class="primary" href="/app/meters" wire:navigate>Buka tugas pencatatan →</a>
    @endif
</section>
<section class="panel">
    <h2>Urutan pekerjaan setiap bulan</h2>
    <ol class="guide-steps">
        <li><strong>Catat meter.</strong> Petugas mengisi angka terbaru dan foto. Bendahara memeriksa catatan tersebut.</li>
        @if($finance)
        <li><strong>Buat tagihan.</strong> Periksa pemakaian, tarif, biaya administrasi dan batas pembayaran sebelum diterbitkan.</li>
        <li><strong>Terima pembayaran.</strong> Catat uang setelah benar-benar diterima. Transfer diperiksa pada rekening.</li>
        <li><strong>Cocokkan laporan.</strong> Periksa uang tunai dan rekening, kemudian minta pengurus utama membuat salinan cadangan.</li>
        @else
        <li><strong>Tunggu pemeriksaan.</strong> Bendahara memeriksa hasil pencatatan sebelum membuat tagihan.</li>
        @endif
    </ol>
</section>
<section class="guide-menu-grid">
@foreach(\App\Support\PengurusGuide::pages() as $slug => $guide)
    @if($slug !== 'help' && (in_array($slug, ['dashboard','meters']) || ($finance && ($slug !== 'settings' || auth()->user()->can('admin')))))
        <article class="panel">
            <h2><a class="text-link" href="/app/{{ $slug }}" wire:navigate>{{ $guide['name'] }} →</a></h2>
            <p>{{ $guide['purpose'] }}</p>
            <ol>@foreach($guide['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
        </article>
    @endif
@endforeach
</section>
@if($finance)
<section class="panel">
    <h2>Istilah yang sering ditanyakan</h2>
    <dl class="guide-glossary">
        <dt>Sambungan air rumah</dt><dd>Catatan rumah yang memakai layanan air, termasuk nomor meter dan petugasnya. Satu pelanggan dapat mempunyai lebih dari satu sambungan.</dd>
        <dt>Angka meter pertama</dt><dd>Angka yang terlihat saat mulai mencatat melalui aplikasi. Ini menjadi pembanding untuk pembacaan berikutnya. Angka ini saja belum menentukan pemakaian atau tagihan.</dd>
        <dt>m³ atau meter kubik</dt><dd>Satuan volume air pada meter. Satu meter kubik sama dengan 1.000 liter. Pemakaian dihitung dari selisih angka meter, lalu dikalikan tarif air yang berlaku.</dd>
        <dt>Uang saat mulai mencatat</dt><dd>Uang tunai dan saldo rekening yang telah dicocokkan dengan keadaan sebenarnya pada tanggal mulai. Angka yang belum diketahui dikosongkan; isi nol hanya jika memang tidak ada uang.</dd>
        <dt>Salinan cadangan</dt><dd>Salinan data dan dokumen untuk mengembalikan catatan jika ada masalah. Tunggu sampai aplikasi menyatakan selesai. Pengelola membantu proses pengembaliannya.</dd>
        <dt>Daftar anggota lama</dt><dd>Riwayat dari Excel pengurus. Status lama belum memastikan orang tersebut masih memakai air. Periksa terlebih dahulu sebelum mendaftarkannya sebagai pelanggan saat ini.</dd>
    </dl>
</section>
@endif

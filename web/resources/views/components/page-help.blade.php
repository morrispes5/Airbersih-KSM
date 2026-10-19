@props(['page'])
@php
$guide = \App\Support\PengurusGuide::pages()[$page];
if (!auth()->user()->can('finance')) {
    $guide = ['purpose' => 'Catat meter pada rumah yang ditugaskan kepada Anda.', 'steps' => [
        'Buka Catat meter untuk melihat rumah yang perlu dikunjungi. Jika belum ada pilihan rumah, minta pengurus memberi penugasan.',
        'Pilih rumah, lalu isi angka terbaru, tanggal pemeriksaan, dan foto meter yang terbaca jelas.',
        'Simpan catatan untuk diperiksa bendahara. Jika angka meter pertama belum tersedia, minta pengurus melengkapinya sebelum mencatat pembacaan berikutnya.',
    ]];
}
@endphp
@if($page !== 'help')
<details class="page-help" wire:ignore.self data-testid="page-help">
    <summary>Cara memakai halaman ini</summary>
    <div class="help-content">
        <p>{{ $guide['purpose'] }}</p>
        <ol>@foreach($guide['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
        <a class="text-link" href="/app/help" wire:navigate>Lihat panduan lengkap →</a>
    </div>
</details>
@endif

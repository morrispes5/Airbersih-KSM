@props(['readiness'])
@php
$steps = [
    ['name'=>'Daftarkan pelanggan', 'text'=>'Nama dan alamat warga yang memakai air.', 'done'=>$readiness['customers']>0, 'url'=>'/app/customers', 'count'=>$readiness['customers'].' pelanggan'],
    ['name'=>'Daftarkan sambungan air rumah', 'text'=>'Pilih pelanggan dan isi nomor meter yang terpasang.', 'done'=>$readiness['connections']>0, 'url'=>'/app/customers#connection-registration', 'count'=>$readiness['connections'].' sambungan'],
    ['name'=>'Isi angka meter pertama', 'text'=>'Periksa meter di rumah, lalu simpan tanggal dan fotonya.', 'done'=>$readiness['active_connections']>0&&$readiness['verified_baselines']>=$readiness['active_connections'], 'url'=>'/app/meters', 'count'=>$readiness['verified_baselines'].' dari '.$readiness['active_connections'].' sambungan aktif'],
    ['name'=>'Cocokkan uang kas dan rekening', 'text'=>'Isi uang yang benar-benar tersedia saat mulai mencatat.', 'done'=>$readiness['accounts']>0&&$readiness['confirmed_accounts']>=$readiness['accounts'], 'url'=>'/app/finance', 'count'=>$readiness['confirmed_accounts'].' dari '.$readiness['accounts'].' tempat penyimpanan uang'],
];
$nextStep = collect($steps)->first(fn($step)=>!$step['done']);
@endphp
<section class="panel getting-started" data-testid="operational-preparation">
    <div class="panel-title"><div><h2>Mulai dari sini</h2><p>Ikuti urutan ini saat pertama memakai aplikasi. Langkah 1 dan 2 membuat rumah muncul di pilihan Catat meter.</p></div><span class="badge neutral">LANGKAH AWAL</span></div>
    <ol class="setup-steps">
        @foreach($steps as $step)
        <li class="{{ $step['done']?'step-done':'step-pending' }}"><div><strong>{{ $loop->iteration }}. {{ $step['name'] }}</strong><p>{{ $step['text'] }}</p><small>{{ $step['count'] }} · {{ $step['done']?'Sudah terisi':'Perlu dilengkapi' }}</small></div><a class="{{ $nextStep&&$nextStep['name']===$step['name']?'primary':'secondary' }}" href="{{ $step['url'] }}" wire:navigate>{{ $step['done']?'Lihat data':'Buka langkah ini' }} →</a></li>
        @endforeach
    </ol>
    @if($readiness['history_members'])<p class="field-help">Ada {{ $readiness['history_members'] }} nama pada daftar anggota Desember 2025. Itu catatan lama; periksa siapa yang masih memakai air sebelum mendaftarkannya sebagai pelanggan. <a class="text-link" href="/app/imports" wire:navigate>Lihat daftar lama →</a></p>@endif
    @if(!$nextStep)<p>Data awal sudah terisi. Cocokkan kembali bersama pengurus sebelum mulai menerbitkan tagihan.</p>@endif
    <p><a class="text-link" href="/app/help" wire:navigate>Belum paham urutannya? Buka panduan penggunaan →</a></p>
</section>

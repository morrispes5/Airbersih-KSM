@props(['sourceRows','sourceInvoices'])
<details class="panel" wire:ignore.self data-testid="source-review">
    <summary>Periksa catatan Excel lama yang belum dipakai</summary>
    <p class="section-note">Bagian ini untuk mencocokkan catatan lama dengan keadaan sebenarnya. Menyimpan catatan sebagai referensi tidak membuat tagihan atau menambah uang. Jika belum yakin, pilih Periksa lagi nanti.</p>
    @foreach($sourceInvoices as $attachment)<p><a target="_blank" rel="noopener" class="text-link" href="{{ route('attachment',$attachment->id) }}">Lihat contoh tagihan lama yang diberikan pengurus</a></p>@endforeach
    <label class="field"><span>Hasil pemeriksaan atau alasan keputusan</span><input name="source_reason" wire:model="reason" minlength="8" placeholder="Contoh: nama dan alamat sudah diperiksa…"></label>
    <div class="table-wrap"><table>
        <thead><tr><th>Catatan asal</th><th>Isi yang perlu diperiksa</th><th>Perhatian</th><th>Keputusan pengurus</th></tr></thead>
        <tbody>
        @forelse($sourceRows as $source)
        <tr>
            <td>Baris {{ $source->row_number }}<small>{{ $source->period ? \Carbon\CarbonImmutable::parse($source->period.'-01')->locale('id')->translatedFormat('F Y') : 'Tanggal belum tercantum' }}</small><details><summary>Letak dalam Excel</summary><p>{{ $source->sheet }}, baris {{ $source->row_number }}</p></details></td>
            <td class="source-card">
                @if(in_array($source->kind,['master','history']))
                    <dl><dt>Nama pada catatan</dt><dd>{{ $source->cells['C']['value']??'Belum tercantum' }}</dd>
                    @if($source->kind==='master')<dt>Alamat pada catatan</dt><dd>{{ collect(['D','E','F'])->map(fn($column)=>(string)($source->cells[$column]['value']??''))->filter()->implode(' ') }}</dd>@else<dt>Pemakaian pada catatan</dt><dd>{{ $source->cells['I']['value']??'Belum tercantum' }} m³</dd>@endif</dl>
                @else
                    <p>{{ collect($source->cells)->map(fn($cell)=>is_scalar($cell['value']??null)?(string)$cell['value']:null)->filter()->take(6)->implode(' · ') }}</p>
                @endif
            </td>
            <td>
                @if($source->kind==='master')<p>Cocokkan nama dan alamat dengan pelanggan sekarang. Dua versi Excel dapat memuat angka berbeda.</p>
                @elseif($source->kind==='history')<p>Periksa bulan, angka meter dan jumlah tagihan. Catatan ini belum membuktikan pembayaran diterima.</p>
                @else<p>Catatan pendukung; tidak menambah tagihan atau uang.</p>@endif
                @foreach($source->issues as $issue)
                    @if(str_contains($issue,'CONFLICT:'))<p>Angka berbeda: bukti tagihan mencatat 50 m³, sedangkan Excel 0 m³. Tanyakan angka yang benar kepada pengurus.</p>
                    @elseif(str_contains($issue,'label sumber April'))<p>Nama lembar menyebut Mei, tetapi judulnya April. Pastikan bulan yang benar dahulu.</p>
                    @elseif(str_contains($issue,'tidak lengkap'))<p>Ada angka pada sumber yang belum lengkap.</p>
                    @elseif(str_contains($issue,'Selisih meter'))<p>Selisih angka meter belum cocok dengan pemakaian yang tertulis.</p>@endif
                @endforeach
            </td>
            <td>{{ \App\Support\PengurusWords::status($source->status) }}<p>{{ \App\Support\PengurusWords::plain($source->decision??'') }}</p>
                @if($source->status==='review')
                    @if($source->kind==='master')<button class="secondary" wire:click="reviewSource({{ $source->id }},'verify_master')" wire:confirm="Nama dan alamat ini sudah dicocokkan dengan keadaan sekarang? Tindakan ini mendaftarkan identitas pelanggan. Sambungan air dan angka meter tetap perlu dicatat terpisah.">Identitas sudah diperiksa</button>@endif
                    <button class="text-link" wire:click="reviewSource({{ $source->id }},'reference')">Simpan sebagai catatan lama</button>
                    <button class="text-link" wire:click="reviewSource({{ $source->id }},'hold')">Periksa lagi nanti</button>
                @endif
            </td>
        </tr>
        @empty<tr><td colspan="4" class="empty">Belum ada catatan Excel yang menunggu pemeriksaan.</td></tr>@endforelse
        </tbody>
    </table></div>
    {{ $sourceRows->links() }}
</details>

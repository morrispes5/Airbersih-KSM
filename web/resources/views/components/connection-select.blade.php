@props(['connections','change'=>false])
<label class="field"><span>Rumah / sambungan air</span><select aria-label="Rumah / sambungan air" name="connection_id" wire:model="form.connection_id" @disabled($connections->isEmpty()) @if($change) wire:change="selectConnection" @endif><option value="">{{ $connections->isEmpty() ? 'Belum ada rumah yang dapat dipilih' : 'Pilih nama pelanggan / rumah' }}</option>@foreach($connections as $c)<option value="{{ $c->id }}">{{ $c->customer_name }} · {{ $c->address }} · {{ $c->code }}</option>@endforeach</select></label>
@if($connections->isEmpty())
<p class="field-help">
@can('finance')
Daftarkan pelanggan beserta sambungan airnya terlebih dahulu di <a class="text-link" href="/app/customers" wire:navigate>Daftar pelanggan</a>.
@else
Belum ada rumah yang ditugaskan kepada Anda. Minta pengurus menambahkan penugasan.
@endcan
</p>
@endif

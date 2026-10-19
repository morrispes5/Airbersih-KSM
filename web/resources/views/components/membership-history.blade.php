@props(['history', 'rows'])
<section class="panel" data-testid="membership-history">
    <div class="panel-title">
        <div>
            <h2>Riwayat anggota Desember 2025</h2>
            <p>Daftar dari pengurus RT · kondisi sumber per <strong>8 Desember 2025</strong>.</p>
        </div>
        <span class="badge neutral">RIWAYAT TERCATAT</span>
    </div>
    <div class="cash-grid">
        <div><span>Anggota dalam sumber</span><strong>{{ $history['count'] }} anggota</strong></div>
        <div><span>Aktif pada sumber</span><strong>{{ $history['active'] }} anggota</strong></div>
        <div><span>Pasif pada sumber</span><strong>{{ $history['passive'] }} anggota</strong></div>
    </div>
    <p>Status Aktif/Pasif mengikuti Excel pada tanggal tersebut. Keadaan pelanggan dan sambungan air sekarang tetap perlu diperiksa.</p>
    <p class="muted">Pencatatan riwayat ini tidak menambah tagihan, pembayaran, saldo kas atau pelanggan aktif. Nama, alamat, jabatan dan nomor HP dipertahankan sesuai sumber.</p>
    <p><a class="text-link" href="{{ route('source.download', $history['batch_id']) }}">Unduh Excel sumber</a></p>
    <details wire:ignore.self>
        <summary class="text-link">Lihat daftar {{ $history['count'] }} anggota</summary>
        <div data-testid="membership-private-details">
            <div class="toolbar">
                <label class="search"><span aria-hidden="true">⌕</span><input wire:model.live.debounce.350ms="membershipSearch" aria-label="Cari riwayat anggota" placeholder="Cari nama, alamat, jabatan, atau HP"></label>
                <label class="field"><span>Status pada sumber</span><select wire:model.live="membershipStatus" aria-label="Status anggota historis"><option value="">Semua status</option><option value="Aktif">Aktif</option><option value="Pasif">Pasif</option></select></label>
            </div>
            <p class="muted">{{ $rows->total() }} catatan sesuai pencarian. Data pribadi hanya untuk pengurus berwenang.</p>
            <div class="table-wrap"><table>
                <thead><tr><th>No. sumber</th><th>Nama anggota</th><th>Alamat lengkap</th><th>Jabatan</th><th>Status saat itu</th><th>No. HP sumber</th><th>Asal</th></tr></thead>
                <tbody>
                @forelse($rows as $row)
                    <tr>
                        <td>{{ $row->cells['B']['value'] }}</td>
                        <td>{{ $row->cells['C']['value'] }}</td>
                        <td>{{ trim((string) ($row->cells['D']['value'] ?? '')) ?: 'Belum tercantum' }}</td>
                        <td>{{ trim((string) ($row->cells['E']['value'] ?? '')) ?: 'Belum tercantum' }}</td>
                        <td><span class="badge {{ $row->cells['F']['value'] === 'Aktif' ? 'success' : 'neutral' }}">{{ $row->cells['F']['value'] }}</span></td>
                        <td>{{ trim((string) ($row->cells['G']['value'] ?? '')) ?: 'Belum tercantum' }}</td>
                        <td><small>{{ $row->sheet }}:{{ $row->row_number }}</small></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">Tidak ada anggota yang cocok dengan pencarian.</td></tr>
                @endforelse
                </tbody>
            </table></div>
            {{ $rows->links() }}
        </div>
    </details>
</section>

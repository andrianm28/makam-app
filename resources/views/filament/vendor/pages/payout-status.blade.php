{{-- Payout Status page --}}
<div>
    <div class="fi-header">
        <h1 class="fi-header-heading">{{ $this->title }}</h1>
    </div>
    <div class="fi-content">
        <x-filament-tables::table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Jumlah</th>
                    <th>Status</th>
                    <th>Bebas Pada</th>
                    <th>Dicairkan Pada</th>
                    <th>Referensi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        <td>{{ $record->uuid }}</td>
                        <td>Rp {{ number_format($record->amount_minor / 100, 2) }}</td>
                        <td>
                            @if($record->state === 'held')
                                <span class="badge gray">Ditahan</span>
                            @elseif($record->state === 'payable')
                                <span class="badge info">Dapat Dicairkan</span>
                            @else
                                <span class="badge success">Sudah Dicairkan</span>
                            @endif
                        </td>
                        <td>{{ $record->eligible_at?->format('d M Y') }}</td>
                        <td>{{ $record->paid_at?->format('d M Y') }}</td>
                        <td>{{ $record->payout?->reference }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-gray-500">Belum ada data pencairan.</td>
                    </tr>
                @endforelse
            </tbody>
        </x-filament-tables::table>
    </div>
</div>

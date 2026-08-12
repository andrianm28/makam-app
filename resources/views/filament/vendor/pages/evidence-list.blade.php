{{-- Evidence List page --}}
<div>
    <div class="fi-header">
        <h1 class="fi-header-heading">{{ $this->title }}</h1>
    </div>
    <div class="fi-content">
        <x-filament-tables::table>
            <thead>
                <tr>
                    <th>ID Pesanan</th>
                    <th>Jenis Bukti</th>
                    <th>Diunggah</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        <td>{{ $record->order?->uuid }}</td>
                        <td>
                            @if($record->evidence_type === 'PHOTO')
                                <span class="badge">Foto</span>
                            @else
                                <span class="badge">Dokumen</span>
                            @endif
                        </td>
                        <td>{{ $record->uploaded_at?->format('d M Y H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="text-center text-gray-500">Belum ada bukti yang diunggah.</td>
                    </tr>
                @endforelse
            </tbody>
        </x-filament-tables::table>
    </div>
</div>

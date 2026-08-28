{{--
    Quota Cards — the aggregate tier's availability view. READ-ONLY by
    design: an aggregate cemetery has no per-plot truth to act on, and the
    write path for `cemetery_packages.availability_status` stays where it
    already is, the PackagesRelationManager under CemeteryResource. This
    phase adds no second editor for the same column.

    Status colour and label come from
    StatusIntent::FAMILY_CEMETERY_PACKAGE_AVAILABILITY — never a local
    match(), per design-system.md §3.7/§9.2.
--}}
@php
    use App\Support\Design\StatusIntent;

    $packages = $this->packages();
@endphp

<x-filament::section>
    <x-slot name="heading">Kuota per paket</x-slot>
    <x-slot name="description">
        Ketersediaan tingkat paket/kelas. Angka ini bersifat indikatif, bukan jaminan ketersediaan.
    </x-slot>

    <div class="grid divide-y divide-neutral-200">
        @forelse ($packages as $package)
            <div class="grid gap-y-1.5 py-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium text-neutral-800">{{ $package->name }}</span>
                    @if ($package->class_label !== null)
                        <span class="text-xs text-neutral-600">{{ $package->class_label }}</span>
                    @endif
                    <x-filament::badge :color="StatusIntent::filamentColor($package->availability_status, StatusIntent::FAMILY_CEMETERY_PACKAGE_AVAILABILITY)">
                        {{ StatusIntent::label($package->availability_status, StatusIntent::FAMILY_CEMETERY_PACKAGE_AVAILABILITY) }}
                    </x-filament::badge>
                </div>
                @if ($package->description !== null)
                    <p class="text-sm text-neutral-600">{{ $package->description }}</p>
                @endif
            </div>
        @empty
            <p class="text-sm text-neutral-600">
                Makam ini belum memiliki paket atau kelas aktif.
            </p>
        @endforelse
    </div>
</x-filament::section>

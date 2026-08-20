<x-filament-widgets::widget>
    <x-filament::section heading="Status Transaksi" :description="'Total ' . number_format($totalTransactions, 0, ',', '.') . ' transaksi di seluruh modul'">
        <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
            <div>
                <h3 class="fi-section-header-heading text-sm font-semibold text-gray-950 dark:text-white">
                    Booking
                </h3>

                <div class="mt-3 flex flex-wrap gap-2">
                    @forelse ($bookingRows as $row)
                        <x-filament::badge :color="$row['color']">
                            {{ $row['label'] }} ({{ $row['count'] }})
                        </x-filament::badge>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada data booking.</p>
                    @endforelse
                </div>
            </div>

            <div>
                <h3 class="fi-section-header-heading text-sm font-semibold text-gray-950 dark:text-white">
                    Marketplace
                </h3>

                <div class="mt-3 flex flex-wrap gap-2">
                    @forelse ($marketplaceRows as $row)
                        <x-filament::badge :color="$row['color']">
                            {{ $row['label'] }} ({{ $row['count'] }})
                        </x-filament::badge>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada data marketplace.</p>
                    @endforelse
                </div>
            </div>

            <div>
                <h3 class="fi-section-header-heading text-sm font-semibold text-gray-950 dark:text-white">
                    Perpanjangan
                </h3>

                <div class="mt-3 flex flex-wrap gap-2">
                    @forelse ($renewalRows as $row)
                        <x-filament::badge :color="$row['color']">
                            {{ $row['label'] }} ({{ $row['count'] }})
                        </x-filament::badge>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada data perpanjangan.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

<div>
    @unless ($unavailable || empty($showcase))
        <section aria-labelledby="plot-preview-heading" class="mx-auto max-w-content px-4 py-5 md:px-6 lg:px-8 lg:py-8">
            <h2 id="plot-preview-heading" class="mb-2 text-center text-2xl font-semibold text-neutral-900">
                Lihat Contoh Ketersediaan Plot
            </h2>
            <p class="mx-auto mb-6 max-w-prose text-center text-base text-neutral-600">
                Data plot di bawah ini nyata dan diperbarui secara berkala, bukan ilustrasi — sebagian kecil dari
                TPU/TPS kami yang sudah memiliki data plot rinci.
            </p>
            <div class="grid gap-y-8">
                @foreach ($showcase as $entry)
                    @php [$cemetery, $blocks] = [$entry['cemetery'], $entry['blocks']]; @endphp
                    <div wire:key="plot-preview-cemetery-{{ $cemetery['id'] }}">
                        <h3 class="mb-3 text-lg font-semibold text-neutral-900">{{ $cemetery['name'] }}</h3>
                        <div class="grid gap-y-4">
                            @foreach ($blocks as $block)
                                <div wire:key="plot-preview-block-{{ $block['id'] }}">
                                    <p class="mb-2 text-sm font-medium text-neutral-900">{{ $block['code'] }} &mdash; {{ $block['name'] }}</p>
                                    <ul class="flex flex-wrap gap-2" aria-label="Plot di {{ $block['code'] }}">
                                        @foreach ($block['plots'] as $plot)
                                            <li wire:key="plot-preview-plot-{{ $plot['id'] }}" class="inline-flex items-center gap-1 rounded-md border border-neutral-200 px-2 py-1 text-sm text-neutral-700">
                                                {{ $plot['slot'] }}
                                                <x-mk.badge
                                                    intent="{{ \App\Support\Design\StatusIntent::intent($plot['plot_state'], \App\Support\Design\StatusIntent::FAMILY_PLOT_STATE) }}"
                                                    :icon="\App\Support\Design\StatusIntent::icon($plot['plot_state'], \App\Support\Design\StatusIntent::FAMILY_PLOT_STATE)"
                                                    size="sm"
                                                >
                                                    {{ \App\Support\Design\StatusIntent::label($plot['plot_state'], \App\Support\Design\StatusIntent::FAMILY_PLOT_STATE) }}
                                                </x-mk.badge>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="mt-6 text-center text-sm text-neutral-600">
                <a href="{{ route('cemeteries.index') }}" class="font-medium text-primary-700 underline underline-offset-2">
                    Lihat semua TPU &amp; TPS
                </a>
            </p>
        </section>
    @endunless
</div>

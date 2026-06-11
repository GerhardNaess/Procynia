@props([
    'title'       => 'Hjelp',
    'description' => null,
    'intro'       => null,
    'sections'    => [],
])

<x-filament::section
    :heading="$title"
    :description="$description"
    collapsible
    :collapsed="true"
>
    @if ($intro)
        <p class="mb-4 text-sm leading-6 text-gray-600">{{ $intro }}</p>
    @endif

    @if (count($sections))
        <div class="space-y-4">
            @foreach ($sections as $section)
                <div>
                    @if (!empty($section['title']))
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">
                            {{ $section['title'] }}
                        </h3>
                    @endif

                    @if (!empty($section['items']))
                        <ul class="space-y-2">
                            @foreach ($section['items'] as $item)
                                <li class="rounded-xl border border-gray-100 bg-gray-50 px-4 py-3">
                                    @if (!empty($item['title']))
                                        <p class="mb-0.5 text-sm font-semibold text-gray-700">{{ $item['title'] }}</p>
                                    @endif
                                    <p class="text-sm leading-5 text-gray-500">{{ $item['text'] }}</p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-filament::section>

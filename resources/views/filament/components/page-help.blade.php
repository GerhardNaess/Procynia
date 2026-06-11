@if (!empty($intro))
    <p class="mb-4 text-sm leading-6 text-gray-600">{{ $intro }}</p>
@endif

@if (!empty($sections))
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

@if (!empty($editUrl))
    <div class="mt-4 border-t border-gray-100 pt-4">
        <a href="{{ $editUrl }}"
           class="inline-flex items-center gap-1.5 text-xs font-medium text-primary-600 hover:text-primary-500">
            <x-filament::icon
                icon="heroicon-m-pencil-square"
                class="h-3.5 w-3.5"
            />
            {{ $editLabel ?? 'Rediger hjelpetekst' }}
        </a>
    </div>
@endif

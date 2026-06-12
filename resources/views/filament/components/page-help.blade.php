@if (!empty($intro))
    <p class="mb-4 text-sm leading-6 text-gray-600">{{ $intro }}</p>
@endif

@if (!empty($sections))
    <div class="space-y-1">
        @foreach ($sections as $loop_index => $section)
            <details
                class="group rounded-lg border border-gray-200"
                @if ($loop_index === 0) open @endif
            >
                <summary class="flex cursor-pointer select-none items-center justify-between px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                    {{ $section['title'] ?? '' }}
                    <svg class="h-4 w-4 shrink-0 text-gray-400 transition-transform group-open:rotate-180" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                    </svg>
                </summary>
                @if (!empty($section['items']))
                    <ul class="border-t border-gray-100 px-4 py-2">
                        @foreach ($section['items'] as $item)
                            <li class="py-1.5">
                                @if (!empty($item['title']))
                                    <span class="text-xs font-semibold text-gray-700">{{ $item['title'] }}: </span>
                                @endif
                                <span class="text-xs leading-5 text-gray-500">{{ $item['text'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </details>
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

@if (!empty($intro))
    <p class="mb-4 text-base leading-7 text-gray-600 dark:text-gray-400">{{ $intro }}</p>
@endif

@if (!empty($sections))
    <div class="space-y-2">
        @foreach ($sections as $loop_index => $section)
            <details
                class="group rounded-xl border border-orange-100 bg-orange-50 dark:border-orange-900/40 dark:bg-orange-950/20"
                @if ($loop_index === 0) open @endif
            >
                <summary class="flex cursor-pointer select-none items-center justify-between px-4 py-3 font-semibold text-gray-900 hover:bg-orange-100/60 dark:text-gray-100 dark:hover:bg-orange-900/20">
                    {{ $section['title'] ?? '' }}
                    <svg class="h-4 w-4 shrink-0 text-orange-400 transition-transform group-open:rotate-180" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                    </svg>
                </summary>
                @if (!empty($section['items']))
                    <ul class="border-t border-orange-100 px-4 pb-3 pt-2 dark:border-orange-900/40">
                        @foreach ($section['items'] as $item)
                            <li class="py-1.5">
                                @if (!empty($item['title']))
                                    <span class="text-base font-semibold leading-7 text-gray-800 dark:text-gray-200">{{ $item['title'] }}: </span>
                                @endif
                                <span class="text-base leading-7 text-gray-700 dark:text-gray-300">{{ $item['text'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </details>
        @endforeach
    </div>
@endif

@if (!empty($editUrl))
    <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
        <a href="{{ $editUrl }}"
           class="inline-flex items-center gap-1.5 text-base font-medium text-primary-600 hover:text-primary-500">
            <x-filament::icon
                icon="heroicon-m-pencil-square"
                class="h-3.5 w-3.5"
            />
            {{ $editLabel ?? 'Rediger hjelpetekst' }}
        </a>
    </div>
@endif

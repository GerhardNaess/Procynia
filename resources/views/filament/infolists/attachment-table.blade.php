@php
    /** @var \App\Models\OperationalRunbook $record */
    $attachments = $record->attachments()->orderBy('sort_order')->orderBy('created_at')->get();
@endphp

@if ($attachments->isEmpty())
    <div class="rounded-xl border border-dashed border-gray-300 dark:border-white/20 bg-gray-50 dark:bg-white/5 px-5 py-6 text-base leading-6 text-gray-600 dark:text-gray-400">
        {{ __('procynia.operational_runbooks.empty_states.attachments') }}
    </div>
@else
    <div x-data="{ open: null }" class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
        {{-- Scrolls sideways on narrow screens instead of widening the page (1.4.10); [contain:inline-size]
             keeps the table's width from pushing the infolist wider than the viewport. --}}
        <div class="relative overflow-x-auto [contain:inline-size]" @include('filament.components.scroll-region-attrs', ['label' => __('procynia.operational_runbooks.sections.attachments')])>
        <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10 text-base">
            <caption class="sr-only">{{ __('procynia.operational_runbooks.sections.attachments') }}</caption>
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400">{{ __('procynia.operational_runbooks.fields.filename') }}</th>
                    <th scope="col" class="px-4 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ __('procynia.operational_runbooks.fields.type') }}</th>
                    <th scope="col" class="px-4 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ __('procynia.operational_runbooks.fields.size') }}</th>
                    <th scope="col" class="px-4 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ __('procynia.operational_runbooks.fields.uploaded_at') }}</th>
                    <th scope="col" class="px-4 py-3 text-left text-base font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ __('procynia.operational_runbooks.actions.download') }}</th>
                    <th scope="col" class="w-8 px-4 py-3"><span class="sr-only">{{ __('procynia.operational_runbooks.fields.description') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10 bg-white dark:bg-gray-900">
                @foreach ($attachments as $attachment)
                    @php $hasDescription = filled($attachment->description); @endphp
                    <tr
                        class="{{ $hasDescription ? 'cursor-pointer select-none' : '' }} hover:bg-gray-50 dark:hover:bg-white/5 transition-colors"
                        @if ($hasDescription) @click="open = open === {{ $attachment->id }} ? null : {{ $attachment->id }}" @endif
                    >
                        <th scope="row" class="px-4 py-3 text-left font-medium text-gray-900 dark:text-white">{{ $attachment->original_name }}</th>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="inline-flex items-center rounded-full bg-primary-50 dark:bg-primary-500/10 px-2.5 py-1 text-base font-medium leading-6 text-primary-700 dark:text-primary-400 ring-1 ring-inset ring-primary-200 dark:ring-primary-500/30">
                                {{ $attachment->file_type_label }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-base text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $attachment->formatted_size ?? '—' }}</td>
                        <td class="px-4 py-3 text-base text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $attachment->created_at?->format('d.m.Y H:i') }}</td>
                        <td class="px-4 py-3 whitespace-nowrap" @click.stop>
                            <a
                                href="{{ route('admin.operational-runbook-attachments.download', ['attachment' => $attachment]) }}"
                                aria-label="{{ __('procynia.operational_runbooks.actions.download') }} {{ $attachment->original_name }}"
                                class="inline-flex items-center gap-1 text-base font-medium text-primary-600 dark:text-primary-400 hover:text-primary-800 hover:underline dark:hover:text-primary-300"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="h-3.5 w-3.5" aria-hidden="true">
                                    <path d="M8.75 2.75a.75.75 0 0 0-1.5 0v5.69L5.03 6.22a.75.75 0 0 0-1.06 1.06l3.5 3.5a.75.75 0 0 0 1.06 0l3.5-3.5a.75.75 0 0 0-1.06-1.06L8.75 8.44V2.75Z" />
                                    <path d="M3.5 9.75a.75.75 0 0 0-1.5 0v1.5A2.75 2.75 0 0 0 4.75 14h6.5A2.75 2.75 0 0 0 14 11.25v-1.5a.75.75 0 0 0-1.5 0v1.5c0 .69-.56 1.25-1.25 1.25h-6.5c-.69 0-1.25-.56-1.25-1.25v-1.5Z" />
                                </svg>
                                {{ __('procynia.operational_runbooks.actions.download') }}
                            </a>
                        </td>
                        {{-- A11Y-053: the description opens from a real button (keyboard, aria-expanded); the row
                             click stays as a mouse shortcut. --}}
                        <td class="px-2 py-2 text-gray-600 dark:text-gray-400" @click.stop>
                            @if ($hasDescription)
                                <button
                                    type="button"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:focus-visible:outline-primary-400"
                                    aria-controls="runbook-attachment-description-{{ $attachment->id }}"
                                    aria-expanded="false"
                                    :aria-expanded="(open === {{ $attachment->id }}).toString()"
                                    data-testid="runbook-attachment-description-toggle"
                                    @click="open = open === {{ $attachment->id }} ? null : {{ $attachment->id }}"
                                >
                                    <span class="sr-only">{{ \App\Filament\Support\AdminText::get('admin_a11y.attachment_description_toggle', 'Beskrivelse av :name', ['name' => $attachment->original_name]) }}</span>
                                    <svg
                                        :class="open === {{ $attachment->id }} ? 'rotate-180' : ''"
                                        class="h-4 w-4 transition-transform duration-150"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
                                    </svg>
                                </button>
                            @endif
                        </td>
                    </tr>
                    @if ($hasDescription)
                        <tr id="runbook-attachment-description-{{ $attachment->id }}" x-show="open === {{ $attachment->id }}" x-cloak>
                            <td colspan="6" class="bg-gray-50 dark:bg-white/5 px-6 pb-4 pt-2">
                                <div class="text-base font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400">{{ __('procynia.operational_runbooks.fields.description') }}</div>
                                <p class="mt-1 whitespace-pre-wrap text-base leading-6 text-gray-900 dark:text-white">{{ $attachment->description }}</p>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
@endif

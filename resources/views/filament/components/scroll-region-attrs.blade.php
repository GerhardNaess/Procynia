{{--
    Attributes for the box a wide admin table scrolls sideways in — the Blade/Alpine counterpart of
    resources/js/Components/App/ScrollRegion.jsx (A11Y-110 in the customer frontend; WCAG Fase 6 here).

    While — and only while — the content is wider than the box, the box becomes a named region in the
    Tab order, so a keyboard user can focus it and scroll it with the arrow keys (2.1.1; axe
    scrollable-region-focusable). A table that fits adds no tab stop and no landmark.

    Usage, inside the opening tag of the scrolling element:
        <div class="relative overflow-x-auto" @include('filament.components.scroll-region-attrs', ['label' => $label])>
    or ['labelledby' => 'id-of-the-section-heading'].

    Give the box `relative`: an sr-only caption or header inside an unpositioned scroll box is positioned
    against the page, not the box, and widens the page on a phone.

    No @if here: Livewire wraps Blade conditionals in <!--[if BLOCK]> comments, which would land inside
    the tag and break its attributes.
--}}
x-data="{ scrollable: false }"
x-init="const measure = () => { scrollable = $el.scrollWidth > $el.clientWidth + 1 || $el.scrollHeight > $el.clientHeight + 1 }; measure(); if (window.ResizeObserver) { const observer = new ResizeObserver(measure); observer.observe($el); if ($el.firstElementChild) { observer.observe($el.firstElementChild) } } else { window.addEventListener('resize', measure) }"
x-bind:tabindex="scrollable ? 0 : null"
x-bind:role="scrollable ? 'region' : null"
{!! ! empty($labelledby) ? 'x-bind:aria-labelledby="scrollable ? '.e(\Illuminate\Support\Js::from((string) $labelledby)).' : null"' : 'x-bind:aria-label="scrollable ? '.e(\Illuminate\Support\Js::from((string) ($label ?? ''))).' : null"' !!}
data-admin-scroll-region

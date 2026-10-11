{{-- One headline figure in the RD-2 shell (docs/mockups/RD-5/option-a.html, "Right now" row): an uppercase label, a
     big mono number with a short accent beside it, and one line under it. A null `value` (its source could not be
     read) shows "—" and leaves `data-figure-value` empty. `tone` colours the accent: needs (amber), live (blue) or
     neutral. Built for the overview's four figures; the preflight page's trend strip compares two windows and is not
     this. --}}
@props(['key', 'label', 'value' => null, 'accent' => null, 'tone' => null])
@php
    $accentClass = match ($tone) {
        'needs' => 'text-needs',
        'live' => 'text-live',
        default => 'text-ink-muted',
    };
@endphp
<article data-figure="{{ $key }}" data-figure-value="{{ $value }}" {{ $attributes->class('flex flex-col gap-2.5 rounded-xl border border-edge bg-surface p-4.5') }}>
    <h3 class="text-xs font-medium tracking-wider text-ink-muted uppercase">{{ $label }}</h3>
    <p class="flex flex-wrap items-baseline gap-x-2.5">
        <span class="font-mono text-3xl font-medium tracking-tight">{{ $value ?? '—' }}</span>
        @if ($accent)<span class="text-sm {{ $accentClass }}">{{ $accent }}</span>@endif
    </p>
    @if ($slot->isNotEmpty())<p class="text-sm text-ink-muted">{{ $slot }}</p>@endif
</article>

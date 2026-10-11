{{-- A small outlined pill in the RD-2 shell (docs/mockups/RD-3/option-a.html's `.pill`): a story's ID, status or
     initiative, a count in a Details header. `tone` colours text and border for a signal — needs (amber, waiting on
     the owner), go (built), live (in progress), fail — and stays neutral otherwise. `mono` sets it in Geist Mono. --}}
@props(['tone' => null, 'mono' => false])
@php
    $toneClass = match ($tone) {
        'needs' => 'border-needs/50 text-needs',
        'go' => 'border-go/50 text-go',
        'live' => 'border-live/50 text-live',
        'fail' => 'border-fail/50 text-fail',
        default => 'border-rim text-ink-soft',
    };
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs whitespace-nowrap', $toneClass, 'font-mono' => $mono]) }}>{{ $slot }}</span>

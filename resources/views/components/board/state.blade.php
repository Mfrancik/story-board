{{--
    A project's snapshot state (SB-9 tile, SB-10 project header): `part="dot"` is the coloured dot,
    `part="label"` the word beside it. An `ok` snapshot has no label — the dot says enough — and any
    state outside the four known ones is shown in the danger tone, never hidden.
--}}
@props(['state', 'part' => 'dot'])
@php
    // State → dot token, label and label tone; the same token map as the sidebar's dots (SB-7).
    [$dot, $label, $tone] = match ($state) {
        'ok' => ['bg-ok', null, null],
        'pending' => ['bg-pending', 'Not read yet', 'text-zinc-500 dark:text-zinc-400'],
        'stale' => ['bg-warning', 'Stale', 'text-warning'],
        'unreachable' => ['bg-danger', 'Unreachable', 'text-danger'],
        default => ['bg-danger', $state, 'text-danger'],
    };
@endphp
@if ($part === 'dot')
    <span data-state-dot="{{ $state }}" {{ $attributes->class(['size-2 shrink-0 rounded-full', $dot]) }} aria-hidden="true"></span>
@elseif ($label)
    <span data-state="{{ $state }}" {{ $attributes->class(['shrink-0 text-xs font-medium', $tone]) }}>{{ $label }}</span>
@endif

{{--
    A project's snapshot state (SB-7 sidebar, SB-9 tile, SB-10 project header) — the one state→token map: `part="dot"` is the coloured dot,
    `part="label"` the word beside it. An `ok` snapshot has no label — the dot says enough — and any
    state outside the four known ones is shown in the danger tone, never hidden.
--}}
@props(['state', 'part' => 'dot'])
@php
    // State → dot token, label and label tone. The sidebar renders its dots through here too, so the map cannot drift.
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

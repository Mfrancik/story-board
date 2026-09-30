{{-- A mockup set's pick state (SB-21): Awaiting pick (the `pick` token, as the What needs me pick card),
     Picked X (the `built` token, as the chosen ring on the story modal), or grey for anything else. The
     words always carry the meaning; colour only reinforces it. A board pick not pushed yet says so. --}}
@props(['set'])
@php
    $state = $set['state'];
    $tone = match ($state) {
        \App\Actions\Board\ReadMockupSets::AWAITING => 'bg-pick/15 text-zinc-800 ring-pick/40 dark:text-zinc-100',
        \App\Actions\Board\ReadMockupSets::PICKED => 'bg-built/15 text-zinc-800 ring-built/40 dark:text-zinc-100',
        default => 'bg-zinc-100 text-zinc-600 ring-zinc-300 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-700',
    };
@endphp
<span data-mockup-state="{{ $state }}" {{ $attributes->class(['inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $tone]) }}>
    @if ($state === \App\Actions\Board\ReadMockupSets::AWAITING)
        <span class="size-1.5 rounded-full bg-pick" aria-hidden="true"></span>Awaiting pick
    @elseif ($state === \App\Actions\Board\ReadMockupSets::PICKED)
        <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg>Picked {{ strtoupper((string) $set['picked']) }}@unless ($set['pushed']) · not pushed @endunless
    @else
        {{ $set['status'] === null ? 'No story file' : ($set['recorded'] ? 'Choice as written' : 'No pick recorded') }}
    @endif
</span>

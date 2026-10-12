{{-- The page tabs under a project's header (SB-14, design A; RD-4 order, RD-11 puts Overview first): Overview (RD-11) | Stories (SB-15) | Preflight (SB-16) | Handbook |
     Map (SB-24). Real links, one URL each, so a tab can be opened in a new window and Back works as expected. The row
     scrolls sideways if it ever outgrows a phone; pb-px keeps the active tab's -mb-px underline inside the scroll box
     instead of adding a 1px vertical scroll. RD-4 dropped the Dashboard tab and RD-9 deleted the old dashboard, so every
     page that shows these tabs marks one current. Inside the dark shell the tones come from the shell tokens
     (`shell:`); the light/dark ones served that old dashboard's light layout. --}}
@props(['project', 'current'])
@php
    $tabs = ['overview' => ['Overview', route('projects.overview', $project)], 'stories' => ['Stories', route('projects.stories', $project)], 'preflight' => ['Preflight', route('projects.preflight', $project)],
        'handbook' => ['Handbook', route('projects.handbook', $project)], 'map' => ['Map', route('projects.map', $project)]];
@endphp
<nav aria-label="Project pages" data-project-tabs {{ $attributes->class('mt-5 flex gap-4 sm:gap-6 overflow-x-auto pb-px border-b border-zinc-200 text-sm dark:border-zinc-800 shell:border-hairline') }}>
    @foreach ($tabs as $key => [$label, $url])
        <a href="{{ $url }}" wire:navigate data-project-tab="{{ $key }}" @if ($key === $current) aria-current="page" @endif
            @class([
                '-mb-px shrink-0 border-b-2 pb-3',
                'border-zinc-800 font-medium dark:border-white shell:border-ink shell:text-ink' => $key === $current,
                'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200 shell:text-ink-muted shell:hover:text-ink' => $key !== $current,
            ])>{{ $label }}</a>
    @endforeach
</nav>

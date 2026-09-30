{{-- The page tabs under a project's header (SB-14, design A): Dashboard (SB-10) | Handbook | Stories (SB-15) | Preflight (SB-16) | App map (SB-24). Real links,
     one URL each, so a tab can be opened in a new window and Back works as expected. Five tabs outgrow a 375px phone, so
     the row scrolls sideways; pb-px keeps the active tab's -mb-px underline inside the scroll box instead of adding a 1px vertical scroll. --}}
@props(['project', 'current'])
@php
    $tabs = ['dashboard' => ['Dashboard', route('projects.show', $project)], 'handbook' => ['Handbook', route('projects.handbook', $project)],
        'stories' => ['Stories', route('projects.stories', $project)], 'preflight' => ['Preflight', route('projects.preflight', $project)],
        'map' => ['App map', route('projects.map', $project)]];
@endphp
<nav aria-label="Project pages" data-project-tabs {{ $attributes->class('mt-5 flex gap-4 sm:gap-6 overflow-x-auto pb-px border-b border-zinc-200 text-sm dark:border-zinc-800') }}>
    @foreach ($tabs as $key => [$label, $url])
        <a href="{{ $url }}" wire:navigate data-project-tab="{{ $key }}" @if ($key === $current) aria-current="page" @endif
            @class([
                '-mb-px shrink-0 border-b-2 pb-3',
                'border-zinc-800 font-medium dark:border-white' => $key === $current,
                'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' => $key !== $current,
            ])>{{ $label }}</a>
    @endforeach
</nav>

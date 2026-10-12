{{-- The Marketing tab's own page links (MK-1, the prototype's sub-nav): Campaigns · Calendar · Library · Inspiration ·
     Settings. A page is listed only once its route exists, so an unbuilt page is left out rather than shown as a dead
     link; each joins by itself when its story registers `projects.marketing.<key>` (Calendar and Library MK-10,
     Inspiration MK-18, Settings MK-2). `current` is the key of the page being shown. --}}
@props(['project', 'current'])
@php
    $pages = collect([
        'campaigns' => ['Campaigns', 'projects.marketing'],
        'calendar' => ['Calendar', 'projects.marketing.calendar'],
        'library' => ['Library', 'projects.marketing.library'],
        'inspiration' => ['Inspiration', 'projects.marketing.inspiration'],
        'settings' => ['Settings', 'projects.marketing.settings'],
    ])->filter(fn (array $page) => Route::has($page[1]));
@endphp
<nav aria-label="Marketing pages" data-marketing-subnav {{ $attributes->class('flex flex-wrap gap-1.5') }}>
    @foreach ($pages as $key => [$label, $name])
        <a href="{{ route($name, $project) }}" wire:navigate data-marketing-page="{{ $key }}" @if ($key === $current) aria-current="page" @endif
            @class([
                'inline-flex min-h-9 items-center rounded-lg px-3 text-sm outline-needs focus-visible:outline-2 focus-visible:outline-offset-2',
                'bg-raised font-medium text-ink' => $key === $current,
                'text-ink-muted hover:bg-raised hover:text-ink' => $key !== $current,
            ])>{{ $label }}</a>
    @endforeach
</nav>

{{-- The Marketing tab's own page links (MK-1, the prototype's sub-nav): Campaigns · Review · Calendar · Library ·
     Inspiration · Settings. A page is listed only once its route exists, so an unbuilt page is left out rather than
     shown as a dead link; each joins by itself when its story registers `projects.marketing.<key>` (Calendar and
     Library MK-10, Inspiration MK-18, Settings MK-2, Review MK-20). `current` is the key of the page being shown.

     MK-20: Review carries a count of the dates waiting for a pick (ListReviewQueue), hidden at 0. The Review page
     passes the count it already has as `reviewCount`, so it is not worked out twice. --}}
@use('App\Actions\Marketing\ListReviewQueue')
@use('App\Models\Project')
@props(['project', 'current', 'reviewCount' => null])
@php
    $pages = collect([
        'campaigns' => ['Campaigns', 'projects.marketing'],
        'review' => ['Review', 'projects.marketing.review'],
        'calendar' => ['Calendar', 'projects.marketing.calendar'],
        'library' => ['Library', 'projects.marketing.library'],
        'inspiration' => ['Inspiration', 'projects.marketing.inspiration'],
        'settings' => ['Settings', 'projects.marketing.settings'],
    ])->filter(fn (array $page) => Route::has($page[1]));
    if ($pages->has('review')) {
        $reviewCount ??= app(ListReviewQueue::class)->count(Project::where('name', $project)->firstOrFail(), now());
    }
@endphp
<nav aria-label="Marketing pages" data-marketing-subnav {{ $attributes->class('flex flex-wrap gap-1.5') }}>
    @foreach ($pages as $key => [$label, $name])
        <a href="{{ route($name, $project) }}" wire:navigate data-marketing-page="{{ $key }}" @if ($key === $current) aria-current="page" @endif
            @class([
                'inline-flex min-h-9 items-center gap-1.5 rounded-lg px-3 text-sm outline-needs focus-visible:outline-2 focus-visible:outline-offset-2',
                'bg-raised font-medium text-ink' => $key === $current,
                'text-ink-muted hover:bg-raised hover:text-ink' => $key !== $current,
            ])>{{ $label }}@if ($key === 'review' && $reviewCount > 0)<span data-review-count class="rounded-full bg-needs px-1.5 font-mono text-xs leading-4 font-semibold text-needs-ink">{{ $reviewCount }}</span><span class="sr-only"> {{ $reviewCount === 1 ? 'date waits' : 'dates wait' }} for a pick</span>@endif</a>
    @endforeach
</nav>

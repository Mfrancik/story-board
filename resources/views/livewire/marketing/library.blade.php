{{--
    MK-10 Library (docs/mockups/MK-1/option-b.html reorganised as the prototype's round-5 Library route) in the RD-2
    shell: the project header with Marketing current and the marketing sub-nav; the title and what lives here; search
    and the grid / list toggle; campaign filter chips (All, each campaign, One-off); then Coming up (by week) and Posted
    (by month, newest first; past months start collapsed), each with its count.

    The grid / list toggle is Alpine (`layout`, read by the cards through `group-data-[layout=list]/lib` variants), so
    it makes no request. A card opens its post in the post studio (MK-9). Each card's "♡ Save to Inspiration" (MK-18)
    is a sibling of the card's button, not inside it (a button cannot hold a button), laid over the card's corner.
--}}
@php
    $focus = 'outline-needs focus-visible:outline-2 focus-visible:outline-offset-2';
    $sections = [
        'coming' => ['Coming up', 'approved, in date order', $narrowed ? 'Nothing approved matches.' : 'Nothing approved yet. Open a campaign to fill dates.'],
        'posted' => ['Posted', 'newest first', $narrowed ? 'Nothing posted matches.' : 'Nothing posted yet.'],
    ];
    $chips = collect([[\App\Actions\Marketing\ListLibraryPosts::ALL, 'All']])
        ->merge($campaigns->map(fn ($c) => [(string) $c->id, $c->name]))
        ->push([\App\Actions\Marketing\ListLibraryPosts::ONE_OFF, 'One-off']);
@endphp
<main data-marketing-library x-data="{ layout: 'grid' }" x-bind:data-layout="layout"
    class="group/lib mx-auto flex max-w-7xl min-w-0 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-10 lg:py-10">
    <div class="flex flex-col gap-4">
        <x-board.project-header :model="$model" page="Marketing" current="marketing" />
        <x-marketing.sub-nav :project="$model->name" current="library" />
    </div>

    <div class="flex flex-col gap-1">
        <h2 class="text-xl font-semibold tracking-tight">Library</h2>
        <p class="text-sm text-ink-muted">Everything approved or posted. Drafts stay on their campaign; ads you like live in <a href="{{ route('projects.marketing.inspiration', $model->name) }}" wire:navigate class="text-needs hover:underline {{ $focus }}">Inspiration</a>.</p>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <label class="relative min-w-0 flex-1 basis-64">
            <span class="sr-only">Search captions</span>
            <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-faint" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <input type="search" wire:model.live.debounce.300ms="search" data-library-search placeholder="Search captions"
                class="min-h-10 w-full rounded-lg border border-rim bg-ground py-2 pr-3 pl-9 text-sm {{ $focus }}">
        </label>
        <span wire:loading.delay class="text-xs text-ink-muted">Searching…</span>
        <div class="flex items-center gap-1 rounded-lg border border-rim p-0.5" role="group" aria-label="Layout">
            @foreach (['grid' => 'Grid', 'list' => 'List'] as $layout => $label)
                <button type="button" x-on:click="layout = '{{ $layout }}'" data-library-layout="{{ $layout }}" x-bind:aria-pressed="layout === '{{ $layout }}'"
                    class="inline-flex min-h-8 items-center rounded-md px-3 text-sm text-ink-muted hover:text-ink {{ $focus }}"
                    x-bind:class="layout === '{{ $layout }}' && 'bg-raised font-medium text-ink'">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div class="flex flex-wrap gap-1.5" role="group" aria-label="Campaign">
        @foreach ($chips as [$value, $label])
            <button type="button" wire:click="$set('filter', '{{ $value }}')" data-library-filter="{{ $value }}" aria-pressed="{{ $filter === $value ? 'true' : 'false' }}"
                @class(['inline-flex min-h-9 items-center rounded-full border px-3 text-sm '.$focus,
                    'border-needs/60 bg-needs/15 text-needs' => $filter === $value,
                    'border-rim text-ink-soft hover:bg-raised' => $filter !== $value])>{{ $label }}</button>
        @endforeach
    </div>

    @foreach ($sections as $key => [$title, $hint, $empty])
        <section data-library-section="{{ $key }}" aria-labelledby="lib-{{ $key }}" class="flex min-w-0 flex-col gap-2">
            <h3 id="lib-{{ $key }}" class="flex flex-wrap items-baseline gap-2.5 text-lg font-semibold">
                {{ $title }}
                <span data-library-count class="font-mono text-sm font-normal text-ink-muted">{{ $library['counts'][$key] }}</span>
                <span class="text-xs font-normal text-ink-faint">{{ $hint }}</span>
            </h3>

            @forelse ($library[$key] as $group)
                <details @if ($group['open']) open @endif wire:key="{{ $key }}-{{ $group['label'] }}" class="group/g flex flex-col">
                    <summary class="flex min-h-10 cursor-pointer list-none items-center gap-2 text-sm text-ink-soft {{ $focus }}">
                        <span aria-hidden="true" class="text-ink-faint transition-transform group-open/g:rotate-90">›</span>
                        <span data-library-group-label class="font-medium text-ink">{{ $group['label'] }}</span>
                        <span class="font-mono text-xs text-ink-muted">{{ $group['posts']->count() }}</span>
                    </summary>
                    <div class="mt-1 mb-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 group-data-[layout=list]/lib:grid-cols-1 group-data-[layout=list]/lib:gap-0 group-data-[layout=list]/lib:divide-y group-data-[layout=list]/lib:divide-hairline group-data-[layout=list]/lib:rounded-xl group-data-[layout=list]/lib:border group-data-[layout=list]/lib:border-edge">
                        @foreach ($group['posts'] as $post)
                            @php
                                $zone = $post->campaign?->timezone ?? (string) config('board.timezone');
                                $at = $post->isPosted() && $post->posted_at ? $post->posted_at : $post->scheduled_for;
                            @endphp
                            <div wire:key="card-{{ $post->id }}" class="relative min-w-0">
                            <button type="button" data-library-card="{{ $post->id }}"
                                wire:click="$dispatch('open-studio', { post: {{ $post->id }} })" aria-label="Open {{ $at->copy()->setTimezone($zone)->format('D j M') }} post in the studio"
                                class="flex min-w-0 flex-col gap-2 rounded-xl border border-edge bg-surface p-2 text-left hover:border-rim hover:bg-raised group-data-[layout=list]/lib:flex-row group-data-[layout=list]/lib:items-center group-data-[layout=list]/lib:gap-3 group-data-[layout=list]/lib:rounded-none group-data-[layout=list]/lib:border-0 {{ $focus }}">
                                <span class="block w-full shrink-0 group-data-[layout=list]/lib:w-14">
                                    {{-- MK-5: the post's 1:1 ad once rendered; the HTML preview until then. --}}
                                    @if ($renderId = $post->renderId('square'))
                                        <img data-library-render="{{ $post->id }}" src="{{ route('marketing.asset', [$model->name, $renderId]) }}" width="1080" height="1080" loading="lazy"
                                            alt="" class="block aspect-square h-auto w-full rounded-lg">
                                    @else
                                        <x-marketing.post-preview :post="$post" :brand="$brand" :project="$model->name" :label="$post->campaign?->name" />
                                    @endif
                                </span>
                                <span class="flex min-w-0 flex-1 flex-col gap-1 px-1">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span data-campaign-chip @class(['max-w-full truncate rounded-full border px-2 py-0.5 text-xs', 'border-rim text-ink-soft' => $post->campaign, 'border-dashed border-rim text-ink-muted' => ! $post->campaign])>{{ $post->campaign?->name ?? 'One-off' }}</span>
                                    </span>
                                    <span class="truncate text-sm text-ink">{{ trim(strtok((string) $post->caption, "\n") ?: '') }}</span>
                                    <span class="font-mono text-xs text-ink-muted">{{ $at->copy()->setTimezone($zone)->format('D j M · H:i') }}</span>
                                </span>
                            </button>
                            <div class="absolute top-3 right-3 group-data-[layout=list]/lib:top-1/2 group-data-[layout=list]/lib:-translate-y-1/2">
                                @if ($saved->contains($post->id))
                                    <span data-saved-inspiration="{{ $post->id }}" class="inline-flex min-h-8 items-center gap-1 rounded-full bg-ground/90 py-0.5 pr-1 pl-2.5 text-xs text-needs">
                                        ♥ Saved ·
                                        <button type="button" wire:click="undoInspiration({{ $post->id }})" wire:loading.attr="disabled" wire:target="undoInspiration" data-undo-inspiration="{{ $post->id }}" class="rounded px-1.5 py-1 text-ink hover:bg-raised {{ $focus }}">Undo</button>
                                    </span>
                                @else
                                    <button type="button" wire:click="saveToInspiration({{ $post->id }})" wire:loading.attr="disabled" wire:target="saveToInspiration" data-save-inspiration="{{ $post->id }}"
                                        aria-label="Save to Inspiration" title="Save to Inspiration"
                                        class="inline-flex size-9 items-center justify-center rounded-full bg-ground/90 text-base text-ink hover:text-needs {{ $focus }}">♡</button>
                                @endif
                            </div>
                            </div>
                        @endforeach
                    </div>
                </details>
            @empty
                <div data-library-empty="{{ $key }}" class="rounded-xl border border-dashed border-rim bg-surface px-6 py-8 text-center text-sm text-ink-muted">
                    {{ $empty }}
                    @if ($key === 'coming' && ! $narrowed)
                        <a href="{{ route('projects.marketing', $model->name) }}" wire:navigate class="ml-1 text-needs hover:underline {{ $focus }}">Campaigns ›</a>
                    @endif
                </div>
            @endforelse
        </section>
    @endforeach

    {{-- MK-9: the post studio, opened by a card. An explicit key (see RUNBOOK: the studio closing). --}}
    <livewire:marketing.post-studio :project="$model->name" wire:key="post-studio" />
</main>

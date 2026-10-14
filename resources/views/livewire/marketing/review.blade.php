{{--
    MK-20 Review (docs/mockups/MK-20/option-a.html, "Grid") in the RD-2 shell: the project header with Marketing current
    and the marketing sub-nav (Review current, with its count); the title, how many dates wait and the keys; campaign
    filter chips (All, each campaign with a date waiting, One-off); then one row per date, soonest first: the date, its
    time and campaign, Reject all, and its ads side by side — each the 1:1 render (marketing/ad-square) with the caption
    under it, Keep, 👍/👎 and open in the studio. A date being redrafted shows "Drafting…"; a failed redraft, Retry.

    Alpine (`reviewInbox`, resources/js/review-inbox.js) owns the focused row (←/→, click), the 1/2/3 and R keys, the
    dislike reasons and the Reject all confirm (board/confirm-modal), so none of that is a round trip. Below 768 px a
    row's ads scroll sideways inside the row (scroll-snap), so the page itself never does.
--}}
@php
    $focus = 'outline-needs focus-visible:outline-2 focus-visible:outline-offset-2';
    $btn = 'inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border px-3 text-sm font-medium whitespace-nowrap disabled:cursor-not-allowed disabled:opacity-50 '.$focus;
    $icon = 'inline-flex size-10 shrink-0 items-center justify-center rounded-lg border border-rim bg-surface text-ink-soft hover:bg-raised hover:text-ink '.$focus;
    $kbd = 'inline-block min-w-5 rounded border border-b-2 border-rim bg-ground px-1 text-center font-mono text-xs leading-4 text-ink-soft';
    $thumb = '<svg class="size-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true"><path d="M7 11v9H4v-9zM7 11l4-8c1.7 0 3 1.3 3 3v3h5.2a2 2 0 0 1 2 2.3l-1.2 7A2 2 0 0 1 18 20H7"/></svg>';
    // The same hand, upside down, for 👎.
    $thumbDown = str_replace('class="size-4.5"', 'class="size-4.5 -scale-y-100"', $thumb);
    $spin = '<span class="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent motion-reduce:animate-none" aria-hidden="true"></span>';
    $dates = $queue['dates'];
@endphp
<main data-marketing-review x-data="reviewInbox" x-on:keydown.window="key($event)"
    class="mx-auto flex max-w-7xl min-w-0 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-10 lg:py-10">
    <div class="flex flex-col gap-4">
        <x-board.project-header :model="$model" page="Marketing" current="marketing" />
        <x-marketing.sub-nav :project="$model->name" current="review" :review-count="$queue['total']" />
    </div>

    <div class="flex flex-wrap items-end gap-x-4 gap-y-2">
        <h2 class="text-xl font-semibold tracking-tight">Pick your posts</h2>
        <span data-review-waiting class="text-sm text-ink-muted">{{ $queue['waiting'] }} {{ $queue['waiting'] === 1 ? 'date' : 'dates' }} waiting · soonest first</span>
        <div class="ml-auto hidden flex-wrap gap-x-3.5 gap-y-1.5 text-xs text-ink-muted md:flex" aria-label="Keyboard shortcuts">
            <span class="inline-flex items-center gap-1"><kbd class="{{ $kbd }}">←</kbd><kbd class="{{ $kbd }}">→</kbd> dates</span>
            <span class="inline-flex items-center gap-1"><kbd class="{{ $kbd }}">1</kbd><kbd class="{{ $kbd }}">2</kbd><kbd class="{{ $kbd }}">3</kbd> keep</span>
            <span class="inline-flex items-center gap-1"><kbd class="{{ $kbd }}">R</kbd> reject all</span>
        </div>
    </div>

    @if (count($queue['chips']) > 1)
        <div class="flex gap-2 overflow-x-auto p-0.5" role="group" aria-label="Filter by campaign">
            @foreach ($queue['chips'] as $chip)
                <button type="button" wire:click="$set('filter', '{{ $chip['value'] }}')" data-review-filter="{{ $chip['value'] }}" aria-pressed="{{ $filter === $chip['value'] ? 'true' : 'false' }}"
                    @class(['inline-flex min-h-9 shrink-0 items-center gap-2 rounded-full border px-3 text-sm font-medium '.$focus,
                        'border-ink bg-ink text-ground' => $filter === $chip['value'],
                        'border-rim text-ink-soft hover:bg-raised' => $filter !== $chip['value']])>
                    {{ $chip['label'] }} <span class="font-mono text-xs opacity-70">{{ $chip['count'] }}</span>
                </button>
            @endforeach
        </div>
    @endif

    @if ($busy)
        <div wire:poll.2s data-polling class="sr-only">Waiting for new ads…</div>
    @endif

    @if ($dates === [])
        <div data-review-empty class="flex flex-col items-center gap-3.5 rounded-2xl border border-dashed border-rim bg-surface px-5 py-14 text-center">
            <svg class="size-11 text-needs/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 13h5l1.5 2h5L16 13h5"/></svg>
            <h3 class="text-lg font-semibold">Nothing to pick.</h3>
            <p class="text-sm text-ink-muted">Fill some dates on a campaign page.</p>
            <a href="{{ route('projects.marketing', $model->name) }}" wire:navigate class="{{ $btn }} border-needs bg-needs text-needs-ink hover:brightness-110">Go to campaigns</a>
        </div>
    @else
        <div class="flex flex-col gap-4">
            @foreach ($dates as $row)
                @php
                    $campaign = $row['campaign'];
                    $day = $row['at']->format('D j M');
                    $count = $row['posts']->count();
                @endphp
                <section wire:key="row-{{ $row['key'] }}" data-review-row="{{ $row['key'] }}" data-focused="false" tabindex="-1"
                    aria-label="{{ $day }}, {{ $campaign->name ?? 'One-off' }}" x-on:click="pick($el)"
                    class="group/row flex min-w-0 scroll-mt-4 flex-col gap-3.5 rounded-2xl border border-edge bg-surface p-4 outline-none data-[focused=true]:border-needs data-[focused=true]:ring-1 data-[focused=true]:ring-needs">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <span class="text-lg font-semibold">{{ $day }}</span>
                        <span class="font-mono text-sm text-ink-muted">{{ $row['at']->format('H:i') }}</span>
                        <span class="inline-flex items-center rounded-full border border-rim px-2.5 py-0.5 text-xs whitespace-nowrap text-ink-soft">{{ $campaign->name ?? 'One-off' }}</span>
                        <span data-focus-label class="hidden text-xs font-semibold tracking-wide text-needs uppercase md:group-data-[focused=true]/row:inline">focused</span>
                        <span class="flex-1"></span>
                        @if ($campaign !== null && $count > 0)
                            <button type="button" data-reject-all="{{ $row['key'] }}"
                                x-on:click.stop="pick($el.closest('[data-review-row]')); openReject(@js(['campaign' => $campaign->id, 'date' => $row['date'], 'day' => $day, 'count' => $count, 'name' => $campaign->name]))"
                                class="{{ $btn }} w-full border-fail/40 text-fail hover:bg-fail/10 sm:w-auto">Reject all {{ $count }} <kbd class="{{ $kbd }} hidden md:inline-block">R</kbd></button>
                        @endif
                    </div>

                    @if ($row['drafting'] === 'drafting')
                        <p data-review-drafting role="status" class="flex flex-wrap items-center gap-2 text-sm text-live">{!! $spin !!}Drafting… Claude is writing three new ads for this date.</p>
                        <div class="grid grid-cols-3 gap-3 md:gap-4" aria-hidden="true">
                            @for ($i = 0; $i < 3; $i++)
                                <div class="flex aspect-square flex-col items-center justify-center gap-2 rounded-md border border-dashed border-live/40 bg-ground p-3 text-center text-xs font-medium text-live sm:text-sm">{!! $spin !!}Drafting…</div>
                            @endfor
                        </div>
                    @elseif ($row['drafting'] === 'failed')
                        <p data-review-draft-failed role="alert" class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-fail">
                            Drafting failed. The new ads could not be written.
                            <button type="button" wire:click="retryDraft({{ $campaign->id }}, '{{ $row['date'] }}')" wire:loading.attr="disabled" wire:target="retryDraft"
                                data-retry-draft class="{{ $btn }} min-h-9 border-fail text-fail hover:bg-fail hover:text-ground">Retry</button>
                        </p>
                    @else
                        <div class="-mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 pb-1 md:mx-0 md:grid md:grid-cols-3 md:gap-4 md:overflow-visible md:px-0 md:pb-0">
                            @foreach ($row['posts'] as $i => $post)
                                @php
                                    $vote = $post->feedback->first();
                                    $liked = $vote?->vote === \App\Models\MarketingFeedback::LIKED;
                                    $disliked = $vote?->vote === \App\Models\MarketingFeedback::DISLIKED;
                                    $needs = $post->needsPhoto();
                                @endphp
                                <div wire:key="ad-{{ $post->id }}" data-review-ad="{{ $post->id }}"
                                    x-data="{ open: false, reasons: @js($disliked ? ($vote->reasons ?? []) : []) }"
                                    class="flex min-w-0 shrink-0 basis-5/6 snap-center flex-col gap-2.5 md:basis-auto">
                                    <x-marketing.ad-square :post="$post" :brand="$brand" :project="$model->name" :label="$campaign?->name">
                                        <button type="button" wire:click="$dispatch('open-studio', { post: {{ $post->id }} })" class="{{ $btn }} border-rim bg-surface text-ink hover:bg-raised">Add it in the studio</button>
                                    </x-marketing.ad-square>
                                    <p data-review-caption class="line-clamp-3 min-h-18 text-sm/6 break-words whitespace-pre-line text-ink-soft">{{ $post->caption }}</p>
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <button type="button" wire:click="keep({{ $post->id }})" wire:loading.attr="disabled" wire:target="keep"
                                            data-keep="{{ $post->id }}" data-keep-slot="{{ $i + 1 }}" @disabled($needs) @if ($needs) title="Add a photo in the studio first" @endif
                                            class="{{ $btn }} min-w-0 flex-1 border-needs bg-needs text-needs-ink hover:brightness-110">
                                            <kbd class="{{ $kbd }} border-needs-ink/30 bg-needs-ink/10 text-needs-ink">{{ $i + 1 }}</kbd> Keep
                                        </button>
                                        <button type="button" data-vote-up="{{ $post->id }}" aria-label="Like ad {{ $i + 1 }}" aria-pressed="{{ $liked ? 'true' : 'false' }}"
                                            wire:click="vote({{ $post->id }}, {{ $liked ? 0 : 1 }})" x-on:click="open = false; reasons = []"
                                            @class([$icon, 'border-go/50 bg-go/10 text-go' => $liked])>{!! $thumb !!}</button>
                                        <button type="button" data-vote-down="{{ $post->id }}" aria-label="Dislike ad {{ $i + 1 }}" aria-pressed="{{ $disliked ? 'true' : 'false' }}"
                                            @if ($disliked) wire:click="vote({{ $post->id }}, 0)" x-on:click="open = false; reasons = []"
                                            @else x-on:click="open = true; reasons = []; $wire.vote({{ $post->id }}, -1, [])" @endif
                                            @class([$icon, 'border-fail/50 bg-fail/10 text-fail' => $disliked])>{!! $thumbDown !!}</button>
                                        <button type="button" data-open-in-studio="{{ $post->id }}" aria-label="Open ad {{ $i + 1 }} in the studio" title="Open in studio"
                                            wire:click="$dispatch('open-studio', { post: {{ $post->id }} })" class="{{ $icon }}">
                                            <svg class="size-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
                                        </button>
                                    </div>
                                    <div x-show="open" x-cloak role="group" aria-label="Why not? (optional)" class="flex flex-col gap-2 rounded-xl border border-fail/40 bg-fail/5 p-2.5">
                                        <span class="text-xs text-ink-soft">Disliked. Why not? <span class="text-ink-muted">(optional)</span></span>
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach ($reasons as $reason)
                                                <button type="button" data-reason="{{ $post->id }}:{{ $reason }}"
                                                    x-on:click="reasons = reasons.includes(@js($reason)) ? reasons.filter(r => r !== @js($reason)) : [...reasons, @js($reason)]; $wire.vote({{ $post->id }}, -1, reasons)"
                                                    x-bind:aria-pressed="reasons.includes(@js($reason))"
                                                    class="min-h-8 rounded-full border border-rim bg-surface px-3 text-sm text-ink-soft aria-pressed:border-fail aria-pressed:bg-fail aria-pressed:text-ground {{ $focus }}">{{ $reason }}</button>
                                            @endforeach
                                        </div>
                                        <button type="button" x-on:click="open = false" class="self-end rounded-md px-2 py-1 text-sm text-ink-muted hover:bg-raised {{ $focus }}">Done</button>
                                    </div>
                                    @if ($disliked)
                                        <p x-show="! open" class="flex flex-wrap items-center gap-1.5 text-xs text-fail">
                                            Disliked{{ ($vote->reasons ?? []) !== [] ? ': '.implode(', ', $vote->reasons) : '' }}
                                            <button type="button" x-on:click="open = true" class="min-h-6 text-ink-muted underline {{ $focus }}">edit reasons</button>
                                        </p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        @if ($count > 1)
                            <span class="text-xs text-ink-faint md:hidden">Swipe for the other ads →</span>
                        @endif
                    @endif
                </section>
            @endforeach
        </div>
    @endif

    <x-board.confirm-modal show="rejecting" confirm="Reject all and redraft" action="confirmReject()" target="rejectAll" id="reject-all" data-reject-confirm>
        <x-slot:title><span x-text="rejecting ? 'Reject all ' + rejecting.count + ' ads for ' + rejecting.day + '?' : ''"></span></x-slot:title>
        <span x-text="rejecting ? 'Each gets a dislike, and Claude drafts three new ads for this date only. ' + rejecting.name + '.' : ''"></span>
        <span class="mt-4 block text-xs font-medium tracking-wide text-ink-muted uppercase">Reason for all <span class="normal-case">(optional)</span></span>
        <span class="mt-2 flex flex-wrap gap-1.5" role="group" aria-label="Reason for all">
            @foreach ($reasons as $reason)
                <button type="button" data-reject-reason="{{ $reason }}" x-on:click="why = why === @js($reason) ? null : @js($reason)" x-bind:aria-pressed="why === @js($reason)"
                    class="min-h-8 rounded-full border border-rim bg-surface px-3 text-sm text-ink-soft aria-pressed:border-fail aria-pressed:bg-fail aria-pressed:text-ground {{ $focus }}">{{ $reason }}</button>
            @endforeach
        </span>
    </x-board.confirm-modal>

    <livewire:marketing.post-studio :project="$model->name" wire:key="post-studio" />
</main>

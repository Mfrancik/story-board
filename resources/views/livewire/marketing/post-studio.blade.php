{{--
    MK-9 Post studio (docs/mockups/MK-prototype/index.html, the studio): a large modal over the Marketing pages; from
    768 px a framed dialog with the big preview on the left and the controls on the right (stacked below 1024 px), and
    below 768 px a full-screen sheet. The header (campaign, date and time, state, "1 of N to approve") and the footer
    (the state's actions, Approve) stay put while the body scrolls, so Approve is always on screen.

    Alpine holds the pure-UI state and never asks the server: `cur` (which draft is shown: ← / →, the dots, the arrow
    keys), `compare`, the favourites grid, the reschedule and mark-posted forms, and `unapproving` for
    board/confirm-modal. Everything that reads or writes data is a Livewire call. MK-18 adds "♡ Save to Inspiration"
    (with Undo) beside each post's votes, and an "Inspired by" row of the inspiration its draft was written from.
    MK-5: a post with a rendered ad shows that image instead of the HTML preview, with a 1:1 / 4:5 switch (Alpine
    `ratio`) and Download of both PNGs; while it renders the preview stays (marketing/post-preview is the fallback)
    with "Rendering…" and the studio polls; a failed render says "Couldn’t render · Retry".
--}}
@php
    $focus = 'outline-needs focus-visible:outline-2 focus-visible:outline-offset-2';
    $button = 'inline-flex min-h-10 items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium whitespace-nowrap disabled:cursor-not-allowed disabled:opacity-50 '.$focus;
    $primary = $button.' bg-needs text-needs-ink hover:brightness-110';
    $secondary = $button.' border border-rim hover:bg-raised';
    $ghost = $button.' text-ink-soft hover:bg-raised';
    $small = 'inline-flex min-h-9 items-center justify-center gap-2 rounded-lg border border-rim px-3 text-sm font-medium hover:bg-raised disabled:opacity-50 '.$focus;
    $chip = 'inline-flex min-h-9 items-center rounded-full border px-3 text-xs font-medium '.$focus;
    $input = 'w-full rounded-lg border border-rim bg-ground px-3 py-2 text-sm '.$focus;
    $spin = '<span class="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden="true"></span>';
    $title = $campaign?->name ?? 'One-off post';
    $pill = [
        'review' => ['Needs approval', 'border-needs/50 bg-needs/10 text-needs'],
        'approved' => ['Approved', 'border-live/50 text-live'],
        'missed' => ['Missed', 'border-fail/60 bg-fail/10 text-fail'],
        'posted' => ['Posted', 'border-go/50 text-go'],
        'skipped' => ['Skipped', 'border-rim text-ink-faint'],
        'open' => ['Open', 'border-dashed border-needs/60 text-needs'],
        'one-off' => ['New', 'border-rim text-ink-muted'],
    ][$state] ?? [$state, 'border-rim text-ink-muted'];
    $lintWords = [
        'too_long' => 'More than 2 lines',
        'filler' => 'Filler phrase',
        'banned_claim' => 'Banned claim',
        'no_price_source' => 'A comparison with no price source',
    ];
    $when = $at ? $at->format('D j M').' · '.$at->format('H:i') : 'Not scheduled';
    $reasons = \App\Actions\Marketing\RecordFeedback::REASONS;
@endphp
<div data-studio data-studio-state="{{ $state }}" data-draft-count="{{ $shown->count() }}">
<div wire:key="studio-{{ $campaign?->id }}-{{ $this->date }}-{{ $this->postId }}"
    x-data="{
        cur: 0,
        n: {{ $shown->count() }},
        compare: false,
        ratio: 'square',
        favs: false,
        rs: false,
        mp: false,
        unapproving: null,
        go(step) { if (this.n > 1) { this.cur = (this.cur + step + this.n) % this.n; this.compare = false; } },
        typing(e) { return e.target.closest('input, textarea, select, [contenteditable]') !== null; },
    }"
    x-on:keydown.escape.window="if (unapproving === null) $wire.close()"
    x-on:keydown.arrow-right.window="typing($event) || go(1)"
    x-on:keydown.arrow-left.window="typing($event) || go(-1)"
    class="fixed inset-0 z-50 md:p-6 lg:p-8">
    <div data-studio-backdrop x-on:click="$wire.close()" class="absolute inset-0 bg-ground/70 backdrop-blur-xs" aria-hidden="true"></div>

    <div role="dialog" aria-modal="true" aria-labelledby="studio-title" x-trap.noscroll="true"
        class="relative mx-auto flex h-full w-full max-w-7xl min-w-0 flex-col overflow-hidden bg-surface shadow-2xl md:rounded-2xl md:ring-1 md:ring-edge">

        {{-- Header --}}
        <header class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-hairline py-2.5 pr-2 pl-4 md:pl-5">
            <div class="flex min-w-0 flex-col">
                <h2 id="studio-title" class="truncate text-base font-semibold">{{ $title }}</h2>
                <span data-studio-when class="font-mono text-xs text-ink-muted">{{ $when }}</span>
            </div>
            <span class="inline-flex min-h-6 items-center rounded-full border px-2 text-xs font-medium whitespace-nowrap {{ $pill[1] }}">{{ $pill[0] }}</span>
            @if ($position !== null && $state === 'review')
                <span data-studio-counter class="text-xs text-ink-muted">{{ $position }} of {{ $queueCount }} to approve</span>
            @endif
            <span class="ml-auto flex items-center gap-2">
                @if ($campaign)
                    <a href="{{ route('projects.marketing.campaign', [$model->name, $campaign->slug]) }}" wire:navigate class="hidden text-sm text-ink-muted hover:text-ink hover:underline md:inline {{ $focus }}">Campaign ›</a>
                @endif
                <button type="button" wire:click="close" data-studio-close aria-label="Close" class="inline-flex size-10 items-center justify-center rounded-lg text-ink-soft hover:bg-raised {{ $focus }}">✕</button>
            </span>
        </header>

        {{-- Body --}}
        <div class="min-h-0 flex-1 overflow-y-auto">
            @if ($error)
                <p data-studio-error class="p-6 text-sm text-fail">This campaign's dates can't be worked out: {{ $error }} Fix its days, time or timezone on the campaign page.</p>
            @elseif ($state === 'one-off')
                <form wire:submit="createOneOff" novalidate data-one-off class="mx-auto flex max-w-xl flex-col gap-4 p-4 md:p-8">
                    <p class="text-sm text-ink-muted">A post on a date you choose, in no campaign: a show this weekend, a one-time offer. It starts as a draft and is approved here like any other.</p>
                    <div>
                        <label for="oo-at" class="block text-sm font-medium">Post at</label>
                        <input id="oo-at" type="datetime-local" wire:model="oneOffAt" min="{{ $minAt }}" class="mt-1 {{ $input }} scheme-dark" aria-describedby="oo-at-err">
                        <flux:error name="oneOffAt" id="oo-at-err" class="mt-1.5" />
                    </div>
                    <div>
                        <label for="oo-caption" class="block text-sm font-medium">Caption</label>
                        <textarea id="oo-caption" wire:model="oneOffCaption" rows="4" placeholder="We are at the Baltimore show this weekend. Table 412, come say hi." class="mt-1 {{ $input }}" aria-describedby="oo-caption-err"></textarea>
                        <flux:error name="oneOffCaption" id="oo-caption-err" class="mt-1.5" />
                    </div>
                </form>
            @elseif ($state === 'open' || $state === 'skipped')
                <div class="flex flex-col items-center gap-4 p-4 md:p-10">
                    <div class="flex aspect-square w-full max-w-md flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-needs/40 p-8 text-center">
                        <b class="text-lg font-semibold text-ink">{{ $state === 'open' ? ($drafting === 'drafting' ? 'Writing 3 drafts…' : 'No drafts yet') : 'Skipped' }}</b>
                        <p class="text-sm text-ink-muted">
                            @if ($state === 'open')
                                {{ $title }} on {{ $at?->format('D j M') }} is open. Fill writes 3 drafts from the campaign's topics and brief; you flip through them here and approve one.
                            @else
                                Nothing posts on {{ $at?->format('D j M') }}.
                            @endif
                        </p>
                        @if ($drafting === 'failed')
                            <p class="text-sm text-fail">Drafting failed. Try again.</p>
                        @endif
                    </div>
                </div>
                @if ($drafting === 'drafting')
                    <div wire:poll.2s data-polling class="sr-only">Drafting…</div>
                @endif
            @else
                @if ($shown->contains(fn ($p) => $p->renderState() === \App\Models\MarketingPost::RENDER_RENDERING))
                    <div wire:poll.2s data-polling class="sr-only">Rendering…</div>
                @endif
                @foreach ($shown as $i => $post)
                    @php
                        $needs = $post->needsPhoto();
                        $render = $post->renderState();
                        $squareId = $post->renderId('square');
                        $portraitId = $post->renderId('portrait');
                        // A post waiting for its photo shows "Needs a photo", whatever was rendered before.
                        $showRender = ! $needs && $squareId !== null && $portraitId !== null;
                        $previous = $post->versions->last();
                        $vote = $post->feedback->first();
                        $editable = in_array($state, ['review', 'approved', 'missed'], true);
                        $spec = $post->adSpec();
                        // One string, not inline @if: Livewire wraps every @if in morph markers, which would split the line.
                        $specLine = $spec === null ? null : 'Layout: '.(\App\Models\MarketingPost::AD_LAYOUT_LABELS[$spec['layout']] ?? $spec['layout'])
                            .(is_string($spec['cta']) && $spec['cta'] !== '' ? ' · CTA: '.$spec['cta'] : '');
                    @endphp
                    <div data-draft="{{ $post->id }}" x-show="cur === {{ $i }}" @if ($i > 0) x-cloak @endif wire:key="draft-{{ $post->id }}"
                        class="grid min-w-0 grid-cols-1 lg:min-h-full lg:grid-cols-12">
                        {{-- Left: the big preview, flip arrows, dots, version and taste. --}}
                        <div class="flex min-w-0 flex-col items-center gap-3 bg-ground p-3 md:p-5 lg:col-span-7">
                            <div class="relative flex w-full justify-center">
                                @if ($shown->count() > 1)
                                    <button type="button" x-on:click="go(-1)" aria-label="Previous draft" class="absolute top-1/2 left-2 z-10 inline-flex size-11 -translate-y-1/2 items-center justify-center rounded-full border border-rim bg-surface/90 text-lg hover:bg-raised {{ $focus }}">←</button>
                                @endif
                                @if ($showRender)
                                    <figure data-ad-render="{{ $post->id }}" class="relative m-0 flex w-full max-w-2xl min-w-0 justify-center">
                                        <img x-show="ratio === 'square'" src="{{ route('marketing.asset', [$model->name, $squareId]) }}" width="1080" height="1080"
                                            alt="The ad for this post, square (1:1)" class="block h-auto w-full rounded-lg shadow-2xl">
                                        <img x-show="ratio === 'portrait'" x-cloak src="{{ route('marketing.asset', [$model->name, $portraitId]) }}" width="1080" height="1350"
                                            alt="The ad for this post, portrait (4:5)" class="block h-auto w-full max-w-xl rounded-lg shadow-2xl">
                                        @if ($render === \App\Models\MarketingPost::RENDER_RENDERING)
                                            <span data-rendering="{{ $post->id }}" class="absolute top-2 left-2 inline-flex items-center gap-2 rounded-full bg-ground/90 px-3 py-1 text-xs text-ink">{!! $spin !!}Rendering…</span>
                                        @endif
                                    </figure>
                                @else
                                    <x-marketing.post-preview :post="$post" :brand="$brand" :project="$model->name" :label="$campaign?->name" class="max-w-2xl shadow-2xl">
                                        <span class="flex flex-wrap justify-center gap-2">
                                            <label class="{{ $primary }} relative cursor-pointer overflow-hidden">Upload a photo
                                                <input type="file" wire:model="uploads.{{ $post->id }}" accept="image/jpeg,image/png,image/webp" class="absolute inset-0 cursor-pointer opacity-0" aria-label="Upload a photo for this post">
                                            </label>
                                            <button type="button" x-on:click="favs = true; $nextTick(() => $refs.favs{{ $post->id }}?.scrollIntoView({ block: 'nearest' }))" class="{{ $secondary }} bg-surface text-ink">Use a favourite</button>
                                        </span>
                                    </x-marketing.post-preview>
                                @endif
                                @if ($shown->count() > 1)
                                    <button type="button" x-on:click="go(1)" aria-label="Next draft" class="absolute top-1/2 right-2 z-10 inline-flex size-11 -translate-y-1/2 items-center justify-center rounded-full border border-rim bg-surface/90 text-lg hover:bg-raised {{ $focus }}">→</button>
                                @endif
                            </div>

                            @unless ($needs)
                                <div data-render-bar="{{ $post->id }}" class="flex flex-wrap items-center justify-center gap-2 text-sm">
                                    @if ($showRender)
                                        <div role="group" aria-label="Ad shape" class="inline-flex rounded-lg border border-rim p-0.5">
                                            <button type="button" x-on:click="ratio = 'square'" x-bind:aria-pressed="ratio === 'square'" data-ratio="square"
                                                class="min-h-8 rounded-md px-3 text-xs font-medium {{ $focus }}" x-bind:class="ratio === 'square' ? 'bg-raised text-ink' : 'text-ink-muted'">1:1</button>
                                            <button type="button" x-on:click="ratio = 'portrait'" x-bind:aria-pressed="ratio === 'portrait'" data-ratio="portrait"
                                                class="min-h-8 rounded-md px-3 text-xs font-medium {{ $focus }}" x-bind:class="ratio === 'portrait' ? 'bg-raised text-ink' : 'text-ink-muted'">4:5</button>
                                        </div>
                                        <a href="{{ route('marketing.render', [$model->name, $post->id, 'square']) }}" download data-download-ad="square" class="{{ $small }}">Download 1:1</a>
                                        <a href="{{ route('marketing.render', [$model->name, $post->id, 'portrait']) }}" download data-download-ad="portrait" class="{{ $small }}">Download 4:5</a>
                                    @elseif ($render === \App\Models\MarketingPost::RENDER_RENDERING)
                                        <span data-rendering="{{ $post->id }}" class="inline-flex items-center gap-2 text-ink-muted">{!! $spin !!}Rendering the ad…</span>
                                    @endif
                                    @if ($render === \App\Models\MarketingPost::RENDER_FAILED)
                                        <span data-render-failed="{{ $post->id }}" class="inline-flex flex-wrap items-center gap-x-1.5 text-fail">Couldn’t render ·
                                            <button type="button" wire:click="retryRender({{ $post->id }})" wire:loading.attr="disabled" wire:target="retryRender" data-retry-render="{{ $post->id }}" class="rounded px-1.5 py-1 text-ink underline hover:bg-raised {{ $focus }}">Retry</button>
                                        </span>
                                    @elseif ($render === \App\Models\MarketingPost::RENDER_NONE && in_array($state, ['review', 'approved', 'missed'], true))
                                        <span class="inline-flex flex-wrap items-center gap-x-1.5 text-ink-muted">No ad image yet ·
                                            <button type="button" wire:click="retryRender({{ $post->id }})" wire:loading.attr="disabled" wire:target="retryRender" data-render-now="{{ $post->id }}" class="rounded px-1.5 py-1 text-ink underline hover:bg-raised {{ $focus }}">Render it</button>
                                        </span>
                                    @endif
                                </div>
                            @endunless
                            {{-- MK-19: the art director's choice, one quiet line; shown before the render too (a draft waiting for its photo). --}}
                            @if ($specLine !== null)
                                <p data-ad-spec="{{ $post->id }}" class="text-center text-xs text-ink-muted">{{ $specLine }}</p>
                            @endif

                            @if ($shown->count() > 1)
                                <div class="flex justify-center gap-1" role="group" aria-label="Drafts">
                                    @foreach ($shown as $j => $dot)
                                        <button type="button" x-on:click="cur = {{ $j }}; compare = false" aria-label="Draft {{ $j + 1 }}" x-bind:aria-current="cur === {{ $j }}"
                                            class="inline-flex size-7 items-center justify-center rounded-full {{ $focus }}">
                                            <span class="size-3 rounded-full border" x-bind:class="cur === {{ $j }} ? 'border-needs bg-needs' : 'border-rim'"></span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif

                            <div class="flex flex-wrap items-center justify-center gap-x-3 gap-y-2 text-sm text-ink-soft">
                                @if ($state === 'review')
                                    <span>Draft {{ $i + 1 }} of {{ $shown->count() }}@if ($post->generation_meta['topic'] ?? null) · {{ $post->generation_meta['topic'] }}@endif</span>
                                @endif
                                @if ($needs)
                                    <x-marketing.alert-badge tone="photo" data-needs-photo="{{ $post->id }}">Needs a photo</x-marketing.alert-badge>
                                @endif
                                @if ($post->version > 1 && $previous)
                                    <span data-version="{{ $post->id }}" class="inline-flex items-center gap-1 rounded-full border border-pick/60 py-0.5 pr-1 pl-2.5 font-mono text-xs text-ink">
                                        v{{ $post->version }} ·
                                        @if ($state === 'review')
                                            <button type="button" wire:click="undo({{ $post->id }})" wire:loading.attr="disabled" wire:target="undo" class="rounded px-1.5 py-1 hover:bg-raised {{ $focus }}">Undo</button> ·
                                        @endif
                                        <button type="button" x-on:click="compare = ! compare" x-bind:aria-pressed="compare" class="rounded px-1.5 py-1 hover:bg-raised {{ $focus }}" x-text="compare ? 'Hide compare' : 'Compare'">Compare</button>
                                    </span>
                                @endif
                                @if ($state === 'review')
                                    <span class="inline-flex gap-1">
                                        <button type="button" wire:click="vote({{ $post->id }}, {{ $vote?->vote === 1 ? 0 : 1 }})" aria-label="Like this draft" aria-pressed="{{ $vote?->vote === 1 ? 'true' : 'false' }}" data-like="{{ $post->id }}"
                                            @class(['inline-flex size-9 items-center justify-center rounded-lg border', $focus, 'border-go/60 bg-go/15 text-go' => $vote?->vote === 1, 'border-rim text-ink-muted hover:text-ink' => $vote?->vote !== 1])>
                                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M7 10v11H3V10zm0 0 4-8a3 3 0 0 1 3 3v4h5.5a2 2 0 0 1 2 2.3l-1.3 8A2 2 0 0 1 18.2 21H7"/></svg>
                                        </button>
                                        <button type="button" wire:click="vote({{ $post->id }}, {{ $vote?->vote === -1 ? 0 : -1 }})" aria-label="Dislike this draft" aria-pressed="{{ $vote?->vote === -1 ? 'true' : 'false' }}" data-dislike="{{ $post->id }}"
                                            @class(['inline-flex size-9 items-center justify-center rounded-lg border', $focus, 'border-fail/60 bg-fail/15 text-fail' => $vote?->vote === -1, 'border-rim text-ink-muted hover:text-ink' => $vote?->vote !== -1])>
                                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M17 14V3h4v11zm0 0-4 8a3 3 0 0 1-3-3v-4H4.5a2 2 0 0 1-2-2.3l1.3-8A2 2 0 0 1 5.8 3H17"/></svg>
                                        </button>
                                    </span>
                                @endif
                                {{-- MK-18: one click keeps a post the owner likes as a favourite inspiration; Undo takes it back. --}}
                                @if ($saved->contains($post->id))
                                    <span data-saved-inspiration="{{ $post->id }}" class="inline-flex items-center gap-1 rounded-full border border-needs/50 py-0.5 pr-1 pl-2.5 text-xs text-needs">
                                        ♥ Saved to Inspiration ·
                                        <button type="button" wire:click="undoInspiration({{ $post->id }})" wire:loading.attr="disabled" wire:target="undoInspiration" data-undo-inspiration="{{ $post->id }}" class="rounded px-1.5 py-1 text-ink hover:bg-raised {{ $focus }}">Undo</button>
                                    </span>
                                @else
                                    <button type="button" wire:click="saveToInspiration({{ $post->id }})" wire:loading.attr="disabled" wire:target="saveToInspiration" data-save-inspiration="{{ $post->id }}"
                                        class="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-rim px-3 text-xs font-medium text-ink-soft hover:bg-raised hover:text-ink {{ $focus }}">♡ Save to Inspiration</button>
                                @endif
                            </div>

                            @php $examples = collect((array) ($post->generation_meta['inspiration_ids'] ?? []))->map(fn ($id) => $inspired->get($id))->filter(); @endphp
                            @if ($examples->isNotEmpty())
                                {{-- MK-18: the inspiration this draft's prompt quoted as examples of tone and copy. --}}
                                <div data-inspired-by="{{ $post->id }}" class="flex flex-wrap items-center justify-center gap-1.5 text-xs tracking-wider text-ink-muted uppercase">
                                    <span>Inspired by</span>
                                    @foreach ($examples as $example)
                                        @php $exampleTitle = trim((string) ($example->ai_notes['headline'] ?? '')) ?: 'Inspiration image'; @endphp
                                        <a href="{{ route('projects.marketing.inspiration', $model->name) }}" wire:navigate data-inspired-asset="{{ $example->id }}" title="{{ $exampleTitle }}" class="rounded {{ $focus }}">
                                            @if ($example->isTextCard())
                                                <span class="flex size-8 items-center justify-center rounded border border-rim bg-raised text-xs normal-case text-ink-soft" aria-label="{{ $exampleTitle }}">Aa</span>
                                            @else
                                                <img src="{{ route('marketing.asset', [$model->name, $example->id]) }}" alt="{{ $exampleTitle }}" class="block size-8 rounded border border-rim object-cover">
                                            @endif
                                        </a>
                                    @endforeach
                                </div>
                            @endif

                            @if ($state === 'review' && $vote?->vote === -1)
                                <div data-reasons="{{ $post->id }}" class="flex flex-wrap items-center justify-center gap-1.5">
                                    <span class="text-xs text-ink-muted">Why? (optional)</span>
                                    @foreach ($reasons as $reason)
                                        @php $on = in_array($reason, $vote->reasons ?? [], true); @endphp
                                        <button type="button" wire:click="vote({{ $post->id }}, -1, {{ json_encode($on ? array_values(array_diff($vote->reasons ?? [], [$reason])) : [...($vote->reasons ?? []), $reason]) }})"
                                            aria-pressed="{{ $on ? 'true' : 'false' }}" data-reason="{{ $reason }}"
                                            @class([$chip, 'border-fail/60 bg-fail/15 text-fail' => $on, 'border-dashed border-fail/40 text-ink-soft' => ! $on])>{{ Str::ucfirst($reason) }}</button>
                                    @endforeach
                                </div>
                            @endif

                            @if ($previous)
                                <div x-show="compare" x-cloak data-compare="{{ $post->id }}" class="grid w-full max-w-2xl grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div class="flex min-w-0 flex-col gap-1.5 rounded-xl border border-edge bg-surface p-3">
                                        <span class="text-xs font-medium tracking-wider text-ink-muted uppercase">Before · v{{ $previous->version }}</span>
                                        <p class="text-sm whitespace-pre-line text-ink-soft">{{ $previous->caption }}</p>
                                    </div>
                                    <div class="flex min-w-0 flex-col gap-1.5 rounded-xl border border-pick/60 bg-surface p-3">
                                        <span class="text-xs font-medium tracking-wider text-ink-muted uppercase">Now · v{{ $post->version }}</span>
                                        <p class="text-sm whitespace-pre-line text-ink">{{ $post->caption }}</p>
                                    </div>
                                </div>
                            @endif
                        </div>

                        {{-- Right: what should change, the caption, the photo; the approved post's forms. --}}
                        <div class="flex min-w-0 flex-col gap-5 border-t border-hairline p-4 md:p-5 lg:col-span-5 lg:border-t-0 lg:border-l">
                            @if ($state === 'missed')
                                <p class="rounded-lg border border-fail/50 bg-fail/10 px-3 py-2 text-sm text-ink">This did not go out at its time. It never goes out late on its own: post it now, or reschedule it.</p>
                            @endif
                            @if ($state === 'posted')
                                <section class="flex flex-col gap-1 text-sm">
                                    <span class="text-xs font-medium tracking-wider text-ink-muted uppercase">Posted</span>
                                    <span class="text-ink-soft">{{ $post->posted_at?->setTimezone($campaign?->timezone ?? config('board.timezone'))->format('D j M Y · H:i') }}. Engagement arrives with phase 2.</span>
                                    @if ($link = $post->networks->firstWhere('network', \App\Models\MarketingPostNetwork::FACEBOOK)?->url)
                                        <a href="{{ $link }}" target="_blank" rel="noopener" class="break-all text-live hover:underline {{ $focus }}">{{ $link }}</a>
                                    @endif
                                </section>
                            @endif

                            @if (in_array($state, ['approved', 'missed'], true))
                                <section x-show="mp" x-cloak data-mark-posted-form class="flex flex-col gap-3 rounded-xl border border-go/40 p-4">
                                    <strong class="text-base">{{ $state === 'missed' ? 'Post it now, by hand' : 'Mark as posted' }}</strong>
                                    @if ($state === 'missed')
                                        <ol class="flex list-decimal flex-col gap-1 pl-5 text-sm text-ink-soft">
                                            <li><button type="button" class="text-live hover:underline {{ $focus }}" x-on:click="navigator.clipboard.writeText(@js($post->caption)).then(() => $flux.toast({ text: 'Caption copied' }), () => $flux.toast({ text: 'Could not copy. Select the caption and copy it.', variant: 'warning' }))">Copy the caption</button></li>
                                            @if ($post->renderId('square') !== null)
                                                <li>Download the ad: <a href="{{ route('marketing.render', [$model->name, $post->id, 'square']) }}" download class="text-live hover:underline {{ $focus }}">1:1</a> · <a href="{{ route('marketing.render', [$model->name, $post->id, 'portrait']) }}" download class="text-live hover:underline {{ $focus }}">4:5</a></li>
                                            @elseif ($post->asset)
                                                <li><a href="{{ route('marketing.asset', [$model->name, $post->asset_id]) }}" download="{{ $post->asset->original_name }}" class="text-live hover:underline {{ $focus }}">Download the image</a></li>
                                            @endif
                                            <li>Post it on Facebook, then paste its link here.</li>
                                        </ol>
                                    @else
                                        <span class="text-sm text-ink-muted">You posted it on Facebook by hand. Paste the post's link so its engagement can be read later.</span>
                                    @endif
                                    <div>
                                        <label for="link-{{ $post->id }}" class="block text-sm font-medium">Facebook post link <span class="font-normal text-ink-faint">optional</span></label>
                                        <input id="link-{{ $post->id }}" x-ref="link{{ $post->id }}" type="url" inputmode="url" placeholder="https://www.facebook.com/…/posts/…" class="mt-1 {{ $input }}" aria-describedby="link-err-{{ $post->id }}">
                                        <flux:error name="postLink" id="link-err-{{ $post->id }}" class="mt-1.5" />
                                    </div>
                                    <div class="flex gap-2">
                                        <button type="button" data-mark-posted-save x-on:click="$wire.markPosted({{ $post->id }}, $refs.link{{ $post->id }}.value)" wire:loading.attr="disabled" wire:target="markPosted" class="{{ $primary }}">Mark posted</button>
                                        <button type="button" x-on:click="mp = false" class="{{ $ghost }}">Cancel</button>
                                    </div>
                                </section>

                                <section x-show="rs" x-cloak data-reschedule-form class="flex flex-col gap-3 rounded-xl border border-edge p-4">
                                    <div>
                                        <label for="rs-{{ $post->id }}" class="block text-sm font-medium">Post at</label>
                                        <input id="rs-{{ $post->id }}" x-ref="rs{{ $post->id }}" type="datetime-local" min="{{ $minAt }}" value="{{ $at?->format('Y-m-d\TH:i') }}" class="mt-1 {{ $input }} scheme-dark" aria-describedby="rs-err-{{ $post->id }}">
                                        <flux:error name="rescheduleAt" id="rs-err-{{ $post->id }}" class="mt-1.5" />
                                    </div>
                                    <div class="flex gap-2">
                                        <button type="button" data-reschedule-save x-on:click="$wire.reschedule({{ $post->id }}, $refs.rs{{ $post->id }}.value)" wire:loading.attr="disabled" wire:target="reschedule" class="{{ $primary }}">Save</button>
                                        <button type="button" x-on:click="rs = false" class="{{ $ghost }}">Cancel</button>
                                    </div>
                                </section>
                            @endif

                            @if ($state === 'review')
                                <section x-data="{ fb: '', chips: [] }" data-ask="{{ $post->id }}" class="flex flex-col gap-2.5 rounded-xl border border-pick/60 bg-pick/5 p-4">
                                    <label for="fb-{{ $post->id }}" class="text-base font-semibold">What should change?</label>
                                    <textarea id="fb-{{ $post->id }}" x-model="fb" rows="3" placeholder="Shorter. Mention it's album toning. Less salesy." class="{{ $input }}" aria-describedby="fb-err-{{ $post->id }}"></textarea>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach (\App\Actions\Marketing\ReviseDraft::CHIPS as $c)
                                            <button type="button" x-on:click="chips.includes(@js($c)) ? chips.splice(chips.indexOf(@js($c)), 1) : chips.push(@js($c))" x-bind:aria-pressed="chips.includes(@js($c))"
                                                x-bind:class="chips.includes(@js($c)) ? 'border-pick bg-pick/25 text-ink' : 'border-rim text-ink-soft'" class="{{ $chip }}">{{ $c }}</button>
                                        @endforeach
                                    </div>
                                    <flux:error name="feedback" id="fb-err-{{ $post->id }}" />
                                    @if (($unchanged['post_id'] ?? null) === $post->id)
                                        {{-- A rebuild that changed nothing: a warning, not an error. The note stays in the box to edit. --}}
                                        <p data-revise-unchanged="{{ $post->id }}" role="status" class="flex items-baseline gap-2 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-ink">
                                            <span class="inline-flex size-4 shrink-0 items-center justify-center rounded-full bg-warning/25 text-xs font-bold text-warning" aria-hidden="true">!</span>
                                            <span>{{ $unchanged['message'] }}@if ($unchanged['reason'] === \App\Actions\Marketing\ExplainUnchangedRevision::NO_FAVOURITES) <a href="{{ route('projects.marketing.settings', $model->name) }}" wire:navigate class="text-live hover:underline {{ $focus }}">Open Settings</a>@endif</span>
                                        </p>
                                    @endif
                                    <div class="flex flex-wrap items-center gap-3">
                                        <button type="button" data-rebuild="{{ $post->id }}" x-on:click="$wire.revise({{ $post->id }}, fb, chips).then((ok) => { if (ok) { fb = ''; chips = [] } })"
                                            wire:loading.attr="disabled" wire:target="revise" @disabled(! $aiReady) @if (! $aiReady) title="Add ANTHROPIC_API_KEY to .env" @endif class="{{ $primary }} min-h-9 px-3">
                                            <span wire:loading.remove wire:target="revise">Rebuild</span>
                                            <span wire:loading wire:target="revise" class="inline-flex items-center gap-2">{!! $spin !!}Rebuilding…</span>
                                        </button>
                                        <span class="text-xs text-ink-muted">The AI rewrites this post. Your note also teaches this campaign's taste.</span>
                                    </div>
                                </section>
                            @endif

                            <section class="flex flex-col gap-2">
                                <label for="cap-{{ $post->id }}" class="text-xs font-medium tracking-wider text-ink-muted uppercase">Caption</label>
                                @if ($editable)
                                    <textarea id="cap-{{ $post->id }}" wire:model.blur="captions.{{ $post->id }}" rows="4" data-caption="{{ $post->id }}" class="{{ $input }}" aria-describedby="cap-err-{{ $post->id }}"></textarea>
                                    <flux:error name="captions.{{ $post->id }}" id="cap-err-{{ $post->id }}" />
                                @else
                                    <p class="text-sm whitespace-pre-line text-ink">{{ $post->caption }}</p>
                                @endif
                                @if (is_array($post->lint))
                                    <ul data-lint="{{ $post->id }}" class="flex flex-col gap-1 text-sm">
                                        @forelse ($post->lint as $warning)
                                            <li class="flex items-baseline gap-2 text-ink"><span class="inline-flex size-4 shrink-0 items-center justify-center rounded-full bg-needs/20 text-xs font-bold text-needs" aria-hidden="true">!</span>{{ $lintWords[$warning['code']] ?? $warning['code'] }} <span class="text-xs text-ink-muted">“{{ $warning['match'] }}”</span></li>
                                        @empty
                                            <li class="flex items-baseline gap-2 text-ink-muted"><span class="inline-flex size-4 shrink-0 items-center justify-center rounded-full bg-go/20 text-xs font-bold text-go" aria-hidden="true">✓</span>Copy rules pass.</li>
                                        @endforelse
                                        @if ($post->lint !== [])
                                            <li class="text-xs text-ink-muted">Warnings only. They never block you.</li>
                                        @endif
                                    </ul>
                                @endif
                            </section>

                            @if ($editable)
                                <section class="flex flex-col gap-2">
                                    <span class="text-xs font-medium tracking-wider text-ink-muted uppercase">Photo</span>
                                    <div class="flex flex-wrap gap-2">
                                        <label class="{{ $small }} relative cursor-pointer overflow-hidden">
                                            <span wire:loading.remove wire:target="uploads.{{ $post->id }}">Upload a photo for this post</span>
                                            <span wire:loading wire:target="uploads.{{ $post->id }}" class="inline-flex items-center gap-2">{!! $spin !!}Uploading…</span>
                                            <input type="file" wire:model="uploads.{{ $post->id }}" accept="image/jpeg,image/png,image/webp" data-upload="{{ $post->id }}" class="absolute inset-0 cursor-pointer opacity-0" aria-label="Upload a photo for this post">
                                        </label>
                                        <button type="button" x-on:click="favs = ! favs" x-bind:aria-pressed="favs" data-use-favourite="{{ $post->id }}" class="{{ $small }}">Use a favourite</button>
                                        <button type="button" wire:click="textOnly({{ $post->id }})" aria-pressed="{{ $post->is_text_only ? 'true' : 'false' }}" data-text-only="{{ $post->id }}" @class([$small, 'border-needs text-needs' => $post->is_text_only])>Text only</button>
                                    </div>
                                    <flux:error name="uploads.{{ $post->id }}" />
                                    <div x-show="favs" x-cloak x-ref="favs{{ $post->id }}" class="flex flex-wrap gap-2">
                                        @forelse ($favourites as $fav)
                                            <button type="button" wire:click="useFavourite({{ $post->id }}, {{ $fav->id }})" x-on:click="favs = false" data-favourite="{{ $fav->id }}" title="{{ $fav->original_name }}" aria-pressed="{{ $post->asset_id === $fav->id ? 'true' : 'false' }}"
                                                @class(['size-16 overflow-hidden rounded-lg border-2 bg-ink', $focus, 'border-needs' => $post->asset_id === $fav->id, 'border-transparent' => $post->asset_id !== $fav->id])>
                                                <img src="{{ route('marketing.asset', [$model->name, $fav->id]) }}" alt="{{ $fav->original_name }}" class="size-full object-cover">
                                            </button>
                                        @empty
                                            <p class="text-sm text-ink-muted">No favourites yet. Add some in <a href="{{ route('projects.marketing.settings', $model->name) }}" wire:navigate class="text-live hover:underline">Settings</a>.</p>
                                        @endforelse
                                    </div>
                                </section>
                            @endif
                        </div>
                    </div>
                @endforeach
            @endif
        </div>

        {{-- Footer: the state's actions. Per draft where they act on one, so they follow `cur`. --}}
        @unless ($error)
            <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-hairline bg-ground/40 px-3 py-2.5 md:px-5">
                @if ($state === 'one-off')
                    <button type="button" wire:click="createOneOff" wire:loading.attr="disabled" wire:target="createOneOff" data-create-one-off class="{{ $primary }} max-md:w-full">Create post</button>
                @elseif ($state === 'open')
                    <button type="button" wire:click="skip" wire:loading.attr="disabled" data-skip class="{{ $ghost }} max-md:flex-1">Skip this date</button>
                    @if ($campaign?->is_enabled)
                        <button type="button" wire:click="fillDate" wire:loading.attr="disabled" wire:target="fillDate" data-studio-fill @disabled(! $aiReady || $drafting === 'drafting')
                            @if (! $aiReady) title="Add ANTHROPIC_API_KEY to .env" @endif class="{{ $primary }} max-md:flex-1">
                            @if ($drafting === 'drafting') {!! $spin !!}Drafting… @else Fill this date @endif
                        </button>
                    @endif
                @elseif ($state === 'skipped')
                    <button type="button" wire:click="restore" wire:loading.attr="disabled" data-restore class="{{ $secondary }} max-md:w-full">Restore this date</button>
                @else
                    @foreach ($shown as $i => $post)
                        @php $needs = $post->needsPhoto(); @endphp
                        <div x-show="cur === {{ $i }}" @if ($i > 0) x-cloak @endif data-footer="{{ $post->id }}" class="flex w-full min-w-0 flex-wrap items-center justify-end gap-2">
                            @if ($state === 'review')
                                <span class="mr-auto hidden text-sm text-ink-muted md:inline">
                                    {{ $needs ? 'Add a photo before approving.' : (count($post->lint ?? []) > 0 ? count($post->lint).' copy '.Str::plural('warning', count($post->lint)).'. You decide.' : 'Nothing posts until you approve.') }}
                                </span>
                                @if ($campaign)
                                    {{-- A one-off has no campaign date to skip; skip() would 404 without one. --}}
                                    <button type="button" wire:click="skip" wire:loading.attr="disabled" data-skip class="{{ $ghost }} max-md:flex-1">Skip date</button>
                                    <button type="button" wire:click="regenerate" wire:loading.attr="disabled" wire:target="regenerate" data-regenerate @disabled(! $aiReady) class="{{ $ghost }} max-md:flex-1">
                                        <span wire:loading.remove wire:target="regenerate">Regenerate all 3</span>
                                        <span wire:loading wire:target="regenerate" class="inline-flex items-center gap-2">{!! $spin !!}Drafting…</span>
                                    </button>
                                @endif
                                @if ($needs)
                                    <span data-approve-held class="w-full text-center text-xs text-ink-muted md:hidden">Needs a photo before it can be approved.</span>
                                @endif
                                <button type="button" data-approve="{{ $post->id }}" @disabled($needs) wire:click="approve({{ $post->id }})" wire:loading.attr="disabled" wire:target="approve"
                                    @if ($needs) title="Needs a photo" @endif class="{{ $others > 0 ? $secondary : $primary }} max-md:flex-1">Approve for {{ $at?->format('D j M H:i') }}</button>
                                @if ($others > 0)
                                    <button type="button" data-approve-next="{{ $post->id }}" @disabled($needs) wire:click="approve({{ $post->id }}, true)" wire:loading.attr="disabled" wire:target="approve"
                                        @if ($needs) title="Needs a photo" @endif class="{{ $primary }} max-md:w-full">Approve &amp; next ({{ $others }} left)</button>
                                @endif
                            @else
                                <button type="button" data-copy-caption x-on:click="navigator.clipboard.writeText(@js($post->caption)).then(() => $flux.toast({ text: 'Caption copied' }), () => $flux.toast({ text: 'Could not copy. Select the caption and copy it.', variant: 'warning' }))" class="{{ $secondary }} max-md:flex-1">Copy caption</button>
                                {{-- With a rendered ad, its Download 1:1 / 4:5 sit under the preview; the bare photo only without one. --}}
                                @if ($post->asset && $post->renderId('square') === null)
                                    <a href="{{ route('marketing.asset', [$model->name, $post->asset_id]) }}" download="{{ $post->asset->original_name }}" data-download class="{{ $secondary }} max-md:flex-1">Download image</a>
                                @endif
                                @if (in_array($state, ['approved', 'missed'], true))
                                    <button type="button" data-reschedule x-on:click="rs = ! rs; mp = false" class="{{ $secondary }} max-md:flex-1">Reschedule</button>
                                @endif
                                @if ($state === 'approved')
                                    <button type="button" data-unapprove x-on:click="unapproving = {{ $post->id }}" class="{{ $button }} border border-fail/50 text-fail hover:bg-fail/10 max-md:flex-1">Unapprove</button>
                                    <button type="button" data-mark-posted x-on:click="mp = ! mp; rs = false" class="{{ $primary }} max-md:w-full">Mark as posted</button>
                                @elseif ($state === 'missed')
                                    <button type="button" data-post-now x-on:click="mp = ! mp; rs = false" class="{{ $primary }} max-md:w-full">Post it now</button>
                                @endif
                            @endif
                        </div>
                    @endforeach
                @endif
            </footer>
        @endunless
    </div>

    <x-board.confirm-modal show="unapproving" confirm="Unapprove" action="$wire.unapprove(unapproving); unapproving = null" target="unapprove" id="unapprove-modal">
        <x-slot:title>Unapprove this post?</x-slot:title>
        It goes back to needing your approval and will not post until you approve it again.
    </x-board.confirm-modal>
</div>
</div>

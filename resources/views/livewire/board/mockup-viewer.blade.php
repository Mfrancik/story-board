{{--
    SB-21 full-screen mockup viewer, option A's lightbox (docs/mockups/SB-21/option-a.html, "Viewer"):
    a small title bar (ID, title, pick state, previous/next set), the description and Where, then tabs
    Current / A / B / C, Side by side and the width switch over one stage. Side by side splits the
    stage into two panes, each with its own picker, stacked below 768px with a swap.

    All of that is Alpine (mockupViewer in resources/js): switching an option only changes a frame's
    src, never loads the page. The compare panes are `x-if`, so their frames exist only while open.
    "Current" is SB-23's pane (board/mockup-current): today's page from a journey shot, or why there is
    none. With a shot the viewer opens in compare, Current left and the first option right (option a, or
    the picked one). The one server call is the pick.
    RD-4: in the RD-2 dark shell (layouts/shell), restyled with its tokens; layout and behaviour unchanged.
--}}
@php
    $frame = fn (string $o) => route('mockups.frame', ['project' => $set['project'], 'story' => $set['story'], 'file' => "option-{$o}.html"]);
    $urls = collect($set['options'])->mapWithKeys(fn ($o) => [$o => $frame($o)])->all();
    $show = fn (string $story) => route('mockups.show', ['project' => $set['project'], 'story' => $story]);
    $gallery = route('mockups', ['project' => $set['project']]);
    $awaiting = $set['state'] === \App\Actions\Board\ReadMockupSets::AWAITING;
    $picked = $set['state'] === \App\Actions\Board\ReadMockupSets::PICKED ? $set['picked'] : null;
    $btn = 'inline-flex shrink-0 items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-medium border-rim bg-surface hover:bg-raised';
    $primary = 'inline-flex shrink-0 items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium disabled:opacity-60 bg-needs text-needs-ink hover:brightness-110';
    $seg = 'shrink-0 rounded-lg border p-0.5 border-edge bg-surface';
    $segBtn = 'rounded-md px-2.5 py-1 text-xs font-medium whitespace-nowrap';
    $on = 'bg-ink text-ground';
    $off = 'text-ink-muted hover:text-ink';
    $widths = [1280 => 'Desktop', 768 => 'Tablet', 375 => 'Phone'];
    // Current vs an option by default when a shot exists (SB-23); otherwise option vs option, as SB-21 opened.
    $hasShot = $current['state'] === \App\Actions\Board\ReadCurrentVersion::SHOT;
    $left = $hasShot ? 'current' : $first;
    $right = $hasShot ? $first : (collect($set['options'])->first(fn ($o) => $o !== $first) ?? 'current');
    $currentLabel = match ($current['state']) {
        \App\Actions\Board\ReadCurrentVersion::SHOT => "Current · today's page",
        \App\Actions\Board\ReadCurrentVersion::NEW_PAGE => 'Current · none (new page)',
        default => 'Current · no journey shot',
    };
    $icon = fn (string $d, string $size = 'size-4') => '<svg class="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">'.$d.'</svg>';
@endphp
<div data-viewer="{{ $set['project'] }}/{{ $set['story'] }}" role="dialog" aria-modal="true" aria-labelledby="viewer-title"
    data-compare-open="{{ $hasShot ? 'true' : 'false' }}" data-default-left="{{ $left }}" data-default-right="{{ $right }}"
    x-data="mockupViewer(@js(['options' => $set['options'], 'first' => $first, 'compare' => $hasShot, 'left' => $left, 'right' => $right, 'urls' => $urls, 'gallery' => $gallery, 'prev' => $show($prev), 'next' => $show($next)]))"
    x-trap.noscroll="true"
    x-on:keydown.escape.window="escape()"
    x-on:keydown.arrow-right.window="step($event, 'next')"
    x-on:keydown.arrow-left.window="step($event, 'prev')"
    class="fixed inset-0 z-60 flex flex-col bg-ground">

    <header class="border-b border-edge bg-surface">
        <div class="flex h-11 items-center gap-2 px-2 sm:px-3">
            <a href="{{ $gallery }}" wire:navigate data-viewer-close aria-label="Back to the gallery (Esc)" class="rounded-md p-1.5 hover:bg-raised">{!! $icon('<path d="M6 6l12 12M18 6L6 18"/>') !!}</a>
            <span class="shrink-0 font-mono text-xs whitespace-nowrap text-ink-muted"><span class="hidden sm:inline">{{ $set['project'] }} · </span>{{ $set['story'] }}</span>
            <h1 id="viewer-title" class="min-w-0 truncate text-sm font-semibold">{{ $set['title'] ?? $set['story'] }}</h1>
            <x-board.mockup-state :set="$set" />
            <div class="ml-auto flex shrink-0 items-center gap-1 text-xs text-ink-muted">
                <a href="{{ $show($prev) }}" wire:navigate aria-label="Previous set" class="rounded-md p-1.5 hover:bg-raised">{!! $icon('<path d="M15 6l-6 6 6 6"/>') !!}</a>
                <span class="tabular-nums">{{ $position }} / {{ $count }}</span>
                <a href="{{ $show($next) }}" wire:navigate aria-label="Next set" class="rounded-md p-1.5 hover:bg-raised">{!! $icon('<path d="M9 6l6 6-6 6"/>') !!}</a>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-0.5 px-3 pb-2 text-xs">
            <p data-viewer-description class="min-w-0 text-ink-muted">{{ $description ?? 'No description: the set has no index.html and the story no Story line.' }}</p>
            <p data-viewer-where class="min-w-0 truncate text-ink-muted">Where:
                @if ($set['where'])<code class="font-mono text-ink-soft">{{ $set['where'] }}</code>@else not named in the story @endif
            </p>
        </div>
        <div class="flex items-center gap-2 overflow-x-auto border-t px-3 py-2 border-edge">
            <div role="tablist" aria-label="Version" class="{{ $seg }} inline-flex" x-show="! compare">
                <button type="button" role="tab" data-option-tab="current" x-on:click="opt = 'current'" x-bind:aria-selected="(opt === 'current').toString()"
                    class="{{ $segBtn }}" x-bind:class="opt === 'current' ? @js($on) : @js($off)">Current</button>
                @foreach ($set['options'] as $o)
                    <button type="button" role="tab" data-option-tab="{{ $o }}" data-src="{{ $urls[$o] }}" x-on:click="opt = @js($o)" x-bind:aria-selected="(opt === @js($o)).toString()"
                        class="{{ $segBtn }}" x-bind:class="opt === @js($o) ? @js($on) : @js($off)">{{ strtoupper($o) }}@if ($o === $picked) ✓@endif</button>
                @endforeach
            </div>
            <span x-show="compare" x-cloak class="shrink-0 text-xs text-ink-muted">Pick a version in each pane ·</span>
            <button type="button" data-compare-swap x-show="compare" x-cloak x-on:click="swap()" aria-label="Swap left and right" class="{{ $btn }}">⇄ Swap</button>
            <button type="button" data-compare-toggle x-on:click="compare = ! compare" x-bind:aria-pressed="compare.toString()"
                class="{{ $btn }}" x-bind:class="compare && 'ring-2 ring-accent'">{!! $icon('<rect x="3" y="4" width="8" height="16" rx="1.5"/><rect x="13" y="4" width="8" height="16" rx="1.5"/>', 'size-3.5') !!} Side by side</button>
            <div role="group" aria-label="Page width" class="{{ $seg }} hidden sm:inline-flex">
                @foreach ($widths as $w => $label)
                    <button type="button" data-width="{{ $w }}" x-on:click="width = {{ $w }}" x-bind:aria-pressed="(width === {{ $w }}).toString()"
                        class="{{ $segBtn }}" x-bind:class="width === {{ $w }} ? @js($on) : @js($off)">{{ $label }} <span class="tabular-nums opacity-60">{{ $w }}</span></button>
                @endforeach
            </div>
            <a x-show="! compare && opt !== 'current'" x-bind:href="url(opt)" href="{{ $urls[$first] }}" target="_blank" rel="noopener" class="{{ $btn }} ml-auto">Open file ↗</a>
        </div>
    </header>

    <div class="min-h-0 flex-1 p-2 sm:p-3">
        {{-- One version at a time. --}}
        <div x-show="! compare" class="relative h-full overflow-hidden rounded-lg border bg-surface"
            x-bind:class="opt === @js($picked) ? 'border-built' : 'border-edge'">
            <div x-show="opt === 'current'" x-cloak class="absolute inset-0"><x-board.mockup-current :current="$current" :project="$set['project']" /></div>
            <div x-show="opt !== 'current'" class="absolute inset-0 overflow-hidden" x-data="mockupFit(() => width)">
                <iframe data-viewer-frame src="{{ $urls[$first] }}" x-bind:src="url(opt)" sandbox="allow-scripts" title="{{ $set['story'] }} mockup"
                    class="absolute top-0 left-0 origin-top-left border-0 bg-white" x-bind:style="frameStyle"></iframe>
            </div>
        </div>

        {{-- Side by side: x-if, so the two compare frames (two git reads) exist only while it is open. --}}
        <template x-if="compare">
            <div class="grid h-full min-h-0 grid-rows-2 gap-3 md:grid-cols-2 md:grid-rows-1">
                @foreach (['left' => 'Left', 'right' => 'Right'] as $side => $label)
                    <section data-compare-pane="{{ $side }}" class="flex min-h-0 min-w-0 flex-col overflow-hidden rounded-lg border bg-surface"
                        x-bind:class="{{ $side }} === @js($picked) ? 'border-built' : 'border-edge'">
                        <div class="flex items-center justify-between gap-2 border-b px-2 py-1.5 border-edge">
                            <label class="flex min-w-0 items-center gap-2 text-xs">
                                <span class="shrink-0 font-medium text-ink-muted">{{ $label }}</span>
                                <select x-model="{{ $side }}" class="min-w-0 truncate rounded-md border py-1 pr-7 pl-2 text-xs border-rim bg-surface">
                                    <option value="current">{{ $currentLabel }}</option>
                                    @foreach ($set['options'] as $o)
                                        <option value="{{ $o }}">Option {{ strtoupper($o) }}@if ($o === $picked) ✓ picked @endif</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                        <div class="relative min-h-0 flex-1">
                            <div x-show="{{ $side }} === 'current'" class="absolute inset-0"><x-board.mockup-current :current="$current" :project="$set['project']" /></div>
                            <div x-show="{{ $side }} !== 'current'" class="absolute inset-0 overflow-hidden" x-data="mockupFit(() => width)">
                                <iframe x-bind:src="url({{ $side }})" sandbox="allow-scripts" x-bind:title="@js($set['story']) + ' ' + label({{ $side }})"
                                    class="absolute top-0 left-0 origin-top-left border-0 bg-white" x-bind:style="frameStyle"></iframe>
                            </div>
                        </div>
                    </section>
                @endforeach
            </div>
        </template>
    </div>

    <footer class="flex flex-wrap items-center gap-2 border-t px-3 py-2 text-xs border-edge bg-surface">
        @if ($awaiting)
            <span class="font-medium text-ink">Not picked yet.</span>
            <span class="text-ink-muted">Pick one:</span>
            @foreach ($set['options'] as $o)
                <button type="button" data-pick="{{ $o }}" x-on:click="openPick(@js($o))" class="{{ $btn }}">Pick {{ strtoupper($o) }}</button>
            @endforeach
        @elseif ($picked)
            <span class="min-w-0 flex-1 truncate text-ink-muted">
                <strong class="font-medium text-ink">Picked {{ strtoupper($picked) }}</strong> · {{ $set['reason'] ?? 'No reason recorded.' }}
                @unless ($set['pushed']) · committed in the checkout, not pushed yet @endunless
            </span>
        @else
            <span class="min-w-0 flex-1 truncate text-ink-muted">
                {{ $set['recorded'] ? 'Chosen, as written in the story: '.$set['recorded'] : 'No pick recorded, and this story does not take one from the board.' }}
            </span>
        @endif
    </footer>

    @if ($awaiting)
        {{-- Pick dialog: open/closed is Alpine; confirming is the one server call, re-validated there. --}}
        <div x-show="picking" x-cloak x-on:click.self="picking = null" class="fixed inset-0 z-70 grid place-items-center bg-ground/70 p-4">
            <form x-trap="picking" x-on:submit.prevent="confirmPick()" role="dialog" aria-modal="true" aria-labelledby="pick-title" data-pick-dialog
                class="w-full max-w-md rounded-2xl border p-5 shadow-xl border-edge bg-surface">
                <h2 id="pick-title" class="font-semibold">Pick option <span x-text="picking && picking.toUpperCase()"></span> for {{ $set['story'] }}?</h2>
                <p class="mt-1 text-sm text-ink-muted">The pick and your reason go into the story's mockup gate, committed on their own in the project's checkout. Nothing is pushed.</p>
                <label for="pick-reason" class="mt-4 block text-sm font-medium">Why this one <span class="font-normal text-ink-muted">(one line, optional)</span></label>
                <textarea id="pick-reason" x-ref="reason" x-model="reason" rows="2" maxlength="{{ \App\Services\StoryPickWriter::REASON_MAX }}"
                    x-on:keydown.enter.prevent="confirmPick()" placeholder="e.g. the split compare is what I asked for"
                    class="mt-1 w-full rounded-lg border p-2 text-sm border-rim bg-ground"></textarea>
                @if ($refusal)
                    <p role="alert" data-pick-refusal class="mt-2 rounded-md border border-danger/40 bg-danger/10 px-3 py-2 text-sm text-danger">{{ $refusal }}</p>
                @endif
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" x-on:click="picking = null" class="{{ $btn }}">Cancel</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="pick" class="{{ $primary }}">
                        <span wire:loading.remove wire:target="pick">Pick option <span x-text="picking && picking.toUpperCase()"></span></span>
                        <span wire:loading wire:target="pick">Picking…</span>
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>

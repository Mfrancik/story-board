{{--
    MK-10 Calendar (docs/mockups/MK-10/option-a.html, the prototype's Calendar route) in the RD-2 shell: the project
    header with Marketing current and the marketing sub-nav; a toolbar (‹ Today ›, the range, Week / Month); the gap
    banner; the key; then from 1024 px the grid beside the campaigns tray (one column below).

    From 768 px the grid is drawn (month: Monday-first weeks; week: 7 columns); below it, the two-week agenda list
    instead. Both are rendered and CSS picks one, so a resize needs no request. A chip's click opens the quick-peek, an
    Alpine panel over the chip's own data; its "Open in studio" sends `open-studio` to the post studio on this page.
--}}
@php
    $focus = 'outline-needs focus-visible:outline-2 focus-visible:outline-offset-2';
    $button = 'inline-flex min-h-9 items-center justify-center rounded-lg border border-rim px-3 text-sm font-medium hover:bg-raised disabled:opacity-60 '.$focus;
    $card = 'flex min-w-0 flex-col gap-3 rounded-xl border border-edge bg-surface p-4';
    $inGap = fn (string $date) => $gap !== null && $date >= $gap['from']->toDateString() && $date <= $gap['to']->toDateString();
    $gapRange = null;
    if ($gap !== null) {
        $gapRange = $gap['from']->format('M') === $gap['to']->format('M')
            ? $gap['from']->format('D j').' – '.$gap['to']->format('D j M')
            : $gap['from']->format('D j M').' – '.$gap['to']->format('D j M');
    }
    $peekTone = "{ 'border-live/50 text-live': peek.tone === 'approved', 'border-go/50 text-go': peek.tone === 'posted', 'border-fail/60 text-fail': peek.tone === 'missed', 'border-needs/50 text-needs': peek.tone === 'review' || peek.tone === 'open' }";
@endphp
<main data-marketing-calendar x-data="{ peek: null }" x-on:keydown.escape.window="peek = null"
    class="mx-auto flex max-w-7xl min-w-0 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-10 lg:py-10">
    <div class="flex flex-col gap-4">
        <x-board.project-header :model="$model" page="Marketing" current="marketing" />
        <x-marketing.sub-nav :project="$model->name" current="calendar" />
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <div class="hidden items-center gap-1 md:flex" role="group" aria-label="Range">
            <button type="button" wire:click="previous" data-calendar-previous aria-label="Previous {{ $this->mode }}" class="{{ $button }} w-9 px-0">‹</button>
            <button type="button" wire:click="today" data-calendar-today class="{{ $button }}">Today</button>
            <button type="button" wire:click="next" data-calendar-next aria-label="Next {{ $this->mode }}" class="{{ $button }} w-9 px-0">›</button>
        </div>
        <h2 data-calendar-range class="text-xl font-semibold tracking-tight">
            <span class="hidden md:inline">{{ $range }}</span><span class="md:hidden">Next two weeks</span>
        </h2>
        <span wire:loading.delay class="text-xs text-ink-muted">Loading…</span>
        <div class="ml-auto hidden items-center gap-1 rounded-lg border border-rim p-0.5 md:flex" role="group" aria-label="View">
            @foreach (['week' => 'Week', 'month' => 'Month'] as $view => $label)
                <button type="button" wire:click="setView('{{ $view }}')" data-view="{{ $view }}" aria-pressed="{{ $this->mode === $view ? 'true' : 'false' }}"
                    @class(['inline-flex min-h-8 items-center rounded-md px-3 text-sm '.$focus, 'bg-raised font-medium text-ink' => $this->mode === $view, 'text-ink-muted hover:text-ink' => $this->mode !== $view])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    @if ($gap !== null)
        <div data-gap-banner role="alert" class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-fail/40 bg-fail/10 px-3.5 py-3 text-sm text-ink">
            <span class="size-2 shrink-0 rounded-full bg-fail" aria-hidden="true"></span>
            <span class="min-w-0 flex-1"><b class="font-semibold text-fail">Nothing approved {{ $gapRange }}{{ $gap['open'] ? ' and beyond' : '' }}.</b> Open a campaign to fill its dates, or approve its drafts.</span>
            <a href="{{ route('projects.marketing', $model->name) }}" wire:navigate class="{{ $button }}">Campaigns ›</a>
        </div>
    @endif

    <p class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-muted">
        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-live" aria-hidden="true"></span>approved</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-needs" aria-hidden="true"></span>drafts to approve</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full border border-dashed border-needs" aria-hidden="true"></span>open date</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-go" aria-hidden="true"></span>posted</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-fail" aria-hidden="true"></span>missed</span>
        <span class="hidden md:inline">Click a chip for a quick peek.</span>
    </p>

    <div class="grid min-w-0 grid-cols-1 items-start gap-5 lg:grid-cols-4">
        <section aria-label="Calendar" class="min-w-0 lg:col-span-3">
            {{-- From 768 px: the month grid or the week's columns. --}}
            <div data-calendar-grid="{{ $this->mode }}" class="hidden md:block">
                @if ($this->mode === 'month')
                    <div class="grid grid-cols-7 overflow-hidden rounded-xl border border-edge">
                        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                            <div class="border-b border-edge bg-surface px-2 py-1.5 text-xs font-medium tracking-wider text-ink-faint uppercase">{{ $weekday }}</div>
                        @endforeach
                        @foreach ($grid as $date => $chips)
                            @php $day = \Carbon\CarbonImmutable::parse($date); @endphp
                            <div data-calendar-day="{{ $date }}" @if ($inGap($date)) data-gap-day @endif
                                @class([
                                    'flex min-h-28 min-w-0 flex-col gap-1 border-r border-b border-hairline p-1.5 [&:nth-child(7n)]:border-r-0',
                                    'bg-surface' => $day->month === $month,
                                    'bg-ground opacity-60' => $day->month !== $month,
                                    'bg-fail/5' => $inGap($date),
                                ])>
                                <span @class(['flex items-center gap-1.5 px-0.5 font-mono text-xs', 'text-ink-muted' => $date !== $today, 'font-semibold text-needs' => $date === $today])>
                                    {{ $day->day }}
                                    @if ($gap !== null && $date === $gap['from']->toDateString())
                                        <span class="font-sans text-xs font-semibold tracking-wide text-fail uppercase">gap</span>
                                    @endif
                                </span>
                                @foreach ($chips as $chip)
                                    {{-- A month cell is narrow: the state wraps under the name rather than being cut off. --}}
                                    <x-marketing.calendar-chip :chip="$chip" :project="$model->name" class="flex-wrap" wire:key="m-{{ $chip['key'] }}-{{ $date }}" />
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="grid grid-cols-7 gap-2">
                        @foreach ($grid as $date => $chips)
                            @php $day = \Carbon\CarbonImmutable::parse($date); @endphp
                            <div data-calendar-day="{{ $date }}" @if ($inGap($date)) data-gap-day @endif
                                @class(['flex min-h-96 min-w-0 flex-col gap-1.5 rounded-xl border border-edge p-2', 'bg-surface' => ! $inGap($date), 'bg-fail/5' => $inGap($date)])>
                                <h3 @class(['flex items-baseline gap-1.5 text-sm font-semibold', 'text-needs' => $date === $today])>
                                    {{ $day->format('D') }} <span class="font-mono text-xs font-normal text-ink-muted">{{ $day->format('j M') }}</span>
                                </h3>
                                @foreach ($chips as $chip)
                                    <x-marketing.calendar-chip :chip="$chip" :project="$model->name" wire:key="w-{{ $chip['key'] }}-{{ $date }}" />
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Below 768 px: the next two weeks as a list. --}}
            <ol data-calendar-agenda role="list" class="flex flex-col gap-3 md:hidden">
                @foreach ($agenda as $date => $chips)
                    @php $day = \Carbon\CarbonImmutable::parse($date); @endphp
                    @if ($gap !== null && $date === $gap['from']->toDateString())
                        <li data-agenda-gap class="rounded-lg border border-dashed border-fail/60 px-3 py-2 text-sm text-fail">Nothing approved {{ $gapRange }}{{ $gap['open'] ? ' and beyond' : '' }}</li>
                    @endif
                    <li data-agenda-day="{{ $date }}" class="flex flex-col gap-1.5">
                        <h3 class="flex items-center gap-2 text-sm font-semibold">
                            {{ $day->format('D j M') }}
                            @if ($date === $today)
                                <span class="rounded-full border border-needs/50 px-2 text-xs font-normal text-needs">today</span>
                            @endif
                        </h3>
                        @forelse ($chips as $chip)
                            <x-marketing.calendar-chip :chip="$chip" :project="$model->name" class="min-h-10 text-sm" wire:key="a-{{ $chip['key'] }}-{{ $date }}" />
                        @empty
                            <p class="text-sm text-ink-faint">Nothing planned</p>
                        @endforelse
                    </li>
                @endforeach
            </ol>
        </section>

        <aside data-calendar-tray aria-labelledby="tray-h" class="{{ $card }} lg:sticky lg:top-4">
            <h2 id="tray-h" class="flex items-center text-xs font-medium tracking-wider text-ink-faint uppercase">
                Campaigns
                <a href="{{ route('projects.marketing', $model->name) }}" wire:navigate class="ml-auto text-xs font-normal tracking-normal text-ink-muted normal-case hover:text-ink hover:underline {{ $focus }}">All campaigns ›</a>
            </h2>
            @forelse ($campaigns as $row)
                @php $c = $row['campaign']; @endphp
                <div data-tray-campaign="{{ $c->slug }}" @class(['flex flex-col gap-1 border-t border-hairline pt-2.5', 'opacity-70' => ! $c->is_enabled])>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('projects.marketing.campaign', [$model->name, $c->slug]) }}" wire:navigate class="min-w-0 flex-1 truncate text-sm font-medium hover:underline {{ $focus }}">{{ $c->name }}</a>
                        <x-board.switch :on="$c->is_enabled" :label="$c->name.' on'" target="toggleCampaign" wire:click="toggleCampaign({{ $c->id }})" />
                    </div>
                    <span class="font-mono text-xs text-ink-muted">{{ $c->scheduleLabel() }}</span>
                    @if ($row['error'] !== null)
                        <span data-tray-error class="text-xs text-fail">This campaign's dates can't be worked out: {{ $row['error'] }}</span>
                    @else
                        <span @class(['text-xs', 'text-go' => $row['runway']['approved_through'], 'text-needs' => ! $row['runway']['approved_through']])>
                            {{ $row['runway']['approved_through'] ? 'Approved through '.$row['runway']['approved_through']->format('D j M') : 'Nothing approved yet' }}
                        </span>
                    @endif
                    <a href="{{ route('projects.marketing.campaign', [$model->name, $c->slug]) }}" wire:navigate data-tray-open class="self-start text-xs text-needs hover:underline {{ $focus }}">Open campaign ›</a>
                </div>
            @empty
                <p class="text-sm text-ink-muted">No campaigns yet. <a href="{{ route('projects.marketing.campaign.create', $model->name) }}" wire:navigate class="text-needs hover:underline {{ $focus }}">+ New campaign</a></p>
            @endforelse
        </aside>
    </div>

    {{-- The quick-peek: one Alpine panel, filled by the clicked chip. --}}
    <div x-cloak x-show="peek" x-transition.opacity data-peek role="dialog" aria-modal="false" aria-labelledby="peek-h"
        class="fixed inset-x-4 bottom-4 z-30 flex flex-col gap-3 rounded-xl border border-rim bg-surface p-4 shadow-2xl sm:inset-x-auto sm:right-6 sm:w-96">
        <template x-if="peek">
            <div class="flex flex-col gap-3">
                <div class="flex items-start gap-2">
                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <h2 id="peek-h" class="truncate text-base font-semibold" x-text="peek.campaign"></h2>
                        <span data-peek-when class="font-mono text-xs text-ink-muted" x-text="peek.when"></span>
                    </div>
                    <button type="button" x-on:click="peek = null" aria-label="Close" class="inline-flex size-9 items-center justify-center rounded-lg text-ink-muted hover:bg-raised hover:text-ink {{ $focus }}">✕</button>
                </div>
                <span data-peek-state class="self-start rounded-full border px-2 py-0.5 text-xs" x-bind:class="{{ $peekTone }}" x-text="peek.state"></span>
                <img data-peek-image x-show="peek.image" x-bind:src="peek.image" alt="The ad for this post" width="1080" height="1080" class="block aspect-square h-auto w-full rounded-lg">
                <p class="text-sm text-ink-soft" x-show="peek.caption" x-text="peek.caption"></p>
                <div class="flex flex-wrap gap-2">
                    <a data-peek-campaign x-show="peek.campaignUrl" x-bind:href="peek.campaignUrl" wire:navigate class="{{ $button }}">Open campaign ›</a>
                    <button type="button" data-peek-studio x-on:click="$wire.$dispatch('open-studio', peek.studio); peek = null"
                        class="inline-flex min-h-9 items-center justify-center rounded-lg bg-needs px-3 text-sm font-medium text-needs-ink hover:brightness-110 {{ $focus }}">Open in studio</button>
                </div>
            </div>
        </template>
    </div>

    {{-- MK-9: the post studio, opened from the quick-peek. An explicit key (see RUNBOOK: the studio closing). --}}
    <livewire:marketing.post-studio :project="$model->name" wire:key="post-studio" />
</main>

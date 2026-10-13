{{-- One date on a campaign page's Future posts timeline (MK-7, the prototype's `.tl-row`): the date and time, a dot in
     the state's colour, then what is there — the approved or posted post's preview and caption, the drafts' count and
     thumbnails, an open slot, a skipped date, or a missed post. Dates that need the owner (drafts, open, missed) get
     the amber edge (red for missed). `occurrence` is one ListCampaignOccurrences row; `project` the project's name,
     for photo URLs. A date the schedule no longer lands on (the campaign moved days) is tagged "Off schedule".

     Fill (MK-8): an open row has a Fill button (wire:click `fillDate(date)` on the campaign page), "Drafting…" while its
     job runs (`fillState` drafting) and "Drafting failed · Retry" when it failed. `canFill` false (a paused campaign)
     hides the button; `aiReady` false (no API key) disables it.

     MK-9: the whole row opens the date in the post studio (`campaignId` given): a full-row button under the content
     dispatches `open-studio`; the Fill button sits above it, so it still works on its own. --}}
@props(['occurrence', 'project', 'campaignId' => null, 'photoMode' => null, 'fillState' => null, 'canFill' => false, 'aiReady' => false])
@php
    $o = $occurrence;
    $state = $o['state'];
    $shown = $o['posts']->firstWhere('status', \App\Models\MarketingPost::STATUS_APPROVED)
        ?? $o['posts']->first(fn ($p) => $p->isPosted())
        ?? $o['posts']->first();
    $drafts = $o['posts']->where('status', \App\Models\MarketingPost::STATUS_DRAFT);
    $dot = match ($state) {
        'approved', 'posted' => 'bg-live border-live',
        'review' => 'bg-needs border-needs',
        'missed' => 'bg-fail border-fail',
        'skipped' => 'border-rim',
        default => 'border-needs/60',
    };
    $pill = [
        'approved' => ['Approved', 'border-live/50 text-live'],
        'posted' => ['Posted', 'border-go/50 text-go'],
        'skipped' => ['Skipped', 'border-rim text-ink-faint'],
        'missed' => ['Missed', 'border-fail/60 bg-fail/10 text-fail'],
        'review' => ['Needs your approval · '.$drafts->count().' '.Str::plural('draft', $drafts->count()), 'border-needs/50 bg-needs/10 text-needs'],
        'open' => ['Open', 'border-dashed border-needs/60 text-needs'],
    ][$state] ?? [$state, 'border-rim text-ink-muted'];
    $thumb = 'size-14 shrink-0 overflow-hidden rounded-md';
@endphp
<li data-occurrence="{{ $o['date'] }}" data-occurrence-state="{{ $state }}"
    {{ $attributes->class([
        'relative grid grid-cols-1 gap-2 border-t border-hairline px-2 py-3.5 first:border-t-0 sm:grid-cols-12 sm:gap-3',
        'rounded-lg hover:bg-raised/60' => $campaignId !== null,
        'border-l-4 border-l-needs bg-needs/5' => $o['flagged'] && $state !== 'missed',
        'border-l-4 border-l-fail bg-fail/5' => $state === 'missed',
    ]) }}>
    @if ($campaignId !== null)
        <button type="button" data-open-studio="{{ $o['date'] }}" wire:click="$dispatch('open-studio', { campaign: {{ $campaignId }}, date: '{{ $o['date'] }}' })"
            aria-label="Open {{ $o['at']->format('D j M') }} in the studio"
            class="absolute inset-0 rounded-lg outline-needs focus-visible:outline-2 focus-visible:-outline-offset-2"></button>
    @endif
    <div class="pointer-events-none flex items-baseline gap-2 sm:col-span-3 sm:flex-col sm:gap-0.5">
        <span class="text-sm font-semibold text-ink">{{ $o['at']->format('D j M') }}</span>
        <span class="font-mono text-xs text-ink-muted">{{ $o['at']->format('H:i') }}</span>
        @unless ($o['on_schedule'])
            <span data-off-schedule class="text-xs text-ink-faint">Off schedule</span>
        @endunless
    </div>

    <div class="pointer-events-none flex min-w-0 items-center gap-3 sm:col-span-9">
        <span class="hidden size-3 shrink-0 rounded-full border-2 sm:block {{ $dot }}" aria-hidden="true"></span>

        @if ($state === 'open' || $state === 'skipped')
            <span class="{{ $thumb }} flex items-center justify-center border border-dashed border-rim text-lg text-ink-faint" aria-hidden="true">{{ $state === 'open' ? '+' : '–' }}</span>
        @elseif ($state === 'review')
            <span class="flex shrink-0 gap-1.5">
                @foreach ($drafts->take(3) as $draft)
                    @if ($draft->asset_id)
                        <img src="{{ route('marketing.asset', [$project, $draft->asset_id]) }}" alt="Draft {{ $loop->iteration }} photo" class="size-10 rounded-md object-cover sm:size-14">
                    @else
                        <span class="flex size-10 items-center justify-center rounded-md border border-rim bg-raised text-xs text-ink-soft sm:size-14" aria-hidden="true">Aa</span>
                    @endif
                @endforeach
            </span>
        @elseif ($shown?->asset_id)
            <img src="{{ route('marketing.asset', [$project, $shown->asset_id]) }}" alt="Photo for {{ $o['at']->format('D j M') }}" class="{{ $thumb }} object-cover">
        @else
            <span class="{{ $thumb }} flex items-center justify-center border border-rim bg-raised text-xs text-ink-soft" aria-hidden="true">Aa</span>
        @endif

        <div class="flex min-w-0 flex-1 flex-col gap-1">
            <span class="flex flex-wrap gap-1.5">
                <span class="inline-flex min-h-6 items-center rounded-full border px-2 text-xs font-medium whitespace-nowrap {{ $pill[1] }}">{{ $pill[0] }}</span>
                @if ($state === 'review' && $drafts->contains(fn ($d) => $d->asset_id === null) && ($photoMode ?? null) === \App\Models\MarketingCampaign::PHOTO_PER_POST)
                    <x-marketing.alert-badge tone="photo">Needs a photo</x-marketing.alert-badge>
                @endif
            </span>
            <span class="truncate text-sm text-ink-soft">
                @switch ($state)
                    @case('open') {{ $fillState === 'drafting' ? 'Writing 3 drafts…' : 'No drafts yet' }} @break
                    @case('skipped') Skipped. Nothing posts. @break
                    @case('review') {{ Str::before((string) $drafts->first()?->caption, "\n") }} @break
                    @default {{ Str::before((string) $shown?->caption, "\n") }}
                @endswitch
            </span>
        </div>

        @if ($state === 'open' && $canFill)
            <div class="pointer-events-auto relative flex shrink-0 items-center gap-2">
                @if ($fillState === 'failed')
                    <span data-fill-failed="{{ $o['date'] }}" class="text-xs whitespace-nowrap text-fail">Drafting failed ·</span>
                @endif
                <button type="button" wire:click="fillDate('{{ $o['date'] }}')" wire:loading.attr="disabled" wire:target="fillDate('{{ $o['date'] }}')" data-fill="{{ $o['date'] }}"
                    @disabled(! $aiReady || $fillState === 'drafting')
                    @if (! $aiReady) title="Add ANTHROPIC_API_KEY to .env" @endif
                    aria-label="{{ $fillState === 'failed' ? 'Retry drafting' : 'Fill' }} {{ $o['at']->format('D j M') }}"
                    class="inline-flex min-h-9 items-center justify-center gap-2 rounded-lg border border-rim px-3 text-sm font-medium whitespace-nowrap hover:bg-raised disabled:opacity-60 outline-needs focus-visible:outline-2 focus-visible:outline-offset-2">
                    @if ($fillState === 'drafting')
                        <span class="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden="true"></span>Drafting…
                    @elseif ($fillState === 'failed')
                        Retry
                    @else
                        Fill
                    @endif
                </button>
            </div>
        @endif
    </div>
</li>

{{-- One campaign row of the MK-1 Campaigns list: name and schedule, next post and its state, approved-through with the
     runway bar, and the "needs you" badges. A paused campaign shows "Paused" in place of its next post's badges; a
     campaign whose runway failed shows the reason across the row. Not a link until MK-7 adds the campaign page. --}}
@php
    $c = $row['campaign'];
    $r = $row['runway'];
@endphp
<li data-campaign-row="{{ $c->slug }}" class="grid min-h-18 grid-cols-1 gap-x-4 gap-y-3 px-4 py-3.5 sm:grid-cols-2 lg:grid-cols-12 lg:items-center">
    <div class="flex min-w-0 flex-col gap-0.5 lg:col-span-3">
        <span class="truncate text-base font-semibold text-ink">{{ $c->name }}</span>
        <span class="font-mono text-xs text-ink-muted">{{ $c->scheduleLabel() }}</span>
    </div>

    @if ($r === null)
        <p data-runway-error class="min-w-0 text-sm text-fail sm:col-span-1 lg:col-span-9">
            This campaign's dates can't be worked out: {{ $row['error'] }} Fix its timezone, days or time to see its runway.
        </p>
    @else
        <div class="flex min-w-0 flex-col gap-1 text-sm lg:col-span-2">
            <span class="{{ $key }}">Next post</span>
            @if ($c->is_enabled && $r['next'])
                <span>{{ $r['next']['at']->format('D j M') }} · {{ $r['next']['at']->format('H:i') }}</span>
                <span class="text-xs {{ $stateTone[$r['next']['state']] ?? 'text-ink-muted' }}">{{ $stateWords[$r['next']['state']] ?? $r['next']['state'] }}</span>
            @else
                <span class="text-ink-faint">—</span>
            @endif
        </div>

        <div class="flex min-w-0 flex-col gap-1.5 text-sm sm:col-span-2 lg:col-span-4">
            <span class="{{ $key }}">Runway · next 6 dates</span>
            <span data-approved-through @class(['font-medium', 'text-go' => $r['approved_through'], 'text-needs' => ! $r['approved_through']])>
                {{ $r['approved_through'] ? 'Approved through '.$r['approved_through']->format('D j M') : 'Nothing approved yet' }}
            </span>
            <x-marketing.runway-bar :runway="$r['runway']" :approved="$r['approved']" />
            @if ($c->is_enabled && $r['thin'] && $r['approved_through'])
                <x-marketing.alert-badge tone="thin">Nothing approved past this week</x-marketing.alert-badge>
            @endif
        </div>

        <div class="flex min-w-0 flex-col gap-1 text-sm sm:col-span-2 lg:col-span-3">
            <span class="{{ $key }}">Needs you</span>
            <span class="flex flex-wrap gap-1.5">
                @if (! $c->is_enabled)
                    <span class="text-ink-faint">Paused</span>
                @else
                    @if ($r['missed'])
                        <x-marketing.alert-badge tone="missed">Missed {{ $r['missed']->format('D') }}</x-marketing.alert-badge>
                    @endif
                    @if ($r['needs_photo'])
                        <x-marketing.alert-badge tone="photo">Needs a photo</x-marketing.alert-badge>
                    @endif
                    @if ($r['to_approve'] > 0)
                        <x-marketing.alert-badge tone="approve">{{ $r['to_approve'] }} to approve</x-marketing.alert-badge>
                    @endif
                    @if ($r['open_dates'] > 0)
                        <x-marketing.alert-badge tone="open">{{ $r['open_dates'] }} open {{ Str::plural('date', $r['open_dates']) }}</x-marketing.alert-badge>
                    @endif
                    @if (! $r['missed'] && ! $r['needs_photo'] && $r['to_approve'] === 0 && $r['open_dates'] === 0)
                        <span class="text-ink-faint">Nothing</span>
                    @endif
                @endif
            </span>
        </div>
    @endif
</li>

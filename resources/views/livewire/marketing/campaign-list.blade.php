{{--
    MK-1 Campaigns list, the Marketing tab's landing page (docs/mockups/MK-prototype/index.html, Campaigns route) in the
    RD-2 shell: the project header with Marketing current, the Marketing sub-nav, the page title with its counts, the
    alerts banner, one row per campaign that is on, then the paused ones in a quieter group, and the runway key.

    Below 1024 px a row stacks its fields with small labels; from 1024 px it becomes the prototype's columns. Rows and
    badges are not links yet: the campaign page is MK-7 and the post studio MK-9. The analytics strip is MK-15 and has
    no placeholder here. A row whose runway failed (CampaignList logged why) shows that error and nothing else.
--}}
@php
    $focus = 'outline-needs focus-visible:outline-2 focus-visible:outline-offset-2';
    $key = 'text-xs font-medium tracking-wider text-ink-faint uppercase lg:hidden';
    $stateWords = ['approved' => 'approved', 'posted' => 'posted', 'review' => 'drafts to approve', 'open' => 'open', 'skipped' => 'skipped'];
    $stateTone = ['approved' => 'text-live', 'posted' => 'text-live', 'review' => 'text-needs', 'open' => 'text-ink-muted', 'skipped' => 'text-ink-faint'];
    $names = fn ($rows) => $rows->map(fn ($r) => $r['campaign']->name)->join(', ', ' and ');
@endphp
<main class="mx-auto flex max-w-7xl min-w-0 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-10 lg:py-10">
    <div class="flex flex-col gap-4">
        <x-board.project-header :model="$model" page="Marketing" current="marketing" :marketing-count="$toApprove" />
        <x-marketing.sub-nav :project="$model->name" current="campaigns" />
    </div>

    @if ($on->isEmpty() && $paused->isEmpty())
        <div data-campaigns-empty class="rounded-xl border border-dashed border-rim bg-surface px-6 py-14 text-center">
            <svg class="mx-auto size-8 text-ink-faint" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
            <p class="mt-3 font-medium">No campaigns yet</p>
            <p class="mt-1 text-sm text-ink-muted">A campaign is a post on a weekly schedule, such as a feature every Tuesday at 19:00. {{ $model->name }}'s campaigns will be listed here.</p>
        </div>
    @else
        <div class="flex flex-col gap-1">
            <h2 class="text-xl font-semibold tracking-tight">Campaigns</h2>
            <p class="text-sm text-ink-muted">{{ $on->count() }} on regular posting @if ($paused->isNotEmpty()) · {{ $paused->count() }} paused @endif</p>
        </div>

        @if ($on->isNotEmpty())
            @if ($toApprove > 0 || $thin->isNotEmpty() || $missed->isNotEmpty())
                <div data-marketing-alerts role="alert" class="flex flex-wrap items-center gap-x-3.5 gap-y-2 rounded-xl border border-needs/40 bg-needs/10 px-3.5 py-3 text-sm leading-relaxed text-ink">
                    <span class="size-2 shrink-0 rounded-full bg-needs" aria-hidden="true"></span>
                    <span class="min-w-0 flex-1">
                        @php
                            $bits = [];
                            if ($toApprove > 0) {
                                $bits[] = '<b class="font-semibold text-needs">'.$toApprove.' '.($toApprove === 1 ? 'post needs' : 'posts need').' your approval</b>';
                            }
                            if ($thin->isNotEmpty()) {
                                $bits[] = e($names($thin)).' '.($thin->count() === 1 ? 'has' : 'have').' nothing approved past this week';
                            }
                            foreach ($missed as $row) {
                                $bits[] = e($row['campaign']->name).' missed '.$row['runway']['missed']->format('D j M');
                            }
                        @endphp
                        {!! implode(' · ', $bits) !!}
                    </span>
                </div>
            @else
                <div data-marketing-alerts role="status" class="flex flex-wrap items-center gap-3.5 rounded-xl border border-go/40 bg-go/10 px-3.5 py-3 text-sm text-ink">
                    <span class="size-2 shrink-0 rounded-full bg-go" aria-hidden="true"></span>
                    <span>All caught up. Every campaign is approved past this week.</span>
                </div>
            @endif

            <section data-campaign-list aria-label="Campaigns that are on" class="overflow-hidden rounded-xl border border-edge bg-surface">
                <div aria-hidden="true" class="hidden gap-4 border-b border-edge px-4 py-2.5 text-xs font-medium tracking-wider text-ink-faint uppercase lg:grid lg:grid-cols-12">
                    <span class="col-span-3">Campaign</span><span class="col-span-2">Next post</span><span class="col-span-4">Runway · next 6 dates</span><span class="col-span-3">Needs you</span>
                </div>
                <ul role="list" class="divide-y divide-hairline">
                    @foreach ($on as $row)
                        @include('livewire.marketing.partials.campaign-row', ['row' => $row])
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($paused->isNotEmpty())
            <section data-paused-campaigns aria-labelledby="paused-h" class="rounded-xl border border-dashed border-edge pt-2.5">
                <h3 id="paused-h" class="px-4 pb-1.5 text-xs font-medium tracking-wider text-ink-muted uppercase">Paused</h3>
                <ul role="list" class="divide-y divide-hairline opacity-70">
                    @foreach ($paused as $row)
                        @include('livewire.marketing.partials.campaign-row', ['row' => $row])
                    @endforeach
                </ul>
            </section>
        @endif

        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-muted">Runway: each block is one upcoming date. <x-marketing.runway-bar legend /></p>
    @endif
</main>

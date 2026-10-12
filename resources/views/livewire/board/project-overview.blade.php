{{--
    RD-11 project overview, option B "command center" (docs/mockups/RD-11/option-b.html) in the RD-2 shell: the project
    header with Refresh this project (F-16), the tabs with Overview first, then a 4 → 2 → 1 column tile grid in option B's
    order — Story progress, Needs you, Live now, Latest preflight, Production, About, Biggest initiatives. Option B's git
    tiles (commits per day, last edits, most-edited files) are RD-12. Production values come from stored snapshots only.

    Each tile's data may be null — its source threw (ProjectOverview::attempt logged it) — so that tile shows its error
    and the others still render. The page polls only while a refresh the owner asked for is on its way.
--}}
@use('App\Actions\Board\ListNeedsMe')
@use('App\Actions\Board\ReadPreflightHistory')
@use('App\Actions\Board\ReadProjectStories')
@use('App\Livewire\Board\Overview')
@use('App\Services\SessionReader')
@php
    $focus = 'outline-needs focus-visible:outline-2 focus-visible:outline-offset-2';
    $tile = 'flex min-w-0 flex-col gap-3 rounded-xl border border-edge bg-surface px-4.5 py-4';
    $heading = 'flex items-center gap-2 text-xs font-medium tracking-wider text-ink-muted uppercase';
    $headLink = "ml-auto inline-flex min-h-11 items-center rounded-md text-xs font-normal tracking-normal text-needs normal-case hover:underline {$focus}";
    $note = 'text-sm text-ink-muted';
    $panelError = 'text-sm text-fail';
    $big = 'font-mono text-3xl leading-none font-medium tracking-tight';
    $stories = route('projects.stories', $model->name);
@endphp
<main class="mx-auto flex max-w-7xl min-w-0 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-10 lg:py-10">
    @php
        $total = $progress['total'] ?? 0;
        $groups = $progress['initiative_count'] ?? 0;
    @endphp
    <div>
        <x-board.project-header :model="$model" page="Overview" current="overview">
            @if ($total > 0)
                · {{ number_format($total) }} {{ Str::plural('story', $total) }} in {{ $groups }} {{ Str::plural('initiative', $groups) }}
            @endif

            <x-slot:actions>
                <div class="flex flex-wrap items-center gap-3 text-sm text-ink-muted">
                    <span data-refreshed>{{ $model->indexed_at ? 'Refreshed '.$model->indexed_at->diffForHumans() : 'Not read yet' }}</span>
                    {{-- Disabled while queued or running, and for a project off the board (only reachable by a hand-made mount). --}}
                    <button type="button" data-refresh-project wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh"
                        @disabled($refreshing || ! $model->is_enabled)
                        class="inline-flex min-h-11 items-center gap-2 rounded-lg border border-rim bg-raised px-4 font-medium text-ink hover:border-ink-muted disabled:cursor-not-allowed disabled:opacity-60 {{ $focus }}">
                        <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/></svg>
                        @if ($refreshing)
                            <span data-refreshing role="status">Refreshing…</span>
                        @else
                            <span wire:loading.remove wire:target="refresh">Refresh this project</span>
                            <span wire:loading wire:target="refresh">Refreshing…</span>
                        @endif
                    </button>
                </div>
            </x-slot:actions>
        </x-board.project-header>
        @if ($refreshing)
            {{-- Waits for the queued refresh to land; gone (no more polls) once the snapshot has moved on. --}}
            <div wire:poll.5s data-refresh-poll class="hidden"></div>
        @endif
    </div>

    <div data-overview-grid class="grid grid-cols-1 gap-3.5 md:grid-cols-2 xl:grid-cols-4">
        {{-- Story progress, counted with the Stories page's kinds so both pages give the same numbers. --}}
        <section data-tile="progress" aria-labelledby="ov-progress" class="{{ $tile }} md:col-span-2">
            <h2 id="ov-progress" class="{{ $heading }}">Story progress · on {{ $model->ref }} <a href="{{ $stories }}" wire:navigate class="{{ $headLink }}">Stories</a></h2>
            @if ($progress === null)
                <p role="alert" class="{{ $panelError }}">Could not count the stories. The rest of the page is unaffected.</p>
            @elseif ($total === 0)
                <p class="{{ $note }}">No stories on {{ $model->ref }} yet.</p>
            @else
                @php
                    $t = $progress['tallies'];
                    // Cancelled work is not owed: a share of what is still meant to ship.
                    $owed = $total - $t['cancelled'];
                    $pct = $owed > 0 ? (int) floor($t['built'] / $owed * 100) : null;
                    $labels = ['built' => 'built', 'approved' => 'approved', 'draft' => 'draft', 'cancelled' => 'cancelled', 'other' => 'other'];
                    $keys = ['built' => 'bg-built', 'approved' => 'bg-approved', 'draft' => 'bg-draft', 'cancelled' => 'bg-cancelled', 'other' => 'bg-ink-faint'];
                @endphp
                <p class="flex flex-wrap items-baseline gap-x-2.5">
                    <span class="{{ $big }}">{{ $pct === null ? '—' : $pct.'%' }}</span>
                    <span class="{{ $note }}">{{ number_format($t['built']) }} of {{ number_format($owed) }} built</span>
                </p>
                <x-board.status-bar variant="tag" class="h-2.5" :counts="$t"
                    :label="collect($t)->filter()->map(fn ($n, $k) => number_format($n).' '.$labels[$k])->join(', ')" />
                <ul class="flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-ink-muted">
                    @foreach (ReadProjectStories::KINDS as $kind)
                        @continue($kind === 'other' && $t['other'] === 0)
                        <li data-progress-count="{{ $kind }}" class="inline-flex items-center gap-1.5">
                            <span class="size-2 rounded-sm {{ $keys[$kind] }}" aria-hidden="true"></span>
                            <span class="font-mono font-medium text-ink">{{ number_format($t[$kind]) }}</span> {{ $labels[$kind] }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Needs you: this project's rows of the home overview's Needs me, by its own rules. --}}
        @php
            $rows = $needs['rows'] ?? [];
            $kinds = [ListNeedsMe::PICK => ['Pick', 'needs'], ListNeedsMe::DRAFT => ['Draft', 'live'], ListNeedsMe::READY => ['Ready', 'go']];
        @endphp
        <section data-tile="needs" data-needs-panel="{{ $needs === null ? 'error' : ($rows === [] ? 'empty' : 'ok') }}" aria-labelledby="ov-needs" class="{{ $tile }}">
            <h2 id="ov-needs" class="{{ $heading }}">Needs you</h2>
            @if ($needs === null)
                <p role="alert" class="{{ $panelError }}">Could not load what needs you.</p>
            @elseif ($rows === [])
                <p class="{{ $note }}">Nothing needs you here</p>
            @else
                <span class="{{ $big }} text-needs">{{ count($rows) }}</span>
                <ul class="flex flex-col">
                    @foreach ($rows as $row)
                        @php
                            $story = $row['story'];
                            [$kindLabel, $kindTone] = $kinds[$row['kind']];
                            // A malformed ID has no story page (Story::hasPage): shown, not linked. A bag, not an `@if` in the tag.
                            $link = new \Illuminate\View\ComponentAttributeBag($story->hasPage() ? [
                                'href' => route('stories.show', ['project' => $model->name, 'storyId' => $story->story_id]),
                                'wire:navigate' => true,
                            ] : []);
                        @endphp
                        <li wire:key="needs-{{ $story->id }}">
                            <a {{ $link }} data-needs-row="{{ $row['kind'] }}" data-needs-story="{{ $story->story_id ?? $story->path }}"
                                @class(['flex min-h-11 min-w-0 items-center gap-2 rounded-md text-sm', "hover:bg-raised {$focus}" => $story->hasPage()])>
                                <x-shell.tag :tone="$kindTone" class="min-w-14 justify-center">{{ $kindLabel }}</x-shell.tag>
                                <span class="shrink-0 font-mono text-xs text-ink-soft">{{ $story->story_id ?? $story->path }}</span>
                                <span class="min-w-0 truncate text-ink-muted">{{ $story->title }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Live now: this project's cards of the home overview's Live now. --}}
        @php
            $cards = $live['sessions'] ?? [];
            $liveState = match (true) {
                $live === null, ($live['status'] ?? null) === SessionReader::UNAVAILABLE => 'error',
                $live['status'] === SessionReader::MISSING => 'missing',
                $cards === [] => 'empty',
                default => 'ok',
            };
        @endphp
        <section data-tile="live" data-live-now="{{ $liveState }}" aria-labelledby="ov-live" class="{{ $tile }}">
            <h2 id="ov-live" class="{{ $heading }}"><span class="size-2 shrink-0 rounded-full bg-live" aria-hidden="true"></span>Live now</h2>
            @if ($liveState === 'error')
                <p role="alert" class="{{ $panelError }}">Could not read sessions.</p>
            @elseif ($liveState === 'missing')
                <p class="{{ $note }}">No Claude Code sessions folder found. Set <code class="font-mono text-xs">BOARD_SESSIONS_PATH</code> if Claude Code keeps its sessions elsewhere.</p>
            @elseif ($liveState === 'empty')
                <p class="{{ $note }}">Nothing running.</p>
            @else
                @foreach ($cards as $card)
                    @php
                        $pct = $card['tokens'] === null ? null : (int) round($card['tokens'] / $card['window'] * 100);
                        $context = $pct === null ? '—' : ReadPreflightHistory::tokens($card['tokens']).' / '.ReadPreflightHistory::tokens($card['window']);
                    @endphp
                    <article wire:key="live-{{ $card['key'] }}" data-live-card="{{ $card['project'] }}" class="flex min-w-0 flex-col gap-2 border-t border-hairline pt-3 first-of-type:border-t-0 first-of-type:pt-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-shell.tag mono>{{ match ($card['branch']) { null => 'no branch', 'HEAD' => 'detached HEAD', default => $card['branch'] } }}</x-shell.tag>
                            @foreach ($card['stories'] as $id)
                                <a href="{{ route('stories.show', ['project' => $card['project'], 'storyId' => $id]) }}" wire:navigate data-live-story="{{ $id }}" class="rounded-full {{ $focus }}">
                                    <x-shell.tag tone="needs" mono>{{ $id }}</x-shell.tag>
                                </a>
                            @endforeach
                            @if ($card['started_at'])
                                <span class="ml-auto text-xs text-ink-muted"><span class="sr-only">running for </span>{{ Overview::duration($card['started_at']->diffInSeconds(now(), true), seconds: false) }}</span>
                            @endif
                        </div>
                        <p class="font-mono text-xs break-words text-ink-soft">{{ $card['doing'] ?? 'No tool calls yet' }}</p>
                        <div class="flex h-1.5 overflow-hidden rounded-full bg-edge" role="img" aria-label="{{ $pct === null ? 'Context: no usage recorded yet' : "Context {$pct}% full: {$context} tokens" }}">
                            @if ($pct !== null)
                                <div class="h-full {{ $pct >= 60 ? 'bg-needs' : 'bg-live' }}" style="width: {{ min(100, $pct) }}%"></div>
                            @endif
                        </div>
                        <span class="font-mono text-xs text-ink-muted">{{ $context }} context</span>
                    </article>
                @endforeach
            @endif
        </section>

        {{-- Latest preflight: the newest cost row, its verdict only when a run record (RD-8) carries one. --}}
        @php $run = $preflight['run'] ?? null; @endphp
        <section data-tile="preflight" data-preflight-state="{{ $preflight === null ? 'error' : ($run === null ? 'empty' : 'ok') }}" aria-labelledby="ov-preflight" class="{{ $tile }} md:col-span-2">
            <h2 id="ov-preflight" class="{{ $heading }}">Latest preflight <a href="{{ route('projects.preflight', $model->name) }}" wire:navigate class="{{ $headLink }}">Preflight</a></h2>
            @if ($preflight === null)
                <p role="alert" class="{{ $panelError }}">Could not read the preflight runs.</p>
            @elseif ($run === null)
                <p class="{{ $note }}">No preflight runs recorded for {{ $model->name }} yet.</p>
            @else
                @php
                    $latest = $run['latest'];
                    $at = $latest['at']->setTimezone(config('app.timezone'));
                    $verdictTone = match ($latest['verdict']) { 'GO' => 'go', 'NO-GO' => 'fail', default => null };
                    $days = $run['days'];
                    $peak = max(1, max(array_column($days, 'runs')));
                    $today = end($days);
                @endphp
                <div class="flex flex-wrap items-center gap-2.5">
                    <span class="sr-only">Verdict:</span>
                    <x-shell.tag :tone="$verdictTone" mono data-preflight-verdict="{{ $latest['verdict'] ?? '' }}" class="text-sm">{{ $latest['verdict'] ?? '—' }}</x-shell.tag>
                    @if ($latest['mode'] !== null)<x-shell.tag mono data-preflight-mode>{{ $latest['mode'] }}</x-shell.tag>@endif
                    <span data-preflight-tokens class="font-mono text-sm">{{ ReadPreflightHistory::tokens($latest['tokens']) }} tok</span>
                    <span data-preflight-at class="ml-auto text-xs text-ink-muted" title="{{ $at->toDateTimeString() }}">{{ $at->format('M j H:i') }} · {{ ReadPreflightHistory::wall($latest['wall']) }}@if ($latest['ran_on']) · {{ $latest['ran_on'] }}@endif</span>
                </div>
                @if ($latest['branch'])<p class="font-mono text-xs break-all text-ink-faint">{{ $latest['branch'] }}</p>@endif
                {{-- Runs per day (grey) with the all-pass runs (green) drawn over them from the baseline. --}}
                <svg data-preflight-chart viewBox="0 0 {{ count($days) * 20 - 6 }} 50" preserveAspectRatio="none" class="block h-14 w-full" role="img"
                    aria-label="Preflight runs per day, {{ $days[0]['day']->format('j M') }} to today: today {{ $today['runs'] }} {{ Str::plural('run', $today['runs']) }}, {{ $today['pass'] }} all-pass.">
                    @foreach ($days as $i => $d)
                        @php
                            $h = $d['runs'] / $peak * 48;
                            $p = $d['pass'] / $peak * 48;
                        @endphp
                        @if ($d['runs'] > 0)<rect x="{{ $i * 20 }}" y="{{ 50 - $h }}" width="14" height="{{ $h }}" rx="2" class="fill-rim" />@endif
                        @if ($d['pass'] > 0)<rect x="{{ $i * 20 }}" y="{{ 50 - $p }}" width="14" height="{{ $p }}" rx="2" class="fill-go" />@endif
                    @endforeach
                </svg>
                <p class="flex flex-wrap gap-x-4 text-xs text-ink-muted">
                    <span>{{ $days[0]['day']->format('j M') }}</span>
                    <span data-preflight-today class="ml-auto">today <b class="font-mono font-medium text-ink">{{ $today['runs'] }}</b> {{ Str::plural('run', $today['runs']) }} · <b class="font-mono font-medium text-go">{{ $today['pass'] }}</b> all-pass</span>
                </p>
            @endif
        </section>

        {{-- Production: stored snapshots of the connected metrics; one line when there is no connection. --}}
        @php $metrics = $production['metrics'] ?? null; @endphp
        <section data-tile="production" data-production-state="{{ $production === null ? 'error' : ($metrics === null ? 'none' : 'ok') }}" aria-labelledby="ov-production"
            @class([$tile, 'md:col-span-2', 'justify-center' => $metrics === null])>
            @if ($production !== null && $metrics === null)
                <p data-production-none class="{{ $note }}"><span id="ov-production">Production not connected</span> · <a href="{{ route('projects.manage') }}" wire:navigate class="inline-flex min-h-11 items-center rounded-md text-needs hover:underline {{ $focus }}">Set up</a></p>
            @else
                <h2 id="ov-production" class="{{ $heading }}">Production <a href="{{ route('projects.manage') }}" wire:navigate class="{{ $headLink }}">Settings</a></h2>
                @if ($production === null)
                    <p role="alert" class="{{ $panelError }}">Could not read the production numbers.</p>
                @elseif ($metrics === [])
                    <p class="{{ $note }}">No metrics switched on. Choose some on the projects page.</p>
                @else
                    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                        @foreach ($metrics as $metric)
                            <div wire:key="metric-{{ $loop->index }}" data-metric="{{ $metric['label'] }}" class="flex min-w-0 flex-col gap-1 rounded-lg border border-hairline bg-ground px-3 py-2.5">
                                <span class="truncate text-xs text-ink-muted">{{ $metric['label'] }}</span>
                                <span class="flex items-end gap-2">
                                    <span data-metric-value class="font-mono text-xl">{{ $metric['text'] ?? '—' }}</span>
                                    <x-board.sparkline decorative :series="$metric['series']" tone="text-live" class="ml-auto h-6 w-20 shrink-0" />
                                </span>
                                @if ($metric['text'] === null)<span class="text-xs text-ink-faint">No reading in the last {{ \App\Actions\Board\ReadProjectProduction::DAYS }} days</span>@endif
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </section>

        {{-- About: the brief's "What it is", read at the snapshot like the handbook. --}}
        <section data-tile="about" data-about-state="{{ $about['state'] }}" aria-labelledby="ov-about" class="{{ $tile }} md:col-span-2">
            <h2 id="ov-about" class="{{ $heading }}">About {{ $model->name }} <a href="{{ route('projects.handbook', $model->name) }}" wire:navigate class="{{ $headLink }}">Handbook</a></h2>
            @switch ($about['state'])
                @case('ok')
                    <x-board.prose :html="$about['html']" class="prose-sm text-ink-soft" />
                    @break
                @case('missing')
                    <x-board.handbook-empty :project="$model->name" file="docs/PROJECT-BRIEF.md" class="py-6">A project brief's "What it is" section shows here.</x-board.handbook-empty>
                    @break
                @case('no-section')
                    <x-board.handbook-empty :project="$model->name" file="What it is" class="py-6">docs/PROJECT-BRIEF.md has no "What it is" section to show here.</x-board.handbook-empty>
                    @break
                @case('unread')
                    <p class="{{ $note }}">{{ $model->name }} has not been read yet. The board refreshes it within a few minutes of a page load.</p>
                    @break
                @default
                    <p role="alert" class="{{ $panelError }} break-words">Could not read the brief at {{ substr((string) $model->sha, 0, 8) }}: {{ $about['error'] }}</p>
            @endswitch
        </section>

        {{-- Biggest initiatives: most stories first, built out of total. --}}
        @php $initiatives = $progress['initiatives'] ?? []; @endphp
        <section data-tile="initiatives" aria-labelledby="ov-initiatives" class="{{ $tile }} md:col-span-2">
            <h2 id="ov-initiatives" class="{{ $heading }}">Biggest initiatives <a href="{{ $stories }}" wire:navigate class="{{ $headLink }}">All {{ $groups }}</a></h2>
            @if ($progress === null)
                <p role="alert" class="{{ $panelError }}">Could not count the initiatives.</p>
            @elseif ($initiatives === [])
                <p class="{{ $note }}">No initiatives yet.</p>
            @else
                <ul class="flex flex-col gap-2">
                    @foreach ($initiatives as $initiative)
                        <li wire:key="initiative-{{ $loop->index }}" data-initiative="{{ $initiative['name'] ?? '' }}" class="grid grid-cols-[minmax(0,7rem)_minmax(0,1fr)_4rem] items-center gap-3 text-sm sm:grid-cols-[minmax(0,10rem)_minmax(0,1fr)_4rem]">
                            <span class="truncate">{{ $initiative['name'] ?? 'No initiative' }}</span>
                            {{-- A plain track, not board/status-bar: that bar always fills its width, and this one shows built out of total. --}}
                            <span class="flex h-1.5 overflow-hidden rounded-full bg-raised" role="img" aria-label="{{ $initiative['built'] }} of {{ $initiative['total'] }} built">
                                <span class="bg-built" style="width: {{ round($initiative['built'] / max(1, $initiative['total']) * 100, 2) }}%"></span>
                            </span>
                            <span data-initiative-count class="text-right font-mono text-xs text-ink-muted">{{ $initiative['built'] }}/{{ $initiative['total'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</main>

{{--
    RD-5 home overview, Home A "command center" (docs/mockups/RD-5/option-a.html) in the RD-2 shell. Top to bottom: today's
    date and one sentence of counts; the four headline figures; then Needs me (oldest first) beside Live now. The
    mockup's dashed parts (search, Run preflight on brain, spend) are other stories. RD-7 (docs/mockups/RD-7/option-a.html)
    adds the Projects grid and Shipped this week below; the Full timeline link and the spend card stay out (F-2, Money).

    Each panel's data arrives on its own and may be null — its source threw (Overview::attempt logged it) — so a panel
    shows its error state and the others still render. Live now's section carries the page's only wire:poll; a poll
    re-renders the whole component, so the sentence and the figures follow the cards. Nothing of a transcript renders
    but its project, branch, times, the one-line summary of its newest tool call and its token count.
--}}
@use('App\Actions\Board\ListNeedsMe')
@use('App\Actions\Board\ReadPreflightHistory')
@use('App\Livewire\Board\Overview')
@use('App\Services\SessionReader')
@php
    $focus = 'outline-needs focus-visible:outline-2 focus-visible:outline-offset-2';
    $note = 'text-sm text-ink-soft';
@endphp
{{-- One root element, always: Livewire morphs a poll's render into it, and a root `@if` would append a second page. --}}
<main class="flex min-w-0 flex-col gap-7 px-4 py-6 sm:px-8 sm:py-7">
@if ($empty)
        {{-- One empty state for the whole page: with no project there is nothing for any panel to say. --}}
        <section data-overview-empty aria-labelledby="no-projects" class="flex flex-col items-center gap-3 rounded-xl border border-edge bg-surface px-5 py-10 text-center">
            <h1 id="no-projects" class="text-xl font-semibold">No projects yet</h1>
            <p class="max-w-md text-sm text-ink-muted">The overview fills in once Story Board knows about a project.</p>
            <a href="{{ route('projects.manage') }}" wire:navigate class="inline-flex min-h-11 items-center rounded-lg bg-needs px-4 text-sm font-medium text-needs-ink {{ $focus }}">Add a project</a>
        </section>
@else
    @php
        $rows = $needs['rows'] ?? [];
        $counts = $needs['counts'] ?? null;
        $cards = $live['sessions'] ?? [];
        $liveState = match (true) {
            $live === null, ($live['status'] ?? null) === SessionReader::UNAVAILABLE => 'error',
            $live['status'] === SessionReader::MISSING => 'missing',
            $cards === [] => 'empty',
            default => 'ok',
        };
        $liveCount = $liveState === 'error' ? null : count($cards);
        $projectCount = count(array_unique(array_column($cards, 'project')));
        // The session running longest, for the figure's last line; only sessions whose start could be read.
        $longest = collect($cards)->filter(fn ($c) => $c['started_at'] !== null)->sortBy('started_at')->first();
        $overADay = collect($rows)->filter(fn ($r) => $r['story']->dated_on !== null && $r['story']->dated_on->lt(today()))->count();

        $sentence = collect([
            match (true) {
                $needs === null => null,
                count($rows) === 0 => 'Nothing needs you.',
                default => count($rows).' '.(count($rows) === 1 ? 'thing needs' : 'things need').' you.',
            },
            match (true) {
                $liveCount === null => null,
                $liveCount === 0 => 'No sessions are building.',
                default => $liveCount.' '.($liveCount === 1 ? 'session is' : 'sessions are').' building.',
            },
        ])->filter()->implode(' ');

        $kinds = [
            ListNeedsMe::PICK => ['Pick', 'needs'],
            ListNeedsMe::DRAFT => ['Draft', 'live'],
            ListNeedsMe::READY => ['Ready', 'go'],
        ];
        $age = function ($date) {
            if ($date === null) {
                return 'undated';
            }
            $days = (int) $date->diffInDays(today(), true);

            return $days === 0 ? 'today' : $days.'d';
        };
        $panelError = 'text-sm text-fail';
        $retry = "inline-flex min-h-11 items-center self-start rounded-lg border border-rim bg-raised px-4 text-sm font-medium hover:text-ink disabled:opacity-60 {$focus}";
    @endphp
        @php
            $chosen = $filter->names;
            $filterLabel = match (count($chosen)) {
                0 => 'All projects',
                1 => $chosen[0],
                default => count($chosen).' projects',
            };
            $clear = "inline-flex min-h-11 items-center rounded-md px-2.5 text-sm text-ink-muted underline underline-offset-4 hover:text-ink {$focus}";
        @endphp
        <header class="flex flex-wrap items-start gap-4">
            <div class="mr-auto flex min-w-0 flex-col gap-1">
                <p class="text-xs font-medium tracking-wider text-ink-muted uppercase">{{ now()->format('l j F') }}</p>
                <h1 class="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">{{ $sentence !== '' ? $sentence : 'Overview' }}</h1>
            </div>

            {{-- RD-6 Projects filter, in the Preflight page's popover-of-checkboxes style. Open and close are Alpine (no
                 round-trip); a tick calls toggleProject, which re-renders every panel and pushes the URL. Each box's
                 ticked state follows $wire.projects, so Back (which changes the property, not the box) unticks it too. --}}
            <div class="relative" x-data="{ open: false }" x-on:keydown.escape="if (open) { open = false; $refs.filterButton.focus() }">
                <button type="button" data-filter-button x-ref="filterButton" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-expanded="false" aria-controls="home-projects"
                    class="inline-flex min-h-11 max-w-64 items-center gap-2 rounded-lg border border-rim bg-surface px-3 text-sm font-medium hover:border-ink-muted {{ $focus }}">
                    <svg class="size-4 shrink-0 text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 5h18l-7 8v6l-4 2v-8L3 5z"/></svg>
                    <span class="sr-only">Projects:</span>
                    <span data-filter-label class="truncate">{{ $filterLabel }}</span>
                    <svg class="size-4 shrink-0 text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>
                <div id="home-projects" data-filter-panel x-show="open" x-cloak x-on:click.outside="open = false" x-anchor.bottom-end.offset.4="$refs.filterButton"
                    role="group" aria-label="Projects shown" class="absolute z-20 w-64 rounded-lg border border-edge bg-surface p-1 text-sm shadow-lg">
                    @foreach ($options as $name)
                        <label wire:key="filter-option-{{ $name }}" data-filter-option="{{ $name }}" class="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-md px-2.5 hover:bg-raised">
                            <input type="checkbox" data-filter-toggle="{{ $name }}" @checked(in_array($name, $chosen, true))
                                x-bind:checked="$wire.projects.split(',').map(n => n.trim()).includes(@js($name))"
                                wire:change="toggleProject(@js($name))" class="size-4 shrink-0 accent-accent">
                            <span class="truncate">{{ $name }}</span>
                        </label>
                    @endforeach
                    <div class="mt-1 border-t border-hairline pt-1">
                        <button type="button" data-filter-clear wire:click="clearProjects" @disabled($chosen === []) class="{{ $clear }} w-full disabled:no-underline disabled:opacity-60">Clear · all projects</button>
                    </div>
                </div>
            </div>

            @if ($chosen !== [])
                {{-- The chosen projects as removable chips, so a narrowed page never passes for the whole board. --}}
                <div data-filter-active class="flex basis-full flex-wrap items-center gap-x-1 gap-y-0">
                    <span class="mr-1 text-sm text-ink-muted">Showing</span>
                    @foreach ($chosen as $name)
                        <button type="button" wire:key="filter-chip-{{ $name }}" data-filter-chip="{{ $name }}" wire:click="toggleProject(@js($name))" aria-label="Remove {{ $name }} from the filter"
                            class="inline-flex min-h-11 items-center rounded-full {{ $focus }}">
                            <x-shell.tag tone="needs" mono class="hover:bg-raised">
                                {{ $name }}
                                <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                            </x-shell.tag>
                        </button>
                    @endforeach
                    <button type="button" data-filter-clear-chips wire:click="clearProjects" class="{{ $clear }}">Clear</button>
                </div>
            @endif
        </header>

        <section aria-label="Right now" class="grid gap-3.5 sm:grid-cols-2 xl:grid-cols-4">
            <x-shell.figure key="live" label="Live sessions" :value="$liveCount" tone="live"
                :accent="$liveCount === null ? 'could not be read' : ($liveCount > 0 ? $projectCount.' '.Str::plural('project', $projectCount) : null)">
                @if ($longest)Longest: {{ $longest['stories'][0] ?? $longest['project'] }}, {{ Overview::duration($longest['started_at']->diffInSeconds(now(), true), seconds: false) }}@endif
            </x-shell.figure>

            <x-shell.figure key="needs" label="Needs me" :value="$counts === null ? null : count($rows)" tone="needs"
                :accent="$counts === null ? 'could not be read' : ($overADay > 0 ? $overADay.' over a day' : null)">
                @if ($counts !== null){{ $counts['pick'] }} {{ Str::plural('pick', $counts['pick']) }} · {{ $counts['draft'] }} {{ Str::plural('draft', $counts['draft']) }} · {{ $counts['ready'] }} ready @endif
            </x-shell.figure>

            @php $preflight = $figures['preflight'] ?? null; @endphp
            <x-shell.figure key="preflight" label="Preflight · last 7 days" :value="$preflight['runs'] ?? null"
                :accent="match (true) { $preflight === null => 'could not be read', $preflight['runs'] === 0 => 'no runs this week', default => Str::plural('run', $preflight['runs']) }">
                @if (($preflight['wall'] ?? null) !== null)median {{ Overview::duration($preflight['wall']) }}@endif
            </x-shell.figure>

            @php $inFlight = $figures['in_flight'] ?? null; @endphp
            <x-shell.figure key="in-flight" label="In flight" :value="$inFlight"
                :accent="$inFlight === null ? 'could not be read' : Str::plural('story', $inFlight)">
                @if ($inFlight !== null)approved, not yet built @endif
            </x-shell.figure>
        </section>

        <div class="grid items-start gap-5 lg:grid-cols-2">
            <section data-needs-panel="{{ $needs === null ? 'error' : ($rows === [] ? 'empty' : 'ok') }}" aria-labelledby="needs-title" class="min-w-0 rounded-xl border border-edge bg-surface py-1.5">
                <div class="flex items-center gap-3 px-5 pt-3.5 pb-2.5">
                    <h2 id="needs-title" class="text-base font-semibold">Needs me</h2>
                    <span wire:loading.delay class="text-xs text-ink-muted" role="status">Updating…</span>
                    <span class="ml-auto text-sm text-ink-muted">oldest first</span>
                </div>

                @if ($needs === null)
                    <div class="flex flex-col gap-3 border-t border-hairline px-5 py-4">
                        <p role="alert" class="{{ $panelError }}">Could not load what needs you. The rest of the page is unaffected.</p>
                        <button type="button" wire:click="$refresh" wire:loading.attr="disabled" class="{{ $retry }}">Try again</button>
                    </div>
                @elseif ($rows === [])
                    <p class="border-t border-hairline px-5 py-4 {{ $note }}">Nothing needs you. No picks, drafts or ready stories.</p>
                @else
                    <ul>
                        @foreach ($rows as $row)
                            @php
                                $story = $row['story'];
                                [$kindLabel, $kindTone] = $kinds[$row['kind']];
                                $meta = match ($row['kind']) {
                                    ListNeedsMe::PICK => count($story->mockups['options'] ?? []).' options',
                                    ListNeedsMe::DRAFT => 'draft',
                                    default => 'approved',
                                };
                                // A row whose ID is malformed has no story page (Story::hasPage), so it is shown, not linked. The link
                                // attributes are a bag, not an `@if` inside the tag, which Livewire's morph markers would split.
                                $link = new \Illuminate\View\ComponentAttributeBag($story->hasPage() ? [
                                    'href' => route('stories.show', ['project' => $story->project->name, 'storyId' => $story->story_id]),
                                    'wire:navigate' => true,
                                ] : []);
                            @endphp
                            <li wire:key="needs-{{ $story->id }}">
                                <a {{ $link }} data-needs-row="{{ $row['kind'] }}" data-needs-story="{{ $story->project->name }}/{{ $story->story_id ?? $story->path }}"
                                    @class(['flex min-h-14 items-center gap-3.5 border-t border-hairline px-5 py-2.5', "hover:bg-raised {$focus}" => $story->hasPage()])>
                                    <x-shell.tag :tone="$kindTone" class="min-w-16 justify-center">{{ $kindLabel }}</x-shell.tag>
                                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                        <span class="truncate text-sm font-medium">{{ $story->title ?? $story->story_id ?? $story->path }}</span>
                                        <span class="truncate font-mono text-xs text-ink-muted">{{ $story->project->name }} · {{ $story->story_id ?? $story->path }} · {{ $meta }}</span>
                                    </span>
                                    <span class="text-xs whitespace-nowrap text-ink-faint" title="{{ $story->dated_on ? 'Asked for '.$story->dated_on->toDateString() : 'No date in its Source line' }}"><span class="sr-only">waiting </span>{{ $age($story->dated_on) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section wire:poll.30s data-live-now="{{ $liveState }}" aria-labelledby="live-title" class="flex min-w-0 flex-col gap-3">
                <div class="flex items-center gap-2">
                    <span class="size-2 shrink-0 rounded-full bg-live" aria-hidden="true"></span>
                    <h2 id="live-title" class="text-base font-semibold">Live now</h2>
                    <span wire:loading.delay class="text-xs text-ink-muted" role="status">Updating…</span>
                    <span class="ml-auto text-sm text-ink-muted">updates every 30s</span>
                </div>

                @if ($liveState === 'error')
                    <div class="flex flex-col gap-3 rounded-xl border border-edge bg-surface p-4">
                        <p role="alert" class="{{ $panelError }}">Could not read sessions. The rest of the page is unaffected.</p>
                        <button type="button" wire:click="$refresh" wire:loading.attr="disabled" class="{{ $retry }}">Try again</button>
                    </div>
                @elseif ($liveState === 'missing')
                    <p class="rounded-xl border border-edge bg-surface p-4 {{ $note }}">
                        No Claude Code sessions folder found. Looked in <code class="font-mono text-xs">{{ config('board.sessions_path') }}</code>; set <code class="font-mono text-xs">BOARD_SESSIONS_PATH</code> if Claude Code keeps its sessions elsewhere.
                    </p>
                @elseif ($liveState === 'empty')
                    <p class="rounded-xl border border-edge bg-surface p-4 {{ $note }}">No sessions running.</p>
                @else
                    @foreach ($cards as $card)
                        @php
                            $pct = $card['tokens'] === null ? null : (int) round($card['tokens'] / $card['window'] * 100);
                            $context = $pct === null ? '—' : ReadPreflightHistory::tokens($card['tokens']).' / '.ReadPreflightHistory::tokens($card['window']);
                        @endphp
                        <article wire:key="live-{{ $card['key'] }}" data-live-card="{{ $card['project'] }}" class="flex flex-col gap-2.5 rounded-xl border border-edge bg-surface p-4">
                            <div class="flex flex-wrap items-center gap-2.5">
                                <span class="text-sm font-semibold">{{ $card['project'] }}</span>
                                <x-shell.tag mono>{{ match ($card['branch']) { null => 'no branch', 'HEAD' => 'detached HEAD', default => $card['branch'] } }}</x-shell.tag>
                                @forelse ($card['stories'] as $id)
                                    <a href="{{ route('stories.show', ['project' => $card['project'], 'storyId' => $id]) }}" wire:navigate data-live-story="{{ $card['project'] }}/{{ $id }}" class="rounded-full {{ $focus }}">
                                        <x-shell.tag tone="needs" mono>{{ $id }}</x-shell.tag>
                                    </a>
                                @empty
                                    <x-shell.tag class="text-ink-muted">no story matched</x-shell.tag>
                                @endforelse
                                @if ($card['started_at'])
                                    <span class="ml-auto text-xs text-ink-muted" title="Started {{ $card['started_at']->toDateTimeString() }}"><span class="sr-only">running for </span>{{ Overview::duration($card['started_at']->diffInSeconds(now(), true), seconds: false) }}</span>
                                @endif
                            </div>
                            <p data-doing class="font-mono text-sm break-words text-ink-soft">{{ $card['doing'] ?? 'No tool calls yet' }}</p>
                            <div data-context data-context-pct="{{ $pct }}" class="flex items-center gap-2.5">
                                <span class="w-13 text-xs text-ink-muted">context</span>
                                <div class="flex h-1.5 flex-1 overflow-hidden rounded-full bg-edge" role="img"
                                    aria-label="{{ $pct === null ? 'Context: no usage recorded yet' : "Context {$pct}% full: {$context} tokens" }}">
                                    @if ($pct !== null)
                                        {{-- Amber once the context is past 60%: that is when a session is worth a look before it compacts. --}}
                                        <div class="h-full {{ $pct >= 60 ? 'bg-needs' : 'bg-live' }}" style="width: {{ min(100, $pct) }}%"></div>
                                    @endif
                                </div>
                                <span data-context-label class="min-w-21 text-right font-mono text-xs text-ink-soft">{{ $context }}</span>
                            </div>
                        </article>
                    @endforeach
                @endif
            </section>
        </div>

        {{-- RD-7 Projects grid: one tile per covered project, linking to its Stories page. Counts, off-main and the
             production headline are stored data; the last preflight is the newest CSV row; the live dot follows the
             Live now cards above, so it never disagrees with them. --}}
        @php
            $liveProjects = array_unique(array_column($cards, 'project'));
            $tileState = $tiles === null ? 'error' : ($tiles === [] ? 'empty' : 'ok');
        @endphp
        <section data-tiles-panel="{{ $tileState }}" aria-labelledby="projects-title" class="flex min-w-0 flex-col gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <h2 id="projects-title" class="text-base font-semibold">Projects</h2>
                <span wire:loading.delay class="text-xs text-ink-muted" role="status">Updating…</span>
                <a href="{{ route('projects.manage') }}" wire:navigate class="ml-auto inline-flex min-h-11 items-center rounded-md text-sm text-needs hover:underline {{ $focus }}">Manage projects</a>
            </div>

            @if ($tiles === null)
                <div class="flex flex-col gap-3 rounded-xl border border-edge bg-surface p-4">
                    <p role="alert" class="{{ $panelError }}">Could not load the project tiles. The rest of the page is unaffected.</p>
                    <button type="button" wire:click="$refresh" wire:loading.attr="disabled" class="{{ $retry }}">Try again</button>
                </div>
            @elseif ($tiles === [])
                <p class="rounded-xl border border-edge bg-surface p-4 {{ $note }}">No projects match the filter.</p>
            @else
                <div class="grid gap-3.5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    @foreach ($tiles as $tile)
                        @php
                            ['built' => $built, 'approved' => $approved, 'draft' => $draft] = $tile['stories'];
                            $progress = "{$built} built · {$approved} in flight · {$draft} draft";
                            $run = $tile['preflight'];
                            $live = in_array($tile['name'], $liveProjects, true);
                            // One string, not an `@if` inside the span: Livewire's morph markers would split the text.
                            $runText = $run === null ? null : Overview::ago($run['at']).($run['wall'] !== null ? ' · '.Overview::duration($run['wall']) : '');
                        @endphp
                        <a wire:key="tile-{{ $tile['name'] }}" href="{{ route('projects.stories', ['project' => $tile['name']]) }}" wire:navigate data-tile="{{ $tile['name'] }}"
                            aria-label="{{ $tile['name'] }} stories"
                            class="flex min-w-0 flex-col gap-3.5 rounded-xl border border-edge bg-surface p-4 hover:border-rim {{ $focus }}">
                            <span class="flex flex-wrap items-center gap-2.5">
                                <x-board.state :state="$tile['state']" />
                                <span class="min-w-0 truncate text-sm font-semibold">{{ $tile['name'] }}</span>
                                <x-board.state :state="$tile['state']" part="label" />
                                @if ($live)
                                    <span data-tile-live class="ml-auto inline-flex items-center gap-1.5 text-xs text-live">
                                        <span class="size-2 shrink-0 rounded-full bg-live" aria-hidden="true"></span>session running
                                    </span>
                                @endif
                            </span>

                            <span class="flex flex-col gap-1.5">
                                <x-board.status-bar :counts="['built' => $built, 'approved' => $approved, 'draft' => $draft]" :label="$progress" />
                                <span class="flex flex-wrap gap-x-3.5 gap-y-1 text-xs text-ink-muted">
                                    <span data-tile-counts>{{ $progress }}</span>
                                    <span data-tile-offmain="{{ $tile['offmain'] }}" class="ml-auto">{{ $tile['offmain'] > 0 ? number_format($tile['offmain']).' off main' : 'clean' }}</span>
                                </span>
                            </span>

                            @if ($tile['prod'] !== null)
                                <span data-tile-prod class="flex items-end gap-3">
                                    <span class="flex min-w-0 flex-col gap-0.5">
                                        <span class="truncate text-xs text-ink-muted">{{ $tile['prod']['label'] }} <span class="text-ink-faint">(prod)</span></span>
                                        <span class="font-mono text-xl">{{ $tile['prod']['text'] }}</span>
                                    </span>
                                    <x-board.sparkline decorative :series="$tile['prod']['series']" tone="text-live" class="ml-auto h-8 w-28 shrink-0" />
                                </span>
                            @endif

                            <span data-tile-preflight class="flex flex-wrap items-center gap-2 border-t border-hairline pt-3 text-xs text-ink-muted">
                                <span>Last preflight</span>
                                @if ($run === null)
                                    <span class="ml-auto text-ink-faint">no runs yet</span>
                                @else
                                    <span class="ml-auto font-mono text-ink-soft" title="{{ $run['at']->toDateTimeString() }}">{{ $runText }}</span>
                                    @if ($run['mode'] !== null)<x-shell.tag mono>{{ $run['mode'] }}</x-shell.tag>@endif
                                    {{-- RD-8 sets where the run was made; until then this is always null. --}}
                                    @if ($run['ran_on'] !== null)<span data-tile-ran-on class="font-mono">on {{ $run['ran_on'] }}</span>@endif
                                @endif
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- RD-7 Shipped this week: build commits (feat|fix|test with a story ID) on each covered project's ref in the
             last 7 days, newest first. A project whose history cannot be read is named; the others are still listed. --}}
        @php
            $shippedRows = $shipped['rows'] ?? [];
            $shippedFailed = $shipped['failed'] ?? [];
            $shippedState = match (true) {
                $shipped === null => 'error',
                $shippedRows === [] => 'empty',
                default => 'ok',
            };
        @endphp
        <section data-shipped-panel="{{ $shippedState }}" aria-labelledby="shipped-title" class="min-w-0 rounded-xl border border-edge bg-surface py-1.5">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 pt-3.5 pb-2.5">
                <h2 id="shipped-title" class="text-base font-semibold">Shipped this week</h2>
                <span wire:loading.delay class="text-xs text-ink-muted" role="status">Updating…</span>
                <span class="text-sm text-ink-muted">newest first · build commits only</span>
            </div>

            @if ($shipped === null)
                <div class="flex flex-col gap-3 border-t border-hairline px-5 py-4">
                    <p role="alert" class="{{ $panelError }}">Could not load what shipped. The rest of the page is unaffected.</p>
                    <button type="button" wire:click="$refresh" wire:loading.attr="disabled" class="{{ $retry }}">Try again</button>
                </div>
            @else
                @if ($shippedFailed !== [])
                    <div class="flex flex-col gap-3 border-t border-hairline px-5 py-4">
                        <p role="alert" data-shipped-failed="{{ implode(',', $shippedFailed) }}" class="{{ $panelError }}">
                            Could not read git history for {{ implode(', ', $shippedFailed) }}. @if ($shippedRows !== [])Other projects are listed.@endif
                        </p>
                        <button type="button" wire:click="$refresh" wire:loading.attr="disabled" class="{{ $retry }}">Try again</button>
                    </div>
                @endif
                @if ($shippedRows === [])
                    <p class="border-t border-hairline px-5 py-4 {{ $note }}">Nothing shipped in the last 7 days.</p>
                @else
                    <ul>
                        @foreach ($shippedRows as $row)
                            @php
                                $at = $row['at']->setTimezone(config('app.timezone'));
                                $shipLink = new \Illuminate\View\ComponentAttributeBag($row['linked'] ? [
                                    'href' => route('stories.show', ['project' => $row['project'], 'storyId' => $row['story_id']]),
                                    'wire:navigate' => true,
                                ] : []);
                            @endphp
                            <li wire:key="shipped-{{ $row['project'] }}-{{ $row['story_id'] }}-{{ $loop->index }}">
                                <a {{ $shipLink }} data-shipped-row="{{ $row['project'] }}/{{ $row['story_id'] }}"
                                    @class(['flex min-h-11 flex-wrap items-center gap-x-3.5 gap-y-1 border-t border-hairline px-5 py-2.5', "hover:bg-raised {$focus}" => $row['linked']])>
                                    <time datetime="{{ $at->toIso8601String() }}" class="w-18 font-mono text-xs text-ink-faint">{{ $at->format('D H:i') }}</time>
                                    <x-shell.tag tone="go" mono>{{ $row['story_id'] }}</x-shell.tag>
                                    <span class="min-w-0 flex-1 basis-40 text-sm">{{ $row['title'] }}</span>
                                    <span class="text-xs text-ink-muted">{{ $row['project'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </section>
@endif
</main>

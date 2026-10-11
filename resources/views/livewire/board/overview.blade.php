{{--
    RD-5 home overview, Home A "command center" (docs/mockups/RD-5/option-a.html) in the RD-2 shell. Top to bottom: today's
    date and one sentence of counts; the four headline figures; then Needs me (oldest first) beside Live now. The
    mockup's dashed parts (search, Run preflight on brain, project tiles, Shipped this week, spend) are other stories.

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
        <header class="flex flex-col gap-1">
            <p class="text-xs font-medium tracking-wider text-ink-muted uppercase">{{ now()->format('l j F') }}</p>
            <h1 class="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">{{ $sentence !== '' ? $sentence : 'Overview' }}</h1>
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
@endif
</main>

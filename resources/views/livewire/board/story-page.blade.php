{{--
    RD-3 story page, short first (docs/mockups/RD-3/option-a.html, option A) in the RD-2 shell. Top to bottom: the ID,
    status and initiative as tags and the title; one summary line; the one action the story waits for (a mockup pick);
    the mockups — Today (a journey shot of the page, when one exists) and each option in one sandboxed frame, with full
    screen and the existing side-by-side viewer; then Details, every section folded with its count on the header.

    The option buttons are Alpine (`view` is what the frame shows, `choice` the option a pick would record; Today
    changes only `view`), and Details are native <details>, so neither calls the server. The pick is the one server call.
    The mockup's "Approve without a pick", "Edit story" and "Cost & history" are out of scope (RD-3) and not built.
--}}
@use('App\Actions\Board\ReadMockupSets')
@use('App\Services\StoryPickWriter')
@php
    $options = $story->mockups['options'] ?? [];
    // The same rule both file routes enforce: only the story's own folder, read from git.
    $servable = $gate['visual'] && ($story->mockups['dir'] ?? null) === "docs/mockups/{$story->story_id}" && $options !== [] && $story->isInGit();
    $awaiting = $set !== null && $set['state'] === ReadMockupSets::AWAITING;
    $picked = $set !== null ? ($set['state'] === ReadMockupSets::PICKED ? $set['picked'] : null) : ($story->mockups['chosen'] ?? null);
    $pickedWhy = $set !== null ? $set['reason'] : $gate['why'];
    $first = $picked !== null && in_array($picked, $options, true) ? $picked : ($options[0] ?? null);
    $urls = collect($options)->mapWithKeys(fn ($o) => [$o => $story->mockupUrl("option-{$o}.html")])->all();
    $shotUrl = $today ? route('shots.file', ['project' => $model->name, 'journey' => $today['shot']['journey'], 'file' => $today['shot']['file']]) : null;
    $page = fn (int $id, bool $onRef) => route('stories.show', ['project' => $model->name, 'storyId' => $story->story_id, ...($onRef ? [] : ['v' => $id])]);
    $statusTone = match (true) {
        $awaiting => 'needs',
        $story->status === 'built' => 'go',
        $story->status === 'approved' => 'live',
        default => null,
    };
    $kindLabels = ['main' => 'on main', 'branch' => 'branch', 'worktree' => 'worktree', 'untracked' => 'untracked'];
    $versionSummary = collect($versionKinds)->map(fn ($n, $kind) => "{$n} ".($kindLabels[$kind] ?? $kind))->implode(' · ');
    $focus = 'outline-needs focus-visible:outline-2 focus-visible:outline-offset-2';
    $link = "text-needs hover:underline underline-offset-4 {$focus}";
    $optionButton = "min-h-11 rounded-lg border border-rim px-4 text-sm font-medium text-ink-soft hover:text-ink aria-pressed:border-ink aria-pressed:bg-ink aria-pressed:text-ground {$focus}";
    $summary = "flex min-h-13 cursor-pointer list-none items-center gap-3 px-5 text-sm font-medium hover:bg-raised [&::-webkit-details-marker]:hidden {$focus}";
    $chevron = '<svg class="size-4 shrink-0 text-ink-faint transition-transform group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';
    $count = 'ml-auto text-right text-sm font-normal text-ink-muted';
    $detailBody = 'px-5 pt-1 pb-5 text-sm leading-relaxed text-ink-soft';
@endphp
<main class="mx-auto flex max-w-5xl flex-col gap-7 px-4 py-6 sm:px-8 lg:py-10"
    @if ($servable) x-data="{ view: @js($first), choice: @js($first), reason: '', urls: @js($urls) }" @endif>

    <nav aria-label="Breadcrumb" class="text-sm text-ink-muted">
        <a href="{{ route('projects.stories', $model->name) }}" wire:navigate class="hover:text-ink {{ $focus }}">{{ $model->name }}</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('projects.stories', $model->name) }}" wire:navigate class="hover:text-ink {{ $focus }}">Stories</a>
        <span aria-hidden="true">/</span>
        <span class="font-mono">{{ $story->story_id }}</span>
    </nav>

    <header class="flex flex-col gap-3.5">
        <div class="flex flex-wrap items-center gap-2">
            <x-shell.tag mono data-tag="id">{{ $story->story_id }}</x-shell.tag>
            <x-shell.tag :tone="$statusTone" data-tag="status">{{ ($story->status ?? 'no status').($awaiting ? ' · awaiting your pick' : '') }}</x-shell.tag>
            @if ($story->initiative)<x-shell.tag data-tag="initiative">{{ $story->initiative }}</x-shell.tag>@endif
            @if (count($story->parse_errors) > 0)<x-shell.tag tone="fail" data-tag="parse-errors">{{ count($story->parse_errors) }} parse {{ Str::plural('error', count($story->parse_errors)) }}</x-shell.tag>@endif
        </div>
        <h1 class="text-3xl font-semibold tracking-tight text-balance sm:text-4xl">{{ $story->title ?? $story->story_id }}</h1>
        @if ($outline['summary'])
            <p data-summary class="max-w-2xl text-lg leading-relaxed text-ink-soft">{{ $outline['summary'] }}</p>
        @endif

        @if ($story->location)
            <p data-offmain-shown class="text-sm text-needs">
                {{ ucfirst($story->placePhrase()) }} — not on main.
                @if ($onRef)<a href="{{ $page($onRef->id, true) }}" wire:navigate class="underline underline-offset-4 {{ $focus }}">See the version on main</a>@else It is not on main at all yet.@endif
            </p>
        @endif
        @if ($story->isInGit() && $body === null)
            <p role="alert" data-read-failed class="text-sm text-fail">This story could not be read from git at {{ substr($story->sha, 0, 8) }}. Its summary and details are missing until it can be.</p>
        @endif

        @if ($awaiting && $servable)
            <div class="mt-1 flex flex-wrap items-center gap-2.5">
                <button type="button" data-primary-action x-on:click="$wire.pick(choice, reason)" wire:loading.attr="disabled" wire:target="pick"
                    class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-needs px-4.5 text-sm font-medium text-needs-ink hover:brightness-110 disabled:opacity-60 {{ $focus }}">
                    <span wire:loading.remove wire:target="pick">Pick option <span x-text="choice.toUpperCase()">{{ strtoupper((string) $first) }}</span></span>
                    <span wire:loading wire:target="pick">Picking…</span>
                </button>
            </div>
        @endif
        @if ($refusal)
            <p role="alert" data-pick-refusal class="rounded-lg border border-fail/40 bg-fail/10 px-3 py-2 text-sm text-fail">{{ $refusal }}</p>
        @endif
        @if ($done)
            <p role="status" data-pick-done class="rounded-lg border border-go/40 bg-go/10 px-3 py-2 text-sm text-go">{{ $done }}</p>
        @endif
    </header>

    @if ($servable)
        <section data-story-mockups aria-labelledby="mockups-title" class="flex flex-col gap-3.5">
            <div class="flex flex-wrap items-center gap-2.5">
                <h2 id="mockups-title" class="text-lg font-semibold">Mockups</h2>
                @if ($today)<span class="text-sm text-ink-muted">compare with today's screen</span>@endif
                <div role="group" aria-label="Choose a mockup" class="flex flex-wrap gap-2 sm:ml-auto">
                    @if ($today)
                        <button type="button" data-mockup-option="today" x-on:click="view = 'today'" aria-pressed="false" x-bind:aria-pressed="(view === 'today').toString()" class="{{ $optionButton }}">Today</button>
                    @endif
                    @foreach ($options as $o)
                        <button type="button" data-mockup-option="{{ $o }}" x-on:click="view = @js($o); choice = @js($o)" aria-pressed="{{ $o === $first ? 'true' : 'false' }}" x-bind:aria-pressed="(view === @js($o)).toString()" class="{{ $optionButton }}">Option {{ strtoupper($o) }}@if ($o === $picked) ✓@endif</button>
                    @endforeach
                </div>
            </div>

            @if ($picked)
                <p data-picked class="flex flex-wrap items-center gap-2 text-sm text-ink-soft">
                    <x-shell.tag tone="go">Picked {{ strtoupper($picked) }}</x-shell.tag>
                    {{ $pickedWhy ?? 'No reason recorded.' }}
                    @if ($set !== null && ! $set['pushed'])<span class="text-ink-muted">· committed in the checkout, not pushed yet</span>@endif
                </p>
            @elseif (! $awaiting && $gate['chosen'])
                {{-- A choice the kit parser could not read as a letter (ADR-009): quote it, never guess. --}}
                <p data-chosen-text class="text-sm text-ink-soft"><span class="font-medium text-needs">Chosen, as written in the story:</span> {{ $gate['chosen'] }}</p>
            @endif

            <div class="flex flex-col gap-3 rounded-xl border border-edge bg-surface p-4">
                <div class="relative h-110 overflow-hidden rounded-lg bg-white">
                    @if ($today)
                        <div x-show="view === 'today'" x-cloak class="absolute inset-0"><x-board.mockup-current :current="$today" :project="$model->name" /></div>
                    @endif
                    <div x-show="view !== 'today'" class="absolute inset-0 overflow-hidden" x-data="mockupFit(() => 1280)">
                        <iframe data-mockup-frame src="{{ $urls[$first] }}" x-bind:src="urls[choice]" sandbox="allow-scripts" title="{{ $story->story_id }} mockup"
                            class="absolute top-0 left-0 origin-top-left border-0 bg-white" x-bind:style="frameStyle"></iframe>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                    <span class="text-ink-soft" x-text="view === 'today' ? 'Today: the page as its journey test last saw it.' : 'Option ' + view.toUpperCase()">Option {{ strtoupper((string) $first) }}</span>
                    <a data-full-screen href="{{ $urls[$first] }}" x-bind:href="view === 'today' ? @js($shotUrl) : urls[view]" target="_blank" rel="noopener" class="sm:ml-auto inline-flex min-h-11 items-center {{ $link }}">Full screen ↗</a>
                    @if ($set !== null)
                        <a href="{{ route('mockups.show', ['project' => $model->name, 'story' => $story->story_id]) }}" wire:navigate class="inline-flex min-h-11 items-center {{ $link }}">Side by side</a>
                    @endif
                </div>
            </div>

            @if ($awaiting)
                <label for="pick-reason" class="flex flex-col gap-1.5 text-sm text-ink-muted">Why this one? (optional, saved with the pick)
                    <input id="pick-reason" type="text" x-model="reason" maxlength="{{ StoryPickWriter::REASON_MAX }}" placeholder="One line"
                        class="min-h-11 rounded-lg border border-rim bg-surface px-3.5 text-sm text-ink placeholder:text-ink-faint {{ $focus }}">
                </label>
            @endif
        </section>
    @endif

    <section aria-labelledby="details-title" class="overflow-hidden rounded-xl border border-edge bg-surface">
        <h2 id="details-title" class="px-5 py-4 text-lg font-semibold">Details</h2>

        <details data-detail="criteria" class="group border-t border-hairline">
            <summary class="{{ $summary }}">{!! $chevron !!}Done when
                <span class="ml-auto">@if ($outline['criteria'] !== [])<x-shell.tag>{{ count($outline['criteria']) }} {{ count($outline['criteria']) === 1 ? 'criterion' : 'criteria' }}</x-shell.tag>@else<span class="text-sm font-normal text-ink-muted">None written</span>@endif</span>
            </summary>
            @if ($outline['criteria'] !== [])
                <ol class="{{ $detailBody }} flex list-decimal flex-col gap-2 pl-10">
                    @foreach ($outline['criteria'] as $criterion)<li>{!! $criterion !!}</li>@endforeach
                </ol>
            @else
                <p class="{{ $detailBody }}">The story has no Acceptance criteria section.</p>
            @endif
        </details>

        <details data-detail="depends" class="group border-t border-hairline">
            <summary class="{{ $summary }}">{!! $chevron !!}Depends on<span class="{{ $count }}">{{ $story->depends_on !== [] ? implode(', ', $story->depends_on) : 'None' }}</span></summary>
            @if ($story->depends_on !== [])
                <ul class="{{ $detailBody }} flex flex-col gap-1.5">
                    @foreach ($story->depends_on as $dep)
                        <li>
                            @if ($depends->has($dep))
                                <a href="{{ route('stories.show', ['project' => $model->name, 'storyId' => $dep]) }}" wire:navigate class="font-mono {{ $link }}">{{ $dep }}</a>
                                {{ $depends[$dep]->title }} <span class="text-ink-muted">({{ $depends[$dep]->status ?? 'no status' }})</span>
                            @else
                                <span class="font-mono">{{ $dep }}</span> <span class="text-ink-muted">— not on the board</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="{{ $detailBody }}">This story depends on no other story.</p>
            @endif
        </details>

        <details data-detail="untouchable" class="group border-t border-hairline">
            <summary class="{{ $summary }}">{!! $chevron !!}Do not touch<span class="{{ $count }}">{{ $outline['untouchable'] !== [] ? count($outline['untouchable']).' '.Str::plural('item', count($outline['untouchable'])) : 'None listed' }}</span></summary>
            @if ($outline['untouchable'] !== [])
                <ul class="{{ $detailBody }} flex list-disc flex-col gap-1.5 pl-10">
                    @foreach ($outline['untouchable'] as $item)<li>{!! $item !!}</li>@endforeach
                </ul>
            @else
                <p class="{{ $detailBody }}">The story lists nothing it must not touch.</p>
            @endif
        </details>

        <details data-detail="versions" class="group border-t border-hairline">
            <summary class="{{ $summary }}">{!! $chevron !!}Other versions<span class="{{ $count }}">{{ $versionSummary !== '' ? $versionSummary : 'None' }}</span></summary>
            @if ($versions !== [])
                <ul data-offmain-banner class="{{ $detailBody }} flex flex-col gap-1.5">
                    @foreach ($versions as $v)
                        <li><a href="{{ $page($v['id'], $v['onRef']) }}" wire:navigate class="{{ $v['onRef'] ? 'text-ink' : 'text-needs' }} hover:underline underline-offset-4 {{ $focus }}">{{ $v['text'] }}</a></li>
                    @endforeach
                </ul>
            @else
                <p class="{{ $detailBody }}">No copy of this story is on a branch, in a worktree or untracked.</p>
            @endif
        </details>

        <details data-detail="text" class="group border-t border-hairline">
            <summary class="{{ $summary }}">{!! $chevron !!}Full story text
                <span class="{{ $count }} font-mono text-xs">{{ $story->path }} · {{ $lines !== null ? $lines.' '.Str::plural('line', $lines) : ($story->isInGit() ? 'not read' : 'not in git') }}</span>
            </summary>
            <div class="{{ $detailBody }}">
                <p class="font-mono text-xs text-ink-muted">{{ $model->name }} · {{ $story->path }} @ {{ $story->location_kind === null ? $model->ref.' '.substr($story->sha, 0, 8) : ($story->isInGit() ? $story->branch.' '.substr($story->sha, 0, 8) : $story->location) }}</p>
                @if ($story->parse_errors !== [])
                    <ul class="mt-3 rounded-lg border border-fail/40 bg-fail/10 px-3 py-2 text-xs text-fail">
                        @foreach ($story->parse_errors as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                @endif
                @if (! $story->isInGit())
                    <p class="mt-3">An untracked file, {{ $story->location }} — not in git, so the board does not read its text. Open {{ $story->path }} in that checkout.</p>
                @elseif ($body === null)
                    <p class="mt-3 text-fail">Not read: git could not read this file at {{ substr($story->sha, 0, 8) }}.</p>
                @else
                    <div class="prose prose-invert mt-3 max-w-none prose-code:before:content-none prose-code:after:content-none">{!! $body !!}</div>
                @endif
            </div>
        </details>
    </section>
</main>

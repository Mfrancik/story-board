{{-- SB-4 story page — mockup B (story above the mockups) plus the owner's full-screen compare. All UI state is Alpine. --}}
@php
    $options = $story->mockups['options'] ?? [];
    $servable = ($story->mockups['dir'] ?? null) === "docs/mockups/{$story->story_id}" && $options !== [] && $story->isInGit();
    $chosen = $story->mockups['chosen'] ?? null;
    $first = $chosen && in_array($chosen, $options, true) ? $chosen : ($options[0] ?? null);
    $other = collect($options)->first(fn ($o) => $o !== $first) ?? $first;
    $src = fn (string $o) => $story->mockupUrl("option-{$o}.html");
    $page = fn (int $id, bool $onRef) => route('stories.show', ['project' => $project->name, 'storyId' => $story->story_id, ...($onRef ? [] : ['v' => $id])]);
    $toggle = 'rounded-md border border-zinc-300 px-2 py-1 text-xs aria-pressed:bg-zinc-900 aria-pressed:text-white dark:border-zinc-700 dark:aria-pressed:bg-white dark:aria-pressed:text-zinc-900';
    $chip = 'rounded bg-zinc-100 px-1.5 py-0.5 text-xs dark:bg-zinc-800';
@endphp
<div>
    <header class="flex flex-wrap items-center gap-2 border-b border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
        <a href="{{ route('home') }}" wire:navigate class="text-sm text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">← What needs me</a>
        <span class="text-zinc-300 dark:text-zinc-600" aria-hidden="true">/</span>
        <a href="{{ route('projects.show', ['project' => $project->name]) }}" wire:navigate class="text-sm hover:underline">{{ $project->name }}</a>
        <span class="text-zinc-300 dark:text-zinc-600" aria-hidden="true">/</span>
        <span class="font-mono text-sm font-semibold">{{ $story->story_id }}</span>
        <div class="ml-auto flex items-center gap-2">
            @if ($story->status === 'approved')
                <button type="button" x-data="{ copied: false }"
                    x-on:click="navigator.clipboard?.writeText(@js('/build '.$story->story_id)); copied = true; setTimeout(() => copied = false, 1500)"
                    class="rounded-md border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700">
                    <span x-show="! copied">Copy <code>/build {{ $story->story_id }}</code></span>
                    <span x-show="copied" x-cloak>Copied /build {{ $story->story_id }}</span>
                </button>
            @endif
            <button type="button" x-data x-on:click="$flux.dark = ! $flux.dark" aria-label="Toggle dark mode"
                class="rounded-md border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700">◐</button>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6">
        @if ($story->location)
            <p data-offmain-shown class="mb-3 rounded-lg border border-warning/50 bg-warning/10 px-3 py-2 text-sm">
                <b>{{ ucfirst($story->placePhrase()) }} — not on main.</b>
                @if ($onRef)<a href="{{ $page($onRef->id, true) }}" wire:navigate class="underline">See the version on main</a>@else It is not on main at all yet.@endif
            </p>
        @endif
        @if ($versions !== [])
            <ul data-offmain-banner class="mb-3 space-y-1 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-800 dark:bg-zinc-900">
                @foreach ($versions as $v)
                    <li><a href="{{ $page($v['id'], $v['onRef']) }}" wire:navigate class="{{ $v['onRef'] ? '' : 'text-warning' }} hover:underline">{{ $v['text'] }}</a></li>
                @endforeach
            </ul>
        @endif
        <article class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex flex-wrap items-center gap-1.5">
                <x-board.status-chip :status="$story->status" :errors="count($story->parse_errors)" />
                @if ($story->initiative)<span class="{{ $chip }}">initiative: {{ $story->initiative }}</span>@endif
                @if ($story->journey)<span class="{{ $chip }}">journey: {{ $story->journey }}</span>@endif
                @if ($story->depends_on !== [])
                    <span class="{{ $chip }}">depends on
                        @foreach ($story->depends_on as $dep)
                            @if (in_array($dep, $known, true))
                                <a href="{{ route('stories.show', ['project' => $project->name, 'storyId' => $dep]) }}" wire:navigate class="underline">{{ $dep }}</a>@if (! $loop->last),@endif
                            @else
                                {{ $dep }}@if (! $loop->last),@endif
                            @endif
                        @endforeach
                    </span>
                @endif
            </div>
            @if ($story->source)
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Source: {{ $story->source }}</p>
            @endif
            <p class="mt-1 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $project->name }} · {{ $story->path }} @ {{ $story->location_kind === null ? $project->ref.' '.substr($story->sha, 0, 8) : ($story->isInGit() ? $story->branch.' '.substr($story->sha, 0, 8) : $story->location) }}</p>

            @if ($story->parse_errors !== [])
                <ul class="mt-3 rounded border border-danger/30 bg-danger/5 px-3 py-2 text-xs text-danger">
                    @foreach ($story->parse_errors as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            @endif

            <details open class="mt-3">
                <summary class="cursor-pointer text-sm text-zinc-500 dark:text-zinc-400">Story text</summary>
                @if (! $story->isInGit())
                    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">An untracked file, {{ $story->location }} — not in git, so the board does not read its text. Open {{ $story->path }} in that checkout.</p>
                @elseif ($body === null)
                    <p class="mt-3 text-sm text-danger">This story could not be read from git at {{ substr($story->sha, 0, 8) }}.</p>
                @else
                    <div class="prose prose-zinc prose-code:before:content-none prose-code:after:content-none mt-3 max-w-3xl dark:prose-invert">{!! $body !!}</div>
                @endif
            </details>
        </article>

        @if ($gate['visual'])
            <section data-mockups class="mt-6"
                x-data="{ mode: 'side', width: 375, current: @js($first), compare: false, left: @js($first), right: @js($other), options: @js($options) }"
                x-on:keydown.escape.window="compare = false">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold">Mockups <span class="text-zinc-500">{{ count($options) }}</span></h2>
                    @if ($servable)
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="flex gap-1" role="group" aria-label="Layout">
                                <button type="button" x-on:click="mode = 'side'" x-bind:aria-pressed="(mode === 'side').toString()" class="{{ $toggle }}">Side by side</button>
                                <button type="button" x-on:click="mode = 'one'" x-bind:aria-pressed="(mode === 'one').toString()" class="{{ $toggle }}">One at a time</button>
                            </div>
                            <div class="flex gap-1" role="group" aria-label="Frame width">
                                @foreach ([375, 768, 1280] as $w)
                                    <button type="button" x-on:click="width = {{ $w }}" x-bind:aria-pressed="(width === {{ $w }}).toString()" class="{{ $toggle }} tabular-nums">{{ $w }}</button>
                                @endforeach
                            </div>
                            <div class="flex gap-1" role="group" aria-label="Option" x-show="mode === 'one'" x-cloak>
                                @foreach ($options as $o)
                                    <button type="button" x-on:click="current = @js($o)" x-bind:aria-pressed="(current === @js($o)).toString()" class="{{ $toggle }} font-mono">{{ strtoupper($o) }}{{ $o === $chosen ? ' ✓' : '' }}</button>
                                @endforeach
                            </div>
                            @if (count($options) > 1)
                                <button type="button" data-compare x-on:click="compare = true" class="rounded-md bg-zinc-900 px-3 py-1 text-xs font-medium text-white dark:bg-white dark:text-zinc-900">Compare two…</button>
                            @endif
                        </div>
                    @endif
                </div>

                @if (! $servable)
                    <p class="mt-3 rounded-lg border border-dashed border-zinc-300 px-3 py-4 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">No mockups {{ $story->location ? $story->placePhrase() : 'at '.$project->ref }} for {{ $story->story_id }}{{ $story->isInGit() ? '' : ' that the board can serve (untracked files are not in git)' }}.</p>
                @else
                    @if ($chosen)
                        <div class="mt-3 rounded-lg border border-built/50 bg-built/10 p-3 text-sm">
                            <b>Chosen: option {{ strtoupper($chosen) }}.</b>
                            @if ($gate['why'])<span class="text-zinc-700 dark:text-zinc-300">{{ $gate['why'] }}</span>
                            @elseif ($gate['chosen'])<span class="text-zinc-700 dark:text-zinc-300">{{ $gate['chosen'] }}</span>@endif
                        </div>
                    @elseif ($gate['chosen'])
                        {{-- The story records a choice the kit parser could not read as a letter (F-1): quote it, never guess. --}}
                        <div data-chosen-text class="mt-3 rounded-lg border border-warning/50 bg-warning/10 p-3 text-sm">
                            <b>Chosen, as written in the story:</b> {{ $gate['chosen'] }}
                            <span class="block text-xs text-zinc-600 dark:text-zinc-400">The story parser could not read an option letter from this line, so no frame is marked (backlog F-1).</span>
                        </div>
                    @else
                        <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">No option chosen yet.</p>
                    @endif

                    <div class="mt-3 flex gap-4 overflow-x-auto pb-4">
                        @foreach ($options as $o)
                            <figure data-mockup-frame="{{ $o }}" @if ($o === $chosen) data-chosen @endif class="shrink-0"
                                x-show="mode === 'side' || current === @js($o)">
                                <figcaption class="mb-1 flex items-center gap-2 text-sm">
                                    <span class="font-mono font-semibold">option-{{ $o }}</span>
                                    @if ($o === $chosen)<span class="rounded bg-built px-1.5 text-xs font-medium text-white">chosen</span>@endif
                                    <a href="{{ $src($o) }}" target="_blank" rel="noopener" class="ml-auto text-xs text-zinc-500 underline dark:text-zinc-400">open ↗</a>
                                </figcaption>
                                <div class="overflow-hidden rounded-lg bg-white {{ $o === $chosen ? 'ring-2 ring-built' : 'ring-1 ring-zinc-200 dark:ring-zinc-800' }}">
                                    <iframe src="{{ $src($o) }}" sandbox="allow-scripts" loading="lazy" title="Option {{ $o }}"
                                        class="mockup-frame" x-bind:style="`width: ${width}px`"></iframe>
                                </div>
                            </figure>
                        @endforeach
                    </div>

                    {{-- Compare: any two options, full window, 50/50. Esc or × closes. --}}
                    {{-- x-if, not x-show: the two frames (two git reads) are created only while the overlay is open.
                         x-trap moves focus in on open and hands it back to "Compare two…" on close. --}}
                    <template x-teleport="body">
                        <template x-if="compare">
                        <div x-trap.noscroll="compare" x-transition.opacity role="dialog" aria-modal="true" aria-label="Compare two mockups"
                            class="fixed inset-0 z-50 flex flex-col bg-zinc-100 dark:bg-zinc-950">
                            <div class="flex flex-wrap items-center gap-2 border-b border-zinc-200 bg-white px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900">
                                <span class="text-sm font-semibold">Compare · {{ $story->story_id }}</span>
                                <label class="sr-only" for="cmp-left">Left option</label>
                                <select id="cmp-left" x-model="left" class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                                    <template x-for="o in options" :key="o"><option :value="o" x-text="'Option ' + o.toUpperCase()"></option></template>
                                </select>
                                <span class="text-sm text-zinc-500">vs</span>
                                <label class="sr-only" for="cmp-right">Right option</label>
                                <select id="cmp-right" x-model="right" class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                                    <template x-for="o in options" :key="o"><option :value="o" x-text="'Option ' + o.toUpperCase()"></option></template>
                                </select>
                                <button type="button" x-on:click="[left, right] = [right, left]" class="rounded-md border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-700" aria-label="Swap sides">⇄</button>
                                <div class="flex gap-1" role="group" aria-label="Frame width">
                                    @foreach ([375, 768, 1280] as $w)
                                        <button type="button" x-on:click="width = {{ $w }}" x-bind:aria-pressed="(width === {{ $w }}).toString()" class="{{ $toggle }} tabular-nums">{{ $w }}</button>
                                    @endforeach
                                </div>
                                <button type="button" x-on:click="compare = false" class="ml-auto rounded-md border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-700" aria-label="Close compare">× Close</button>
                            </div>
                            <div class="grid min-h-0 flex-1 grid-cols-2 gap-2 p-2">
                                <template x-for="side in [left, right]">
                                    <div class="flex min-h-0 flex-col">
                                        <p class="mb-1 font-mono text-xs"><span x-text="'option-' + side"></span><span x-show="side === @js($chosen)" class="ml-1 rounded bg-built px-1 text-white">chosen</span></p>
                                        <div class="min-h-0 flex-1 overflow-auto rounded-lg bg-white ring-1 ring-zinc-200 dark:ring-zinc-800">
                                            <iframe x-bind:src="@js($story->mockupUrl('__FILE__')).replace('__FILE__', 'option-' + side + '.html')"
                                                sandbox="allow-scripts" title="Compared option" class="h-full border-0" x-bind:style="`width: ${width}px`"></iframe>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                        </template>
                    </template>
                @endif
            </section>
        @endif
    </main>
</div>

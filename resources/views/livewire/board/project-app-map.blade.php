{{--
    SB-24 app map, option A — the storyboard strip (docs/mockups/SB-24/option-a.html) inside the design-A shell. A flow
    picker (All flows, then one pill per journey) over either the overview — every journey stacked as a lane of
    screens, shared screens (same route) wearing a lettered ring that lights in every lane on hover, and a shared-screens
    list beside — or one flow: a large stage with Back/Next (← → too) and full screen, its step card, and the numbered
    strip of its screens joined by arrows.

    Every journey and step is in this markup once, rendered by the server. The flow picker, stepping, full screen and
    hover are Alpine (resources/js/app-map.js); a flow's stage, card and strip sit in x-if templates so only the open
    flow's frames exist in the DOM, and nothing on this page calls the server after it loads.
--}}
@php
    $total = array_sum(array_column($journeys, 'total'));
    $built = array_sum(array_column($journeys, 'built'));
    $byRoute = collect($shared)->keyBy('route');
    // One ring colour per shared screen, cycling through the chart palette; the letter tells them apart.
    $rings = ['ring-series-1', 'ring-series-2', 'ring-series-3'];
    $badges = ['bg-series-1', 'bg-series-2', 'bg-series-3'];
    $tone = fn (?string $letter) => $letter === null ? 0 : (ord(substr($letter, -1)) - 65) % 3;
    $showing = fn (array $s) => match ($s['picture']['kind']) {
        'shot' => 'The journey test\'s shot of this screen',
        'placeholder' => 'Built · a drawn placeholder (no journey shot yet)',
        'mockup' => 'Chosen mockup: option '.strtoupper((string) $s['picture']['option']),
        'awaiting' => 'Mockups exist, awaiting your pick',
        'mockups' => 'Mockups exist, no pick the board can read',
        'unlinked' => 'Nothing: the step names no story',
        default => 'No mockup yet',
    };
    $arrow = '<svg class="size-4 shrink-0 text-zinc-300 dark:text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
@endphp
<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-10 lg:py-10"
    x-data="appMap(@js(array_column($journeys, 'total')))" x-on:keydown.window="key($event)">

    <x-board.project-header :model="$model" page="App map" current="map">
        @if ($journeys !== [])
            · <span class="tabular-nums">{{ count($journeys) }}</span> {{ Str::plural('journey', count($journeys)) }}
            · <span class="tabular-nums">{{ $built }}</span> of <span class="tabular-nums">{{ $total }}</span> steps built
        @endif
        · journeys read from the ref, shots from the journey tests
    </x-board.project-header>

    @if ($unreadable)
        <div role="status" data-unreadable class="mt-5 flex items-start gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm dark:border-zinc-800 dark:bg-zinc-900">
            <svg class="mt-0.5 size-4 shrink-0 text-warning" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l9.5 17h-19L12 3z"/><path d="M12 10v4M12 17h.01"/></svg>
            <p><span class="font-medium">The journeys could not be read from git.</span> The snapshot commit is not in {{ $model->name }}'s checkout. Refresh the project from its dashboard, then reload this page.</p>
        </div>
    @endif

    @if ($unparsed !== [])
        <div role="status" data-unparsed class="mt-5 flex items-start gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm dark:border-zinc-800 dark:bg-zinc-900">
            <svg class="mt-0.5 size-4 shrink-0 text-warning" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l9.5 17h-19L12 3z"/><path d="M12 10v4M12 17h.01"/></svg>
            <div>
                <p><span class="font-medium">{{ count($unparsed) }} {{ Str::plural('journey', count($unparsed)) }} could not be read</span> and {{ count($unparsed) === 1 ? 'is' : 'are' }} not drawn. No numbered flow steps and no <span class="font-mono text-xs">| Step |</span> stories table were found. The file is left as it is.</p>
                <ul class="mt-1 font-mono text-xs text-zinc-500 dark:text-zinc-400">
                    @foreach ($unparsed as $file)
                        <li>{{ $file }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @if ($journeys === [])
        @unless ($unreadable)
            <div data-empty class="mt-6 rounded-xl border border-dashed border-zinc-300 bg-white px-4 py-10 text-center sm:px-6 dark:border-zinc-700 dark:bg-zinc-900">
                <svg class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="4" width="7" height="6" rx="1"/><rect x="14" y="14" width="7" height="6" rx="1"/><path d="M10 7h4a2 2 0 012 2v5"/></svg>
                <p class="mt-3 font-medium">{{ $model->name }} has no journeys yet.</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-zinc-500 dark:text-zinc-400">
                    The map is drawn from <span class="font-mono text-xs">docs/journeys/&lt;slug&gt;.md</span> at the ref.
                    <span class="font-mono text-xs">/story</span> writes a journey when a brain dump describes an end-to-end flow; its steps appear here as screens.
                </p>
            </div>
        @endunless
    @else
        <div class="mt-5 space-y-4">
            {{-- Flow picker: All flows, then one pill per journey with its built/total. Scrolls inside itself on a phone. --}}
            <div role="tablist" aria-label="Flows" class="flex max-w-full gap-1 overflow-x-auto rounded-lg border border-zinc-200 bg-white p-0.5 sm:w-fit dark:border-zinc-800 dark:bg-zinc-900">
                @foreach ([['all', -1, 'All flows', (string) count($journeys)], ...array_map(fn ($jn, $k) => [$jn['slug'], $k, $jn['name'], $jn['built'].'/'.$jn['total']], $journeys, array_keys($journeys))] as [$key, $k, $label, $sub])
                    <button type="button" role="tab" data-flow="{{ $key }}" x-on:click="show({{ $k }})"
                        x-bind:aria-selected="j === {{ $k }}" aria-selected="{{ $k === -1 ? 'true' : 'false' }}"
                        class="shrink-0 rounded-md px-2.5 py-1 text-xs font-medium whitespace-nowrap"
                        x-bind:class="j === {{ $k }} ? 'bg-accent text-accent-foreground' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'">
                        {{ $label }} <span class="tabular-nums opacity-60">{{ $sub }}</span>
                    </button>
                @endforeach
            </div>

            <ul aria-label="Legend" class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-4 rounded-sm border border-zinc-300 bg-zinc-200 dark:border-zinc-700 dark:bg-zinc-700"></span>Built · shot or placeholder</li>
                <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-4 rounded-sm border border-dashed border-pick bg-pick/30"></span>Pending · chosen mockup</li>
                <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-4 rounded-sm border border-dashed border-warning bg-warning/20"></span>Pending · awaiting pick</li>
                <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-4 rounded-sm border border-dashed border-zinc-300 dark:border-zinc-600"></span>No mockup yet · not linked</li>
            </ul>

            {{-- All flows: every journey as a lane; shared screens ringed and lettered, lit across lanes on hover. --}}
            <div data-overview x-show="j === -1" class="grid items-start gap-4 xl:grid-cols-[1fr_22rem]">
                <div class="min-w-0 space-y-3">
                    @foreach ($journeys as $k => $journey)
                        <section data-journey="{{ $journey['slug'] }}" class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <h2 class="text-sm font-medium">{{ $journey['name'] }}</h2>
                                <span class="text-xs text-zinc-500 tabular-nums dark:text-zinc-400">{{ $journey['built'] }}/{{ $journey['total'] }} built</span>
                                <div class="h-1.5 w-24 overflow-hidden rounded-full bg-draft/30" aria-hidden="true">
                                    <div class="h-full bg-built" style="width: {{ $journey['total'] ? round($journey['built'] / $journey['total'] * 100) : 0 }}%"></div>
                                </div>
                                <button type="button" x-on:click="show({{ $k }})" class="ml-auto text-xs text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">Step through →</button>
                            </div>
                            <ol class="mt-2 flex items-center gap-1 overflow-x-auto pt-2 pb-1">
                                @foreach ($journey['steps'] as $s => $step)
                                    <li data-step="{{ $journey['slug'] }}/{{ $step['n'] }}" data-story="{{ $step['story'] }}" data-state="{{ $step['state'] }}" @if ($step['shared']) data-shared="{{ $step['shared'] }}" @endif
                                        class="flex shrink-0 items-center gap-1">
                                        <button type="button" x-on:click="jump({{ $k }}, {{ $s }})"
                                            @if ($step['shared']) x-on:mouseenter="light(@js($step['route']))" x-on:mouseleave="light(null)" x-on:focus="light(@js($step['route']))" x-on:blur="light(null)" @endif
                                            x-bind:class="{ 'opacity-35': dim(@js($step['route'])) }"
                                            title="{{ $step['name'] }}{{ $step['story'] ? ' · '.$step['story'] : '' }}"
                                            @class(['relative w-28 rounded-md p-1 text-left transition-opacity hover:bg-zinc-50 sm:w-32 dark:hover:bg-zinc-800', 'ring-2 '.$rings[$tone($step['shared'])] => $step['shared']])>
                                            <x-board.map-screen :step="$step" />
                                            @if ($step['shared'])
                                                <span class="absolute -top-1.5 -right-1.5 grid size-5 place-items-center rounded-full {{ $badges[$tone($step['shared'])] }} text-xs font-bold text-white shadow" title="Shared screen {{ $step['shared'] }}">{{ $step['shared'] }}</span>
                                            @endif
                                            <span class="mt-1 flex items-center gap-1 text-xs">
                                                <span class="text-zinc-400 tabular-nums">{{ $step['n'] }}</span>
                                                <span class="truncate font-mono text-zinc-500 dark:text-zinc-400">{{ $step['story'] ?? '—' }}</span>
                                                <span @class(['ml-auto size-1.5 shrink-0 rounded-full', 'bg-built' => $step['state'] === 'built', 'bg-draft' => $step['state'] === 'pending', 'bg-cancelled' => $step['state'] === 'unlinked'])></span>
                                            </span>
                                            <span class="block truncate text-xs leading-tight">{{ $step['name'] }}</span>
                                            @if ($step['unlinked'])
                                                <span data-unlinked="{{ $step['unlinked'] }}" class="mt-0.5 block text-xs text-warning" title="{{ $step['unlinked'] === 'story' ? 'The journey doc names no story for this step' : 'Neither the journey doc nor the story names a route' }}">Not linked · no {{ $step['unlinked'] }}</span>
                                            @endif
                                        </button>
                                        @unless ($loop->last) {!! $arrow !!} @endunless
                                    </li>
                                @endforeach
                            </ol>
                        </section>
                    @endforeach
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">A lettered ring marks a screen more than one flow passes through (the same route). Hover one to light it in every flow; click any screen to step through its flow from there.</p>
                </div>

                <section data-shared-list class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    <h2 class="px-4 pt-3 text-xs font-semibold tracking-wider text-zinc-400 uppercase">Shared screens · {{ count($shared) }}</h2>
                    @if ($shared === [])
                        <p class="px-4 pt-1 pb-3 text-sm text-zinc-500 dark:text-zinc-400">No screen appears in more than one flow.</p>
                    @else
                        <ul class="mt-1 divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                            @foreach ($shared as $screen)
                                @php $slugs = array_column($journeys, 'slug'); @endphp
                                <li data-shared-route="{{ $screen['route'] }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5"
                                    x-on:mouseenter="light(@js($screen['route']))" x-on:mouseleave="light(null)"
                                    x-bind:class="{ 'bg-zinc-50 dark:bg-zinc-800/60': hot === @js($screen['route']) }">
                                    <span class="grid size-5 place-items-center rounded-full {{ $badges[$tone($screen['letter'])] }} text-xs font-bold text-white">{{ $screen['letter'] }}</span>
                                    <span class="font-mono text-xs break-all">{{ $screen['route'] }}</span>
                                    <span class="flex flex-wrap gap-1">
                                        @foreach ($screen['uses'] as $use)
                                            <button type="button" x-on:click="jump({{ array_search($use['slug'], $slugs, true) }}, {{ $use['n'] - 1 }})"
                                                class="rounded-md border border-zinc-200 px-1.5 py-0.5 text-xs hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">{{ $use['journey'] }} · {{ $use['n'] }} <span class="font-mono text-zinc-500 dark:text-zinc-400">{{ $use['story'] }}</span></button>
                                        @endforeach
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>

            {{-- One flow: the stage (full screen on demand), its step card, and the storyboard strip. Only the open flow is in the DOM. --}}
            @foreach ($journeys as $k => $journey)
                <template x-if="j === {{ $k }}">
                    <div data-journey-view="{{ $journey['slug'] }}" class="space-y-4">
                        <section aria-label="Stage" class="grid gap-4 lg:grid-cols-[1fr_19rem]">
                            <div class="min-w-0">
                                <div class="mb-2 flex items-center gap-3 text-xs text-zinc-500 dark:text-zinc-400">
                                    <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $journey['name'] }}</span>
                                    <span class="tabular-nums">{{ $journey['built'] }} of {{ $journey['total'] }} built</span>
                                    <div class="flex max-w-xs flex-1 gap-1">
                                        @foreach ($journey['steps'] as $s => $step)
                                            <button type="button" x-on:click="i = {{ $s }}" aria-label="Step {{ $step['n'] }}: {{ $step['name'] }}"
                                                @class(['h-1.5 flex-1 rounded-full', 'bg-built' => $step['state'] === 'built', 'bg-draft' => $step['state'] === 'pending', 'bg-cancelled' => $step['state'] === 'unlinked'])
                                                x-bind:class="i === {{ $s }} ? '' : 'opacity-40 hover:opacity-70'"></button>
                                        @endforeach
                                    </div>
                                </div>

                                {{-- The stage becomes the full-screen viewer in place (Escape or Close leaves it): one large frame, never two. --}}
                                <div data-stage x-bind:role="lb ? 'dialog' : null" x-bind:aria-modal="lb ? 'true' : null" aria-label="{{ $journey['name'] }}, screen by screen"
                                    x-bind:class="lb ? 'fixed inset-0 z-50 flex flex-col gap-3 bg-zinc-950/90 p-3 sm:p-8' : 'group relative'">
                                    <div x-show="lb" x-cloak class="flex items-center gap-3 text-sm text-white">
                                        @foreach ($journey['steps'] as $s => $step)
                                            <template x-if="i === {{ $s }}">
                                                <p class="min-w-0 truncate font-medium">{{ $journey['name'] }} · step {{ $step['n'] }} of {{ $journey['total'] }} · {{ $step['name'] }} <span class="font-mono text-white/60">{{ $step['story'] }}</span></p>
                                            </template>
                                        @endforeach
                                        <button type="button" data-lightbox-close x-on:click="close()" class="ml-auto rounded-lg px-2 py-1 hover:bg-white/10" aria-label="Close full-size view">Close <kbd class="ml-1 text-xs opacity-60">Esc</kbd></button>
                                    </div>
                                    <div x-bind:class="lb ? 'grid min-h-0 flex-1 place-items-center' : ''">
                                        <div x-bind:class="lb ? 'w-full max-w-6xl' : ''">
                                            @foreach ($journey['steps'] as $s => $step)
                                                <template x-if="i === {{ $s }}">
                                                    <button type="button" data-open-large data-stage-step="{{ $journey['slug'] }}/{{ $step['n'] }}" x-on:click="open()"
                                                        class="block w-full text-left" aria-label="Open {{ $step['name'] }} full size">
                                                        <x-board.map-screen :step="$step" size="lg" class="shadow-sm" />
                                                    </button>
                                                </template>
                                            @endforeach
                                        </div>
                                    </div>
                                    <button type="button" x-show="!lb && !atFirst" x-on:click="go(-1)" aria-label="Previous step"
                                        class="absolute top-1/2 left-2 grid size-9 -translate-y-1/2 place-items-center rounded-full bg-white/90 shadow ring-1 ring-zinc-200 hover:bg-white dark:bg-zinc-800/90 dark:ring-zinc-700">‹</button>
                                    <button type="button" x-show="!lb && !atLast" x-on:click="go(1)" aria-label="Next step"
                                        class="absolute top-1/2 right-2 grid size-9 -translate-y-1/2 place-items-center rounded-full bg-white/90 shadow ring-1 ring-zinc-200 hover:bg-white dark:bg-zinc-800/90 dark:ring-zinc-700">›</button>
                                    <div x-show="lb" x-cloak class="flex items-center justify-center gap-3">
                                        <button type="button" data-lightbox-back x-on:click="go(-1)" x-bind:disabled="atFirst" class="rounded-lg bg-white/10 px-4 py-2 text-sm text-white hover:bg-white/20 disabled:opacity-40">← Back</button>
                                        <span class="flex gap-1.5" aria-hidden="true">
                                            @foreach ($journey['steps'] as $s => $step)
                                                <span @class(['size-2 rounded-full', 'bg-built' => $step['state'] === 'built', 'bg-draft' => $step['state'] === 'pending', 'bg-cancelled' => $step['state'] === 'unlinked'])
                                                    x-bind:class="i === {{ $s }} ? 'ring-2 ring-white ring-offset-2 ring-offset-zinc-900' : 'opacity-50'"></span>
                                            @endforeach
                                        </span>
                                        <button type="button" data-lightbox-next x-on:click="go(1)" x-bind:disabled="atLast" class="rounded-lg bg-white px-4 py-2 text-sm font-medium text-zinc-900 hover:bg-zinc-200 disabled:opacity-40">Next →</button>
                                    </div>
                                </div>
                            </div>

                            {{-- The step card: what is on the stage, where it lives, what it shows, and Back/Next. --}}
                            <div data-step-card class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-4 text-sm dark:border-zinc-800 dark:bg-zinc-900">
                                @foreach ($journey['steps'] as $s => $step)
                                    @php $also = collect($byRoute->get($step['route'])['uses'] ?? [])->where('slug', '!=', $journey['slug'])->values(); @endphp
                                    <template x-if="i === {{ $s }}">
                                        <div class="flex flex-col gap-3">
                                            <div class="flex items-center justify-between gap-2">
                                                <p class="text-xs text-zinc-500 tabular-nums dark:text-zinc-400">Step {{ $step['n'] }} of {{ $journey['total'] }}</p>
                                                @if ($step['state'] === 'built')
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-built/10 px-2 py-0.5 text-xs font-medium text-gain ring-1 ring-built/30 ring-inset"><span class="size-1.5 rounded-full bg-built"></span>Built</span>
                                                @elseif ($step['state'] === 'pending')
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-draft/10 px-2 py-0.5 text-xs font-medium text-warning ring-1 ring-draft/40 ring-inset"><span class="size-1.5 rounded-full bg-draft"></span>Pending{{ $step['status'] ? ' · '.$step['status'] : '' }}</span>
                                                @else
                                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium text-zinc-500 ring-1 ring-zinc-300 ring-inset dark:ring-zinc-700">Not linked</span>
                                                @endif
                                            </div>
                                            <div>
                                                <h2 class="text-base leading-snug font-semibold">{{ $step['name'] }}</h2>
                                                @if ($step['story'])
                                                    <p class="mt-1">
                                                        @if ($step['link'])
                                                            <a href="{{ $step['link'] }}" wire:navigate class="font-mono text-xs font-medium underline decoration-zinc-300 underline-offset-4 hover:decoration-current dark:decoration-zinc-600">{{ $step['story'] }}</a>
                                                        @else
                                                            <span class="font-mono text-xs font-medium">{{ $step['story'] }}</span>
                                                        @endif
                                                        @if ($step['title']) <span class="text-zinc-500 dark:text-zinc-400">· {{ $step['title'] }}</span> @endif
                                                    </p>
                                                @endif
                                            </div>
                                            <dl class="grid grid-cols-[4.5rem_1fr] gap-x-2 gap-y-1.5 text-xs">
                                                <dt class="text-zinc-500 dark:text-zinc-400">Route</dt>
                                                <dd class="font-mono break-all">{{ $step['route'] ?? 'not named' }}</dd>
                                                <dt class="text-zinc-500 dark:text-zinc-400">Showing</dt>
                                                <dd>{{ $showing($step) }}</dd>
                                                @if ($step['picture']['captured_at'])
                                                    <dt class="text-zinc-500 dark:text-zinc-400">Captured</dt>
                                                    <dd>{{ $step['picture']['captured_at']->utc()->format('M j, Y H:i') }} UTC</dd>
                                                @endif
                                                @if ($step['also'] !== [])
                                                    <dt class="text-zinc-500 dark:text-zinc-400">Also</dt>
                                                    <dd class="font-mono">{{ implode(', ', $step['also']) }}</dd>
                                                @endif
                                                <dt class="text-zinc-500 dark:text-zinc-400">Also in</dt>
                                                <dd>
                                                    @forelse ($also as $use)
                                                        <button type="button" x-on:click="jump({{ array_search($use['slug'], array_column($journeys, 'slug'), true) }}, {{ $use['n'] - 1 }})"
                                                            class="block text-left underline decoration-zinc-300 underline-offset-4 hover:decoration-current dark:decoration-zinc-600">{{ $use['journey'] }} · step {{ $use['n'] }}</button>
                                                    @empty
                                                        <span class="text-zinc-400">no other flow</span>
                                                    @endforelse
                                                </dd>
                                            </dl>
                                            @if ($step['unlinked'])
                                                <p class="rounded-lg border border-zinc-200 px-3 py-2 text-xs text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                                                    <span class="font-medium text-warning">Not linked.</span>
                                                    {{ $step['unlinked'] === 'story' ? 'The journey doc names no story for this step.' : 'Neither the journey doc nor '.$step['story'].'\'s Routes line names a route, so it cannot be matched to shared screens or shots.' }}
                                                </p>
                                            @endif
                                            @if (in_array($step['picture']['kind'], ['awaiting', 'mockup', 'mockups'], true))
                                                <a href="{{ $step['picture']['gallery'] }}" wire:navigate @if ($step['picture']['kind'] === 'awaiting') data-awaiting-link @endif
                                                    @class(['rounded-lg border px-3 py-2 text-xs font-medium',
                                                        'border-warning/60 bg-warning/10 text-warning hover:bg-warning/20' => $step['picture']['kind'] === 'awaiting',
                                                        'border-zinc-200 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800' => $step['picture']['kind'] !== 'awaiting'])>
                                                    {{ $step['picture']['kind'] === 'awaiting' ? 'Awaiting pick · pick a mockup for '.$step['story'].' →' : 'Open '.$step['story'].'\'s mockups →' }}
                                                </a>
                                            @endif
                                        </div>
                                    </template>
                                @endforeach
                                <div class="mt-auto flex items-center gap-2 pt-1">
                                    <button type="button" data-back x-on:click="go(-1)" x-bind:disabled="atFirst" class="flex-1 rounded-lg border border-zinc-200 px-3 py-1.5 text-sm hover:bg-zinc-50 disabled:opacity-40 dark:border-zinc-700 dark:hover:bg-zinc-800">← Back</button>
                                    <button type="button" data-next x-on:click="go(1)" x-bind:disabled="atLast" class="flex-1 rounded-lg bg-accent px-3 py-1.5 text-sm font-medium text-accent-foreground disabled:opacity-40">Next →</button>
                                </div>
                                <p class="text-xs break-all text-zinc-400">← → keys step through @if ($journey['test']) · <span class="font-mono">{{ $journey['test'] }}</span> @endif</p>
                            </div>
                        </section>

                        <section aria-label="Storyboard">
                            <h2 class="mb-2 text-xs font-semibold tracking-wider text-zinc-400 uppercase">Storyboard · {{ $journey['total'] }} {{ Str::plural('screen', $journey['total']) }} in order</h2>
                            <ol data-strip class="relative flex snap-x items-start gap-1.5 overflow-x-auto p-1 pb-2">
                                @foreach ($journey['steps'] as $s => $step)
                                    <li class="flex shrink-0 snap-start items-center gap-1.5">
                                        <button type="button" data-strip-step="{{ $journey['slug'] }}/{{ $step['n'] }}" x-on:click="i = {{ $s }}" x-bind:aria-current="i === {{ $s }} ? 'true' : 'false'"
                                            class="w-40 rounded-lg p-1.5 text-left sm:w-44"
                                            x-bind:class="i === {{ $s }} ? 'bg-white ring-2 ring-zinc-800 dark:bg-zinc-900 dark:ring-white' : 'hover:bg-white dark:hover:bg-zinc-900'">
                                            <x-board.map-screen :step="$step" />
                                            <span class="mt-1.5 flex items-center gap-1.5 text-xs">
                                                <span class="grid size-4 place-items-center rounded-full bg-zinc-200 text-xs font-semibold tabular-nums dark:bg-zinc-700">{{ $step['n'] }}</span>
                                                <span class="font-mono text-zinc-500 dark:text-zinc-400">{{ $step['story'] ?? '—' }}</span>
                                                <span @class(['ml-auto size-1.5 rounded-full', 'bg-built' => $step['state'] === 'built', 'bg-draft' => $step['state'] === 'pending', 'bg-cancelled' => $step['state'] === 'unlinked'])></span>
                                            </span>
                                            <span class="mt-0.5 line-clamp-2 block text-xs leading-snug font-medium">{{ $step['name'] }}</span>
                                        </button>
                                        @unless ($loop->last) {!! $arrow !!} @endunless
                                    </li>
                                @endforeach
                            </ol>
                        </section>
                    </div>
                </template>
            @endforeach
        </div>
    @endif
</main>

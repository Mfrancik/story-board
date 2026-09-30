{{--
    SB-15 stories by initiative, option C (docs/mockups/SB-15/option-c.html) inside the design-A shell:
    status filter chips and Expand all above two panes — initiatives with their full counts on the left
    (a select below lg), the chosen initiative's stories on the right. Expand all turns the right pane into
    every initiative, grouped, under sticky headers; an initiative on the left then jumps to its group.

    Every story of the project is in this markup once, and nothing here calls the server: selection,
    filters and Expand all are Alpine. At coins' size (946 rows) the rows carry no Alpine of their own:
    a filter chip toggles one `hide-<kind>` class on the list and each row's `[.hide-<kind>_&]:hidden`
    variant does the hiding in CSS, and one delegated click on the list opens the SB-8 modal. Only the
    initiatives (at most ~60) are Alpine-bound. The page arrives complete, so no loading state is needed:
    groups other than the first are hidden in the markup itself, not after Alpine starts.
--}}
@php
    $kinds = \App\Actions\Board\ReadProjectStories::KINDS;
    $labels = ['built' => 'Built', 'approved' => 'To do', 'draft' => 'Draft', 'cancelled' => 'Cancelled', 'other' => 'Other'];
    $dots = ['built' => 'bg-built', 'approved' => 'bg-approved', 'draft' => 'bg-draft', 'cancelled' => 'bg-cancelled', 'other' => 'bg-zinc-300 dark:bg-zinc-600'];
    // Literal class names, one per kind, so Tailwind's scanner of resources/views generates each variant.
    $hide = [
        'built' => '[.hide-built_&]:hidden',
        'approved' => '[.hide-approved_&]:hidden',
        'draft' => '[.hide-draft_&]:hidden',
        'cancelled' => '[.hide-cancelled_&]:hidden',
        'other' => '[.hide-other_&]:hidden',
    ];
    // A count's words under a group's name: the kit's statuses in the page's vocabulary, anything else raw.
    $countWords = ['built' => 'built', 'approved' => 'to do', 'draft' => 'draft', 'cancelled' => 'cancelled', '(none)' => 'no status'];
    $groupCount = count($initiatives);
    $first = $initiatives[0]['key'] ?? null;
    // What Alpine needs per initiative: its name for the select, its counts by kind to know when the
    // filters have hidden every row. The rows themselves stay in the markup.
    $meta = array_map(fn ($g) => [
        'key' => $g['key'],
        'label' => ($g['name'] ?? 'No initiative').($g['parked'] ? ' (parked)' : '').' — '.($g['open'] > 0 ? $g['open'].' open / ' : '').$g['total'],
        'total' => $g['total'],
        'kinds' => $g['kinds'],
    ], $initiatives);
@endphp
<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-10 lg:py-10"
    x-data="{
        groups: @js($meta),
        on: @js(array_fill_keys($kinds, true)),
        sel: @js($first),
        all: false,
        count(g) { return Object.entries(g.kinds).reduce((n, [k, c]) => n + (this.on[k] ? c : 0), 0) },
        group(key) { return this.groups.find(g => g.key === key) },
        get visible() { return this.groups.filter(g => this.count(g) > 0) },
        shown(key) { return this.count(this.group(key)) > 0 },
        hiddenIn(key) { const g = this.group(key); return g.total - this.count(g) },
        // The chosen initiative, or the first one left when the filters have hidden it.
        get current() { return this.shown(this.sel) ? this.sel : (this.visible[0]?.key ?? null) },
        open(key) { return this.shown(key) && (this.all || this.current === key) },
        get filtered() { return Object.values(this.on).some(v => ! v) },
        showAll() { for (const k in this.on) this.on[k] = true },
        choose(key) {
            this.sel = key;
            const target = this.all ? 'stories-' + key : 'stories-pane';
            // Expanded: jump to the group. One at a time on a narrow screen: bring the list into view.
            if (this.all || innerWidth < 1024) this.$nextTick(() => document.getElementById(target)?.scrollIntoView({ block: 'start' }));
        },
        openStory(event) {
            const row = event.target.closest('[data-story-link]');
            if (row) this.$dispatch('board-story', row.dataset.storyLink);
        },
    }">

    <x-board.project-header :model="$model" page="Stories" current="stories">
        @if ($stories > 0)
            · {{ number_format($stories) }} {{ Str::plural('story', $stories) }} in {{ $groupCount }} {{ Str::plural('initiative', $groupCount) }}
        @endif
    </x-board.project-header>

    {{-- SB-8: a story row opens here, and `?story=<project>/<ID>` opens straight in on this page too. --}}
    <livewire:board.story-modal />

    @if ($stories === 0)
        <div data-empty class="mt-6 rounded-xl border border-dashed border-zinc-300 bg-white px-6 py-14 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <svg class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>
            <p class="mt-3 font-medium">{{ $model->name }} has no stories on {{ $model->ref }} yet.</p>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Stories appear here once they are committed under <span class="font-mono text-xs">stories/</span> on the project's ref.</p>
        </div>
    @else
        {{-- Status filter chips and Expand all. Chips hide rows; the counts beside them and on the left never change. --}}
        <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
            <div role="group" aria-label="Filter by status" class="flex flex-wrap items-center gap-1.5">
                @foreach ($kinds as $kind)
                    <button type="button" data-filter="{{ $kind }}" x-on:click="on.{{ $kind }} = ! on.{{ $kind }}" x-bind:aria-pressed="on.{{ $kind }}" aria-pressed="true"
                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset"
                        x-bind:class="on.{{ $kind }} ? 'bg-white text-zinc-800 ring-zinc-300 dark:bg-zinc-900 dark:text-zinc-100 dark:ring-zinc-700' : 'text-zinc-400 line-through ring-zinc-200 dark:ring-zinc-800'">
                        <span class="size-2 rounded-full" x-bind:class="on.{{ $kind }} ? @js($dots[$kind]) : 'bg-zinc-200 dark:bg-zinc-700'"></span>
                        {{ $labels[$kind] }}
                        <span data-tally class="tabular-nums text-zinc-400">{{ number_format($tallies[$kind]) }}</span>
                    </button>
                @endforeach
                <button type="button" data-show-all-filters x-show="filtered" x-cloak x-on:click="showAll()"
                    class="ml-1 text-xs text-zinc-500 underline underline-offset-4 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200">Show all</button>
            </div>
            <div class="flex items-center gap-3">
                <p data-initiatives-shown class="text-xs text-zinc-500 tabular-nums dark:text-zinc-400"><span x-text="visible.length">{{ $groupCount }}</span> of {{ $groupCount }} initiatives</p>
                <button type="button" data-expand-all x-on:click="all = ! all" x-bind:aria-expanded="all" aria-expanded="false" aria-controls="stories-pane"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm font-medium hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path x-show="! all" d="M7 9l5-5 5 5M7 15l5 5 5-5"/><path x-show="all" x-cloak d="M7 4l5 5 5-5M7 20l5-5 5 5"/></svg>
                    <span x-text="all ? 'Collapse all' : 'Expand all'">Expand all</span>
                </button>
            </div>
        </div>

        <div x-show="visible.length === 0" x-cloak data-all-filtered class="mt-4 rounded-xl border border-dashed border-zinc-300 px-6 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            No stories match these filters. <button type="button" x-on:click="showAll()" class="font-medium text-zinc-800 underline underline-offset-4 dark:text-white">Show all</button>
        </div>

        <div x-show="visible.length > 0" class="mt-4 items-start gap-4 lg:grid lg:grid-cols-[17rem_minmax(0,1fr)]">
            {{-- Below lg the initiative list is a select. --}}
            <div class="mb-3 lg:hidden">
                <label for="initiative-select" class="sr-only">Initiative</label>
                <select id="initiative-select" data-initiative-select x-on:change="choose($event.target.value)"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <template x-for="g in visible" :key="g.key">
                        <option :value="g.key" :selected="g.key === current" x-text="g.label"></option>
                    </template>
                </select>
            </div>

            <nav aria-label="Initiatives" class="hidden rounded-xl border border-zinc-200 bg-white py-1 lg:sticky lg:top-4 lg:block lg:max-h-[calc(100vh-2rem)] lg:overflow-y-auto dark:border-zinc-800 dark:bg-zinc-900">
                <p class="flex justify-between px-3 pt-1.5 pb-1 text-xs font-semibold tracking-wider text-zinc-400 uppercase"><span>Initiative</span><span>open / all</span></p>
                <ul>
                    @foreach ($initiatives as $g)
                        <li x-show="shown(@js($g['key']))">
                            <button type="button" data-initiative-entry="{{ $g['name'] }}" x-on:click="choose(@js($g['key']))"
                                x-bind:aria-current="! all && current === @js($g['key']) ? 'true' : 'false'"
                                class="w-full border-l-2 px-3 py-1.5 text-left text-sm"
                                x-bind:class="! all && current === @js($g['key']) ? 'border-zinc-800 bg-zinc-100 font-medium dark:border-white dark:bg-zinc-800' : 'border-transparent hover:bg-zinc-50 dark:hover:bg-zinc-800/50'">
                                <span class="flex items-center gap-2">
                                    @if ($g['name'] === null)
                                        <span class="truncate text-zinc-500 italic dark:text-zinc-400">No initiative</span>
                                    @else
                                        <span class="truncate">{{ $g['name'] }}</span>
                                    @endif
                                    @if ($g['parked'])
                                        <span data-parked title="Its README says Status: draft group" class="shrink-0 rounded bg-cancelled/15 px-1.5 text-xs text-zinc-700 ring-1 ring-cancelled/40 ring-inset dark:text-zinc-300">parked</span>
                                    @endif
                                    {{-- Full counts, rendered once: the filters never change them. --}}
                                    <span data-initiative-counts class="ml-auto text-xs whitespace-nowrap text-zinc-400 tabular-nums">@if ($g['open'] > 0)<span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $g['open'] }} open</span> / @endif{{ $g['total'] }}</span>
                                </span>
                                <x-board.status-bar :counts="$g['counts']" variant="tag" class="mt-1 h-1" />
                            </button>
                        </li>
                    @endforeach
                </ul>
            </nav>

            {{-- One list of every group. Collapsed, only the chosen group shows; expanded, every group with rows left. --}}
            <div id="stories-pane" data-story-list class="min-w-0 scroll-mt-16 overflow-clip rounded-xl border border-zinc-200 bg-white md:scroll-mt-4 dark:border-zinc-800 dark:bg-zinc-900"
                x-bind:class="{ @foreach ($kinds as $kind)'hide-{{ $kind }}': ! on.{{ $kind }}, @endforeach }"
                x-on:click="openStory($event)">
                @foreach ($initiatives as $g)
                    <section id="stories-{{ $g['key'] }}" data-group="{{ $g['name'] }}" aria-label="{{ $g['name'] ?? 'No initiative' }}"
                        x-show="open(@js($g['key']))" @if ($g['key'] !== $first) style="display: none" @endif
                        class="scroll-mt-16 md:scroll-mt-4">
                        <div class="z-10 border-b border-zinc-100 bg-zinc-50/95 px-4 py-2 backdrop-blur dark:border-zinc-800 dark:bg-zinc-950/95"
                            x-bind:class="all && 'sticky top-14 md:top-0 border-t'">
                            <h2 class="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                                <span class="font-semibold {{ $g['name'] === null ? 'italic' : '' }}">{{ $g['name'] ?? 'No initiative' }}</span>
                                @if ($g['parked'])
                                    <span class="rounded bg-cancelled/15 px-1.5 text-xs font-normal text-zinc-700 ring-1 ring-cancelled/40 ring-inset dark:text-zinc-300">parked</span>
                                @endif
                                <span class="flex flex-wrap gap-x-3 text-xs font-normal text-zinc-500 dark:text-zinc-400">
                                    @foreach ($g['counts'] as $status => $n)
                                        <span class="inline-flex items-center gap-1 whitespace-nowrap">
                                            <span class="size-1.5 rounded-full {{ $dots[\App\Actions\Board\ReadProjectStories::kind($status === '(none)' ? null : $status)] }}" aria-hidden="true"></span>
                                            <span class="tabular-nums">{{ $n }}</span> {{ $countWords[$status] ?? $status }}
                                        </span>
                                    @endforeach
                                </span>
                            </h2>
                            <x-board.status-bar :counts="$g['counts']" variant="tag" class="mt-2 h-1.5" x-show="! all" />
                        </div>
                        <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($g['stories'] as $s)
                                @php $cancelled = $s['kind'] === 'cancelled'; @endphp
                                <li data-story-row="{{ $s['story_id'] ?? $s['id'] }}" data-kind="{{ $s['kind'] }}" class="{{ $hide[$s['kind']] }}">
                                    @if ($s['link'])
                                        <button type="button" data-story-link="{{ $s['link'] }}" aria-haspopup="dialog"
                                            @class(['grid w-full grid-cols-[5rem_1fr_auto] items-center gap-x-3 px-4 py-1.5 text-left text-sm hover:bg-zinc-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent sm:grid-cols-[6rem_1fr_auto] dark:hover:bg-zinc-800/50', 'text-zinc-400 dark:text-zinc-500' => $cancelled])>
                                    @else
                                        {{-- An ID that is not well formed cannot be linked by ?story= (SB-8): a plain row. --}}
                                        <div @class(['grid w-full grid-cols-[5rem_1fr_auto] items-center gap-x-3 px-4 py-1.5 text-sm sm:grid-cols-[6rem_1fr_auto]', 'text-zinc-400 dark:text-zinc-500' => $cancelled])>
                                    @endif
                                        <span @class(['truncate font-mono text-xs', 'line-through' => $cancelled, 'text-zinc-500 dark:text-zinc-400' => ! $cancelled])>{{ $s['story_id'] ?? '—' }}</span>
                                        <span @class(['min-w-0 truncate', 'line-through' => $cancelled]) title="{{ $s['title'] }}">{{ $s['title'] }}</span>
                                        <x-board.status-chip :status="$s['status']" variant="tag" class="justify-self-end whitespace-nowrap" />
                                    @if ($s['link'])
                                        </button>
                                    @else
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <p x-show="hiddenIn(@js($g['key'])) > 0" style="display: none" class="border-t border-zinc-100 px-4 py-2 text-xs text-zinc-400 dark:border-zinc-800">
                            <span x-text="hiddenIn(@js($g['key']))"></span> hidden by the filters
                        </p>
                    </section>
                @endforeach
            </div>
        </div>
    @endif
</main>

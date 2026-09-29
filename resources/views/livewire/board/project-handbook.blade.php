{{--
    SB-14 project handbook, option A (docs/mockups/SB-14/option-a.html): the SB-10 project header and the
    Dashboard | Handbook tabs, then section tabs across the top above one full-width reading card, kit
    badges inline, and a decision opening in the design-A centred modal.

    Tabs are Alpine (pure UI). The first tab arrives rendered; any other is filled the first time it opens
    by $wire.loadSection(), whose HTML Alpine keeps in `bodies` (x-html initialises the Alpine inside it).
    The component never re-renders after the first response, so nothing already loaded is sent twice.
    A decision row dispatches `handbook-decision`; the modal fetches its HTML with $wire.openDecision() and
    keeps `?decision=` in the URL (replaceState: the modal is not a history step), so a reload or a shared
    link lands on the same file.
--}}
@php
    $labels = ['rules' => 'Rules', 'lessons' => 'Lessons', 'standards' => 'Standards', 'runbook' => 'Runbook', 'decisions' => 'Decisions', 'skills' => 'Skills'];
    $kitOk = $kitSha !== null;
@endphp
<main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-10 lg:py-10"
    x-data="{
        tab: @js($initial),
        loaded: @js([$initial]),
        bodies: {},
        loading: null,
        doc: @js($decisionHtml !== null ? $decision : null),
        docHtml: null,
        docLoading: false,
        docMissing: false,
        async go(section) {
            this.tab = section;
            if (this.loaded.includes(section)) return;
            this.loaded.push(section);
            this.loading = section;
            try {
                this.bodies[section] = (await this.$wire.loadSection(section)) ?? '';
            } catch (e) {
                // Let the next click try again rather than keep an empty tab.
                this.loaded = this.loaded.filter(s => s !== section);
            } finally {
                this.loading = null;
            }
        },
        setUrl(name) {
            const url = new URL(location.href);
            name === null ? url.searchParams.delete('decision') : url.searchParams.set('decision', name);
            history.replaceState(history.state, '', url);
        },
        async openDoc(name) {
            this.doc = name;
            this.docHtml = null;
            this.docMissing = false;
            this.docLoading = true;
            this.setUrl(name);
            try {
                const html = await this.$wire.openDecision(name);
                if (this.doc !== name) return;
                html === null ? this.docMissing = true : this.docHtml = html;
            } finally {
                this.docLoading = false;
            }
        },
        closeDoc() {
            if (this.doc === null) return;
            this.doc = null;
            this.docHtml = null;
            this.setUrl(null);
        },
    }"
    x-on:handbook-decision="openDoc($event.detail)"
    x-on:keydown.escape.window="closeDoc()">

    <x-board.project-header :model="$model" page="Handbook" current="handbook" />

    @unless ($kitOk)
        <div role="status" data-kit-unreachable class="mt-5 flex items-start gap-3 rounded-xl border border-warning/40 bg-warning/10 px-4 py-3 text-sm">
            <svg class="size-5 shrink-0 text-warning" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l9 16H3z"/><path d="M12 10v4M12 17h.01"/></svg>
            <div class="min-w-0">
                <p class="font-medium break-words">Kit not found at {{ $kitPath }} — comparison unavailable</p>
                <p class="mt-0.5 text-zinc-600 dark:text-zinc-300">Everything below is read from {{ $model->name }} as usual. Point <span class="font-mono text-xs">BOARD_KIT_PATH</span> at the kit's checkout to see which files differ.</p>
            </div>
        </div>
    @endunless

    @if ($decisionRefusal)
        <p role="status" data-decision-refused class="mt-5 rounded-md border border-warning/50 bg-warning/10 px-3 py-2 text-sm break-words">{{ $decisionRefusal }}</p>
    @endif

    <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
        <div role="tablist" aria-label="Handbook sections" class="-mx-4 flex max-w-full gap-1 overflow-x-auto px-4 sm:mx-0 sm:px-0">
            @foreach (\App\Livewire\Board\ProjectHandbook::SECTIONS as $section)
                <button type="button" role="tab" data-handbook-tab="{{ $section }}" id="handbook-tab-{{ $section }}" aria-controls="handbook-{{ $section }}"
                    x-on:click="go(@js($section))" x-bind:aria-selected="tab === @js($section)"
                    class="inline-flex shrink-0 items-center gap-2 rounded-lg px-3 py-1.5 text-sm"
                    x-bind:class="tab === @js($section) ? 'bg-zinc-800 font-medium text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800'">
                    {{ $labels[$section] }}
                </button>
            @endforeach
        </div>
        @if ($kitOk)
            <p data-kit-ref class="min-w-0 text-xs break-all text-zinc-500 dark:text-zinc-400">vs kit <span class="font-mono">{{ $kitPath }}</span> · <span class="font-mono">{{ substr($kitSha, 0, 7) }}</span></p>
        @endif
    </div>

    <section aria-label="Handbook" class="mt-4 rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        @foreach (\App\Livewire\Board\ProjectHandbook::SECTIONS as $section)
            <div role="tabpanel" id="handbook-{{ $section }}" aria-labelledby="handbook-tab-{{ $section }}" data-section="{{ $section }}"
                x-show="tab === @js($section)" @if ($section !== $initial) x-cloak @endif>
                @if ($section === $initial)
                    {!! $initialHtml !!}
                @else
                    {{-- Loading: a skeleton the height of a short section, so the card does not jump. --}}
                    <div x-show="loading === @js($section)" class="space-y-3 px-5 py-6" aria-label="Loading">
                        <div class="h-3 w-1/3 animate-pulse rounded bg-zinc-200 dark:bg-zinc-800"></div>
                        <div class="h-3 w-full animate-pulse rounded bg-zinc-100 dark:bg-zinc-800"></div>
                        <div class="h-3 w-5/6 animate-pulse rounded bg-zinc-100 dark:bg-zinc-800"></div>
                        <div class="h-3 w-2/3 animate-pulse rounded bg-zinc-100 dark:bg-zinc-800"></div>
                    </div>
                    <div x-html="bodies[@js($section)] ?? ''"></div>
                @endif
            </div>
        @endforeach
    </section>

    {{-- A decision file: the design-A centred modal (SB-8's shape), read from git when opened. --}}
    <div x-show="doc !== null" x-cloak class="fixed inset-0 z-60 flex items-end justify-center sm:items-center sm:p-6">
        <div data-decision-backdrop x-on:click="closeDoc()" class="absolute inset-0 bg-zinc-950/50 backdrop-blur-xs" aria-hidden="true"></div>
        <div role="dialog" aria-modal="true" aria-labelledby="decision-title" x-trap.noscroll="doc !== null"
            class="board-modal relative flex w-full max-w-3xl flex-col rounded-t-2xl bg-white shadow-2xl ring-1 ring-zinc-200 sm:rounded-2xl dark:bg-zinc-900 dark:ring-zinc-800">
            <header class="flex items-start gap-3 border-b border-zinc-200 px-5 py-4 sm:px-6 dark:border-zinc-800">
                <div class="min-w-0 flex-1">
                    <p class="font-mono text-xs break-all text-zinc-500 dark:text-zinc-400">{{ $model->name }} · docs/decisions/<span x-text="doc"></span></p>
                    <h2 id="decision-title" class="mt-1 text-lg font-semibold tracking-tight break-words" x-text="doc"></h2>
                </div>
                <button type="button" x-on:click="closeDoc()" autofocus class="shrink-0 rounded-md px-3 py-1.5 text-sm font-medium hover:bg-zinc-100 dark:hover:bg-zinc-800">Close</button>
            </header>
            <div class="flex-1 overflow-y-auto px-5 py-6 sm:px-8">
                @if ($decisionHtml !== null)
                    <div data-decision-open x-show="doc === @js($decision) && docHtml === null && ! docLoading">
                        <x-board.prose :html="$decisionHtml" class="max-w-none" />
                    </div>
                @endif
                <div x-show="docLoading" class="space-y-3" aria-label="Loading">
                    <div class="h-3 w-1/3 animate-pulse rounded bg-zinc-200 dark:bg-zinc-800"></div>
                    <div class="h-3 w-full animate-pulse rounded bg-zinc-100 dark:bg-zinc-800"></div>
                    <div class="h-3 w-5/6 animate-pulse rounded bg-zinc-100 dark:bg-zinc-800"></div>
                </div>
                <p x-show="docMissing" x-cloak role="status" class="text-sm text-zinc-600 dark:text-zinc-300">
                    Could not open <span class="font-mono" x-text="doc"></span>: it is not in {{ $model->name }} at this snapshot.
                </p>
                <div x-show="docHtml !== null" x-cloak>
                    <x-board.prose html="" class="max-w-none" x-html="docHtml ?? ''" />
                </div>
            </div>
        </div>
    </div>
</main>

{{-- Handbook › Decisions (SB-14): the file names in docs/decisions/, 50 a page, with a file-name filter.
     Every name is on the page once (coins has 1,747, about 150 KB), so filtering and paging are Alpine
     over all of them with no round trip. A row asks the page to open that file in the modal. --}}
@if ($names === null)
    <x-board.handbook-empty :project="$project" file="docs/decisions/">Decision records appear here once /document writes the first ADR.</x-board.handbook-empty>
@else
    <div data-decisions data-per-page="{{ \App\Livewire\Board\ProjectHandbook::DECISIONS_PAGE }}"
        x-data="{
            names: @js($names),
            q: '',
            page: 1,
            per: {{ \App\Livewire\Board\ProjectHandbook::DECISIONS_PAGE }},
            get matches() { const q = this.q.trim().toLowerCase(); return q === '' ? this.names : this.names.filter(n => n.toLowerCase().includes(q)) },
            get pages() { return Math.max(1, Math.ceil(this.matches.length / this.per)) },
            get rows() { return this.matches.slice((this.page - 1) * this.per, this.page * this.per) },
            date(n) { return (n.match(/^\d{4}-\d{2}-\d{2}/) ?? [''])[0] },
            title(n) { const t = n.replace(/\.md$/, '').replace(/^\d{4}-\d{2}-\d{2}-/, '').replaceAll('-', ' '); return t.charAt(0).toUpperCase() + t.slice(1) },
        }">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 px-5 py-3 dark:border-zinc-800">
            <label class="relative w-full sm:w-96">
                <span class="sr-only">Filter decisions by file name</span>
                <svg class="absolute top-2.5 left-2.5 size-4 text-zinc-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                <input type="search" data-decision-filter x-model="q" x-on:input="page = 1" placeholder="Filter by file name"
                    class="w-full rounded-lg border border-zinc-300 bg-white py-2 pr-3 pl-8 text-sm focus:ring-2 focus:ring-zinc-400 focus:outline-none dark:border-zinc-700 dark:bg-zinc-950">
            </label>
            <p class="text-xs text-zinc-500 tabular-nums dark:text-zinc-400">
                <span data-decision-count x-text="matches.length ? ((page - 1) * per + 1) + '–' + Math.min(page * per, matches.length) + ' of ' + matches.length.toLocaleString() : 'None'"></span>
                of {{ number_format(count($names)) }} files
            </p>
        </div>
        <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
            <template x-for="n in rows" :key="n">
                <li>
                    <button type="button" data-decision-row x-bind:data-decision-row="n" x-on:click="$dispatch('handbook-decision', n)"
                        class="grid w-full gap-x-3 gap-y-0.5 px-5 py-2.5 text-left hover:bg-zinc-50 sm:grid-cols-[6.5rem_1fr] dark:hover:bg-zinc-800/60">
                        <span class="pt-0.5 text-xs text-zinc-500 tabular-nums dark:text-zinc-400" x-text="date(n)"></span>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium" x-text="title(n)"></span>
                            <span class="block truncate font-mono text-xs text-zinc-400" x-text="n"></span>
                        </span>
                    </button>
                </li>
            </template>
        </ul>
        <div x-show="! matches.length" x-cloak class="px-5 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">
            No decision file names contain “<span x-text="q"></span>”.
            <button type="button" x-on:click="q = ''" class="font-medium text-zinc-800 hover:underline dark:text-white">Clear the filter</button>
        </div>
        <div x-show="matches.length" class="flex items-center justify-between border-t border-zinc-200 px-5 py-3 text-sm dark:border-zinc-800">
            <button type="button" data-decisions-prev x-on:click="page--" x-bind:disabled="page === 1" class="rounded-md px-3 py-1.5 font-medium hover:bg-zinc-100 disabled:opacity-40 dark:hover:bg-zinc-800">← Previous</button>
            <span class="text-xs text-zinc-500 tabular-nums dark:text-zinc-400" x-text="'Page ' + page + ' of ' + pages"></span>
            <button type="button" data-decisions-next x-on:click="page++" x-bind:disabled="page >= pages" class="rounded-md px-3 py-1.5 font-medium hover:bg-zinc-100 disabled:opacity-40 dark:hover:bg-zinc-800">Next →</button>
        </div>
    </div>
@endif

{{-- Handbook › Lessons (SB-14): docs/LESSONS.md split into its `## L-<n>` entries, newest first. A row
     opens its body in place (Alpine: pure UI); "Expand all" opens or closes every row. --}}
@if ($lessons === null)
    <x-board.handbook-empty :project="$project" file="docs/LESSONS.md">Lessons appear here once the project records its first ## L-1 entry with /lesson.</x-board.handbook-empty>
@elseif ($lessons === [])
    <x-board.handbook-empty :project="$project" file="docs/LESSONS.md" data-no-entries>
        The file is there but has no ## L-&lt;n&gt; entries yet.
    </x-board.handbook-empty>
@else
    <div x-data="{ all: false }">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b px-5 py-3 border-edge">
            <p class="font-mono text-xs text-ink-muted">docs/LESSONS.md</p>
            <p class="text-xs text-ink-muted">
                {{ count($lessons) }} {{ Str::plural('lesson', count($lessons)) }} · newest first ·
                <button type="button" x-on:click="all = ! all" class="font-medium hover:underline text-ink-soft"
                    x-text="all ? 'Collapse all' : 'Expand all'">Expand all</button>
            </p>
        </div>
        <ul class="divide-y divide-hairline">
            @foreach ($lessons as $lesson)
                <li data-lesson="{{ $lesson['number'] }}" wire:key="lesson-{{ $lesson['number'] }}" x-data="{ open: false }" x-effect="open = all">
                    <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open"
                        class="grid w-full grid-cols-[3rem_1fr_auto] items-center gap-x-3 gap-y-1 px-5 py-3 text-left sm:grid-cols-[3.5rem_6.5rem_1fr_auto] hover:bg-raised">
                        <span class="font-mono text-xs font-medium text-ink-muted">L-{{ $lesson['number'] }}</span>
                        <span class="hidden text-xs tabular-nums sm:block text-ink-muted">{{ $lesson['date'] ?? '—' }}</span>
                        <span class="min-w-0 text-sm font-medium">{{ $lesson['name'] }}</span>
                        <span class="row-span-2 flex items-center gap-2 sm:row-span-1">
                            {{-- A short chip; the ledger's full Scope line (often prose) is the tooltip and sits in the expanded body. --}}
                            @if ($lesson['scope_label'])
                                <span title="{{ $lesson['scope'] }}" class="hidden shrink-0 whitespace-nowrap rounded-full px-2 py-0.5 text-xs ring-1 ring-inset md:inline text-ink-muted ring-rim">{{ $lesson['scope_label'] }}</span>
                            @endif
                            <svg class="size-4 text-ink-faint transition-transform" x-bind:class="open && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                        </span>
                        <span class="col-start-2 text-xs tabular-nums sm:hidden text-ink-muted">{{ $lesson['date'] ?? '—' }}</span>
                    </button>
                    <div x-show="open" x-cloak class="px-5 pb-4 sm:pl-36">
                        <x-board.prose :html="$lesson['html']" class="prose-sm" />
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
@endif

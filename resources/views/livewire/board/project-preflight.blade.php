{{--
    SB-16 preflight history, option A — the dense ledger (docs/mockups/SB-16/option-a.html) inside the design-A shell:
    filters in one row, then the trend strip (runs, median wall time, median tokens for the last 30 days vs the 30
    before, and one line chart each for wall time and tokens), then one wide table of every run, newest first, with a
    sticky header. Hovering a row marks its run on both charts; hovering a chart marks the nearest run.

    Every run is in this markup once, rendered by the server (dates in UTC until Alpine rewrites them in the viewer's
    zone). The filters, figures, charts and hover are Alpine (resources/js/preflight-history.js) over the same runs,
    passed in as data, so nothing on this page calls the server after it loads.

    SB-19 adds the Columns picker to the filter row: every th and td carries its `data-col` key, and a column the
    viewer unticks is hidden by Alpine (x-show) and remembered in this browser only. When is the timeline's key and
    cannot be hidden.
--}}
@use('App\Actions\Board\ReadPreflightHistory', 'History')
@php
    $count = count($runs);
    // What Alpine needs per run; `id` is the row's index in this newest-first list.
    $data = array_map(fn (array $r, int $i) => [
        'id' => $i, 'ts' => $r['ts']->getTimestampMs(), 'branch' => $r['branch'], 'where' => $r['where'],
        'mode' => $r['mode'], 'wall' => $r['wall'], 'tokens' => $r['tokens'], 'flagged' => $r['flagged'],
    ], $runs, array_keys($runs));
    $modes = ['all' => 'All', 'scoped' => 'Scoped', 'full' => 'Full'];
    $modeCounts = ['all' => $count, 'scoped' => count(array_filter($runs, fn ($r) => $r['mode'] === 'scoped')), 'full' => count(array_filter($runs, fn ($r) => $r['mode'] === 'full'))];
    $figures = [
        'runs' => ['Runs', fn ($f) => (string) $f['runs']],
        'wall' => ['Median wall time', fn ($f) => History::wall($f['wall'])],
        'tokens' => ['Median tokens', fn ($f) => History::tokens($f['tokens'])],
    ];
    $num = fn (?int $n) => $n === null ? '—' : number_format($n);
    // The ledger's columns in table order: each key is the `data-col` on its th and tds and a checkbox in the picker.
    $columns = ['when' => 'When', 'branch' => 'Branch', 'where' => 'Where', 'mode' => 'Mode', 'wall' => 'Wall', 'turns' => 'Turns',
        'tools' => 'Tool calls', 'tokens' => 'Tokens', 'share' => 'Subagent', 'pack' => 'Pack', 'tier' => 'Audit tier', 'tests' => 'Tests'];
    // When stays: without it the rows are figures with no place on the timeline.
    $hideable = array_values(array_diff(array_keys($columns), ['when']));
@endphp
<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-10 lg:py-10"
    @if ($count > 0) x-data="preflightHistory(@js($data), {{ $now->getTimestampMs() }}, @js(History::MAIN), @js($model->name), @js($hideable))" @endif>

    <x-board.project-header :model="$model" page="Preflight" current="preflight">
        @if ($count > 0)
            · <span class="tabular-nums">{{ $count }}</span> preflight {{ Str::plural('run', $count) }}, {{ end($runs)['ts']->format('M j') }} – {{ $runs[0]['ts']->format('M j') }}
        @endif
        · read on page load · <a href="{{ route('projects.preflight', $model->name) }}" wire:navigate data-refresh class="font-medium text-zinc-800 hover:underline dark:text-white">Refresh</a>
    </x-board.project-header>

    @if ($skipped > 0)
        <div role="status" data-skipped class="mt-5 flex items-start gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm dark:border-zinc-800 dark:bg-zinc-900">
            <svg class="mt-0.5 size-4 shrink-0 text-warning" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l9.5 17h-19L12 3z"/><path d="M12 10v4M12 17h.01"/></svg>
            <p><span class="font-medium">{{ $skipped }} {{ Str::plural('row', $skipped) }} could not be read</span> and {{ $skipped === 1 ? 'is' : 'are' }} not listed. Each had the wrong number of columns or a date that could not be parsed. The CSV itself is left as it is.</p>
        </div>
    @endif

    @if ($count === 0)
        <div data-empty class="mt-6 rounded-xl border border-dashed border-zinc-300 bg-white px-4 py-10 text-center sm:px-6 dark:border-zinc-700 dark:bg-zinc-900">
            <svg class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M4 19V9M10 19V5M16 19v-7M21 19H3"/></svg>
            <p class="mt-3 font-medium">{{ $model->name }} has no preflight runs recorded yet.</p>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Runs appear here after the next <span class="font-mono text-xs">/preflight</span> in that project. These places were checked:</p>
            <ul data-looked class="mx-auto mt-3 max-w-xl space-y-1 text-left font-mono text-xs break-all text-zinc-500 dark:text-zinc-400">
                @foreach ($looked as $path)
                    <li>{{ $path }}</li>
                @endforeach
            </ul>
        </div>
    @else
        <div class="mt-5 space-y-4">
            {{-- Filters: one row, above everything they scope. Alpine only; no server call. --}}
            <div class="flex flex-wrap items-center gap-2">
                <div role="group" aria-label="Mode" class="inline-flex rounded-lg border border-zinc-200 bg-white p-0.5 dark:border-zinc-800 dark:bg-zinc-900">
                    @foreach ($modes as $mode => $label)
                        <button type="button" data-mode-filter="{{ $mode }}" x-on:click="fMode = @js($mode)" x-bind:aria-pressed="fMode === @js($mode)" aria-pressed="{{ $mode === 'all' ? 'true' : 'false' }}"
                            class="rounded-md px-2.5 py-1 text-xs font-medium whitespace-nowrap"
                            x-bind:class="fMode === @js($mode) ? 'bg-accent text-accent-foreground' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'">
                            {{ $label }} <span class="tabular-nums opacity-60">{{ $modeCounts[$mode] }}</span>
                        </button>
                    @endforeach
                </div>
                <label class="relative min-w-36 flex-1 sm:max-w-xs">
                    <span class="sr-only">Branch contains</span>
                    <svg class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-zinc-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                    <input type="search" data-branch-filter x-model="fBranch" placeholder="Branch contains…"
                        class="w-full rounded-lg border border-zinc-200 bg-white py-1.5 pr-3 pl-8 text-sm placeholder:text-zinc-400 dark:border-zinc-800 dark:bg-zinc-900">
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <span class="text-zinc-500 dark:text-zinc-400">Where</span>
                    <select data-where-filter x-model="fWhere" class="rounded-lg border border-zinc-200 bg-white py-1.5 pr-7 pl-2 text-sm dark:border-zinc-800 dark:bg-zinc-900">
                        <option value="all">All</option>
                        <option value="main">Main checkout</option>
                        <option value="worktrees">Worktrees</option>
                    </select>
                </label>
                {{-- Columns picker (SB-19): a popover of checkboxes. Anchored to its button (x-anchor flips and shifts it to
                     stay on screen at 375px). Toggling is Alpine only; the choice is saved per project in this browser. --}}
                <div class="relative" x-on:keydown.escape="colsOpen = false">
                    <button type="button" data-columns-button x-ref="colsButton" x-on:click="colsOpen = ! colsOpen" x-bind:aria-expanded="colsOpen" aria-expanded="false" aria-controls="preflight-columns"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm font-medium whitespace-nowrap hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 5h16v14H4zM9.5 5v14M14.5 5v14"/></svg>
                        <span x-text="columnsLabel">Columns</span>
                    </button>
                    <div id="preflight-columns" data-columns-panel x-show="colsOpen" x-cloak x-on:click.outside="colsOpen = false" x-anchor.bottom-start.offset.4="$refs.colsButton"
                        role="group" aria-label="Columns shown" class="absolute z-20 w-56 rounded-lg border border-zinc-200 bg-white p-1 text-sm shadow-lg dark:border-zinc-800 dark:bg-zinc-900">
                        @foreach ($columns as $col => $label)
                            <label data-col-option="{{ $col }}" @if ($col === 'when') title="When is always shown: it places each run on the timeline" @endif
                                @class(['flex items-center gap-2 rounded-md px-2 py-1.5', 'cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800' => $col !== 'when', 'text-zinc-400 dark:text-zinc-500' => $col === 'when'])>
                                @if ($col === 'when')
                                    <input type="checkbox" data-col-toggle="when" checked disabled class="size-4 accent-accent">
                                @else
                                    <input type="checkbox" data-col-toggle="{{ $col }}" checked x-bind:checked="shown(@js($col))" x-on:change="toggleColumn(@js($col))" class="size-4 accent-accent">
                                @endif
                                {{ $label }}
                            </label>
                        @endforeach
                        <div class="mt-1 border-t border-zinc-100 px-2 pt-1.5 pb-1 dark:border-zinc-800">
                            <button type="button" data-columns-reset x-on:click="resetColumns()" class="text-xs text-zinc-500 underline underline-offset-4 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200">Reset columns</button>
                        </div>
                    </div>
                </div>
                <button type="button" x-show="filtered" x-cloak x-on:click="reset()" class="text-xs text-zinc-500 underline underline-offset-4 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200">Clear filters</button>
                <p data-runs-shown class="ml-auto text-xs text-zinc-500 tabular-nums dark:text-zinc-400"><span x-text="visible.length">{{ $count }}</span> of {{ $count }} runs</p>
            </div>

            {{-- Trend strip: the server's figures first; Alpine recomputes them when a filter narrows the runs. --}}
            <section aria-label="Trend" class="rounded-xl border border-zinc-200 bg-white p-3 sm:p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="grid grid-cols-3 gap-2 sm:gap-3">
                    @foreach ($figures as $name => [$label, $format])
                        <div data-trend="{{ $name }}" class="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-950/60">
                            <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
                            <p data-current x-text="figure(@js($name), 'current')" class="mt-0.5 text-lg font-semibold tabular-nums sm:text-xl">{{ $format($trend['current']) }}</p>
                            <p class="text-xs leading-snug text-zinc-500 tabular-nums dark:text-zinc-400">30 days before: <span data-previous x-text="figure(@js($name), 'previous')">{{ $format($trend['previous']) }}</span></p>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 grid gap-5 md:grid-cols-2">
                    @foreach (['wall' => ['Wall time per run', 'minutes'], 'tokens' => ['Tokens per run (in + out)', 'tokens']] as $metric => [$caption, $unit])
                        <figure class="min-w-0">
                            <figcaption class="flex items-baseline justify-between gap-2 text-xs"><span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $caption }}</span><span class="text-zinc-400">{{ $unit }}</span></figcaption>
                            <div class="relative mt-2 h-30 touch-pan-y" data-chart="{{ $metric }}" x-on:pointermove="onMove($event, @js($metric))" x-on:pointerleave="leave()"
                                role="img" x-bind:aria-label="@js($caption) + ', ' + visible.length + ' runs, oldest to newest'">
                                <div x-html="chart(@js($metric), w.{{ $metric }}, 120)" class="h-30"></div>
                                <div x-show="hoverChart === @js($metric) && hovered" x-cloak x-bind:style="tipStyle(@js($metric))"
                                    class="pointer-events-none absolute bottom-full z-10 mb-1 -translate-x-1/2 rounded-md bg-zinc-900 px-2 py-1 text-xs whitespace-nowrap text-white shadow-lg dark:bg-white dark:text-zinc-900">
                                    <span class="font-semibold tabular-nums" x-text="hovered && {{ $metric === 'wall' ? 'fmtWall(hovered.wall)' : "fmtTok(hovered.tokens) + ' tokens'" }}"></span>
                                    <span class="opacity-70" x-text="hovered && day(hovered.id) + ' · ' + time(hovered.id)"></span>
                                    <span class="block max-w-56 truncate font-mono opacity-70" x-text="hovered && hovered.branch"></span>
                                </div>
                            </div>
                        </figure>
                    @endforeach
                </div>
            </section>

            <p class="flex items-start gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                <svg class="mt-px size-4 shrink-0 text-zinc-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
                <span>Test counts and failures are not in the CSV yet, so this page cannot show them. They come with <span class="font-mono">F-5</span> (a kit run record). The greyed <span class="font-medium">Tests</span> column holds their place.</span>
            </p>

            {{-- The ledger: every run, newest first. Scrolls inside its own box on narrow screens and past most of the viewport. --}}
            <div x-show="visible.length" data-ledger class="max-h-[70vh] overflow-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                {{-- The full ledger keeps its approved 5xl floor. With columns hidden it shrinks to its content so the table
                     really narrows, and the box still scrolls sideways if that is wider than the screen. --}}
                <table class="w-full min-w-5xl text-sm" x-bind:class="{ 'min-w-5xl': ! hiddenCount, 'min-w-max': hiddenCount }">
                    <thead class="sticky top-0 z-10 bg-zinc-50/95 text-xs text-zinc-500 backdrop-blur dark:bg-zinc-950/95 dark:text-zinc-400">
                        <tr class="border-b border-zinc-200 whitespace-nowrap dark:border-zinc-800">
                            <th scope="col" data-col="when" class="sticky left-0 bg-zinc-50 px-3 py-2 text-left font-medium dark:bg-zinc-950">When</th>
                            <th scope="col" data-col="branch" x-show="shown('branch')" class="px-3 py-2 text-left font-medium">Branch</th>
                            <th scope="col" data-col="where" x-show="shown('where')" class="px-3 py-2 text-left font-medium">Where</th>
                            <th scope="col" data-col="mode" x-show="shown('mode')" class="px-3 py-2 text-left font-medium">Mode</th>
                            <th scope="col" data-col="wall" x-show="shown('wall')" class="px-3 py-2 text-right font-medium">Wall</th>
                            <th scope="col" data-col="turns" x-show="shown('turns')" class="px-3 py-2 text-right font-medium">Turns</th>
                            <th scope="col" data-col="tools" x-show="shown('tools')" class="px-3 py-2 text-right font-medium">Tool calls</th>
                            <th scope="col" data-col="tokens" x-show="shown('tokens')" class="px-3 py-2 text-right font-medium">Tokens</th>
                            <th scope="col" data-col="share" x-show="shown('share')" class="px-3 py-2 text-right font-medium" title="Share of the tokens spent inside subagents">Subagent</th>
                            <th scope="col" data-col="pack" x-show="shown('pack')" class="px-3 py-2 text-right font-medium">Pack</th>
                            <th scope="col" data-col="tier" x-show="shown('tier')" class="px-3 py-2 text-left font-medium">Audit tier</th>
                            <th scope="col" data-col="tests" x-show="shown('tests')" class="bg-zinc-100 px-3 py-2 text-right font-medium text-zinc-300 dark:bg-zinc-900 dark:text-zinc-600">Tests <span class="block text-xs font-normal">needs F-5</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($runs as $i => $r)
                            <tr data-run="{{ $i }}" x-show="keep({{ $i }})" x-on:mouseenter="hoverId = {{ $i }}" x-on:mouseleave="hoverId = null"
                                x-bind:class="hoverId === {{ $i }} && 'bg-series/5'" class="group">
                                <td data-col="when" class="sticky left-0 bg-white px-3 py-1.5 whitespace-nowrap tabular-nums group-hover:bg-zinc-50 dark:bg-zinc-900 dark:group-hover:bg-zinc-800">
                                    <time datetime="{{ $r['ts']->toIso8601ZuluString() }}"><span x-text="day({{ $i }})">{{ $r['ts']->format('M j') }}</span> <span class="text-zinc-400" x-text="time({{ $i }})">{{ $r['ts']->format('H:i') }}</span></time>
                                </td>
                                <td data-col="branch" x-show="shown('branch')" class="px-3 py-1.5"><span class="block max-w-68 truncate font-mono text-xs" title="{{ $r['branch'] }}">{{ $r['branch'] ?? '—' }}</span></td>
                                <td data-col="where" x-show="shown('where')" class="px-3 py-1.5 whitespace-nowrap text-zinc-500 dark:text-zinc-400">{{ $r['where'] }}</td>
                                <td data-col="mode" x-show="shown('mode')" @class(['px-3 py-1.5', 'text-zinc-400' => $r['mode'] === null])>{{ $r['mode'] ?? '—' }}</td>
                                <td data-col="wall" x-show="shown('wall')" class="px-3 py-1.5 text-right font-medium tabular-nums">{{ History::wall($r['wall']) }}</td>
                                <td data-col="turns" x-show="shown('turns')" class="px-3 py-1.5 text-right tabular-nums">{{ $num($r['turns']) }}</td>
                                <td data-col="tools" x-show="shown('tools')" class="px-3 py-1.5 text-right tabular-nums">{{ $num($r['tools']) }}</td>
                                <td data-col="tokens" x-show="shown('tokens')" class="px-3 py-1.5 text-right font-medium tabular-nums" title="{{ $num($r['tokens_in']) }} in, {{ $num($r['tokens_out']) }} out">{{ History::tokens($r['tokens']) }}</td>
                                <td data-col="share" x-show="shown('share')" class="px-3 py-1.5 text-right tabular-nums">{{ $r['share'] === null ? '—' : $r['share'].'%' }}</td>
                                <td data-col="pack" x-show="shown('pack')" class="px-3 py-1.5 text-right whitespace-nowrap text-zinc-500 tabular-nums dark:text-zinc-400">{{ History::pack($r['pack']) }}</td>
                                <td data-col="tier" x-show="shown('tier')" class="px-3 py-1.5">
                                    <span class="inline-flex items-center gap-1 whitespace-nowrap">
                                        @if ($r['tier'] === null)
                                            <span class="text-zinc-400">—</span>
                                        @else
                                            <span class="font-mono text-xs">{{ $r['tier'] }}</span>
                                        @endif
                                        @if ($r['flagged'])
                                            <span data-tier-flag class="inline-flex items-center rounded bg-warning/15 px-1.5 text-xs font-medium text-zinc-800 ring-1 ring-warning/40 ring-inset dark:text-zinc-100"
                                                title="CLAUDE.md pins the audit to Sonnet. The label may be off (F-3), so check it rather than assume it is wrong.">check the tier</span>
                                        @endif
                                    </span>
                                </td>
                                <td data-col="tests" x-show="shown('tests')" class="bg-zinc-50 px-3 py-1.5 text-right text-zinc-300 dark:bg-zinc-950/40 dark:text-zinc-600">—</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div x-show="! visible.length" x-cloak data-no-match class="rounded-xl border border-dashed border-zinc-300 px-6 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                No runs match these filters.
                <button type="button" x-on:click="reset()" class="font-medium text-zinc-800 underline underline-offset-4 dark:text-white">Clear filters</button>
            </div>
            <p x-show="visible.length" class="text-xs text-zinc-500 dark:text-zinc-400">
                <span class="tabular-nums" x-text="flaggedCount"></span> of these runs name an audit tier other than Sonnet. Hover a row to find it on both charts; hover a chart to read a run.
            </p>
        </div>
    @endif
</main>

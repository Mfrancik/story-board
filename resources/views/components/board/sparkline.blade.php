{{--
    A 30-day trend line (SB-18), inline SVG — no chart library. `series` is ListProductionDashboard's list of
    {day, value|null}, oldest first; a missing day is a gap in the x axis, not a zero. The SVG stretches to its box
    (preserveAspectRatio none, non-scaling stroke), so points are placed in percent and the end dot and hover
    readout are HTML over it. Hover is pure UI, so it is Alpine: the nearest day with a value and its number.
    `tone` is a full text-colour class (text-series-N, or text-zinc-400 for a greyed last-good line).
--}}
@props(['series', 'tone', 'label'])
@php
    $n = count($series);
    $values = array_filter(array_column($series, 'value'), fn ($v) => $v !== null);
    $lo = $values === [] ? 0 : min($values);
    $hi = $values === [] ? 0 : max($values);
    // A flat line sits in the middle rather than on an edge.
    if ($hi === $lo) { $lo -= 1; $hi += 1; }
    $points = [];
    foreach ($series as $i => $s) {
        $points[] = $s['value'] === null ? null : [
            'x' => round($n > 1 ? $i / ($n - 1) * 100 : 50, 2),
            // 12–88 % of the height, so the stroke and end dot are never clipped.
            'y' => round(12 + 76 * (1 - ($s['value'] - $lo) / ($hi - $lo)), 2),
            'd' => $s['day'],
            'v' => \App\Actions\Board\ListProductionDashboard::format((float) $s['value']),
        ];
    }
    $drawn = array_values(array_filter($points));
    $line = collect($drawn)->map(fn ($p, $k) => ($k ? 'L' : 'M').$p['x'].' '.$p['y'])->implode(' ');
    $last = end($drawn) ?: null;
    $first = $drawn[0] ?? null;
@endphp
@if ($drawn === [])
    <p {{ $attributes->class('text-xs text-zinc-400 dark:text-zinc-500') }}>No history yet</p>
@else
    <div {{ $attributes->class(['relative h-8 w-full cursor-crosshair touch-pan-y', $tone]) }}
        x-data="{ i: null, pts: @js($points) }"
        x-on:pointermove="
            const r = $el.getBoundingClientRect();
            let k = Math.round(Math.min(1, Math.max(0, ($event.clientX - r.left) / r.width)) * (pts.length - 1));
            let best = null;
            pts.forEach((p, j) => { if (p && (best === null || Math.abs(j - k) < Math.abs(best - k))) best = j; });
            i = best;"
        x-on:pointerleave="i = null">
        <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="block size-full overflow-visible" role="img"
            aria-label="{{ $label }}, {{ count($drawn) }} {{ Str::plural('day', count($drawn)) }}: {{ $first['v'] }} on {{ $first['d'] }} to {{ $last['v'] }} on {{ $last['d'] }}">
            @if (count($drawn) > 1)
                <path d="{{ $line }} L{{ $last['x'] }} 100 L{{ $first['x'] }} 100 Z" fill="currentColor" fill-opacity=".1" />
                <path d="{{ $line }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
            @endif
        </svg>
        <span class="pointer-events-none absolute size-2 -translate-1/2 rounded-full bg-current ring-2 ring-white dark:ring-zinc-900" style="left: {{ $last['x'] }}%; top: {{ $last['y'] }}%" aria-hidden="true"></span>
        <template x-if="i !== null">
            <div aria-hidden="true">
                <span class="pointer-events-none absolute inset-y-0 w-px bg-zinc-300 dark:bg-zinc-600" x-bind:style="'left: ' + pts[i].x + '%'"></span>
                <span class="pointer-events-none absolute size-2 -translate-1/2 rounded-full bg-current ring-2 ring-white dark:ring-zinc-900" x-bind:style="'left: ' + pts[i].x + '%; top: ' + pts[i].y + '%'"></span>
                <span class="pointer-events-none absolute bottom-full z-10 mb-1 -translate-x-1/2 rounded-md bg-zinc-900 px-2 py-1 text-xs whitespace-nowrap text-white shadow-lg dark:bg-white dark:text-zinc-900"
                    x-bind:style="'left: ' + Math.min(80, Math.max(20, pts[i].x)) + '%'"><span x-text="pts[i].d"></span> · <b x-text="pts[i].v"></b></span>
            </div>
        </template>
    </div>
@endif

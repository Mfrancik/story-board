{{-- One app-map screen (SB-24, option A): browser chrome with the step's route, then its picture — the journey test's
     shot, a drawn placeholder page for a built step without one, the chosen mockup option live in a sandboxed frame,
     "awaiting pick", "no mockup yet", or "not linked". `size` is sm (a strip or lane thumbnail) or lg (the stage).
     Holds nothing interactive, so a caller can wrap it in a button; links (the gallery, the story) live beside it.
     RD-4: only the dark shell uses it, so it carries the shell tokens alone (layout unchanged). --}}
@props(['step', 'size' => 'sm'])
@php
    $lg = $size === 'lg';
    $p = $step['picture'];
    $route = $step['route'];
    $tag = $lg ? 'px-2 py-0.5 text-xs' : 'px-1 text-thumb';
    $border = match ($p['kind']) {
        'shot', 'placeholder' => 'border-rim',
        'mockup' => 'border-dashed border-pick/70',
        'awaiting' => 'border-dashed border-warning/70',
        default => 'border-dashed border-ink-faint',
    };
    $captured = $p['captured_at']?->utc()->format('M j, Y H:i');
@endphp
<div data-picture="{{ $p['kind'] }}" {{ $attributes->class(['overflow-hidden rounded-md border bg-surface', $border]) }}>
    <div @class(['flex items-center gap-1 border-b px-1.5 border-edge bg-ground', 'h-7' => $lg, 'h-4' => ! $lg])>
        <span @class(['rounded-full bg-ink-faint', 'size-2' => $lg, 'size-1' => ! $lg])></span>
        <span @class(['rounded-full bg-ink-faint', 'size-2' => $lg, 'size-1' => ! $lg])></span>
        <span @class(['ml-1 min-w-0 truncate font-mono', 'text-xs' => $lg, 'text-thumb' => ! $lg, 'text-ink-muted' => $route, 'text-ink-faint italic' => ! $route])>{{ $route ?? 'no route named' }}</span>
    </div>
    <div class="relative aspect-16/10 overflow-hidden">
        @switch($p['kind'])
            @case('shot')
                <img src="{{ $p['url'] }}" loading="lazy" alt="{{ $step['name'] }}, as the journey test saw it" title="{{ $captured ? 'Captured '.$captured.' UTC' : 'Journey shot' }}"
                    class="absolute inset-0 size-full object-cover object-top">
                @if ($lg && $captured)
                    <span class="absolute right-1 bottom-1 rounded bg-ground/75 {{ $tag }} font-medium whitespace-nowrap text-ink">Captured {{ $captured }} UTC</span>
                @endif
                @break
            @case('placeholder')
                {{-- A drawn page (resources/css/app.css .map-page), not a picture of this screen: labelled with its route and story. --}}
                <div class="map-page absolute inset-0" aria-hidden="true"></div>
                <div class="absolute inset-0 grid place-items-center p-2 text-center">
                    <div class="rounded-md px-2 py-1 bg-surface/90">
                        <p @class(['font-mono font-medium text-ink-soft', 'text-sm' => $lg, 'text-thumb' => ! $lg])>{{ $step['story'] }} · {{ $route ?? 'no route' }}</p>
                        @if ($lg)
                            <p class="mt-1 text-xs text-ink-muted">Built · a drawn placeholder. The real screen appears once this project's journey tests save shots.</p>
                        @endif
                    </div>
                </div>
                <span class="absolute right-1 bottom-1 rounded bg-ground/75 {{ $tag }} font-medium whitespace-nowrap text-ink">placeholder</span>
                @break
            @case('mockup')
                <div class="absolute inset-0 bg-raised" x-data="mockupFit(() => 1280)">
                    {{-- A live render at a desktop viewport, scaled in; inert and sandboxed like every mockup (SB-21). --}}
                    <iframe src="{{ $p['url'] }}" sandbox="allow-scripts" loading="lazy" tabindex="-1" aria-hidden="true" title="{{ $step['story'] }} option {{ strtoupper((string) $p['option']) }}"
                        class="pointer-events-none absolute top-0 left-0 origin-top-left border-0 bg-white" x-bind:style="frameStyle"></iframe>
                </div>
                <span class="absolute right-1 bottom-1 rounded bg-pick {{ $tag }} font-medium whitespace-nowrap text-white">mockup · option {{ strtoupper((string) $p['option']) }}</span>
                @break
            @default
                @php
                    [$title, $line] = match ($p['kind']) {
                        'awaiting' => ['Awaiting pick', 'Mockups exist; none is chosen yet.'],
                        'mockups' => ['No pick recorded', 'Mockups exist; the story records no option the board can read.'],
                        'unlinked' => ['Not linked', 'This step names no story, so there is nothing to show.'],
                        default => ['No mockup yet', 'Pending, and no mockup has been drawn for it.'],
                    };
                @endphp
                <div @class(['absolute inset-0 grid place-items-center text-center', 'bg-warning/10' => $p['kind'] === 'awaiting', 'bg-surface' => $p['kind'] !== 'awaiting'])>
                    <div class="px-2">
                        <svg @class(['mx-auto text-ink-faint', 'size-8' => $lg, 'size-3' => ! $lg]) viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M8 14h8"/></svg>
                        <p @class(['font-medium', 'mt-1 text-sm' => $lg, 'text-thumb' => ! $lg, 'text-warning' => $p['kind'] === 'awaiting', 'text-ink-muted' => $p['kind'] !== 'awaiting'])>{{ $title }}</p>
                        @if ($lg)
                            <p class="mt-1 text-xs text-ink-muted">{{ $line }}</p>
                        @endif
                    </div>
                </div>
        @endswitch
    </div>
</div>

{{-- RD-2 shell sidebar, Home A (docs/mockups/RD-2/option-a.html): logo, Home, Mockups with the awaiting count, then
     the enabled projects with state dot and ID prefix. Below 768px it is a drawer behind the top bar's menu button;
     `open` lives in layouts/shell's x-data. Pure UI, so all of it is Alpine. Every target is at least 44px. --}}
@php
    $item = 'flex min-h-11 w-full items-center gap-2.5 rounded-lg px-3 text-sm outline-needs focus-visible:outline-2 focus-visible:outline-offset-2';
    $state = fn (bool $on) => $on ? 'bg-raised font-medium text-ink' : 'text-ink-soft hover:bg-raised hover:text-ink';
    $iconButton = 'grid size-11 place-items-center rounded-lg text-ink-soft outline-needs hover:bg-raised hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2';
@endphp
<div class="sticky top-0 z-30 flex h-14 items-center gap-2 border-b border-hairline bg-ground/95 px-2 backdrop-blur md:hidden">
    <button type="button" data-shell-menu aria-label="Open menu" aria-controls="shell-sidebar" x-bind:aria-expanded="open.toString()" x-on:click="open = true" class="{{ $iconButton }}">
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <x-shell.logo />
</div>

<div x-show="open" x-cloak x-on:click="open = false" class="fixed inset-0 z-40 bg-ground/70 md:hidden" aria-hidden="true"></div>

<aside id="shell-sidebar" data-shell-sidebar aria-label="Board" x-bind:data-open="open" x-trap.noscroll="open" x-on:keydown.escape.window="open = false"
    class="board-drawer fixed inset-y-0 left-0 z-50 hidden w-72 flex-col gap-6 overflow-y-auto border-r border-hairline bg-surface px-3.5 py-4 data-open:flex md:flex md:w-58 md:bg-ground md:py-5">
    <div class="flex items-center justify-between pl-1.5">
        <x-shell.logo />
        <button type="button" data-shell-close aria-label="Close menu" x-on:click="open = false" class="{{ $iconButton }} md:hidden">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </div>

    <nav aria-label="Main" class="flex flex-col gap-0.5">
        <a href="{{ route('home') }}" wire:navigate data-nav="home" @if ($onHome) aria-current="page" @endif class="{{ $item }} {{ $state($onHome) }}">Home</a>
        <a href="{{ route('mockups') }}" wire:navigate data-nav="mockups" @if ($onMockups) aria-current="page" @endif class="{{ $item }} {{ $state($onMockups) }}">
            Mockups
            @if ($awaiting > 0)
                <span data-mockups-count="{{ $awaiting }}" title="{{ $awaiting }} awaiting your pick"
                    class="ml-auto rounded-full border border-rim px-2 py-0.5 font-mono text-xs text-ink-soft">{{ $awaiting }}<span class="sr-only"> awaiting your pick</span></span>
            @endif
        </a>
    </nav>

    <nav aria-label="Projects" class="flex flex-col gap-0.5">
        <p class="px-3 pb-1.5 text-xs font-medium tracking-wider text-ink-muted uppercase">Projects</p>
        @forelse ($projects as $p)
            @php $on = $current === $p->name; @endphp
            <a href="{{ route('projects.stories', $p->name) }}" wire:navigate data-nav-project="{{ $p->name }}" @if ($on) aria-current="page" @endif class="{{ $item }} {{ $state($on) }}">
                <x-board.state :state="$p->state" title="State: {{ $p->state }}" />
                <span class="min-w-0 truncate">{{ $p->name }}</span>
                <span class="sr-only">, {{ $p->state }}</span>
                @isset ($prefixes[$p->id])
                    <span class="ml-auto font-mono text-xs text-ink-faint" data-id-prefix>{{ $prefixes[$p->id] }}</span>
                @endisset
            </a>
        @empty
            {{-- Empty state: the one next action is adding a project. --}}
            <p data-shell-no-projects class="px-3 py-2 text-sm text-ink-muted">
                No projects yet.
                <a href="{{ route('projects.manage') }}" wire:navigate class="inline-flex min-h-11 items-center font-medium text-needs underline-offset-4 outline-needs hover:underline focus-visible:outline-2">Add a project</a>
            </p>
        @endforelse
    </nav>
</aside>

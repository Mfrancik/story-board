{{-- SB-11 "Live now", design A (docs/mockups/SB-7/option-a.html, views 1 and 2). The poll lives on this
     panel only, never the page. Nothing from a transcript renders except what cwd, gitBranch and the file
     time say: the project and checkout (from cwd), the branch, and "active N min ago". Story text comes
     from git (ListLiveSessions), never from the session. Every linked story reserves its thumbnail box,
     so a poll that swaps a card's contents does not move the page. --}}
@php
    $count = count($sessions);
    $label = 'text-sm font-semibold tracking-wider text-zinc-500 uppercase dark:text-zinc-400';
    $note = 'rounded-xl border border-dashed border-zinc-200 p-5 text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400';
@endphp
<section wire:poll.30s data-live-panel="{{ $status }}" aria-labelledby="live-title" class="mt-10">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="live-title" class="{{ $label }}">Live now</h2>
        @if ($status === \App\Services\SessionReader::OK && $count > 0)
            <span class="text-xs text-zinc-500 dark:text-zinc-400">
                {{ $count }} {{ $project ? Str::plural('session', $count).' in '.$project : 'Claude Code '.Str::plural('session', $count) }} · metadata only
            </span>
        @endif
    </div>

    @if ($status === \App\Services\SessionReader::MISSING)
        <p class="mt-3 {{ $note }}">
            <span class="block font-medium text-zinc-700 dark:text-zinc-300">No Claude Code sessions folder found</span>
            Looked in <code class="text-xs">{{ $root }}</code>. Set <code class="text-xs">BOARD_SESSIONS_PATH</code> if Claude Code keeps its sessions elsewhere.
        </p>
    @elseif ($status === \App\Services\SessionReader::UNAVAILABLE)
        <p class="mt-3 {{ $note }}">
            <span class="block font-medium text-zinc-700 dark:text-zinc-300">Sessions unavailable</span>
            No live session file could be read. The log names each one under <code class="text-xs">board.session_unreadable</code>.
        </p>
    @elseif ($count === 0)
        <p class="mt-3 {{ $note }}">
            <span class="block font-medium text-zinc-700 dark:text-zinc-300">No live sessions</span>
            A Claude Code session {{ $project ? 'in '.$project : 'in a project on the board' }} shows here within 30 seconds of its last activity.
        </p>
    @else
        <div class="mt-3 grid gap-4 sm:grid-cols-2 {{ $project ? '' : 'xl:grid-cols-3' }}">
            @foreach ($sessions as $s)
                @php $minutes = (int) floor($s['active_at']->diffInSeconds(now(), true) / 60); @endphp
                <article wire:key="live-{{ $s['key'] }}" data-live-session data-live-project="{{ $s['project'] }}" x-data
                    class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate text-sm font-medium">{{ $s['project'] }} <span class="font-normal text-zinc-400">· {{ $s['checkout'] }}</span></span>
                        <time datetime="{{ $s['active_at']->toIso8601String() }}" title="Last activity {{ $s['active_at']->toDateTimeString() }}"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-ok/15 px-2 py-0.5 text-xs font-medium text-zinc-800 dark:text-zinc-100">
                            <span class="live-pulse size-1.5 rounded-full bg-ok" aria-hidden="true"></span>active {{ $minutes < 1 ? 'just now' : $minutes.' min ago' }}
                        </time>
                    </div>
                    <p class="mt-1 truncate font-mono text-xs text-zinc-500 dark:text-zinc-400" title="{{ $s['branch'] ?? 'no branch' }}">
                        {{ match ($s['branch']) { null => 'no branch', 'HEAD' => 'detached HEAD', default => $s['branch'] } }}
                    </p>

                    @forelse ($s['stories'] as $link)
                        @php $story = $link['story']; $ref = $s['project'].'/'.$story->story_id; @endphp
                        <button type="button" data-live-story="{{ $ref }}" aria-haspopup="dialog" x-on:click="$dispatch('board-story', @js($ref))"
                            class="mt-3 block w-full rounded-lg border border-zinc-200 p-3 text-left hover:border-zinc-300 focus-visible:outline-2 focus-visible:outline-accent dark:border-zinc-800 dark:hover:border-zinc-700">
                            <span class="flex items-center justify-between gap-2">
                                <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $story->story_id }}</span>
                                <x-board.status-chip :status="$story->status" :errors="count($story->parse_errors)" />
                            </span>
                            <span class="mt-0.5 block text-sm font-medium">{{ $story->title ?? $story->path }}</span>
                            @if ($link['line'])<span data-live-line class="mt-1 block text-xs text-zinc-500 dark:text-zinc-400">{{ $link['line'] }}</span>@endif
                            @if ($story->location)<span class="mt-1.5 block text-xs text-warning">Not on main · {{ $story->location }}</span>@endif
                            <span class="mt-2.5 flex items-center gap-2">
                                @if ($link['thumb'])
                                    <span class="mockup-thumb-box block shrink-0 rounded bg-white ring-2 ring-built" data-live-thumb="{{ $link['chosen'] }}">
                                        {{-- A scaled-down live render, inert and sandboxed like every mockup (SB-8's thumbnail). --}}
                                        <iframe src="{{ $link['thumb'] }}" sandbox="allow-scripts" loading="lazy" tabindex="-1" title="Option {{ $link['chosen'] }}" class="mockup-thumb"></iframe>
                                    </span>
                                    <span class="font-mono text-xs text-zinc-600 dark:text-zinc-300">option-{{ $link['chosen'] }} · chosen</span>
                                @else
                                    {{-- Same box, empty: the card keeps its height whichever way a poll goes. --}}
                                    <span class="mockup-thumb-box block shrink-0 rounded border border-dashed border-zinc-200 dark:border-zinc-700" aria-hidden="true"></span>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">No chosen mockup</span>
                                @endif
                            </span>
                        </button>
                    @empty
                        <p class="mt-3 rounded-lg border border-dashed border-zinc-200 p-3 text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">No story linked</p>
                    @endforelse
                </article>
            @endforeach
        </div>
    @endif
</section>

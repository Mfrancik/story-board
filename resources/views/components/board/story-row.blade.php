{{--
    One story in a list. A button that opens the story modal (SB-8): it dispatches `board-story`
    with `<project>/<ID>`, which StoryModal turns into `?story=`. No round trip on the list itself.
    A row whose ID is not well formed (an oddly named mockup directory off main) cannot be linked
    by `?story=`, so it stays a plain, non-interactive row.
--}}
@props(['story', 'group' => null])
@php
    $options = $story->mockups['options'] ?? [];
    $errors = count($story->parse_errors);
    // Mockup-only off-main rows have no status of their own; that is not an error.
    $showStatus = ! $story->isMockupOnly() && ($errors > 0 || ! in_array($story->status, ['draft', 'approved', 'built'], true));
    $link = $story->hasPage() ? $story->project->name.'/'.$story->story_id : null;
    $tag = $link ? 'button' : 'div';
@endphp
<div wire:key="row-{{ $story->id }}" x-data>
    <{{ $tag }} data-row="{{ $story->story_id }}" @if ($group === 'pick') data-pick-row="{{ $story->story_id }}" @endif
        @if ($link) type="button" data-story-link="{{ $link }}" aria-haspopup="dialog" x-on:click="$dispatch('board-story', @js($link))" @endif
        class="grid w-full grid-cols-[auto_1fr] items-baseline gap-x-3 gap-y-0.5 px-3 py-2 text-left sm:grid-cols-[8rem_6rem_1fr_auto] {{ $link ? 'hover:bg-zinc-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent dark:hover:bg-zinc-800/50' : '' }}">
        <span class="font-mono text-sm font-medium sm:order-2">{{ $story->story_id ?? '—' }}</span>
        <span class="truncate text-right text-xs text-zinc-500 sm:order-1 sm:text-left dark:text-zinc-400">{{ $story->project->name }}</span>
        <span class="col-span-2 min-w-0 truncate text-sm sm:order-3 sm:col-span-1">
            {{ $story->title ?? $story->path }}
            @if ($group === 'pick')<span class="text-xs text-zinc-500 dark:text-zinc-400">· options {{ implode(', ', $options) }}</span>@endif
            @if ($showStatus)<x-board.status-chip :status="$story->status" :errors="$errors" class="ml-1" />@endif
        </span>
        <span class="hidden text-xs text-zinc-500 sm:order-4 sm:inline dark:text-zinc-400">{{ $story->initiative }}</span>
        @if ($story->location)
            {{-- SB-5: where this version lives, and what it says there. --}}
            <span data-offmain-row="{{ $story->story_id }}" class="col-span-2 flex flex-wrap items-center gap-1.5 text-xs text-warning sm:order-5 sm:col-span-4">
                <span class="break-all font-mono">{{ $story->location }}</span>
                @if ($story->isMockupOnly())
                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">mockups only</span>
                @elseif ($story->status)
                    <x-board.status-chip :status="$story->status" :errors="$errors" />
                @endif
                @if ($story->mockups['chosen'] ?? null)<span class="rounded bg-zinc-100 px-1.5 py-0.5 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">picked {{ strtoupper($story->mockups['chosen']) }}</span>@endif
            </span>
        @endif
    </{{ $tag }}>
</div>

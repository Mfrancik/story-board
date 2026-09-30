{{-- The header of a project's sub-page (SB-14 Handbook, SB-15 Stories): breadcrumb to the project's dashboard,
     name with its state, ref and snapshot SHA, the dark-mode toggle, then the project tabs with `current` marked.
     The slot adds to the ref line (" · 946 stories in 57 initiatives"). The dashboard keeps its own header:
     it carries Refresh this project and the status tallies. --}}
@props(['model', 'page', 'current'])
<header class="flex flex-wrap items-end justify-between gap-3">
    <div class="min-w-0">
        <p class="text-xs text-zinc-500 dark:text-zinc-400">
            <a href="{{ route('home') }}" wire:navigate class="hover:underline">All projects</a> <span aria-hidden="true">/</span>
            <a href="{{ route('projects.show', $model->name) }}" wire:navigate class="hover:underline">{{ $model->name }}</a> <span aria-hidden="true">/</span> {{ $page }}
        </p>
        <h1 class="mt-1 flex items-center gap-2 text-2xl font-semibold tracking-tight">
            <span class="truncate">{{ $model->name }}</span>
            <x-board.state :state="$model->state" title="State: {{ $model->state }}" />
            <span class="sr-only">, state {{ $model->state }}</span>
            <x-board.state :state="$model->state" part="label" />
        </h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            <span class="font-mono text-xs">{{ $model->sha ? $model->ref.' @ '.substr($model->sha, 0, 8) : $model->ref.' · no snapshot yet' }}</span> {{ $slot }}
        </p>
    </div>
    <button type="button" x-data x-on:click="$flux.dark = ! $flux.dark" aria-label="Toggle dark mode"
        class="rounded-md border border-zinc-300 px-2 py-1.5 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">◐</button>
</header>

<x-board.project-tabs :project="$model->name" :current="$current" />

{{-- The header of a project's sub-page (RD-11 Overview, SB-14 Handbook, SB-15 Stories, SB-16 Preflight, SB-24 App map): breadcrumb,
     name with its state, ref and snapshot SHA, then the project tabs with `current` marked. The slot adds to the ref
     line (" · 946 stories in 57 initiatives"). RD-4: every page that uses it is in the dark shell, so it carries the
     shell tokens only and has no light/dark toggle. RD-11: the breadcrumb's project link opens the project's Overview,
     as the shell sidebar's does. `actions` is an optional slot on the right of the name (the Overview's Refresh). --}}
@props(['model', 'page', 'current', 'actions' => null])
<header class="flex min-w-0 flex-wrap items-start gap-x-4 gap-y-3">
<div class="mr-auto min-w-0">
    <p class="text-xs text-ink-muted">
        <a href="{{ route('home') }}" wire:navigate class="hover:underline">All projects</a> <span aria-hidden="true">/</span>
        <a href="{{ route('projects.overview', $model->name) }}" wire:navigate data-crumb-project class="hover:underline">{{ $model->name }}</a> <span aria-hidden="true">/</span> {{ $page }}
    </p>
    <h1 class="mt-1 flex items-center gap-2 text-2xl font-semibold tracking-tight">
        <span class="truncate">{{ $model->name }}</span>
        <x-board.state :state="$model->state" title="State: {{ $model->state }}" />
        <span class="sr-only">, state {{ $model->state }}</span>
        <x-board.state :state="$model->state" part="label" />
    </h1>
    <p class="mt-1 text-sm text-ink-muted">
        <span class="font-mono text-xs">{{ $model->sha ? $model->ref.' @ '.substr($model->sha, 0, 8) : $model->ref.' · no snapshot yet' }}</span> {{ $slot }}
    </p>
</div>
@if ($actions)
    {{ $actions }}
@endif
</header>

<x-board.project-tabs :project="$model->name" :current="$current" />

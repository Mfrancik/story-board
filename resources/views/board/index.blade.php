{{-- SB-2: the bare list that proves the snapshot. SB-3 replaces this page with the "What needs me" home. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => 'Projects'])
    </head>
    <body class="min-h-screen bg-white p-6 text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100">
        <h1 class="text-lg font-semibold">Projects</h1>

        @if ($projects->isEmpty())
            <p class="mt-4 text-sm text-zinc-500">No projects registered. Run <code>php artisan board:project add &lt;path&gt;</code>.</p>
        @else
            <table class="mt-4 text-sm">
                <thead>
                    <tr class="text-left text-zinc-500">
                        <th class="py-1 pr-6 font-medium">Project</th>
                        <th class="py-1 pr-6 font-medium">Stories</th>
                        <th class="py-1 pr-6 font-medium">State</th>
                        <th class="py-1 pr-6 font-medium">Ref</th>
                        <th class="py-1 font-medium">Indexed</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($projects as $project)
                        <tr data-project="{{ $project->name }}">
                            <td class="py-1 pr-6">{{ $project->name }}</td>
                            <td class="py-1 pr-6 tabular-nums" data-story-count="{{ $project->stories_count }}">{{ $project->stories_count }}</td>
                            <td class="py-1 pr-6">{{ $project->state }}</td>
                            <td class="py-1 pr-6 font-mono">{{ $project->ref }} @ {{ substr((string) $project->sha, 0, 8) ?: '—' }}</td>
                            <td class="py-1">{{ $project->indexed_at?->diffForHumans() ?? 'never' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </body>
</html>

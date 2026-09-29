{{-- Handbook › Skills (SB-14): the project's .claude/skills/*/ folders. With the kit: the kit's skills
     (same, changed or missing by their SKILL.md) and then the project's own. Without it, one plain list. --}}
@php
    $groups = $kit
        ? ['kit' => 'From the kit', 'project' => 'Project only']
        : ['all' => 'Skills'];
@endphp
@if ($skills === [])
    <x-board.handbook-empty :project="$project" file=".claude/skills/">Skills appear here once the project has a .claude/skills/ folder.</x-board.handbook-empty>
@else
    <div class="space-y-8 p-5">
        @foreach ($groups as $from => $title)
            @php($rows = array_values(array_filter($skills, fn ($s) => $from === 'all' || $s['from'] === $from)))
            @continue($rows === [])
            <div data-skill-group="{{ $from }}">
                <h2 class="text-sm font-semibold tracking-wider text-zinc-500 uppercase dark:text-zinc-400">
                    {{ $title }} <span class="font-normal tabular-nums">· {{ count($rows) }}</span>
                </h2>
                <ul class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($rows as $skill)
                        <li data-skill="{{ $skill['name'] }}" @if ($skill['badge']) data-badge="{{ $skill['badge'] }}" @endif wire:key="skill-{{ $skill['name'] }}"
                            @class([
                                'flex flex-wrap items-center justify-between gap-2 rounded-lg border p-4',
                                'border-dashed border-danger/40' => $skill['badge'] === \App\Actions\Board\ReadHandbook::MISSING,
                                'border-zinc-200 dark:border-zinc-800' => $skill['badge'] !== \App\Actions\Board\ReadHandbook::MISSING,
                            ])>
                            <span class="font-mono text-sm font-medium">{{ $skill['name'] }}</span>
                            @if ($skill['badge'])
                                <x-board.kit-badge :badge="$skill['badge']" />
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
@endif

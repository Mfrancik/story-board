{{--
    Under a project's name on /prod (SB-18): whether its read is outstanding, when it was last read, and — when the
    last read failed — a badge naming why (Unreachable, Refused: this user can now write, …) and the reader's own
    redacted sentence. The values beside it are then the last good ones, greyed. `row` is ListProductionDashboard's.
--}}
@props(['row'])
@php $failed = in_array($row['state'], ['error', 'stalled'], true); @endphp
<p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
    @if ($row['state'] === 'loading')
        Reading…
    @elseif ($failed)
        {{ $row['readAgo'] ? 'last good read '.$row['readAgo'] : 'no good read yet' }}
    @elseif ($row['readAgo'])
        read {{ $row['readAgo'] }}
    @else
        not read yet
    @endif
    @if ($row['historySince'] && $row['state'] !== 'loading')
        · history since {{ $row['historySince'] }}
    @endif
</p>
@if ($failed)
    <div role="status" data-prod-error="{{ $row['name'] }}">
        <span data-prod-error-label class="mt-2 inline-flex items-center gap-1 rounded-full bg-danger/10 px-2 py-0.5 text-xs font-medium text-danger ring-1 ring-danger/30 ring-inset">
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>{{ $row['error']['label'] }}
        </span>
        <p class="mt-1 text-xs break-words text-danger">{{ $row['error']['message'] }}</p>
    </div>
@endif

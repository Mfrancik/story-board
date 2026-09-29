{{--
    A production metric's last Test result, inline beside its Test button (SB-17; the story asks for inline status,
    not a toast). `result` is MetricReading::toArray() or null before the first Test; `target` is the Livewire call
    that runs the Test, so "Running…" shows while it does. A value carries `data-metric-value` for tests.
--}}
@props(['result' => null, 'target'])
<span role="status" aria-live="polite" {{ $attributes->class('min-w-0 text-sm tabular-nums') }}>
    <span wire:loading wire:target="{{ $target }}" class="text-zinc-500">Running…</span>
    @if ($result)
        <span wire:loading.remove wire:target="{{ $target }}">
            @if ($result['status'] === 'ok')
                <span class="font-semibold" data-metric-value="{{ $result['value'] }}">{{ is_int($result['value']) ? number_format($result['value']) : $result['value'] }}</span>
                <span class="text-xs text-zinc-500">· {{ $result['ms'] }} ms</span>
            @elseif ($result['status'] === 'timed_out')
                <span class="text-xs text-warning">{{ $result['message'] }}</span>
            @else
                <span class="text-xs break-words text-danger">{{ $result['message'] }}</span>
            @endif
        </span>
    @endif
</span>

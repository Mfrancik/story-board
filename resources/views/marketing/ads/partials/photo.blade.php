{{-- The post's photo as a data URL (no file or network reads at render time), cropped by the spec's crop. `$class`
     adds `fill` to cover a positioned slot. --}}
<img class="img {{ $class ?? '' }}" src="{{ $ad['photo'] }}" alt="" style="object-position:{{ $ad['crop'] }}">

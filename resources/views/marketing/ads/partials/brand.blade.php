{{-- The brand lockup: logo (SanitiseSvg's output) + name. The mockups' "Beta" chip is StackerMint's own and the brand
     has no field for it, so it is not drawn. --}}
<div class="brand">@if ($ad['logo'])<span class="swirl" aria-hidden="true">{!! $ad['logo'] !!}</span>@endif<span>{{ $ad['brand'] }}</span></div>

{{-- Poster · stat (mockup C5): the number on an accent block beside (4:5: above) the eyebrow, subline and cta. --}}
<div class="main"><div class="num">@include('marketing.ads.partials.headline', ['ad' => $ad])</div>
<div class="txt">@if ($ad['eyebrow'] !== '')<span class="eyebrow">{{ $ad['eyebrow'] }}</span>@endif @if ($ad['subline'])<p class="sub">{{ $ad['subline'] }}</p>@endif @if ($ad['cta'])<span class="cta">{{ $ad['cta'] }}</span>@endif</div></div>
<div class="foot">@include('marketing.ads.partials.brand', ['ad' => $ad])<span class="tag">{{ $ad['eyebrow'] }}</span></div>

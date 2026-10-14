{{-- Poster · photo + headline (mockup C1): the frame split photo | text (stacked at 4:5) over the ink footer bar. --}}
<div class="main"><div class="pane">@include('marketing.ads.partials.photo', ['ad' => $ad, 'class' => 'fill'])</div>
<div class="txt">@if ($ad['eyebrow'] !== '')<span class="eyebrow">{{ $ad['eyebrow'] }}</span>@endif @include('marketing.ads.partials.headline', ['ad' => $ad])@if ($ad['subline'])<p class="sub">{{ $ad['subline'] }}</p>@endif</div></div>
<div class="foot">@include('marketing.ads.partials.brand', ['ad' => $ad])<span class="tag">{{ $ad['eyebrow'] }}</span></div>

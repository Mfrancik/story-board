{{-- Gallery · stat (mockup A5): the number set huge over a decorative soft ring, the subline explaining it, cta in
     the foot. --}}
<span class="ring" aria-hidden="true"></span>
<div class="hdr"><span class="eyebrow">{{ $ad['eyebrow'] }}</span>@include('marketing.ads.partials.brand', ['ad' => $ad])</div>
<div class="body">@include('marketing.ads.partials.headline', ['ad' => $ad])<span class="rule"></span>@if ($ad['subline'])<p class="sub">{{ $ad['subline'] }}</p>@endif
@if ($ad['cta'])<div class="foot"><span></span><span class="cta">{{ $ad['cta'] }}</span></div>@endif</div>

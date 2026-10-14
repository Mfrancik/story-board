{{-- Vault · stat (mockup B5): the number in accent over concentric rings, the subline under it, cta in the foot. --}}
<span class="rings" aria-hidden="true"></span>
<div class="hdr"><span class="eyebrow">{{ $ad['eyebrow'] }}</span>@include('marketing.ads.partials.brand', ['ad' => $ad])</div>
<div class="body">@include('marketing.ads.partials.headline', ['ad' => $ad])@if ($ad['subline'])<p class="sub">{{ $ad['subline'] }}</p>@endif</div>
@if ($ad['cta'])<div class="foot"><span></span><span class="cta">{{ $ad['cta'] }}</span></div>@endif

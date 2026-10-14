{{-- Gallery · question (mockup A3): a soft ring with an accent question mark over the centred headline. --}}
<div class="hdr"><span class="eyebrow">{{ $ad['eyebrow'] }}</span>@include('marketing.ads.partials.brand', ['ad' => $ad])</div>
<div class="body"><span class="qmark" aria-hidden="true">?</span>@include('marketing.ads.partials.headline', ['ad' => $ad])@if ($ad['subline'])<p class="sub">{{ $ad['subline'] }}</p>@endif</div>

{{-- Gallery · coin spotlight (mockup A4, kit 02): the photo on a white card with the badge tab; cta as the ink pill. --}}
<div class="hdr"><span class="eyebrow">{{ $ad['eyebrow'] }}</span>@include('marketing.ads.partials.brand', ['ad' => $ad])</div>
<div class="stage"><div class="card">@if ($ad['badge'])<span class="badge">{{ $ad['badge'] }}</span>@endif
<div class="shot">@include('marketing.ads.partials.photo', ['ad' => $ad])</div>
@include('marketing.ads.partials.headline', ['ad' => $ad, 'tag' => 'h2'])@if ($ad['subline'])<p class="sub">{{ $ad['subline'] }}</p>@endif @if ($ad['cta'])<span class="pill">{{ $ad['cta'] }}</span>@endif</div></div>

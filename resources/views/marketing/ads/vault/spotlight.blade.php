{{-- Vault · coin spotlight (mockup B4): the photo in an accent glow with the badge as a rotated stamp. --}}
<span class="glow"></span>
<div class="hdr"><span class="eyebrow">{{ $ad['eyebrow'] }}</span>@include('marketing.ads.partials.brand', ['ad' => $ad])</div>
<div class="stage"><div style="position:relative"><div class="shot">@include('marketing.ads.partials.photo', ['ad' => $ad])</div>@if ($ad['badge'])<span class="stamp">{{ $ad['badge'] }}</span>@endif</div></div>
<div class="txt">@include('marketing.ads.partials.headline', ['ad' => $ad, 'tag' => 'h2'])@if ($ad['subline'])<p class="sub">{{ $ad['subline'] }}</p>@endif @if ($ad['cta'])<span class="cta">{{ $ad['cta'] }}</span>@endif</div>

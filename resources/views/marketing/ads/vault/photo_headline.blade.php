{{-- Vault · photo + headline (mockup B1): the photo bleeds off the right edge under an ink scrim; the badge (or the
     cta) sits bottom right. --}}
<div class="bleed">@include('marketing.ads.partials.photo', ['ad' => $ad, 'class' => 'fill'])</div><div class="scrim"></div>
<div class="hdr"><span class="eyebrow">{{ $ad['eyebrow'] }}</span>@include('marketing.ads.partials.brand', ['ad' => $ad])</div>
<div class="hl z">@include('marketing.ads.partials.headline', ['ad' => $ad])</div>
<div class="bot z"><p class="sub">{{ $ad['subline'] }}</p>@if ($ad['badge'])<span class="badge">{{ $ad['badge'] }}</span>@elseif ($ad['cta'])<span class="cta">{{ $ad['cta'] }}</span>@endif</div>

{{-- Gallery · photo + headline (mockup A1): the photo on a soft panel, the headline and subline under it. --}}
<div class="hdr"><span class="eyebrow">{{ $ad['eyebrow'] }}</span>@include('marketing.ads.partials.brand', ['ad' => $ad])</div>
<div class="photo">@include('marketing.ads.partials.photo', ['ad' => $ad, 'class' => 'fill'])</div>
<div class="txt">@include('marketing.ads.partials.headline', ['ad' => $ad])@if ($ad['subline'])<p class="sub">{{ $ad['subline'] }}</p>@endif</div>

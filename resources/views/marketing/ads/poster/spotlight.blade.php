{{-- Poster · coin spotlight (mockup C4): the photo on a soft pane over an accent band, the badge as an ink stamp. --}}
<div class="pane"><div class="shot">@include('marketing.ads.partials.photo', ['ad' => $ad])</div>@if ($ad['badge'])<span class="stamp">{{ $ad['badge'] }}</span>@endif</div>
<div class="txt">@if ($ad['eyebrow'] !== '')<span class="eyebrow">{{ $ad['eyebrow'] }}</span>@endif @include('marketing.ads.partials.headline', ['ad' => $ad, 'tag' => 'h2'])@if ($ad['subline'])<p class="sub">{{ $ad['subline'] }}</p>@endif</div>
<div class="foot">@include('marketing.ads.partials.brand', ['ad' => $ad])<span class="tag">{{ $ad['eyebrow'] }}</span></div>

{{-- Poster · bold text card (mockup C2): a full accent poster with the logo watermark, the cta as an ink button. --}}
@if ($ad['logo'])<span class="wm" aria-hidden="true">{!! $ad['logo'] !!}</span>@endif
<div class="body"><span class="eyebrow">{{ $ad['eyebrow'] }}</span>
<div class="mid">@include('marketing.ads.partials.headline', ['ad' => $ad])@if ($ad['subline'])<p class="sub">{{ $ad['subline'] }}</p>@endif</div>
@if ($ad['cta'])<span class="cta">{{ $ad['cta'] }}</span>@endif</div>
<div class="foot">@include('marketing.ads.partials.brand', ['ad' => $ad])<span class="tag">{{ $ad['eyebrow'] }}</span></div>

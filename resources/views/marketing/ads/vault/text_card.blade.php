{{-- Vault · bold text card (mockup B2): an accent bar beside the headline and the logo as a large watermark, so a
     text-only post still reads as the brand. --}}
@if ($ad['logo'])<span class="wm" aria-hidden="true">{!! $ad['logo'] !!}</span>@endif
<div class="hdr"><span class="eyebrow">{{ $ad['eyebrow'] }}</span></div>
<div class="body"><div class="fitwrap">@include('marketing.ads.partials.headline', ['ad' => $ad])</div>@if ($ad['subline'])<p class="sub">{{ $ad['subline'] }}</p>@endif @if ($ad['cta'])<span class="cta" style="align-self:flex-start">{{ $ad['cta'] }}</span>@endif</div>
<div class="hdr">@include('marketing.ads.partials.brand', ['ad' => $ad])</div>

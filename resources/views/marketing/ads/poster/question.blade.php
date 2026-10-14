{{-- Poster · question (mockup C3): a soft ground, a huge accent quote mark, the headline, the subline after a rule. --}}
<div class="body"><span class="quo" aria-hidden="true">&ldquo;</span>@include('marketing.ads.partials.headline', ['ad' => $ad])@if ($ad['subline'])<p class="sub">{{ $ad['subline'] }}</p>@endif</div>
<div class="foot">@include('marketing.ads.partials.brand', ['ad' => $ad])<span class="tag">{{ $ad['eyebrow'] }}</span></div>

{{-- Vault · question (mockup B3): a big accent quote mark, the eyebrow, the centred headline; the lockup below. --}}
<div class="body"><span class="quo" aria-hidden="true">&ldquo;</span>@if ($ad['eyebrow'] !== '')<span class="eyebrow">{{ $ad['eyebrow'] }}</span>@endif @include('marketing.ads.partials.headline', ['ad' => $ad])@if ($ad['subline'])<p class="sub">{{ $ad['subline'] }}</p>@endif</div>
@include('marketing.ads.partials.brand', ['ad' => $ad])

{{-- MK-5: one ad as a self-contained page, opened by bin/render-ad.mjs over file:// and screenshotted at its true
     size (1080×1080 or 1080×1350). Built by App\Actions\Marketing\BuildAdHtml, which passes every value: the CSS
     (base + the campaign's style, ported from docs/mockups/MK-5), the brand's fonts as data URLs, the brand values as
     CSS variables, and `$ad` (the spec plus what the layout draws). No JS, no external URL: the renderer refuses any
     request that is not a file or data URL. Unescaped: the CSS this app ships, `$vars` (BuildAdHtml checks every
     colour and the font against the brand's own patterns before printing them) and the logo (SanitiseSvg's output). --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $ad['headline'] }}</title>
<style>
{!! $fonts !!}
{!! $css !!}
</style>
</head>
<body>
<div class="ad {{ $ad['size'] }} {{ $ad['letter'] }} {{ $ad['letter'] }}-{{ $ad['suffix'] }}" style="{!! $vars !!}" data-ad-style="{{ $ad['style'] }}" data-ad-layout="{{ $ad['layout'] }}">
@include('marketing.ads.'.$ad['style'].'.'.$ad['layout'], ['ad' => $ad])
</div>
</body>
</html>

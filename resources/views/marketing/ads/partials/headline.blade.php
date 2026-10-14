{{-- The headline in its shrink-to-fit box: the layout's CSS gives the box, these give the text's character count, its
     width in em and its widest word's (see .fit in css/base.css). `$tag` h1 or h2, as in the mockups. --}}
<{{ $tag ?? 'h1' }} class="fit" style="--chars:{{ $ad['chars'] }};--ems:{{ $ad['ems'] }};--word-ems:{{ $ad['word_ems'] }}">{{ $ad['headline'] }}</{{ $tag ?? 'h1' }}>

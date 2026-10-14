{{-- The headline in its shrink-to-fit box: the layout's CSS gives the box, these give the text's length and its
     longest word (see .fit in css/base.css). `$tag` h1 or h2, as in the mockups. --}}
<{{ $tag ?? 'h1' }} class="fit" style="--chars:{{ $ad['chars'] }};--word:{{ $ad['word'] }}">{{ $ad['headline'] }}</{{ $tag ?? 'h1' }}>

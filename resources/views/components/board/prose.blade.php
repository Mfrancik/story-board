{{-- Rendered markdown in the handbook's reading width (SB-14). `html` must come from RenderStory::toHtml()
     (raw HTML escaped, unsafe links dropped): it is printed as is. --}}
@props(['html'])
<article {{ $attributes->class('prose prose-zinc max-w-3xl prose-h1:text-2xl prose-h1:font-semibold prose-h1:tracking-tight prose-h2:text-lg prose-code:before:content-none prose-code:after:content-none dark:prose-invert') }}>{!! $html !!}</article>

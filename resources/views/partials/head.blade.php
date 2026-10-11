<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

{{-- The RD-2 shell (`$shell`) is dark only and sets its own Geist from app.css: no Instrument Sans, no Flux light/dark switch. --}}
@unless ($shell ?? false)
    @fonts
@endunless

@vite(['resources/css/app.css', 'resources/js/app.js'])
@unless ($shell ?? false)
    @fluxAppearance
@endunless

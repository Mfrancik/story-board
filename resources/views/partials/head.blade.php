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
@if ($shell ?? false)
    {{-- RD-4: the shell loads flux.js for its toasts. flux.js applies the light/dark choice through
         window.Flux.applyAppearance on load and after every wire:navigate swap, and with none defined it deletes the
         choice the old light/dark pages saved. The shell is dark only, so this one keeps `.dark` off a shell page and
         leaves that saved choice alone; an old page's own appearance script still applies it there. --}}
    <script>
        window.Flux = { applyAppearance () { if (document.documentElement.classList.contains('shell')) document.documentElement.classList.remove('dark') } }
    </script>
@else
    @fluxAppearance
@endif

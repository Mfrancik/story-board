{{-- The RD-2 command-center shell (docs/mockups/RD-2/option-a.html, "Home A"): the graphite ground, the sidebar beside
     the page, Geist throughout. Dark only — `.shell` on <html> points the board's tokens at the dark palette (app.css),
     and there is no light theme or switch. RD-4 moved the kept pages in (Stories, Handbook, Mockups, Manage projects,
     App map) and with them Flux's toasts and scripts. `open` is the mobile drawer's state, shared by the sidebar's menu button,
     backdrop and aside. No auth: a single-owner localhost tool (SB-3). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="shell">
    <head>
        @include('partials.head', ['shell' => true])
    </head>
    <body class="min-h-screen bg-ground font-sans text-ink antialiased">
        <div x-data="{ open: false }">
            <x-shell.sidebar />
            <div class="min-w-0 md:pl-58">
                {{ $slot }}
            </div>
        </div>
        {{-- Toasts (SB-12, SB-21): Manage projects, its Production panels and the mockup viewer confirm with Flux::toast.
             Persisted so one raised just before a wire:navigate reload survives it. Flux styles its toast for light
             and `.dark`, and the shell never sets `.dark` on <html>, so this wrapper alone opts the toast into its dark
             look. --}}
        @persist('toast')
            <div class="dark">
                <flux:toast.group>
                    <flux:toast />
                </flux:toast.group>
            </div>
        @endpersist
        @fluxScripts
    </body>
</html>

{{-- The board's shell: the project sidebar (SB-7) beside the page, no auth — a single-owner localhost tool (SB-3).
     `open` is the mobile drawer's state, shared by the sidebar's menu button, backdrop and aside. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <div x-data="{ open: false }">
            <x-board.sidebar />
            <div class="md:pl-64">
                {{ $slot }}
            </div>
        </div>
        @fluxScripts
    </body>
</html>

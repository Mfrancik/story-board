{{-- A handbook section git could not read (a vanished checkout, a snapshot SHA no longer in the repo). --}}
<div data-read-failed role="status" class="px-5 py-14 text-center">
    <p class="font-medium">Could not read this section of {{ $project }} at {{ substr($sha, 0, 8) }}</p>
    <p class="mt-1 break-words text-sm text-zinc-500 dark:text-zinc-400">git said: {{ $error }}. Refresh the project from its dashboard and try again.</p>
</div>

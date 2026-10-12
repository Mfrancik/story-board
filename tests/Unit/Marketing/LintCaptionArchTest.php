<?php

/**
 * MK-8: the copy-rules lint is pure — no AI, no database, no log — so it can run anywhere, on any caption, for free.
 * DraftOccurrence and ReviseDraft log its warnings (`marketing.lint.warned`).
 */
arch('LintCaption is pure: no AI client, no database, no log, no HTTP')
    ->expect('App\Actions\Marketing\LintCaption')
    ->not->toUse(['App\Services\Marketing', 'Illuminate\Support\Facades', 'App\Models', 'Illuminate\Database']);

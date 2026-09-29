<?php

/*
|--------------------------------------------------------------------------
| Story board settings
|--------------------------------------------------------------------------
|
| Paths outside the board's own database that the board reads (never writes):
| Claude Code's session transcripts (SB-11) and the dev-standards kit that the
| project handbook compares each project with (SB-14).
|
*/

return [

    /*
     * Where Claude Code keeps its session transcripts, one folder per working
     * directory (SB-11, Live sessions). Read only: the board takes a few metadata
     * fields from the newest files and never their message content.
     */
    'sessions_path' => env('BOARD_SESSIONS_PATH', rtrim((string) env('HOME', ''), '/').'/.claude/projects'),

    /*
     * The dev-standards kit's checkout and the ref to read it at (SB-14, Project
     * handbook). Read only, through GitReader: each project's standards files and
     * kit skills are badged against the kit's blobs at this ref. The kit is not a
     * registered project; when it is missing the badges are hidden, nothing else.
     */
    'kit_path' => env('BOARD_KIT_PATH', rtrim((string) env('HOME', ''), '/').'/Code/dev-standards'),

    'kit_ref' => env('BOARD_KIT_REF', 'origin/main'),

];

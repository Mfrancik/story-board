<?php

/*
|--------------------------------------------------------------------------
| Story board settings
|--------------------------------------------------------------------------
|
| Paths outside the board's own database that the board reads (never writes).
| SB-14 will add `kit_path` and `kit_ref` to this same file.
|
*/

return [

    /*
     * Where Claude Code keeps its session transcripts, one folder per working
     * directory (SB-11, Live sessions). Read only: the board takes a few metadata
     * fields from the newest files and never their message content.
     */
    'sessions_path' => env('BOARD_SESSIONS_PATH', rtrim((string) env('HOME', ''), '/').'/.claude/projects'),

];

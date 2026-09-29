<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The Manage projects form could not add a project (SB-12). Carries the form
 * field the refusal belongs under and a stable reason for the log, so the page
 * can show the message inline without parsing it.
 */
class ProjectAddRefusedException extends RuntimeException
{
    /** The path is blank, or no folder exists there. */
    public const MISSING_PATH = 'missing_path';

    /** The folder exists but is not a git repository. */
    public const NOT_A_REPO = 'not_a_repo';

    /** The path or the name is already registered. */
    public const DUPLICATE = 'duplicate';

    /** The ref could be read by git as an option or a range. */
    public const BAD_REF = 'bad_ref';

    /**
     * @param  'path'|'name'|'ref'  $field  the form field the message is shown under
     * @param  self::MISSING_PATH|self::NOT_A_REPO|self::DUPLICATE|self::BAD_REF  $reason  logged as `reason`
     * @param  string  $message  the owner-facing sentence
     */
    public function __construct(public readonly string $field, public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

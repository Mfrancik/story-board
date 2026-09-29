<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A git command the board ran against a project failed, timed out, or was refused.
 */
class GitReaderException extends RuntimeException {}

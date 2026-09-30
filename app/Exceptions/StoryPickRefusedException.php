<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A mockup pick the board would not write (SB-21). The message is the one-line
 * reason shown to the owner; nothing was written to the project.
 */
class StoryPickRefusedException extends RuntimeException {}

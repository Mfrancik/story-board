<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A path could not be registered as a project (missing, not a repo, or already registered).
 */
class ProjectRegistrationException extends RuntimeException {}

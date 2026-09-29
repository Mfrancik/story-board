<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A requested mockup file is not servable: unknown story, no mockup directory,
 * a path outside that directory, or no such file at the ref.
 */
class MockupNotFoundException extends RuntimeException {}

<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A campaign's schedule cannot be worked out: its timezone is not a real one, or
 * its days or time are malformed. The campaigns list shows that row's error state
 * and logs `marketing.runway.failed`; the rest of the page still renders.
 */
class CampaignScheduleException extends RuntimeException {}

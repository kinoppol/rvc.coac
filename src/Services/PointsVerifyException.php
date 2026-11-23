<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A sale spends enough points (MemberService::VERIFY_POINTS or more on one bill) that the buyer must
 * confirm with the last 4 digits of their national ID, and none was given, or it did not match.
 */
class PointsVerifyException extends \RuntimeException
{
}

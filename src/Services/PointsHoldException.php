<?php

declare(strict_types=1);

namespace App\Services;

/** Points held for a cart line are gone (expired, given back) by the time the sale is paid. */
class PointsHoldException extends PromoChangedException
{
}

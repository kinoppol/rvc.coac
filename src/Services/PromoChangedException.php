<?php

declare(strict_types=1);

namespace App\Services;

/** The bundle discount of a sale differs from what the POS showed (a promotion or price changed meanwhile). */
class PromoChangedException extends \RuntimeException
{
}

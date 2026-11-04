<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The action would remove or replace something the user may not mean to (e.g. a product's piece
 * barcode). Controllers answer 409 with the message; the client asks the user and, on a yes,
 * sends the request again with its confirm flag set.
 */
class NeedsConfirmation extends \RuntimeException
{
}

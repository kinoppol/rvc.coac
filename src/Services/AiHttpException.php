<?php

declare(strict_types=1);

namespace App\Services;

/** HTTP-level failure from the AI provider; keeps the status so callers can retry transient errors. */
class AiHttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

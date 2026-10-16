<?php

declare(strict_types=1);

namespace App\Services;

class OaApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0, public readonly string $apiError = '')
    {
        parent::__construct($message);
    }
}

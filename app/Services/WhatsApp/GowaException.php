<?php

namespace App\Services\WhatsApp;

use RuntimeException;

final class GowaException extends RuntimeException
{
    public function __construct(string $safeMessage, public readonly ?int $httpStatus = null, public readonly bool $retryable = false, public readonly bool $ambiguous = false)
    {
        parent::__construct($safeMessage);
    }
}

<?php

declare(strict_types=1);

namespace Maxoxide;

/**
 * Thrown whenever the Max Bot API or the HTTP layer returns an error.
 */
class MaxException extends \RuntimeException
{
    /** HTTP status code (0 = transport / JSON-parse error). */
    private int $apiCode;

    public function __construct(string $message, int $apiCode = 0, ?\Throwable $previous = null)
    {
        $this->apiCode = $apiCode;
        parent::__construct($message, $apiCode, $previous);
    }

    public function getApiCode(): int
    {
        return $this->apiCode;
    }
}

<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class ProviderException extends RuntimeException
{
    public function __construct(
        string $message = 'The top-up provider returned an error.',
        public readonly ?int $httpStatus = null,
        public readonly ?string $providerCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The provider clearly refused the request, so no order exists on its side.
     * Anything else (timeouts, 5xx, 409, 429, odd responses) is an UNKNOWN state:
     * never refund on those, re-check or re-send with the same idempotency key.
     */
    public function isDefinitiveRejection(): bool
    {
        return in_array($this->httpStatus, [400, 403, 404, 422], true);
    }

    public function isAuthError(): bool
    {
        return $this->httpStatus === 401;
    }
}
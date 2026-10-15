<?php

namespace App\Services\Auth;

/**
 * What we accept from Google after a successful sign-in: nothing but these four values.
 */
class GoogleIdentity
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $name,
        public readonly bool $verified,
    ) {
    }
}
<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A customer-facing reason why an order cannot be placed. The message is safe to show.
 */
class OrderRejectedException extends RuntimeException
{
}
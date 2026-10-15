<?php

namespace App\Contracts;

interface TopUpProvider
{
    public function isConfigured(): bool;

    /**
     * Place an order. A retry MUST reuse the same idempotency key.
     *
     * @param  array<string,mixed>  $fields
     * @return array{id:string,status:string,raw:array<string,mixed>}
     */
    public function createOrder(string $categoryId, string $offerId, array $fields, string $idempotencyKey): array;

    /**
     * @return array{id:string,status:string,raw:array<string,mixed>}
     */
    public function getOrder(string $orderId): array;
}
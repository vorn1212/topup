<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\OrderNote;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class OrderNoteService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function add(Order $order, User $author, string $body): OrderNote
    {
        if (! Gate::forUser($author)->allows('orders.manage')) {
            throw new AuthorizationException('You may not add notes to orders.');
        }

        $body = trim($body);

        if (mb_strlen($body) < 2 || mb_strlen($body) > 1000) {
            throw new InvalidArgumentException('A note must be between 2 and 1000 characters.');
        }

        $note = new OrderNote;
        $note->forceFill([
            'order_id' => $order->id,
            'user_id' => $author->id,
            'body' => $body,
        ])->save();

        $this->audit->log('order.note_added', $order, [], [
            'order_number' => $order->order_number,
            'note_id' => $note->id,
        ], $author);

        return $note;
    }
}
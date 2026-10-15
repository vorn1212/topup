<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Payment\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayWayQueuePositionTest extends TestCase
{
    use RefreshDatabase;

    public function test_waiting_customers_see_their_position_in_line(): void
    {
        config(['topup.deposit.min' => '1.00', 'topup.deposit.max' => '500.00']);

        $deposits = app(DepositService::class);
        $first = User::factory()->create();
        $second = User::factory()->create();

        $a = $deposits->create($first, '10.00', DepositService::METHOD_PAYWAY_LINK);
        $b = $deposits->create($second, '10.00', DepositService::METHOD_PAYWAY_LINK);

        $this->actingAs($first)->getJson("/wallet/deposit/{$a->deposit_number}/status")
            ->assertJsonPath('data.queue_position', 1);

        $this->actingAs($second)->getJson("/wallet/deposit/{$b->deposit_number}/status")
            ->assertJsonPath('data.queue_position', 2);

        // Once the first one is picked up by a worker, the second moves to the front.
        $deposits->transition($a->id, null, ['gateway_stage' => 'starting']);

        $this->actingAs($second)->getJson("/wallet/deposit/{$b->deposit_number}/status")
            ->assertJsonPath('data.queue_position', 1);
    }
}
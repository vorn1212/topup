<?php

namespace Tests\Feature;

use App\Enums\WalletTxType;
use App\Jobs\CheckFazerCardsOrderStatusJob;
use App\Jobs\ProcessFazerCardsOrderJob;
use App\Models\ProviderOrder;
use App\Models\User;
use App\Services\TopUp\OrderService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\MakesCatalog;
use Tests\TestCase;

class ProcessPendingOrdersCommandTest extends TestCase
{
    use MakesCatalog, RefreshDatabase;

    private User $user;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fazercards.api_key' => 'test-key-123', 'fazercards.fake' => false]);
        Queue::fake();

        $this->user = User::factory()->create();
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->createWallet($this->user), '50.00', 'test:fund:1', WalletTxType::Bonus);

        $game = $this->makeGame(['slug' => 'pubg-mobile', 'provider_category_id' => 'cat_pubgm_1']);
        $this->makeField($game);
        $this->productId = $this->makeProduct($game, attributes: ['provider_offer_id' => 'offer_60uc'])->id;
    }

    private function providerOrder(array $changes = []): ProviderOrder
    {
        $order = app(OrderService::class)->create($this->user, 'pubg-mobile', $this->productId, ['player_id' => '123456789']);
        $po = ProviderOrder::where('order_id', $order->id)->firstOrFail();

        if ($changes !== []) {
            DB::table('provider_orders')->where('id', $po->id)->update($changes);
        }

        return $po->fresh();
    }

    private function runCommand(): void
    {
        Queue::fake(); // forget the jobs queued while creating the orders
        $this->artisan('orders:process-pending')->assertExitCode(0);
    }

    public function test_missed_and_due_orders_are_sent_again(): void
    {
        $fresh = $this->providerOrder();                                                                          // just created: its own job is on the way
        $missed = $this->providerOrder(['updated_at' => now()->subMinutes(5)]);                                  // queued but never picked up
        $due = $this->providerOrder(['status' => 'unknown', 'next_check_at' => now()->subMinute()]);             // retry is due
        $waiting = $this->providerOrder(['status' => 'unknown', 'next_check_at' => now()->addMinutes(10)]);      // backoff still running
        $abandoned = $this->providerOrder(['status' => 'submitting', 'updated_at' => now()->subMinutes(10)]);    // a worker died mid-send
        $busy = $this->providerOrder(['status' => 'submitting', 'updated_at' => now()]);                         // a worker is on it right now

        $this->runCommand();

        Queue::assertPushed(ProcessFazerCardsOrderJob::class, 3);
        foreach ([$missed, $due, $abandoned] as $po) {
            Queue::assertPushed(ProcessFazerCardsOrderJob::class, fn ($job) => $job->providerOrderId === $po->id);
        }
        foreach ([$fresh, $waiting, $busy] as $po) {
            Queue::assertNotPushed(ProcessFazerCardsOrderJob::class, fn ($job) => $job->providerOrderId === $po->id);
        }
    }

    public function test_processing_orders_are_polled_when_due(): void
    {
        $due = $this->providerOrder(['status' => 'processing', 'provider_order_id' => 'ord-1', 'next_check_at' => now()->subMinute()]);
        $later = $this->providerOrder(['status' => 'processing', 'provider_order_id' => 'ord-2', 'next_check_at' => now()->addMinutes(5)]);
        $done = $this->providerOrder(['status' => 'completed', 'provider_order_id' => 'ord-3']);
        $review = $this->providerOrder(['status' => 'review']);

        $this->runCommand();

        Queue::assertPushed(CheckFazerCardsOrderStatusJob::class, 1);
        Queue::assertPushed(CheckFazerCardsOrderStatusJob::class, fn ($job) => $job->providerOrderId === $due->id);
        Queue::assertNotPushed(ProcessFazerCardsOrderJob::class);
    }

    public function test_the_same_order_is_not_queued_again_every_minute(): void
    {
        $this->providerOrder(['updated_at' => now()->subMinutes(5)]);
        $this->providerOrder(['status' => 'processing', 'provider_order_id' => 'ord-1', 'next_check_at' => now()->subMinute()]);

        $this->runCommand();
        Queue::assertPushed(ProcessFazerCardsOrderJob::class, 1);
        Queue::assertPushed(CheckFazerCardsOrderStatusJob::class, 1);

        $this->runCommand();
        Queue::assertNothingPushed();
    }
}
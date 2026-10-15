<?php

namespace Tests\Feature;

use App\Enums\MarkupType;
use App\Enums\WalletTxType;
use App\Models\Game;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProviderOrder;
use App\Models\User;
use App\Services\TopUp\OrderProcessor;
use App\Services\TopUp\OrderService;
use App\Services\TopUp\RefundService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\Concerns\MakesCatalog;
use Tests\TestCase;

class OrderProcessorTest extends TestCase
{
    use MakesCatalog, RefreshDatabase;

    private User $user;

    private Product $product;

    private OrderProcessor $processor;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fazercards.api_key' => 'test-key-123',
            'fazercards.fake' => false,
            'fazercards.base_url' => 'https://api.fzr.cards/api/v2',
            'fazercards.retry_sleep_ms' => 0,
        ]);
        Queue::fake();

        $this->processor = app(OrderProcessor::class);
        $this->wallets = app(WalletService::class);

        $this->user = User::factory()->create();
        $this->wallets->credit($this->wallets->createWallet($this->user), '20.00', 'test:fund:1', WalletTxType::Bonus);

        $game = $this->makeGame(['slug' => 'pubg-mobile', 'provider_category_id' => 'cat_pubgm_1']);
        $this->makeField($game);
        $this->product = $this->makeProduct($game, '0.80', MarkupType::FixedMarkup, '0.20', ['provider_offer_id' => 'offer_60uc']);
    }

    private function newOrder(): ProviderOrder
    {
        $order = app(OrderService::class)->create($this->user, 'pubg-mobile', $this->product->id, ['player_id' => '123456789']);

        return ProviderOrder::where('order_id', $order->id)->firstOrFail();
    }

    private function balance(): string
    {
        return $this->wallets->getBalance($this->user);
    }

    private function fakeCreate(string $status = 'processing', string $id = 'ord-9002'): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['*/topups/order' => Http::response(['ok' => true, 'order' => ['id' => $id, 'kind' => 'topup', 'status' => $status]])]);
    }

    private function fakeRemote(string $status, string $id = 'ord-9002'): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['*/orders/'.$id => Http::response(['ok' => true, 'order' => ['id' => $id, 'kind' => 'topup', 'status' => $status]])]);
    }

    /** An order the provider accepted and is still working on. */
    private function processingOrder(): ProviderOrder
    {
        $this->fakeCreate();
        $po = $this->newOrder();
        $this->processor->submit($po->id);

        return $po->fresh();
    }

    // ---------------------------------------------------------------- submit

    public function test_a_successful_submit_moves_the_order_to_processing(): void
    {
        $this->fakeCreate();
        $po = $this->newOrder();

        $this->processor->submit($po->id);

        $po = $po->fresh();
        $order = Order::find($po->order_id);

        $this->assertSame('processing', $po->status->value);
        $this->assertSame('ord-9002', $po->provider_order_id);
        $this->assertSame(1, $po->attempts);
        $this->assertTrue($po->next_check_at->isFuture());
        $this->assertSame('processing', $order->status->value);
        $this->assertSame('19.0000', $this->balance());

        Http::assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key', $po->idempotency_key)
            && $r['category_id'] === 'cat_pubgm_1'
            && $r['offer_id'] === 'offer_60uc'
            && ((array) $r['fields'])['player_id'] === '123456789');
    }

    public function test_a_provider_that_completes_immediately_completes_the_order(): void
    {
        $this->fakeCreate('completed');
        $po = $this->newOrder();

        $this->processor->submit($po->id);

        $order = Order::find($po->order_id);
        $this->assertSame('completed', $order->status->value);
        $this->assertNotNull($order->completed_at);
        $this->assertSame('completed', $po->fresh()->status->value);
    }

    public function test_submitting_twice_calls_the_provider_once(): void
    {
        $this->fakeCreate();
        $po = $this->newOrder();

        $this->processor->submit($po->id);
        $this->processor->submit($po->id);

        Http::assertSentCount(1);
    }

    public function test_a_clear_refusal_on_the_first_attempt_refunds_the_customer(): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['*/topups/order' => Http::response(['ok' => false, 'error' => 'Invalid player id'], 400)]);
        $po = $this->newOrder();
        $this->assertSame('19.0000', $this->balance());

        $this->processor->submit($po->id);

        $order = Order::find($po->order_id);
        $this->assertSame('refunded', $order->status->value);
        $this->assertSame('The provider rejected the top-up.', $order->failure_reason);
        $this->assertSame('failed', $po->fresh()->status->value);
        $this->assertSame('20.0000', $this->balance());
        $this->assertDatabaseHas('wallet_transactions', [
            'idempotency_key' => 'refund:order:'.$order->id, 'type' => 'refund', 'direction' => 'credit', 'amount' => '1.0000',
        ]);
    }

    public function test_a_timeout_is_not_a_failure_and_nobody_is_refunded(): void
    {
        Http::swap(new HttpFactory);
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: timed out');
        });
        $po = $this->newOrder();

        $this->processor->submit($po->id);

        $po = $po->fresh();
        $order = Order::find($po->order_id);

        $this->assertSame('unknown', $po->status->value);
        $this->assertSame(1, $po->attempts);
        $this->assertTrue($po->next_check_at->isFuture());
        $this->assertSame('processing', $order->status->value);
        $this->assertSame('19.0000', $this->balance());
        $this->assertDatabaseMissing('wallet_transactions', ['type' => 'refund']);
    }

    public function test_server_errors_are_not_failures_either(): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['*/topups/order' => Http::response(['ok' => false, 'error' => 'boom'], 500)]);
        $po = $this->newOrder();

        $this->processor->submit($po->id);

        $this->assertSame('unknown', $po->fresh()->status->value);
        $this->assertSame('19.0000', $this->balance());
    }

    public function test_a_retry_after_a_timeout_reuses_the_same_idempotency_key(): void
    {
        $calls = 0;
        $keys = [];

        Http::swap(new HttpFactory);
        Http::fake(function (Request $request) use (&$calls, &$keys) {
            $calls++;
            $keys[] = $request->header('Idempotency-Key')[0] ?? null;

            if ($calls === 1) {
                throw new ConnectionException('timed out');
            }

            return Http::response(['ok' => true, 'order' => ['id' => 'ord-9002', 'kind' => 'topup', 'status' => 'processing']]);
        });

        $po = $this->newOrder();

        $this->processor->submit($po->id);
        $this->assertSame('unknown', $po->fresh()->status->value);

        $this->processor->submit($po->id); // what orders:process-pending does after the backoff

        $po = $po->fresh();
        $this->assertSame(2, $calls);
        $this->assertSame([$po->idempotency_key, $po->idempotency_key], $keys);
        $this->assertSame('processing', $po->status->value);
        $this->assertSame(2, $po->attempts);
        $this->assertSame('19.0000', $this->balance());
    }

    public function test_a_refusal_after_an_unclear_attempt_needs_a_human_and_does_not_refund(): void
    {
        $calls = 0;

        Http::swap(new HttpFactory);
        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw new ConnectionException('timed out');
            }

            return Http::response(['ok' => false, 'error' => 'Duplicate order'], 400);
        });

        $po = $this->newOrder();
        $this->processor->submit($po->id);
        $this->processor->submit($po->id);

        $po = $po->fresh();
        $order = Order::find($po->order_id);

        $this->assertSame('review', $po->status->value);
        $this->assertNull($po->next_check_at);
        $this->assertSame('processing', $order->status->value);
        $this->assertSame('19.0000', $this->balance());
        $this->assertDatabaseMissing('wallet_transactions', ['type' => 'refund']);
    }

    public function test_after_too_many_unclear_attempts_the_order_goes_to_review(): void
    {
        Http::swap(new HttpFactory);
        Http::fake(function () {
            throw new ConnectionException('timed out');
        });

        $po = $this->newOrder();
        DB::table('provider_orders')->where('id', $po->id)->update(['attempts' => OrderProcessor::MAX_SUBMIT_ATTEMPTS - 1]);

        $this->processor->submit($po->id);

        $this->assertSame('review', $po->fresh()->status->value);
        $this->assertSame('19.0000', $this->balance());
    }

    public function test_a_fresh_claim_by_another_worker_is_respected_but_a_stale_one_is_taken_over(): void
    {
        $this->fakeCreate();
        $po = $this->newOrder();

        DB::table('provider_orders')->where('id', $po->id)->update(['status' => 'submitting', 'updated_at' => now()]);
        $this->processor->submit($po->id);
        Http::assertNothingSent();

        DB::table('provider_orders')->where('id', $po->id)->update(['updated_at' => now()->subMinutes(5)]);
        $this->processor->submit($po->id);
        Http::assertSentCount(1);
        $this->assertSame('processing', $po->fresh()->status->value);
    }

    // ---------------------------------------------------------------- status checks

    public function test_a_completed_provider_order_completes_ours(): void
    {
        $po = $this->processingOrder();

        $this->fakeRemote('completed');
        $this->processor->check($po->id);

        $order = Order::find($po->order_id);
        $this->assertSame('completed', $order->status->value);
        $this->assertNotNull($order->completed_at);
        $this->assertSame('completed', $po->fresh()->status->value);
        $this->assertSame('19.0000', $this->balance());
    }

    public function test_a_failed_or_refunded_provider_order_refunds_the_customer(): void
    {
        foreach (['failed', 'refund'] as $remoteStatus) {
            $po = $this->processingOrder();
            $before = $this->balance();

            $this->fakeRemote($remoteStatus, 'ord-9002');
            $this->processor->check($po->id);

            $order = Order::find($po->order_id);
            $this->assertSame('refunded', $order->status->value, $remoteStatus);
            $this->assertSame('1.0000', bcsub($this->balance(), $before, 4), $remoteStatus);

            // The provider order id is unique per provider order: free it for the next loop run.
            DB::table('provider_orders')->where('id', $po->id)->update(['provider_order_id' => 'ord-1'.$po->id]);
        }
    }

    public function test_a_still_processing_order_is_checked_again_later(): void
    {
        $po = $this->processingOrder();

        $this->fakeRemote('processing');
        $this->processor->check($po->id);

        $po = $po->fresh();
        $this->assertSame('processing', $po->status->value);
        $this->assertTrue($po->next_check_at->isFuture());
        $this->assertSame('processing', Order::find($po->order_id)->status->value);
    }

    public function test_an_unrecognised_provider_status_is_never_treated_as_failure(): void
    {
        $po = $this->processingOrder();

        $this->fakeRemote('some-new-status');
        $this->processor->check($po->id);

        $this->assertSame('processing', Order::find($po->order_id)->status->value);
        $this->assertSame('19.0000', $this->balance());
    }

    public function test_a_failed_lookup_keeps_the_order_processing(): void
    {
        $po = $this->processingOrder();

        Http::swap(new HttpFactory);
        Http::fake(['*/orders/*' => Http::response(['ok' => false, 'error' => 'boom'], 500)]);
        $this->processor->check($po->id);

        $po = $po->fresh();
        $this->assertSame('processing', $po->status->value);
        $this->assertNotNull($po->last_error);
        $this->assertSame('processing', Order::find($po->order_id)->status->value);
    }

    public function test_an_order_stuck_for_hours_goes_to_review(): void
    {
        $po = $this->processingOrder();
        DB::table('provider_orders')->where('id', $po->id)->update(['created_at' => now()->subHours(7)]);

        $this->fakeRemote('processing');
        $this->processor->check($po->id);

        $this->assertSame('review', $po->fresh()->status->value);
        $this->assertSame('19.0000', $this->balance());
    }

    // ---------------------------------------------------------------- refunds

    public function test_a_refund_happens_once_whatever_the_number_of_calls(): void
    {
        $order = app(OrderService::class)->create($this->user, 'pubg-mobile', $this->product->id, ['player_id' => '123456789']);
        $refunds = app(RefundService::class);

        $refunds->failAndRefund($order, 'First');
        $refunds->failAndRefund($order, 'Second');

        $this->assertSame('20.0000', $this->balance());
        $this->assertSame(1, DB::table('wallet_transactions')->where('idempotency_key', 'refund:order:'.$order->id)->count());
        $this->assertSame('refunded', Order::find($order->id)->status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.refunded', 'subject_id' => $order->id]);
    }

    public function test_a_completed_order_can_never_be_refunded(): void
    {
        $this->fakeCreate('completed');
        $po = $this->newOrder();
        $this->processor->submit($po->id);

        $this->expectException(LogicException::class);

        try {
            app(RefundService::class)->failAndRefund(Order::find($po->order_id), 'Mistake');
        } finally {
            $this->assertSame('19.0000', $this->balance());
        }
    }
}
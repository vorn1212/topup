<?php

namespace Tests\Feature;

use App\Enums\DepositStatus;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Payment\DepositService;
use App\Services\Wallet\WalletService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DepositServiceTest extends TestCase
{
    use RefreshDatabase;

    private DepositService $deposits;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();

        config(['topup.deposit.min' => '1.00', 'topup.deposit.max' => '500.00']);

        $this->deposits = app(DepositService::class);
        $this->wallets = app(WalletService::class);
    }

    public function test_create_makes_a_pending_deposit_with_unique_number(): void
    {
        $user = User::factory()->create();

        $a = $this->deposits->create($user, '20');
        $b = $this->deposits->create($user, '5.5');

        $this->assertSame(DepositStatus::Pending, $a->status);
        $this->assertSame('20.0000', $a->amount);
        $this->assertMatchesRegularExpression('/^DEP-\d{8}-[A-Z0-9]{6}$/', $a->deposit_number);
        $this->assertNotSame($a->deposit_number, $b->deposit_number);
        $this->assertNotSame($a->payment_reference, $b->payment_reference);
        $this->assertTrue($a->expires_at->isFuture());
        $this->assertDatabaseHas('audit_logs', ['action' => 'deposit.created', 'subject_id' => $a->id]);

        // Creating a deposit never touches the balance.
        $this->assertSame('0.0000', $this->wallets->getBalance($user));
    }

    public function test_create_enforces_limits_and_format(): void
    {
        $user = User::factory()->create();

        foreach (['0.50', '501', '0', '-5', 'abc', '1.23456'] as $bad) {
            try {
                $this->deposits->create($user, $bad);
                $this->fail("Deposit amount [{$bad}] should be rejected.");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_suspended_and_banned_users_cannot_create_deposits(): void
    {
        foreach ([User::factory()->suspended()->create(), User::factory()->banned()->create()] as $user) {
            try {
                $this->deposits->create($user, '10');
                $this->fail('Non-active users must not deposit.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_mark_paid_credits_wallet_once(): void
    {
        $user = User::factory()->create();
        $this->wallets->credit($this->wallets->createWallet($user), '10.00', 'seed:1', \App\Enums\WalletTxType::Bonus);

        $deposit = $this->deposits->create($user, '20.00');

        $paid = $this->deposits->markPaid($deposit->id, 'ABA-TX-1');
        $this->assertSame(DepositStatus::Paid, $paid->status);
        $this->assertSame('ABA-TX-1', $paid->aba_transaction_id);
        $this->assertNotNull($paid->paid_at);
        $this->assertSame('30.0000', $this->wallets->getBalance($user));

        // Duplicate callback.
        $this->deposits->markPaid($deposit->id, 'ABA-TX-1');
        $this->deposits->markPaid($deposit->id, 'ABA-TX-1');

        $this->assertSame('30.0000', $this->wallets->getBalance($user));
        $this->assertSame(1, WalletTransaction::where('idempotency_key', "deposit:{$deposit->id}")->count());
        $this->assertDatabaseHas('wallet_transactions', [
            'idempotency_key' => "deposit:{$deposit->id}",
            'reference_type' => 'wallet_deposit',
            'reference_id' => $deposit->id,
            'type' => 'deposit',
        ]);
    }

    public function test_an_aba_transaction_id_cannot_pay_two_deposits(): void
    {
        $user = User::factory()->create();
        $first = $this->deposits->create($user, '10.00');
        $second = $this->deposits->create($user, '15.00');

        $this->deposits->markPaid($first->id, 'ABA-DUP');

        try {
            $this->deposits->markPaid($second->id, 'ABA-DUP');
            $this->fail('Reusing an ABA transaction id must fail.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(DepositStatus::Pending, $second->fresh()->status);
            $this->assertSame('10.0000', $this->wallets->getBalance($user));
        }
    }

    public function test_late_payment_on_an_expired_deposit_is_still_credited(): void
    {
        $user = User::factory()->create();
        $deposit = $this->deposits->create($user, '12.00');
        $deposit->forceFill(['status' => DepositStatus::Expired])->save();

        $this->deposits->markPaid($deposit->id, 'ABA-LATE');

        $this->assertSame(DepositStatus::Paid, $deposit->fresh()->status);
        $this->assertSame('12.0000', $this->wallets->getBalance($user));
    }

    public function test_refunded_deposit_cannot_be_marked_paid(): void
    {
        $user = User::factory()->create();
        $deposit = $this->deposits->create($user, '12.00');
        $deposit->forceFill(['status' => DepositStatus::Refunded])->save();

        $this->expectException(DomainException::class);
        $this->deposits->markPaid($deposit->id, 'ABA-X');
    }

    public function test_mark_paid_requires_an_aba_transaction_id(): void
    {
        $user = User::factory()->create();
        $deposit = $this->deposits->create($user, '5.00');

        $this->expectException(InvalidArgumentException::class);
        $this->deposits->markPaid($deposit->id, '  ');
    }
}
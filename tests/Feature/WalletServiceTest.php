<?php

namespace Tests\Feature;

use App\Enums\WalletStatus;
use App\Enums\WalletTxType;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\WalletFrozenException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallets = app(WalletService::class);
    }

    private function walletFor(?User $user = null): Wallet
    {
        $user ??= User::factory()->create();

        return $this->wallets->createWallet($user);
    }

    public function test_wallet_is_created_automatically_for_every_user(): void
    {
        $user = User::factory()->create();

        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'currency' => 'USD', 'status' => 'active']);
        $this->assertSame('0.0000', $this->wallets->getBalance($user));

        $this->wallets->createWallet($user); // calling again must not create a second wallet
        $this->assertSame(1, Wallet::where('user_id', $user->id)->count());
    }

    public function test_credit_increases_balance_and_writes_ledger_row(): void
    {
        $wallet = $this->walletFor();

        $tx = $this->wallets->credit($wallet, '20.00', 't:credit:1', WalletTxType::Deposit, null, 'Deposit');

        $this->assertSame('20.0000', $wallet->balance);
        $this->assertSame('20.0000', $this->wallets->getBalance($wallet));
        $this->assertSame('0.0000', $tx->balance_before);
        $this->assertSame('20.0000', $tx->balance_after);
        $this->assertSame('credit', $tx->direction);
        $this->assertSame(WalletTxType::Deposit, $tx->type);
    }

    public function test_debit_decreases_balance_and_can_reach_exactly_zero(): void
    {
        $wallet = $this->walletFor();
        $this->wallets->credit($wallet, '10.00', 't:c', WalletTxType::Bonus);

        $tx = $this->wallets->debit($wallet, '4.25', 't:d1');
        $this->assertSame('5.7500', $wallet->balance);
        $this->assertSame('debit', $tx->direction);

        $this->wallets->debit($wallet, '5.75', 't:d2');
        $this->assertSame('0.0000', $wallet->balance);
    }

    public function test_debit_beyond_balance_is_rejected_and_changes_nothing(): void
    {
        $wallet = $this->walletFor();
        $this->wallets->credit($wallet, '10.00', 't:c', WalletTxType::Bonus);

        try {
            $this->wallets->debit($wallet, '10.01', 't:d');
            $this->fail('Expected InsufficientBalanceException.');
        } catch (InsufficientBalanceException) {
            $this->assertSame('10.0000', $this->wallets->getBalance($wallet));
            $this->assertSame(1, WalletTransaction::count());
        }
    }

    public function test_second_purchase_cannot_spend_the_same_balance(): void
    {
        $wallet = $this->walletFor();
        $this->wallets->credit($wallet, '10.00', 't:c', WalletTxType::Bonus);

        $this->wallets->debit($wallet, '6.00', 'purchase:order:1');

        try {
            $this->wallets->debit($wallet, '6.00', 'purchase:order:2');
            $this->fail('Second purchase must fail.');
        } catch (InsufficientBalanceException) {
            $this->assertSame('4.0000', $this->wallets->getBalance($wallet));
        }
    }

    public function test_invalid_amounts_are_rejected(): void
    {
        $wallet = $this->walletFor();

        foreach (['0', '0.0000', '-1', 'abc', '', '1.23456', '1e3', ' 5'] as $bad) {
            try {
                $this->wallets->credit($wallet, $bad, 'bad:'.md5($bad));
                $this->fail("Amount [{$bad}] should be rejected.");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_same_idempotency_key_is_applied_only_once(): void
    {
        $wallet = $this->walletFor();

        $first = $this->wallets->credit($wallet, '5.00', 'deposit:99');
        $second = $this->wallets->credit($wallet, '5.00', 'deposit:99');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('5.0000', $this->wallets->getBalance($wallet));
        $this->assertSame(1, WalletTransaction::where('idempotency_key', 'deposit:99')->count());
    }

    public function test_reusing_a_key_for_a_different_amount_is_rejected(): void
    {
        $wallet = $this->walletFor();
        $this->wallets->credit($wallet, '5.00', 'deposit:1');

        $this->expectException(LogicException::class);
        $this->wallets->credit($wallet, '6.00', 'deposit:1');
    }

    public function test_refund_restores_balance_and_is_idempotent(): void
    {
        $wallet = $this->walletFor();
        $this->wallets->credit($wallet, '10.00', 't:c', WalletTxType::Bonus);
        $this->wallets->debit($wallet, '5.00', 'purchase:order:7');

        $refund = $this->wallets->refund($wallet, '5.00', 'refund:order:7');
        $this->wallets->refund($wallet, '5.00', 'refund:order:7');

        $this->assertSame(WalletTxType::Refund, $refund->type);
        $this->assertSame('10.0000', $this->wallets->getBalance($wallet));
        $this->assertSame(1, WalletTransaction::where('type', 'refund')->count());
    }

    public function test_frozen_wallet_blocks_purchases_but_accepts_credits(): void
    {
        $wallet = $this->walletFor();
        $this->wallets->credit($wallet, '10.00', 't:c', WalletTxType::Bonus);
        $wallet->forceFill(['status' => WalletStatus::Frozen])->save();

        $this->wallets->credit($wallet, '1.00', 't:c2', WalletTxType::Deposit);
        $this->assertSame('11.0000', $this->wallets->getBalance($wallet));

        $this->expectException(WalletFrozenException::class);
        $this->wallets->debit($wallet, '1.00', 't:d');
    }

    public function test_adjustment_requires_permission_and_records_admin_details(): void
    {
        $wallet = $this->walletFor();

        $admin = User::factory()->admin()->create(); // no wallet.adjust
        try {
            $this->wallets->adjust($wallet, 'credit', '5.00', $admin, 'Manual fix');
            $this->fail('Admin without wallet.adjust must be rejected.');
        } catch (AuthorizationException) {
            $this->assertSame('0.0000', $this->wallets->getBalance($wallet));
        }

        $super = User::factory()->superAdmin()->create();
        $tx = $this->wallets->adjust($wallet, 'credit', '5.00', $super, 'Compensation for failed order');

        $this->assertSame(WalletTxType::Adjustment, $tx->type);
        $this->assertSame($super->id, $tx->meta['admin_id']);
        $this->assertSame('Compensation for failed order', $tx->meta['reason']);
        $this->assertSame('5.0000', $this->wallets->getBalance($wallet));
        $this->assertDatabaseHas('audit_logs', ['action' => 'wallet.credit', 'subject_id' => $tx->id]);
    }

    public function test_adjustment_requires_a_reason_and_cannot_overdraw(): void
    {
        $wallet = $this->walletFor();
        $super = User::factory()->superAdmin()->create();

        try {
            $this->wallets->adjust($wallet, 'credit', '5.00', $super, '  ');
            $this->fail('Empty reason must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(InsufficientBalanceException::class);
        $this->wallets->adjust($wallet, 'debit', '5.00', $super, 'Chargeback');
    }

    public function test_ledger_rows_cannot_be_edited_or_deleted(): void
    {
        $wallet = $this->walletFor();
        $tx = $this->wallets->credit($wallet, '5.00', 't:c', WalletTxType::Deposit, null, 'Original');

        try {
            $tx->description = 'Tampered';
            $tx->save();
            $this->fail('Updating a ledger row must throw.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        try {
            $tx->delete();
            $this->fail('Deleting a ledger row must throw.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseHas('wallet_transactions', ['id' => $tx->id, 'description' => 'Original']);
    }

    public function test_database_refuses_a_negative_balance(): void
    {
        $wallet = $this->walletFor();

        try {
            DB::table('wallets')->where('id', $wallet->id)->update(['balance' => '-1.0000']);
            $this->fail('CHECK constraint should reject a negative balance.');
        } catch (QueryException) {
            $this->assertSame('0.0000', $this->wallets->getBalance($wallet));
        }
    }

    public function test_get_transactions_can_filter_by_type(): void
    {
        $wallet = $this->walletFor();
        $this->wallets->credit($wallet, '10.00', 't:1', WalletTxType::Deposit);
        $this->wallets->credit($wallet, '2.00', 't:2', WalletTxType::Bonus);
        $this->wallets->debit($wallet, '1.00', 't:3');

        $this->assertSame(3, $this->wallets->getTransactions($wallet)->total());
        $this->assertSame(1, $this->wallets->getTransactions($wallet, WalletTxType::Bonus)->total());
        $this->assertSame(1, $this->wallets->getTransactions($wallet, WalletTxType::Purchase)->total());
    }

    public function test_reconcile_passes_on_clean_data_and_detects_tampering(): void
    {
        $wallet = $this->walletFor();
        $this->wallets->credit($wallet, '10.00', 't:1', WalletTxType::Deposit);
        $this->wallets->debit($wallet, '3.50', 't:2');

        $this->artisan('wallet:reconcile')->assertExitCode(0);

        // Bypass the service: simulate a corrupted cached balance.
        DB::table('wallets')->where('id', $wallet->id)->update(['balance' => '99.0000']);

        $this->artisan('wallet:reconcile')->assertExitCode(1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'wallet.reconcile_mismatch']);
    }
}
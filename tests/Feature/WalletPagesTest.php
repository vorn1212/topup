<?php

namespace Tests\Feature;

use App\Enums\WalletTxType;
use App\Models\User;
use App\Services\Payment\DepositService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletPagesTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();

        config(['topup.deposit.min' => '1.00', 'topup.deposit.max' => '500.00']);
        $this->wallets = app(WalletService::class);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/wallet')->assertRedirect('/login');
        $this->get('/wallet/deposit')->assertRedirect('/login');
    }

    public function test_wallet_page_shows_only_the_users_own_transactions(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->wallets->credit($this->wallets->createWallet($alice), '20.00', 'a:1', WalletTxType::Deposit, null, 'Alpha deposit');
        $this->wallets->credit($this->wallets->createWallet($bob), '7.00', 'b:1', WalletTxType::Deposit, null, 'Bravo secret');

        $this->actingAs($alice)->get('/wallet')
            ->assertOk()
            ->assertSee('Alpha deposit')
            ->assertSee('$20.00')
            ->assertDontSee('Bravo secret');
    }

    public function test_wallet_page_type_filter(): void
    {
        $user = User::factory()->create();
        $wallet = $this->wallets->createWallet($user);
        $this->wallets->credit($wallet, '5.00', 'k:1', WalletTxType::Bonus, null, 'Bonus row');
        $this->wallets->credit($wallet, '5.00', 'k:2', WalletTxType::Deposit, null, 'Deposit row');

        $this->actingAs($user)->get('/wallet?type=bonus')
            ->assertOk()
            ->assertSee('Bonus row')
            ->assertDontSee('Deposit row');
    }

    public function test_deposit_page_lists_only_own_deposits(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $deposits = app(DepositService::class);

        $mine = $deposits->create($alice, '10.00');
        $theirs = $deposits->create($bob, '10.00');

        $this->actingAs($alice)->get('/wallet/deposit')
            ->assertOk()
            ->assertSee($mine->deposit_number)
            ->assertDontSee($theirs->deposit_number);
    }

    public function test_suspended_user_can_still_view_wallet_pages(): void
    {
        $user = User::factory()->suspended()->create();

        $this->actingAs($user)->get('/wallet')->assertOk()->assertSee('Suspended');
        $this->actingAs($user)->get('/wallet/deposit')->assertOk();
    }

    public function test_dashboard_shows_the_real_balance(): void
    {
        $user = User::factory()->create();
        $this->wallets->credit($this->wallets->createWallet($user), '12.50', 'd:1', WalletTxType::Bonus, null, 'Welcome bonus');

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('$12.50')
            ->assertSee('Welcome bonus');
    }
}
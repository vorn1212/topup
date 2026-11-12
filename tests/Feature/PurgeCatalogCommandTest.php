<?php

namespace Tests\Feature;

use App\Console\Commands\PurgeCatalogCommand;
use App\Models\Game;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProviderOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

class PurgeCatalogCommandTest extends TestCase
{
    use MakesCatalog, MakesOrders, RefreshDatabase;

    public function test_without_force_nothing_is_deleted(): void
    {
        $this->setUpShop();
        $this->placeOrder();

        $this->artisan('catalog:purge')->assertExitCode(0);

        $this->assertSame(1, Game::count());
        $this->assertSame(1, Product::count());
        $this->assertSame(1, Order::count());
    }

    public function test_declining_the_question_deletes_nothing(): void
    {
        $this->setUpShop();
        $this->placeOrder();

        $this->artisan('catalog:purge --force')
            ->expectsConfirmation(PurgeCatalogCommand::CONFIRMATION, 'no')
            ->assertExitCode(0);

        $this->assertSame(1, Game::count());
        $this->assertSame(1, Order::count());
    }

    public function test_force_deletes_games_products_and_their_orders_but_keeps_the_wallet_ledger(): void
    {
        $this->setUpShop();
        $this->placeOrder();
        $ledger = DB::table('wallet_transactions')->count();
        $balance = $this->customerBalance();

        $this->artisan('catalog:purge --force')
            ->expectsConfirmation(PurgeCatalogCommand::CONFIRMATION, 'yes')
            ->assertExitCode(0);

        $this->assertSame(0, Game::count());
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, ProviderOrder::count());
        $this->assertSame($ledger, DB::table('wallet_transactions')->count());
        $this->assertSame($balance, $this->customerBalance());
    }
}

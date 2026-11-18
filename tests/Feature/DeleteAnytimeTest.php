<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

class DeleteAnytimeTest extends TestCase
{
    use MakesCatalog, MakesOrders, RefreshDatabase;

    public function test_a_game_with_products_and_orders_can_be_deleted_and_the_orders_stay(): void
    {
        $this->setUpShop();
        $po = $this->placeOrder();
        $order = Order::findOrFail($po->order_id);
        $gameName = $order->game_name;
        $productName = $order->product_name;
        $game = Game::firstOrFail();

        $this->actingAs($this->staff('super_admin'))
            ->delete(route('admin.games.destroy', $game))
            ->assertRedirect();

        $this->assertSame(0, Game::count());
        $this->assertSame(0, Product::count());

        $order = $order->fresh();
        $this->assertNotNull($order);
        $this->assertNull($order->game_id);
        $this->assertNull($order->product_id);
        $this->assertSame($gameName, $order->game_name);
        $this->assertSame($productName, $order->product_name);
    }

    public function test_a_product_with_orders_can_be_deleted_and_the_orders_stay(): void
    {
        $this->setUpShop();
        $po = $this->placeOrder();
        $productName = Order::findOrFail($po->order_id)->product_name;

        $this->actingAs($this->staff('super_admin'))
            ->delete(route('admin.products.destroy', $this->product))
            ->assertRedirect();

        $this->assertSame(0, Product::count());
        $this->assertSame(1, Game::count());
        $this->assertSame($productName, Order::findOrFail($po->order_id)->product_name);
    }

    public function test_customers_cannot_delete_products(): void
    {
        $this->setUpShop();

        $this->actingAs($this->customer)
            ->delete(route('admin.products.destroy', $this->product))
            ->assertForbidden();

        $this->assertSame(1, Product::count());
    }
}

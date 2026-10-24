<?php

namespace Tests\Feature;

use App\Enums\MarkupType;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

class ProductDeleteTest extends TestCase
{
    use MakesCatalog, MakesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpShop();
    }

    public function test_an_unused_product_can_be_deleted(): void
    {
        $spare = $this->makeProduct($this->product->game, '1.00', MarkupType::FixedMarkup, '0.20');
        $this->actingAs($this->staff('super_admin'));

        $this->delete('/admin/products/'.$spare->id)->assertSessionHasNoErrors();

        $this->assertNull(Product::find($spare->id));
        $this->assertNotNull(Product::find($this->product->id));
    }

    public function test_a_product_with_orders_is_kept(): void
    {
        $this->placeOrder();
        $this->actingAs($this->staff('super_admin'));

        $this->delete('/admin/products/'.$this->product->id)->assertSessionHasErrors('delete');

        $this->assertNotNull(Product::find($this->product->id));
    }

    public function test_customers_cannot_delete_products(): void
    {
        $this->actingAs($this->customer);

        $this->delete('/admin/products/'.$this->product->id)->assertForbidden();

        $this->assertNotNull(Product::find($this->product->id));
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LightningTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_background_has_lightning_flashes(): void
    {
        $this->get('/')->assertOk()->assertSee('gg-lightning', false)->assertSee('gg-bolt', false);
    }
}

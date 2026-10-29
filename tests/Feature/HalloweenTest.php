<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HalloweenTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_pumpkins_fly_during_the_halloween_season(): void
    {
        Carbon::setTestNow('2026-10-29 12:00:00');

        $this->get('/')->assertOk()->assertSee('gg-pumpkins', false)->assertSee('gg-pumpkin', false);
    }

    public function test_no_pumpkins_the_rest_of_the_year(): void
    {
        Carbon::setTestNow('2026-06-10 12:00:00');

        $this->get('/')->assertOk()->assertDontSee('gg-pumpkins', false);
    }
}

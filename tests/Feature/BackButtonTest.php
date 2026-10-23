<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_inner_pages_have_a_back_button_but_home_does_not(): void
    {
        $this->get('/games')->assertOk()->assertSee('class="back-link"', false);
        $this->get('/contact')->assertOk()->assertSee('class="back-link"', false);
        $this->get('/')->assertOk()->assertDontSee('class="back-link"', false);
    }

    public function test_back_button_is_translated(): void
    {
        $this->withSession(['locale' => 'km'])->get('/contact')->assertOk()->assertSee('ត្រឡប់ក្រោយ');
    }
}

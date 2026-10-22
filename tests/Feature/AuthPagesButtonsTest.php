<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthPagesButtonsTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_has_a_register_button_and_readable_form_styles(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('class="auth-btn-outline"', false)
            ->assertSee('href="'.route('register').'"', false)
            ->assertSee('auth-card', false)
            ->assertSee('color: #000 !important', false);
    }

    public function test_register_page_has_a_login_button(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('class="auth-btn-outline"', false)
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('auth-card', false);
    }

    public function test_forgot_password_page_uses_the_same_readable_styles(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee('auth-card', false);
    }
}

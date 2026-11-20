<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LogoutWhenBrowserClosesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_page', fn () => response('<html><body>hello</body></html>'));
    }

    public function test_a_guest_page_only_gets_the_script_without_the_signed_in_flag(): void
    {
        $html = $this->get('/_page')->assertOk()->getContent();

        $this->assertStringContainsString('/kt-session.js', $html);
        $this->assertStringNotContainsString('data-signed-in', $html);
    }

    public function test_a_signed_in_page_is_flagged(): void
    {
        $user = User::factory()->create(['email' => 'real@example.com']);

        $this->actingAs($user)->get('/_page')->assertSee('data-signed-in="1"', false);
    }

    public function test_guest_checkout_accounts_are_left_alone(): void
    {
        $user = User::factory()->create(['email' => 'abc@guest.invalid']);

        $this->actingAs($user)->get('/_page')->assertDontSee('data-signed-in', false);
        $this->actingAs($user)->post('/_session/end')->assertNoContent();
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_end_call_signs_the_user_out(): void
    {
        $user = User::factory()->create(['email' => 'real@example.com']);

        $this->actingAs($user)->post('/_session/end')->assertNoContent();

        $this->assertGuest();
    }
}

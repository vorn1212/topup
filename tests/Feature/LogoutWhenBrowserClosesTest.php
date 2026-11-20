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

    public function test_a_guest_page_sets_the_tab_marker_only(): void
    {
        $html = $this->get('/_page')->assertOk()->getContent();

        $this->assertStringContainsString("setItem('kt_tab','1')", $html);
        $this->assertStringNotContainsString('_session/end', $html);
    }

    public function test_a_signed_in_page_checks_the_marker(): void
    {
        $user = User::factory()->create(['email' => 'real@example.com']);

        $html = $this->actingAs($user)->get('/_page')->getContent();

        $this->assertStringContainsString('_session/end', $html);
        $this->assertStringContainsString("getItem('kt_tab')", $html);
    }

    public function test_guest_checkout_accounts_are_left_alone(): void
    {
        $user = User::factory()->create(['email' => 'abc@guest.invalid']);

        $this->actingAs($user)->get('/_page')->assertDontSee('_session/end', false);
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

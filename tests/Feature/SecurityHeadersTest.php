<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use MakesCatalog, MakesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpShop();
    }

    private function assertBaseline($response): void
    {
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy');
        $response->assertHeader('Content-Security-Policy', SecurityHeaders::CSP);
    }

    public function test_public_pages_carry_the_security_headers(): void
    {
        $this->assertBaseline($this->get('/'));
        $this->assertBaseline($this->get('/games'));
        $this->assertBaseline($this->get('/login'));
        $this->assertBaseline($this->get('/robots.txt'));
    }

    public function test_signed_in_and_admin_pages_carry_the_security_headers(): void
    {
        $this->assertBaseline($this->actingAs($this->customer)->get('/dashboard'));
        $this->assertBaseline($this->actingAs($this->staff('admin'))->get('/admin'));
    }

    public function test_json_answers_carry_the_security_headers(): void
    {
        $order = Order::findOrFail($this->placeOrder()->order_id);

        $response = $this->actingAs($this->customer)->getJson('/order/'.$order->order_number.'/status')->assertOk();

        $this->assertBaseline($response);
    }

    public function test_the_policy_closes_framing_base_tag_forms_and_plugins(): void
    {
        $csp = SecurityHeaders::CSP;

        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_pages_of_signed_in_users_are_never_cached(): void
    {
        $this->assertStringContainsString('no-store', (string) $this->actingAs($this->customer)->get('/wallet')->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $this->actingAs($this->staff('admin'))->get('/admin')->headers->get('Cache-Control'));
    }

    public function test_public_pages_for_guests_are_not_forced_to_no_store(): void
    {
        $this->assertStringNotContainsString('no-store', (string) $this->get('/')->headers->get('Cache-Control'));
    }

    public function test_hsts_is_sent_only_for_https_in_production(): void
    {
        $handle = function (string $url): Response {
            $request = Request::create($url, 'GET');

            return (new SecurityHeaders)->handle($request, fn () => new Response('ok'));
        };

        // Not production: never.
        $this->assertFalse($handle('https://shop.example.com/')->headers->has('Strict-Transport-Security'));

        $this->app['env'] = 'production';

        // Production but plain http: never (the header would be ignored and is misleading).
        $this->assertFalse($handle('http://shop.example.com/')->headers->has('Strict-Transport-Security'));

        // Production and https: yes.
        $hsts = $handle('https://shop.example.com/')->headers->get('Strict-Transport-Security');
        $this->assertStringContainsString('max-age=31536000', (string) $hsts);
    }

    public function test_the_middleware_does_not_overwrite_a_cache_rule_a_page_already_set(): void
    {
        $request = Request::create('https://shop.example.com/', 'GET');

        $response = (new SecurityHeaders)->handle($request, function () {
            $response = new Response('ok');
            $response->headers->set('Cache-Control', 'public, max-age=600');

            return $response;
        });

        // No session and no signed-in user on this bare request, so the page keeps its own rule.
        $this->assertStringContainsString('max-age=600', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_webhook_never_answers_with_a_server_error_on_junk(): void
    {
        config(['fazercards.webhook_secret' => null]);

        $this->call('POST', '/webhooks/fazercards', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json at all')
            ->assertNotFound();
    }
}
<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustCloudflareIp;
use Illuminate\Http\Request;
use Tests\TestCase;

class TrustCloudflareIpTest extends TestCase
{
    private function ipAfter(string $remote, ?string $header): string
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $remote]);

        if ($header !== null) {
            $request->headers->set('CF-Connecting-IP', $header);
        }

        (new TrustCloudflareIp)->handle($request, fn ($r) => response('ok'));

        return (string) $request->ip();
    }

    public function test_the_visitor_ip_is_used_when_the_request_comes_from_the_tunnel(): void
    {
        $this->assertSame('203.0.113.9', $this->ipAfter('127.0.0.1', '203.0.113.9'));
        $this->assertSame('2001:db8::1', $this->ipAfter('::1', '2001:db8::1'));
    }

    public function test_the_header_is_ignored_from_any_other_source(): void
    {
        $this->assertSame('198.51.100.7', $this->ipAfter('198.51.100.7', '203.0.113.9'));
    }

    public function test_a_garbage_header_is_ignored(): void
    {
        $this->assertSame('127.0.0.1', $this->ipAfter('127.0.0.1', 'not-an-ip'));
        $this->assertSame('127.0.0.1', $this->ipAfter('127.0.0.1', null));
    }
}

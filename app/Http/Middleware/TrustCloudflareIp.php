<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behind the Cloudflare Tunnel every request reaches Laravel from 127.0.0.1, so all visitors looked like
 * ONE person: rate limits were shared by everybody and logs showed 127.0.0.1.
 *
 * Only when the connection really comes from this server itself (the tunnel) is the visitor's address taken
 * from Cloudflare's CF-Connecting-IP header. From any other source the header is ignored, so nobody can fake it.
 */
class TrustCloudflareIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $remote = (string) $request->server->get('REMOTE_ADDR', '');

        if (in_array($remote, ['127.0.0.1', '::1'], true)) {
            $client = trim((string) $request->headers->get('CF-Connecting-IP', ''));

            if ($client !== '' && filter_var($client, FILTER_VALIDATE_IP) !== false) {
                $request->server->set('REMOTE_ADDR', $client);
            }
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function sitemap(): Response
    {
        $urls = [
            [route('home'), null, 'daily', '1.0'],
            [route('games.index'), null, 'daily', '0.9'],
        ];

        foreach (Game::query()->active()->orderBy('id')->get(['id', 'slug', 'updated_at']) as $game) {
            $lastmod = $game->updated_at?->toAtomString();
            $urls[] = [route('games.show', $game->slug), $lastmod, 'weekly', '0.8'];
            $urls[] = [route('games.topup', $game->slug), $lastmod, 'weekly', '0.7'];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as [$loc, $lastmod, $frequency, $priority]) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>'.htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8')."</loc>\n";
            if ($lastmod) {
                $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
            }
            $xml .= "    <changefreq>{$frequency}</changefreq>\n";
            $xml .= "    <priority>{$priority}</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>'."\n";

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /dashboard',
            'Disallow: /wallet',
            'Disallow: /profile',
            'Disallow: /orders',
            'Disallow: /order/',
            'Disallow: /webhooks',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /forgot-password',
            'Disallow: /reset-password',
            'Allow: /',
            '',
            'Sitemap: '.route('sitemap'),
            '',
        ];

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ops\HealthCheck;
use Illuminate\View\View;

/**
 * "Is the shop healthy?" for the super admin (settings.manage). Read-only; shows no secrets.
 */
class HealthController extends Controller
{
    private const BANNERS = [
        'pass' => ['bg-green-50 border-green-200 text-green-800', 'Everything looks good.'],
        'warn' => ['bg-yellow-50 border-yellow-200 text-yellow-800', 'No failures, but read the warnings.'],
        'fail' => ['bg-red-50 border-red-200 text-red-800', 'Something needs fixing now.'],
    ];

    public function index(HealthCheck $health): View
    {
        $results = $health->run();
        $summary = $health->summary($results);

        return view('admin.health.index', [
            'summary' => $summary,
            'groups' => collect($results)->groupBy('group'),
            'environment' => (string) config('app.env'),
            'bannerClass' => self::BANNERS[$summary['overall']][0],
            'bannerText' => self::BANNERS[$summary['overall']][1],
            'badgeClasses' => [
                'pass' => 'bg-green-100 text-green-800',
                'warn' => 'bg-yellow-100 text-yellow-800',
                'fail' => 'bg-red-100 text-red-800',
            ],
        ]);
    }
}
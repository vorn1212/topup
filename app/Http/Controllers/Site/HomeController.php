<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        return view('site.home', [
            'games' => Game::query()
                ->active()
                ->withStartingPrice()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->limit(12)
                ->get(),
        ]);
    }
}
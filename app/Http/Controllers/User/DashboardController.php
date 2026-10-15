<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\User\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): View
    {
        $user = $request->user();

        return view('user.dashboard', [
            'user' => $user,
            'stats' => $dashboard->forUser($user),
        ]);
    }
}
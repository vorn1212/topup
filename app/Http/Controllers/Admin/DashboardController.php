<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('admin.dashboard', [
            'totalUsers' => User::where('role', UserRole::User->value)->count(),
            'activeUsers' => User::where('role', UserRole::User->value)
                ->where('status', UserStatus::Active->value)->count(),
            'suspendedUsers' => User::where('role', UserRole::User->value)
                ->where('status', UserStatus::Suspended->value)->count(),
            'staff' => User::whereIn('role', UserRole::staffValues())->orderBy('role')->get(),
        ]);
    }
}
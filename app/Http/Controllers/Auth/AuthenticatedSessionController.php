<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, AuditService $audit): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $audit->log('auth.login', $request->user());

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function destroy(Request $request, AuditService $audit): RedirectResponse
    {
        if ($user = $request->user()) {
            $audit->log('auth.logout', $user);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
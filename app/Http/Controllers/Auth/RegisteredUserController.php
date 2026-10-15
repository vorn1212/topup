<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validated();

        // role / status are not fillable: DB defaults (user / active) apply.
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
        ]);

        event(new Registered($user)); // Phase 3 hooks wallet creation here.

        Auth::login($user);
        $request->session()->regenerate();

        $audit->log('auth.register', $user, actor: $user);

        return redirect(route('dashboard', absolute: false));
    }
}
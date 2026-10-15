<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DepositStatus;
use App\Http\Controllers\Controller;
use App\Models\WalletDeposit;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DepositController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $like = '%'.addcslashes($q, '%_\\').'%';
        $status = DepositStatus::tryFrom((string) $request->query('status'));

        $deposits = WalletDeposit::query()
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('deposit_number', 'like', $like)
                    ->orWhere('aba_transaction_id', 'like', $like)
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', $like)->orWhere('name', 'like', $like))
            ))
            ->when($status, fn ($query) => $query->where('status', $status->value))
            ->with('user:id,name,email')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.deposits.index', compact('deposits', 'q', 'status'));
    }
}
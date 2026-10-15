<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WalletTxType;
use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TransactionController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $like = '%'.addcslashes($q, '%_\\').'%';
        $type = WalletTxType::tryFrom((string) $request->query('type'));
        $direction = in_array($request->query('direction'), ['credit', 'debit'], true) ? $request->query('direction') : null;

        $transactions = WalletTransaction::query()
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('idempotency_key', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', $like)->orWhere('name', 'like', $like))
            ))
            ->when($type, fn ($query) => $query->where('type', $type->value))
            ->when($direction, fn ($query) => $query->where('direction', $direction))
            ->with('user:id,name,email')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.transactions.index', compact('transactions', 'q', 'type', 'direction'));
    }
}
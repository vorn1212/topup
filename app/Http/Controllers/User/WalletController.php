<?php

namespace App\Http\Controllers\User;

use App\Enums\WalletTxType;
use App\Http\Controllers\Controller;
use App\Services\Wallet\WalletService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WalletController extends Controller
{
    public function index(Request $request, WalletService $wallets): View
    {
        $user = $request->user();
        $wallet = $wallets->createWallet($user);
        $type = WalletTxType::tryFrom((string) $request->query('type'));

        return view('wallet.index', [
            'user' => $user,
            'wallet' => $wallet,
            'balance' => $wallets->getBalance($wallet),
            'type' => $type,
            'transactions' => $wallets->getTransactions($wallet, $type, 15)->withQueryString(),
        ]);
    }
}
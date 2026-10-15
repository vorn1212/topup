<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletDeposit;
use App\Models\WalletTransaction;
use App\Services\Admin\UserAdminService;
use App\Services\Wallet\WalletService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use LogicException;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $like = '%'.addcslashes($q, '%_\\').'%';
        $role = UserRole::tryFrom((string) $request->query('role'));
        $status = UserStatus::tryFrom((string) $request->query('status'));

        $users = User::query()
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)
            ))
            ->when($role, fn ($query) => $query->where('role', $role->value))
            ->when($status, fn ($query) => $query->where('status', $status->value))
            ->with('wallet:id,user_id,balance,status')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.users.index', compact('users', 'q', 'role', 'status'));
    }

    public function show(Request $request, User $user, WalletService $wallets, UserAdminService $admin): View
    {
        $wallet = $wallets->createWallet($user);

        $spent = Order::query()
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->select(DB::raw('COALESCE(SUM(selling_price), 0) as total'))
            ->value('total');

        return view('admin.users.show', [
            'subject' => $user,
            'wallet' => $wallet,
            'balance' => $wallets->getBalance($wallet),
            'spent' => (string) $spent,
            'orderCount' => Order::query()->where('user_id', $user->id)->count(),
            'orders' => Order::query()->where('user_id', $user->id)->latest('id')->limit(10)->get(),
            'deposits' => WalletDeposit::query()->where('user_id', $user->id)->latest('id')->limit(10)->get(),
            'transactions' => WalletTransaction::query()->where('user_id', $user->id)->latest('id')->limit(15)->get(),
            'audit' => AuditLog::query()
                ->where('subject_type', $user->getMorphClass())
                ->where('subject_id', $user->id)
                ->with('actor:id,name,email')
                ->latest('id')
                ->limit(15)
                ->get(),
            'canManage' => $admin->canManage($request->user(), $user),
            'canAdjust' => $request->user()->can('wallet.adjust') && $request->user()->id !== $user->id,
            'canChangeRole' => $request->user()->can('roles.manage'),
            'adjustToken' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    public function status(Request $request, User $user, UserAdminService $admin): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(UserStatus::class)],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        return $this->run($user, fn () => $admin->setStatus($user, UserStatus::from($data['status']), $request->user(), $data['reason']), 'The account status was changed.');
    }

    public function role(Request $request, User $user, UserAdminService $admin): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::enum(UserRole::class)],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        return $this->run($user, fn () => $admin->setRole($user, UserRole::from($data['role']), $request->user(), $data['reason']), 'The role was changed.');
    }

    public function freeze(Request $request, User $user, UserAdminService $admin): RedirectResponse
    {
        $data = $request->validate([
            'frozen' => ['required', 'in:0,1'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        return $this->run(
            $user,
            fn () => $admin->setWalletFrozen($user, $data['frozen'] === '1', $request->user(), $data['reason']),
            $data['frozen'] === '1' ? 'The wallet was frozen.' : 'The wallet was unfrozen.'
        );
    }

    /**
     * Manual credit or debit. The hidden token makes a double click or a page refresh harmless:
     * the same token is the same ledger entry.
     */
    public function adjust(Request $request, User $user, WalletService $wallets): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:credit,debit'],
            'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'token' => ['required', 'regex:/^[A-Za-z0-9\-]{16,64}$/'],
        ], [
            'amount.regex' => 'Enter a positive amount with at most 4 decimals.',
        ]);

        $actor = $request->user();

        if ($actor->id === $user->id) {
            return back()->with('error', 'You cannot adjust your own wallet.');
        }

        if (bccomp($data['amount'], '0', 4) <= 0 || bccomp($data['amount'], '10000', 4) > 0) {
            return back()->withInput()->with('error', 'The amount must be between 0.0001 and 10000.');
        }

        try {
            $wallets->adjust(
                $wallets->createWallet($user),
                $data['direction'],
                $data['amount'],
                $actor,
                $data['reason'],
                'adjust:'.$actor->id.':'.$data['token'],
            );
        } catch (InsufficientBalanceException) {
            return back()->withInput()->with('error', 'The customer does not have enough balance for this debit.');
        } catch (InvalidArgumentException|LogicException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.users.show', $user)->with('status', 'The wallet was adjusted.');
    }

    private function run(User $user, callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException|InvalidArgumentException|AuthorizationException $e) {
            return redirect()->route('admin.users.show', $user)->with('error', $e->getMessage());
        }

        return redirect()->route('admin.users.show', $user)->with('status', $success);
    }
}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $subject->name }}</h2>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash />

            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-700">
                    @foreach ($errors->all() as $message)
                        <p>{{ $message }}</p>
                    @endforeach
                </div>
            @endif

            <a href="{{ route('admin.users.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; All users</a>

            {{-- Summary --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                    <div><p class="text-gray-500">E-mail</p><p class="font-medium">{{ $subject->email }}</p></div>
                    <div><p class="text-gray-500">Phone</p><p class="font-medium">{{ $subject->phone ?? '-' }}</p></div>
                    <div><p class="text-gray-500">Role</p><p class="font-medium">{{ $subject->role->label() }}</p></div>
                    <div><p class="text-gray-500">Status</p><x-status-badge :status="$subject->status->value" /></div>
                    <div><p class="text-gray-500">Wallet balance</p><p class="text-lg font-bold text-indigo-600">{{ \App\Support\Money::display($balance, $wallet->currency) }}</p></div>
                    <div><p class="text-gray-500">Wallet</p><x-status-badge :status="$wallet->status->value === 'frozen' ? 'suspended' : 'active'" /> <span class="text-xs text-gray-500">{{ ucfirst($wallet->status->value) }}</span></div>
                    <div><p class="text-gray-500">Orders</p><p class="font-medium">{{ $orderCount }}</p></div>
                    <div><p class="text-gray-500">Spent (completed)</p><p class="font-medium">{{ \App\Support\Money::display($spent, $wallet->currency) }}</p></div>
                </div>
                <p class="mt-3 text-xs text-gray-500">Joined {{ $subject->created_at->format('Y-m-d H:i') }}</p>
            </div>

            {{-- Actions --}}
            @if ($canManage || $canAdjust)
                <div class="grid gap-4 md:grid-cols-2">
                    @if ($canManage && auth()->user()->can('users.manage'))
                        <div class="bg-white shadow-sm sm:rounded-lg p-5">
                            <h3 class="font-semibold text-gray-900">Account status</h3>
                            <p class="mt-1 text-sm text-gray-600">Suspended users can look around but cannot deposit or order. Banned users cannot log in.</p>
                            <form method="POST" action="{{ route('admin.users.status', $subject) }}" class="mt-3 space-y-2">
                                @csrf
                                <select name="status" class="w-full rounded-md border-gray-300 text-sm">
                                    @foreach (\App\Enums\UserStatus::cases() as $case)
                                        <option value="{{ $case->value }}" @selected($subject->status === $case)>{{ $case->label() }}</option>
                                    @endforeach
                                </select>
                                <input type="text" name="reason" required minlength="5" maxlength="255" class="w-full rounded-md border-gray-300 text-sm" placeholder="Reason (saved in the audit log)">
                                <button class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">Change status</button>
                            </form>
                        </div>

                        <div class="bg-white shadow-sm sm:rounded-lg p-5">
                            <h3 class="font-semibold text-gray-900">Wallet freeze</h3>
                            <p class="mt-1 text-sm text-gray-600">A frozen wallet cannot pay for orders. Deposits and refunds still arrive.</p>
                            <form method="POST" action="{{ route('admin.users.freeze', $subject) }}" class="mt-3 space-y-2">
                                @csrf
                                <input type="hidden" name="frozen" value="{{ $wallet->status->value === 'frozen' ? '0' : '1' }}">
                                <input type="text" name="reason" required minlength="5" maxlength="255" class="w-full rounded-md border-gray-300 text-sm" placeholder="Reason">
                                <button class="rounded-md {{ $wallet->status->value === 'frozen' ? 'bg-green-600 hover:bg-green-500' : 'bg-red-600 hover:bg-red-500' }} px-3 py-1.5 text-sm font-medium text-white">
                                    {{ $wallet->status->value === 'frozen' ? 'Unfreeze wallet' : 'Freeze wallet' }}
                                </button>
                            </form>
                        </div>
                    @endif

                    @if ($canManage && $canChangeRole)
                        <div class="bg-white shadow-sm sm:rounded-lg p-5">
                            <h3 class="font-semibold text-gray-900">Role</h3>
                            <p class="mt-1 text-sm text-gray-600">Only a super admin can change roles. Super admin accounts are managed on the server.</p>
                            <form method="POST" action="{{ route('admin.users.role', $subject) }}" class="mt-3 space-y-2">
                                @csrf
                                <select name="role" class="w-full rounded-md border-gray-300 text-sm">
                                    @foreach ([\App\Enums\UserRole::User, \App\Enums\UserRole::Support, \App\Enums\UserRole::Admin] as $case)
                                        <option value="{{ $case->value }}" @selected($subject->role === $case)>{{ $case->label() }}</option>
                                    @endforeach
                                </select>
                                <input type="text" name="reason" required minlength="5" maxlength="255" class="w-full rounded-md border-gray-300 text-sm" placeholder="Reason">
                                <button class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">Change role</button>
                            </form>
                        </div>
                    @endif

                    @if ($canAdjust)
                        <div class="bg-white shadow-sm sm:rounded-lg p-5">
                            <h3 class="font-semibold text-gray-900">Adjust balance</h3>
                            <p class="mt-1 text-sm text-gray-600">Adds a new ledger entry (nothing is edited). Use for corrections only.</p>
                            <form method="POST" action="{{ route('admin.users.adjust', $subject) }}" class="mt-3 space-y-2">
                                @csrf
                                <input type="hidden" name="token" value="{{ $adjustToken }}">
                                <div class="flex gap-2">
                                    <select name="direction" class="rounded-md border-gray-300 text-sm">
                                        <option value="credit" @selected(old('direction') === 'credit')>Add money (credit)</option>
                                        <option value="debit" @selected(old('direction') === 'debit')>Remove money (debit)</option>
                                    </select>
                                    <input type="text" name="amount" value="{{ old('amount') }}" required inputmode="decimal" placeholder="Amount, e.g. 5.00" class="w-full rounded-md border-gray-300 text-sm">
                                </div>
                                <input type="text" name="reason" value="{{ old('reason') }}" required minlength="5" maxlength="255" class="w-full rounded-md border-gray-300 text-sm" placeholder="Reason (required, saved with the entry)">
                                <button class="rounded-md bg-yellow-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-yellow-500">Apply adjustment</button>
                            </form>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Orders --}}
            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <div class="p-5 pb-2"><h3 class="font-semibold text-gray-900">Latest orders</h3></div>
                <table class="min-w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="p-3">Order</th><th>Game / package</th><th>Amount</th><th>Status</th><th>Date</th><th class="p-3"></th></tr></thead>
                    <tbody>
                        @forelse ($orders as $order)
                            <tr class="border-t">
                                <td class="p-3 font-medium">{{ $order->order_number }}</td>
                                <td>{{ $order->game_name }} / {{ $order->product_name }}</td>
                                <td>{{ \App\Support\Money::display((string) $order->selling_price, $order->currency) }}</td>
                                <td><x-status-badge :status="$order->status->value" /></td>
                                <td>{{ $order->created_at->format('Y-m-d H:i') }}</td>
                                @can('orders.view')
                                    <td class="p-3 text-right"><a class="text-indigo-600 hover:underline" href="{{ route('admin.orders.show', $order) }}">Open</a></td>
                                @endcan
                            </tr>
                        @empty
                            <tr class="border-t"><td colspan="6" class="p-4 text-gray-500">No orders.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Deposits --}}
            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <div class="p-5 pb-2"><h3 class="font-semibold text-gray-900">Latest deposits</h3></div>
                <table class="min-w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="p-3">Deposit</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                        @forelse ($deposits as $deposit)
                            <tr class="border-t">
                                <td class="p-3 font-medium">{{ $deposit->deposit_number }}</td>
                                <td>{{ \App\Support\Money::display((string) $deposit->amount, $deposit->currency) }}</td>
                                <td><x-status-badge :status="$deposit->status->value" /></td>
                                <td>{{ $deposit->created_at->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr class="border-t"><td colspan="4" class="p-4 text-gray-500">No deposits.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Ledger --}}
            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <div class="p-5 pb-2"><h3 class="font-semibold text-gray-900">Wallet ledger (latest 15)</h3></div>
                <table class="min-w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="p-3">Date</th><th>Type</th><th>Amount</th><th>Balance after</th><th>Description</th></tr></thead>
                    <tbody>
                        @forelse ($transactions as $tx)
                            <tr class="border-t">
                                <td class="p-3">{{ $tx->created_at->format('Y-m-d H:i') }}</td>
                                <td>{{ $tx->type->label() }}</td>
                                <td class="{{ $tx->direction === 'credit' ? 'text-green-700' : 'text-red-700' }}">{{ $tx->direction === 'credit' ? '+' : '-' }}{{ \App\Support\Money::display((string) $tx->amount, $tx->currency) }}</td>
                                <td>{{ \App\Support\Money::display((string) $tx->balance_after, $tx->currency) }}</td>
                                <td class="text-xs text-gray-600">{{ $tx->description }}@if (! empty($tx->meta['reason'])) - {{ $tx->meta['reason'] }}@endif</td>
                            </tr>
                        @empty
                            <tr class="border-t"><td colspan="5" class="p-4 text-gray-500">No transactions.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Audit --}}
            @can('audit.view')
                <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                    <div class="p-5 pb-2"><h3 class="font-semibold text-gray-900">Changes to this account</h3></div>
                    <table class="min-w-full text-sm">
                        <thead><tr class="text-left text-gray-500"><th class="p-3">When</th><th>By</th><th>Action</th><th>Details</th></tr></thead>
                        <tbody>
                            @forelse ($audit as $log)
                                <tr class="border-t">
                                    <td class="p-3">{{ $log->created_at->format('Y-m-d H:i') }}</td>
                                    <td>{{ $log->actor?->email ?? 'system' }}</td>
                                    <td>{{ $log->action }}</td>
                                    <td class="text-xs text-gray-600">{{ json_encode($log->new_values) }}</td>
                                </tr>
                            @empty
                                <tr class="border-t"><td colspan="4" class="p-4 text-gray-500">Nothing recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endcan
        </div>
    </div>
</x-app-layout>
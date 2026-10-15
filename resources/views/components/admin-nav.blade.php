@php
    $user = auth()->user();

    $attentionCount = $user?->hasPermission('orders.view')
        ? \App\Models\ProviderOrder::query()->where('status', 'review')->count()
        : 0;
    $unreadStaff = $user?->unreadNotifications()->count() ?? 0;

    // Permission null = everybody who can open the admin area. Badge = a red number next to the name.
    $groups = [
        ['Overview', [
            ['Dashboard', 'admin.dashboard', 'admin.dashboard', null, 0],
            ['Needs attention', 'admin.attention.index', 'admin.attention.*', 'orders.view', $attentionCount],
        ]],
        ['Catalog', [
            ['Games', 'admin.games.index', 'admin.games.*', 'games.manage', 0],
            ['Products', 'admin.products.index', 'admin.products.*', 'products.manage', 0],
            ['Provider', 'admin.provider.index', 'admin.provider.*', 'provider.manage', 0],
        ]],
        ['Sales', [
            ['Orders', 'admin.orders.index', 'admin.orders.*', 'orders.view', 0],
            ['Deposits', 'admin.deposits.index', 'admin.deposits.*', 'deposits.view', 0],
            ['Ledger', 'admin.transactions.index', 'admin.transactions.*', 'transactions.view', 0],
        ]],
        ['Accounts', [
            ['Users', 'admin.users.index', 'admin.users.*', 'users.view', 0],
        ]],
        ['Insight', [
            ['Reports', 'admin.reports.index', 'admin.reports.*', 'reports.view', 0],
            ['Audit log', 'admin.audit.index', 'admin.audit.*', 'audit.view', 0],
        ]],
        ['System', [
            ['Health', 'admin.health.index', 'admin.health.*', 'settings.manage', 0],
            ['Settings', 'admin.settings.edit', 'admin.settings.*', 'settings.manage', 0],
            ['Notifications', 'notifications.index', 'notifications.*', null, $unreadStaff],
        ]],
    ];

    $allowed = fn (?string $permission) => $permission === null || ($user !== null && $user->can($permission));
@endphp

{{-- Desktop: a sticky column on the left. Phone: a bar with a button that opens the list. --}}
<style>
    @media (min-width: 1024px) {
        main:has(> .admin-sidebar) { display: grid; grid-template-columns: 16rem minmax(0, 1fr); align-items: start; }
        main:has(> .admin-sidebar) > .admin-sidebar { grid-column: 1; grid-row: 1 / span 50; position: sticky; top: 0; max-height: 100vh; overflow-y: auto; }
        main:has(> .admin-sidebar) > :not(.admin-sidebar) { grid-column: 2; }
    }
</style>

<aside x-data="{ open: false }" class="admin-sidebar bg-white border-b border-gray-200 lg:min-h-[calc(100vh-4rem)] lg:border-b-0 lg:border-e">
    <div class="flex items-center justify-between px-4 py-3 lg:hidden">
        <span class="text-sm font-semibold text-gray-800">Admin menu</span>
        <button type="button" @click="open = ! open" class="rounded-md p-2 text-gray-500 hover:bg-gray-50" aria-label="Toggle admin menu" :aria-expanded="open.toString()">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>
    </div>

    <nav :class="{ 'hidden': ! open }" class="hidden space-y-5 px-3 pb-4 lg:block lg:py-5" aria-label="Admin">
        @foreach ($groups as [$title, $items])
            @php $visible = array_values(array_filter($items, fn ($item) => $allowed($item[3]))); @endphp

            @if (count($visible) > 0)
                <div>
                    <div class="px-3 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">{{ $title }}</div>

                    <ul class="space-y-0.5">
                        @foreach ($visible as [$label, $route, $pattern, $permission, $badge])
                            @php $active = request()->routeIs($pattern); @endphp
                            <li>
                                <a href="{{ route($route) }}"
                                   @if ($active) aria-current="page" @endif
                                   class="flex items-center justify-between rounded-md px-3 py-2 text-sm {{ $active ? 'bg-indigo-600 font-medium text-white' : 'text-gray-700 hover:bg-gray-50' }}">
                                    <span>{{ $label }}</span>
                                    @if ($badge > 0)
                                        <span class="ml-2 rounded-full bg-red-600 px-1.5 text-xs text-white">{{ $badge > 99 ? '99+' : $badge }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endforeach
    </nav>
</aside>
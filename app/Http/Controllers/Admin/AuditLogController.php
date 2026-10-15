<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Read-only viewer for the audit log (audit.view). Nothing here can change or delete a row.
 */
class AuditLogController extends Controller
{
    private const ACTOR_TYPES = ['user', 'staff', 'system'];

    public function index(Request $request, ReportService $dates): View
    {
        $q = $this->text($request->query('q'), 100);
        $like = '%'.addcslashes($q, '%_\\').'%';
        $action = $this->text($request->query('action'), 100);
        $actorType = in_array($request->query('actor_type'), self::ACTOR_TYPES, true) ? $request->query('actor_type') : null;
        $from = $dates->parseDate($request->query('from'));
        $to = $dates->parseDate($request->query('to'));

        $logs = AuditLog::query()
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('action', 'like', $like)
                    ->orWhere('ip', 'like', $like)
                    ->orWhereHas('actor', fn ($a) => $a->where('email', 'like', $like)->orWhere('name', 'like', $like))
            ))
            ->when($action !== '', fn ($query) => $query->where('action', $action))
            ->when($actorType, fn ($query) => $query->where('actor_type', $actorType))
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from->startOfDay()))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to->copy()->endOfDay()))
            ->with('actor:id,name,email')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.audit.index', [
            'logs' => $logs,
            'q' => $q,
            'action' => $action,
            'actorType' => $actorType,
            'from' => is_string($request->query('from')) ? $request->query('from') : '',
            'to' => is_string($request->query('to')) ? $request->query('to') : '',
            'actions' => AuditLog::query()->distinct()->orderBy('action')->limit(300)->pluck('action'),
            'actorTypes' => self::ACTOR_TYPES,
        ]);
    }

    public function show(AuditLog $auditLog): View
    {
        $auditLog->load('actor:id,name,email');

        // A link to the thing the entry is about, only when the viewer may open it.
        $subjectUrl = null;

        if ($auditLog->subject_id !== null) {
            if ($auditLog->subject_type === (new Order)->getMorphClass() && Gate::allows('orders.view')) {
                $subjectUrl = route('admin.orders.show', $auditLog->subject_id);
            } elseif ($auditLog->subject_type === (new User)->getMorphClass() && Gate::allows('users.view')) {
                $subjectUrl = route('admin.users.show', $auditLog->subject_id);
            }
        }

        return view('admin.audit.show', [
            'log' => $auditLog,
            'subjectUrl' => $subjectUrl,
            'old' => $this->pretty($auditLog->old_values),
            'new' => $this->pretty($auditLog->new_values),
        ]);
    }

    private function text(mixed $value, int $max): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }

    private function pretty(?array $values): ?string
    {
        if ($values === null || $values === []) {
            return null;
        }

        return json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }
}
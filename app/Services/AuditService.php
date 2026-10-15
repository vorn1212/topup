<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditService
{
    /** Keys whose values are never stored. */
    private const REDACTED_KEYS = [
        'password', 'password_confirmation', 'current_password',
        'token', 'remember_token', 'secret', 'secret_key',
        'api_key', 'authorization', 'hash',
    ];

    public function log(
        string $action,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        ?User $actor = null,
    ): AuditLog {
        $actor ??= Auth::user();
        $inConsole = app()->runningInConsole();

        return AuditLog::create([
            'actor_id' => $actor?->getKey(),
            'actor_type' => $actor ? ($actor->isStaff() ? 'staff' : 'user') : 'system',
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'old_values' => $old === [] ? null : $this->redact($old),
            'new_values' => $new === [] ? null : $this->redact($new),
            'ip' => $inConsole ? null : request()->ip(),
            'user_agent' => $inConsole ? null : Str::limit((string) request()->userAgent(), 255, ''),
            'created_at' => now(),
        ]);
    }

    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
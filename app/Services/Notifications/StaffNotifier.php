<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Throwable;

/**
 * Sends one notification to every active admin and super admin.
 * A failure here is reported but never breaks the job that called it.
 */
class StaffNotifier
{
    public static function send(Notification $notification): void
    {
        try {
            $staff = User::query()
                ->where('status', 'active')
                ->whereIn('role', ['admin', 'super_admin'])
                ->get();

            if ($staff->isNotEmpty()) {
                NotificationFacade::send($staff, $notification);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryStatus;
use App\Models\NotificationDelivery;
use App\Notifications\NotificationDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Zustellprotokoll: einen endgültig fehlgeschlagenen Versuch von Hand
 * wiederholen. Die automatischen Versuche macht die Warteschlange selbst —
 * hier geht es um den Fall danach, wenn etwa das Ziel tagelang weg war.
 */
class NotificationDeliveryController extends Controller
{
    public function retry(NotificationDelivery $delivery, NotificationDispatcher $dispatcher): RedirectResponse
    {
        Gate::authorize('send', $delivery->channel);

        if ($delivery->status !== DeliveryStatus::Failed) {
            // Eine laufende oder bereits zugestellte Nachricht ein zweites Mal
            // einzureihen, würde sie doppelt zustellen.
            return back()->with('status', __('notifications.flash.delivery_not_failed'));
        }

        $dispatcher->retry($delivery);

        return back()->with('status', __('notifications.flash.delivery_retried'));
    }
}

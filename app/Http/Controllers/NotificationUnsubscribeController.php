<?php

namespace App\Http\Controllers;

use App\Enums\NotificationEventType;
use App\Enums\NotificationTransport;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Notifications\PreferenceScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Abmelden über den Link aus der Mail — ohne Anmeldung.
 *
 * Beide Aktionen hängen an derselben signierten Adresse: die Signatur gilt für
 * die Adresse, nicht für das Verfahren, weshalb das Formular schlicht auf
 * dieselbe URL zurückschickt.
 *
 * Die Abmeldung passiert bewusst erst auf Klick und nicht schon beim Öffnen
 * des Links: Virenscanner und Vorschau-Funktionen rufen Adressen aus Mails
 * ungefragt auf, und ein solcher Aufruf würde sonst die Bereitschaft
 * stummschalten, ohne dass je ein Mensch beteiligt war.
 */
class NotificationUnsubscribeController extends Controller
{
    public function show(Request $request, User $user, string $event, NotificationPreferences $preferences): InertiaResponse
    {
        $type = $this->event($event);

        return Inertia::render('notifications/Unsubscribe', [
            'recipient' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'event' => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
                'critical' => $type->isCritical(),
            ],
            // Dieselbe Adresse samt Signatur — nur eben als POST.
            'applyHref' => $request->fullUrl(),
            'settingsHref' => route('notifications.preferences'),
            'state' => [
                'eventOff' => ! $preferences->wants($user, $type, NotificationTransport::Mail),
                'allOff' => $user->notificationSettingOrDefault()->isUnsubscribed(),
            ],
        ]);
    }

    /**
     * Wirkt sofort: die Entscheidung steht in der Datenbank, bevor die Antwort
     * das Haus verlässt, und der Zustell-Job fragt sie unmittelbar vor jedem
     * Versand erneut ab. Schon eingereihte Mails gehen damit nicht mehr raus.
     */
    public function store(Request $request, User $user, string $event, NotificationPreferences $preferences): RedirectResponse
    {
        $type = $this->event($event);

        $mode = $request->validate([
            'mode' => ['required', Rule::in(['event', 'all'])],
        ])['mode'];

        if ($mode === 'all') {
            $setting = $user->ensureNotificationSetting();
            $setting->unsubscribed_at = Date::now();
            $setting->save();

            $status = 'Abgemeldet. Kritische Alarme kommen weiterhin an — alles andere nicht mehr.';
        } else {
            // Abgemeldet wird der Weg, über den die Mail kam. Das Postfach in
            // der Anwendung bleibt unberührt: wer keine Mail mehr will, will
            // deshalb nicht nichts mehr wissen.
            NotificationPreference::put(
                $user,
                PreferenceScope::global(),
                $type,
                NotificationTransport::Mail,
                false,
            );

            $status = 'Keine E-Mails mehr zu „'.$type->label().'“.';
        }

        $preferences->forget($user);

        return back()->with('status', $status);
    }

    private function event(string $event): NotificationEventType
    {
        return NotificationEventType::tryFrom($event)
            ?? throw new NotFoundHttpException("Unbekannter Anlass: {$event}");
    }
}

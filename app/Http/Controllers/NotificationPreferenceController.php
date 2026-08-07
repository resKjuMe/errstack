<?php

namespace App\Http\Controllers;

use App\Http\Requests\NotificationPreferenceRequest;
use App\Http\Requests\QuietHoursRequest;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Support\NotificationPreferenceData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die persönlichen Benachrichtigungs-Einstellungen eines Nutzers.
 *
 * Hier gibt es nichts zu autorisieren: jeder verwaltet ausschließlich seine
 * eigenen Einstellungen, und welcher Bereich dabei überhaupt in Frage kommt,
 * prüft NotificationPreferenceRequest gegen die Mitgliedschaften.
 */
class NotificationPreferenceController extends Controller
{
    public function index(Request $request, NotificationPreferences $preferences): InertiaResponse
    {
        return Inertia::render(
            'notifications/Preferences',
            NotificationPreferenceData::index($this->viewer($request), $preferences),
        );
    }

    /**
     * Speichert die Entscheidungen eines Bereichs. „Erbt" löscht die Zeile,
     * statt sie auf einen Wert festzuschreiben — nur so wirkt eine spätere
     * Änderung auf gröberer Ebene hier wieder durch.
     */
    public function update(NotificationPreferenceRequest $request, NotificationPreferences $preferences): RedirectResponse
    {
        $user = $this->viewer($request);
        $scope = $request->scope();

        foreach ($request->decisions() as $decision) {
            if ($decision['choice'] === 'inherit') {
                NotificationPreference::forget($user, $scope, $decision['event'], $decision['transport']);

                continue;
            }

            NotificationPreference::put(
                $user,
                $scope,
                $decision['event'],
                $decision['transport'],
                $decision['choice'] === 'on',
            );
        }

        $preferences->forget($user);

        return back()->with('status', __('notifications.flash.preferences_saved'));
    }

    public function quietHours(QuietHoursRequest $request, NotificationPreferences $preferences): RedirectResponse
    {
        $user = $this->viewer($request);

        $user->ensureNotificationSetting()->update([
            'quiet_hours_enabled' => $request->boolean('quiet_hours_enabled'),
            'quiet_from' => $request->validated('quiet_from'),
            'quiet_until' => $request->validated('quiet_until'),
            'timezone' => $request->validated('timezone'),
        ]);

        $preferences->forget($user);

        return back()->with('status', __('notifications.flash.quiet_hours_saved'));
    }

    /**
     * Pauschal ab- oder wieder anbestellen. Kritische Alarme bleiben davon
     * unberührt — das steht so auch an der Schaltfläche.
     */
    public function subscription(Request $request, NotificationPreferences $preferences): RedirectResponse
    {
        $unsubscribed = $request->boolean('unsubscribed');
        $user = $this->viewer($request);

        $setting = $user->ensureNotificationSetting();
        $setting->unsubscribed_at = $unsubscribed ? Date::now() : null;
        $setting->save();

        $preferences->forget($user);

        return back()->with('status', __(
            $unsubscribed ? 'notifications.flash.unsubscribed' : 'notifications.flash.resubscribed',
        ));
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();

        assert($user instanceof User);

        return $user;
    }
}

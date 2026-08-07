<?php

namespace App\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\NotificationTransport;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Die eine Stelle, an der entschieden wird, ob eine Meldung einen bestimmten
 * Nutzer auf einem bestimmten Weg erreichen darf.
 *
 * Wer benachrichtigen will, fragt hier — der Alert-Kern ebenso wie der
 * Wochenbericht und ein späteres Postfach in der Anwendung. Gäbe es zwei
 * solche Stellen, wäre eine davon irgendwann anderer Meinung, und ein
 * abbestellter Nutzer bekäme trotzdem Post.
 *
 * Die Auflösung geht vom feinsten Bereich zum gröbsten: Projekt schlägt
 * Organisation, Organisation schlägt „überall", und wo gar nichts eingestellt
 * ist, gilt die Vorgabe des Anlasses.
 */
final class NotificationPreferences
{
    /**
     * Ausdrückliche Entscheidungen je Nutzer, einmal geladen.
     *
     * @var array<int, array<string, bool>>
     */
    private array $explicit = [];

    /**
     * Darf diese Meldung raus? Das ist die Frage, die der Versand stellt.
     *
     * Ruhezeit und pauschale Abmeldung greifen nur bei nicht-kritischen
     * Anlässen: ein Alarm ist der Grund, warum jemand Errstack einsetzt.
     */
    public function allows(
        User $user,
        NotificationEventType $event,
        NotificationTransport $transport,
        ?Project $project = null,
        ?Organization $organization = null,
        ?Carbon $at = null,
    ): bool {
        if (! $this->wants($user, $event, $transport, $project, $organization)) {
            return false;
        }

        if ($event->isCritical()) {
            return true;
        }

        $settings = $user->notificationSettingOrDefault();

        return ! $settings->isUnsubscribed() && ! $settings->isQuietAt($at);
    }

    /**
     * Nur die Einstellung selbst, ohne Ruhezeit und Abmeldung — das ist der
     * Wert, den die Übersicht als „wirksam" anzeigt.
     */
    public function wants(
        User $user,
        NotificationEventType $event,
        NotificationTransport $transport,
        ?Project $project = null,
        ?Organization $organization = null,
    ): bool {
        foreach (PreferenceScope::chainFor($project, $organization) as $scopeKey) {
            $decision = $this->decision($user, $scopeKey, $event, $transport);

            if ($decision !== null) {
                return $decision;
            }
        }

        return $event->defaultFor($transport);
    }

    /**
     * Alle Wege, auf denen dieser Anlass diesen Nutzer gerade erreichen darf.
     * Ist die Liste leer, ist für ihn nichts zu tun.
     *
     * @return list<NotificationTransport>
     */
    public function transportsFor(
        User $user,
        NotificationEventType $event,
        ?Project $project = null,
        ?Organization $organization = null,
        ?Carbon $at = null,
    ): array {
        return array_values(array_filter(
            NotificationTransport::cases(),
            fn (NotificationTransport $transport): bool => $this->allows($user, $event, $transport, $project, $organization, $at),
        ));
    }

    /**
     * Die ausdrückliche Entscheidung in genau diesem Bereich — oder null,
     * wenn dort nichts entschieden wurde und geerbt wird.
     */
    public function decision(
        User $user,
        string $scopeKey,
        NotificationEventType $event,
        NotificationTransport $transport,
    ): ?bool {
        return $this->explicitFor($user)["{$scopeKey}|{$event->value}|{$transport->value}"] ?? null;
    }

    /**
     * Nach jeder Änderung nötig: derselbe Dienst lebt innerhalb einer Anfrage
     * weiter und würde sonst den Stand von vorher behalten.
     */
    public function forget(User $user): void
    {
        unset($this->explicit[$user->id]);
        $user->unsetRelation('notificationSetting');
    }

    /**
     * @return array<string, bool>
     */
    private function explicitFor(User $user): array
    {
        return $this->explicit[$user->id] ??= NotificationPreference::query()
            ->where('user_id', $user->id)
            ->get()
            ->mapWithKeys(fn (NotificationPreference $preference): array => [
                "{$preference->scope_key}|{$preference->event_type->value}|{$preference->transport->value}" => $preference->enabled,
            ])
            ->all();
    }
}

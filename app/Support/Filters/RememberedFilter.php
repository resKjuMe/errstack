<?php

namespace App\Support\Filters;

use App\Enums\FilterPeriod;
use App\Models\FilterPreference;
use App\Models\Organization;
use App\Models\User;

/**
 * Der zuletzt benutzte Filter eines Kontos in einer Organisation — lesen und
 * fortschreiben.
 *
 * Die Adresszeile bleibt der Ort, an dem der Filterzustand lebt; sie ist teilbar
 * und macht Vor und Zurück richtig. Was ihr fehlt, ist die Erinnerung über den
 * einzelnen Aufruf hinaus: die Links der Navigation führen zu einer nackten
 * Adresse, und nach dem Abmelden ist ohnehin alles vergessen. Diese Klasse
 * schließt genau diese Lücke — nicht mehr.
 *
 * **Die Rangfolge ist eindeutig:** Eine Adresse mit ausdrücklicher Auswahl
 * gewinnt immer ({@see FilterQuery::isExplicit()}); der gemerkte Stand tritt nur
 * ein, wo nichts dasteht. Andernfalls zeigte ein geteilter Link dem Empfänger
 * dessen eigenen Ausschnitt statt des verschickten.
 *
 * **Gemerkt wird die Auswahl, nicht ihr Ergebnis:** „letzte 7 Tage" und nicht
 * die sieben Tage von gestern. Und nicht gemerkt wird die Zeitzone — die gehört
 * dem Browser, nicht dem Konto: wer unterwegs in einer anderen Zone arbeitet,
 * will die Tagesgrenzen seiner Uhr sehen und nicht die von zu Hause.
 */
final class RememberedFilter
{
    /**
     * Der gemerkte Stand in der Form, in der {@see GlobalFilter::resolve()} ihn
     * entgegennimmt — ein leeres Feld, solange nichts gemerkt ist.
     *
     * Geprüft wird hier nichts: ob die gemerkten Projekte und die gemerkte
     * Umgebung noch existieren, entscheidet das Auflösen. Es übergeht
     * Unbekanntes stillschweigend, und genau das ist auch hier gewollt — ein
     * inzwischen gelöschtes Projekt darf die Seite nicht mit einer Fehlermeldung
     * beantworten, sondern fällt einfach aus der Auswahl.
     *
     * @return array{projects?: list<string>, environment?: string|null, period?: string|null, from?: string|null, to?: string|null}
     */
    public static function for(User $user, ?Organization $organization): array
    {
        $preference = self::find($user, $organization);

        if ($preference === null) {
            return [];
        }

        return [
            'projects' => $preference->projects,
            'environment' => $preference->environment,
            'period' => $preference->period,
            'from' => $preference->custom_from?->format('Y-m-d'),
            'to' => $preference->custom_to?->format('Y-m-d'),
        ];
    }

    /**
     * Den aufgelösten Filter als „zuletzt benutzt" festhalten.
     *
     * Festgehalten wird der **aufgelöste** Stand und nicht die rohe Eingabe:
     * dadurch wandert nur wieder in die Tabelle, was es auch wirklich gibt, und
     * ein Link auf ein fremdes Projekt hinterlässt keinen Eintrag, der beim
     * nächsten Aufruf ohnehin verworfen würde.
     *
     * Ohne Organisation gibt es nichts zu merken — dann hat der Betrachter auch
     * keine Projekte zur Auswahl.
     */
    public static function remember(User $user, GlobalFilter $filter): void
    {
        if ($filter->organization === null) {
            return;
        }

        $values = $filter->formValues();
        $custom = $filter->period === FilterPeriod::Custom;

        $attributes = [
            'projects' => $values['projects'],
            'environment' => $values['environment'] === '' ? null : $values['environment'],
            'period' => $values['period'],
            // Die Datumsfelder gehören zum eigenen Zeitraum. Bei einem relativen
            // stünden dort die Grenzen von heute — und beim nächsten Aufruf sähe
            // es aus, als sei „letzte 24 Stunden" einmal ein fester Tag gewesen.
            'custom_from' => $custom ? $values['from'] : null,
            'custom_to' => $custom ? $values['to'] : null,
        ];

        $preference = self::find($user, $filter->organization);

        // Der Regelfall ist, dass sich nichts geändert hat: man sieht sich
        // dieselbe Auswahl auf mehreren Seiten an. Ein Schreibvorgang bei jedem
        // Seitenaufruf wäre für diesen Fall ein Schreibvorgang zu viel — die
        // Tabelle hält fest, was zuletzt **gewählt** wurde, und gewählt wurde
        // dabei nichts.
        if ($preference !== null && self::matches($preference, $attributes)) {
            return;
        }

        FilterPreference::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'organization_id' => $filter->organization->getKey()],
            $attributes,
        );
    }

    /**
     * @param  array{projects: list<string>, environment: string|null, period: string, custom_from: string|null, custom_to: string|null}  $attributes
     */
    private static function matches(FilterPreference $preference, array $attributes): bool
    {
        return $preference->projects === $attributes['projects']
            && $preference->environment === $attributes['environment']
            && $preference->period === $attributes['period']
            && $preference->custom_from?->format('Y-m-d') === $attributes['custom_from']
            && $preference->custom_to?->format('Y-m-d') === $attributes['custom_to'];
    }

    private static function find(User $user, ?Organization $organization): ?FilterPreference
    {
        if ($organization === null) {
            return null;
        }

        return FilterPreference::query()
            ->where('user_id', $user->getKey())
            ->where('organization_id', $organization->getKey())
            ->first();
    }
}

<?php

namespace App\Concerns;

use App\Support\Releases\Health\SessionTally;
use Illuminate\Support\Facades\DB;

/**
 * Schreibt eine Strichliste über Sitzungen auf eine Zähler-Zeile fort.
 *
 * Zwei Tabellen brauchen dasselbe — die Zahlen je Version und die je Nutzer —,
 * und sie unterscheiden sich nur in ihrem Schlüssel. Der Ablauf dahinter ist
 * derselbe und steht deshalb einmal hier: Zeile anlegen, falls sie fehlt, dann
 * in einer Anweisung hochzählen.
 *
 * **Ohne Sperre**, wie bei den Nutzer-Zahlen der Antwortzeiten und aus
 * demselben Grund: es wird ausschließlich gerechnet, und das erledigt die
 * Datenbank selbst. Zwei gleichzeitig eintreffende Sitzungen ergeben zwei, in
 * welcher Reihenfolge sie auch ankommen. Gelesen-geändert-geschrieben wäre hier
 * der Engpass, an dem die Aufnahme stehen bliebe — bei einem Ausfall tragen
 * **alle** gleichzeitig verarbeiteten Meldungen dieselbe Version.
 *
 * Das Anlegen läuft über `insertOrIgnore` gegen den eindeutigen Schlüssel:
 * legen zwei Arbeiter dieselbe Zeile im selben Augenblick an, gewinnt einer und
 * der andere zählt auf dessen Zeile hoch.
 */
trait TalliesSessions
{
    /**
     * Schreibt die Differenz auf die Zeile zu diesem Schlüssel fort.
     *
     * @param  array<string, mixed>  $key  Die Spalten des eindeutigen Schlüssels.
     * @param  SessionTally  $delta  Was sich geändert hat — bei einer neuen
     *                               Sitzung ihre eigene Strichliste, bei einer
     *                               Folgemeldung die Differenz zum bisherigen
     *                               Ausgang. Negative Werte sind hier der
     *                               Normalfall und kein Sonderfall.
     */
    public static function apply(array $key, SessionTally $delta): void
    {
        if ($delta->isEmpty()) {
            // Eine Folgemeldung, die am Ausgang nichts ändert — der häufigste
            // Fall bei einem SDK, das im Minutentakt „läuft" meldet. Ohne
            // diesen Ausstieg wäre jede davon eine Schreibanweisung.
            return;
        }

        $now = now();

        static::query()->insertOrIgnore($key + ['created_at' => $now, 'updated_at' => $now]);

        static::query()->where($key)->update(static::sessionIncrements($delta));
    }

    /**
     * Die Rechenanweisungen je Spalte.
     *
     * Der Abzug ist gegen den Nullwert abgesichert (`case when`), und das ist
     * keine Vorsicht ohne Anlass: die Zähler sind vorzeichenlos, und ein
     * Abzug unter null bräche die Anweisung ab — mitten in der Verarbeitung
     * einer Meldung. Dazu kommen kann es, wenn Zähler und Einzelsitzungen
     * auseinanderlaufen, etwa nachdem alte Sitzungen abgeräumt wurden, ihre
     * Folgemeldung aber noch eintrifft. Der Zähler ist dann um eins daneben —
     * die Aufnahme läuft weiter.
     *
     * @return array<string, mixed>
     */
    private static function sessionIncrements(SessionTally $delta): array
    {
        $increments = ['updated_at' => now()];

        foreach ($delta->columns() as $column => $amount) {
            if ($amount === 0) {
                continue;
            }

            $increments[$column] = $amount > 0
                ? DB::raw($column.' + '.$amount)
                : DB::raw('case when '.$column.' >= '.abs($amount).' then '.$column.' - '.abs($amount).' else 0 end');
        }

        return $increments;
    }
}

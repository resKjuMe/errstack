<?php

namespace App\Support\Ingest\Security;

use App\Enums\InboundFilterKind;
use App\Support\Ingest\Filtering\Defaults;
use App\Support\Ingest\Filtering\Pattern;

/**
 * Erkennt Sicherheitsberichte, die eine Browser-Erweiterung ausgelöst hat.
 *
 * Der größte Teil dessen, was an einem `report-uri` ankommt, stammt nicht von
 * der überwachten Anwendung: Erweiterungen laden Skripte nach, schreiben
 * Formulare um und klinken Stilangaben ein — und jedes Mal meldet der Browser
 * pflichtgemäß einen Verstoß gegen die Richtlinie der Seite. Ungefiltert
 * besteht die Fehlerliste danach aus Werbeblockern und Passwortverwaltungen.
 *
 * **Gefiltert wird hier ohne Rückfrage beim Projekt**, anders als beim
 * Eingangsfilter (I8), der je Projekt ein- und ausschaltbar ist. Der Grund ist
 * der Unterschied im Befund: ein JavaScript-Fehler aus einer Erweiterung
 * *könnte* die eigene Seite betreffen — sie hat das Skript schließlich
 * ausgeführt. Ein CSP-Bericht über `chrome-extension://…` kann es nicht: der
 * Browser sagt darin ausdrücklich, dass eine **Erweiterung** etwas tun wollte,
 * was die Richtlinie der Seite verbietet. Damit ist der Bericht kein Befund
 * über die Anwendung, sondern über die Installation des Besuchers, und die
 * kennt niemand, der hier nachsieht.
 *
 * Die Listen sind bewusst dieselben wie beim Eingangsfilter
 * ({@see Defaults}). Eine zweite Liste neben der ersten wäre die Sorte
 * Doppelung, die man beim nächsten Browser-Hersteller zur Hälfte nachpflegt.
 */
final class ExtensionNoise
{
    /**
     * Werte, die ohne Schema für „Browser-Innenleben" stehen.
     *
     * Chrome schreibt bei Verstößen aus seiner eigenen Oberfläche schlicht
     * `about` in `blocked-uri` — ohne `:` und ohne `//`, weshalb der Vergleich
     * mit den Schemata daran vorbeigeht. Verglichen wird auf den **ganzen**
     * Wert: `about` ist Rauschen, `https://about.example` ist es nicht.
     *
     * @var list<string>
     */
    private const BARE_SOURCES = ['about', 'null'];

    /**
     * Woran die Erweiterung erkannt wurde — oder `null`, wenn der Bericht
     * bleibt.
     *
     * Zurück kommt das Kennzeichen und nicht bloß `true`: es steht danach in
     * der Protokollzeile, und ohne es wäre ein zu weit gefasster Filter nicht
     * mehr nachzuvollziehen.
     */
    public static function match(SecurityReport $report): ?string
    {
        $sources = $report->sources();

        if ($sources === []) {
            return null;
        }

        foreach ($sources as $source) {
            foreach (Defaults::EXTENSION_SCHEMES as $scheme) {
                if (stripos($source, $scheme) === 0) {
                    return $scheme;
                }
            }

            if (in_array(strtolower(trim($source)), self::BARE_SOURCES, true)) {
                return strtolower(trim($source));
            }
        }

        foreach (Defaults::EXTENSION_HOSTS as $expression) {
            if (Pattern::matchesAny($expression, $sources)) {
                return $expression;
            }
        }

        return null;
    }

    /**
     * Die Kategorie, unter der die Verwerfung gezählt wird.
     *
     * Dieselbe wie beim Eingangsfilter, damit „wie viel Rauschen aus
     * Erweiterungen wurde aussortiert?" **eine** Zahl bleibt und nicht zwei,
     * die man erst zusammenzählen muss.
     */
    public static function category(): string
    {
        return InboundFilterKind::BrowserExtension->value;
    }
}

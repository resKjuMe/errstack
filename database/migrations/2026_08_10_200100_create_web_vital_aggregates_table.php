<?php

use App\Enums\VitalRating;
use App\Models\Transaction;
use App\Models\WebVitalAggregate;
use App\Support\Performance\Vitals\VitalHistogram;
use App\Support\Performance\Vitals\WebVitalDetail;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Vorberechnung der Browser-Messwerte: je Seite, Messwert, Umgebung und
     * Zeitfenster eine Zeile.
     *
     * **Warum nicht die vorhandene Vorberechnung der Antwortzeiten?** Weil dort
     * eine Zeile für die ganze Transaktion steht und hier eine je Messwert
     * gebraucht wird. Ein Ladevorgang liefert bis zu sechs Zahlen — LCP, INP,
     * CLS, FCP, TTFB, FID —, jede mit eigener Verteilung und eigener Schwelle.
     * Sie in eine Zeile zu quetschen hieße, für jeden Messwert einen Satz
     * Spalten anzulegen (sechs Verteilungen, sechs Summen, achtzehn Zähler) und
     * bei jedem neuen Messwert der Spezifikation eine Migration zu schreiben.
     * Mit dem Messwert im Schlüssel wächst stattdessen die Zeilenzahl — und die
     * ist der billige Teil.
     *
     * **Warum überhaupt eine Vorberechnung?** Aus demselben Grund wie bei den
     * Antwortzeiten: die Übersicht soll unabhängig von der Datenmenge in einer
     * Sekunde stehen. Über die Einzelmessungen gerechnet wäre sie ein Vollscan
     * über jede Seitenansicht des Zeitraums.
     *
     * **Was hier nicht steht: Browser, Gerät und Land.** Sie gehören in den
     * Schlüssel, wenn man danach aufschlüsseln will — und würden die Zeilenzahl
     * mit jedem Browser mal jedem Gerät mal jedem Land vervielfachen. Ein
     * einziger Nachmittag echten Verkehrs machte daraus Millionen Zeilen je
     * Seite. Die Aufschlüsselung liest deshalb eine Stichprobe der
     * Einzelmessungen ({@see WebVitalDetail}) —
     * dieselbe Abwägung, die schon die Transaktions-Detailseite (PF3) trifft:
     * Kennzahlen vollständig aus der Vorberechnung, Anteile aus einer
     * Stichprobe.
     *
     * Wie bei den Antwortzeiten prüft jeder Schritt, ob es ihn schon gibt: MySQL
     * kann DDL nicht zurückrollen, und ein abgebrochener Lauf lässt alles
     * Vorherige ohne Eintrag in `migrations` stehen.
     */
    public function up(): void
    {
        if (! Schema::hasTable('web_vital_aggregates')) {
            Schema::create('web_vital_aggregates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();

                // Nicht `nullable`, aus demselben Grund wie bei den
                // Antwortzeiten: zwei `NULL` gelten in MySQL im eindeutigen
                // Index als verschieden, und die Zeile „ohne Umgebung" entstünde
                // bei jeder Messung neu.
                $table->string('environment', 64);

                // Die Seite — der Transaktionsname des Ladevorgangs. Derselbe
                // Name wie in der Performance-Übersicht, damit sich beide Seiten
                // verlinken lassen und niemand raten muss, ob „/produkte" hier
                // dasselbe meint wie dort.
                $table->string('name', Transaction::NAME_LIMIT);

                // Welcher Messwert ({@see \App\Enums\WebVital}). Als Text und
                // nicht als Zahl: die Zeilen werden von Hand gelesen, wenn eine
                // Auswertung nicht stimmt, und `lcp` sagt dabei mehr als `1`.
                $table->string('vital', WebVitalAggregate::VITAL_LIMIT);

                // Anfang des Zeitfensters, in der Auflösung der Antwortzeiten
                // ({@see Transaction::BUCKET_SECONDS}). Dieselbe Rasterung,
                // damit sich beide Vorberechnungen über denselben Zeitraum
                // vergleichen lassen, ohne dass eine von beiden umgerechnet
                // werden muss.
                $table->timestamp('window_start');

                $table->unsignedBigInteger('measurement_count')->default(0);

                // Die Bewertung als drei Zähler — der Kern dieser Tabelle.
                //
                // Sie werden beim Eintreffen jeder Messung mit deren **genauem**
                // Wert hochgezählt. Damit ist die Bewertung einer Seite exakt,
                // obwohl die Werte selbst nur als Verteilung vorliegen: aus der
                // Verteilung allein ließe sich nur sagen, in welcher Klasse das
                // Perzentil ungefähr liegt, und genau an der Schwelle wäre
                // „ungefähr" die falsche Auskunft.
                foreach (VitalRating::ordered() as $rating) {
                    $table->unsignedBigInteger($rating->column())->default(0);
                }

                // Summe, Kleinstes und Größtes in Millionsteln. Aus Summe und
                // Anzahl entsteht der Mittelwert für **jeden** Zeitraum, weil
                // beide sich addieren lassen.
                $table->unsignedBigInteger('value_sum')->default(0);
                $table->unsignedBigInteger('value_min')->nullable();
                $table->unsignedBigInteger('value_max')->nullable();

                // Die Verteilung als Häufigkeiten über festen Klassen
                // ({@see VitalHistogram}) — feiner aufgelöst als die der
                // Antwortzeiten, weil die Schwellen der Web Vitals mitten in
                // deren Klassen lägen.
                $table->json('value_histogram')->nullable();

                // Ohne Bruchteile: `window_start` ist auf die Minute
                // abgeschnitten und steht im eindeutigen Schlüssel. Ein Wert mit
                // Millisekunden wäre dort eine zweite Schreibweise desselben
                // Fensters — und damit eine zweite Zeile.
                $table->timestamps();
            });
        }

        // Die Indizes außerhalb der Definition, aus demselben Grund wie bei den
        // Antwortzeiten: scheitert ein Lauf zwischen Tabelle und Index, bliebe
        // die Tabelle sonst für immer ohne den eindeutigen Schlüssel — und
        // {@see WebVitalAggregate::record()} hängt an ihm, weil das
        // `insertOrIgnore` ohne ihn bei jeder Messung eine weitere Zeile
        // anlegte, statt die bestehende zu finden.
        $indexes = [
            // Der Name ist von Hand gesetzt: aus Tabelle und fünf Spalten
            // gebildet wäre er länger als die 64 Zeichen, die MySQL für einen
            // Bezeichner zulässt.
            'web_vital_aggregates_window_unique' => fn (Blueprint $table) => $table->unique(
                ['project_id', 'environment', 'name', 'vital', 'window_start'],
                'web_vital_aggregates_window_unique',
            ),

            // Die Übersicht liest einen Zeitraum je Projekt.
            'web_vital_aggregates_project_id_window_start_index' => fn (Blueprint $table) => $table->index(
                ['project_id', 'window_start'],
            ),
        ];

        $missing = array_filter(
            $indexes,
            fn (string $name): bool => ! Schema::hasIndex('web_vital_aggregates', $name),
            ARRAY_FILTER_USE_KEY,
        );

        if ($missing === []) {
            return;
        }

        Schema::table('web_vital_aggregates', function (Blueprint $table) use ($missing) {
            foreach ($missing as $definition) {
                $definition($table);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_vital_aggregates');
    }
};

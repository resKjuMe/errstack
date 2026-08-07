<?php

use App\Models\Transaction;
use App\Support\Performance\DurationHistogram;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Ablage der Antwortzeiten: Transaktionen, ihre Einzelschritte und die
     * Vorberechnung, aus der die Auswertungen lesen.
     *
     * Drei Tabellen in einer Migration, weil sie zusammen eine Einheit sind —
     * eine Transaktion ohne ihre Spans ist eine Zahl ohne Erklärung, und ohne
     * die Vorberechnung wäre jede Übersichtsseite ein Vollscan über Millionen
     * Zeilen.
     *
     * Warum die Daten **nicht** bei den Fehlermeldungen liegen: eine
     * Transaktion ist kein Fehler. Sie kommt in ganz anderer Menge (jede
     * Seitenansicht, nicht nur die kaputte), wird anders ausgewertet
     * (Verteilungen statt Einzelfälle) und darf in keiner Fehlerliste
     * auftauchen. Eine gemeinsame Tabelle mit einer Spalte „ist Fehler" würde
     * genau das jedem Aufrufer zur Pflicht machen — und beim ersten
     * vergessenen Filter stünde die Startseite voll mit erfolgreichen
     * Seitenaufrufen.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Woher die Zahlen kommen. `nullOnDelete`, weil das Aufräumen alter
            // Rohdaten (O2) früher greift als die Aufbewahrung der Auswertung:
            // die Transaktion soll die Löschung ihres Eingangs überleben.
            $table->foreignId('ingest_payload_id')->nullable()->constrained()->nullOnDelete();

            // Die Nummer der Meldung, unter der das SDK die Transaktion geführt
            // hat — dieselbe Form wie bei den Fehlern (32 Hex-Zeichen).
            $table->char('event_id', 32);

            // Der Trace-Zusammenhang. `trace_id` verbindet die Aufrufe
            // **mehrerer** Dienste zu einem Ablauf, `span_id` ist die Kennung
            // dieser Transaktion darin, `parent_span_id` zeigt auf den
            // aufrufenden Dienst — bei der äußersten Transaktion leer.
            $table->char('trace_id', 32);
            $table->char('span_id', 16);
            $table->char('parent_span_id', 16)->nullable();

            $table->string('name', Transaction::NAME_LIMIT);
            $table->string('op', Transaction::OP_LIMIT)->nullable();

            // Woraus der Name gebildet wurde (`route`, `url`, `custom` …). Ohne
            // diese Angabe ist nicht zu erkennen, ob ein Name für die
            // Gruppierung taugt: `/users/4711` ist ein eigener „Name" je Nutzer
            // und würde die Übersicht mit Einzelfällen fluten.
            $table->string('source', 32)->nullable();

            $table->string('status', 32)->nullable();
            $table->string('platform', 32)->nullable();

            // Umgebung und Version als Text, nicht als Fremdschlüssel: eine
            // versteckte oder gelöschte Umgebung soll ihre Messwerte nicht
            // mitnehmen (siehe `environments`).
            $table->string('environment', 64);
            $table->string('release')->nullable();

            // Wer den Aufruf ausgelöst hat, als eine Angabe (id, Name, E-Mail
            // oder Adresse — was das SDK mitgeschickt hat). Für „welche Nutzer
            // sind betroffen" reicht eine Kennung; die vollen Nutzerdaten
            // gehören zu den Fehlermeldungen, nicht in eine Zeitreihe.
            $table->string('user_identifier')->nullable();

            // Anfang und Ende auf Millisekunden genau. Ohne Bruchteile fielen
            // alle Aufrufe unter einer Sekunde auf denselben Zeitpunkt und die
            // Reihenfolge im Trace wäre verloren.
            $table->timestamp('started_at', 3);
            $table->timestamp('finished_at', 3);

            // Die Dauer in **Mikrosekunden**. Millisekunden wären zu grob: ein
            // Datenbankaufruf von 300 µs wäre 0 ms, und eine Verteilung, in der
            // die Hälfte der Werte 0 ist, taugt für keine Auswertung.
            $table->unsignedBigInteger('duration_us');

            // Wie viele Einzelschritte abgelegt wurden. Steht hier, damit die
            // Übersicht die Zahl nicht je Zeile nachzählen muss; bei
            // abgeschnittenen Transaktionen ({@see Transaction::SPAN_LIMIT}) ist
            // es die Zahl der **abgelegten**, nicht der gemeldeten Schritte.
            $table->unsignedSmallInteger('span_count')->default(0);

            // Messwerte des SDK (`lcp`, `fcp`, `ttfb` …) als Feld-Baum: welche
            // es gibt, entscheidet die überwachte Anwendung, nicht wir. Eine
            // Spalte je Messwert hieße, für jede neue Kennzahl eine Migration zu
            // schreiben — die Bewertung der Web Vitals kommt erst mit PF5.
            $table->json('measurements')->nullable();

            $table->timestamps(3);

            // Dieselbe Transaktion darf nur einmal gezählt werden. Die
            // Doppelerkennung der Verarbeitung greift zwar vorher, deckt aber
            // nur den Weg über die Warteschlange ab; hier entscheidet die
            // Datenbank, und ein erneuter Durchlauf derselben Rohdaten
            // aktualisiert die Zeile statt eine zweite anzulegen.
            $table->unique(['project_id', 'event_id']);

            // Die Abfrage der Performance-Übersicht (PF2) und der Detailseite
            // (PF3): ein Transaktionsname in einem Zeitraum, je Umgebung.
            $table->index(['project_id', 'name', 'started_at']);

            // Die Liste „was war in diesem Zeitraum langsam" ohne Namensfilter.
            $table->index(['project_id', 'started_at']);

            // Die Trace-Ansicht (PF4) sammelt alle Transaktionen eines Ablaufs —
            // und die stehen bei mehreren Diensten in mehreren Projekten. Der
            // Index trägt deshalb bewusst kein `project_id` vorneweg.
            $table->index('trace_id');
        });

        Schema::create('transaction_spans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();

            // Doppelt geführt, obwohl es über die Transaktion zu erreichen wäre:
            // das Aufräumen (O2) und jede Auswertung „langsamste Abfragen dieses
            // Projekts" filtern danach, und ein Join über Millionen Zeilen nur
            // für die Projektzugehörigkeit ist der teuerste Teil der Abfrage.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->char('trace_id', 32);
            $table->char('span_id', 16);

            // Der Elternteil im Baum — bei den unmittelbaren Schritten einer
            // Transaktion ist das deren eigene `span_id`. Genau daran hängt die
            // Baumstruktur: ohne diese Spalte wäre die Verschachtelung („diese
            // Abfrage lief innerhalb dieses Aufrufs") verloren.
            $table->char('parent_span_id', 16)->nullable();

            $table->string('op', Transaction::OP_LIMIT)->nullable();

            // Was der Schritt getan hat — bei einer Datenbank-Abfrage das SQL.
            // `text`, weil eine Abfrage lang ist und eine gekappte Beschreibung
            // die Ursachensuche wertlos macht.
            $table->text('description')->nullable();

            $table->string('status', 32)->nullable();
            $table->timestamp('started_at', 3);
            $table->timestamp('finished_at', 3);
            $table->unsignedBigInteger('duration_us');

            // Zusatzangaben des SDK (Zeilenzahl einer Abfrage, HTTP-Status,
            // Adresse). Freier Feld-Baum aus demselben Grund wie bei den
            // Messwerten: was drinsteht, entscheidet das SDK.
            $table->json('data')->nullable();

            // Die Reihenfolge, in der die Schritte gemeldet wurden. Nötig, weil
            // gleichzeitig gestartete Schritte denselben Zeitstempel tragen und
            // die Anzeige sonst bei jedem Aufruf anders sortiert.
            $table->unsignedSmallInteger('position');

            $table->timestamps(3);

            // Ein Schritt kommt in seiner Transaktion nur einmal vor. Der Index
            // trägt zugleich das Laden der Schritte einer Transaktion.
            $table->unique(['transaction_id', 'span_id']);

            // Die Trace-Ansicht (PF4) baut den Ablauf über alle Dienste — wieder
            // ohne `project_id` vorneweg.
            $table->index(['trace_id', 'parent_span_id']);

            // „Welche Abfragen sind in diesem Projekt die langsamsten" (PF6).
            $table->index(['project_id', 'op', 'started_at']);
        });

        Schema::create('transaction_aggregates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Nicht `nullable`: in MySQL gelten zwei `NULL` in einem
            // eindeutigen Index als verschieden — die Zeile für „ohne Umgebung"
            // würde bei jeder Transaktion neu entstehen und nichts
            // zusammenfassen. Die Aufnahme setzt deshalb immer einen Namen.
            $table->string('environment', 64);

            $table->string('name', Transaction::NAME_LIMIT);

            // Aus demselben Grund nicht `nullable` wie die Umgebung: die
            // Operation steht im eindeutigen Schlüssel. Fehlt sie, ist der Wert
            // die leere Zeichenkette — ein Wert, der sich mit sich selbst
            // vergleichen lässt.
            $table->string('op', Transaction::OP_LIMIT)->default('');

            // Anfang des Zeitfensters. Die Auflösung ist eine Minute
            // ({@see Transaction::BUCKET_SECONDS}): fein genug, um einen
            // Ausschlag zu sehen, und grob genug, dass ein Tag je
            // Transaktionsname 1440 Zeilen hat statt Millionen. Längere
            // Zeiträume entstehen durch Summieren dieser Fenster.
            $table->timestamp('window_start');

            // Nicht `count`: ein Model-Feld dieses Namens steht neben der
            // gleichnamigen Abfrage-Methode, und `$aggregate->count` gegen
            // `$aggregate->count()` zu lesen ist eine Fehlerquelle, die man sich
            // mit einem klaren Namen erspart.
            $table->unsignedBigInteger('transaction_count')->default(0);

            // Wie viele davon nicht erfolgreich waren — die Fehlerrate ist
            // `failure_count / transaction_count`. Vorberechnet, weil sie sonst über die
            // Einzelzeilen gerechnet werden müsste, und genau das soll diese
            // Tabelle ersparen.
            $table->unsignedBigInteger('failure_count')->default(0);

            // Summe, Kleinstes und Größtes. Aus Summe und Anzahl entsteht der
            // Mittelwert für **jeden** Zeitraum, weil beide sich addieren
            // lassen; ein vorberechneter Mittelwert ließe sich das nicht.
            $table->unsignedBigInteger('duration_sum_us')->default(0);
            $table->unsignedBigInteger('duration_min_us')->nullable();
            $table->unsignedBigInteger('duration_max_us')->nullable();

            // Die Verteilung als Häufigkeiten über festen Klassen
            // ({@see DurationHistogram}). Perzentile sind der Grund: p95 lässt
            // sich nicht addieren, eine Verteilung schon. Ohne sie müsste jede
            // Detailseite die Einzelzeilen des Zeitraums sortieren — bei einem
            // Monat sind das Millionen.
            $table->json('duration_histogram')->nullable();

            // Ohne Bruchteile, anders als bei den Messungen: `window_start` ist
            // auf die Minute abgeschnitten und steht im eindeutigen Schlüssel.
            // Ein Wert mit Millisekunden wäre dort eine zweite Schreibweise
            // desselben Fensters — und damit eine zweite Zeile.
            $table->timestamps();

            // Ein Fenster je Name, Operation und Umgebung. Die Aufnahme zählt
            // über diesen Schlüssel hoch, statt zu lesen und zu schreiben:
            // zwei Arbeiter mit Transaktionen derselben Minute würden sich sonst
            // gegenseitig überschreiben.
            //
            // Der Name ist von Hand gesetzt: aus Tabelle und fünf Spalten
            // gebildet wäre er 73 Zeichen lang, und MySQL lässt für einen
            // Bezeichner nur 64 zu.
            $table->unique(
                ['project_id', 'environment', 'name', 'op', 'window_start'],
                'transaction_aggregates_window_unique',
            );

            // Die Übersicht liest einen Zeitraum je Projekt und sortiert nach
            // Dauer oder Anzahl.
            $table->index(['project_id', 'window_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_aggregates');
        Schema::dropIfExists('transaction_spans');
        Schema::dropIfExists('transactions');
    }
};

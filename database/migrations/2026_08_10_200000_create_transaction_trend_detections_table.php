<?php

use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die festgestellten Trendbrüche: je Transaktion und Richtung der Zeitpunkt,
     * an dem sie umgeschlagen ist.
     *
     * **Eine eigene Tabelle und kein Eintrag in `issues`.** Ein Leistungsproblem
     * (PF6) ist ein Muster in einem einzelnen Ablauf — N+1-Abfragen, eine
     * blockierende Ressource —, und der Beleg dafür ist der Ablauf selbst. Ein
     * Trendbruch ist das Gegenteil: kein einzelner Ablauf ist auffällig, die
     * **Verteilung über viele** hat sich verschoben. Er hat deshalb weder ein
     * Ereignis noch einen Fingerabdruck, dafür aber Zahlen, die ein Fehler nicht
     * hat (Vorher, Nachher, Aussagekraft) — in `issues` wären das fünf Spalten,
     * die für alles andere leer bleiben.
     *
     * **Eine Zeile je Transaktion und Richtung, nicht je Feststellung.** Der
     * Durchlauf rechnet denselben Zeitraum immer wieder durch; jeder Lauf würde
     * sonst eine weitere Zeile für denselben Bruch anlegen und die Liste mit
     * Wiederholungen füllen. Der eindeutige Schlüssel macht daraus eine
     * fortgeschriebene Feststellung: der Bruch bleibt derselbe, seine Zahlen
     * werden genauer, je mehr Messungen nach ihm liegen.
     *
     * Verbesserungen stehen daneben und nicht in einer zweiten Tabelle. Es ist
     * dieselbe Rechnung mit umgekehrtem Vorzeichen, und die Frage „ist die
     * Optimierung von letzter Woche angekommen?" ist dieselbe Frage wie „ist
     * etwas langsamer geworden?" — nur mit der Antwort, die man hören will.
     */
    public function up(): void
    {
        Schema::create('transaction_trend_detections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Wie bei der Vorberechnung nicht `nullable`: die Umgebung steht im
            // eindeutigen Schlüssel, und zwei `NULL` gelten in MySQL als
            // verschieden.
            $table->string('environment', 64);

            $table->string('name', Transaction::NAME_LIMIT);
            $table->string('op', Transaction::OP_LIMIT)->default('');

            // `worse` oder `better` ({@see App\Enums\TrendDirection}). Die
            // übrigen Fälle der Aufzählung („neu", „unverändert", „zu wenige
            // Messungen") sind Auskünfte der Übersicht und keine Feststellung —
            // sie kommen hier nicht vor.
            $table->string('direction', 10);

            // Der Anfang des ersten Fensters **nach** dem Bruch — der Zeitpunkt,
            // von dem an die neue Höhe gilt. Nicht das Ende des letzten Fensters
            // davor: beides beschreibt dieselbe Grenze, aber nur diese Angabe
            // steht auch dann richtig da, wenn dazwischen eine stille Stunde
            // ohne Messungen liegt.
            $table->timestamp('breakpoint_at');

            // Die beiden Höhen in Mikrosekunden, gerechnet aus den
            // zusammengelegten Verteilungen der jeweiligen Seite — nicht aus dem
            // Mittel der Fenster-Perzentile. Ein p95 aus allen Messungen einer
            // Seite ist die belastbarere Zahl; die Fenster-Perzentile werden für
            // den statistischen Test gebraucht, nicht für die Anzeige.
            $table->unsignedBigInteger('before_p95_us');
            $table->unsignedBigInteger('after_p95_us');

            // Wie viele Messungen hinter den beiden Höhen stehen. Sie sind der
            // Grund, warum eine Feststellung überhaupt geglaubt werden darf, und
            // gehören deshalb in die Ansicht — „von 200 ms auf 900 ms" ist ohne
            // sie eine Behauptung.
            $table->unsignedBigInteger('before_count');
            $table->unsignedBigInteger('after_count');

            // Die relative Änderung: 0,2 heißt „20 % langsamer", -0,2 „20 %
            // schneller". Abgeleitet aus den beiden Höhen und trotzdem
            // gespeichert, weil danach sortiert wird — eine Sortierung über
            // einen gerechneten Ausdruck kann keinen Index benutzen.
            $table->double('change_ratio');

            // Die Aussagekraft als z-Wert des Rangsummentests
            // ({@see App\Support\Performance\Trends\BreakpointScan}). Sie steht
            // in der Zeile, weil sie die Zusage des Auftrags belegt: kein Alarm
            // bei zu geringer statistischer Aussagekraft. Wer wissen will,
            // warum etwas gemeldet wurde, findet hier die Zahl dazu.
            $table->double('z_score');

            // Die Auslieferung, die zeitlich am besten zum Bruch passt (R3).
            // `nullable` und `nullOnDelete`: die allermeisten Brüche haben
            // keinen Deploy in Reichweite, und ein aufgeräumter Deploy soll die
            // Feststellung nicht mitreißen — sie bleibt richtig, nur ohne
            // Verdächtigen.
            $table->foreignId('deploy_id')->nullable()->constrained()->nullOnDelete();

            // Wann der Bruch zum ersten Mal festgestellt wurde, und wann er
            // hinausgegangen ist. Getrennt, weil dazwischen etwas schiefgehen
            // kann: eine Feststellung ohne Meldung ist eine Zeile, die beim
            // nächsten Durchlauf noch einmal zugestellt wird.
            $table->timestamp('detected_at');
            $table->timestamp('notified_at')->nullable();

            // „Gesehen": wer sich der Sache angenommen hat und wann. Der Nutzer
            // darf verschwinden, ohne die Feststellung zurück auf ungesehen zu
            // werfen — deshalb `nullOnDelete` und nicht `cascadeOnDelete`.
            $table->timestamp('seen_at')->nullable();
            $table->foreignId('seen_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Ein Bruch je Transaktion und Richtung. Der Name ist von Hand
            // gesetzt: aus Tabelle und fünf Spalten gebildet wäre er länger als
            // die 64 Zeichen, die MySQL für einen Bezeichner zulässt.
            $table->unique(
                ['project_id', 'environment', 'name', 'op', 'direction'],
                'transaction_trend_detections_unique',
            );

            // Die Liste: ein Zeitraum je Projekt, das Auffälligste zuerst.
            $table->index(['project_id', 'breakpoint_at'], 'transaction_trend_detections_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_trend_detections');
    }
};

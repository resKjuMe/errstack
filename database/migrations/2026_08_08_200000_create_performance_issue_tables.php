<?php

use App\Enums\IssueCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Ablage der Leistungsprobleme: die Kategorie am vorhandenen Eintrag,
     * die einzelnen Funde und die Schwellen je Projekt.
     *
     * **Kein eigener Eintragstyp.** Ein Leistungsproblem bekommt eine Zeile in
     * `issues` wie ein Fehler auch — damit gelten Zustand, Priorität,
     * Zuweisung, Zählung und Alarme sofort und unverändert. Was hinzukommt, ist
     * eine Spalte, die sagt, wonach die Ansicht trennt, und eine Spalte für die
     * Größe, die es bei Fehlern nicht gibt: die verlorene Zeit.
     *
     * **Die Funde stehen getrennt**, in `performance_detections`. Sie sind für
     * die Leistungsprobleme, was die Ereignisse für die Fehler sind: das
     * einzelne Auftreten mit seinem Beleg — welcher Ablauf, welche Schritte,
     * wie viel Zeit. Sie in `issues` zu falten hieße, entweder nur den letzten
     * Fund zu behalten oder eine Spalte mit einer Liste darin zu führen; beides
     * nimmt genau die Frage, die jemand vor der Behebung stellt: „zeig mir
     * einen Fall".
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            // Fehler oder Leistungsproblem. Der Vorgabewert macht jede
            // bestehende Zeile zu dem, was sie war — vor dieser Spalte gab es
            // nur Fehler.
            $table->string('category', 20)->default(IssueCategory::DEFAULT->value)->after('project_id');

            // Die aufsummierte verlorene Zeit in **Mikrosekunden**, dieselbe
            // Einheit wie bei den Schritten eines Ablaufs. Bei Fehlern bleibt
            // sie null: ein Fehler kostet keine messbare Zeit, er kostet das
            // Ergebnis.
            $table->unsignedBigInteger('time_lost_us')->default(0)->after('users_seen');
        });

        Schema::table('issues', function (Blueprint $table) {
            // Die Listen fragen immer innerhalb einer Kategorie — es gibt keine
            // Ansicht über beide. Deshalb steht `category` vor `status`, und
            // die vorhandenen Indizes ohne Kategorie bleiben unangetastet: sie
            // tragen weiterhin die Abfragen, die ein einzelnes Projekt über
            // alles hinweg auswerten (Aufräumen, Zeitreihen).
            $table->index(['project_id', 'category', 'status', 'last_seen'], 'issues_category_status_index');

            // Die Sortierung der Leistungsprobleme nach Wirkung — „was kostet
            // am meisten Zeit" ist dort die erste Frage, nicht die letzte.
            $table->index(['project_id', 'category', 'time_lost_us'], 'issues_category_impact_index');
        });

        Schema::create('performance_detections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Der Eintrag, unter dem dieser Fund erscheint. `nullable`, weil
            // der Fund zuerst geschrieben und dann zugeordnet wird — und weil
            // ein von Hand gelöschter Eintrag den Beleg nicht mitreißen soll.
            $table->foreignId('issue_id')->nullable()->constrained()->nullOnDelete();

            // Der Ablauf, in dem der Fund steckt. `cascadeOnDelete`: räumt das
            // Aufräumen (O2) die Transaktion weg, ist der Beleg wertlos — er
            // zeigt auf Schritte, die es nicht mehr gibt.
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();

            // Der Trace-Zusammenhang, doppelt geführt wie bei den Schritten:
            // die Detailansicht springt von hier direkt in den Ablauf, ohne die
            // Transaktion nur für ihre `trace_id` zu laden.
            $table->char('trace_id', 32);

            $table->string('problem', 40);

            // Derselbe Fingerabdruck wie am Eintrag — er sagt, welche Funde
            // dasselbe Problem sind. Steht hier mit, damit ein nachträglich
            // geänderter Zuschnitt (ein zusammengeführter Eintrag) die
            // Zuordnung des einzelnen Fundes nicht verliert.
            $table->char('fingerprint', 32);

            // Die betroffenen Schritte, als Liste ihrer `span_id`. Kein
            // Fremdschlüssel: die Schritte sind erst über
            // (`transaction_id`, `span_id`) eindeutig, und eine Zwischentabelle
            // für im Schnitt fünf Kennungen wäre eine Tabelle mehr für nichts.
            $table->json('span_ids');

            // Was den Fund ausmacht, in der Sprache des Musters: die
            // Abfrageform, die Adresse des Aufrufs, der Name der Datei.
            $table->text('description')->nullable();

            // Die Zahlen des Fundes: wie viele Schritte, wie viel Zeit davon
            // vermeidbar. Getrennt gespeichert und nicht nur am Eintrag
            // summiert, weil die Detailansicht den **schlimmsten** Fall zeigt
            // und nicht den Durchschnitt.
            $table->unsignedSmallInteger('span_count')->default(0);
            $table->unsignedBigInteger('time_lost_us')->default(0);

            // Zusatzangaben des Erkenners (gemessene Größe, Anteil der
            // Fehlgriffe, Adresse) — was die Detailansicht erklärt, ohne dass
            // jedes Muster eine eigene Spalte bekommt.
            $table->json('evidence')->nullable();

            $table->timestamp('occurred_at', 3);
            $table->timestamps();

            // Derselbe Ablauf, dasselbe Problem: einmal. Ohne diesen Index
            // würde ein zweiter Durchlauf über dieselbe Transaktion — ein
            // wiederholter Auftrag, eine erneut verarbeitete Meldung — jeden
            // Fund ein zweites Mal zählen.
            $table->unique(['transaction_id', 'fingerprint'], 'performance_detections_unique');

            // „Zeig mir Beispiele zu diesem Eintrag, das schlimmste zuerst."
            $table->index(['issue_id', 'time_lost_us'], 'performance_detections_impact_index');

            // Das Aufräumen alter Funde und die Auswertung je Projekt.
            $table->index(['project_id', 'occurred_at'], 'performance_detections_period_index');
        });

        Schema::create('performance_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('problem', 40);

            // Ein Muster lässt sich je Projekt abschalten. Nicht jede Anwendung
            // hat einen Browser-Teil, und ein Erkenner, der dort nie etwas
            // findet, ist eine Zeile in einer Liste von Einstellungen, die
            // niemand mehr liest.
            $table->boolean('is_enabled')->default(true);

            // Nur die **abweichenden** Schwellen, nicht der ganze Satz. Der
            // Unterschied zeigt sich, wenn sich ein Vorgabewert ändert: wer den
            // vollen Satz kopiert hätte, bliebe für immer auf dem alten Wert
            // stehen, obwohl er nie etwas eingestellt hat.
            $table->json('thresholds')->nullable();

            $table->timestamps();

            $table->unique(['project_id', 'problem']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            // Wann die Erkennung diesen Ablauf angesehen hat. Zwei Aufgaben:
            // sie hält den erneuten Durchlauf ab (der Auftrag darf mehrfach
            // kommen), und sie beantwortet die Frage, ob die Erkennung
            // hinterherhängt.
            $table->timestamp('scanned_at', 3)->nullable()->after('measurements');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('scanned_at');
        });

        Schema::dropIfExists('performance_settings');
        Schema::dropIfExists('performance_detections');

        Schema::table('issues', function (Blueprint $table) {
            $table->dropIndex('issues_category_impact_index');
            $table->dropIndex('issues_category_status_index');
            $table->dropColumn(['category', 'time_lost_us']);
        });
    }
};

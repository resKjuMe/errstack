<?php

use App\Models\IssueAlertRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alarm-Regeln für Fehler: die Regel, ihr Zustand je Fehler und ihr Verlauf.
     *
     * **`issue_alert_rules`** — Bedingungen, Filter und Aktionen liegen als
     * JSON in der Regel und nicht in drei Nebentabellen. Sie werden nur
     * gemeinsam gelesen, nur gemeinsam geschrieben und nie einzeln gesucht;
     * drei Tabellen wären drei Abfragen je Regel und je Ereignis — genau in dem
     * Weg, der jede eingehende Meldung sieht.
     *
     * **`issue_alert_states`** — je Regel und Fehler eine Zeile: wann zuletzt
     * gemeldet wurde. Das ist die Häufigkeitsbegrenzung, und sie ist zugleich
     * die **Abkürzung**: der Zustand wird vor der Auswertung gelesen, nicht
     * danach. Bei einem Fehlersturm — dem Fall, gegen den die Begrenzung
     * überhaupt schützt — endet die Regel damit nach einem Blick auf eine
     * indizierte Zeile, statt Ereignisse zu zählen, deren Ergebnis ohnehin
     * verworfen würde.
     *
     * **`issue_alert_triggers`** — je Auslösung eine Zeile, für den Verlauf
     * (A4) und als Beleg, dass die Begrenzung eingehalten wurde. Aus den
     * Zustellungen wäre das nicht zu holen: die sagen, was verschickt wurde,
     * nicht, was festgestellt wurde.
     *
     * **Die Begrenzung selbst ist eine bedingte Anweisung** (`update … where
     * last_notified_at is null or last_notified_at <= ?`) und keine Sperre —
     * dieselbe Wahl wie beim Zustandswechsel der Schwellwert-Alarme (A3).
     * Mehrere Arbeiter verarbeiten denselben Fehler gleichzeitig; die Anweisung
     * trifft aber nur eine Zeile, und nur wer sie trifft, meldet.
     */
    public function up(): void
    {
        Schema::create('issue_alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('name', IssueAlertRule::NAME_LIMIT);

            // Ob alle Bedingungen bzw. Filter zutreffen müssen oder eine genügt
            // (App\Enums\IssueAlertMatch).
            $table->string('condition_match', 10)->default('all');
            $table->string('filter_match', 10)->default('all');

            // Anlässe, Einschränkungen und Aktionen — jeweils eine Liste aus
            // `{"type": …}` samt den Angaben, die der Typ braucht. Der Aufbau
            // wird in App\Http\Requests\IssueAlertRuleRequest geprüft, damit
            // niemals ein Rumpf in die Auswertung gerät, den sie nicht deuten
            // kann.
            $table->json('conditions');
            $table->json('filters');
            $table->json('actions');

            // Höchstens eine Meldung je Fehler in dieser Spanne. Die Zusage der
            // Aufgabe steckt in dieser einen Zahl; ohne sie ist eine Regel auf
            // „neuer Fehler mit Grad error" bei einem Ausfall ein Postfach voll
            // derselben Nachricht.
            $table->unsignedSmallInteger('frequency_minutes')->default(30);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Ein Name je Projekt: die Meldung nennt ihn, und zwei Regeln
            // gleichen Namens wären in der Benachrichtigung nicht zu trennen.
            $table->unique(['project_id', 'name']);

            // Der Zugriff des Aufnahmewegs: alle aktiven Regeln eines Projekts.
            $table->index(['project_id', 'is_active']);
        });

        Schema::create('issue_alert_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();

            $table->timestamp('last_notified_at')->nullable();
            $table->unsignedInteger('notified_count')->default(0);

            // Der Auflösungszeitpunkt, zu dem zuletzt ein Rückfall gemeldet
            // wurde. Ohne ihn wäre ein erledigter Fehler, der weiter auftritt,
            // in jedem Zeitfenster erneut ein „Rückfall" — er bleibt erledigt,
            // weil das Wiederaufmachen zu S8 gehört und nicht hierher.
            $table->timestamp('regression_at')->nullable();

            $table->timestamps();

            // Zugleich der Zugriffsweg und das Verfahren: die Zeile wird über
            // diesen Index angelegt (`insertOrIgnore`), und der eindeutige Index
            // entscheidet, wer sie anlegt.
            $table->unique(['issue_alert_rule_id', 'issue_id']);
        });

        Schema::create('issue_alert_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();

            // Welche Bedingungen den Ausschlag gaben — als Liste, weil bei
            // „eine genügt" mehrere zugleich zutreffen können. Der Verlauf soll
            // die Rückfrage „warum hat das gefeuert?" beantworten, und dafür
            // genügt der Regelname nicht.
            $table->json('conditions');

            // Wie viele Zustellungen daraus entstanden sind. Getrennt vom
            // Verlauf der Zustellungen selbst (A1): eine Regel kann greifen und
            // trotzdem nichts verschicken, wenn kein Kanal aktiv ist — und
            // genau das ist die Lage, die man sucht.
            $table->unsignedSmallInteger('delivery_count')->default(0);

            $table->timestamp('occurred_at');

            $table->timestamps();

            // Der Verlauf einer Regel und der eines Fehlers, jüngstes zuerst.
            $table->index(['issue_alert_rule_id', 'occurred_at']);
            $table->index(['issue_id', 'occurred_at']);
        });

        // „Betrifft mehr als X Nutzer in Y Minuten" liest die Betroffenen eines
        // Fehlers in einer Zeitspanne. Der vorhandene eindeutige Index beginnt
        // mit dem Eintrag, taugt für die Spanne aber nicht — bei einem Fehler
        // mit vielen Betroffenen wäre das ein Durchlauf über alle seine Zeilen.
        //
        // „Wie oft in den letzten Y Minuten?" braucht dagegen keinen neuen
        // Index: die Ereignisse tragen ihn seit der Gruppierung (I5) unter
        // `(event_group_id, occurred_at)`.
        Schema::table('issue_users', function (Blueprint $table) {
            $table->index(['issue_id', 'first_seen']);
        });
    }

    public function down(): void
    {
        Schema::table('issue_users', function (Blueprint $table) {
            $table->dropIndex(['issue_id', 'first_seen']);
        });

        Schema::dropIfExists('issue_alert_triggers');
        Schema::dropIfExists('issue_alert_states');
        Schema::dropIfExists('issue_alert_rules');
    }
};

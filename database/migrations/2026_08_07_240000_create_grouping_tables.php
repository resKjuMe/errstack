<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Gruppierung: aus vielen gleichen Meldungen wird ein Eintrag.
     *
     * Zwei Tabellen und drei Spalten am Ereignis.
     *
     * **`event_groups`** ist die Gruppe selbst — ein Fingerabdruck je Projekt.
     * Sie ist bewusst schmal: hier steht, **dass** es diese Gruppe gibt und
     * **warum**, aber weder Zähler noch Zeitpunkte noch Zustand. Das ist der
     * Fehler-Eintrag (Issue), und der kommt mit I6. Die Trennung ist nicht
     * Ordnungsliebe, sondern die Voraussetzung für S9: dort werden mehrere
     * Fingerabdrücke von Hand zu einem Eintrag zusammengeführt und einzelne
     * wieder herausgelöst. Läge der Zähler an der Gruppe, wäre jedes
     * Zusammenführen ein Umrechnen und jedes Auftrennen ein Verlust.
     *
     * **`fingerprint_rules`** sind die projektweiten Regeln: Bedingung trifft zu
     * → dieser Fingerabdruck. Sie stehen in der Datenbank und nicht in einer
     * Konfigurationsdatei, weil sie je Projekt verschieden sind und von den
     * Leuten gepflegt werden, die die Fehlerliste ansehen — nicht von denen, die
     * die Anwendung ausliefern.
     *
     * **Am Ereignis** steht der Fingerabdruck, die Gruppe und die Begründung.
     * Der Fingerabdruck **zusätzlich** zur Gruppen-Kennung: nach dem
     * Zusammenführen in S9 zeigen mehrere Fingerabdrücke auf denselben Eintrag,
     * und ohne den Wert am Ereignis ließe sich nicht mehr sagen, welche
     * Untergruppe es war.
     */
    public function up(): void
    {
        // Die Regeln zuerst: die Gruppe verweist auf die Regel, die sie
        // hervorgebracht hat, und ein Fremdschlüssel braucht sein Ziel.
        Schema::create('fingerprint_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Wofür die Regel da ist — für die Liste, nicht für die Auswertung.
            // Ein Regelwerk ohne Namen ist nach einem halben Jahr niemandem
            // mehr zu erklären.
            $table->string('name');

            // Die Bedingungen. Alle müssen zutreffen (UND); wer ODER braucht,
            // schreibt zwei Regeln. Als JSON, weil ihre Zahl je Regel wechselt
            // und niemand nach ihnen sucht.
            $table->json('matchers');

            // Der Fingerabdruck, den die Regel setzt — eine Liste, die
            // `{{ default }}` und Feld-Platzhalter enthalten darf.
            $table->json('fingerprint');

            // Die Reihenfolge. Die erste zutreffende Regel gewinnt; ohne feste
            // Ordnung hinge das Ergebnis an der Reihenfolge der Datenbank, und
            // dieselbe Meldung könnte morgen anders gruppiert werden.
            $table->unsignedInteger('position')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Die Abfrage der Gruppierung: die aktiven Regeln eines Projekts in
            // ihrer Reihenfolge.
            $table->index(['project_id', 'is_active', 'position']);
        });

        Schema::create('event_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Der Fingerabdruck. Die Länge ist die des gekürzten SHA-256 aus
            // App\Support\Ingest\Grouping\Fingerprint.
            $table->char('fingerprint', 32);

            // Wonach gruppiert wurde: Stacktrace, Ausnahme, Meldungstext, eigene
            // Angabe des SDK, projektweite Regel. Als Spalte und nicht im JSON
            // daneben, weil danach gefiltert wird — „zeig mir alle Gruppen, die
            // nur am Meldungstext hängen" ist die Frage, mit der man ein zu
            // grobes Grouping findet.
            $table->string('source', 20);

            // Die Bestandteile, aus denen der Fingerabdruck entstand. Sie
            // beantworten am Eintrag die Frage „warum liegen die in einer
            // Gruppe?", ohne dafür ein Ereignis öffnen zu müssen.
            $table->json('components')->nullable();

            // Die Regel, die gegriffen hat. `nullOnDelete`, weil eine gelöschte
            // Regel die Gruppe nicht mitnehmen darf: die Ereignisse liegen
            // weiter darin, und die Gruppe zu entfernen hieße, sie zu verlieren.
            $table->foreignId('fingerprint_rule_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // Der Zugriff der Gruppierung: je Meldung einmal, also die
            // häufigste Abfrage dieser Tabelle überhaupt. Zugleich die Zusage,
            // auf der alles beruht — derselbe Fingerabdruck ergibt in einem
            // Projekt genau eine Gruppe, auch wenn zwei Arbeiter gleichzeitig
            // dieselbe neue Gruppe anlegen wollen.
            $table->unique(['project_id', 'fingerprint']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->char('fingerprint', 32)->nullable()->after('event_id');

            $table->foreignId('event_group_id')->nullable()->after('project_id')
                ->constrained()->nullOnDelete();

            // Die Begründung je Ereignis: Verfahren, Bestandteile, Regel. Sie
            // steht **am Ereignis** und nicht nur an der Gruppe, weil sie sich
            // ändern kann — eine neue Regel, ein verbessertes Verfahren —, und
            // dann ist die Frage nicht „wie wird heute gruppiert?", sondern
            // „warum landete diese Meldung damals dort?".
            $table->json('grouping')->nullable()->after('notes');

            // Die Ereignisse einer Gruppe, jüngste zuerst — die Liste, die
            // aufgeht, wenn jemand einen Fehler-Eintrag öffnet.
            $table->index(['event_group_id', 'occurred_at']);

            // Der Weg von einem Fingerabdruck zu seinen Ereignissen, ohne den
            // Umweg über die Gruppe. Nach dem Zusammenführen in S9 ist das der
            // einzige Weg, eine Untergruppe wieder herauszulösen.
            $table->index(['project_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'fingerprint']);
            $table->dropIndex(['event_group_id', 'occurred_at']);
            $table->dropConstrainedForeignId('event_group_id');
            $table->dropColumn(['fingerprint', 'grouping']);
        });

        // Umgekehrt zur Anlage: die Gruppe verweist auf die Regel.
        Schema::dropIfExists('event_groups');
        Schema::dropIfExists('fingerprint_rules');
    }
};

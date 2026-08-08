<?php

use App\Support\Tags\TagAggregates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Merkmal-Aggregate: welcher Browser, welche Fassung, welcher Server.
     *
     * Die Frage ist immer dieselbe — „betrifft das alle oder nur einen?" —, und
     * sie ist über die Einzelereignisse **nicht** zu beantworten. Ein Fehler mit
     * einer Million Auftreten hieße dort eine Million Zeilen lesen, gruppieren
     * und zählen, und zwar bei jedem Aufschlagen der Seite. Deshalb wird beim
     * Eingang mitgeschrieben: jedes Ereignis erhöht ein paar Zähler, und die
     * Auswertung ist danach ein Lesen von Zeilen, die schon fertig sind.
     *
     * Vier Tabellen, zwei Ebenen mal zwei Rollen:
     *
     * **`issue_tags` / `project_tags`** — je Merkmal **und Wert** ein Zähler
     * (`browser` = `Chrome 124`). Das ist die Verteilung, die jemand ansieht.
     *
     * **`issue_tag_keys` / `project_tag_keys`** — je Merkmal ein Zähler über
     * **alle** Werte. Er sieht überflüssig aus (man könnte die Werte summieren)
     * und ist der Grund, warum die Prozentangabe stimmt: die Werte sind je
     * Merkmal begrenzt ({@see TagAggregates::MAX_VALUES_PER_KEY}),
     * ihre Summe ist deshalb kleiner als die Zahl der Ereignisse, sobald ein
     * Merkmal mehr Werte hat als aufgehoben werden. Ohne eigenen Nenner käme
     * dann „100 %" heraus, obwohl die Hälfte fehlt — eine Zahl, die falsch ist
     * und richtig aussieht.
     *
     * **Warum die Projekt-Ebene nicht aus der Fehler-Ebene gerechnet wird:**
     * „welche Browser sind in diesem Projekt betroffen" wäre dort eine
     * Gruppierung über alle Zeilen aller Fehler des Projekts — genau der
     * Volltabellen-Scan, den diese Aufgabe vermeiden soll. Zwei Zähler zu
     * schreiben ist billiger als einen zu summieren.
     *
     * Alle Zähler werden sperrfrei fortgeschrieben (`times_seen = times_seen + 1`),
     * wie bei den Fehler-Zählern (I6): bei einem Ausfall trifft dasselbe Merkmal
     * jede gleichzeitig verarbeitete Meldung, und eine Sperre auf dieser Zeile
     * wäre der Engpass, an dem die Aufnahme stehen bleibt.
     *
     * Die Spalten heißen `tag_key` und `tag_value` und nicht `key`/`value`:
     * `key` ist in MySQL ein reserviertes Wort, und die Zähl-Anweisungen sind
     * bewusst rohes SQL.
     */
    public function up(): void
    {
        Schema::create('issue_tag_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();

            // Das Projekt steht redundant daneben: das Aufräumen (O2) und jede
            // projektweite Prüfung fragen danach, und der Weg über den Eintrag
            // wäre je Zeile eine Verknüpfung.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('tag_key', 200);

            // Wie viele Ereignisse dieses Merkmal überhaupt getragen haben —
            // der Nenner jeder Prozentangabe.
            $table->unsignedBigInteger('times_seen')->default(0);

            // Wie viele verschiedene Werte aufgehoben werden. Er ist die
            // Obergrenzen-Prüfung selbst: ohne ihn wäre bei jedem neuen Wert
            // ein `count(*)` über die Werte-Tabelle fällig, und zwar im
            // Eingangsweg.
            $table->unsignedInteger('value_count')->default(0);

            $table->timestamps();

            // Je Eintrag und Merkmal genau eine Zeile — die Zusage, auf der das
            // sperrfreie Fortschreiben beruht.
            $table->unique(['issue_id', 'tag_key']);
        });

        Schema::create('issue_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('tag_key', 200);

            // Dieselbe Länge wie ein Textfeld der Normalisierung sie hat, damit
            // ein Wert hier nicht anders abgeschnitten wird als in der Meldung.
            $table->string('tag_value', 400);

            $table->unsignedBigInteger('times_seen')->default(0);

            // Erstes und letztes Auftreten **dieses Wertes** — die Antwort auf
            // „seit welcher Fassung?" und „ist das noch aktuell?".
            $table->timestamp('first_seen');
            $table->timestamp('last_seen');

            $table->timestamps();

            $table->unique(['issue_id', 'tag_key', 'tag_value']);

            // Die Verteilung eines Merkmals, häufigster Wert zuerst — die
            // Abfrage der Detailseite.
            $table->index(['issue_id', 'tag_key', 'times_seen']);

            // Der Filter der Fehlerliste („nur Chrome"): er sucht die Einträge
            // zu einem Wert und muss dafür bei den Werten anfangen, nicht bei
            // den Einträgen.
            $table->index(['project_id', 'tag_key', 'tag_value']);
        });

        Schema::create('project_tag_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('tag_key', 200);

            $table->unsignedBigInteger('times_seen')->default(0);
            $table->unsignedInteger('value_count')->default(0);

            $table->timestamps();

            $table->unique(['project_id', 'tag_key']);
        });

        Schema::create('project_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('tag_key', 200);
            $table->string('tag_value', 400);

            $table->unsignedBigInteger('times_seen')->default(0);

            $table->timestamp('first_seen');
            $table->timestamp('last_seen');

            $table->timestamps();

            $table->unique(['project_id', 'tag_key', 'tag_value']);

            // Die projektweite Übersicht: je Merkmal die häufigsten Werte.
            $table->index(['project_id', 'tag_key', 'times_seen']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_tags');
        Schema::dropIfExists('project_tag_keys');
        Schema::dropIfExists('issue_tags');
        Schema::dropIfExists('issue_tag_keys');
    }
};

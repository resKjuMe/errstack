<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die ausgelieferte Version (Release) als eigener Gegenstand — und die
     * Verbindung vom Fehler-Eintrag zu ihr.
     *
     * Die Versionsangabe steht bereits an jedem Ereignis (`events.release`).
     * Damit ließe sich „in welcher Version trat das zuerst auf?" auch
     * beantworten — mit einem `min(occurred_at)` über alle Meldungen eines
     * Eintrags, also einem vollen Durchlauf über die größte Tabelle dieser
     * Anwendung, bei jedem Aufschlagen einer Fehlerseite. Dieselbe Überlegung
     * wie bei den Zählern in I6: gerechnet wird beim Aufnehmen, gelesen wird
     * danach nur noch.
     *
     * Zwei Dinge entstehen:
     *
     * **`releases`** — je Projekt und Versionsangabe eine Zeile, mit erstem und
     * letztem Auftreten und den Zeitpunkten, die von außen kommen (angelegt,
     * ausgeliefert). Sie entsteht von selbst aus den Meldungen und lässt sich
     * zusätzlich über die Schnittstelle anlegen: wer eine Version ankündigt,
     * bevor die erste Meldung daraus eintrifft, hat sie schon in der Liste.
     *
     * **Die Verweise am Eintrag** (`issues.first_release_id`,
     * `issues.last_release_id`) — „zuerst gesehen in" und „zuletzt aufgetreten
     * in". Sie sind der Grund für die ganze Tabelle: die Frage „war das schon
     * vor dem Deploy so?" ist die, mit der nach einer Auslieferung als Erstes
     * jemand kommt.
     */
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Dieselbe Länge wie `events.release`: die Angabe stammt von dort,
            // und eine Version, die beim Übertragen abgeschnitten würde, wäre
            // hier eine andere als in der Meldung, aus der sie kam.
            $table->string('version', 200);

            // Was von außen dazukommt und aus keiner Meldung hervorgeht: der
            // Stand im Repository (R2 verknüpft darüber die Commits) und ein
            // Link auf die Auslieferung. Beide bleiben leer, wenn die Version
            // nur aus Ereignissen entstanden ist.
            $table->string('ref', 250)->nullable();
            $table->string('url', 500)->nullable();

            // Die semantische Sortierung, zerlegt und daneben gelegt. Die
            // Versionsangabe selbst bleibt Zeichenkette — sie ist das, was das
            // SDK geschickt hat, und wird nicht umgeschrieben. Aber als Text
            // sortiert steht „1.10.0" vor „1.9.0", und damit ist jede Liste
            // „neueste zuerst" falsch.
            //
            // Alle vier sind `nullable`, und das ist kein Randfall: eine Version
            // darf ein Commit-Hash sein, ein Datum, ein Zählerstand aus der
            // Bauumgebung. Was sich nicht zerlegen lässt, hat keine Ordnung
            // gegenüber `1.2.3` — für diese Zeilen entscheidet die Zeit
            // (`first_event_at`), und der Nullwert sagt genau das, statt eine
            // Ordnung zu erfinden.
            $table->unsignedBigInteger('sort_major')->nullable();
            $table->unsignedBigInteger('sort_minor')->nullable();
            $table->unsignedBigInteger('sort_patch')->nullable();

            // Der Vorabteil („1.2.3-rc.1"). `null` heißt **nicht** „unbekannt",
            // sondern „endgültige Fassung" — und die steht nach der Semantik
            // von SemVer hinter allen ihren Vorabversionen. Die Sortierung
            // fragt deshalb zuerst, ob die Spalte leer ist, und vergleicht erst
            // danach den Text.
            $table->string('sort_prerelease', 100)->nullable();

            // Wann die Version ausgeliefert wurde. Von außen gesetzt, denn aus
            // Meldungen geht sie nicht hervor: die erste Meldung sagt, wann der
            // erste Fehler auftrat, nicht wann jemand ausgeliefert hat.
            $table->timestamp('released_at')->nullable();

            // Erstes und letztes Auftreten nach der Uhr der überwachten
            // Anwendung — wie am Fehler-Eintrag und aus demselben Grund
            // (`events.occurred_at`, nicht unsere Empfangszeit).
            //
            // Beide `nullable`: eine über die Schnittstelle angekündigte Version
            // hat noch kein einziges Ereignis, und „noch nichts gesehen" ist
            // etwas anderes als „am 1.1.1970 gesehen".
            $table->timestamp('first_event_at')->nullable();
            $table->timestamp('last_event_at')->nullable();

            $table->timestamps();

            // Je Projekt eine Zeile je Versionsangabe. Der Index ist hier nicht
            // Ordnung, sondern das Verfahren selbst: er entscheidet, wer die
            // Version anlegt, wenn zwei Arbeiter im selben Augenblick die erste
            // Meldung daraus verarbeiten.
            $table->unique(['project_id', 'version']);

            // Die Versionsliste, wie sie aufgeschlagen wird: zuletzt gesehene
            // zuerst.
            $table->index(['project_id', 'last_event_at']);

            // Dieselbe Liste in semantischer Ordnung.
            $table->index(['project_id', 'sort_major', 'sort_minor', 'sort_patch']);
        });

        Schema::table('issues', function (Blueprint $table) {
            // „Zuerst gesehen in" und „zuletzt aufgetreten in".
            //
            // `nullOnDelete` und nicht `cascade`: wer eine Version wegräumt,
            // löscht damit keine Fehler. Der Eintrag verliert dann seine Angabe
            // und behält alles andere.
            $table->foreignId('first_release_id')->nullable()->after('last_seen')
                ->constrained('releases')->nullOnDelete();

            // Wann die Version an diesem Eintrag zuerst bzw. zuletzt gesehen
            // wurde. Auf den ersten Blick doppelt zu `first_seen`/`last_seen` —
            // ist es aber nicht: die beiden stehen für **alle** Meldungen des
            // Eintrags, diese hier nur für die mit Versionsangabe. Ein SDK, das
            // die Version erst seit gestern mitschickt, hat einen Eintrag, der
            // seit Wochen läuft und dessen erste **bekannte** Version von
            // gestern ist.
            //
            // Der zweite Grund ist die Fortschreibung: Meldungen kommen nicht
            // in ihrer zeitlichen Reihenfolge an. Ohne eigenen Zeitstempel
            // ließe sich nicht entscheiden, ob eine gerade eingetroffene
            // Meldung die Angabe ersetzen darf oder eine nachgereichte alte ist.
            $table->timestamp('first_release_at')->nullable()->after('first_release_id');

            $table->foreignId('last_release_id')->nullable()->after('first_release_at')
                ->constrained('releases')->nullOnDelete();
            $table->timestamp('last_release_at')->nullable()->after('last_release_id');

            // „Welche Fehler sind in dieser Version neu aufgetaucht, und wie
            // viele davon sind erledigt?" — die beiden Zahlen der Versionsliste,
            // je Version eine Abfrage auf diesem Index statt eines Durchlaufs
            // über alle Einträge des Projekts.
            $table->index(['first_release_id', 'status']);

            // „Was tritt in dieser Version noch auf?"
            $table->index('last_release_id');
        });
    }

    public function down(): void
    {
        // Erst die Schlüssel, dann die Indizes, dann die Spalten — dieselbe
        // Reihenfolge wie bei den Fehler-Einträgen (I6) und aus demselben
        // Grund: MySQL lässt den Index nicht fallen, solange der Fremdschlüssel
        // ihn braucht, SQLite die Spalte nicht, solange ein Index auf ihr liegt.
        Schema::table('issues', function (Blueprint $table) {
            $table->dropForeign(['first_release_id']);
            $table->dropForeign(['last_release_id']);
            $table->dropIndex(['first_release_id', 'status']);
            $table->dropIndex(['last_release_id']);
            $table->dropColumn([
                'first_release_id',
                'first_release_at',
                'last_release_id',
                'last_release_at',
            ]);
        });

        Schema::dropIfExists('releases');
    }
};

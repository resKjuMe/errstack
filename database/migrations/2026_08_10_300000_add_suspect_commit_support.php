<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Was der verdächtige Commit (R4) über die Angaben aus R2 hinaus braucht.
     *
     * Zwei Spalten, und beide beantworten je eine Frage, die der Abgleich
     * stellt.
     *
     * **Die geänderten Zeilen.** R2 merkt sich, *welche* Dateien ein Commit
     * angefasst hat, nicht *wo*. Für „diese Datei kommt im Stacktrace vor"
     * genügt das — und es genügt schlecht: eine Datei mit tausend Zeilen wird
     * in einer Auslieferung von einem halben Dutzend Commits angefasst, und
     * alle sechs wären gleich verdächtig. Der Stacktrace nennt die Zeile; erst
     * mit den geänderten Bereichen daneben lässt sich sagen, welcher der sechs
     * *diese* Zeile angefasst hat.
     *
     * Als Liste von Bereichen und nicht als eigene Tabelle: die Bereiche
     * entstehen mit ihrer Datei, ändern sich danach nicht mehr und werden immer
     * zusammen mit ihr gelesen — der Abgleich hat die Zeile längst über den
     * Pfad eingegrenzt, und was dann bleibt, sind ein paar Bereiche je Datei.
     * Eine eigene Tabelle wäre ein Verbund für eine Frage, die im Speicher aus
     * drei Vergleichen besteht.
     *
     * `null` heißt „nicht bekannt" und ist der Regelfall bei sentry-cli: dessen
     * `patch_set` nennt nur Pfad und Art der Änderung. Das ist ausdrücklich kein
     * Fehler — ohne Bereiche zählt der Treffer über den Pfad, nur eben
     * schwächer. Eine **leere** Liste ist dagegen eine Angabe: „an dieser Datei
     * wurde keine Zeile geändert" (eine reine Umbenennung).
     *
     * **Der Schalter je Projekt.** Anzeigen ist harmlos, Zuweisen nicht: eine
     * automatische Zuständigkeit schreibt an einem fremden Eintrag und schickt
     * eine Benachrichtigung. Wer das will, sagt es je Projekt — und der
     * Vorgabewert ist deshalb `false`.
     */
    public function up(): void
    {
        Schema::table('commit_files', function (Blueprint $table) {
            // Liste von Paaren `[[12, 40], [88, 88]]` — Zeilennummern der
            // **neuen** Fassung, denn der Stacktrace stammt aus dem
            // ausgelieferten Stand und nicht aus dem davor.
            $table->json('line_ranges')->nullable()->after('change_type');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('auto_assign_suspect_commits')->default(false)->after('resolution_behavior');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('auto_assign_suspect_commits');
        });

        Schema::table('commit_files', function (Blueprint $table) {
            $table->dropColumn('line_ranges');
        });
    }
};

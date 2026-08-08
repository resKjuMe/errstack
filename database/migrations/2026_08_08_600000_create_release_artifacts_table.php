<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Bauartefakte einer Auslieferung: ausgeliefertes Bundle und Quellkarte.
     *
     * Sie sind der Schlüssel zwischen dem, was im Browser lief, und dem, was
     * jemand geschrieben hat. Ohne sie zeigt ein JavaScript-Stacktrace `a.b.c`
     * in Zeile 1 — mit ihnen Datei, Zeile und Funktionsname.
     *
     * **Der Inhalt steht nicht in dieser Tabelle.** Eine Quellkarte mit
     * eingebettetem Quelltext ist mehrere Megabyte groß und wird nur als Ganzes
     * gelesen; sie liegt auf einem Laufwerk (`config/sourcemaps.php`), hier
     * steht der Verweis darauf. Der Pfad ist der Prüfsummenpfad: zwei Uploads
     * derselben Datei belegen einmal Platz, und das ist bei einer
     * Auslieferungs-Pipeline, die nach einem Fehlschlag noch einmal läuft, der
     * Regelfall.
     *
     * **Zwei Wege führen zum Artefakt, und die Tabelle trägt beide.** Der ältere
     * ist der Pfad: das SDK meldet `https://example.com/static/js/app.js`, und
     * gesucht wird das Artefakt `~/static/js/app.js` derselben Version. Der
     * neuere ist die Debug-Kennung — eine Nummer, die der Bauvorgang in Bundle
     * **und** Quellkarte schreibt und die das SDK mitmeldet. Sie ist der
     * verlässlichere Weg, weil sie ohne Adressen auskommt: ein Bundle hinter
     * einem Auslieferungsnetz mit wechselnden Adressen ist über den Pfad nicht
     * mehr zu finden, über die Kennung schon.
     */
    public function up(): void
    {
        Schema::create('release_artifacts', function (Blueprint $table) {
            $table->id();

            // Beide Zugehörigkeiten stehen da, obwohl die Version das Projekt
            // schon kennt: die Suche über die Debug-Kennung fragt **nicht** nach
            // einer Version — die Kennung ist für sich eindeutig, und welche
            // Auslieferung sie trug, ist dabei gleichgültig. Ohne die Spalte
            // wäre das ein Verbund über `releases` bei jedem einzelnen Rahmen
            // eines Stacktraces.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('release_id')->constrained('releases')->cascadeOnDelete();

            // Der Artefakt-Pfad, wie ihn der Bauvorgang meldet:
            // `~/static/js/app.js`. Die Tilde steht für „irgendeine Adresse
            // dieser Anwendung" und ist das, was den Pfad überhaupt
            // wiederfindbar macht — dieselbe Datei kommt unter `https://…` und
            // `https://cdn…` daher, der Pfad dahinter bleibt derselbe.
            //
            // 500 Zeichen: das ist die Länge einer Adresse mit Prüfsumme im
            // Dateinamen und mehreren Ordnerebenen davor.
            $table->string('name', 500);

            // Bundle oder Quellkarte. Die Unterscheidung fällt beim Hochladen
            // (der Inhalt sagt es: eine Quellkarte ist ein JSON mit
            // `mappings`), und sie steht hier, weil die Auflösung sonst jede
            // Datei einer Version öffnen müsste, um die Karte zu finden.
            $table->string('kind', 20);

            // Die Debug-Kennung, wenn der Bauvorgang eine geschrieben hat.
            // `nullable`, weil der Pfad-Weg ohne sie funktioniert und ältere
            // Bauketten keine erzeugen.
            $table->uuid('debug_id')->nullable();

            // Wohin die Quellkarte zeigt, wenn dies ein Bundle ist: der Wert
            // aus `//# sourceMappingURL=` bzw. dem `Sourcemap`-Kopfzeilenfeld
            // des Uploads. Einmal beim Hochladen gelesen und hier vermerkt,
            // damit die Rückübersetzung nicht bei jedem Rahmen ein Bundle von
            // der Platte holen muss, nur um die letzte Zeile zu lesen.
            $table->string('source_map_ref', 500)->nullable();

            $table->unsignedBigInteger('size');

            // `sha1` des Inhalts: er ist der Ablagepfad und zugleich die
            // Antwort auf „ist das dieselbe Datei wie beim letzten Lauf?".
            $table->char('checksum', 40);

            $table->string('path', 300);

            $table->timestamps();

            // Je Version ein Pfad. Ein zweiter Upload derselben Datei ersetzt
            // die Zeile statt eine zweite anzulegen — eine Pipeline, die noch
            // einmal läuft, soll die Version nicht verdoppeln.
            $table->unique(['release_id', 'name']);

            // Der Weg über die Debug-Kennung, projektweit und ohne Version.
            $table->index(['project_id', 'debug_id']);

            // „Welche Quellkarten hat diese Version?" — die Frage der
            // Auflösung, wenn der Pfad-Weg auf ein Bundle ohne Verweis trifft.
            $table->index(['release_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_artifacts');
    }
};

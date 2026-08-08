<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gespeicherte Suchen — und die Suche, mit der ein Projekt aufgeht.
     *
     * **Was drinsteht, ist bewusst wenig: Suchtext und Sortierung.** Nicht der
     * Zeitraum, nicht die Projektauswahl, nicht die Umgebung. Die drei gehören
     * der globalen Filterleiste (F7), und sie bleiben deshalb dort. Eine
     * gespeicherte Suche „Kritische offene Fehler", die beim Aufrufen den
     * Zeitraum auf „letzte 24 Stunden" zurückstellt, beantwortet nicht mehr die
     * Frage, die man gerade untersucht — sie reißt einen aus ihr heraus. Der
     * Suchtext sagt, **welche** Fehler gemeint sind; die Leiste sagt, **wo und
     * wann** gesucht wird. Beides in einen Topf zu werfen hieße, dass jede
     * gespeicherte Suche zwei Fragen auf einmal beantwortet und keine davon
     * verlässlich.
     *
     * **`saved_search_defaults` ist eine eigene Tabelle und keine Spalte.** Die
     * naheliegende Fassung wäre ein `default_project_id` an der Suche gewesen.
     * Sie wäre falsch, sobald eine Suche freigegeben ist: „Kritische offene
     * Fehler" gehört dann einem, benutzt wird sie von allen — und wessen
     * Standard sie ist, ist eine Aussage über den **Betrachter**, nicht über die
     * Suche. Mit einer Spalte an der Suche könnte der Ersteller den Einstieg
     * aller anderen umstellen, und niemand sonst könnte eine fremde Suche zu
     * seinem Standard machen. Der eindeutige Schlüssel (Konto, Projekt) sagt
     * genau das Richtige: je Person und Projekt genau ein Einstieg.
     */
    public function up(): void
    {
        Schema::create('saved_searches', function (Blueprint $table) {
            $table->id();

            // Die Organisation ist der Raum, in dem eine Freigabe gilt. Sie
            // steht neben dem Konto und nicht nur hinter ihm: die Liste der
            // freigegebenen Suchen wird bei jedem Aufruf der Fehlerliste
            // gelesen, und sie soll dafür nicht über die Mitgliedschaften
            // gehen.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Der Ersteller. Anders als beim Kommentar geht die Suche mit ihm:
            // ändern darf sie ohnehin nur er, und eine freigegebene Suche ohne
            // Eigentümer wäre ein Eintrag, den niemand mehr korrigieren und
            // niemand mehr loswerden kann.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // Der Suchausdruck, wie er im Suchfeld steht — unverändert. Nicht
            // die zerlegte Fassung: die Sprache (S4) kann sich erweitern, und
            // ein gespeicherter Ausdrucksbaum wäre die Fassung von heute in der
            // Datenbank von morgen.
            //
            // Er darf leer sein. „Alle Fehler, nach Häufigkeit" ist eine
            // legitime Ansicht, und sie besteht nur aus einer Sortierung.
            $table->string('query', 500)->default('');

            // Die Sortierung als Wort und nicht als Zahl: die Werte stehen in
            // App\Enums\IssueSort, und eine Zahl in der Tabelle wäre in einem
            // Jahr eine Zahl ohne Bedeutung.
            $table->string('sort', 32);

            // Freigegeben heißt: die ganze Organisation sieht sie. Ändern darf
            // sie trotzdem nur der Ersteller — das steht in
            // App\Policies\SavedSearchPolicy und nicht hier.
            $table->boolean('shared')->default(false);

            $table->timestamps();

            // Die Auswahlliste fragt nach den freigegebenen Suchen dieser
            // Organisation. Der Weg zu den eigenen läuft über den eindeutigen
            // Schlüssel unten — ein zweiter Index auf (organization_id,
            // user_id) wäre dessen linke Hälfte noch einmal.
            $table->index(['organization_id', 'shared']);

            // Zwei gleichnamige Suchen desselben Kontos sind keine zwei Suchen,
            // sondern ein Versehen: in der Auswahlliste stünde zweimal dasselbe
            // Wort, und welche der beiden gerade wirkt, wäre nicht zu sehen.
            //
            // Der Schlüssel steht **je Organisation**, nicht je Konto: die
            // Auswahlliste einer Organisation zeigt nur deren Suchen, und wer
            // in zweien arbeitet, darf in beiden eine Ansicht „Offen" haben.
            // Über Konten hinweg gilt die Eindeutigkeit ohnehin nicht — dass
            // zwei Leute ihre Ansicht „Meine Fehler" nennen, ist der Normalfall.
            $table->unique(['organization_id', 'user_id', 'name']);
        });

        Schema::create('saved_search_defaults', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Verschwindet die Suche, verschwindet der Standard mit ihr — und
            // das Projekt geht wieder mit der gewöhnlichen Fehlerliste auf. Die
            // Alternative wäre ein Verweis ins Leere, den erst der nächste
            // Aufruf bemerkt.
            $table->foreignId('saved_search_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            // Je Person und Projekt ein Einstieg. Ohne diesen Schlüssel wäre
            // „mein Standard für den Webshop" eine Menge, und welche Suche
            // gewinnt, entschiede die Reihenfolge der Zeilen.
            $table->unique(['user_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_search_defaults');
        Schema::dropIfExists('saved_searches');
    }
};

<?php

use App\Http\Controllers\SavedQueryController;
use App\Support\Dashboards\WidgetQuery;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gespeicherte Auswertungen: eine freie Auswertung unter einem Namen.
     *
     * **Der Zeitraum steht hier drin — anders als bei der gespeicherten Suche.**
     * Das ist kein Widerspruch, sondern der Unterschied zwischen den beiden
     * Dingen. Eine gespeicherte Suche sagt, **welche** Fehler gemeint sind, und
     * überlässt der Filterleiste, **wann** gesucht wird; „Kritische offene
     * Fehler" ist dieselbe Ansicht über 24 Stunden wie über 30 Tage. Eine
     * Auswertung dagegen ist eine Frage samt ihrem Ausschnitt: „Fehler nach
     * Browser" über eine Stunde und über 90 Tage sind zwei verschiedene
     * Antworten, und wer die eine festhält, meint sie und nicht die andere. Wird
     * sie geöffnet, steht der Zeitraum deshalb da — und lässt sich an der Leiste
     * sofort umstellen, wie jeder andere auch.
     *
     * **Zwei Spalten und nicht eine.** `query` ist die Frage (Quelle,
     * Gruppierung, Kennzahlen, Suchbedingung, Sortierung, Zeilenzahl,
     * Schrittweite), `filters` der Ausschnitt (Zeitraum, Umgebung, Projekt). Sie
     * getrennt zu halten ist der Grund, warum sich eine gespeicherte Auswertung
     * ohne Übersetzung als Kachel übernehmen lässt: die Kachel bekommt die
     * Frage, und den Ausschnitt gibt ihr das Dashboard
     * ({@see SavedQueryController::widget()}). In einem Topf
     * wäre beim Übernehmen erst wieder auseinanderzusortieren, was hier ohne Not
     * zusammengeschrieben wurde.
     *
     * **`query` ist derselbe Inhalt wie an einer Kachel** — dieselben sieben
     * Angaben in derselben Schreibweise ({@see WidgetQuery}).
     * Ein eigenes Format hier hieße, dass „was ist eine Abfrage" an drei Stellen
     * beantwortet wird: in der Adresszeile der Auswertung, an der Kachel und
     * hier.
     */
    public function up(): void
    {
        Schema::create('saved_queries', function (Blueprint $table) {
            $table->id();

            // Die Organisation ist der Raum, in dem eine Freigabe gilt — wie bei
            // der gespeicherten Suche und beim Dashboard.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Der Ersteller. Ändern darf sie ohnehin nur er; eine freigegebene
            // Auswertung ohne Eigentümer wäre ein Eintrag, den niemand mehr
            // korrigieren und niemand mehr loswerden kann.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // Wozu die Auswertung da ist. Sie darf leer bleiben: ein Name wie
            // „Fehler nach Browser" erklärt sich selbst, „Q3-Vergleich" nicht —
            // und nur im zweiten Fall braucht es einen Satz dazu.
            $table->string('description', 500)->default('');

            // Die Frage. Als JSON und nicht in sieben Spalten: die Menge der
            // Angaben gehört dem Motor, und eine neue davon wäre sonst eine
            // Wanderung durch die Tabelle statt eine Zeile im Leser.
            $table->json('query');

            // Der Ausschnitt: Zeitraum, Umgebung, Projekt. Darf fehlen — dann
            // geht die Auswertung mit der Leiste auf, so wie sie gerade steht.
            $table->json('filters')->nullable();

            // Freigegeben heißt: die ganze Organisation sieht sie. Ändern darf
            // sie trotzdem nur der Ersteller — das steht in
            // App\Policies\SavedQueryPolicy und nicht hier.
            $table->boolean('shared')->default(false);

            $table->timestamps();

            // Die Leiste über der Auswertung fragt nach den freigegebenen
            // Auswertungen dieser Organisation. Der Weg zu den eigenen läuft
            // über den eindeutigen Schlüssel unten — ein zweiter Index auf
            // (organization_id, user_id) wäre dessen linke Hälfte noch einmal.
            $table->index(['organization_id', 'shared']);

            // Zwei gleichnamige Auswertungen desselben Kontos sind keine zwei
            // Auswertungen, sondern ein Versehen. Der Schlüssel steht je
            // Organisation: wer in zweien arbeitet, darf in beiden eine
            // „Fehler nach Browser" haben.
            $table->unique(['organization_id', 'user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_queries');
    }
};

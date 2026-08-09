<?php

use App\Http\Requests\GlobalFilterRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Der zuletzt benutzte Stand der globalen Filterleiste — je Konto und
     * Organisation einer.
     *
     * **Warum überhaupt eine Tabelle?** Der Filterzustand lebt in der
     * Adresszeile, und das bleibt so: nur dort ist er teilbar, und nur dort
     * funktionieren Vor und Zurück. Was der Adresszeile fehlt, ist das
     * Gedächtnis über den Aufruf hinaus — wer die Fehlerliste ohne Parameter
     * öffnet, bekäme sonst jedes Mal die Voreinstellung, obwohl er seit Tagen
     * dieselben zwei Projekte ansieht. Diese Tabelle beantwortet genau die eine
     * Frage: **womit ging es zuletzt weiter?** Eine Adresse mit ausdrücklichen
     * Parametern hat immer Vorrang ({@see GlobalFilterRequest}).
     *
     * **Warum nicht die Sitzung?** Weil der gemerkte Stand die Abmeldung
     * überleben soll. Eine Sitzung endet mit ihr, und der nächste Anmeldevorgang
     * fände wieder die Voreinstellung vor.
     *
     * **Warum je Organisation und nicht je Konto?** Projekte gehören einer
     * Organisation. Ein gemerkter Stand über alle hinweg trüge die Projekte der
     * einen in die andere — sie fielen dort beim Auflösen heraus, und der
     * Wechsel zurück hätte die Auswahl verloren. Der eindeutige Schlüssel
     * (Konto, Organisation) sagt genau das Richtige, und der Wechsel der
     * Organisation setzt die Projektauswahl damit von selbst zurück.
     *
     * **Warum Spalten und kein JSON-Klumpen?** Es sind drei Filter, sie sind
     * seit F7 festgelegt, und sie einzeln zu benennen macht in einem Jahr
     * lesbar, was gemerkt wird. Ein `filter`-Feld voller JSON wäre dieselbe
     * Information ohne diese Aussage — und ohne die Möglichkeit, je nachzusehen,
     * wer eigentlich auf welche Umgebung schaut.
     */
    public function up(): void
    {
        Schema::create('filter_preferences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Die gewählten Projekte als Kürzel, nicht als Fremdschlüssel: die
            // Adresszeile führt sie ebenso, und ein gelöschtes Projekt soll den
            // gemerkten Stand nicht mitreißen, sondern beim Auflösen still
            // herausfallen ({@see App\Support\Filters\GlobalFilter::resolve}).
            //
            // Die leere Liste ist der Normalfall und heißt „alle Projekte".
            $table->json('projects');

            // `null` heißt „alle Umgebungen" — dieselbe Bedeutung wie in der
            // Leiste, wo das Feld dann leer steht.
            $table->string('environment')->nullable();

            // Der Zeitraum als Wort (App\Enums\FilterPeriod), nicht als Anzahl
            // Stunden: „letzte 7 Tage" soll auch dann noch sieben Tage sein,
            // wenn sich die Auswahlliste einmal ändert.
            $table->string('period', 32);

            // Nur beim eigenen Zeitraum belegt — daher der Namensteil. Für die
            // relativen Zeiträume wären es die aufgelösten Grenzen von gestern;
            // gemerkt gehört die Auswahl, nicht ihr Ergebnis.
            $table->date('custom_from')->nullable();
            $table->date('custom_to')->nullable();

            $table->timestamps();

            // Je Konto und Organisation genau ein Stand. Ohne den Schlüssel
            // wäre „zuletzt benutzt" eine Menge, und welcher Eintrag gewinnt,
            // entschiede die Reihenfolge der Zeilen.
            $table->unique(['user_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filter_preferences');
    }
};

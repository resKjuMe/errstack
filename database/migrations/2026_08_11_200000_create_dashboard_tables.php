<?php

use App\Support\Dashboards\WidgetQuery;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dashboards und ihre Kacheln.
     *
     * **Eine Kachel speichert ihre Abfrage, nicht ihre Daten.** Das ist die
     * Zusage dieser Tabelle und nicht nur eine Umsetzungsentscheidung: was ein
     * Dashboard zeigt, wird bei jedem Aufschlagen neu gerechnet (D1). Läge dort
     * ein Ergebnis, wäre ein Dashboard eine Sammlung von Momentaufnahmen mit
     * unbekanntem Alter — und die erste Frage vor jeder Zahl wäre „von wann ist
     * das". Der Preis ist die Abfrage je Aufschlag; sie ist im Motor begrenzt
     * und zwischengespeichert, und die Kacheln fragen nebeneinander.
     *
     * **Die Abfrage steht als JSON und nicht in Spalten.** Sie ist genau das,
     * was in der Adresszeile der freien Auswertung steht — Quelle, Gruppierung,
     * Kennzahlen, Suchbedingung, Sortierung, Zeilenzahl, Schrittweite. Eine
     * Spalte je Bestandteil wäre eine zweite Beschreibung derselben Sache: sie
     * müsste bei jeder Erweiterung der Auswertung nachgezogen werden, und
     * zwischen den beiden Fassungen entstünde die Frage, welche gilt. Gelesen
     * wird das JSON durch dieselbe Prüfung, durch die auch die Adresszeile geht
     * ({@see WidgetQuery}) — ein Feld, das der Motor
     * inzwischen nicht mehr kennt, fällt dort heraus, statt die Kachel
     * unlesbar zu machen.
     *
     * **Die Lage der Kachel steht in Spalten und nicht im JSON.** Nach ihr wird
     * sortiert (die Kacheln kommen von links oben nach rechts unten), und beim
     * Verschieben werden genau diese vier Zahlen geschrieben. Beides wäre über
     * einem JSON-Feld umständlich und über allen Datenbanken unterschiedlich.
     *
     * **Der Zeitraum gehört der Filterleiste — mit einer benannten Ausnahme.**
     * Wie bei den gespeicherten Suchen (S6) trägt ein Dashboard keinen eigenen
     * Zeitraum: die Leiste sagt, *wann*, die Kachel sagt, *was*. Die Ausnahme
     * ist ausdrücklich gewollt und steht deshalb an der Kachel und nicht am
     * Dashboard: eine Kachel darf den Zeitraum, die Umgebung und das Projekt
     * für sich überschreiben (`overrides`). Ohne sie ließe sich „letzte Stunde
     * neben letzten 30 Tagen" nicht auf einen Bildschirm bringen — und das ist
     * der halbe Zweck eines Dashboards. Steht dort nichts, gilt die Leiste.
     */
    public function up(): void
    {
        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();

            // Ein Dashboard gehört einer Organisation — in ihr gilt die
            // Freigabe, und mit ihr verschwindet es.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Der Ersteller. Ändern und löschen darf nur er; die Freigabe macht
            // ein Dashboard sichtbar, nicht herrenlos.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // Ein Satz darunter, was die Sammlung beantworten soll. Optional:
            // die meisten Dashboards erklären sich über ihre Kacheln.
            $table->string('description', 500)->default('');

            // Freigegeben heißt: die ganze Organisation sieht es. Ändern darf
            // es weiterhin nur der Ersteller.
            $table->boolean('shared')->default(false);

            // Aus welcher Vorlage es entstanden ist — als Herkunftsvermerk und
            // nicht als Bindung. Die Kacheln sind nach dem Anlegen ganz normale
            // Kacheln; eine Vorlage, die ihre Abkömmlinge später mitändert,
            // wäre eine Zusage, die niemand gegeben hat.
            $table->string('template')->nullable();

            $table->timestamps();

            // Die Liste zeigt die Dashboards einer Organisation, zuletzt
            // geändert zuerst.
            $table->index(['organization_id', 'updated_at']);
        });

        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();

            // Die Überschrift der Kachel. Sie steht ausdrücklich hier und wird
            // nicht aus der Abfrage erzeugt: „Fehler nach Release" ist die
            // Frage, `count() nach release` ist die Rechnung, und die Kachel
            // soll die Frage zeigen.
            $table->string('title');

            // Linie, Fläche, Balken, Tabelle, große Zahl, Weltkarte.
            $table->string('type', 32);

            // Die Abfrage: Quelle, Gruppierung, Kennzahlen, Suchbedingung,
            // Sortierung, Zeilenzahl, Schrittweite.
            $table->json('query');

            // Was diese Kachel an der Filterleiste für sich anders sieht —
            // Zeitraum, Umgebung, Projekt. Leer heißt „die Leiste gilt".
            $table->json('overrides')->nullable();

            // Die Lage im Raster: Spalte, Zeile, Breite, Höhe. Der Nullpunkt
            // ist links oben, gezählt wird in Rasterfeldern und nicht in
            // Pixeln — ein Dashboard soll auf einem schmalen Bildschirm
            // dieselbe Anordnung haben und nicht dieselbe Größe.
            $table->unsignedSmallInteger('x')->default(0);
            $table->unsignedSmallInteger('y')->default(0);
            $table->unsignedSmallInteger('width')->default(6);
            $table->unsignedSmallInteger('height')->default(4);

            $table->timestamps();

            // Die Kacheln kommen in Leserichtung: von oben nach unten, in einer
            // Zeile von links nach rechts.
            $table->index(['dashboard_id', 'y', 'x']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
        Schema::dropIfExists('dashboards');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Sortierungen der Fehlerliste (S1), die es beim Anlegen der Tabelle
     * noch nicht gab.
     *
     * I6 hat die Liste angelegt, wie sie aufgeht: offen, zuletzt aufgetretene
     * zuerst — dafür stehen `(project_id, status, last_seen)` und
     * `(project_id, last_seen)` bereits da. S1 macht die Sortierung wählbar, und
     * damit gibt es zwei weitere Wege durch dieselbe Tabelle.
     *
     * **Warum überhaupt Indizes und nicht „das sortiert die Datenbank schon":**
     * die Zusage der Aufgabe ist eine flotte Liste bei über 100.000 Einträgen.
     * Ohne passenden Index ist jede dieser Sortierungen ein vollständiges Lesen
     * mit anschließendem Sortieren im Speicher — bei einem Projekt mit
     * hunderttausend Fehlern also hunderttausend Zeilen für fünfzig sichtbare.
     * Mit Index liest sie die ersten fünfzig in der Reihenfolge des Index und
     * hört auf.
     *
     * **`status` steht jeweils vor der Sortierspalte**, weil die Liste ihn in
     * der Regel einschränkt („offen"). Ein Index, der nach der Sortierung
     * geordnet ist und den Zustand erst hinterher prüft, muss so lange
     * weiterlesen, bis fünfzig passende zusammen sind — bei einem Projekt, in
     * dem fast alles erledigt ist, ist das wieder die ganze Tabelle. Die Fassung
     * ohne `status` daneben ist für die Auswahl „alle".
     *
     * **Keine Fassung für die Dringlichkeit.** Sie wird über eine
     * `case`-Anweisung sortiert (die gespeicherten Wörter stünden sonst
     * alphabetisch), und darauf kann kein Index greifen. Der Zeitraum-Filter
     * schränkt vorher bereits über `(project_id, last_seen)` ein; sortiert wird
     * damit über die Treffer des Zeitraums und nicht über alles.
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            // „Die häufigsten offenen Fehler."
            $table->index(['project_id', 'status', 'times_seen']);

            // „Was ist neu?" — mit und ohne Zustandsfilter.
            $table->index(['project_id', 'status', 'first_seen']);
            $table->index(['project_id', 'first_seen']);
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'status', 'times_seen']);
            $table->dropIndex(['project_id', 'status', 'first_seen']);
            $table->dropIndex(['project_id', 'first_seen']);
        });
    }
};

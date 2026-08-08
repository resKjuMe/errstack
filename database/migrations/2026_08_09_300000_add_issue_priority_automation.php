<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die automatisch ermittelte Wichtigkeit (S11) — und der Vermerk, dass
     * jemand widersprochen hat.
     *
     * Die Spalte `priority` gibt es seit I6; was fehlt, ist die Antwort auf die
     * Frage, **wer** sie zuletzt gesetzt hat. Ohne sie hat die Automatik nur
     * zwei Möglichkeiten, und beide sind falsch: entweder sie überschreibt die
     * Einordnung von Hand bei jedem Durchlauf, oder sie rührt nie etwas an, was
     * einmal von „mittel" abweicht — und dann bleibt jeder Fehler, den sie
     * selbst einmal hochgestuft hat, für immer hoch.
     *
     * Zwei Dinge entstehen:
     *
     * **`priority_locked`** — die Einordnung stammt von einem Menschen. Ein
     * Schalter und kein Zeitstempel „zuletzt von Hand": die Frage der Automatik
     * ist „darf ich?", und die beantwortet ein Ja/Nein. Wer die Einordnung
     * wieder der Automatik überlässt, löscht den Schalter; der Weg zurück ist
     * damit derselbe Knopf und nicht ein zweiter Begriff.
     *
     * **`escalated_at`** — wann zuletzt eine Eskalation festgestellt wurde. Er
     * ist der Grund, warum dieselbe aus dem Ruder gelaufene Stummschaltung
     * nicht in jedem Durchlauf erneut gemeldet wird: der Durchlauf findet alle
     * paar Minuten statt, die Welle dauert Stunden. Er bleibt auch stehen,
     * nachdem der Eintrag wieder offen ist — er beschreibt, was geschehen ist,
     * und nicht, was gerade gilt.
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->boolean('priority_locked')->default(false)->after('priority');

            $table->timestamp('escalated_at')->nullable()->after('priority_locked');
        });

        Schema::table('issues', function (Blueprint $table) {
            // Der Zugriff des Durchlaufs: er geht über alle Projekte hinweg und
            // sieht sich nur an, was in letzter Zeit überhaupt aufgetreten ist.
            // Die vorhandenen Indizes beginnen alle mit `project_id` und helfen
            // ihm deshalb nicht — ohne diesen wäre jeder Durchlauf ein voller
            // Durchgang durch die Fehlertabelle.
            $table->index('last_seen');
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropIndex(['last_seen']);
            $table->dropColumn(['priority_locked', 'escalated_at']);
        });
    }
};

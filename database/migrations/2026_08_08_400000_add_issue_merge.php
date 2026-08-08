<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Das Zusammenführen von Hand: ein Eintrag tritt einem anderen bei.
     *
     * Die naheliegende Umsetzung wäre, beim Zusammenführen die Gruppen des einen
     * Eintrags auf den anderen umzuhängen und den leeren Eintrag zu löschen.
     * Genau das lässt sich aber nicht mehr rückgängig machen: welche Gruppen
     * vorher zu welchem Eintrag gehörten, stünde nirgends mehr, und die Zähler
     * des aufgelösten Eintrags wären in denen des anderen aufgegangen.
     *
     * Deshalb bewegt sich hier nichts. Der beitretende Eintrag bleibt stehen,
     * behält seine Gruppen, seine Zeitreihe, seine Betroffenen und seine
     * Merkmale — und bekommt nur einen Verweis auf den Eintrag, dem er
     * beigetreten ist. Das Auftrennen ist dann das Löschen dieses einen
     * Verweises, und alles ist wieder da, wo es war.
     *
     * Was das für die übrigen Ansichten bedeutet: der Kopf-Eintrag steht für
     * sich **und** für seine Beigetretenen. Die Fehlerliste lässt Beigetretene
     * aus (sie stehen im Kopf), Verlaufsgrafik und Merkmale summieren über die
     * Mitglieder, und die aufgenommenen Meldungen zählen am Kopf — ein
     * beigetretener Eintrag friert damit im Augenblick des Zusammenführens ein.
     * Das ist die Eigenschaft, die das Auftrennen verlustfrei macht: sein Anteil
     * ist genau das, was in seinen eigenen Zeilen steht.
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            // Der Eintrag, dem dieser beigetreten ist. `null` ist der Regelfall
            // — ein Eintrag steht für sich.
            //
            // `nullOnDelete` und nicht `cascade`: wird der Kopf gelöscht, sind
            // die Beigetretenen wieder eigenständig. Sie mit ihm zu löschen
            // wäre das Gegenteil dessen, was das Zusammenführen zusagt — es
            // sollte nie mehr sein als eine Ansichtssache.
            $table->foreignId('merged_into_id')->nullable()->after('project_id')
                ->constrained('issues')->nullOnDelete();

            // Der Weg vom Kopf zu seinen Mitgliedern. Er wird auf jeder
            // Detailseite und in jeder Verlaufsgrafik gegangen — einmal je
            // Eintrag, um zu wissen, worüber summiert wird.
            $table->index('merged_into_id');
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            // Erst der Schlüssel, dann der Index, dann die Spalte — die einzige
            // Reihenfolge, die beide Datenbanken hinnehmen (siehe die
            // Begründung in create_issue_tables).
            $table->dropForeign(['merged_into_id']);
            $table->dropIndex(['merged_into_id']);
            $table->dropColumn('merged_into_id');
        });
    }
};

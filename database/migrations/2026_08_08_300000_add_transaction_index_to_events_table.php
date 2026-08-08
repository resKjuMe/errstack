<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Der Weg von einer Transaktion zu ihren Fehlern (PF3).
     *
     * Die Detailseite einer Transaktion beantwortet auch „welche Fehler sind
     * dabei aufgetreten". Die Meldungen tragen den Transaktionsnamen bereits
     * (`events.transaction`), aber bisher hat niemand danach gesucht — es gab
     * nur Indizes für Zeitraum, Umgebung und Version.
     *
     * Ohne diesen Index wäre die Frage ein vollständiges Lesen aller Meldungen
     * eines Projekts, und zwar auf der einen Seite, deren Zusage eine feste
     * Zahl beschränkter Abfragen ist. Die Reihenfolge ist die der Abfrage:
     * Projekt, dann Name, dann Zeitraum — der Zeitpunkt zuletzt, weil er als
     * Bereich gelesen wird und nach ihm keine Gleichheit mehr folgt.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->index(['project_id', 'transaction', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'transaction', 'occurred_at']);
        });
    }
};

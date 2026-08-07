<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Umgebungen je Projekt (`production`, `staging`, …). Sie werden nicht
     * angelegt, sondern beim Eintreffen der ersten Meldung erfasst — die
     * Anwendung, die meldet, weiß am besten, wie ihre Umgebungen heißen.
     *
     * Die Tabelle ist deshalb kein Katalog, aus dem die Ereignisse ihre
     * Umgebung wählen, sondern die Liste des Gesehenen: Filterleiste und
     * Auswertungen ziehen ihre Auswahlmöglichkeiten daraus. Ereignisse führen
     * ihre Umgebung weiterhin als Text mit, damit eine versteckte oder später
     * entfernte Umgebung ihre Daten nicht mitnimmt.
     */
    public function up(): void
    {
        Schema::create('environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);

            // Versteckte Umgebungen verschwinden aus der Filterleiste, bleiben
            // aber erfasst: träfe eine Meldung erneut ein, entstünde sonst ein
            // neuer Eintrag und das Verstecken wäre wieder aufgehoben.
            $table->boolean('is_hidden')->default(false);

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // Der Name ist die Kennung innerhalb des Projekts; zwei Projekte
            // haben unabhängig voneinander ihr eigenes `production`. Der Index
            // trägt auch die Abfrage der Filterleiste, die die Umgebungen aller
            // Projekte einer Organisation auf einmal liest.
            $table->unique(['project_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environments');
    }
};

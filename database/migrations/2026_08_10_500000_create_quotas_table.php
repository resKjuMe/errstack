<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kontingente: wie viel eine Organisation oder ein Projekt je Datenart
     * aufnehmen darf — im laufenden Monat und in einer Minute.
     *
     * Eine Tabelle für beide Ebenen und nicht zwei, weil die Zeile in beiden
     * Fällen dasselbe sagt und dieselben Fragen beantwortet. Zwei Tabellen
     * hießen zwei Abfragen auf dem Weg jeder eingehenden Meldung, zwei
     * Formulare und zwei Stellen, an denen eine neue Datenart nachzupflegen
     * ist.
     *
     * Der Schlüssel hat bewusst **keine** Zeile hier: seine Grenze steht an ihm
     * selbst (`project_keys.rate_limit_per_minute`) und gilt für alles, was
     * über ihn hereinkommt — sie ist die Notbremse für eine einzelne Anwendung
     * und keine Frage der Datenart.
     */
    public function up(): void
    {
        Schema::create('quotas', function (Blueprint $table) {
            $table->id();

            // Ebene und Kennung getrennt statt zweier nullbarer Fremdschlüssel:
            // die Datenaufnahme fragt „was gilt für diese Organisation?" und
            // „was gilt für dieses Projekt?" mit derselben Abfrage. Der Preis
            // ist die fehlende Fremdschlüssel-Prüfung — dafür räumt das Löschen
            // einer Organisation ({@see App\Models\Quota::forget()}) hinter
            // sich auf.
            $table->string('scope', 16);
            $table->unsignedBigInteger('scope_id');
            $table->string('category', 24);

            // Beide `null` heißt „unbegrenzt". Das ist die Vorgabe und nicht
            // etwa ein großzügiger Zahlenwert: ein Kontingent ist eine
            // Entscheidung des Betreibers, und eine Voreinstellung, die
            // irgendwann still zuschlägt, wäre die schlechteste Art, sie zu
            // treffen.
            $table->unsignedBigInteger('per_month')->nullable();
            $table->unsignedInteger('per_minute')->nullable();

            // Bis zu welchem Anteil in welchem Monat schon gewarnt wurde
            // (`2026-08`, 80 oder 100). Steht hier und nicht im
            // Zwischenspeicher, damit ein geleerter Cache nicht dieselbe
            // Warnung ein zweites Mal verschickt — die Zahlen dürfen verloren
            // gehen, die Nachricht an die Verwaltung nicht.
            $table->string('warned_period', 7)->nullable();
            $table->unsignedTinyInteger('warned_percent')->nullable();

            $table->timestamps();

            // Je Ebene, Kennung und Datenart genau eine Zeile: zwei
            // Kontingente für dieselbe Sache wären keine Einstellung mehr,
            // sondern ein Zufall.
            $table->unique(['scope', 'scope_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotas');
    }
};

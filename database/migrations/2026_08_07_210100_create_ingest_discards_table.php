<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Was **nicht** angekommen ist, gezählt je Stunde.
     *
     * Die Eingangsablage kann diese Frage nicht beantworten: was verworfen
     * wurde, steht dort naturgemäß nicht drin. Damit wäre „warum fehlen
     * Meldungen?" unbeantwortbar — die Frage, die man an ein Fehler-Werkzeug
     * stellt, sobald man ihm nicht mehr traut.
     *
     * Gezählt statt einzeln abgelegt: eine Flut unbekannter Elemente soll die
     * Datenbank nicht füllen. Auswertung und Darstellung sind die
     * Nutzungsstatistik (O3); hier entsteht nur der Rohstoff dafür.
     */
    public function up(): void
    {
        Schema::create('ingest_discards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Wie bei den Meldungen: ein zurückgezogener Schlüssel darf
            // gelöscht werden, ohne die Zahlen mitzunehmen.
            $table->foreignId('project_key_id')->nullable()->constrained()->nullOnDelete();

            // `server` = wir haben abgelehnt, `client` = das SDK hat schon bei
            // sich verworfen und es uns gemeldet.
            $table->string('origin', 8);

            // Auf unserer Seite einer aus App\Enums\DiscardReason, auf der
            // Client-Seite die Bezeichnung des SDK. Keine Aufzählung in der
            // Datenbank: Sentrys Gründe wachsen mit jeder SDK-Fassung, und eine
            // Meldung soll nicht daran scheitern, dass ein Grund neu ist.
            $table->string('reason', 48);

            // Worum es ging — der Element-Typ bzw. Sentrys Datenkategorie
            // (`error`, `transaction`, `attachment` …). `null`, wo sich das
            // nicht sagen lässt.
            $table->string('category', 32)->nullable();

            // Angefangene Stunde. Feiner brächte für die Auskunft nichts und
            // würde die Tabelle unnötig füllen.
            $table->dateTime('bucket');

            $table->unsignedBigInteger('quantity')->default(0);

            $table->timestamps();

            // Die Auswertung fragt „was wurde in diesem Projekt in diesem
            // Zeitraum verworfen" — genau diese Spaltenfolge. Bewusst kein
            // eindeutiger Index über die Merkmale: bei zwei gleichzeitigen
            // Anfragen entstünde sonst eine Kollision, und ein Zähler darf
            // keine Meldung kosten. Doppelte Zeilen einer Stunde summieren sich
            // korrekt.
            $table->index(['project_id', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_discards');
    }
};

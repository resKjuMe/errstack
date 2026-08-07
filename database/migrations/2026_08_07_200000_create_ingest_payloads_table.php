<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Eingangsablage der Datenaufnahme: hier landet jede angenommene Meldung
     * unverändert, bevor sie ausgewertet wird.
     *
     * Die Trennung von Annahme und Auswertung ist Absicht. Der Endpunkt
     * antwortet, sobald die Rohdaten liegen — damit hängt die Antwortzeit einer
     * überwachten Anwendung nicht an unserer Verarbeitung, und ein Fehler in
     * der Auswertung kostet keine Meldung: sie liegt ja schon hier und kann
     * erneut durchlaufen.
     */
    public function up(): void
    {
        Schema::create('ingest_payloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Über welchen Schlüssel die Meldung kam. `nullOnDelete`, weil ein
            // zurückgezogener Schlüssel gelöscht werden darf, ohne die bereits
            // angenommenen Meldungen mitzunehmen.
            $table->foreignId('project_key_id')->nullable()->constrained()->nullOnDelete();

            // Die Nummer, unter der das SDK die Meldung führt (32 Hex-Zeichen).
            // Sie steht in der Antwort und ist später der Schlüssel für die
            // Doppelerkennung; hier nur ein gewöhnlicher Index, damit die
            // Annahme nie an einer Kollision scheitert — was doppelt ist,
            // entscheidet die Verarbeitung.
            $table->char('event_id', 32);

            // Art der Meldung. Über diesen Endpunkt kommen nur Fehler
            // (`event`); der Envelope-Weg liefert daneben Transaktionen,
            // Sitzungen und Anhänge, die alle in derselben Ablage landen.
            $table->string('type', 32)->default('event');

            // Welches SDK gemeldet hat (`sentry_client` aus den Zugangsdaten),
            // z. B. `sentry.php/4.0.0`. Nützlich, wenn eine bestimmte
            // SDK-Fassung auffällige Daten schickt.
            $table->string('sdk')->nullable();

            $table->longText('payload');

            // Größe der entpackten Nutzdaten. Steht hier, damit sich die
            // Datenmenge je Projekt auswerten lässt, ohne jede Meldung zu lesen.
            $table->unsignedInteger('size_bytes');

            // `created_at` ist der Eingangszeitpunkt.
            $table->timestamps();

            // Die Verarbeitung holt sich die Meldungen eines Projekts in
            // Eingangsreihenfolge; das Aufräumen alter Daten (O2) läuft über
            // dieselbe Spaltenfolge.
            $table->index(['project_id', 'created_at']);

            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_payloads');
    }
};

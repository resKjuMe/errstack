<?php

use App\Models\IngestPayload;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zwei Angaben, die erst der Envelope-Weg braucht.
     *
     * `item_headers` — der Kopf des Elements, so wie er ankam. Bei einem Anhang
     * stehen dort Dateiname und Inhaltstyp, bei einer Aufzeichnung die
     * Zuordnung; ohne diese Angaben wären die Nutzdaten eine Datei ohne Namen.
     * Der ganze Kopf statt einzelner Spalten, weil Sentry ihn je Typ
     * unterschiedlich füllt und laufend erweitert — jede eigene Spalte wäre eine
     * Migration bei der nächsten SDK-Fassung.
     *
     * `payload_encoding` — wie die Nutzdaten in der Spalte liegen. Sie ist
     * `longText`, also für Text gedacht; Anhänge und Aufzeichnungen sind aber
     * beliebige Bytes, und ein Screenshot in einer utf8mb4-Spalte ist entweder
     * ein Fehler beim Schreiben oder stiller Datenverlust beim Lesen. Solche
     * Elemente werden deshalb Base64-verpackt abgelegt und hier als solche
     * gekennzeichnet ({@see IngestPayload::bytes()}).
     *
     * Die naheliegende Alternative — die Spalte auf `binary` umstellen — wurde
     * verworfen: sie kommt bei PostgreSQL als Datenstrom zurück statt als
     * Zeichenkette, und der weitaus häufigste Fall (JSON) wäre in der Datenbank
     * nicht mehr lesbar.
     */
    public function up(): void
    {
        Schema::table('ingest_payloads', function (Blueprint $table) {
            $table->json('item_headers')->nullable();

            // `null` heißt „unverändert" — das ist der Regelfall und bleibt es
            // auch für alles, was über `/store/` hereinkommt.
            $table->string('payload_encoding', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ingest_payloads', function (Blueprint $table) {
            $table->dropColumn(['item_headers', 'payload_encoding']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Debug-Kennungen einer Meldung (`debug_meta.images`).
     *
     * Das Feld stand seit der Normalisierung (I4) auf der Liste der bekannten
     * Felder, hatte aber kein Fach — es fiel also weg. Ab jetzt hat es eines,
     * denn es trägt den zweiten Weg zur Quellkarte: je geladener Datei eine
     * Kennung, die der Bauvorgang in Bundle und Karte geschrieben hat.
     *
     * Eine eigene Spalte und nicht `unknown`: gesucht wird darin bei jedem
     * Rahmen eines Stacktraces, und was gelesen wird, gehört nicht in das Fach
     * für das, was wir nicht kennen.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->json('debug_meta')->nullable()->after('modules');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('debug_meta');
        });
    }
};

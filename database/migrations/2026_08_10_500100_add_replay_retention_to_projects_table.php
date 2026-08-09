<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Aufbewahrungsfrist für Sitzungs-Aufzeichnungen — getrennt von der für
     * Ereignisse.
     *
     * Getrennt, weil es zwei verschiedene Entscheidungen sind. `retention_days`
     * beantwortet „wie lange wollen wir Fehler nachschlagen können" und wird von
     * Ansprüchen an die Fehlersuche bestimmt. Diese Spalte beantwortet „wie
     * lange wollen wir Bildschirminhalte unserer Nutzer aufheben" — eine Frage,
     * die je nach Haus vom Datenschutz und nicht von der Entwicklung entschieden
     * wird, und deren Antwort um ein Vielfaches kürzer ausfällt. Beide an eine
     * Zahl zu hängen hieße, eine der beiden falsch zu beantworten.
     *
     * `null` heißt „die Vorgabe des Betreibers" (`config('replays.retention_days')`)
     * und ist die Voreinstellung — dieselbe Auslegung wie überall sonst, wo ein
     * Projekt eine Betreiber-Einstellung überschreiben darf.
     *
     * **Null Tage heißt „gar nicht aufzeichnen".** Das ist der Ausschalter, und
     * er ist bewusst kein zweiter Schalter daneben: „wir behalten Aufzeichnungen
     * null Tage lang" und „wir wollen keine Aufzeichnungen" sind dieselbe Aussage,
     * und zwei Felder dafür wären zwei Wahrheiten, die eines Tages auseinander
     * laufen. Ankommende Abschnitte werden dann verworfen und gezählt, nicht
     * abgelegt und sofort wieder weggeräumt.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedSmallInteger('replay_retention_days')->nullable()->after('retention_days');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('replay_retention_days');
        });
    }
};

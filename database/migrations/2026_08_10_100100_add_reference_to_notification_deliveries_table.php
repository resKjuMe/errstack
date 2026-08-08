<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Kennung der Meldung als eigene Spalte — damit sich eine Zustellung
     * ihrer Regel zuordnen lässt.
     *
     * Sie stand von Anfang an in der Nutzlast (`payload.reference`, siehe
     * App\Notifications\NotificationMessage): dieselbe Zeichenkette über alle
     * Meldungen eines Alarms, damit Auslösen und Entwarnung im Kanal einander
     * zuzuordnen sind. Gesucht wurde bisher nie danach.
     *
     * Die Alarm-Detailseite (A4) stellt genau diese Frage — „was ist aus dieser
     * Auslösung hinausgegangen, und kam es an?" —, und sie ist aus dem JSON
     * nicht zu beantworten: die beiden unterstützten Datenbanken schreiben
     * JSON-Zugriffe verschieden, und ein Index läge auf keinem von beiden.
     * Deshalb eine gewöhnliche, indizierte Spalte.
     *
     * **Bestehende Zeilen bleiben leer.** Die Kennung ließe sich aus der
     * Nutzlast nachziehen; das wäre ein Durchlauf über das gesamte
     * Zustellprotokoll für eine Auskunft über Meldungen, die längst gelesen
     * sind. Die Detailseite zeigt deshalb, was seit dieser Änderung
     * hinausgegangen ist — und sagt das auch.
     */
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            // Nullable: Testnachrichten und alles vor dieser Änderung haben
            // keine. Der Index ist der Zugriff der Detailseite — je Kennung, und
            // beim Fehler-Alarm über den gemeinsamen Anfang aller Meldungen
            // einer Regel (`ISSUE-<regel>-%`).
            $table->string('reference')->nullable()->after('subject')->index();
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropIndex(['reference']);
            $table->dropColumn('reference');
        });
    }
};

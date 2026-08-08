<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Womit und von wo aus gemessen wurde: Browser, Gerät und Land.
     *
     * Bis hierhin trug eine Transaktion nur, **was** gemessen wurde, nicht
     * **wobei**. Für die serverseitige Antwortzeit ist das richtig — sie hängt
     * nicht am Browser des Aufrufers. Für das Ladeerlebnis im Browser (PF5) ist
     * es die eigentliche Frage: ein LCP von vier Sekunden ist eine völlig andere
     * Auskunft, je nachdem, ob es alle betrifft oder nur Mobilgeräte in einem
     * Land mit schlechter Anbindung.
     *
     * Drei Spalten an der Messung und nicht eine Verknüpfung auf eine
     * Merkmalstabelle: die Werte werden **gelesen**, nicht verwaltet — es gibt
     * nichts an einem Browsernamen zu pflegen. Ein Fremdschlüssel brächte einen
     * Verbund in jede Auswertung und eine zweite Tabelle, die mitwachsen muss.
     *
     * Sie stehen an `transactions` und nicht an der Vorberechnung: in deren
     * eindeutigen Schlüssel aufgenommen würden sie die Zeilenzahl mit jedem
     * Browser, jedem Gerät und jedem Land vervielfachen. Die Aufschlüsselung
     * beruht deshalb auf einer Stichprobe der Einzelmessungen — dieselbe
     * Abwägung wie bei den Merkmalen der Transaktions-Detailseite (PF3).
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Nur der Name, ohne Version: gefragt ist „liegt es an Safari",
            // nicht „liegt es an Safari 17.4.1". Mit der Version wäre jede
            // Aufschlüsselung eine Liste aus zwanzig Zeilen desselben Browsers.
            $table->string('browser', 64)->nullable()->after('platform');

            // Die Gerätefamilie, wie das SDK sie meldet („iPhone", „Pixel 8",
            // „Mac"). Bewusst nicht auf „mobil/Tisch" verdichtet: diese
            // Einteilung ist eine Frage der Anzeige und lässt sich aus dem
            // gemeldeten Wert jederzeit bilden — umgekehrt nicht.
            $table->string('device', 64)->nullable()->after('browser');

            // Das Land als zweistelliges Kürzel (ISO 3166-1 alpha-2). Kein
            // ausgeschriebener Name, weil der je nach Sprache des meldenden
            // SDK anders lautete und dasselbe Land dann mehrfach in der
            // Aufschlüsselung stünde.
            $table->char('country', 2)->nullable()->after('device');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['browser', 'device', 'country']);
        });
    }
};

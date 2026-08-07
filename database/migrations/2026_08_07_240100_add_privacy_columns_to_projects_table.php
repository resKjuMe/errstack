<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die drei Datenschutz-Schalter eines Projekts.
     *
     * Sie stehen am Projekt und nicht in den Regeln, weil sie keine Regeln sind:
     * eine Regel beschreibt, wo etwas steht, diese Schalter beschreiben eine
     * Entscheidung über eine ganze Art von Angabe. „Keine IP-Adressen" wäre als
     * Regel ein Dutzend Feldnamen und ein Muster — und beim nächsten SDK, das
     * die Adresse an einer weiteren Stelle mitschickt, wieder eine mehr.
     *
     * Alle drei stehen auf „speichern" (`false`), wie bei Sentry: das Zählen
     * betroffener Personen, die Herkunft eines Fehlers und ein Screenshot zum
     * Absturz sind der Zweck des Werkzeugs. Wer weniger speichern will, schaltet
     * es ab — was ohne Konfiguration verschwindet, sind die Standardfelder
     * (Passwörter, Nachweise, Kartennummern), und die haben mit diesen Schaltern
     * nichts zu tun.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('scrub_ip_addresses')->default(false)->after('retention_days');
            $table->boolean('scrub_user_data')->default(false)->after('scrub_ip_addresses');
            $table->boolean('scrub_attachments')->default(false)->after('scrub_user_data');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['scrub_ip_addresses', 'scrub_user_data', 'scrub_attachments']);
        });
    }
};

<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die eigene Aufbewahrungsfrist der Anhänge.
     *
     * Sie steht neben `retention_days` und nicht darin, weil sie eine andere
     * Frage beantwortet: eine Meldung ist ein paar Kilobyte und die Grundlage
     * jeder Auswertung über Wochen — ein Speicherabbild ist zwanzig Megabyte und
     * wird in den Tagen gebraucht, in denen jemand den Absturz untersucht. Wer
     * Meldungen ein Jahr behalten will, will nicht ein Jahr Speicherabbilder
     * behalten.
     *
     * Der Vorgabewert steht hier als Zahl und **nicht** als `config()`-Aufruf. Ein
     * Konfigurationswert im Schema wird zum Zeitpunkt der Migration eingebacken:
     * ein später geändertes `ATTACHMENTS_RETENTION_DAYS` erreicht damit kein neues
     * Projekt mehr, und ein Deployment, das Migrationen ohne die Umgebung der
     * Anwendung laufen lässt, bekäme einen dritten Wert. Die Einstellung des
     * Betreibers greift stattdessen beim Anlegen eines Projekts
     * ({@see Project::createFor()}); diese Zahl ist nur der Boden für
     * die bestehenden Zeilen.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedSmallInteger('attachment_retention_days')
                ->default(7)
                ->after('retention_days');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('attachment_retention_days');
        });
    }
};

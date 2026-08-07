<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die sieben Schalter der Eingangsfilter.
     *
     * Sieben Spalten und kein JSON-Feld: es sind sieben unabhängige
     * Entscheidungen, nach denen später ausgewertet wird („welche Projekte
     * filtern Crawler?"), und eine Spalte ist die Form, in der sich das
     * beantworten lässt. Der Preis ist eine breitere Tabelle; der Gegenwert ist,
     * dass jeder Schalter für sich prüfbar, indizierbar und in einer Migration
     * änderbar bleibt.
     *
     * **Alle stehen auf aus.** Ein Filter, der nach dem Einspielen von selbst
     * Meldungen verschluckt, ist genau die Sorte Überraschung, die das Vertrauen
     * in ein Fehler-Werkzeug kostet: die erste Frage bei einer Lücke in der Liste
     * wäre dann nicht „welchen Filter habe ich eingeschaltet?", sondern „warum
     * fehlt hier etwas?". Wer filtern will, schaltet ein — und sieht dabei, was
     * er einschaltet.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('filter_browser_extensions')->default(false)->after('scrub_attachments');
            $table->boolean('filter_legacy_browsers')->default(false)->after('filter_browser_extensions');
            $table->boolean('filter_localhost')->default(false)->after('filter_legacy_browsers');
            $table->boolean('filter_crawlers')->default(false)->after('filter_localhost');
            $table->boolean('filter_message_patterns')->default(false)->after('filter_crawlers');
            $table->boolean('filter_ip_addresses')->default(false)->after('filter_message_patterns');
            $table->boolean('filter_releases')->default(false)->after('filter_ip_addresses');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'filter_browser_extensions',
                'filter_legacy_browsers',
                'filter_localhost',
                'filter_crawlers',
                'filter_message_patterns',
                'filter_ip_addresses',
                'filter_releases',
            ]);
        });
    }
};

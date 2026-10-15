<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket-Systeme neben dem Code-Hoster (X4).
 *
 * X1 hat die drei Tabellen gebaut, in denen eine Anbindung, eine Verknüpfung
 * und eine eingegangene Meldung stehen — und sie so gebaut, dass der Anbieter
 * darin ein Freitext ist. Genau das wird hier eingelöst: Jira und Linear
 * bekommen **keine** eigenen Tabellen, sondern zwei weitere Werte in
 * `provider`. Ein Fehler kann danach mit einem GitHub-Ticket **und** einem
 * Jira-Vorgang verknüpft sein, ohne dass eine Abfrage wüsste, welcher von
 * beiden welcher ist.
 *
 * Was tatsächlich fehlt, sind drei Angaben — und jede davon fehlt aus einem
 * anderen Grund:
 *
 *   integrations.settings              der Abgleich ist je Richtung schaltbar
 *                                      (Abnahmekriterium), und die Vorbelegung
 *                                      des neuen Tickets gehört zur Anbindung,
 *                                      nicht zum einzelnen Klick
 *   integrations.webhook_token_hash    Jira und Linear unterschreiben ihre
 *                                      Meldungen nicht wie GitHub — der Nachweis
 *                                      steckt in der Adresse, und die muss
 *                                      auffindbar sein
 *   issue_links.external_id            wer ein Ticket schließen will, braucht
 *                                      die Kennung, unter der es der Anbieter
 *                                      führt — und die ist nicht die Nummer
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            // Was an dieser Anbindung eingestellt ist: die beiden Schalter des
            // Abgleichs und die Vorbelegung für ein neues Ticket (Projekt, Typ,
            // Priorität, Zuständigkeit).
            //
            // Als JSON und nicht als sechs Spalten, weil die Angaben je Anbieter
            // verschieden sind und sich mit jedem weiteren verschieben: Jira
            // kennt einen Vorgangstyp, Linear nicht; Jira nennt Prioritäten
            // („High"), Linear zählt sie (0–4). Sechs Spalten hießen, für jeden
            // neuen Anbieter eine Wanderung zu schreiben — und die Hälfte davon
            // steht bei den anderen leer.
            //
            // **Nicht verschlüsselt, anders als `credentials`.** Hier steht
            // nichts, womit man beim Anbieter etwas tun könnte; wer die
            // Datenbank liest, erfährt, dass Tickets im Projekt `OPS` angelegt
            // werden. Das ist der Unterschied, an dem die Verschlüsselung
            // hängt — und eine verschlüsselte Spalte wäre nicht abfragbar.
            $table->json('settings')->nullable()->after('credentials');

            // Der Nachweis, dass eine eingehende Meldung wirklich von *dieser*
            // Anbindung kommt.
            //
            // GitHub unterschreibt den Rumpf (`X-Hub-Signature-256`), und die
            // Prüfung braucht nur ein Geheimnis der Installation. Jira Cloud
            // unterschreibt eine per Schnittstelle eingetragene Rückadresse
            // **nicht**, und Linears Unterschrift hängt an einem Geheimnis, das
            // beim Einrichten des Webhooks drüben entsteht und hier nicht
            // bekannt ist. Bliebe es dabei, wäre der Eingang offen: „Vorgang
            // OPS-42 ist erledigt" ist eine Meldung, die einen Fehler hier auf
            // erledigt setzt, und niemand bräuchte dafür mehr als die Adresse.
            //
            // Deshalb steckt das Geheimnis in der Adresse selbst
            // (`/api/hooks/jira/<token>`), und **hier steht nur sein Hash**:
            // gesucht wird damit (deshalb keine Verschlüsselung — die ist nicht
            // deterministisch und damit nicht abfragbar), und wer die Datenbank
            // liest, hat trotzdem keine gültige Adresse. Das Geheimnis selbst
            // liegt verschlüsselt bei den Zugangsdaten, weil die Oberfläche die
            // Adresse zum Eintragen anzeigen muss.
            $table->string('webhook_token_hash', 64)->nullable()->unique()->after('settings');
        });

        Schema::table('issue_links', function (Blueprint $table) {
            // Die Kennung, unter der der Anbieter das Ticket führt.
            //
            // Für GitHub ist sie entbehrlich: Repository und Nummer sind dort
            // die Adresse, unter der man das Ticket auch ändert. Bei Linear ist
            // sie es nicht — `ENG-42` ist die Anzeigeform, geändert wird über
            // eine UUID. Und bei Jira ist der Schlüssel `OPS-42` zwar
            // ansprechbar, wandert aber mit, wenn ein Vorgang in ein anderes
            // Projekt verschoben wird; die Kennung bleibt.
            //
            // Sie steht neben Projekt und Nummer und nicht an deren Stelle: die
            // beiden sind, was auf der Seite steht und wonach eine eingehende
            // Meldung sucht. Die Kennung ist, was man braucht, um drüben etwas
            // zu tun.
            $table->string('external_id', 200)->nullable()->after('number');
        });
    }

    public function down(): void
    {
        Schema::table('issue_links', function (Blueprint $table) {
            $table->dropColumn('external_id');
        });

        Schema::table('integrations', function (Blueprint $table) {
            // Der eindeutige Index hängt an der Spalte und geht mit ihr — in
            // SQLite ausdrücklich zuerst, weil dort das Verwerfen einer Spalte
            // die Tabelle neu baut.
            $table->dropUnique(['webhook_token_hash']);
            $table->dropColumn(['settings', 'webhook_token_hash']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Zuständigkeit (S7): wer sich um einen Fehler kümmert — und die Prüfliste,
 * in der neue Fehler landen, bis jemand sie in die Hand nimmt.
 *
 * **Zwei Spalten für einen Zuständigen, nicht eine Spalte mit einer Art.** Eine
 * Zuweisung geht an eine Person **oder** an ein Team, und beides sind Zeilen in
 * verschiedenen Tabellen. Die naheliegende Alternative — `assignee_id` plus
 * `assignee_type` — wäre ein polymorpher Verweis ohne Fremdschlüssel: ein
 * gelöschtes Team hinterließe eine Kennung, die auf nichts zeigt, und die
 * Datenbank könnte es nicht verhindern. Mit zwei Spalten räumt `nullOnDelete`
 * beim Löschen von selbst auf, und die Abfrage der Liste („wem gehört das?")
 * bleibt eine Verknüpfung ohne Fallunterscheidung.
 *
 * **Dass höchstens eine der beiden gefüllt ist, hält der Schreibweg ein**
 * (App\Support\Issues\IssueActions::assign()) — und nicht eine `CHECK`-Bedingung.
 * Der Grund ist nicht Bequemlichkeit: die Zuweisung wird ausschließlich über
 * diesen einen Weg gesetzt, der beide Spalten immer gemeinsam schreibt, und eine
 * Bedingung in der Datenbank wäre eine zweite Fassung derselben Regel — an einer
 * Stelle, an der sie beim Wechsel des Datenbanksystems (SQLite in den Tests,
 * MySQL im Betrieb) verschieden formuliert werden müsste.
 *
 * **`for_review_at` ist ein Zeitpunkt und kein Häkchen.** „Seit wann liegt das
 * hier?" ist die Frage, die eine Prüfliste beantworten muss — ein `boolean`
 * könnte sie nicht, und ein zweites Feld daneben wäre dieselbe Angabe zweimal.
 * Leer heißt: nicht (mehr) zur Prüfung. Bestehende Einträge bekommen den Wert
 * **nicht** nachgetragen: eine Prüfliste, die beim Aufspielen dieser Migration
 * mit dem gesamten Altbestand aufgeht, ist keine Arbeitsliste, sondern ein
 * Grund, sie nie wieder zu öffnen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            // Die zugewiesene Person. `nullOnDelete`, weil ein gelöschtes Konto
            // den Fehler nicht mitnehmen darf — er wird dadurch wieder
            // herrenlos, und genau das ist die richtige Aussage.
            $table->foreignId('assigned_user_id')->nullable()->after('ignore_users_seen')
                ->constrained('users')->nullOnDelete();

            // Das zuständige Team — die Alternative, nicht die Ergänzung.
            $table->foreignId('assigned_team_id')->nullable()->after('assigned_user_id')
                ->constrained('teams')->nullOnDelete();

            $table->timestamp('assigned_at')->nullable()->after('assigned_team_id');

            // Wer zugewiesen hat. Für die Anzeige („von Anna zugewiesen") und
            // ausdrücklich nicht als Ersatz für den Verlauf: dort steht der
            // Name mitgeschrieben und überlebt das Konto.
            $table->foreignId('assigned_by_id')->nullable()->after('assigned_at')
                ->constrained('users')->nullOnDelete();

            // Seit wann der Eintrag zur Prüfung liegt.
            $table->timestamp('for_review_at')->nullable()->after('assigned_by_id');

            // „Was ist mir zugewiesen?" — die Frage, die jeder morgens stellt.
            // Das Projekt steht vorne, weil die Liste immer über eine
            // Projektauswahl geht und der Index sonst über alle Projekte hinweg
            // gelesen würde.
            $table->index(['project_id', 'assigned_user_id']);
            $table->index(['project_id', 'assigned_team_id']);

            // Die Prüfliste, sortiert nach „zuerst gesehen" — ein Index, der
            // beides trägt: die Einschränkung und die Reihenfolge.
            $table->index(['project_id', 'for_review_at']);
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'assigned_user_id']);
            $table->dropIndex(['project_id', 'assigned_team_id']);
            $table->dropIndex(['project_id', 'for_review_at']);

            $table->dropForeign(['assigned_user_id']);
            $table->dropForeign(['assigned_team_id']);
            $table->dropForeign(['assigned_by_id']);

            $table->dropColumn([
                'assigned_user_id',
                'assigned_team_id',
                'assigned_at',
                'assigned_by_id',
                'for_review_at',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Zustandsaktionen an einem Fehler-Eintrag (S6): erledigen,
     * stummschalten, merken, abonnieren, löschen.
     *
     * I6 hat `status` bereits angelegt — offen, erledigt, stummgeschaltet. Was
     * fehlt, ist alles, was die drei Wörter **überprüfbar** macht: ab wann,
     * durch wen, unter welcher Bedingung. Ein Zustand ohne diese Angaben ist
     * eine Behauptung; erst mit ihnen kann die Aufnahme entscheiden, ob eine
     * Stummschaltung noch gilt, und die Oberfläche sagen, warum ein Eintrag
     * nicht in der Liste steht.
     *
     * Fünf Dinge entstehen:
     *
     * **Spalten an `issues`** — der Bezugspunkt des Erledigens (Zeitpunkt,
     * Konto, Version) und die Bedingung des Stummschaltens samt der Zählerstände
     * zu ihrem Beginn.
     *
     * **`issue_activities`** — der Aktivitätsverlauf: je Handlung eine Zeile mit
     * Konto und Zeit. Er steht ausdrücklich **nicht** im Änderungsprotokoll der
     * Organisation (O4); die Begründung steht an App\Enums\IssueActivityType.
     *
     * **`issue_bookmarks`** und **`issue_subscriptions`** — was einer **Person**
     * an einem Eintrag gehört. Zwei Tabellen und nicht zwei Spalten an `issues`:
     * beide Angaben sind je Betrachter verschieden, und eine Spalte am Eintrag
     * könnte nur die Meinung des Letzten festhalten.
     *
     * **`issue_discards`** — die Fingerabdrücke, deren Meldungen künftig
     * verworfen werden („löschen und verwerfen"). Sie überleben den gelöschten
     * Eintrag; das ist ihr ganzer Zweck.
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            // Wann und durch wen erledigt. Der Zeitpunkt ist nicht dasselbe wie
            // `updated_at`: der wandert bei jedem gezählten Ereignis, und ein
            // erledigter Eintrag zählt weiter.
            $table->timestamp('resolved_at')->nullable()->after('priority');

            // `nullOnDelete`: wer den Fehler geschlossen hat, kann das
            // Unternehmen verlassen — der Eintrag bleibt erledigt. Wer es war,
            // steht dann noch im Verlauf, wo der Name mitgeschrieben wird.
            $table->foreignId('resolved_by_id')->nullable()->after('resolved_at')
                ->constrained('users')->nullOnDelete();

            // Die Version, in der er als behoben gilt („in dieser Version
            // erledigt"). Der Bezugspunkt für die Rückkehr-Erkennung (S8): eine
            // Meldung aus einer **älteren** Version ist kein Widerspruch, eine
            // aus einer neueren schon.
            $table->foreignId('resolved_in_release_id')->nullable()->after('resolved_by_id')
                ->constrained('releases')->nullOnDelete();

            // „Im nächsten Release erledigt" — der Fix ist geschrieben, aber
            // noch nicht draußen. Ein Schalter und kein Verweis, weil es die
            // gemeinte Version beim Auflösen noch nicht gibt; wer sie ist,
            // entscheidet erst die nächste Auslieferung.
            $table->boolean('resolved_in_next_release')->default(false)->after('resolved_in_release_id');

            // Ab wann stummgeschaltet. Zugleich der Anfang des Zeitfensters
            // einer Bedingung — ohne ihn wäre „100 Ereignisse in einer Stunde"
            // nicht ausrechenbar.
            $table->timestamp('ignored_at')->nullable()->after('resolved_in_next_release');

            $table->foreignId('ignored_by_id')->nullable()->after('ignored_at')
                ->constrained('users')->nullOnDelete();

            // Die Bedingung, unter der die Stummschaltung endet. Alle drei leer
            // heißt „dauerhaft".
            //
            // `ignore_count` zählt **Ereignisse**, `ignore_users` zählt
            // **Betroffene**; gesetzt ist immer höchstens eines von beiden. Sie
            // in einer Spalte samt „Art" zu führen wäre kürzer und beim Lesen
            // der Abfrage nicht mehr zu unterscheiden.
            $table->unsignedInteger('ignore_count')->nullable()->after('ignored_by_id');
            $table->unsignedInteger('ignore_window_minutes')->nullable()->after('ignore_count');
            $table->unsignedInteger('ignore_users')->nullable()->after('ignore_window_minutes');

            // Die Zählerstände beim Stummschalten. Sie sind der Grund, warum die
            // Bedingung ohne eine zweite Abfrage auswertbar ist: „hundert
            // weitere" ist `times_seen - ignore_times_seen >= 100`, und beide
            // Werte stehen in derselben Zeile.
            //
            // Ohne den Ausgangsstand bliebe nur, die Ereignisse ab `ignored_at`
            // zu zählen — ein Durchlauf über die größte Tabelle dieser
            // Anwendung, und zwar bei jeder eingehenden Meldung.
            $table->unsignedBigInteger('ignore_times_seen')->nullable()->after('ignore_users');
            $table->unsignedBigInteger('ignore_users_seen')->nullable()->after('ignore_times_seen');
        });

        Schema::create('issue_activities', function (Blueprint $table) {
            $table->id();

            // Der Eintrag, um den es ging. Nullable und `nullOnDelete`, weil die
            // beiden Löschvermerke ihn überleben sollen: „gelöscht und künftig
            // verworfen" ist die Auskunft, die man genau dann sucht, wenn der
            // Eintrag weg ist.
            $table->foreignId('issue_id')->nullable()->constrained()->nullOnDelete();

            // Das Projekt steht daneben und nicht nur am Eintrag — sonst wäre
            // ein Vermerk ohne Eintrag heimatlos, und der Projekt-Verlauf ließe
            // sich nicht abfragen.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Der Name zum Zeitpunkt der Handlung. Dieselbe Wahl wie im
            // Änderungsprotokoll: ein gelöschtes Konto darf den Verlauf nicht
            // anonymisieren. Leer bleibt er nur da, wo niemand gehandelt hat —
            // beim Ablauf einer Stummschaltung.
            $table->string('actor_name')->nullable();

            $table->string('type', 30);

            // Die Einzelheiten: welche Version, welche Schwelle, welches
            // Fenster. Als Feld-Baum und nicht als Spalten, weil sie je Art
            // andere sind und keine davon abgefragt wird — sie werden angezeigt.
            $table->json('data')->nullable();

            $table->timestamp('created_at')->nullable();

            // Der Verlauf eines Eintrags, neueste zuerst — die einzige Abfrage,
            // die diese Tabelle im Betrieb sieht.
            $table->index(['issue_id', 'created_at']);

            // Der Verlauf eines Projekts, für die Vermerke ohne Eintrag.
            $table->index(['project_id', 'created_at']);
        });

        // Merken und Abonnieren: dieselbe Form, zwei Bedeutungen. „Gemerkt" ist
        // ein Lesezeichen und sagt nichts über Benachrichtigungen; „abonniert"
        // sagt genau das und nichts über die Liste.
        foreach (['issue_bookmarks', 'issue_subscriptions'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                // Einmal je Person und Eintrag. Der eindeutige Index ist hier
                // nicht Ordnung, sondern die Zusage, auf der das Umschalten
                // beruht: zwei gleichzeitige Klicks dürfen keine zweite Zeile
                // ergeben.
                $table->unique(['issue_id', 'user_id']);

                // „Meine gemerkten Fehler" — die Abfrage von der anderen Seite.
                $table->index(['user_id', 'issue_id']);
            });
        }

        Schema::create('issue_discards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Der Fingerabdruck, nicht die Gruppen-Kennung: die Gruppe geht mit
            // dem Löschen, der Fingerabdruck wird beim nächsten Auftreten neu
            // gerechnet und ist genau das Wiedererkennungsmerkmal, um das es
            // geht.
            $table->string('fingerprint', 64);

            // Wofür der Eintrag stand — für die Anzeige der Verwerfungsliste.
            // Der Titel ist eine Kopie und kein Verweis: der Eintrag, aus dem er
            // stammt, ist gelöscht.
            $table->string('title', 500)->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            // Der Zugriff der Aufnahme: ein Projekt, ein Fingerabdruck. Zugleich
            // die Zusage, dass zweimaliges Verwerfen desselben Fehlers keine
            // zweite Zeile ergibt.
            $table->unique(['project_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_discards');
        Schema::dropIfExists('issue_subscriptions');
        Schema::dropIfExists('issue_bookmarks');
        Schema::dropIfExists('issue_activities');

        Schema::table('issues', function (Blueprint $table) {
            // Erst die Schlüssel, dann die Spalten — MySQL gibt die Spalte nicht
            // frei, solange ein Fremdschlüssel auf ihr liegt.
            $table->dropForeign(['resolved_by_id']);
            $table->dropForeign(['resolved_in_release_id']);
            $table->dropForeign(['ignored_by_id']);

            $table->dropColumn([
                'resolved_at',
                'resolved_by_id',
                'resolved_in_release_id',
                'resolved_in_next_release',
                'ignored_at',
                'ignored_by_id',
                'ignore_count',
                'ignore_window_minutes',
                'ignore_users',
                'ignore_times_seen',
                'ignore_users_seen',
            ]);
        });
    }
};

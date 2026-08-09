<?php

use App\Models\Environment;
use App\Models\ReleaseSession;
use App\Models\ReleaseSessionCount;
use App\Models\ReleaseSessionUser;
use App\Models\TransactionUserAggregate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Sitzungsdaten der Release-Gesundheit — drei Tabellen, und jede
     * beantwortet eine Frage, die die anderen beiden nicht beantworten können.
     *
     * `release_sessions`       — was ist aus **dieser einen** Sitzung geworden?
     * `release_session_counts` — wie viele Sitzungen je Version und Fenster?
     * `release_session_users`  — **wie viele Menschen** stecken dahinter?
     *
     * **Warum nicht eine Tabelle mit Einzelsitzungen und alles daraus rechnen?**
     * Weil die Hälfte der Sitzungen gar nicht einzeln ankommt: Server-SDKs
     * bündeln sie und schicken fertige Zahlen je Minute (`sessions`-Element).
     * Für die gibt es keine Zeile je Sitzung, die man zählen könnte. Und
     * umgekehrt: eine Übersicht über eine Woche wäre über Einzelsitzungen ein
     * Vollscan über jeden Start jeder App.
     *
     * **Warum dann überhaupt Einzelsitzungen?** Wegen der Zusage, dass keine
     * Sitzung doppelt gezählt wird. Ein SDK meldet dieselbe Sitzung mehrfach —
     * erst „läuft", später „abgestürzt" —, und ohne Gedächtnis über ihren
     * bisherigen Ausgang wäre jede Folgemeldung eine neue Sitzung. Diese
     * Tabelle ist genau dieses Gedächtnis, und mehr steht auch nicht drin.
     *
     * **Warum die Nutzer getrennt?** Weil sich „wie viele Nutzer" aus Summen
     * über Sitzungen nicht herleiten lässt — dieselbe Abwägung wie bei den
     * Antwortzeiten ({@see TransactionUserAggregate}). Zehntausend Sitzungen
     * können von zehn Menschen kommen; für die Frage, wie schlimm eine Version
     * ist, macht das den ganzen Unterschied.
     *
     * Wie bei den übrigen Vorberechnungen prüft jeder Schritt, ob es ihn schon
     * gibt: MySQL kann DDL nicht zurückrollen, und ein abgebrochener Lauf ließe
     * alles Vorherige ohne Eintrag in `migrations` stehen.
     */
    public function up(): void
    {
        if (! Schema::hasTable('release_sessions')) {
            Schema::create('release_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();

                // Ohne Version keine Zeile — und deshalb kein `nullable`. Eine
                // Sitzung ohne Versionsangabe sagt über die Gesundheit einer
                // Auslieferung nichts aus; sie wird beim Aufnehmen verworfen
                // und gezählt, statt hier eine Zeile ohne Bezug anzulegen.
                $table->foreignId('release_id')->constrained()->cascadeOnDelete();

                $table->string('environment', Environment::NAME_LIMIT);

                // Die Sitzungsnummer des SDK — der Schlüssel, an dem eine
                // Folgemeldung ihre Sitzung wiederfindet. Als Text und nicht
                // als UUID-Spalte: die Spezifikation sieht eine UUID vor, im
                // Feld kommt aber alles Mögliche an, und eine Sitzung soll
                // nicht an ihrer Schreibweise scheitern.
                $table->string('sid', ReleaseSession::SID_LIMIT);

                // Die gehashte Nutzerkennung (`did`), sofern das SDK eine
                // schickt. Gehasht wie bei den Antwortzeiten: hier wird nur
                // gezählt und nie angezeigt.
                $table->string('user_key', TransactionUserAggregate::KEY_LENGTH)->nullable();

                $table->string('status', ReleaseSession::STATUS_LIMIT);
                $table->unsignedInteger('error_count')->default(0);

                // Die Folgenummer des SDK. Sie ist der einzige verlässliche
                // Weg, eine überholte Meldung zu erkennen: Sitzungsmeldungen
                // kommen über verschiedene Verbindungen und dürfen sich
                // unterwegs überholen. Ohne sie machte eine verspätete
                // „läuft"-Meldung einen bereits gezählten Absturz wieder
                // rückgängig.
                $table->unsignedBigInteger('seq')->default(0);

                // Das Zeitfenster, in dem die Sitzung **begonnen** hat, und
                // damit das Fenster, in dem sie gezählt wird. Es steht hier,
                // weil es sich nicht ändern darf: ein Absturz nach zwei Stunden
                // gehört zu der Sitzung, die vor zwei Stunden begann — sonst
                // stünde in einem Fenster ein Absturz mehr als Sitzungen.
                $table->timestamp('bucket_start');

                $table->timestamp('started_at');
                $table->timestamp('last_seen_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('release_session_counts')) {
            Schema::create('release_session_counts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->foreignId('release_id')->constrained()->cascadeOnDelete();

                // Nicht `nullable`, aus demselben Grund wie bei den
                // Antwortzeiten: zwei `NULL` gelten in MySQL im eindeutigen
                // Index als verschieden, und die Zeile „ohne Umgebung" entstünde
                // bei jeder Sitzung neu.
                $table->string('environment', Environment::NAME_LIMIT);

                // Anfang des Zeitfensters, in derselben Auflösung wie die
                // Antwortzeiten ({@see ReleaseSessionCount::BUCKET_SECONDS}).
                // Dieselbe Rasterung, damit sich Gesundheit und Antwortzeiten
                // über denselben Zeitraum vergleichen lassen — und weil die
                // gebündelten Sitzungen ohnehin minutenweise ankommen.
                $table->timestamp('bucket_start');

                foreach (ReleaseSessionCount::COUNTER_COLUMNS as $column) {
                    $table->unsignedBigInteger($column)->default(0);
                }

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('release_session_users')) {
            Schema::create('release_session_users', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->foreignId('release_id')->constrained()->cascadeOnDelete();
                $table->string('environment', Environment::NAME_LIMIT);
                $table->timestamp('bucket_start');
                $table->string('user_key', TransactionUserAggregate::KEY_LENGTH);

                // Dieselben vier Zähler wie nebenan, und das ist keine
                // Doppelung: dort steht „wie viele Sitzungen sind abgestürzt",
                // hier „wie oft ist es **diesem** Menschen passiert". Gezählt
                // wird später über den Schlüssel (`count(distinct user_key)`),
                // die Zähler entscheiden nur, ob jemand als betroffen gilt.
                foreach (ReleaseSessionCount::COUNTER_COLUMNS as $column) {
                    $table->unsignedBigInteger($column)->default(0);
                }

                $table->timestamps();
            });
        }

        // Die Indizes außerhalb der Definitionen, aus demselben Grund wie bei
        // den Antwortzeiten: scheitert ein Lauf zwischen Tabelle und Index,
        // bliebe die Tabelle sonst für immer ohne den eindeutigen Schlüssel —
        // und das Fortschreiben hängt an ihm, weil das `insertOrIgnore` ohne
        // ihn bei jeder Sitzung eine weitere Zeile anlegte, statt die
        // bestehende zu finden.
        $this->addIndexes('release_sessions', [
            // Der Schlüssel, unter dem eine Folgemeldung ihre Sitzung findet.
            'release_sessions_sid_unique' => fn (Blueprint $table) => $table->unique(
                ['project_id', 'sid'],
                'release_sessions_sid_unique',
            ),

            // Für das Aufräumen alter Sitzungen: was lange nichts mehr von sich
            // hören ließ, wird nicht mehr fortgeschrieben und kostet nur Platz.
            'release_sessions_project_id_last_seen_at_index' => fn (Blueprint $table) => $table->index(
                ['project_id', 'last_seen_at'],
            ),
        ]);

        $this->addIndexes('release_session_counts', [
            // Von Hand benannt: aus Tabelle und vier Spalten gebildet wäre der
            // Name länger als die 64 Zeichen, die MySQL zulässt.
            'release_session_counts_window_unique' => fn (Blueprint $table) => $table->unique(
                ['project_id', 'release_id', 'environment', 'bucket_start'],
                'release_session_counts_window_unique',
            ),

            // Die Schwellwert-Alarme (A3) lesen ein schmales Zeitfenster je
            // Projekt — über alle Versionen, weil „stürzt gerade mehr ab als
            // sonst" keine Frage an eine einzelne Auslieferung ist.
            'release_session_counts_project_id_bucket_start_index' => fn (Blueprint $table) => $table->index(
                ['project_id', 'bucket_start'],
            ),
        ]);

        $this->addIndexes('release_session_users', [
            'release_session_users_window_unique' => fn (Blueprint $table) => $table->unique(
                ['project_id', 'release_id', 'environment', 'bucket_start', 'user_key'],
                'release_session_users_window_unique',
            ),

            // Die Verbreitung einer Version ({@see ReleaseSessionUser}) fragt
            // „wie viele Nutzer waren im Zeitraum überhaupt unterwegs" — über
            // alle Versionen des Projekts.
            'release_session_users_project_id_bucket_start_index' => fn (Blueprint $table) => $table->index(
                ['project_id', 'bucket_start'],
            ),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('release_session_users');
        Schema::dropIfExists('release_session_counts');
        Schema::dropIfExists('release_sessions');
    }

    /**
     * Legt die fehlenden Indizes einer Tabelle an — und nur die.
     *
     * @param  array<string, callable(Blueprint): mixed>  $indexes
     */
    private function addIndexes(string $table, array $indexes): void
    {
        $missing = array_filter(
            $indexes,
            fn (string $name): bool => ! Schema::hasIndex($table, $name),
            ARRAY_FILTER_USE_KEY,
        );

        if ($missing === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($missing) {
            foreach ($missing as $definition) {
                $definition($blueprint);
            }
        });
    }
};

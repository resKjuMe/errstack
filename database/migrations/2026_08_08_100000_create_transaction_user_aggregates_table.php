<?php

use App\Models\Transaction;
use App\Models\TransactionAggregate;
use App\Models\TransactionUserAggregate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die zweite Vorberechnung der Antwortzeiten: welche **Nutzer** eine
     * Transaktion in einem Zeitfenster hatte und wie viele davon zu lange warten
     * mussten.
     *
     * Warum das nicht in {@see TransactionAggregate} steht: dort ist der
     * Schlüssel die eigentliche Entscheidung, und jedes weitere Merkmal darin
     * vervielfacht die Zeilen. Name, Operation und Umgebung ergeben je Minute
     * eine Handvoll Zeilen; der Nutzer dazu multipliziert das mit der Zahl der
     * Besucher — aus 1440 Zeilen am Tag würden bei tausend Nutzern 1,44
     * Millionen. Deshalb trägt das Aggregat bewusst keine Nutzer-Dimension, und
     * deshalb ist „wie viele Nutzer sind betroffen" eine eigene Tabelle.
     *
     * Die Alternative wäre, die Frage aus den Einzelmessungen zu beantworten:
     * `COUNT(DISTINCT user_identifier)` über `transactions`. Das ist genau der
     * Vollscan über Millionen Zeilen, den die Vorberechnung vermeiden soll — die
     * Übersicht hätte damit eine Kennzahl, deren Kosten mit dem Verkehr wachsen,
     * während alle anderen es nicht tun.
     *
     * Hier wächst die Zeilenzahl dagegen nur mit der Zahl der **wiederkehrenden**
     * Nutzer: wer in einer Minute zehn Seiten derselben Transaktion aufruft, ist
     * eine Zeile mit `transaction_count = 10`.
     *
     * Jeder Schritt prüft, ob es ihn schon gibt — aus demselben Grund wie in
     * `2026_08_07_230000_create_performance_tables.php`: MySQL kann DDL nicht
     * zurückrollen. Ein Abbruch zwischen Tabelle und Index lässt die Tabelle
     * ohne Eintrag in `migrations` stehen, und der nächste Lauf beginnt wieder
     * von vorn. Die Prüfungen machen den zweiten Lauf zu dem, was er sein soll:
     * er ergänzt, was fehlt.
     */
    public function up(): void
    {
        $this->createIfMissing('transaction_user_aggregates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Nicht `nullable`, aus demselben Grund wie im Aggregat: in MySQL
            // gelten zwei `NULL` in einem eindeutigen Index als verschieden. Die
            // Zeile „ohne Umgebung" entstünde bei jeder Messung neu und würde
            // nichts zusammenfassen.
            $table->string('environment', 64);

            $table->string('name', Transaction::NAME_LIMIT);

            // Leere Zeichenkette statt `null` — ein Wert, der sich mit sich
            // selbst vergleichen lässt (siehe `transaction_aggregates`).
            $table->string('op', Transaction::OP_LIMIT)->default('');

            // Dasselbe Minutenraster wie das Aggregat
            // ({@see Transaction::BUCKET_SECONDS}). Beide Vorberechnungen müssen
            // dieselben Fenster benutzen, sonst zählt die Übersicht für einen
            // Zeitraum Messungen und Nutzer aus verschiedenen Ausschnitten.
            $table->timestamp('window_start');

            // Die Kennung des Nutzers als Hash, nicht im Klartext. Hier wird
            // ausschließlich **gezählt** und nie angezeigt; für die Frage „wie
            // viele Nutzer" genügt ein Wert, der sich mit sich selbst
            // vergleichen lässt. Die lesbare Kennung steht weiterhin an der
            // Transaktion — dort, wo sie zur Einzelfall-Analyse gebraucht wird
            // und wo das Aufräumen alter Rohdaten sie wieder entfernt.
            //
            // 40 der 64 Hex-Zeichen: 160 Bit sind für das Auszählen von Nutzern
            // weit jenseits jeder Verwechslungsgefahr, und die Spalte steht in
            // jedem `COUNT(DISTINCT …)` der Übersicht — kürzer ist dort billiger.
            $table->char('user_key', 40);

            // Der eindeutige Schlüssel in einem Wert. Ein Index über
            // `environment + name + op + user_key` wäre in utf8mb4 rund 1,6 KB
            // breit: nah an der Grenze von 3072 Byte und bei jedem Schreibvorgang
            // teuer. Der Hash darüber ist 64 Zeichen lang, vergleicht sich in
            // fester Zeit und trägt dieselbe Aussage.
            $table->char('signature', 64);

            // Wie oft dieser Nutzer diese Transaktion in diesem Fenster
            // ausgelöst hat. Für die Nutzerzahl selbst ist die Zahl belanglos —
            // gebraucht wird sie, um „ein Nutzer, viele Aufrufe" von „viele
            // Nutzer" zu unterscheiden, ohne dafür die Messungen zu befragen.
            $table->unsignedBigInteger('transaction_count')->default(0);

            // Wie viele dieser Aufrufe über der Unzufriedenheits-Schwelle lagen
            // ({@see TransactionUserAggregate::record()}). Gezählt wird beim
            // Aufnehmen, nicht beim Auswerten: eine später geänderte Schwelle
            // bewertet Altdaten deshalb **nicht** rückwirkend um. Der Preis ist
            // Absicht — die Alternative wäre, die Dauer je Nutzer und Fenster
            // aufzubewahren, und damit wäre die Tabelle wieder so groß wie die
            // Messungen selbst.
            $table->unsignedBigInteger('miserable_count')->default(0);

            // Ohne Bruchteile, wie im Aggregat: `window_start` ist auf die Minute
            // abgeschnitten und steht im eindeutigen Schlüssel.
            $table->timestamps();
        });

        // Die Indizes stehen außerhalb der Tabellendefinition, damit ein Lauf,
        // der genau dazwischen abgebrochen ist, sie nachträgt statt sie für
        // immer fehlen zu lassen. An ihnen hängt mehr als die Geschwindigkeit:
        // {@see TransactionUserAggregate::record()} zählt über den eindeutigen
        // Schlüssel hoch und legt ohne ihn bei jeder Messung eine weitere Zeile
        // an, statt die bestehende zu finden.
        $this->addIfMissing('transaction_user_aggregates', [
            // Ein Nutzer je Transaktion und Fenster. Der Name ist von Hand
            // gesetzt und kurz gehalten: aus Tabelle und Spalten gebildet wäre er
            // deutlich länger als die 64 Zeichen, die MySQL für einen Bezeichner
            // zulässt.
            'transaction_user_aggregates_window_unique' => fn (Blueprint $table) => $table->unique(
                ['project_id', 'window_start', 'signature'],
                'transaction_user_aggregates_window_unique',
            ),

            // Der Zugriff der Übersicht: ein Zeitraum je Projekt, danach
            // gruppiert nach Name und Operation.
            'transaction_user_aggregates_window_index' => fn (Blueprint $table) => $table->index(
                ['project_id', 'window_start'],
                'transaction_user_aggregates_window_index',
            ),
        ]);
    }

    /**
     * Legt eine Tabelle an, wenn sie noch fehlt.
     */
    private function createIfMissing(string $table, Closure $definition): void
    {
        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, $definition);
    }

    /**
     * Ergänzt die Indizes, die unter ihrem Namen noch nicht stehen.
     *
     * Geprüft wird der Name und nicht die Spaltenliste: unter demselben Namen
     * darf es einen Index nur einmal geben, und genau daran scheitert ein
     * zweiter Lauf.
     *
     * @param  array<string, Closure(Blueprint): mixed>  $indexes
     */
    private function addIfMissing(string $table, array $indexes): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $missing = array_filter(
            $indexes,
            fn (string $name): bool => ! Schema::hasIndex($table, $name),
            ARRAY_FILTER_USE_KEY,
        );

        if ($missing === []) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($missing) {
            foreach ($missing as $definition) {
                $definition($table);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_user_aggregates');
    }
};

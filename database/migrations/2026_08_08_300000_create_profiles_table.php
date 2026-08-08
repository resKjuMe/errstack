<?php

use App\Models\Profile;
use App\Models\Transaction;
use App\Support\Profiling\CallTree;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Ablage der Laufzeitmessungen: welche Code-Stellen die Rechenzeit
     * verbraucht haben.
     *
     * Eine Tabelle, nicht drei. Die naheliegende Aufteilung — Profil, Rahmen,
     * Aufrufbaum je als eigene Tabelle — wäre hier die falsche: der Aufrufbaum
     * eines einzigen Profils hat je nach Anwendung einige tausend Knoten, und
     * er wird **immer** als Ganzes gelesen. Ein Flamegraph, der die Hälfte
     * seiner Knoten zeigt, ist kein halber Flamegraph, sondern eine falsche
     * Aussage darüber, wo die Zeit hingeht. Normalisiert wären das ein paar
     * tausend Zeilen je Seitenaufruf, um daraus dieselbe verschachtelte Form
     * wieder zusammenzusetzen, die hier schon steht.
     *
     * Warum die Messung **neben** der Transaktion liegt und nicht in ihr: sie
     * kommt als eigenes Envelope-Element, oft deutlich später, und nur für einen
     * kleinen Teil der Aufrufe — Profiling ist im SDK eine eigene Quote unter
     * der ohnehin schon gesiebten Transaktionsquote. Eine Spalte an
     * `transactions` wäre bei über 99 % der Zeilen leer und müsste bei jeder
     * Auswertung mitgelesen werden.
     *
     * Ohne Transaktion gibt es hier keine Zeile ({@see Profile}): ein Profil
     * ohne den Aufruf, den es vermisst, beantwortet keine Frage — es sagt, dass
     * irgendwo Zeit verbraucht wurde, aber nicht wofür. Solche Profile werden
     * verworfen und gezählt.
     */
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Die Messung, zu der dieses Profil gehört. Nicht `nullable`: ein
            // Profil ohne Transaktion wird gar nicht erst abgelegt. Verschwindet
            // die Transaktion (Aufräumen, O2), verschwindet das Profil mit ihr —
            // es ist ohne sie nicht mehr zu deuten.
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();

            // Woher die Zahlen kommen. `nullOnDelete` wie bei der Transaktion:
            // das Aufräumen der Rohdaten greift früher als die Aufbewahrung der
            // Auswertung.
            $table->foreignId('ingest_payload_id')->nullable()->constrained()->nullOnDelete();

            // Die Nummer, unter der das SDK **das Profil** führt — nicht die der
            // Transaktion. Beide sind 32 Hex-Zeichen und sehen gleich aus; sie
            // zu verwechseln hieße, zwei Profile derselben Transaktion für
            // dasselbe zu halten.
            $table->char('profile_id', 32);

            // Der Trace-Zusammenhang, doppelt geführt. Über die Transaktion wäre
            // er zu erreichen — die Trace-Ansicht (PF4) fragt aber genau
            // andersherum: „gibt es zu diesem Ablauf ein Profil?". Ohne die
            // Spalte wäre das ein Join über alle Transaktionen des Ablaufs, nur
            // um am Ende eine Zeile zu finden.
            $table->char('trace_id', 32);

            // Der Name der Transaktion, ebenfalls doppelt geführt. Die
            // zusammengefasste Ansicht („alle Profile von `GET /projects` in
            // diesem Zeitraum") wählt danach aus, und zwar bevor irgendeine
            // Begrenzung greift. Über den Join wäre das ein Durchgang durch die
            // Transaktionen des Zeitraums je Aufruf der Seite.
            $table->string('transaction_name', Transaction::NAME_LIMIT);

            $table->string('platform', 32)->nullable();

            // Umgebung und Version als Text, aus demselben Grund wie bei den
            // Transaktionen: eine versteckte oder gelöschte Umgebung soll ihre
            // Messwerte nicht mitnehmen. Die Version trägt zusätzlich den
            // Vergleich zwischen Releases — ohne sie wäre „ist es mit 1.4
            // langsamer geworden?" nicht zu beantworten.
            $table->string('environment', 64);
            $table->string('release')->nullable();

            // Der Ausführungsstrang, der ausgewertet wurde. Text und nicht Zahl:
            // die SDKs schicken hier alles von `1` über `0x7f...` bis
            // `MainThread`.
            $table->string('thread_id', 64)->nullable();

            $table->timestamp('started_at', 3);

            // Die Zeit, die die Stichproben zusammen abdecken — **nicht** die
            // Dauer der Transaktion. Beide unterscheiden sich: das Profil
            // beginnt nach dem Start des Aufrufs und endet vor seinem Ende, und
            // ein Aufruf, der auf eine Datenbank wartet, verbraucht in dieser
            // Zeit keine Rechenzeit. Der Unterschied ist die eigentliche
            // Auskunft — steht in der Transaktion eine Sekunde und im Profil
            // 40 ms, war es nicht der Code.
            $table->unsignedBigInteger('duration_us');

            $table->unsignedInteger('sample_count')->default(0);

            // Die Rahmen (Funktion, Datei, Zeile) als Tabelle und der Aufrufbaum
            // mit Verweisen darauf — die Form, die {@see CallTree} schreibt und
            // liest. Getrennt, weil derselbe Rahmen in einem Baum hundertfach
            // vorkommt: ausgeschrieben wäre das Vielfache an Platz, und beim
            // Zusammenfassen mehrerer Profile das Vielfache an Vergleichen.
            $table->json('frames');
            $table->json('tree');

            $table->timestamps(3);

            // Dasselbe Profil nur einmal. Wie bei den Transaktionen entscheidet
            // hier die Datenbank und nicht die Doppelerkennung der Verarbeitung:
            // ein erneuter Durchlauf derselben Rohdaten soll die Zeile
            // aktualisieren, nicht eine zweite anlegen.
            $table->unique(['project_id', 'profile_id']);

            // „Gibt es zu dieser Transaktion ein Profil?" — der Weg aus der
            // Detailseite einer Transaktion (PF3) hierher.
            $table->index(['transaction_id', 'started_at']);

            // Die zusammengefasste Ansicht und die Liste: ein Transaktionsname
            // in einem Zeitraum.
            $table->index(['project_id', 'transaction_name', 'started_at']);

            // Die Liste ohne Namensfilter.
            $table->index(['project_id', 'started_at']);

            // Der Weg aus der Trace-Ansicht (PF4). Ohne `project_id` vorneweg,
            // weil ein Ablauf über mehrere Dienste in mehreren Projekten liegt.
            $table->index('trace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};

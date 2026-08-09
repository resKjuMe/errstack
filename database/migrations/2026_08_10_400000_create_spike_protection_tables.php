<?php

use App\Support\Ingest\Spikes\SpikeBaseline;
use App\Support\Ingest\Spikes\SpikeCounter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Der Ausschlag-Schutz (A7): der Verlauf, an dem eine Spitze überhaupt erst
     * als Spitze erkennbar ist, und die Auslösungen selbst.
     *
     * **`ingest_volumes`** ist die Aufnahmemenge je Projekt und Minute. Ohne
     * diesen Verlauf bliebe nur ein fester Absolutwert, und der ist für jedes
     * Projekt der falsche: zehntausend Ereignisse je Minute sind bei der einen
     * Anwendung der Normalbetrieb und bei der anderen der Vorfall, den man
     * abstellen will.
     *
     * Eine Zeile je Projekt und Minute, geschrieben vom minütlichen Durchlauf —
     * **nicht** je Ereignis. Gezählt wird währenddessen im Zwischenspeicher
     * ({@see SpikeCounter}); käme hier ein
     * Schreibvorgang je Ereignis hinzu, wäre ausgerechnet der Schutz gegen die
     * Flut ihr größter Verstärker.
     *
     * **`spike_protection_states`** ist eine Zeile je Auslösung: seit wann
     * gedrosselt wird, wogegen gemessen wurde, wie viel dabei verworfen wurde
     * und wer die Drosselung ggf. von Hand aufgehoben hat. Die offene Zeile
     * (`ended_at` ist leer) ist zugleich der laufende Zustand — kein zweites
     * Feld am Projekt, das mit ihr auseinanderlaufen könnte.
     *
     * **Am Projekt** stehen nur die Einstellungen: an/aus, wie weit über dem
     * Verlauf eine Spitze anfängt, ab welcher Menge überhaupt gedrosselt wird
     * und wie lange nach einem Aufheben Ruhe ist.
     */
    public function up(): void
    {
        Schema::create('ingest_volumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Angefangene Minute. Feiner als bei den Verwerfungen (dort genügt
            // die Stunde), weil eine Fehlerflut in Minuten gemessen wird: eine
            // fehlerhafte Auslieferung erzeugt ihre Millionen Meldungen nicht
            // gleichmäßig über eine Stunde verteilt.
            $table->dateTime('bucket');

            $table->unsignedBigInteger('quantity')->default(0);

            // Wurde in dieser Minute gedrosselt? Die Angabe ist nicht Statistik,
            // sondern Rechenvorschrift: gedrosselte Minuten bleiben bei der
            // Bildung des Verlaufswerts außen vor
            // ({@see SpikeBaseline}). Sonst hübe eine
            // lange Spitze ihren eigenen Vergleichswert an, bis sie als normal
            // gilt — und der Schutz schaltete sich selbst ab.
            $table->boolean('throttled')->default(false);

            $table->timestamps();

            // Der Verlauf eines Projekts, rückwärts gelesen — die einzige
            // Abfrage auf dieser Tabelle. Eindeutig, weil je Projekt und Minute
            // genau eine Zeile entsteht: der Durchlauf schreibt sie einmal, und
            // ein zweiter Schreibversuch derselben Minute ist ein doppelt
            // gelaufener Zeitplan und keine zweite Messung.
            $table->unique(['project_id', 'bucket']);
        });

        Schema::create('spike_protection_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->dateTime('started_at');

            // Leer heißt: läuft noch. Damit ist die Frage „wird dieses Projekt
            // gerade gedrosselt?" eine Zeile und keine Rechnung.
            $table->dateTime('ended_at')->nullable();

            // Wogegen gemessen wurde, als es losging: der Verlaufswert je
            // Minute und die daraus gebildete Schwelle. Beide festgehalten und
            // nicht später neu gerechnet — wer hinterher fragt, warum
            // ausgerechnet dann gedrosselt wurde, bekommt sonst die Zahlen von
            // heute statt die von damals.
            $table->decimal('baseline', 12, 2)->default(0);
            $table->unsignedBigInteger('threshold')->default(0);

            // Die höchste in einer Minute beobachtete Menge und die Summe des
            // Verworfenen. Beide werden vom minütlichen Durchlauf
            // fortgeschrieben, nicht je Ereignis.
            $table->unsignedBigInteger('peak')->default(0);
            $table->unsignedBigInteger('discarded')->default(0);

            // Wer von Hand aufgehoben hat. Leer bei einer Drosselung, die von
            // selbst endete, weil die Menge zurückging.
            $table->foreignId('released_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('released_at')->nullable();

            $table->timestamps();

            // „Läuft hier gerade etwas?" und „was war zuletzt?" — dieselbe
            // Spaltenfolge beantwortet beides.
            $table->index(['project_id', 'started_at']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('spike_protection_enabled')->default(false)->after('digest_max_events');

            // Ab dem Wievielfachen des Verlaufswerts eine Minute als Spitze
            // gilt. Zwei Nachkommastellen, weil zwischen „dreifach" und
            // „vierfach" bei einer belebten Anwendung Welten liegen und
            // jemand 3,5 einstellen können muss.
            $table->decimal('spike_threshold_factor', 5, 2)->default(5);

            // Untergrenze, unterhalb derer nie gedrosselt wird. Sie ist der
            // Schutz des Schutzes: bei einem Verlaufswert von zwei Ereignissen
            // je Minute wäre das Fünffache zehn — und ein ruhiges Projekt mit
            // einem kurzen Ausschlag stünde sofort in der Drosselung.
            $table->unsignedInteger('spike_minimum_events')->default(500);

            // Wie lange nach einem Aufheben von Hand Ruhe ist. Ohne diese Frist
            // wäre der Knopf wirkungslos: die Flut läuft ja weiter, und die
            // nächste Minute löste sofort wieder aus.
            $table->unsignedSmallInteger('spike_release_minutes')->default(15);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'spike_protection_enabled',
                'spike_threshold_factor',
                'spike_minimum_events',
                'spike_release_minutes',
            ]);
        });

        Schema::dropIfExists('spike_protection_states');
        Schema::dropIfExists('ingest_volumes');
    }
};

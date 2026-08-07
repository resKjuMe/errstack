<?php

use App\Enums\ProcessingState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Der Verarbeitungsstand jeder angenommenen Meldung.
     *
     * Er steht bewusst an der Meldung und nicht in der Warteschlange. Die
     * Warteschlange kennt Jobs, keine Meldungen: nach einem erfolgreichen Lauf
     * ist der Job weg, nach einem endgültig gescheiterten liegt in
     * `failed_jobs` ein serialisierter Job — aus dem sich weder ablesen lässt,
     * welche Meldungen betroffen sind, noch, wie viel insgesamt noch aussteht.
     * Genau das sind aber die Fragen im Betrieb.
     *
     * Die Spalten im Einzelnen:
     *
     *   processing_state — wo die Meldung steht ({@see ProcessingState}).
     *   processed_at     — wann sie den Wartezustand verlassen hat, gleich mit
     *                      welchem Ausgang. Zusammen mit `created_at` ergibt
     *                      das die Liegezeit inklusive Wartezeit, während
     *                      `duration_ms` nur die reine Rechenzeit misst.
     *   duration_ms      — wie lange die Kette gebraucht hat. Ohne diesen Wert
     *                      wäre nur zu sehen, dass der Rückstand wächst, aber
     *                      nicht, ob das an der Menge oder an einem langsam
     *                      gewordenen Schritt liegt.
     *   attempts         — der wievielte Anlauf zum Ergebnis geführt hat. Eine
     *                      Meldung, die erst im vierten Versuch durchgeht, ist
     *                      kein Fehler, aber ein Hinweis.
     *   failure          — die Fehlermeldung des letzten Versuchs, damit die
     *                      Suche nach der Ursache nicht in den Protokolldateien
     *                      beginnen muss.
     */
    public function up(): void
    {
        Schema::table('ingest_payloads', function (Blueprint $table) {
            $table->string('processing_state', 16)->default(ProcessingState::Pending->value);
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('failure')->nullable();

            // Die beiden Fragen des Betriebs in einem Index: „wie viel liegt
            // noch an?" (Zählung über den Zustand) und „seit wann liegt das
            // Älteste?" (kleinstes `created_at` im selben Zustand). Ohne die
            // zweite Spalte wäre für die Altersfrage jede wartende Zeile zu
            // lesen — und die sind bei einem Rückstand gerade viele.
            $table->index(['processing_state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ingest_payloads', function (Blueprint $table) {
            $table->dropIndex(['processing_state', 'created_at']);

            $table->dropColumn([
                'processing_state',
                'processed_at',
                'duration_ms',
                'attempts',
                'failure',
            ]);
        });
    }
};

<?php

use App\Enums\IssueStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Rückfall (S8): ein erledigter Fehler, der wieder aufgetreten ist.
 *
 * Zwei Spalten an `issues` und kein eigener Zustand: „wieder aufgetreten" ist
 * keine vierte Antwort auf „woran ist der Eintrag gerade?" — er ist **offen**,
 * genau wie ein Fehler, den jemand von Hand wieder aufgemacht hat. Ein vierter
 * Fall in {@see IssueStatus} müsste deshalb überall dort, wo heute
 * „offen" steht, mitgeführt werden — in der Liste, in den Ansichten, in den
 * Alarm-Filtern —, und jede vergessene Stelle ließe einen zurückgekehrten
 * Fehler aus der Arbeitsliste fallen. Die Marke daneben kostet stattdessen
 * genau die Stellen, die sie auch anzeigen wollen.
 *
 * **Der Zeitpunkt ist zugleich die Marke.** `regressed_at` beantwortet „ist er
 * zurückgekommen?" und „wann?" in einer Spalte; ein Schalter daneben wäre eine
 * zweite Wahrheit, die auseinanderlaufen kann. Gelöscht wird sie beim nächsten
 * Zustandswechsel von Hand (erledigen, stummschalten, wieder öffnen) — der
 * Rückfall ist damit abgehandelt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            // Wann der Eintrag von selbst wieder aufgegangen ist. `null` heißt
            // „kein Rückfall" — auch bei einem Fehler, den jemand von Hand
            // wieder geöffnet hat: das ist eine Entscheidung und keine
            // Beobachtung.
            $table->timestamp('regressed_at')->nullable()->after('ignore_users_seen');

            // Die Version, in der er zurückkam. Der Verlauf nennt sie, und ohne
            // sie wäre der Vermerk „wieder aufgetreten" die halbe Auskunft: die
            // Frage danach lautet immer „wo denn?".
            //
            // `nullOnDelete` und nullable, weil eine Meldung ohne
            // Versionsangabe der Regelfall ist ({@see RecordRelease}) — ein
            // Rückfall ohne Version ist trotzdem einer.
            $table->foreignId('regressed_in_release_id')->nullable()->after('regressed_at')
                ->constrained('releases')->nullOnDelete();

            // Die Ansicht „Wieder aufgetreten" (S5) ist `is:regressed` über ein
            // Projekt — dieselbe Form wie die übrigen Listenabfragen, und
            // deshalb derselbe zusammengesetzte Index.
            $table->index(['project_id', 'regressed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'regressed_at']);
            $table->dropForeign(['regressed_in_release_id']);
            $table->dropColumn(['regressed_at', 'regressed_in_release_id']);
        });
    }
};

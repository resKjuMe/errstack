<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Bündelung von Benachrichtigungen (A6).
     *
     * Drei Dinge entstehen, und jedes beantwortet eine eigene Frage:
     *
     * **`notification_digest_entries`** — der Wartekorb. Eine Meldung, die
     * gebündelt werden soll, geht nicht sofort hinaus, sondern legt sich hier
     * ab und wartet auf ihre Nachbarn. Sie trägt ihre fertige Nutzlast mit
     * sich (dieselbe Form wie im Zustellprotokoll), damit der spätere Versand
     * nichts nachladen muss — und damit eine Sammelnachricht auch dann noch
     * vollständig ist, wenn der Fehler-Eintrag inzwischen aufgeräumt wurde.
     *
     * **Die Grenzen am Projekt** — ein Fenster von null Minuten heißt „nicht
     * bündeln", und das ist die Vorgabe. Ein bestehendes Projekt darf durch
     * diese Aufgabe nicht plötzlich anders benachrichtigen als gestern; wer
     * bündeln will, sagt es.
     *
     * **`digest_enabled` am Nutzer** — die Gegenrichtung: das Projekt legt
     * fest, ob gebündelt wird, der Einzelne darf für sich widersprechen. Die
     * Vorgabe ist deshalb `true` und nicht `false` — sonst wäre die Einstellung
     * am Projekt wirkungslos, bis jeder Empfänger sie einzeln bestätigt hat.
     */
    public function up(): void
    {
        Schema::create('notification_digest_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Der Korb wird je Projekt gebildet: „zehn Meldungen" ist erst dann
            // eine brauchbare Auskunft, wenn dabeisteht, woher sie kommen.
            // Nullable, weil nicht jede Meldung ein Projekt hat (eine Warnung
            // zum Kontingent gehört der Organisation).
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('event_type', 32);
            $table->json('payload');

            $table->timestamps();

            // Der Zugriff des Durchlaufs: er sucht die ältesten Einträge und
            // gruppiert danach. Genau diese Reihenfolge steht im Index.
            $table->index(['user_id', 'project_id', 'event_type', 'created_at'], 'digest_entries_bucket_index');
            $table->index('created_at');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedSmallInteger('digest_window_minutes')->default(0)->after('retention_days');
            $table->unsignedSmallInteger('digest_min_events')->default(2)->after('digest_window_minutes');
            $table->unsignedSmallInteger('digest_max_events')->default(25)->after('digest_min_events');
        });

        Schema::table('notification_settings', function (Blueprint $table) {
            $table->boolean('digest_enabled')->default(true)->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_digest_entries');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['digest_window_minutes', 'digest_min_events', 'digest_max_events']);
        });

        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropColumn('digest_enabled');
        });
    }
};

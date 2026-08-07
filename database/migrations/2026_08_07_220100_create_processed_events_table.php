<?php

use App\Support\Ingest\EnvelopeIntake;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wer die Auswertung einer Ereignis-Nummer für sich beansprucht hat.
     *
     * Doppelte Zustellungen sind der Normalfall, kein Ausnahmefall: bleibt
     * unsere Antwort unterwegs, schickt ein SDK dieselbe Meldung erneut, und
     * beim Wiederanlauf einer Warteschlange kann derselbe Job ein zweites Mal
     * ausgeliefert werden. Ohne diese Tabelle stünde derselbe Absturz danach
     * zweimal in der Statistik — und die Zahlen eines Fehler-Werkzeugs, denen
     * man nicht trauen kann, sind wertlos.
     *
     * Warum eine eigene Tabelle und nicht ein eindeutiger Index auf
     * `ingest_payloads`: dort würde die zweite Zustellung schon bei der
     * **Annahme** scheitern. Die Annahme soll aber nie an einer Kollision
     * hängen — der Endpunkt antwortet einer Anwendung, in der gerade etwas
     * schiefgeht. Angenommen wird deshalb alles; erst die Verarbeitung
     * entscheidet, was doppelt ist.
     *
     * Der Schlüssel ist bewusst dreiteilig:
     *
     *   project_id — dieselbe Nummer in zwei Projekten sind zwei Ereignisse.
     *                SDK-Nummern sind Zufallswerte, aber die Trennung der
     *                Projekte darf nicht von deren Qualität abhängen.
     *   event_id   — die Nummer der Meldung, vereinheitlicht abgelegt.
     *   type       — ein Anhang trägt die Nummer der Meldung, zu der er gehört
     *                ({@see EnvelopeIntake}). Ohne den Typ
     *                im Schlüssel würde der Anhang als Doppel seiner eigenen
     *                Fehlermeldung gelten und verschwinden.
     */
    public function up(): void
    {
        Schema::create('processed_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->char('event_id', 32);
            $table->string('type', 32);

            // Welche Meldung den Anspruch hält. Daran erkennt ein
            // Wiederholungsversuch seinen eigenen Eintrag wieder und arbeitet
            // weiter, statt sich selbst für ein Doppel zu halten.
            //
            // `cascadeOnDelete`: räumt das Aufräumen alter Rohdaten (O2) eine
            // Meldung weg, ist der Anspruch gegenstandslos.
            $table->foreignId('ingest_payload_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            // Der eigentliche Zweck der Tabelle: die Datenbank entscheidet, wer
            // zuerst da war. Zwei Arbeiter, die im selben Augenblick dieselbe
            // Nummer beanspruchen, brauchen so keine Absprache — einer bekommt
            // die Zeile, der andere den Verstoß.
            $table->unique(['project_id', 'event_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_events');
    }
};

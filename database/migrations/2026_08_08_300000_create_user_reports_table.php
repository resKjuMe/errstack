<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Was ein Mensch zu einem Fehler zu sagen hat.
     *
     * Alles andere in dieser Anwendung entsteht aus Maschinendaten: Stacktrace,
     * Antwortzeit, Häufigkeit. Diese Tabelle ist die einzige, in der jemand
     * beschreibt, **was er tun wollte** — und das ist regelmäßig die Angabe, die
     * aus einem unverständlichen Stacktrace einen reproduzierbaren Fehler macht.
     *
     * Eine eigene Tabelle und kein Feld am Ereignis, aus drei Gründen:
     *
     *   • Eine Rückmeldung kommt **später** als das Ereignis — Sekunden bis
     *     Tage —, und manchmal gar keins dazu.
     *   • Sie hat einen eigenen Lebenslauf: gelesen, zugewiesen, beantwortet.
     *     Ein Ereignis hat den nicht; es ist eine Messung und ändert sich nie.
     *   • Sie überlebt die Rohdaten. Die Aufbewahrung (O2) räumt Ereignisse und
     *     Belege weg; die Zuschrift eines Kunden dabei mitzuräumen wäre der
     *     Verlust des einzigen Teils, den niemand nachproduzieren kann.
     *
     * **Zum Bezug aufs Ereignis stehen zwei Spalten nebeneinander**, und das ist
     * Absicht: `event_reference` ist die 32-stellige Nummer, die der Absender
     * genannt hat, `event_id` der Verweis auf das Ereignis, das dazu gefunden
     * wurde. Die Nummer bleibt auch dann stehen, wenn kein Ereignis dazu
     * existiert — weil es aussortiert wurde, weil es noch nicht ausgewertet ist,
     * oder weil die Nummer schlicht falsch war. Ohne sie wäre eine Rückmeldung
     * ohne Treffer nicht von einer ohne Bezug zu unterscheiden.
     */
    public function up(): void
    {
        Schema::create('user_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Der Beleg, aus dem diese Zeile entstand. `nullOnDelete` und nicht
            // `cascade`: die Rohdaten sind Wegwerfware mit Ablaufdatum, die
            // Zuschrift nicht. Eindeutig, damit ein zweiter Durchlauf desselben
            // Belegs — die Warteschlange darf einen Job erneut ausliefern —
            // keine zweite Rückmeldung erzeugt.
            $table->foreignId('ingest_payload_id')->nullable()->constrained()->nullOnDelete();
            $table->unique('ingest_payload_id');

            // Die vom Absender genannte Ereignisnummer, in derselben Schreibweise
            // wie `events.event_id`: 32 Zeichen, klein, ohne Bindestriche.
            $table->char('event_reference', 32)->nullable();

            // Das Ereignis dazu, sofern es gefunden wurde — und der Fehler-
            // Eintrag, unter dem es steht. Beide `nullOnDelete`: räumt die
            // Aufbewahrung das Ereignis weg, bleibt die Zuschrift stehen, nur
            // ohne Sprungziel.
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignId('issue_id')->nullable()->constrained()->nullOnDelete();

            // Was die Person angegeben hat. Alles außer dem Text ist freiwillig:
            // eine Rückmeldung ohne Namen ist immer noch eine Rückmeldung, eine
            // ohne Text ist keine.
            $table->string('name', 200)->nullable();
            $table->string('email', 255)->nullable();
            $table->text('comments');

            // Die Seite, auf der die Rückmeldung entstand. Bei einer freien
            // Zuschrift ohne Ereignis ist das oft der einzige Hinweis darauf,
            // wovon überhaupt die Rede ist.
            $table->string('url', 2048)->nullable();

            $table->string('status', 20)->default('new');

            // Wer sich kümmert. `nullOnDelete`: verlässt jemand die
            // Organisation, wird die Rückmeldung wieder herrenlos — und
            // erscheint damit wieder in der Liste derer, die niemand hat.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            // Wann sie bei uns eintraf. Anders als beim Ereignis gibt es keine
            // zweite Uhr: eine Rückmeldung entsteht in dem Moment, in dem
            // jemand auf „Absenden" drückt, und der Zeitpunkt der überwachten
            // Anwendung wäre derselbe.
            $table->timestamp('received_at');

            $table->timestamps();

            // Die Liste eines Projekts, neueste zuerst — die Abfrage der Seite.
            $table->index(['project_id', 'received_at']);

            // Dieselbe Liste, auf einen Bearbeitungsstand eingeschränkt: „was
            // ist neu?" ist die Frage, mit der die Seite morgens aufgeschlagen
            // wird.
            $table->index(['project_id', 'status', 'received_at']);

            // Der Weg von einer Ereignisnummer zu den Rückmeldungen dazu — und
            // der Weg, auf dem eine nachträglich verknüpft wird.
            $table->index(['project_id', 'event_reference']);

            // Die Rückmeldungen an einem Fehler-Eintrag (Detailseite, S2).
            $table->index('issue_id');

            // „Was liegt bei mir?" — die Liste einer einzelnen Person.
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_reports');
    }
};

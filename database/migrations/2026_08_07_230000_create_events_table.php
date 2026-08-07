<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die ausgewertete Meldung: das einheitliche Modell, das aus den Rohdaten
     * entsteht.
     *
     * Warum eine eigene Tabelle neben `ingest_payloads` und nicht ein paar
     * Spalten daneben: die beiden haben verschiedene Lebensdauern und
     * verschiedene Leser. Die Rohdaten sind der Beleg — groß, selten gelesen,
     * nach Ablauf der Aufbewahrung als Erstes weg. Der Datensatz hier ist die
     * Arbeitsfläche: Liste, Suche, Gruppierung, Benachrichtigung greifen
     * ständig darauf zu. In einer Tabelle würde jede Abfrage der Oberfläche
     * über die Megabyte des Belegs hinweglesen.
     *
     * Die Aufteilung der Spalten folgt einer Regel: **wonach gefiltert und
     * sortiert wird, bekommt eine eigene Spalte, alles andere ein JSON-Fach.**
     * Grad, Zeitpunkt, Umgebung und Fassung stehen in jeder Liste und jedem
     * Filter — die in ein JSON-Feld zu legen hieße, für jede Fehlerliste die
     * ganze Tabelle zu lesen. Stacktrace, Anfrage und Spuren dagegen werden
     * immer nur für **eine** Meldung geöffnet, nämlich die gerade angesehene;
     * für sie wären dreißig Spalten nur Ballast.
     *
     * Das Originalereignis bleibt unverändert in `ingest_payloads` liegen
     * (`ingest_payload_id`). Das ist die Zusage, auf der die ganze
     * Verarbeitungskette beruht: wird an einem Schritt etwas geändert, lässt
     * sich alles erneut auswerten. Hätten wir nur dieses Ergebnis, wäre jede
     * Verbesserung rückwirkend wertlos.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Die Rohdaten, aus denen dieser Datensatz entstand. `cascadeOnDelete`,
            // weil das Aufräumen (O2) den Beleg wegräumt — und ein Ergebnis
            // ohne Beleg wäre nicht mehr nachvollziehbar.
            $table->foreignId('ingest_payload_id')->constrained()->cascadeOnDelete();

            // Genau ein ausgewerteter Datensatz je Meldung. Der Index ist nicht
            // nur Ordnung, sondern Schutz: läuft dieselbe Meldung ein zweites
            // Mal durch die Kette — nach einem Fehlschlag, nach einer
            // Verbesserung —, entsteht kein zweiter Datensatz, sondern der
            // erste wird ersetzt.
            $table->unique('ingest_payload_id');

            $table->char('event_id', 32);

            // Was in Listen und Filtern steht.
            $table->string('level', 20)->default('error');
            $table->string('platform', 64)->default('other');
            $table->string('title', 500)->nullable();
            $table->string('culprit', 400)->nullable();
            $table->string('transaction', 400)->nullable();
            $table->string('logger', 200)->nullable();
            $table->string('environment', 200)->nullable();
            $table->string('release', 200)->nullable();
            $table->string('dist', 200)->nullable();
            $table->string('server_name', 200)->nullable();

            // Zwei Zeitpunkte, weil sie verschiedene Fragen beantworten:
            // `occurred_at` ist die Uhr der überwachten Anwendung — danach wird
            // die Zeitleiste gebaut. `received_at` ist unsere — daran hängt die
            // Aufbewahrung. Bei einem SDK, das nach einer Netztrennung seine
            // Warteschlange leert, liegen die beiden Stunden auseinander, und
            // beide Angaben werden dann gebraucht.
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');

            // Die Abschnitte des Sentry-Schemas, jeder in seinem Fach.
            $table->json('message')->nullable();
            $table->json('exceptions')->nullable();
            $table->json('threads')->nullable();
            $table->json('request')->nullable();
            $table->json('user')->nullable();
            $table->json('contexts')->nullable();
            $table->json('breadcrumbs')->nullable();
            $table->json('tags')->nullable();
            $table->json('extra')->nullable();
            $table->json('sdk')->nullable();
            $table->json('modules')->nullable();

            // Was das SDK geschickt hat, ohne dass wir ein Fach dafür haben.
            // Aufgehoben, nicht weggeworfen: Sentry erweitert sein Schema
            // laufend, und ein heute verworfenes Feld fehlt rückwirkend, sobald
            // wir es auswerten wollen.
            $table->json('unknown')->nullable();

            // Was bei der Normalisierung gekürzt oder verworfen wurde. Ohne
            // diese Notiz sähe ein abgeschnittener Stacktrace aus wie ein
            // kurzer — und die Suche nach dem Fehler begänne an der falschen
            // Stelle.
            $table->json('notes')->nullable();

            $table->timestamps();

            // Die Fehlerliste eines Projekts, nach Zeit sortiert — die Abfrage,
            // die in dieser Anwendung häufiger läuft als jede andere.
            $table->index(['project_id', 'occurred_at']);

            // Der Sprung von einer SDK-Nummer zur Meldung: aus einer
            // Fehlerseite der überwachten Anwendung, aus einem Support-Ticket,
            // aus dem Protokoll. Ohne den Index wäre das ein voller Durchlauf.
            $table->index(['project_id', 'event_id']);

            // Filter nach Umgebung und Fassung stehen über jeder Liste; ohne
            // Index kostet „nur Produktion" so viel wie alles.
            $table->index(['project_id', 'environment']);
            $table->index(['project_id', 'release']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};

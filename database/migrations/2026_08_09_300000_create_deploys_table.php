<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Auslieferung selbst — wann eine Version in einer Umgebung landete.
     *
     * Die Version (R1) sagt, **was** gebaut wurde; sie entsteht von selbst aus
     * der ersten Meldung. Wann sie ausgeliefert wurde, geht daraus nicht hervor:
     * zwischen dem Bauen und dem ersten Fehler liegen Stunden, und dieselbe
     * Version geht nacheinander in mehrere Umgebungen. Genau diese beiden
     * Angaben — Zeitpunkt und Umgebung — trägt ein Deploy.
     *
     * Er ist deshalb **nicht** dasselbe wie `releases.released_at`. Das Feld
     * dort ist die eine Angabe „diese Version ging raus" und kann nur einmal
     * stimmen; ein Staging-Deploy würde sie überschreiben und den Zeitpunkt der
     * Produktion damit verlieren. Eine Version hat beliebig viele Deploys, und
     * an jedem hängt seine Umgebung.
     *
     * **Kein eindeutiger Index über (Version, Umgebung).** Zweimal
     * auszuliefern ist keine doppelte Erfassung, sondern zweimal ausgeliefert —
     * nach einem Rollback ist genau das der Normalfall, und die zweite Zeile
     * ist die Auskunft darüber.
     */
    public function up(): void
    {
        Schema::create('deploys', function (Blueprint $table) {
            $table->id();

            // Die drei Bezüge. `project_id` ist aus `release` **und** aus
            // `environment` ableitbar und steht trotzdem hier: die Abfrage
            // hinter den Markierungen in den Verlaufsgrafiken lautet „Deploys
            // dieses Projekts in diesem Zeitraum", und ohne die Spalte wäre sie
            // ein Verbund über zwei Tabellen — je Fehlerliste einmal, für ein
            // paar senkrechte Striche. Dass alle drei zum selben Projekt
            // gehören, stellt die einzige Stelle sicher, die Deploys anlegt
            // (`Deploy::record()`).
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();

            // Die Umgebung ist Pflicht: ein Deploy ohne sie wäre die Aussage
            // „irgendwo ausgeliefert", und an ihr hängt, ob die Produktions-
            // Logik greift (siehe `Deploy::record()`). Fehlt die Angabe beim
            // Anlegen, gilt die Standard-Umgebung des Projekts — ein Nullwert
            // entsteht hier nicht.
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();

            // Der Name der Auslieferung, wie ihn die Bauumgebung vergibt
            // („Build 4711", der Name des Pipeline-Laufs). Reine Beschriftung;
            // wer keinen mitschickt, bekommt in der Anzeige die Umgebung.
            $table->string('name', 200)->nullable();

            // Der Weg zurück in die Bauumgebung — die Adresse des Laufs, der
            // ausgeliefert hat.
            $table->string('url', 500)->nullable();

            // Anfang und Ende der Auslieferung. Der Anfang ist freiwillig und
            // interessiert nur, wer wissen will, wie lange sie dauerte.
            //
            // Das Ende ist der Zeitpunkt, der überall sonst gemeint ist, wenn
            // von „dem Deploy" die Rede ist: die Markierung in den Grafiken,
            // die Reihenfolge der Liste, der Bezugspunkt für „erledigt im
            // nächsten Release". Es ist deshalb Pflicht — wer nichts mitschickt,
            // bekommt den Zeitpunkt des Aufrufs, und der ist bei einer
            // Auslieferungs-Pipeline die richtige Antwort.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at');

            $table->timestamps();

            // Die Deploys einer Version, neueste zuerst — die Liste auf der
            // Detailseite der Auslieferung.
            $table->index(['release_id', 'finished_at']);

            // „Wann wurde in diesem Projekt zuletzt ausgeliefert?" — und mit der
            // Umgebung davor die Frage, die die Markierungen stellen: die
            // Deploys **einer** Umgebung in einem Zeitraum.
            $table->index(['project_id', 'environment_id', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploys');
    }
};

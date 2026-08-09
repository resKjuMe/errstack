<?php

use App\Models\Replay;
use App\Models\ReplaySegment;
use App\Support\Replays\ReplayStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Ablage der Sitzungs-Aufzeichnungen: Sitzung, Abschnitte, Verknüpfung
     * zu Fehlern.
     *
     * Drei Tabellen, und die Aufteilung ist hier ausnahmsweise **nicht** die
     * Frage „wie oft kommt es vor", sondern „was wird zusammen gelesen":
     *
     *   replays          — die Sitzung als Ganzes. Alles, was eine Liste zeigt,
     *                      und alles, wonach gefiltert wird. Diese Zeile ist
     *                      klein und wird oft gelesen.
     *   replay_segments  — je Abschnitt eine Zeile, aber **ohne** die Bilddaten:
     *                      die liegen auf der Platte ({@see ReplayStore}). Hier
     *                      steht nur, wo sie liegen und was in ihnen steckt.
     *   replay_errors    — welche Fehler in dieser Sitzung passiert sind. Eine
     *                      eigene Tabelle und keine Spalte an `events`, siehe
     *                      unten.
     *
     * **Die Bilddaten stehen bewusst nicht in der Datenbank.** Ein Abschnitt von
     * fünf Sekunden wiegt gepackt einige zehn Kilobyte, eine Sitzung von zehn
     * Minuten damit mehrere Megabyte — und gelesen wird das immer als Ganzes und
     * nie durchsucht. In einer Spalte wäre es Ballast an jeder Abfrage, die
     * zufällig danebenliegt, und die Zusage „Aufzeichnungen lassen sich getrennt
     * von den Ereignisdaten löschen" wäre nur noch eine Absichtserklärung.
     */
    public function up(): void
    {
        Schema::create('replays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Die Nummer, unter der das SDK die Sitzung führt — 32 Hex-Zeichen
            // wie eine Ereignis-Nummer. Sie steht im Envelope-Kopf **und** in den
            // Kopfdaten; beide Elemente einer Aufzeichnung finden über sie
            // zueinander, auch wenn sie in verschiedenen Anfragen ankommen.
            $table->char('replay_id', 32);

            // Umgebung und Version als Text, aus demselben Grund wie bei den
            // Transaktionen: eine versteckte oder gelöschte Umgebung soll ihre
            // Aufzeichnungen nicht mitnehmen. Ohne die Spalte könnte die globale
            // Filterleiste hier nicht greifen.
            $table->string('environment', 64);
            $table->string('release')->nullable();
            $table->string('dist', 64)->nullable();
            $table->string('platform', 32)->nullable();
            $table->string('sdk', 128)->nullable();

            // Die erste Seite der Sitzung und die weiteren, in der Reihenfolge
            // des Besuchs. Die erste als eigene Spalte, weil die Liste sie in
            // jeder Zeile zeigt; die übrigen als Liste, weil ihre Zahl von der
            // Sitzung abhängt und niemand danach filtert.
            //
            // `text` und nicht `string`: Adressen mit Suchparametern reißen die
            // 255 Zeichen regelmäßig, und eine gekürzte Adresse ist eine falsche.
            $table->text('url')->nullable();
            $table->json('urls')->nullable();

            // Wer betroffen war, so wie es die Aufnahme nach dem Schwärzen
            // übrig gelassen hat. Ein Feld-Baum und keine Einzelspalten: was ein
            // SDK hier meldet, reicht von einer Kennung bis zu einem halben
            // Profil, und die Anzeige nimmt, was da ist.
            $table->json('user')->nullable();

            // Browser, Betriebssystem und Gerät als Text. Sie stehen auch im
            // Feld-Baum der Kopfdaten; hier stehen sie ein zweites Mal, weil die
            // Liste sie in jeder Zeile zeigt und ein JSON-Zugriff je Zeile in
            // MySQL und SQLite verschieden zu schreiben wäre.
            $table->string('browser', 128)->nullable();
            $table->string('os', 128)->nullable();
            $table->string('device', 128)->nullable();

            // Hat das SDK gemeldet, dass es Texte und Eingaben maskiert hat?
            //
            // Die Vorgabe ist `true`, und das ist keine Beschönigung: maskiert
            // wird im Browser, die Vorgabe des SDK **ist** eingeschaltet, und
            // ältere Fassungen schicken die Angabe gar nicht mit. Ein `false`
            // als Vorgabe hieße, jede Aufzeichnung eines älteren SDK als
            // unmaskiert auszuweisen — eine Warnung, die immer leuchtet, wird
            // nicht gelesen. Gemeldetes `false` wird dagegen übernommen und auf
            // der Abspielseite deutlich gesagt.
            $table->boolean('masked')->default(true);

            // Anfang und Ende. `finished_at` ist `null`, solange noch Abschnitte
            // eintreffen können — ein SDK meldet keinen Schlusspunkt, und wer
            // die Registerkarte schließt, verabschiedet sich nicht. Gesetzt wird
            // es, wenn die Untätigkeitsgrenze überschritten ist
            // (`config('replays.idle_minutes')`).
            $table->timestamp('started_at', 3);
            $table->timestamp('last_segment_at', 3);
            $table->timestamp('finished_at', 3)->nullable();

            // Die Dauer als eigene Spalte statt als Differenz: die Liste
            // sortiert und filtert danach, und `finished_at - started_at` wäre
            // in jeder Datenbank anders zu schreiben.
            $table->unsignedBigInteger('duration_ms')->default(0);

            $table->unsignedInteger('segment_count')->default(0);
            $table->unsignedInteger('event_count')->default(0);

            // Wie viele Fehler in dieser Sitzung passiert sind. Fortgeschrieben
            // und nicht gezählt: die Liste zeigt die Zahl in jeder Zeile, und
            // eine Unterabfrage je Zeile wäre genau der Fall, für den es
            // Zählspalten gibt.
            $table->unsignedInteger('error_count')->default(0);

            // Was die Abschnitte auf der Platte zusammen wiegen. Die Grundlage
            // der Obergrenze je Aufzeichnung — und die einzige Auskunft darüber,
            // was diese Funktion an Speicher kostet.
            $table->unsignedBigInteger('size_bytes')->default(0);

            $table->timestamps(3);

            // Dieselbe Sitzung nur einmal je Projekt. Die Datenbank entscheidet
            // das und nicht die Verarbeitung: Kopfdaten und Abschnitte kommen
            // als eigene Jobs, und zwei davon können im selben Augenblick
            // feststellen, dass es die Zeile noch nicht gibt.
            $table->unique(['project_id', 'replay_id']);

            // Die Liste: Projekte eines Zeitraums, neueste zuerst.
            $table->index(['project_id', 'started_at']);

            // Dieselbe Liste mit gewählter Umgebung — der Regelfall, sobald
            // jemand die Filterleiste benutzt.
            $table->index(['project_id', 'environment', 'started_at']);

            // Das Aufräumen (Aufbewahrungsfrist) sucht projektweise nach dem
            // Alter und braucht dafür keinen Zeitraum, sondern eine Grenze.
            $table->index(['project_id', 'last_segment_at']);
        });

        Schema::create('replay_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('replay_id')->constrained()->cascadeOnDelete();

            // Auch hier das Projekt, obwohl es über die Aufzeichnung zu
            // erreichen wäre: das Aufräumen arbeitet projektweise und soll die
            // Abschnitte nicht über einen Join suchen müssen.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Woher der Abschnitt kam. `nullOnDelete` wie überall: das Aufräumen
            // der Rohdaten greift früher als die Aufbewahrung der Auswertung.
            $table->foreignId('ingest_payload_id')->nullable()->constrained()->nullOnDelete();

            // Die laufende Nummer, die das SDK vergibt. Sie ist die
            // Abspielreihenfolge — nicht der Zeitpunkt des Eintreffens: Abschnitte
            // überholen einander in der Warteschlange, und ein Film, der nach
            // Ankunft sortiert ist, springt.
            $table->unsignedInteger('segment_id');

            // Wo die Bilddaten liegen ({@see ReplaySegment::$path}).
            $table->string('path', 255);

            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('event_count')->default(0);

            // Die Zeitspanne, die dieser Abschnitt abdeckt. Sie kommt aus den
            // Zeitstempeln der enthaltenen rrweb-Ereignisse und nicht aus dem
            // Kopf: der Kopf trägt nur die Nummer.
            $table->timestamp('started_at', 3);
            $table->timestamp('ended_at', 3);

            $table->timestamps(3);

            // Ein Abschnitt je Nummer. Ein zweiter Durchlauf derselben Rohdaten
            // soll die Zeile ersetzen und keine zweite anlegen — sonst stünde
            // dieselbe Sekunde zweimal im Film.
            $table->unique(['replay_id', 'segment_id']);
        });

        Schema::create('replay_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('replay_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Die Nummer der Fehlermeldung — als Text und **nicht** als
            // Fremdschlüssel auf `events`.
            //
            // Drei Gründe, und alle drei zählen:
            //
            //   1. Die Reihenfolge ist offen. Ein Fehler nennt seine
            //      Aufzeichnung, und eine Aufzeichnung nennt ihre Fehler — wer
            //      zuerst ankommt, entscheidet die Warteschlange. Ein
            //      Fremdschlüssel verlangte, dass die andere Seite schon da ist.
            //   2. Die Aufbewahrungsfristen sind verschieden. Aufzeichnungen
            //      werden früher weggeräumt als Ereignisse, und umgekehrt darf
            //      das Aufräumen der Ereignisse (O2) keine Aufzeichnung mitnehmen.
            //   3. Getrennt löschbar heißt getrennt: eine Verknüpfung, die an
            //      `events` hängt, wäre wieder eine Verbindung zwischen zwei
            //      Beständen, die ausdrücklich getrennt bleiben sollen.
            //
            // Der Preis ist ein Verweis, der ins Leere zeigen kann. Die Anzeige
            // rechnet damit und lässt einen aufgeräumten Fehler weg, statt eine
            // Fehlerseite zu werfen.
            $table->char('event_id', 32);

            // Wann der Fehler passiert ist — die Sprungmarke auf der Zeitleiste.
            // Doppelt geführt, weil die Zeitleiste sie für **alle** Fehler einer
            // Sitzung auf einmal braucht und der zugehörige Fehler zu diesem
            // Zeitpunkt noch gar nicht abgelegt sein muss.
            $table->timestamp('occurred_at', 3)->nullable();

            $table->timestamps(3);

            // Derselbe Fehler nur einmal je Aufzeichnung. Beide Seiten dürfen
            // ihn melden — die Aufzeichnung über ihre Fehlerliste, der Fehler
            // über seinen Verweis auf die Aufzeichnung —, und beide dürfen das
            // mehrfach tun.
            $table->unique(['replay_id', 'event_id']);

            // Der Weg von einem Fehler zu seinen Aufzeichnungen: die
            // Fehlerdetailseite hat die Nummer und sucht damit hierher.
            $table->index(['project_id', 'event_id']);
        });
    }

    public function down(): void
    {
        // Die Bilddaten auf der Platte fallen hier **nicht** mit. Das ist kein
        // Versehen: eine Migration zurückzunehmen ist ein Eingriff in das
        // Schema, kein Aufräumen von Nutzdaten, und eine zurückgerollte und
        // erneut ausgeführte Migration soll die Aufzeichnungen nicht vernichtet
        // haben. Wer den Platz zurückwill, wirft den Ordner aus
        // `config('replays.path')` weg — dafür liegt er dort ({@see Replay}).
        Schema::dropIfExists('replay_errors');
        Schema::dropIfExists('replay_segments');
        Schema::dropIfExists('replays');
    }
};

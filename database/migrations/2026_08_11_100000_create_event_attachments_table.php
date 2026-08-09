<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Anhänge einer Meldung: Screenshot, Logdatei, Speicherabbild.
     *
     * **Der Inhalt steht nicht in dieser Tabelle.** Eine Datei wird nur als
     * Ganzes gelesen und ist ein Vielfaches schwerer als die Meldung, an der sie
     * hängt; sie liegt auf einem Laufwerk (`config/attachments.php`), hier steht
     * der Verweis darauf. Der Pfad ist der Prüfsummenpfad: derselbe Screenshot,
     * den ein SDK zu jeder Meldung eines Absturzdialogs mitschickt, belegt damit
     * einmal Platz.
     *
     * **Die Zugehörigkeit steht als Nummer da, nicht als Fremdschlüssel.** Ein
     * Anhang kommt als eigenes Envelope-Element mit eigenem Job und trifft
     * deshalb regelmäßig **vor** der Meldung ein, zu der er gehört — ein
     * Fremdschlüssel auf `events` wäre in genau diesem Regelfall nicht zu setzen.
     * Gesucht wird über Projekt und Nummer, und das ist derselbe Weg, den die
     * Detailseite ohnehin geht: sie hat die Meldung in der Hand und fragt nach
     * ihren Anhängen. Dieselbe Überlegung wie bei `user_reports.event_reference`
     * (M6) — nur ohne das dortige Nachverknüpfen, weil hier niemand von der
     * Datei aus zur Meldung springt.
     *
     * Die Aufbewahrung ist die eigene des Projekts
     * (`projects.attachment_retention_days`) und nicht die der Ereignisse: wer
     * Meldungen ein Jahr behalten will, will nicht ein Jahr Speicherabbilder
     * behalten. Ein Anhang kann seine Meldung deshalb überleben und umgekehrt.
     */
    public function up(): void
    {
        Schema::create('event_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Der Beleg, aus dem diese Zeile entstand. `nullOnDelete` und nicht
            // `cascade`: die Rohdaten sind Wegwerfware mit Ablaufdatum, der
            // Anhang hat seine eigene Frist. Eindeutig, damit ein zweiter
            // Durchlauf desselben Belegs — die Warteschlange darf einen Job
            // erneut ausliefern — keinen zweiten Anhang erzeugt.
            $table->foreignId('ingest_payload_id')->nullable()->constrained()->nullOnDelete();
            $table->unique('ingest_payload_id');

            // Die Meldung, zu der der Anhang gehört, in derselben Schreibweise
            // wie `events.event_id`: 32 Zeichen, klein, ohne Bindestriche.
            $table->char('event_reference', 32);

            // Der Dateiname, wie ihn das SDK im Kopf des Elements mitgeschickt
            // hat. Er wird angezeigt und steht im Download — deshalb ist er beim
            // Ablegen von Pfadanteilen befreit ({@see App\Models\EventAttachment::sanitizeName()}).
            $table->string('name', 255);

            // Der gemeldete Inhaltstyp. `nullable`, weil ihn nicht jedes SDK
            // setzt; was daraus für die Anzeige folgt, steht in `kind`.
            $table->string('content_type', 100)->nullable();

            // Bild, Text oder beliebige Bytes. Abgeleitet und nicht erfragt, aber
            // gespeichert statt bei jeder Anzeige neu bestimmt: an der Spalte
            // hängt die Frage, ob eine Datei überhaupt inline ausgeliefert werden
            // darf, und die soll nicht von der Tagesform einer Aufzählung
            // abhängen.
            $table->string('kind', 20);

            $table->unsignedBigInteger('size');

            // `sha1` des Inhalts: er ist der Ablagepfad und zugleich die Antwort
            // auf „ist das dieselbe Datei wie beim letzten Mal?".
            $table->char('checksum', 40);

            $table->string('path', 300);

            // Wann der Anhang bei uns eintraf — der Eingang der Rohdaten, nicht
            // der Zeitpunkt dieses Durchlaufs: zwischen Annahme und Auswertung
            // liegt die Warteschlange. An dieser Spalte hängt die Aufbewahrung,
            // und die soll nicht davon abhängen, wann ein Arbeiter Zeit hatte.
            $table->timestamp('received_at');

            $table->timestamps();

            // „Die Anhänge dieser Meldung" — die Abfrage der Detailseite und die
            // einzige, die von außen kommt.
            $table->index(['project_id', 'event_reference']);

            // Das Aufräumen: „was ist in diesem Projekt älter als …". Ohne den
            // Index wäre der nächtliche Durchlauf ein vollständiger Tabellenlauf.
            $table->index(['project_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_attachments');
    }
};

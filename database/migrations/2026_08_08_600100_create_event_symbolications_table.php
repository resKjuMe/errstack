<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Rückübersetzung eines Stacktraces — als eigene Zeile neben der
     * Meldung, nicht in ihr.
     *
     * Das ist die zentrale Festlegung dieser Aufgabe: das Ereignis bleibt, wie
     * es ankam. Die Rückübersetzung ist eine **zusätzliche Sicht**, und dafür
     * gibt es zwei Gründe, die beide aus dem Betrieb kommen.
     *
     * Der erste: Quellkarten kommen oft nach den ersten Fehlern. Wer die
     * übersetzten Rahmen in `events.exceptions` schreibt, hat danach Meldungen,
     * die verschieden aussehen, je nachdem wann sie eintrafen — und keine
     * Möglichkeit mehr zu sagen, was das SDK eigentlich gemeldet hat. Der
     * zweite: an den gemeldeten Rahmen hängt die Gruppierung (I5). Ein
     * Fingerabdruck, der sich mit dem Upload einer Quellkarte ändert, spaltet
     * einen laufenden Fehler in zwei.
     *
     * Zugleich ist die Zeile der **Zwischenspeicher**. Eine Rückübersetzung
     * kostet das Einlesen und Zerlegen einer mehrere Megabyte großen Quellkarte;
     * das bei jedem Aufschlagen der Fehlerseite zu tun, wäre die eine Sache, die
     * diese Ansicht langsam machen würde. Gerechnet wird deshalb einmal, im
     * Hintergrund, und danach nur noch gelesen.
     */
    public function up(): void
    {
        Schema::create('event_symbolications', function (Blueprint $table) {
            $table->id();

            // Eine Zeile je Meldung — das ist der Zwischenspeicher-Schlüssel.
            // `cascade`: eine aufgeräumte Meldung nimmt ihre Übersetzung mit,
            // sie hat für sich keinen Wert.
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->unique('event_id');

            // Das Projekt steht daneben, damit sich alle Übersetzungen eines
            // Projekts wegräumen lassen, ohne über die Meldungen zu gehen — das
            // ist der Fall „neue Quellkarten hochgeladen, bitte noch einmal".
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Vollständig übersetzt, teilweise, gar nicht — oder gescheitert.
            // Der Unterschied zwischen „gar nicht" und „gescheitert" ist der
            // zwischen einer Auskunft und einem Fehler: ohne hochgeladene
            // Quellkarte gibt es nichts zu übersetzen, das ist kein Defekt.
            $table->string('status', 20);

            // Die übersetzten Ausnahmen in derselben Form wie
            // `events.exceptions` — dieselbe Form, weil die Anzeige denselben
            // Weg nimmt und ein zweites Format ein zweiter Ort für Fehler wäre.
            $table->json('exceptions')->nullable();

            // Warum ein Rahmen **nicht** übersetzt werden konnte, je Grund
            // einmal samt Anzahl. Das ist der Teil, der im Betrieb zählt: „keine
            // Quellkarte gefunden" ist eine Aussage, ein leerer Stacktrace ist
            // keine.
            $table->json('diagnostics')->nullable();

            // Wie viele Rahmen in Frage kamen und wie viele es wurden. Zwei
            // Zahlen statt einer Quote, damit „3 von 40" nicht als „92 % nicht
            // geschafft" gelesen werden muss.
            $table->unsignedInteger('mapped_frames')->default(0);
            $table->unsignedInteger('total_frames')->default(0);

            // Wie lange die Übersetzung gebraucht hat. Sie ist der teuerste
            // Vorgang dieser Anwendung, der nicht an der Aufnahme hängt — ohne
            // Messung wäre eine Verschlechterung erst zu merken, wenn die
            // Warteschlange steht.
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_symbolications');
    }
};

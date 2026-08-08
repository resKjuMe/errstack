<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kommentare an einem Fehler-Eintrag — und wer darin genannt wurde.
     *
     * **Warum eine eigene Tabelle und nicht eine weitere Art im
     * Aktivitätsverlauf?** Weil die beiden gegensätzliche Zusagen haben. Ein
     * Vermerk im Verlauf ist unveränderlich: „am 3. März um 14:12 hat Anna
     * erledigt" darf sich nie wieder ändern, sonst ist der Verlauf als Beleg
     * wertlos. Ein Kommentar ist das Gegenteil — er wird korrigiert,
     * nachgeschärft und zurückgenommen. Beides in eine Tabelle zu legen hieße,
     * die Unveränderlichkeit für alle aufzugeben, damit eine Art sie nicht
     * braucht.
     *
     * Angezeigt werden sie trotzdem gemeinsam, in **einer** Zeitleiste
     * (App\Support\Issues\IssueActivityFeed): getrennt gespeichert, gemeinsam
     * gelesen.
     *
     * **`issue_comment_mentions` ist kein Zwischenspeicher der Anzeige.** Wer
     * genannt wurde, ließe sich beim Anzeigen jedes Mal neu aus dem Text
     * herauslesen. Gebraucht wird die Tabelle für die Benachrichtigung: beim
     * Bearbeiten eines Kommentars soll **nur** benachrichtigt werden, wer neu
     * hinzugekommen ist. Ohne festgehaltene Nennungen wäre jede Korrektur eines
     * Tippfehlers eine zweite Benachrichtigung an dieselben Leute — und nach
     * drei Korrekturen schaltet der Genannte die Erwähnungen ab.
     */
    public function up(): void
    {
        Schema::create('issue_comments', function (Blueprint $table) {
            $table->id();

            // Der Kommentar geht mit dem Eintrag. Anders als die beiden
            // Löschvermerke im Verlauf gibt es hier nichts, was ohne den Fehler
            // noch eine Frage beantwortet.
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();

            // Das Projekt steht daneben, obwohl es über den Eintrag erreichbar
            // wäre: die Rechteprüfung und das spätere Aufräumen (O2) fragen
            // danach, und beide sollen dafür nicht über eine Verknüpfung gehen.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Das schreibende Konto, solange es existiert.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Der Name zum Zeitpunkt des Schreibens. Dieselbe Überlegung wie am
            // Aktivitätsvermerk: ein gelöschtes Konto darf einen Kommentar nicht
            // namenlos machen, und eine spätere Namensänderung soll nicht
            // rückwirkend gelten.
            $table->string('author_name')->nullable();

            $table->text('body');

            // Wann zuletzt bearbeitet — `null`, solange der Kommentar so
            // dasteht, wie er geschrieben wurde. Nicht aus `updated_at`
            // abgeleitet: das ändert sich auch dann, wenn nur die Nennungen neu
            // aufgelöst wurden, und „bearbeitet" ist eine Aussage an den Leser.
            $table->timestamp('edited_at')->nullable();

            $table->timestamps();

            // Die Zeitleiste eines Fehlers, neueste zuerst.
            $table->index(['issue_id', 'created_at']);
        });

        Schema::create('issue_comment_mentions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('issue_comment_id')->constrained()->cascadeOnDelete();

            // Genannt wird entweder eine Person oder ein Team — genau eines von
            // beiden. Zwei getrennte Tabellen wären die sauberere Modellierung
            // und die unpraktischere: jede Auswertung („wurde hier schon
            // jemand genannt?") müsste beide lesen und zusammenführen.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();

            // Der Text, mit dem genannt wurde — also das, was hinter dem `@`
            // stand. Er ist nicht der heutige Name des Kontos, sondern der
            // damalige, und dient dem Anzeigen: der Server zerlegt den Rumpf
            // beim Lesen anhand dieser Zeichenketten in Text und Nennungen.
            //
            // Der naheliegende Weg wäre gewesen, Anfang und Länge der Fundstelle
            // zu speichern. Er wäre falsch: PHP zählt Bytes, JavaScript zählt
            // UTF-16-Einheiten — bei einem „ä" vor der Nennung stünde die
            // Hervorhebung im Browser um ein Zeichen daneben.
            $table->string('label');

            $table->timestamps();

            // Je Kommentar wird ein Ziel einmal vermerkt, auch wenn es im Text
            // dreimal vorkommt: die Tabelle beantwortet „wurde benachrichtigt",
            // und das ist keine Frage nach der Anzahl der Fundstellen.
            //
            // Dass beide eindeutigen Schlüssel `null` zulassen, ist der Grund,
            // warum sie nebeneinander stehen können: eine Team-Nennung hat kein
            // `user_id`, und mehrere `null` verletzen einen eindeutigen Index in
            // keiner der beiden Datenbanken.
            $table->unique(['issue_comment_id', 'user_id']);
            $table->unique(['issue_comment_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_comment_mentions');
        Schema::dropIfExists('issue_comments');
    }
};

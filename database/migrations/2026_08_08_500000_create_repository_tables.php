<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Woher der Code kommt: Repositories, ihre Commits — und welche davon in
     * welcher Auslieferung stecken.
     *
     * Die Version (R1) sagt bisher nur, **dass** ausgeliefert wurde. Die Frage,
     * die unmittelbar danach kommt, ist **was** — und die lässt sich aus keiner
     * Fehlermeldung beantworten: ein Ereignis kennt seine Versionsangabe, nicht
     * den Inhalt der Auslieferung. Der Inhalt muss von außen kommen, aus dem
     * Repository oder aus der Bauumgebung.
     *
     * Vier Tabellen, und die Aufteilung folgt genau einer Aussage aus der
     * Wirklichkeit: **ein Commit gehört zu genau einem Repository und kann in
     * mehreren Auslieferungen erscheinen.** Das erste ist eine Eigenschaft des
     * Commits (Fremdschlüssel), das zweite eine Beziehung (Zwischentabelle).
     * Ein Commit am Release wäre die naheliegende Vereinfachung und wäre falsch:
     * derselbe Commit steckt in `1.2.0` und in dem Nachzügler `1.2.1`, und in
     * einer Anwendung mit mehreren Projekten aus demselben Repository sowieso.
     *
     * **Nicht hier: die Anbindung selbst.** Wie Commits aus GitHub oder GitLab
     * hereinkommen, ist X1/X2. Diese Tabellen sind der Ort, an dem sie landen —
     * gleich, ob sie eine Anbindung geholt oder eine Bauumgebung über die
     * Schnittstelle geschickt hat.
     */
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();

            // An der Organisation und nicht am Projekt: dasselbe Repository
            // versorgt in aller Regel mehrere Projekte (Server, Browser,
            // Hintergrunddienst), und es je Projekt erneut zu verbinden hieße,
            // dieselben Commits mehrfach zu führen. Die Auslieferung stellt den
            // Bezug zum Projekt her, nicht das Repository.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Der Name, unter dem das Repository beim Anbieter läuft
            // („acme/webshop"). Er ist zugleich die Angabe, die eine
            // Bauumgebung beim Übergeben von Commits mitschickt — deshalb ist
            // er der Schlüssel und keine bloße Beschriftung.
            $table->string('name', 200);

            // Woher es kommt. Ein Freitext und keine Aufzählung: mit jeder
            // Anbindung (X1/X2) kommt ein Wert dazu, und eine Aufzählung in der
            // Datenbank hieße, für jeden davon eine Wanderung zu schreiben.
            // `manual` ist der Fall ohne Anbindung — von Hand verbunden oder
            // beim ersten Übergeben von Commits entstanden.
            $table->string('provider', 50)->default('manual');

            // Die Adresse im Netz, für den Sprung vom Commit zur Ansicht beim
            // Anbieter. Ohne sie bleibt der Commit-Hash eine Zeichenkette.
            $table->string('url', 500)->nullable();

            // Die Kennung beim Anbieter, sobald es eine Anbindung gibt (X1/X2).
            // Sie steht hier und nicht erst dort, weil ein Repository seinen
            // Namen ändern kann, ohne ein anderes zu werden — die spätere
            // Anbindung erkennt es daran wieder.
            $table->string('external_id', 200)->nullable();

            $table->timestamps();

            // Je Organisation ein Repository je Name. Wie bei den Versionen ist
            // der eindeutige Index nicht Ordnung, sondern Verfahren: er
            // entscheidet, wer das Repository anlegt, wenn zwei Bauläufe
            // gleichzeitig ihre Commits übergeben.
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('commits', function (Blueprint $table) {
            $table->id();

            // Der Commit verschwindet mit seinem Repository: er ist ohne dieses
            // keine Aussage mehr. Was an ihm hängt — die Zuordnung zu
            // Auslieferungen — geht denselben Weg (siehe unten).
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();

            // Der Hash. 64 Zeichen, damit auch SHA-256 hineinpasst — Git kann
            // das seit 2.29, und ein abgeschnittener Hash wäre ein anderer
            // Commit.
            $table->string('sha', 64);

            // Die Nachricht, wie sie im Repository steht — vollständig, nicht
            // nur die erste Zeile. Was die Oberfläche davon zeigt, entscheidet
            // sie selbst; hier abzuschneiden hieße, die Begründung einer
            // Änderung wegzuwerfen, und genau die wird bei „welcher Commit war
            // das?" gelesen.
            $table->text('message')->nullable();

            // Der Autor, wie das Repository ihn führt: Name und E-Mail als
            // Zeichenketten. Sie bleiben stehen, auch wenn kein Konto dazu
            // passt — die meisten Commits eines Projekts stammen von Personen,
            // die hier nie ein Konto hatten.
            $table->string('author_name', 200)->nullable();
            $table->string('author_email', 254)->nullable();

            // Und dasselbe noch einmal als Verweis, wenn sich die Adresse einem
            // Konto zuordnen ließ. Das ist die Spalte, wegen der die Zuordnung
            // überhaupt stattfindet: erst mit ihr lässt sich der verdächtige
            // Commit (R4) jemandem zeigen, statt nur eine Adresse zu nennen.
            //
            // `nullOnDelete`: wer sein Konto löscht, löscht keine Commits. Der
            // Verweis fällt weg, Name und Adresse bleiben — der Commit ist
            // Geschichte des Repositories und nicht unsere Aufzeichnung über
            // eine Person.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            // Wann der Commit entstand — nach der Uhr des Repositories, nicht
            // nach unserer Empfangszeit. `nullable`, weil eine Bauumgebung sie
            // nicht mitschicken muss.
            $table->timestamp('committed_at')->nullable();

            $table->timestamps();

            // Ein Commit je Repository und Hash. Derselbe Hash in zwei
            // Repositories sind zwei Zeilen, und das ist richtig so: eine
            // Abspaltung teilt ihre Geschichte, aber nicht ihre Zukunft.
            $table->unique(['repository_id', 'sha']);

            // Die Commit-Liste einer Auslieferung wird nach der Zeit sortiert
            // gelesen, und die Liste eines Repositories ebenso.
            $table->index(['repository_id', 'committed_at']);

            // „Woran hat diese Person zuletzt gearbeitet?" — die Frage hinter
            // der Zuständigkeit (R6) und dem verdächtigen Commit (R4).
            $table->index(['author_id', 'committed_at']);
        });

        Schema::create('commit_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commit_id')->constrained()->cascadeOnDelete();

            // Der Pfad, wie er im Repository steht. Er ist der Grund, dass
            // diese Tabelle überhaupt existiert und die Dateien nicht als Liste
            // am Commit hängen: der verdächtige Commit (R4) entsteht aus dem
            // Vergleich von Stacktrace-Pfaden mit genau dieser Spalte, und das
            // ist eine Abfrage und keine Schleife über entpackte Listen.
            $table->string('path', 500);

            // Hinzugefügt, geändert, gelöscht — die drei Fälle, die auch
            // sentry-cli und die Anbieter kennen (`A`, `M`, `D`). Ein Zeichen
            // und kein Wort, weil genau diese Buchstaben von außen ankommen.
            $table->string('change_type', 1);

            // Kein `timestamps()`: eine Datei eines Commits ändert sich nicht.
            // Sie entsteht mit ihm und vergeht mit ihm — ein Zeitpunkt der
            // Änderung wäre eine Spalte, die nie einen zweiten Wert bekommt.

            // Dieselbe Datei zweimal im selben Commit gibt es nicht. Der Index
            // hält das fest und ist zugleich der Weg, auf dem die Dateiliste
            // eines Commits gelesen wird.
            $table->unique(['commit_id', 'path']);
        });

        Schema::create('release_commit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commit_id')->constrained()->cascadeOnDelete();

            // Die Reihenfolge, in der die Commits übergeben wurden — und damit
            // die, in der sie angezeigt werden.
            //
            // Nicht überflüssig neben `committed_at`: die Zeit eines Commits
            // stammt aus dem Repository und ist dort nicht verlässlich geordnet
            // (Rebase, Cherry-Pick, falsch gestellte Uhr auf einem Bau-Rechner
            // — alle drei erzeugen Commits, deren Zeitstempel ihre Reihenfolge
            // verkehrt herum wiedergeben). Wer die Liste schickt, kennt sie;
            // wir merken sie uns, statt sie neu zu erfinden.
            $table->unsignedInteger('position')->default(0);

            // Kein `timestamps()`: die Zuordnung entsteht beim Übergeben und
            // wird danach nicht mehr angefasst — wer wissen will, wann das war,
            // findet es an der Auslieferung.

            // Derselbe Commit steht nur einmal in derselben Auslieferung. Der
            // Index ist zugleich der Weg für die Commit-Liste einer Version.
            $table->unique(['release_id', 'commit_id']);

            // Die Gegenrichtung: „in welchen Auslieferungen steckt dieser
            // Commit?" — die Frage, die der verdächtige Commit (R4) stellt.
            $table->index('commit_id');
        });
    }

    public function down(): void
    {
        // Von der Beziehung zu den Dingen, sonst hängen Fremdschlüssel in der
        // Luft.
        Schema::dropIfExists('release_commit');
        Schema::dropIfExists('commit_files');
        Schema::dropIfExists('commits');
        Schema::dropIfExists('repositories');
    }
};

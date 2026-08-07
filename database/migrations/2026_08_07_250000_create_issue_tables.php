<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Der Fehler-Eintrag (Issue): das, was jemand ansieht, zuweist und schließt.
     *
     * Die Gruppe (I5) sagt, **welche** Meldungen zusammengehören. Sie sagt
     * nicht, wie oft, seit wann und wen es getroffen hat — das steht hier.
     * Getrennt sind die beiden nicht aus Ordnungsliebe, sondern weil S9 mehrere
     * Fingerabdrücke von Hand zu einem Eintrag zusammenführt und einzelne wieder
     * herauslöst: läge der Zähler an der Gruppe, wäre jedes Zusammenführen ein
     * Umrechnen und jedes Auftrennen ein Verlust.
     *
     * Vier Dinge entstehen:
     *
     * **`issues`** — der Eintrag mit Titel, Fehlerstelle, Grad, Zählern,
     * erstem und letztem Auftreten, Zustand und Priorität.
     *
     * **`issue_counts`** — die Zeitreihe: je Eintrag und Zeitfenster ein Zähler,
     * stündlich und täglich. Sie ist die Grundlage der Verlaufsgrafik und der
     * Alarm-Bedingungen („mehr als 100 mal in einer Stunde"). Über die
     * Einzelereignisse ließe sich das auch rechnen — bei einem Fehler mit einer
     * Million Auftreten allerdings nicht mehr in der Zeit, die eine Seite
     * braucht, um zu erscheinen.
     *
     * **`issue_users`** — wen es getroffen hat, als Streuwert je Nutzer. Nicht
     * um Nutzer zu verfolgen, sondern um sie **zu zählen**: „einer betroffen"
     * und „zehntausend betroffen" sind derselbe Zähler und zwei völlig
     * verschiedene Lagen. Ein `count(distinct ...)` über die Ereignisse wäre die
     * Alternative — und bei jeder Anzeige ein voller Durchlauf über alle
     * Meldungen des Eintrags.
     *
     * **`events.counted_at`** — der Vermerk, dass ein Ereignis gezählt wurde.
     * Er ist der Grund, warum ein zweiter Durchlauf derselben Meldung — nach
     * einem Fehlschlag, nach einer Verbesserung an einem Schritt — die Zähler
     * nicht verdoppelt.
     */
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Was in der Liste steht. Die Werte stammen aus dem Ereignis und
            // haben deshalb dieselben Längen wie dort — ein Titel, der beim
            // Übertragen abgeschnitten wird, wäre in der Liste ein anderer als
            // in der Meldung.
            $table->string('title', 500)->nullable();
            $table->string('culprit', 400)->nullable();

            // Die Art des Fehlers — bei einer Ausnahme deren Klasse
            // („RuntimeException"), sonst leer. Eigene Spalte, weil danach
            // gefiltert und gruppiert wird: „alle TypeError der letzten Woche"
            // ist die Frage, mit der man ein Muster findet.
            $table->string('type', 200)->nullable();

            $table->string('level', 20)->default('error');

            // Zustand und Dringlichkeit. Sie stehen hier und nicht an der
            // Gruppe, weil sie am Eintrag hängen: wer einen Fehler schließt,
            // schließt ihn für alle seine Untergruppen.
            $table->string('status', 20)->default('unresolved');
            $table->string('priority', 20)->default('medium');

            // Die Zähler. `unsignedBigInteger`, weil ein Fehler in einer
            // Anwendung mit hoher Last die Grenze von vier Milliarden erreichen
            // kann, ohne dass dabei irgendetwas ungewöhnlich wäre.
            $table->unsignedBigInteger('times_seen')->default(0);
            $table->unsignedBigInteger('users_seen')->default(0);

            // Erstes und letztes Auftreten nach der Uhr der überwachten
            // Anwendung (`events.occurred_at`) und nicht nach unserer: bei einem
            // SDK, das nach einer Netztrennung seine Warteschlange leert, liegen
            // die beiden Stunden auseinander, und die Frage „seit wann tritt das
            // auf?" meint die Uhr dort.
            $table->timestamp('first_seen');
            $table->timestamp('last_seen');

            $table->timestamps();

            // Die Fehlerliste eines Projekts: offene Einträge, zuletzt
            // aufgetretene zuerst. Die Abfrage, mit der diese Anwendung
            // aufgeschlagen wird.
            $table->index(['project_id', 'status', 'last_seen']);

            // Dieselbe Liste ohne Zustandsfilter — „alles, was es je gab",
            // sortiert nach Zeit.
            $table->index(['project_id', 'last_seen']);

            // Die Sortierung nach Häufigkeit: „was tritt am meisten auf".
            $table->index(['project_id', 'times_seen']);
        });

        Schema::table('event_groups', function (Blueprint $table) {
            // Der Eintrag, zu dem diese Gruppe gehört. Nullable, weil eine
            // Gruppe einen Augenblick lang ohne Eintrag existiert — sie entsteht
            // im Schritt davor — und weil eine Gruppe aus der Zeit vor dieser
            // Aufgabe keinen hat.
            //
            // `nullOnDelete` und nicht `cascade`: ein gelöschter Eintrag darf
            // die Gruppen nicht mitnehmen, denn an ihnen hängen die Ereignisse.
            // Die Gruppe bekommt beim nächsten Auftreten einen neuen Eintrag;
            // ein gelöschter Eintrag ist die Aussage „will ich nicht mehr
            // sehen", nicht „das ist nie passiert".
            $table->foreignId('issue_id')->nullable()->after('project_id')
                ->constrained()->nullOnDelete();

            // Der Weg vom Eintrag zu seinen Gruppen. Ab S9 sind es mehrere, und
            // dann ist das der Weg zu allen Ereignissen eines Eintrags.
            $table->index('issue_id');
        });

        Schema::create('issue_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();

            // Stunde oder Tag. Beide, weil sie verschiedene Fragen beantworten:
            // die Stunde die nach dem Ausschlag von heute Mittag, der Tag die
            // nach dem Verlauf über ein Vierteljahr. Aus Stunden Tage zu
            // summieren wäre möglich — für 90 Tage sind das 2.160 Zeilen je
            // Eintrag und Diagramm, und das bei jedem Seitenaufruf.
            $table->string('period', 5);

            // Der Anfang des Fensters, abgeschnitten und nicht gerundet.
            $table->timestamp('window_start');

            $table->unsignedBigInteger('event_count')->default(0);

            $table->timestamps();

            // Die Zusage, auf der das sperrfreie Fortschreiben beruht: je
            // Eintrag, Auflösung und Fenster genau eine Zeile — auch wenn zwei
            // Arbeiter im selben Augenblick dasselbe Fenster anlegen wollen.
            // Zugleich der Zugriff der Verlaufsgrafik: ein Eintrag, eine
            // Auflösung, ein Zeitraum.
            $table->unique(['issue_id', 'period', 'window_start']);

            // Das Aufräumen (O2) räumt nach Alter, nicht nach Eintrag: ohne
            // diesen Index wäre „alle Stunden-Fenster älter als 90 Tage" ein
            // voller Durchlauf über die größte Tabelle dieser Aufgabe.
            $table->index(['period', 'window_start']);
        });

        Schema::create('issue_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();

            // Der Streuwert der Nutzer-Kennung, nicht die Kennung selbst. Zum
            // Zählen genügt „verschieden oder nicht", und dafür braucht es die
            // Kennung nicht im Klartext. Wer wirklich betroffen war, steht am
            // Ereignis — dort, wo das Scrubbing (I7) darüber entschieden hat und
            // wo die Aufbewahrung es wieder wegräumt.
            $table->char('user_key', 32);

            // Wann dieser Nutzer den Fehler zum ersten Mal sah. Nicht für die
            // Zählung, sondern für die Frage danach: „betrifft das noch
            // jemanden, oder immer denselben?"
            $table->timestamp('first_seen');

            $table->timestamps();

            // Der eindeutige Index ist hier nicht Ordnung, sondern das
            // Zählverfahren selbst: er entscheidet, ob ein Nutzer neu ist. Nur
            // wer die Zeile tatsächlich einfügt, zählt `users_seen` hoch —
            // damit kann derselbe Nutzer aus zwei Arbeitern heraus nicht doppelt
            // gezählt werden.
            $table->unique(['issue_id', 'user_key']);
        });

        Schema::table('events', function (Blueprint $table) {
            // Wann dieses Ereignis in die Zähler eingegangen ist. Der Vermerk
            // trennt „ausgewertet" von „gezählt", und das ist nötig, weil
            // dieselbe Meldung ein zweites Mal durch die Kette laufen darf: der
            // ausgewertete Datensatz wird dann ersetzt (`updateOrCreate`), die
            // Zähler dürfen sich aber nicht verdoppeln.
            $table->timestamp('counted_at')->nullable()->after('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('counted_at');
        });

        Schema::dropIfExists('issue_users');
        Schema::dropIfExists('issue_counts');

        // Zuerst die Verweise lösen, dann die Einträge: ein Fremdschlüssel
        // hält die Tabelle fest, auf die er zeigt.
        //
        // Die Reihenfolge ist die einzige, die beide Datenbanken hinnehmen:
        // MySQL lässt den Index nicht fallen, solange der Fremdschlüssel ihn
        // braucht — SQLite lässt die Spalte nicht fallen, solange ein Index auf
        // ihr liegt. Also erst der Schlüssel, dann der Index, dann die Spalte.
        // (Das Lösen des Schlüssels ist unter SQLite folgenlos; dort steht er
        // in der Tabellendefinition und geht mit der Spalte.)
        Schema::table('event_groups', function (Blueprint $table) {
            $table->dropForeign(['issue_id']);
            $table->dropIndex(['issue_id']);
            $table->dropColumn('issue_id');
        });

        Schema::dropIfExists('issues');
    }
};

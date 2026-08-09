<?php

use App\Enums\HttpMethod;
use App\Enums\UptimeCheckOutcome;
use App\Enums\UptimeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Erreichbarkeits-Überwachung: Monitore, ihre einzelnen Prüfungen und die
     * daraus abgeleiteten Ausfälle.
     *
     * **Drei Tabellen, weil drei verschiedene Fragen gestellt werden**, und sie
     * unterschiedlich oft gestellt werden:
     *
     *   Monitor   — „ist die Seite gerade erreichbar?" Ein Datensatz je Ziel,
     *               ständig gelesen, selten geschrieben.
     *   Prüfung   — „wie schnell war sie in den letzten Stunden?" Ein Datensatz
     *               je Intervall und Monitor; bei einem Takt von einer Minute
     *               sind das 1.440 am Tag. Daraus kommen Verfügbarkeitsquote
     *               und Antwortzeit-Verlauf.
     *   Ausfall   — „wann war sie weg und wie lange?" Wenige Datensätze, die
     *               dafür jemand liest, ausdruckt und weiterreicht.
     *
     * Den Ausfall aus den Prüfungen abzuleiten wäre möglich — man müsste die
     * Kette gescheiterter Prüfungen zusammenfassen. Es wäre aber jedes Mal ein
     * Durchlauf über die größte der drei Tabellen, und die Aufbewahrung der
     * Prüfungen ist kürzer als die Frage „wie oft waren wir dieses Jahr weg?".
     * Ein Ausfall ist ein Vorfall und kein Nebenprodukt einer Abfrage.
     */
    public function up(): void
    {
        Schema::create('uptime_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // Dieselbe Begründung wie beim Cronjob-Monitor: die Kennung steht
            // in Adressen und in Meldungen und ist nur je Projekt eindeutig —
            // zwei Projekte dürfen beide eine „startseite" überwachen.
            $table->string('slug', 64);

            // Das Ziel. Lang genug für eine Adresse mit Abfrageteil; kürzer
            // wäre eine Grenze, die irgendwann jemand von Hand umgeht.
            $table->string('url', 2048);

            $table->string('method', 8)->default(HttpMethod::Get->value);

            // Kopfzeilen als Liste von Paaren statt als Objekt: die Reihenfolge
            // bleibt erhalten, und derselbe Name darf zweimal vorkommen (etwa
            // zwei `Cookie`-Zeilen). Ein Objekt könnte beides nicht.
            $table->json('headers')->nullable();

            $table->text('body')->nullable();

            // Was als „erreichbar" zählt. Als Text und nicht als Zahl, weil
            // die Angabe ein Bereich ist: „200-299", ergänzt um Einzelwerte
            // („200-299,301"). Ein einzelner erwarteter Code wäre für die
            // meisten Ziele zu eng — eine Weiterleitung ist kein Ausfall.
            $table->string('expected_status_codes', 64)->default('200-299');

            // Inhaltsprüfung: dieser Text muss im Rumpf vorkommen. Sie ist der
            // Unterschied zwischen „der Webserver antwortet" und „die Anwendung
            // läuft" — eine Fehlerseite mit HTTP 200 ist der häufigste
            // Ausfall, den eine reine Statusprüfung übersieht.
            $table->string('expected_content', 255)->nullable();

            // Der Takt in Sekunden und nicht in Minuten: die Einheit, in der
            // sich die Auflösung später verfeinern lässt, ohne die Spalte zu
            // ändern. Die Untergrenze von einer Minute steht in der
            // Eingabeprüfung — feiner kann der Zeitplan der Anwendung ohnehin
            // nicht auslösen.
            $table->unsignedInteger('interval_seconds')->default(300);

            // Zeitüberschreitung der einzelnen Anfrage. Kurz genug, dass eine
            // hängende Gegenstelle den Arbeiter nicht blockiert.
            $table->unsignedSmallInteger('timeout_seconds')->default(10);

            // Die Wiederholung zur Bestätigung: nach einem Fehlschlag wird die
            // Anfrage sofort noch einmal gestellt, bevor die Prüfung als
            // gescheitert gilt. Genau das trennt einen echten Ausfall von einem
            // verlorenen Paket — und ohne sie meldet jede Überwachung an einem
            // schlechten Tag mehr Fehlalarme als Ausfälle.
            $table->unsignedTinyInteger('confirmation_retries')->default(1);
            $table->unsignedTinyInteger('confirmation_delay_seconds')->default(5);

            // Und darüber die zweite Stufe, für Ziele, die pendeln: erst nach
            // so vielen gescheiterten Prüfungen in Folge beginnt ein Ausfall.
            // Vorgabe 1 — die Bestätigung oben hat die Aussetzer bereits
            // abgefangen; wer trotzdem Ruhe braucht, stellt hier höher.
            $table->unsignedSmallInteger('failure_threshold')->default(1);

            // Dasselbe für das Ende: sonst zerfällt ein wackliger Dienst in
            // Dutzende Ausfälle von je zwei Minuten.
            $table->unsignedSmallInteger('recovery_threshold')->default(1);

            $table->boolean('follow_redirects')->default(true);

            // Ein abgelaufenes Zertifikat ist ein Ausfall — deshalb wird
            // standardmäßig geprüft. Abschaltbar bleibt es für interne Ziele
            // mit eigener Zertifizierungsstelle.
            $table->boolean('verify_tls')->default(true);

            $table->boolean('is_active')->default(true);

            $table->string('status', 16)->default(UptimeStatus::Unknown->value);

            // Zähler der laufenden Serie, aus demselben Grund wie beim
            // Cronjob-Monitor: die Entscheidung „Ausfall oder nicht" fällt bei
            // jeder Prüfung und soll nicht jedes Mal den Verlauf lesen.
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('consecutive_successes')->default(0);

            $table->timestamp('last_checked_at')->nullable();

            // Die nächste fällige Prüfung. Sie wird nach jeder Prüfung
            // fortgeschrieben — dadurch muss der Sweep nur diese eine Spalte
            // vergleichen, statt für jeden Monitor den Takt nachzurechnen.
            $table->timestamp('next_check_at')->nullable();

            $table->timestamps();

            $table->unique(['project_id', 'slug']);

            // Der Zugriff des minütlichen Sweeps: fällige Monitore über alle
            // Projekte hinweg.
            $table->index(['is_active', 'next_check_at']);
        });

        Schema::create('uptime_outages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uptime_monitor_id')->constrained()->cascadeOnDelete();

            // Doppelt geführt wie beim Cronjob-Verlauf: nach Projekt wird
            // aufgeräumt und ausgewertet, und dafür soll kein Join nötig sein.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Der Fehler-Eintrag zu diesem Ausfall. `nullOnDelete`, weil ein
            // gelöschter Eintrag den Vorfall nicht mitnehmen darf: dass die
            // Seite weg war, bleibt wahr, auch wenn jemand den Eintrag
            // aufgeräumt hat.
            $table->foreignId('issue_id')->nullable()->constrained()->nullOnDelete();

            // Woran es lag, als der Ausfall begann. Wechselt der Grund
            // währenddessen (erst Zeitüberschreitung, dann HTTP 500), bleibt
            // der erste stehen — er beschreibt, womit es anfing.
            $table->string('outcome', 24)->default(UptimeCheckOutcome::ConnectionFailed->value);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error', 500)->nullable();

            $table->timestamp('started_at');

            // Leer, solange der Ausfall läuft. Genau daran wird er gefunden —
            // es gibt je Monitor höchstens einen offenen.
            $table->timestamp('ended_at')->nullable();

            // Die Dauer wird beim Abschluss ausgerechnet und gespeichert, statt
            // sie bei jeder Anzeige aus den beiden Zeitpunkten zu bilden: sie
            // steht in Meldungen, in der Liste und in Auswertungen, und eine
            // Zahl, nach der sich sortieren lässt, gehört in eine Spalte.
            $table->unsignedInteger('duration_seconds')->nullable();

            // Wie viele Prüfungen währenddessen gescheitert sind — das Maß
            // dafür, wie durchgehend der Ausfall war.
            $table->unsignedInteger('failed_checks')->default(1);

            $table->timestamps();

            // Die Liste der Ausfälle eines Monitors, neueste zuerst.
            $table->index(['uptime_monitor_id', 'started_at']);

            // Der laufende Ausfall eines Monitors — der Zugriff jeder Prüfung.
            $table->index(['uptime_monitor_id', 'ended_at']);
        });

        Schema::create('uptime_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uptime_monitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('outcome', 24)->default(UptimeCheckOutcome::Up->value);

            $table->unsignedSmallInteger('http_status')->nullable();

            // Leer bei einer Prüfung, die nie eine Antwort gesehen hat. Das ist
            // wichtig für den Verlauf: eine ausgefallene Prüfung mit „0 ms"
            // wäre die schnellste Antwort des Tages.
            $table->unsignedInteger('response_time_ms')->nullable();

            $table->string('error', 500)->nullable();

            // Wie viele Anläufe nötig waren (1 = auf Anhieb). Steht hier, weil
            // eine Häufung von zwei Anläufen etwas erzählt, das keine der
            // beiden Zahlen allein hergibt: die Gegenstelle wackelt, auch wenn
            // die Quote noch bei 100 % steht.
            $table->unsignedTinyInteger('attempts')->default(1);

            $table->timestamp('checked_at');

            $table->timestamps();

            // Der Verlauf und die Verfügbarkeitsquote eines Monitors über ein
            // Zeitfenster — beides derselbe Zugriff.
            $table->index(['uptime_monitor_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uptime_checks');
        Schema::dropIfExists('uptime_outages');
        Schema::dropIfExists('uptime_monitors');
    }
};

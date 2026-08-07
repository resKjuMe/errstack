<?php

use App\Enums\CronCheckInStatus;
use App\Enums\CronMonitorStatus;
use App\Enums\CronScheduleType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Überwachte Cronjobs und ihre Ausführungen.
     *
     * Zwei Tabellen, weil zwei Dinge gefragt sind: „läuft der Job noch?" (der
     * Monitor, ein Datensatz, der ständig gelesen wird) und „wie lief er in den
     * letzten Tagen?" (der Verlauf, viele Datensätze, die nur selten jemand
     * ansieht). Den Zustand aus dem Verlauf abzuleiten wäre möglich, hieße aber,
     * für jede Übersicht über alle Ausführungen zu aggregieren — und die wächst
     * mit jedem Lauf.
     */
    public function up(): void
    {
        Schema::create('cron_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // Die Kennung, die im Check-in steht. Sie gehört in den Job und
            // steht damit in fremdem Code — deshalb nur je Projekt eindeutig
            // und nicht global: zwei Projekte dürfen beide einen
            // „nightly-import" haben.
            $table->string('slug', 64);

            $table->string('schedule_type', 16)->default(CronScheduleType::Crontab->value);

            // Bei `crontab` der Ausdruck, sonst leer.
            $table->string('schedule_expression', 128)->nullable();

            // Bei `interval` der Abstand, sonst leer.
            $table->unsignedInteger('interval_value')->nullable();
            $table->string('interval_unit', 16)->nullable();

            // Ohne Zeitzone ist „täglich 02:00" keine Angabe. Gespeichert wird
            // der Name (`Europe/Berlin`), nicht der Versatz — sonst stimmt die
            // Uhrzeit ein halbes Jahr lang nicht.
            $table->string('timezone', 64)->default('UTC');

            // Ein Job startet nie auf die Sekunde. Innerhalb dieses Fensters
            // nach der geplanten Zeit gilt er noch als pünktlich.
            $table->unsignedInteger('checkin_margin_minutes')->default(5);

            // Ab hier gilt eine begonnene Ausführung als hängend. Ohne diese
            // Grenze bliebe ein Job, der nie zurückmeldet, für immer „läuft".
            $table->unsignedInteger('max_runtime_minutes')->default(30);

            // Fehlertoleranz: erst nach so vielen Fehlschlägen in Folge geht ein
            // Alarm raus. Ein Job, der einmal in der Woche an einer trägen
            // Gegenstelle scheitert, soll niemanden wecken.
            $table->unsignedSmallInteger('failure_tolerance')->default(1);

            // Dasselbe für die Entwarnung — sonst meldet ein Job, der zwischen
            // Erfolg und Fehlschlag pendelt, im Wechsel Alarm und Entwarnung.
            $table->unsignedSmallInteger('recovery_tolerance')->default(1);

            $table->boolean('is_active')->default(true);

            $table->string('status', 16)->default(CronMonitorStatus::Unknown->value);

            // Zähler der laufenden Serie. Sie stehen hier und nicht im Verlauf,
            // weil die Entscheidung „Alarm oder nicht" bei jedem Check-in fällt
            // und nicht jedes Mal den Verlauf lesen soll.
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('consecutive_successes')->default(0);

            $table->timestamp('last_check_in_at')->nullable();

            // Die nächste geplante Ausführung. Sie wird fortgeschrieben, sobald
            // eine Ausführung gemeldet oder als verpasst festgestellt wurde —
            // dadurch muss die Prüfung nur diese eine Spalte vergleichen,
            // statt für jeden Monitor den Zeitplan durchzurechnen.
            $table->timestamp('next_due_at')->nullable();

            // Steht hier ein Zeitpunkt, ist der Alarm für die laufende Störung
            // bereits raus. Ohne das käme er im Minutentakt erneut.
            $table->timestamp('alerted_at')->nullable();

            $table->timestamps();

            $table->unique(['project_id', 'slug']);

            // Der Zugriff der minütlichen Prüfung: fällige Monitore über alle
            // Projekte hinweg.
            $table->index(['is_active', 'next_due_at']);
        });

        Schema::create('cron_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cron_monitor_id')->constrained()->cascadeOnDelete();

            // Doppelt geführt, obwohl der Monitor das Projekt kennt: der
            // Verlauf wird nach Projekt aufgeräumt (Aufbewahrung), und dafür
            // soll kein Join nötig sein.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Die Kennung aus dem Check-in. Über sie findet die Abschluss-
            // Meldung („ok", mit Dauer) wieder zu ihrem „in_progress" —
            // ohne sie wären es zwei getrennte Ausführungen.
            $table->string('check_in_id', 32)->nullable();

            $table->string('status', 16)->default(CronCheckInStatus::InProgress->value);

            $table->string('environment', 64)->nullable();

            // Die geplante Zeit, zu der dieser Lauf gehört — nicht die, zu der
            // er tatsächlich begann. Nur damit lässt sich sagen, ob er zu spät
            // dran war.
            $table->timestamp('expected_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // In Millisekunden, weil manche Jobs unter einer Sekunde bleiben.
            // Vorrang hat die vom Job gemeldete Dauer; ohne sie rechnen wir aus
            // Start und Ende.
            $table->unsignedBigInteger('duration_ms')->nullable();

            $table->timestamps();

            // Der Verlauf eines Monitors, neueste zuerst.
            $table->index(['cron_monitor_id', 'id']);

            // Der Abgleich einer Abschluss-Meldung mit ihrem Beginn.
            $table->index(['cron_monitor_id', 'check_in_id']);

            // Die Suche nach hängenden Ausführungen über alle Monitore.
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_check_ins');
        Schema::dropIfExists('cron_monitors');
    }
};

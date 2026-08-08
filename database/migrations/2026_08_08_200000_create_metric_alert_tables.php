<?php

use App\Models\MetricAlert;
use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Schwellwert-Alarme auf Kennzahlen: die Regel und ihr Verlauf.
     *
     * **`metric_alerts`** — die Regel samt ihrem **Zustand**. Dass der Zustand
     * an der Regel steht und nicht in einer Auswertung errechnet wird, ist die
     * tragende Entscheidung dieser Aufgabe: nur so lässt sich ein *Übergang*
     * von einem Dauerzustand unterscheiden. Ohne ihn wäre jede Auswertung eines
     * überschrittenen Grenzwerts eine neue Meldung — bei minütlichem Lauf also
     * sechzig in der Stunde für ein und dieselbe Lage.
     *
     * **`metric_alert_transitions`** — je Zustandswechsel eine Zeile. Sie ist
     * zweierlei: der Verlauf, den jemand ansieht („wann fing das an?"), und der
     * Beleg dafür, dass die Zusage „höchstens eine Meldung je Übergang"
     * eingehalten wurde. Beides wäre aus den Benachrichtigungen nicht zu holen —
     * die sagen, was verschickt wurde, nicht, was festgestellt wurde.
     *
     * **Der Zustandswechsel selbst ist eine bedingte Anweisung** (`update …
     * where status = <alt>`), keine Sperre. Läuft der Zeitplan doppelt an, sehen
     * beide Arbeiter denselben alten Zustand; die Anweisung trifft aber nur
     * einmal eine Zeile, und nur wer sie trifft, meldet. Eine Sperre hätte
     * denselben Zweck erfüllt und dabei den Zeitplan blockiert.
     */
    public function up(): void
    {
        Schema::create('metric_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('name', MetricAlert::NAME_LIMIT);

            // Welche Kennzahl, in welche Richtung und woran gemessen
            // (App\Enums\AlertMetric, AlertDirection, AlertComparison).
            $table->string('metric', 40);
            $table->string('direction', 10)->default('above');
            $table->string('comparison', 30)->default('absolute');

            // Worauf eingeschränkt wird. Beides frei lassbar: ohne Umgebung
            // zählt das ganze Projekt, ohne Vorgang alle Aufrufe.
            $table->string('environment', 64)->nullable();
            $table->string('transaction_name', Transaction::NAME_LIMIT)->nullable();

            // Das Zeitfenster, über das gerechnet wird. In Minuten, weil das
            // die Auflösung des Zeitplans ist — ein Fenster von 30 Sekunden
            // wäre eine Angabe, die die Auswertung gar nicht einlösen kann.
            $table->unsignedSmallInteger('window_minutes')->default(5);

            // Die Schwellen. `double` und nicht `decimal`: die Kennzahlen sind
            // Anzahlen, Millisekunden und Prozentwerte in sehr verschiedenen
            // Größenordnungen, und eine feste Nachkommastelle wäre für die eine
            // zu grob und für die andere Ballast.
            //
            // Beide frei lassbar, aber nicht beide zugleich — geprüft wird das
            // in der Eingabe (App\Http\Requests\MetricAlertRequest): ein Alarm
            // ohne jede Schwelle wäre eine Regel, die nie greift.
            $table->double('warning_threshold')->nullable();
            $table->double('critical_threshold')->nullable();

            // Die Auflösungsschwelle. Ohne sie endet der Alarm, sobald die
            // auslösende Schwelle wieder unterschritten ist — mit ihr erst,
            // wenn der Wert die Grenze wirklich hinter sich lässt. Der
            // Unterschied ist ein Wert, der um die Schwelle pendelt: ohne
            // Hysterese schickt er abwechselnd Alarm und Entwarnung.
            $table->double('resolve_threshold')->nullable();

            // Wie viele Messungen mindestens vorliegen müssen, damit ein Anteil
            // oder ein Perzentil überhaupt etwas aussagt. Bei drei Aufrufen ist
            // eine Fehlerquote von 33 % kein Befund.
            $table->unsignedInteger('minimum_samples')->default(0);

            $table->boolean('is_active')->default(true);

            // Der Zustand und seit wann er gilt.
            $table->string('status', 20)->default('ok');
            $table->timestamp('status_since')->nullable();

            // Was zuletzt gerechnet wurde — die Auskunft „der Alarm lebt und
            // sieht gerade das hier". Ohne sie wäre eine stille Regel nicht von
            // einer kaputten zu unterscheiden.
            $table->timestamp('last_evaluated_at')->nullable();
            $table->double('last_value')->nullable();
            $table->double('last_baseline')->nullable();

            $table->timestamps();

            // Ein Name je Projekt: die Meldung nennt ihn, und zwei Alarme
            // gleichen Namens wären in der Benachrichtigung nicht zu trennen.
            $table->unique(['project_id', 'name']);

            // Der Zugriff des Zeitplans: alle aktiven Alarme, ältester
            // Auswertungszeitpunkt zuerst.
            $table->index(['is_active', 'last_evaluated_at']);
        });

        Schema::create('metric_alert_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('metric_alert_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 20);
            $table->string('to_status', 20);

            // Der Wert, der den Wechsel ausgelöst hat, und die Schwelle, an der
            // er gemessen wurde. Beide gehören in den Verlauf: „kritisch" allein
            // beantwortet die erste Rückfrage nicht, die jemand stellt.
            $table->double('value');
            $table->double('threshold')->nullable();

            // Der Vergleichswert der Vorwoche, sofern so gemessen wurde.
            $table->double('baseline')->nullable();

            $table->timestamp('occurred_at');

            $table->timestamps();

            // Der Verlauf eines Alarms, jüngster zuerst.
            $table->index(['metric_alert_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_alert_transitions');
        Schema::dropIfExists('metric_alerts');
    }
};

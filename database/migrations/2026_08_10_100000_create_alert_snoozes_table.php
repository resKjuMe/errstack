<?php

use App\Support\Alerts\AlertMute;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Stummschaltung einer Regel — befristet, für alle oder nur für einen.
     *
     * **Sie hängt an der Regel und nicht am Kanal.** Ein stummgeschalteter Kanal
     * wäre eine andere Zusage: dann schwiege auch alles andere, was über ihn
     * geht. Gemeint ist aber die eine Regel, die gerade zu laut ist, während der
     * Rest der Überwachung weiterlaufen soll.
     *
     * **Zwei Fremdschlüssel statt einer polymorphen Spalte.** Es gibt genau zwei
     * Arten von Regeln (Schwellwert-Alarm aus A3, Fehler-Regel aus A2), und eine
     * echte Beziehung räumt beim Löschen mit auf: eine gelöschte Regel nimmt
     * ihre Stummschaltungen mit. Ein Paar aus Typ-Spalte und Kennung täte das
     * nicht — dort bliebe die Zeile stehen und zeigte irgendwann auf eine Regel,
     * die es nicht mehr gibt.
     *
     * **`user_id` leer heißt „für alle".** Das ist keine Sparsamkeit, sondern
     * die Aussage selbst: eine Stummschaltung ohne Person ist die für jeden.
     * Wer sie setzen darf, entscheidet die Rechteprüfung — für alle darf nur die
     * Verwaltung, für sich selbst jedes Mitglied.
     *
     * **Kein eindeutiger Index über den Geltungsbereich.** Er wäre nur die halbe
     * Zusage: in beiden Datenbanken gelten zwei leere `user_id` als
     * verschieden, und genau die Zeile „für alle" bliebe damit ungeschützt.
     * Statt eines Index, der nur den persönlichen Fall abdeckt, entscheidet die
     * Leseseite: sie nimmt je Geltungsbereich die Zeile, die am längsten reicht
     * ({@see AlertMute}). Eine doppelte Zeile ist damit
     * folgenlos — und zwei gleichzeitige Klicks auf denselben Knopf sind der
     * einzige Weg, überhaupt eine zu bekommen.
     */
    public function up(): void
    {
        Schema::create('alert_snoozes', function (Blueprint $table) {
            $table->id();

            // Genau eine der beiden Spalten ist gesetzt. Beide zugleich wären
            // zwei Regeln in einer Zeile, keine von beiden eine Stummschaltung
            // ohne Gegenstand; darüber wacht App\Models\AlertSnooze.
            $table->foreignId('metric_alert_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('issue_alert_rule_id')->nullable()->constrained()->cascadeOnDelete();

            // Leer = für alle. Sonst genau die eine Person, die für sich Ruhe
            // haben wollte.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            // Wer sie gesetzt hat. Die Auskunft zählt bei der Stummschaltung für
            // alle: „seit gestern still" ist eine Angabe, „von wem" die
            // Anschlussfrage. Beim Löschen des Kontos bleibt die Stummschaltung
            // bestehen — sie ist eine Tatsache über die Regel, nicht über die
            // Person.
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            // Befristet, immer. Eine Stummschaltung ohne Ende ist eine
            // abgeschaltete Überwachung, die niemand mehr als solche erkennt —
            // dafür gibt es den Schalter an der Regel selbst.
            $table->timestamp('until');

            $table->timestamps();

            // Der einzige Zugriff der Auswertung: „gilt für diese Regel gerade
            // eine?" — je Regel und Ablauf.
            $table->index(['metric_alert_id', 'until']);
            $table->index(['issue_alert_rule_id', 'until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_snoozes');
    }
};

<?php

use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Ablage der Stichproben: die Regeln, nach denen behalten wird, und die
     * Spalten, mit denen sich das Behaltene wieder hochrechnen lässt.
     *
     * Beides in einer Migration, weil es ohne einander nicht taugt. Eine Regel
     * ohne die Quoten am Datensatz spart Speicher und verfälscht dabei jede
     * Zahl, die darauf gerechnet wird: von hundert Aufrufen steht einer da, und
     * die Übersicht meldet einen. Umgekehrt sind die Quoten ohne Regeln
     * dauerhaft leer.
     *
     * **`sampling_rules`** sind die serverseitigen Regeln je Projekt: „Aufrufe,
     * die so aussehen, behalte davon diesen Anteil". Sie stehen in der Datenbank
     * und nicht in einer Konfigurationsdatei, weil sie je Projekt verschieden
     * sind und von denen gepflegt werden, die die Antwortzeiten ansehen — nicht
     * von denen, die die Anwendung ausliefern.
     *
     * **An der Messung** stehen zwei Quoten: die des SDK und unsere. Zwei
     * Spalten und nicht ein fertiges Gewicht, weil die Frage „warum steht dort
     * der hundertfache Durchsatz?" sonst nicht zu beantworten wäre. Das Gewicht
     * entsteht daraus in {@see Transaction::sampleWeight()}.
     *
     * **An der Vorberechnung** steht die hochgerechnete Anzahl neben der
     * tatsächlichen. Beide werden gebraucht: die tatsächliche sagt, auf wie
     * vielen Messungen eine Verteilung beruht (und ob man ihr trauen darf), die
     * hochgerechnete sagt, wie viel Verkehr die Anwendung wirklich hatte.
     */
    public function up(): void
    {
        Schema::create('sampling_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Wofür die Regel da ist — für die Liste, nicht für die Auswertung.
            // Eine Quote ohne Namen ist nach einem halben Jahr niemandem mehr zu
            // erklären.
            $table->string('name');

            // Die Bedingungen. Alle gesetzten müssen zutreffen (UND); wer ODER
            // braucht, schreibt zwei Regeln. Als eigene Spalten und nicht als
            // JSON — anders als bei den Fingerprint-Regeln ist die Menge der
            // Merkmale hier geschlossen und klein, und eigene Spalten machen
            // aus jeder Bedingung eine Angabe, die die Datenbank prüfen kann.
            //
            // Leer heißt: dieses Merkmal ist der Regel gleichgültig. Eine Regel
            // ohne jede Bedingung ist damit die Vorgabe des Projekts — sie
            // trifft auf alles zu und ist ausdrücklich erlaubt.
            $table->string('transaction_name', Transaction::NAME_LIMIT)->nullable();
            $table->string('environment', 64)->nullable();
            $table->string('release')->nullable();

            // Der Anfragetyp (`http.server`, `queue.task`, `db.sql.query` …).
            // Er ist das Merkmal, mit dem sich Hintergrundarbeit von
            // Seitenaufrufen trennen lässt, ohne jeden Namen aufzuzählen.
            $table->string('op', Transaction::OP_LIMIT)->nullable();

            // Der behaltene Anteil, zwischen 0 und 1. Acht Nachkommastellen,
            // weil eine Anwendung mit Millionen Aufrufen je Stunde bei 0,01 %
            // noch sinnvolle Zahlen liefert — und weil eine Quote, die beim
            // Speichern gerundet wird, hinterher einen anderen Durchsatz
            // ausweist als den eingestellten.
            $table->decimal('sample_rate', 9, 8);

            // Wie viele Vorgänge je Zeitfenster **immer** behalten werden, auch
            // wenn die Quote es nicht vorsieht. Das ist die Zusage an die
            // seltenen Vorgänge: ein nächtlicher Import, der einmal je Stunde
            // läuft, verschwindet bei 1 % Quote sonst mit 99 % Wahrscheinlichkeit
            // ganz — und ausgerechnet der ist der interessante.
            //
            // Gezählt wird je Transaktionsname und Umgebung im Fenster der
            // Vorberechnung ({@see Transaction::BUCKET_SECONDS}), nicht je Regel:
            // selten ist ein Vorgang, nicht eine Regel.
            $table->unsignedSmallInteger('minimum_per_window')->default(1);

            // Die Reihenfolge. Die erste zutreffende Regel gewinnt; ohne feste
            // Ordnung hinge die Quote an der Reihenfolge der Datenbank, und
            // derselbe Aufruf wäre morgen anders bewertet als heute.
            $table->unsignedInteger('position')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Der Zugriff der Aufnahme: die aktiven Regeln eines Projekts in
            // ihrer Reihenfolge.
            $table->index(['project_id', 'is_active', 'position']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            // Was das SDK schon vor dem Senden ausgesiebt hat. Ohne diese Angabe
            // wäre eine Anwendung mit `traces_sample_rate: 0.1` in der Übersicht
            // um den Faktor zehn zu leise — und zwar unbemerkt, weil an den
            // gespeicherten Messungen nichts fehlt.
            $table->decimal('client_sample_rate', 9, 8)->nullable()->after('span_count');

            // Was unsere Regeln ausgesiebt haben. Getrennt von der Quote des
            // SDK, weil beide unabhängig voneinander wirken und sich
            // multiplizieren: 10 % beim Absender und 10 % bei uns ergeben 1 %.
            $table->decimal('server_sample_rate', 9, 8)->nullable()->after('client_sample_rate');
        });

        Schema::table('transaction_aggregates', function (Blueprint $table) {
            // Die hochgerechnete Anzahl: die Summe der Gewichte der behaltenen
            // Messungen. Keine ganze Zahl, weil ein Gewicht keine ist — bei 30 %
            // Quote wiegt eine behaltene Messung 3,33 Aufrufe. Aufgerundet je
            // Messung addiert, wäre der Durchsatz um ein Fünftel zu hoch.
            //
            // Neben `transaction_count` und nicht an seiner Stelle: die
            // tatsächliche Anzahl sagt, auf wie vielen Messungen die Verteilung
            // beruht. Eine p95 aus drei Messungen ist eine andere Auskunft als
            // eine aus dreitausend, und diese Unterscheidung wäre verloren, wenn
            // dort die hochgerechnete Zahl stünde.
            $table->decimal('extrapolated_count', 20, 4)->default(0)->after('transaction_count');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_aggregates', function (Blueprint $table) {
            $table->dropColumn('extrapolated_count');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['client_sample_rate', 'server_sample_rate']);
        });

        Schema::dropIfExists('sampling_rules');
    }
};

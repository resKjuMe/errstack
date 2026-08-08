<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Listen der Eingangsfilter: welche Fehlertexte, welche Absender,
     * welche Releases, ab welcher Browser-Fassung.
     *
     * Eine Tabelle für alle vier Arten und nicht vier Tabellen. Sie tragen
     * dieselben Spalten — ein Ausdruck und ein Schalter —, und der Unterschied
     * steckt allein darin, **worauf** der Ausdruck angewendet wird. Vier
     * Tabellen wären viermal dasselbe, und die Aufnahme müsste für jede
     * eingehende Meldung vier Abfragen stellen statt einer.
     *
     * Nur am Projekt und nicht zusätzlich an der Organisation, anders als bei
     * den Datenschutz-Regeln: dort ist die organisationsweite Regel der
     * Regelfall („nirgends Kennwörter"), hier der Ausnahmefall. Welche
     * Fehlertexte uninteressant sind, hängt an der einzelnen Anwendung, und
     * eine geerbte Sperrliste, die eine fremde Anwendung stillschweigend
     * mitfiltert, wäre der teuerste Fehler, den dieses Werkzeug machen kann.
     *
     * `is_active` neben dem Schalter am Projekt: der Schalter entscheidet über
     * die **Art**, dieses Feld über den **einzelnen Eintrag**. Wer prüfen will,
     * ob ein Muster zu viel wegnimmt, legt es still, ohne es zu löschen und
     * ohne den ganzen Filter abzuschalten.
     */
    public function up(): void
    {
        Schema::create('inbound_filter_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Welche Filterart den Eintrag verwendet (App\Enums\InboundFilterKind).
            $table->string('kind', 32);

            // Der Eintrag selbst: ein Muster mit `*`, eine Adresse oder ein
            // Netz in CIDR-Schreibweise, ein Release-Name, eine
            // Browser-Untergrenze (`safari:6`). Großzügig bemessen wie bei den
            // Datenschutz-Regeln — ein stillschweigend abgeschnittenes Muster
            // filtert danach etwas anderes als gedacht.
            $table->string('expression', 500);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Der Zugriff der Aufnahme: alle aktiven Einträge eines Projekts,
            // in einem Zug für alle Arten. `kind` gehört bewusst nicht in den
            // Index — die Abfrage schränkt darauf zwar ein, aber ein Projekt hat
            // eine Handvoll Einträge, und die nach Arten zu trennen kostet mehr
            // Pflege am Index, als es an Zeilen spart.
            $table->index(['project_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_filter_rules');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Eigene Datenschutz-Regeln: was über die eingebauten Standardfelder hinaus
     * aus einer Meldung entfernt wird.
     *
     * Eine Tabelle für beide Ebenen. Die Organisation trägt ihre Regeln mit
     * leerem `project_id` — sie gelten dann für alle ihre Projekte, und ein
     * Projekt legt daneben eigene an. Zwei Tabellen wären dieselben Spalten
     * zweimal, und die Auswertung müsste beide lesen und zusammenfügen; die
     * Ebene ist hier eine Eigenschaft der Regel, nicht ihre Art.
     *
     * `organization_id` steht auch an der Projekt-Regel, obwohl sie sich über
     * das Projekt ergäbe: die Regeln eines Projekts und die seiner Organisation
     * werden immer zusammen geholt, und ohne die Spalte bräuchte dieser Zugriff
     * eine Verknüpfung für jede einzelne aufgenommene Meldung.
     */
    public function up(): void
    {
        Schema::create('scrub_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('type');

            // Feldname oder regulärer Ausdruck. Großzügig bemessen: ein Ausdruck
            // für mehrere Kartenanbieter wird lang, und eine Regel, die beim
            // Speichern stillschweigend abgeschnitten wird, greift danach
            // woanders als gedacht.
            $table->string('expression', 500);

            // Auf welchen Abschnitt der Meldung die Regel beschränkt ist
            // (`request.data`, `extra`, `user`) — leer heißt: auf die ganze
            // Meldung. Die Einschränkung ist der Unterschied zwischen „Feld
            // `id` überall entfernen" und „nur im Anfrage-Rumpf".
            $table->string('path', 200)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Der Zugriff der Datenaufnahme: alle Regeln einer Organisation,
            // aus denen die für dieses Projekt gültigen ausgewählt werden.
            $table->index(['organization_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrub_rules');
    }
};

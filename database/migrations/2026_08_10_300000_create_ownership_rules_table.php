<?php

use App\Models\Issue;
use App\Models\OwnershipRule;
use App\Support\Issues\IssueAssignee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Zuständigkeits-Regeln (R6): wer sich um einen Fehler kümmert, abgeleitet
 * aus dem Ort, an dem er passiert ist.
 *
 * **Regeln und der Schalter in einer Migration**, weil keines von beiden ohne
 * das andere taugt. Regeln ohne Schalter wären eine Liste, die nur vorschlägt
 * und nie zuweist; ein Schalter ohne Regeln wäre eine Einstellung, die nichts
 * bewirkt. Zusammen sind sie eine Aussage: „so wird hier verteilt, und ja, es
 * soll auch geschehen".
 *
 * **Das Muster steht als Text und nicht als regulärer Ausdruck.** Dieselbe
 * Entscheidung wie beim Eingangsfilter (I8) und aus demselben Grund: die Regeln
 * schreiben die Leute, die die Fehlerliste ansehen. `*` ist das einzige
 * Sonderzeichen — und ein Muster, das versehentlich zu viel trifft, verteilt
 * hier immerhin nur Arbeit, statt Meldungen wegzuwerfen.
 *
 * **Die Zuständigen stehen als JSON-Liste und nicht als Fremdschlüssel.** Das
 * ist die eine Stelle, an der von den Zwei-Spalten-Regeln der Zuweisung
 * ({@see Issue} — `assigned_user_id` **oder** `assigned_team_id`) abgewichen
 * wird, und sie hat einen Grund: eine Regel benennt **mehrere** Zuständige
 * („die Kasse und Anna"), und beides sind Zeilen verschiedener Tabellen. Als
 * Fremdschlüssel wären das zwei weitere Tabellen für eine Angabe, die
 * ausschließlich als Ganzes gelesen wird. Geschrieben wird deshalb dieselbe
 * Schreibweise, die auch im Suchfeld steht ({@see IssueAssignee}):
 * `anna@example.com`, `#Kasse`. Ihr Preis ist bekannt und wird bewusst gezahlt:
 * ein gelöschtes Konto räumt die Datenbank hier nicht mit auf — der Eintrag
 * bleibt stehen und lässt sich nicht mehr auflösen. Das ist die harmlosere
 * Hälfte des Tauschs, denn eine nicht auflösbare Regel weist niemandem etwas
 * zu und fällt in der Vorschau sofort auf.
 *
 * **`position` und nicht `created_at` für die Reihenfolge.** Sie ist die
 * eigentliche Aussage des Regelwerks: bei mehreren Treffern gewinnt die
 * **zuletzt** passende Regel — dieselbe Auflösung wie in einer CODEOWNERS-Datei,
 * aus der sich diese Listen importieren lassen. Wer die Reihenfolge nicht selbst
 * in der Hand hat, kann eine allgemeine Regel nicht mehr durch eine engere
 * überschreiben, und genau das ist der Zweck der Liste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ownership_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Worauf sich das Muster bezieht: Pfad, Adresse, Modul oder
            // Merkmal ({@see App\Enums\OwnershipMatcher}). Als Spalte und nicht
            // als Vorsilbe im Muster (`path:src/*`), obwohl die Oberfläche und
            // der CODEOWNERS-Import genau so schreiben: eine Vorsilbe wäre eine
            // zweite Sprache im Feld, und ein Tippfehler darin („paht:") ergäbe
            // eine Regel, die stillschweigend nie zutrifft.
            $table->string('matcher', 16);

            // Nur bei `tag` gefüllt: welches Merkmal gemeint ist
            // (`tag:server_name`). Eine eigene Spalte statt eines
            // zusammengesetzten Musters, damit der Schlüssel nicht am
            // Doppelpunkt aus dem Muster geschnitten werden muss — Merkmalswerte
            // enthalten selbst Doppelpunkte.
            $table->string('tag_key', 64)->nullable();

            $table->string('pattern', OwnershipRule::PATTERN_LIMIT);

            // Die Zuständigen in der Schreibweise des Suchfeldes. Mehrere sind
            // ausdrücklich erlaubt: eine Datei gehört oft einem Team **und**
            // einer Person, die sie zuletzt angefasst hat. Zugewiesen wird davon
            // der erste auflösbare, vorgeschlagen werden alle.
            $table->json('owners');

            // Woher die Regel stammt — von Hand oder aus einer CODEOWNERS-Datei.
            // Für die Anzeige und für die Frage, die nach einem Import als
            // erstes kommt: „welche davon habe ich selbst geschrieben?"
            $table->string('source', 16)->default(OwnershipRule::SOURCE_MANUAL);

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Die Liste wird immer vollständig und in ihrer Reihenfolge gelesen
            // — je Projekt, nie über Projekte hinweg.
            $table->index(['project_id', 'position']);
        });

        Schema::table('projects', function (Blueprint $table) {
            // Automatisch zuweisen: aus **an**, und das ist keine Vorsicht,
            // sondern die Bedeutung des Schalters. Eine Zuweisung ist eine
            // Aussage darüber, wer sich kümmert; sie ungefragt zu treffen, weil
            // jemand eine CODEOWNERS-Datei importiert hat, wäre eine Behauptung
            // über die Arbeit anderer Leute. Wer sie will, schaltet sie ein —
            // und sieht vorher in der Vorschau, was sie täte.
            $table->boolean('ownership_auto_assign')->default(false)->after('filter_releases');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('ownership_auto_assign');
        });

        Schema::dropIfExists('ownership_rules');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabelle der API-Tokens. Die Spalten sind die, die Sanctum erwartet
     * (`tokenable`, `token`, `abilities`, `last_used_at`, `expires_at`), dazu
     * kommen zwei eigene. Der Name ist trotzdem `api_tokens` und nicht
     * `personal_access_tokens`: hier liegen auch die organisationsweiten Tokens,
     * und die sind gerade nicht persönlich. Sanctum stört das nicht — es arbeitet
     * über das Modell (App\Models\ApiToken) und nicht über einen festen
     * Tabellennamen.
     *
     * Bewusst als eigene Migration statt als veröffentlichte Kopie der
     * Paket-Migration: so steht das ganze Schema an einer Stelle, statt in zwei
     * Dateien, die zusammengelesen werden müssen. Die Migration des Pakets läuft
     * nicht von selbst mit — sie wird nur auf Wunsch veröffentlicht — und kommt
     * damit auch nicht in die Quere.
     *
     * `tokenable` entscheidet über die Art des Tokens: ein Nutzer für
     * persönliche Tokens, eine Organisation für organisationsweite. Die Spalte
     * `organization_id` gibt es trotzdem in beiden Fällen — ein Token gilt immer
     * für genau eine Organisation, sonst käme ein persönliches Token an die
     * Daten aller Organisationen seines Besitzers.
     */
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');

            // Geltungsbereich der Organisation. Wird sie gelöscht, sind ihre
            // Tokens wertlos und verschwinden mit ihr.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Wer das Token ausgestellt hat. Bei persönlichen Tokens derselbe
            // Nutzer wie `tokenable`, bei organisationsweiten die Verwaltung,
            // die es angelegt hat. Nur zur Nachvollziehbarkeit.
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            // Der Name ist die Wiedererkennung in der Liste; innerhalb einer
            // Organisation darf er sich deshalb nicht wiederholen.
            $table->unique(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};

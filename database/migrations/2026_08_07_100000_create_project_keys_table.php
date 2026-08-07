<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client-Schlüssel: die Zugangsdaten, mit denen eine Anwendung ihre
     * Meldungen einstellt. Sie lösen den einen Token am Projekt ab — ein
     * Projekt darf mehrere Schlüssel führen, damit sich Umgebungen trennen
     * und ein Schlüssel zurückgezogen werden kann, ohne die übrigen Anwendungen
     * stillzulegen.
     */
    public function up(): void
    {
        Schema::create('project_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            // Der öffentliche Teil der DSN. Er steht im Klartext in jeder
            // SDK-Konfiguration und identifiziert Projekt und Organisation,
            // deshalb global eindeutig — nicht nur je Projekt.
            $table->string('public_key', 32)->unique();

            // Abgeschaltete Schlüssel bleiben stehen (Name und Datum sind der
            // Nachweis, was einmal gültig war), werden bei der Datenaufnahme
            // aber abgewiesen.
            $table->boolean('active')->default(true);

            // Eigenes Kontingent je Schlüssel, `null` heißt „unbegrenzt".
            // Greift mit der Datenaufnahme; hier steht nur der Wert.
            $table->unsignedInteger('rate_limit_per_minute')->nullable();

            $table->timestamps();
        });

        // Bestehende Projekte behalten ihren bisherigen Token als ersten
        // Schlüssel: seine 32 Hex-Zeichen haben dasselbe Format wie ein
        // öffentlicher Schlüssel, und SDKs, die ihn schon tragen, funktionieren
        // weiter.
        $now = now();

        DB::table('projects')->orderBy('id')->select('id', 'token')->chunk(200, function ($projects) use ($now) {
            DB::table('project_keys')->insert($projects->map(fn ($project): array => [
                'project_id' => $project->id,
                'name' => 'Standard',
                'public_key' => $project->token,
                'active' => true,
                'rate_limit_per_minute' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['token']);
            $table->dropColumn('token');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('token', 32)->nullable();
        });

        // Zurück auf einen Token je Projekt: der älteste Schlüssel gewinnt,
        // denn er ist der beim Anlegen erzeugte.
        DB::table('project_keys')->orderBy('id')->select('id', 'project_id', 'public_key')->chunk(200, function ($keys) {
            foreach ($keys as $key) {
                DB::table('projects')
                    ->where('id', $key->project_id)
                    ->whereNull('token')
                    ->update(['token' => $key->public_key]);
            }
        });

        Schema::dropIfExists('project_keys');

        Schema::table('projects', function (Blueprint $table) {
            $table->string('token', 32)->nullable(false)->unique()->change();
        });
    }
};

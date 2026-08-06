<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Projekte als Container für Ereignisse: je eigener Anwendung ein Projekt.
     * Alles, was ab Phase P1 aufgenommen wird (Ereignisse, Issues, Releases),
     * hängt über `project_id` an genau einem Projekt und darüber an dessen
     * Organisation.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('platform');

            // Einstellungen. Sie wirken erst mit der Datenaufnahme, gehören
            // aber zum Projekt und werden deshalb hier schon geführt.
            $table->string('default_environment')->default('production');
            $table->string('resolution_behavior')->default('manual');
            $table->unsignedSmallInteger('retention_days')->default(30);

            // Sicherheits-Token: kommt später in die Zugangsdaten des SDK und
            // ist der einzige Nachweis, dass eine Meldung zu diesem Projekt
            // gehört. Deshalb global eindeutig, nicht nur je Organisation.
            $table->string('token', 32)->unique();
            $table->timestamps();

            // Der Slug steht in der Adresszeile hinter der Organisation und
            // muss nur dort eindeutig sein.
            $table->unique(['organization_id', 'slug']);
        });

        // Optionale Zuordnung zu Teams. Ein Projekt ohne Team gehört allen
        // Mitgliedern der Organisation; die Rechte hängen weiterhin an der
        // Rolle dort, nicht am Team.
        Schema::create('project_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_team');
        Schema::dropIfExists('projects');
    }
};

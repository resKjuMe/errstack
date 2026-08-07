<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mandantenfähigkeit: Organisation als oberste Klammer, darunter
     * Mitgliedschaften mit Rolle, Teams und offene Einladungen. Alle späteren
     * Ressourcen (Projekte, Issues, Alerts) hängen über `organization_id` an
     * genau einer Organisation.
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        // Mitgliedschaft samt Rolle. Ein Nutzer gehört einer Organisation
        // höchstens einmal an, deshalb das zusammengesetzte Unique.
        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
        });

        // Einladung an eine E-Mail-Adresse, die noch zu keinem Konto gehören
        // muss. Der Token steht im Link der Einladungs-Mail.
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('role');
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['organization_id', 'email']);
        });

        // Zuletzt gewählte Organisation. Wird sie gelöscht, fällt das Feld auf
        // null zurück und die Oberfläche fragt erneut nach der Organisation.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_organization_id')
                ->nullable()
                ->after('password')
                ->constrained('organizations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_organization_id');
        });

        Schema::dropIfExists('organization_invitations');
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};

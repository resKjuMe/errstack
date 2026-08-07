<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persönliche Benachrichtigungs-Einstellungen: was ein einzelner Nutzer
     * worüber erfahren will (`notification_preferences`) und wann er in Ruhe
     * gelassen werden möchte (`notification_settings`).
     *
     * Gespeichert wird nur, was ausdrücklich eingestellt wurde. Alles andere
     * bleibt leer und fällt auf die Vorgabe des Anlasses zurück — so bleibt
     * eine später geänderte Vorgabe für alle wirksam, die nichts entschieden
     * haben.
     */
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Die beiden Fremdschlüssel räumen auf, wenn eine Organisation
            // oder ein Projekt verschwindet. Verglichen wird aber über
            // `scope_key`: zwei NULL-Werte gelten in SQL als verschieden, ein
            // eindeutiger Index über nullbare Spalten liefe also ins Leere.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope_key', 40);

            $table->string('event_type', 40);
            $table->string('transport', 20);
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(
                ['user_id', 'scope_key', 'event_type', 'transport'],
                'notification_preferences_scope_unique',
            );

            // Der Versand fragt immer nach „alles zu diesem Nutzer".
            $table->index(['user_id', 'event_type']);
        });

        // Ein Datensatz je Nutzer, und auch nur dann, wenn er etwas eingestellt
        // hat. Die Vorgaben stehen im Modell, nicht als Spalten-Default —
        // sonst wären sie an zwei Stellen gepflegt.
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('quiet_hours_enabled')->default(false);
            $table->time('quiet_from')->default('22:00:00');
            $table->time('quiet_until')->default('07:00:00');
            $table->string('timezone', 64)->default('Europe/Berlin');
            // Gesetzt heißt: pauschal abbestellt. Kritische Alarme kommen
            // trotzdem an — deshalb ein Zeitpunkt und kein „alles aus".
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('notification_preferences');
    }
};

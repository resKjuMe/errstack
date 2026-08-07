<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Benachrichtigungswege einer Organisation und das Protokoll ihrer
     * Zustellversuche.
     *
     * `type` ist bewusst eine freie Zeichenkette und kein Enum-Feld: welche
     * Kanäle es gibt, entscheidet die Kanal-Liste (config/notifications.php).
     * Ein neuer Kanal ist damit eine neue Treiber-Klasse — keine Migration.
     */
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('name');
            // Zugangsdaten: Webhook-URLs und Token liegen verschlüsselt in der
            // Datenbank (Cast `encrypted:array`), deshalb Text statt JSON.
            $table->text('config');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Zwei Kanäle derselben Organisation dürfen nicht denselben Namen
            // tragen — sonst ist im Protokoll nicht zu erkennen, welcher gemeint ist.
            $table->unique(['organization_id', 'name']);
        });

        // Ein Datensatz je Kanal und Nachricht. Er entsteht beim Auslösen und
        // wird vom Worker fortgeschrieben; er ist damit zugleich Protokoll und
        // Arbeitsauftrag.
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_channel_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->json('payload');
            $table->string('status')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('error')->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            // Das Protokoll wird immer je Kanal und nach Zeit gelesen.
            $table->index(['notification_channel_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_channels');
    }
};

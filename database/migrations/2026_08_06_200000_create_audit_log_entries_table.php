<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Änderungsprotokoll je Organisation: wer wann von welcher Adresse aus
     * welche Verwaltungsaktion ausgeführt hat, samt Vorher/Nachher-Werten.
     *
     * Zwei Entscheidungen prägen den Schnitt:
     *
     * 1. Kein `updated_at`. Ein Eintrag wird geschrieben und nie wieder
     *    angefasst — eine Spalte für „zuletzt geändert" wäre ein Widerspruch
     *    zur Zusage, dass Einträge unveränderlich sind.
     * 2. Nutzer und Betreff stehen zusätzlich als Klartext im Eintrag. Genau
     *    die Dinge, die man später nachschlägt, sind oft die, die es dann nicht
     *    mehr gibt: das entfernte Mitglied, das gelöschte Team. Der Fremd-
     *    schlüssel auf `users` bleibt trotzdem, damit sich nach Nutzer filtern
     *    lässt, solange das Konto existiert.
     */
    public function up(): void
    {
        Schema::create('audit_log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Wer gehandelt hat. `null` steht für eine Aktion ohne angemeldetes
            // Konto (Konsole, geplanter Lauf) — der Klartext-Name bleibt.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('actor_email')->nullable();

            $table->string('action', 64);

            // Worauf sich die Aktion bezog. Bewusst ohne Fremdschlüssel: der
            // Betreff ist beim Löschen ja gerade weg, der Eintrag bleibt.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();

            // Geänderte Werte als {"Feld": {"before": …, "after": …}}.
            $table->json('changed_values')->nullable();

            // IPv6 braucht bis zu 45 Zeichen.
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('created_at')->nullable();

            // Die Ansicht zeigt immer eine Organisation, absteigend nach Zeit;
            // die Filter setzen auf Art und Nutzer auf.
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'action']);
            $table->index(['organization_id', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log_entries');
    }
};

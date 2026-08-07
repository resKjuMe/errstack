<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprache der Oberfläche je Konto. Null heißt „nicht gewählt" — dann gilt die
 * Sprache des Browsers und ersatzweise die Vorgabe aus config('app.locale').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale', 5)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};

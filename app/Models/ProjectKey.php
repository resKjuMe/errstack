<?php

namespace App\Models;

use Database\Factories\ProjectKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Client-Schlüssel ist die Adresse, an die eine Anwendung ihre Meldungen
 * schickt. Nach außen zeigt er sich als DSN in Sentry-Form
 * (`https://<public_key>@host/<project_id>`), damit die Original-SDKs
 * unverändert damit umgehen können.
 *
 * Mehrere Schlüssel je Projekt sind der Normalfall: je Umgebung oder je
 * Anwendung einer, damit sich einer zurückziehen lässt, ohne die übrigen
 * stillzulegen.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $public_key
 * @property bool $active
 * @property int|null $rate_limit_per_minute
 */
#[Fillable(['name', 'active', 'rate_limit_per_minute'])]
class ProjectKey extends Model
{
    /** @use HasFactory<ProjectKeyFactory> */
    use HasFactory;

    /**
     * Legt einen Schlüssel samt öffentlichem Teil an.
     *
     * Wie beim Projekt bewusst ein benannter Konstruktor statt eines
     * `creating`-Hooks: Seeder laufen mit abgeschalteten Model-Events und
     * würden ihn überspringen.
     */
    public static function createFor(Project $project, string $name, ?int $rateLimitPerMinute = null): self
    {
        $key = new self([
            'name' => $name,
            'active' => true,
            'rate_limit_per_minute' => $rateLimitPerMinute,
        ]);

        $key->project_id = $project->id;
        $key->public_key = self::freshPublicKey();
        $key->save();

        return $key;
    }

    /**
     * Neuer öffentlicher Schlüssel: 32 Hex-Zeichen aus dem Zufallsgenerator des
     * Systems. Dasselbe Format wie bei Sentry — nicht erratbar und ohne
     * Sonderzeichen, damit er in jede DSN passt.
     */
    public static function freshPublicKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Sucht den Schlüssel zu einem öffentlichen Teil — der Einstieg der
     * Datenaufnahme (P1). Abgeschaltete Schlüssel gelten als unbekannt, damit
     * eine Meldung mit zurückgezogenem Schlüssel abgewiesen wird.
     */
    public static function findActive(string $publicKey): ?self
    {
        return self::query()
            ->where('public_key', $publicKey)
            ->where('active', true)
            ->first();
    }

    /**
     * Zieht den Schlüssel neu. Ab dann werden Meldungen mit dem alten
     * abgewiesen; Name, Zustand und Kontingent bleiben erhalten.
     */
    public function rotate(): void
    {
        $this->public_key = self::freshPublicKey();
        $this->save();
    }

    /**
     * Die vollständige DSN, wie sie in die SDK-Konfiguration gehört. Host und
     * Schema stammen aus der Adresse der Installation (`APP_URL`), damit die
     * angezeigte DSN auch auf die zeigt, von der sie abgelesen wurde.
     */
    public function dsn(): string
    {
        $base = (string) config('app.url');
        $parts = parse_url($base);

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'localhost';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        // Liegt die Installation in einem Unterverzeichnis, gehört das vor die
        // Projekt-Nummer — sonst zeigt die DSN am Einstiegspunkt vorbei.
        $path = rtrim($parts['path'] ?? '', '/');

        return "{$scheme}://{$this->public_key}@{$host}{$port}{$path}/{$this->project_id}";
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'rate_limit_per_minute' => 'integer',
        ];
    }
}

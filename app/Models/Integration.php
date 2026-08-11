<?php

namespace App\Models;

use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\IntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Der Zugang einer Organisation zu einem Anbieter (X1).
 *
 * Er ist das, was zwischen „ein Repository heißt acme/webshop" (R2) und
 * „hol dort die Commits" fehlt: ein Token, das einer Organisation gehört und
 * länger lebt als die Anfrage, in der es entstand.
 *
 * **Das Token steht verschlüsselt in `credentials` und wird nirgends
 * ausgegeben.** Weder die Oberfläche noch das Änderungsprotokoll noch eine
 * Fehlermeldung bekommen es zu sehen — was hinausgeht, ist der Kontoname, unter
 * dem verbunden wurde. Deshalb ist `credentials` auch nicht `fillable`: ein
 * Massen-Zuweisen aus einer Anfrage ist genau der Weg, auf dem so etwas
 * versehentlich hineingerät.
 *
 * @property int $id
 * @property int $organization_id
 * @property IntegrationProvider $provider
 * @property string|null $account
 * @property string|null $external_id
 * @property array<string, mixed>|null $credentials
 * @property array<string, mixed>|null $settings
 * @property string|null $webhook_token_hash
 * @property IntegrationStatus $status
 * @property string|null $last_error
 * @property CarbonImmutable|null $last_error_at
 * @property CarbonImmutable|null $last_synced_at
 * @property int|null $connected_by_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Integration extends Model
{
    /** @use HasFactory<IntegrationFactory> */
    use HasFactory;

    /**
     * Länge einer gespeicherten Fehlermeldung (siehe Migration).
     */
    public const ERROR_LIMIT = 500;

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_id');
    }

    /**
     * Die Repositories, die über diese Anbindung hereingekommen sind.
     *
     * Nicht alle Repositories der Organisation: die von Hand eingetragenen
     * bleiben daneben bestehen, und sie sollen es auch — eine Bauumgebung ohne
     * Anbindung übergibt ihre Commits weiterhin selbst.
     *
     * @return HasMany<Repository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    /**
     * Das Zugriffstoken — der einzige Weg daran.
     *
     * Als Methode und nicht als Eigenschaft, damit an jeder Aufrufstelle
     * sichtbar bleibt, dass hier ein Geheimnis geholt wird. Ein
     * `$integration->token` verschwindet in einer Ausgabe, ohne dass es
     * jemandem auffällt.
     */
    public function token(): ?string
    {
        return $this->credential('token');
    }

    /**
     * Eine einzelne Angabe aus den Zugangsdaten.
     *
     * Nicht jede Anbindung kommt mit einem Token aus (X4): Jira braucht neben
     * dem API-Token die E-Mail-Adresse, mit der es ausgegeben wurde, und die
     * Adresse der Instanz — jede Organisation hat ihr eigenes
     * `acme.atlassian.net`, und eine Einstellung in der `.env` könnte davon
     * genau eine bedienen.
     *
     * Wie {@see token()} eine Methode und keine Eigenschaft: an der Aufrufstelle
     * soll sichtbar bleiben, dass hier aus der verschlüsselten Ablage gelesen
     * wird.
     */
    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Eine Einstellung dieser Anbindung — die Vorbelegung für neue Tickets (X4).
     *
     * Anders als {@see credential()} steht hier nichts Geheimes: wer die
     * Datenbank liest, erfährt, dass Tickets im Projekt `OPS` angelegt werden.
     * Leere Zeichenketten kommen als `null` heraus — ein leeres Feld im Formular
     * ist „nicht vorbelegt" und nicht „vorbelegt mit nichts", und der
     * Unterschied entscheidet darüber, ob ein Feld an den Anbieter mitgeschickt
     * wird (ein leeres `assignee` ist bei Jira kein „niemand", sondern ein
     * Prüffehler).
     */
    public function setting(string $key): ?string
    {
        $value = $this->settings[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * Ob ein geschlossenes Ticket den Fehler hier erledigt.
     *
     * **Standard ist an.** Das ist die Richtung, um derentwillen jemand ein
     * Ticket-System anbindet — und wer sie nicht will, schaltet sie ab
     * (Abnahmekriterium „Statusabgleich ist je Richtung schaltbar").
     */
    public function syncsInbound(): bool
    {
        return (bool) ($this->settings['sync_inbound'] ?? true);
    }

    /**
     * Ob ein hier erledigter Fehler das Ticket schließt.
     *
     * Ebenfalls standardmäßig an, aber die riskantere der beiden Richtungen: sie
     * schreibt in einem fremden System. Deshalb ist sie einzeln abschaltbar und
     * nicht nur zusammen mit der anderen — es gibt Teams, die ihre Vorgänge
     * ausschließlich drüben schließen wollen, weil dort eine Abnahme dranhängt.
     */
    public function syncsOutbound(): bool
    {
        return (bool) ($this->settings['sync_outbound'] ?? true);
    }

    /**
     * Das Geheimnis in der Rückadresse — der Nachweis für eingehende Meldungen
     * von Jira und Linear (X4).
     *
     * Es steht bei den Zugangsdaten, weil die Oberfläche die vollständige
     * Adresse zum Eintragen anzeigen muss; gesucht wird über
     * `webhook_token_hash`. Warum überhaupt ein Geheimnis in der Adresse steht
     * und nicht wie bei GitHub eine Unterschrift geprüft wird, steht in der
     * Wanderung, die beide Spalten anlegt.
     */
    public function webhookToken(): ?string
    {
        return $this->credential('webhook_token');
    }

    /**
     * Die Anbindung zu einem Geheimnis aus der Rückadresse — oder keine.
     *
     * Über den Hash, weil die verschlüsselte Ablage nicht abfragbar ist (die
     * Verschlüsselung ist nicht deterministisch). Der Anbieter steht mit in der
     * Bedingung: eine Adresse für Jira soll nicht auf eine Linear-Anbindung
     * passen, auch wenn das Geheimnis stimmte.
     */
    public static function byWebhookToken(IntegrationProvider $provider, string $token): ?self
    {
        if (trim($token) === '') {
            return null;
        }

        return self::query()
            ->where('provider', $provider->value)
            ->where('webhook_token_hash', self::hashWebhookToken($token))
            ->first();
    }

    public static function hashWebhookToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Ob die Anbindung benutzbar ist.
     *
     * Beides zusammen: ein Zugang ohne Token ist keiner, und einer, dessen
     * Token abgelehnt wurde, ist keiner mehr. Die Aufrufer prüfen damit **eine**
     * Bedingung statt zwei, die auseinanderlaufen können.
     */
    public function isUsable(): bool
    {
        return $this->status === IntegrationStatus::Connected && $this->token() !== null;
    }

    /**
     * Ein gelungener Aufruf.
     *
     * Er räumt die alte Fehlermeldung weg — sonst stünde auf der Seite noch
     * wochenlang, woran es einmal gescheitert ist, obwohl längst wieder alles
     * geht.
     *
     * **Eine noch nicht gespeicherte Anbindung wird nicht angelegt.** Beim
     * Verbinden eines Ticket-Systems (X4) wird das Token an einer
     * ungespeicherten Zeile geprüft; ein `save()` hier würde sie in die Datenbank
     * schreiben, bevor jemand entschieden hat, dass sie hineingehört.
     */
    public function markSynced(): void
    {
        if (! $this->exists) {
            return;
        }

        $this->forceFill([
            'status' => IntegrationStatus::Connected,
            'last_error' => null,
            'last_error_at' => null,
            'last_synced_at' => CarbonImmutable::now(),
        ])->save();
    }

    /**
     * Der Zugang wurde abgelehnt.
     *
     * Nur für den abgelehnten Zugang gedacht, nicht für jeden Fehlschlag: ein
     * Netzfehler geht vorbei, ein zurückgezogenes Token nicht. Wer beides gleich
     * behandelt, macht aus einer kurzen Störung eine Anzeige, die jemand von
     * Hand wegklicken muss.
     *
     * Und wie {@see markSynced()}: eine noch nicht gespeicherte Anbindung wird
     * nicht angelegt. Ein abgelehntes Token beim Verbinden darf keine Zeile
     * hinterlassen, die „Verbindung verloren" anzeigt, obwohl nie eine bestand.
     */
    public function markDisconnected(string $reason): void
    {
        if (! $this->exists) {
            return;
        }

        $this->forceFill([
            'status' => IntegrationStatus::Disconnected,
            'last_error' => Str::limit($reason, self::ERROR_LIMIT, ''),
            'last_error_at' => CarbonImmutable::now(),
        ])->save();
    }

    /**
     * Die Anbindung dieser Organisation an einen Anbieter — oder keine.
     */
    public static function forOrganization(
        Organization|int $organization,
        IntegrationProvider $provider = IntegrationProvider::GitHub,
    ): ?self {
        return self::query()
            ->where('organization_id', $organization instanceof Organization ? $organization->id : $organization)
            ->where('provider', $provider->value)
            ->first();
    }

    /**
     * `credentials` fehlt mit Absicht — siehe den Kommentar an der Klasse.
     *
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'account',
        'external_id',
        'settings',
        'status',
        'connected_by_id',
    ];

    /**
     * Was nie in einer Ausgabe landen darf. Der Doppelschutz zum fehlenden
     * `fillable`: das eine hält es aus der Anfrage heraus, das andere aus der
     * Antwort.
     *
     * @var list<string>
     */
    protected $hidden = [
        'credentials',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'status' => IntegrationStatus::class,
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'last_error_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

<?php

namespace App\Models;

use App\Enums\ExternalIssueState;
use App\Enums\IntegrationProvider;
use Carbon\CarbonImmutable;
use Database\Factories\IssueLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Die Verknüpfung eines Fehlers mit einem Ticket beim Anbieter (X1).
 *
 * Sie ist eine Aussage über zwei Dinge, die beide für sich bestehen: der Fehler
 * hier hat auch ohne Ticket seinen Zustand, und das Ticket dort steht auch ohne
 * diese Zeile. Was sie hinzufügt, ist die Frage „wird daran gearbeitet?" —
 * beantwortbar, ohne zwei Anwendungen nebeneinander offen zu haben.
 *
 * **Titel und Adresse stehen als Text darin.** Sie werden nicht bei jedem
 * Anzeigen nachgeschlagen: eine Fehlerseite soll sich nicht deshalb um eine
 * Netzwerkrunde verzögern, weil oben rechts ein Ticket verlinkt ist. Aktuell
 * hält sie der Webhook — und wo keiner eingerichtet ist, steht eben der Stand
 * vom Verknüpfen. Das ist der ehrlichere Preis: eine veraltete Überschrift
 * neben einem Link ist harmlos, eine Seite, die ohne GitHub nicht mehr lädt,
 * nicht.
 *
 * **`repository` heißt bei den Ticket-Systemen Projekt bzw. Team** (X4): dort
 * steht `OPS` oder `ENG`, nicht `acme/webshop`. Der Name der Spalte ist der von
 * X1 geblieben, weil sie dieselbe Rolle trägt — der Behälter, in dem die Nummer
 * gilt — und eine Wanderung, die eine Spalte umbenennt, zwei Auslieferungen
 * braucht (Migrations-Job vor dem Slot-Wechsel), ohne dass danach irgendetwas
 * anders wäre.
 *
 * @property int $id
 * @property int $issue_id
 * @property int|null $integration_id
 * @property IntegrationProvider $provider
 * @property string $repository
 * @property int $number
 * @property string|null $external_id
 * @property string|null $title
 * @property string $url
 * @property ExternalIssueState $state
 * @property bool $created_remotely
 * @property int|null $linked_by_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class IssueLink extends Model
{
    /** @use HasFactory<IssueLinkFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * @return BelongsTo<Integration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_id');
    }

    /**
     * Wie das Ticket genannt wird, wenn man es kurz nennen muss:
     * „acme/webshop#42" bei GitHub, „OPS-42" bei Jira und Linear.
     *
     * Behälter und Nummer und nicht nur die Nummer — `#42` gibt es in jedem
     * Repository, und ein Fehler kann mit Tickets aus mehreren verknüpft sein.
     *
     * **Die Schreibweise ist die des Anbieters** (X4) und nicht eine eigene, die
     * für alle gilt. Das ist keine Höflichkeit: `OPS-42` ist die Kennung, mit der
     * ein Jira-Vorgang gesucht, verlinkt und im Gespräch genannt wird, und ein
     * `OPS#42` wäre in jeder Suche drüben ein Fehlschlag. Wer die Kennung aus der
     * Anzeige kopiert, soll damit arbeiten können.
     */
    public function reference(): string
    {
        return $this->provider->isTicketProvider()
            ? $this->repository.'-'.$this->number
            : $this->repository.'#'.$this->number;
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'issue_id',
        'integration_id',
        'provider',
        'repository',
        'number',
        'external_id',
        'title',
        'url',
        'state',
        'created_remotely',
        'linked_by_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'state' => ExternalIssueState::class,
            'number' => 'integer',
            'created_remotely' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

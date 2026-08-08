<?php

namespace App\Models;

use App\Support\Ingest\Processing\Steps\GroupEvent;
use Database\Factories\IssueDiscardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Fingerabdruck, dessen Meldungen künftig verworfen werden — die Folge von
 * „löschen und künftig verwerfen".
 *
 * Ohne diesen Vermerk wäre die Aktion wirkungslos: das Löschen entfernt Eintrag
 * und Gruppe, die nächste Meldung rechnet denselben Fingerabdruck und legt
 * beides erneut an. Der Fehler wäre nach Sekunden wieder da, und die einzige
 * Erklärung dafür wäre „hat nicht funktioniert".
 *
 * **Der Fingerabdruck und nicht die Gruppen-Kennung**: die Gruppe geht mit dem
 * Löschen, der Fingerabdruck wird bei jeder Meldung neu gerechnet. Er ist das
 * einzige, was die gelöschte Sache wiedererkennbar macht.
 *
 * Ausgewertet wird der Vermerk in {@see GroupEvent}, direkt nachdem der
 * Fingerabdruck feststeht und **bevor** eine Gruppe entsteht — sonst legte das
 * Verwerfen genau das wieder an, was es verhindern soll.
 *
 * @property int $id
 * @property int $project_id
 * @property string $fingerprint
 * @property string|null $title
 * @property int|null $user_id
 */
#[Fillable(['project_id', 'fingerprint', 'title', 'user_id'])]
class IssueDiscard extends Model
{
    /** @use HasFactory<IssueDiscardFactory> */
    use HasFactory;

    /**
     * Gilt dieser Fingerabdruck in diesem Projekt als verworfen?
     *
     * Eine Abfrage je Meldung wäre an dieser Stelle die falsche Rechnung — der
     * Aufruf sitzt in der Aufnahme, also auf dem heißesten Weg dieser Anwendung.
     * Die Antwort wird deshalb kurz zwischengespeichert: die Liste ändert sich
     * durch eine Handlung eines Menschen, nicht durch den Betrieb, und ein
     * Fenster von einer Minute zwischen „verworfen" und „wirkt" ist bei einer
     * Aktion, die Wochen gilt, ohne Bedeutung.
     */
    public static function blocks(int $projectId, string $fingerprint): bool
    {
        return cache()->remember(
            self::cacheKey($projectId, $fingerprint),
            now()->addMinute(),
            fn (): bool => self::query()
                ->where('project_id', $projectId)
                ->where('fingerprint', $fingerprint)
                ->exists(),
        );
    }

    /**
     * Nimmt einen Fingerabdruck in die Verwerfungsliste auf.
     *
     * `updateOrCreate` und nicht `create`: derselbe Fehler kann ein zweites Mal
     * gelöscht werden, nachdem er zwischenzeitlich wieder aufgetreten war und
     * jemand die Verwerfung aufgehoben hatte — oder schlicht aus zwei Fenstern
     * gleichzeitig. Ein Verstoß gegen den eindeutigen Index wäre dann eine
     * Fehlermeldung für einen Vorgang, der genau das Gewünschte bereits
     * hergestellt hat.
     */
    public static function add(int $projectId, string $fingerprint, ?string $title, ?int $userId): self
    {
        $discard = self::query()->updateOrCreate(
            ['project_id' => $projectId, 'fingerprint' => $fingerprint],
            ['title' => $title, 'user_id' => $userId],
        );

        cache()->forget(self::cacheKey($projectId, $fingerprint));

        return $discard;
    }

    /**
     * Hebt eine Verwerfung wieder auf — der Rückweg der Aktion.
     *
     * Er macht das Löschen nicht rückgängig; der Eintrag ist weg. Was er
     * herstellt, ist die Bereitschaft, denselben Fehler wieder anzunehmen, und
     * genau das ist der Teil der Aktion, den jemand versehentlich auslöst.
     */
    public static function remove(int $projectId, string $fingerprint): void
    {
        self::query()
            ->where('project_id', $projectId)
            ->where('fingerprint', $fingerprint)
            ->delete();

        cache()->forget(self::cacheKey($projectId, $fingerprint));
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    private static function cacheKey(int $projectId, string $fingerprint): string
    {
        return 'issue-discard:'.$projectId.':'.$fingerprint;
    }
}

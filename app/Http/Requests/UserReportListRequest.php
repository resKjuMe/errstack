<?php

namespace App\Http\Requests;

use App\Enums\UserReportStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Die Rückmeldungs-Liste als Eingabe: die Felder der globalen Filterleiste, dazu
 * Bearbeitungsstand, Zuweisung und Seite.
 *
 * Wie überall steht der ganze Zustand in der Adresszeile: „was liegt bei mir und
 * ist noch offen?" soll ein Link sein und keine Klickanleitung.
 */
class UserReportListRequest extends GlobalFilterRequest
{
    /** Der Wert, mit dem der Zustandsfilter abgeschaltet wird. */
    public const STATUS_ANY = 'alle';

    /** Der Wert, mit dem die Liste auf die eigenen Rückmeldungen zeigt. */
    public const ASSIGNEE_ME = 'ich';

    /** Der Wert für „hat noch niemand übernommen". */
    public const ASSIGNEE_NOBODY = 'niemand';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'status' => ['nullable', Rule::in([self::STATUS_ANY, ...array_column(UserReportStatus::cases(), 'value')])],
            'assignee' => ['nullable', Rule::in([self::ASSIGNEE_ME, self::ASSIGNEE_NOBODY])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Der gewählte Bearbeitungsstand, oder `null` für „alle".
     *
     * Ohne Angabe: **alle**. Anders als bei der Fehlerliste, wo die Vorgabe die
     * offenen Einträge sind, und das mit Absicht. Eine Fehlerliste ist eine
     * Arbeitsliste, die ohne Vorauswahl zuläuft; die Rückmeldungen sind wenige,
     * und eine Liste, die erledigte Zuschriften von sich aus versteckt, sagt die
     * Unwahrheit darüber, wie viel überhaupt ankam.
     */
    public function status(): ?UserReportStatus
    {
        $status = (string) ($this->validated('status') ?? '');

        return $status === '' || $status === self::STATUS_ANY
            ? null
            : UserReportStatus::tryFrom($status);
    }

    /**
     * Die gewählte Einschränkung auf eine Zuweisung, oder `null`.
     */
    public function assignee(): ?string
    {
        $assignee = (string) ($this->validated('assignee') ?? '');

        return $assignee === '' ? null : $assignee;
    }

    /**
     * Die Werte, wie die Oberfläche sie in ihren Feldern führt.
     *
     * @return array{status: string, assignee: string}
     */
    public function listValues(): array
    {
        $status = $this->status();

        return [
            'status' => $status === null ? self::STATUS_ANY : $status->value,
            'assignee' => $this->assignee() ?? '',
        ];
    }
}

<?php

namespace App\Support\Discover\Datasets;

use App\Models\UserReport;
use App\Support\Discover\Dataset;
use App\Support\Discover\FieldDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * Die Felder der Rückmeldungen von Nutzern (M6).
 *
 * Die kleinste der Quellen, und die einzige, in der es nichts zu rechnen gibt außer
 * Zählen: eine Rückmeldung hat keine Dauer und keinen Ausgang. Gefragt wird nach
 * ihrer Verteilung — „welche Seite beschweren sich Nutzer über?", „wie viele
 * Rückmeldungen kamen diese Woche?" — und genau das leisten `count()`,
 * `count_unique(email)` und eine Gruppierung.
 *
 * **Die Adresse steht hier vollständig**, anders als bei den Fehlermeldungen: sie
 * kommt aus dem Formular und ist die Seite, auf der jemand geschrieben hat. Wo ein
 * Abfrageteil daran hängt, gehört er zur Meldung — es sind Größenordnungen weniger
 * Zeilen, und die Aufschlüsselung zerfällt daran nicht.
 */
final class UserReportFields extends AbstractDatasetFields
{
    public function dataset(): Dataset
    {
        return Dataset::UserReports;
    }

    public function query(): Builder
    {
        return UserReport::query();
    }

    public function timeColumn(): string
    {
        return 'user_reports.received_at';
    }

    protected function freeTextColumns(): array
    {
        return ['user_reports.comments'];
    }

    /**
     * @return array<string, FieldDefinition>
     */
    protected function definitions(): array
    {
        return $this->keyed([
            $this->text('status', 'user_reports.status'),
            $this->text('url', 'user_reports.url'),
            $this->text('email', 'user_reports.email'),
            $this->text('name', 'user_reports.name'),
            $this->text('issue_id', 'user_reports.issue_id'),
            $this->text('assigned_to', 'user_reports.assigned_to'),
            $this->timestamp('received_at', 'user_reports.received_at'),
        ]);
    }
}

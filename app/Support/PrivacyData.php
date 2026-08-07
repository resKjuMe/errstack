<?php

namespace App\Support;

use App\Enums\ScrubRuleType;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ScrubRule;
use App\Models\User;
use App\Support\Ingest\Scrubbing\Defaults;
use App\Support\Ingest\Scrubbing\Scrubber;
use Illuminate\Support\Facades\Gate;

/**
 * Nutzlast der Datenschutz-Seiten — für ein Projekt und für eine Organisation.
 *
 * Eine Klasse für beide Ebenen, weil es dieselbe Seite ist: dieselben Regeln,
 * dieselbe Liste, dieselbe Erklärung. Was sich unterscheidet, sind die Schalter
 * (nur am Projekt), die Vorschau (nur am Projekt, weil erst dort feststeht,
 * welche Regeln zusammen gelten) und die geerbten Regeln, die das Projekt
 * anzeigt, ohne sie ändern zu können.
 *
 * Die Standardregeln werden mitgeliefert und nicht nur erwähnt. Eine Zusage wie
 * „Passwörter werden immer entfernt" ist nur so viel wert, wie sie nachprüfbar
 * ist — wer die Liste sieht, kann seine eigene Regel dazuschreiben, statt eine
 * doppelt anzulegen.
 */
final class PrivacyData
{
    /**
     * @return array<string, mixed>
     */
    public static function forProject(Project $project, User $viewer): array
    {
        $project->loadMissing('organization');
        $organization = $project->organization;

        $mayManage = Gate::forUser($viewer)->allows('update', $project);

        $inherited = $organization->scrubRules()->orderBy('id')->get();
        $own = $project->scrubRules()->orderBy('id')->get();

        return self::common($organization) + [
            'scope' => 'project',
            'project' => [
                'slug' => $project->slug,
                'name' => $project->name,
                'href' => route('projects.show', [$organization, $project]),
                'privacyHref' => route('projects.privacy.index', [$organization, $project]),
                'optionsHref' => route('projects.privacy.update', [$organization, $project]),
                'rulesHref' => route('projects.privacy.rules.store', [$organization, $project]),
                'previewHref' => route('projects.privacy.preview', [$organization, $project]),
            ],
            'options' => [
                'scrub_ip_addresses' => $project->scrub_ip_addresses,
                'scrub_user_data' => $project->scrub_user_data,
                'scrub_attachments' => $project->scrub_attachments,
            ],
            'permissions' => ['manage' => $mayManage],
            'rules' => $own
                ->map(fn (ScrubRule $rule): array => self::rule($rule))
                ->all(),
            'inheritedRules' => $inherited
                ->map(fn (ScrubRule $rule): array => self::rule($rule))
                ->all(),
            'sample' => self::sample(),
            // Das Ergebnis der letzten Vorschau, sofern gerade eine berechnet
            // wurde. Es kommt aus der Sitzung und nicht aus einem geteilten
            // Inertia-Prop: es gehört zu dieser einen Seite, und ein an jeder
            // Antwort hängender Wert wäre ein Zustand, den alle anderen Seiten
            // mittragen, ohne ihn zu brauchen.
            'preview' => session('scrubPreview'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forOrganization(Organization $organization, User $viewer): array
    {
        $mayManage = Gate::forUser($viewer)->allows('manageProjects', $organization);

        return self::common($organization) + [
            'scope' => 'organization',
            'project' => null,
            'options' => null,
            'permissions' => ['manage' => $mayManage],
            'rules' => $organization->scrubRules()
                ->orderBy('id')
                ->get()
                ->map(fn (ScrubRule $rule): array => self::rule($rule))
                ->all(),
            'inheritedRules' => [],
            // Die Vorschau gibt es nur am Projekt: erst dort steht fest, welche
            // Regeln und Schalter zusammen gelten, und eine Vorschau über die
            // organisationsweiten allein würde ein anderes Ergebnis zeigen als
            // das, was mit einer Meldung wirklich passiert.
            'sample' => null,
            'preview' => null,
        ];
    }

    /**
     * Was beide Ebenen gemeinsam haben.
     *
     * @return array<string, mixed>
     */
    private static function common(Organization $organization): array
    {
        return [
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
                'privacyHref' => route('organizations.privacy.index', $organization),
                'rulesHref' => route('organizations.privacy.rules.store', $organization),
            ],
            'typeOptions' => ScrubRuleType::options(),
            'filteredMarker' => Scrubber::FILTERED,
            'defaults' => [
                'fields' => Defaults::FIELDS,
                'patterns' => Defaults::PATTERNS,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rule(ScrubRule $rule): array
    {
        return [
            'id' => $rule->id,
            'type' => $rule->type->value,
            'typeLabel' => $rule->type->label(),
            'expression' => $rule->expression,
            'path' => $rule->path,
            'isActive' => $rule->is_active,
            // Ändern und Löschen laufen über dieselbe Adresse — eine Angabe
            // statt zweier, die auseinanderlaufen können.
            'href' => route('scrub-rules.update', $rule),
        ];
    }

    /**
     * Das Beispielereignis der Vorschau.
     *
     * Es ist absichtlich keine echte Meldung aus dem Projekt: eine echte enthielte
     * die Daten, um die es hier geht, und die Vorschau wäre dann selbst die Stelle,
     * an der sie jemand zu sehen bekommt, der sie nicht sehen soll. Das Beispiel
     * enthält dafür alles, was die Regeln treffen können — Standardfelder, einen
     * eingebetteten Nachweis, die Adresse des Betroffenen und ein frei geformtes
     * Feld für eigene Regeln.
     *
     * @return array<string, mixed>
     */
    public static function sample(): array
    {
        return [
            'event_id' => str_repeat('a1b2c3d4', 4),
            'level' => 'error',
            'message' => 'Zahlung fehlgeschlagen für Karte 4111111111111111.',
            'user' => [
                'id' => '4711',
                'email' => 'kundin@example.com',
                'ip_address' => '203.0.113.42',
            ],
            'request' => [
                'url' => 'https://example.com/kasse',
                'method' => 'POST',
                'headers' => [
                    'Authorization' => 'Bearer abcdef1234567890',
                    'User-Agent' => 'Mozilla/5.0',
                    'X-Forwarded-For' => '203.0.113.42',
                ],
                'cookies' => [
                    'session' => 'sk_9f3c1a',
                ],
                'data' => [
                    'password' => 'geheim123',
                    'kundennummer' => 'K-000815',
                    'betrag' => 4990,
                ],
            ],
            'extra' => [
                'api_key' => 'ak_live_0123456789',
                'schritt' => 'zahlung.autorisieren',
            ],
        ];
    }
}

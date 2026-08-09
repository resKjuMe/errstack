<?php

namespace App\Http\Requests;

use App\Enums\WidgetType;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\SavedQuery;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * „Diese Auswertung als Kachel" — die Eingabe dazu ist kurz, weil die Frage
 * schon feststeht: wohin, wie dargestellt, unter welcher Überschrift.
 *
 * **Die Abfrage steht ausdrücklich nicht darin.** Sie kommt aus der
 * gespeicherten Auswertung und nicht aus dem Browser; sonst wäre die Adresse
 * „übernimm Gespeichertes" nur ein zweiter Weg, eine beliebige Kachel
 * anzulegen — und was auf dem Dashboard landete, hätte mit dem, was in der
 * Leiste steht, nichts zu tun.
 *
 * **Überschrift und Darstellungsart dürfen fehlen.** Dann heißt die Kachel wie
 * die Auswertung und zeigt eine Tabelle: das ist die Darstellung, die zu
 * Sortierung und Zeilenzahl einer Auswertung gehört, und damit die einzige, die
 * ohne Rückfrage richtig ist. Genau das macht aus „übernehmen" einen Klick.
 */
class SavedQueryWidgetRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'dashboard' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:'.DashboardWidget::TITLE_LIMIT],
            'type' => ['nullable', Rule::enum(WidgetType::class)],
        ];
    }

    /**
     * Das Dashboard, auf das übernommen wird — aus derselben Organisation wie
     * die Auswertung.
     *
     * Die Einschränkung steht hier und nicht in einer `exists`-Regel: ein
     * Dashboard einer fremden Organisation ist an dieser Stelle nicht
     * „ungültig", sondern nicht vorhanden — die Adresse nennt beide, und wenn
     * sie nicht zusammengehören, meint sie nichts.
     */
    public function dashboard(SavedQuery $saved): Dashboard
    {
        $dashboard = Dashboard::query()
            ->where('organization_id', $saved->organization_id)
            ->find((int) $this->validated('dashboard'));

        if (! $dashboard instanceof Dashboard) {
            throw new NotFoundHttpException;
        }

        return $dashboard;
    }

    public function title(SavedQuery $saved): string
    {
        $title = trim((string) ($this->validated('title') ?? ''));

        return $title === '' ? $saved->name : $title;
    }

    public function type(): WidgetType
    {
        $type = $this->validated('type');

        return is_string($type) && $type !== '' ? WidgetType::from($type) : WidgetType::Table;
    }
}

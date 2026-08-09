<?php

namespace App\Http\Requests;

use App\Support\Dashboards\DashboardLayout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Die neue Anordnung nach dem Verschieben oder Vergrößern.
 *
 * **Eine Anfrage für das ganze Raster und nicht eine je Kachel.** Wer eine
 * Kachel verschiebt, verschiebt selten nur sie: die Oberfläche rückt die
 * darunter nach, und das ist eine einzige Bewegung. Als zehn Anfragen wäre sie
 * zehnmal halb angekommen, wenn eine davon scheitert — und die Anordnung im
 * Browser stimmte dann mit keiner in der Datenbank überein.
 *
 * **Geschickt wird nur die Lage.** Überschrift, Abfrage und die eigene Sicht auf
 * die Filterleiste bleiben unangetastet: Schieben ist keine Bearbeitung, und ein
 * Aufruf, der beides könnte, würde beim Schieben Felder überschreiben, die
 * gerade in einem anderen Reiter geändert wurden.
 */
class DashboardLayoutRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'widgets' => ['required', 'array', 'max:'.DashboardLayout::MAX_WIDGETS],
            'widgets.*.id' => ['required', 'integer'],
            'widgets.*.x' => ['required', 'integer', 'min:0', 'max:'.(DashboardLayout::COLUMNS - 1)],
            'widgets.*.y' => ['required', 'integer', 'min:0'],
            'widgets.*.width' => ['required', 'integer', 'min:'.DashboardLayout::MIN_WIDTH, 'max:'.DashboardLayout::COLUMNS],
            'widgets.*.height' => ['required', 'integer', 'min:'.DashboardLayout::MIN_HEIGHT, 'max:'.DashboardLayout::MAX_HEIGHT],
        ];
    }

    /**
     * Die Anordnung nach Kachel-Nummer, jede Lage bereits ins Raster gerückt.
     *
     * @return array<int, array{x: int, y: int, width: int, height: int}>
     */
    public function placements(): array
    {
        $placements = [];

        /** @var list<array{id: int|string, x: int|string, y: int|string, width: int|string, height: int|string}> $widgets */
        $widgets = $this->validated('widgets');

        foreach ($widgets as $widget) {
            $placements[(int) $widget['id']] = DashboardLayout::normalize(
                (int) $widget['x'],
                (int) $widget['y'],
                (int) $widget['width'],
                (int) $widget['height'],
            );
        }

        return $placements;
    }
}

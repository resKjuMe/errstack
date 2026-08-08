<?php

namespace App\Http\Middleware;

use App\Support\FilterData;
use App\Support\Filters\CurrentFilter;
use App\Support\SelfMonitoring\BrowserConfig;
use App\Support\ShellData;
use App\Support\Translations;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * Das Root-Blade, in das Inertia beim ersten (Voll-)Laden rendert.
     */
    protected $rootView = 'app-root';

    /**
     * Bei jeder Inertia-Antwort geteilte Props. `shell` versorgt das persistente
     * React-Grundgerüst (Navigation, Menü, Labels) und wird pro Navigation neu
     * berechnet; `flash` transportiert die Session-Meldungen.
     *
     * `translations` liegt bewusst an jeder Antwort und nicht nur am ersten
     * Laden: nach einem Sprachwechsel kommt die nächste Inertia-Antwort ohne
     * neues Root-Blade, und eine einmal eingebettete Tabelle bliebe dann in der
     * alten Sprache stehen.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'shell' => fn () => ShellData::build(),
            'locale' => fn () => app()->getLocale(),
            'translations' => fn () => Translations::forInterface(),
            'formats' => fn () => Translations::formats(),
            'flash' => fn () => [
                'status' => $request->session()->get('status'),
                'error' => $request->session()->get('error'),
                // Der Rückweg zur letzten Aktion (S6): nur Kennmarke und
                // Beschriftung. Was dahintersteht, bleibt auf dem Server —
                // sonst könnte die Seite bestimmen, was zurückgenommen wird.
                'undo' => $request->session()->get('undo'),
            ],
            // Die globale Filterleiste gehört zum Rahmen und nicht zur Seite:
            // sie steht auf jeder Auswertungsseite an derselben Stelle, und eine
            // neue Auswertungsseite bekommt sie, ohne sie einzubinden.
            //
            // `null` heißt „hier keine Leiste": geliefert wird sie genau dann,
            // wenn die Seite den Filter angefordert hat ({@see CurrentFilter}) —
            // Einstellungen, Profil und Verwaltung tun das nicht.
            'filter' => fn () => ($filter = CurrentFilter::of($request)) === null
                ? null
                : FilterData::bar($filter),
            // Womit sich die Oberfläche bei der Selbstüberwachung meldet.
            // `null`, solange keine DSN eingerichtet ist — dann lädt die Seite
            // das SDK gar nicht erst ({@see resources/js/selfmonitoring.js}).
            'selfMonitoring' => fn () => BrowserConfig::build(),
        ]);
    }
}

<?php

namespace App\Http\Middleware;

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
            ],
            // Womit sich die Oberfläche bei der Selbstüberwachung meldet.
            // `null`, solange keine DSN eingerichtet ist — dann lädt die Seite
            // das SDK gar nicht erst ({@see resources/js/selfmonitoring.js}).
            'selfMonitoring' => fn () => BrowserConfig::build(),
        ]);
    }
}

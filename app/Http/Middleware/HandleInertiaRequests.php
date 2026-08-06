<?php

namespace App\Http\Middleware;

use App\Support\ShellData;
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
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'shell' => fn () => ShellData::build(),
            'flash' => fn () => [
                'status' => $request->session()->get('status'),
                'error' => $request->session()->get('error'),
            ],
        ]);
    }
}

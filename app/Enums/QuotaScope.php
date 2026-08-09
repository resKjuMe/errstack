<?php

namespace App\Enums;

use App\Models\ProjectKey;

/**
 * Auf welcher Ebene ein Kontingent gilt.
 *
 * Drei Ebenen, weil drei verschiedene Fragen dahinterstehen: Was darf die
 * ganze Installation dieser Organisation kosten? Was darf ein einzelnes
 * Projekt davon verbrauchen? Und wie schnell darf eine einzelne Anwendung
 * melden? Die Ebenen begrenzen sich nicht gegenseitig, sie gelten
 * **nebeneinander** — die engste Grenze entscheidet.
 *
 * Der Schlüssel hat als einziger keine Datenarten: sein Wert steht am
 * Schlüssel selbst ({@see ProjectKey::$rate_limit_per_minute}) und meint alles,
 * was über ihn hereinkommt. Er ist die Notbremse für eine einzelne Anwendung,
 * die durchdreht — und die soll nicht erst greifen, wenn jemand die richtige
 * Datenart erraten hat.
 */
enum QuotaScope: string
{
    case Organization = 'organization';

    case Project = 'project';

    case Key = 'key';

    public function label(): string
    {
        return __('enums.quota_scope.'.$this->value);
    }
}

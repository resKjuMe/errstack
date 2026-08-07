<?php

namespace App\Support\Ingest\Normalization\Sections;

use App\Support\Ingest\Normalization\Sanitizer;
use App\Support\Ingest\Scrubbing\Scrubber;

/**
 * Wen der Fehler getroffen hat.
 *
 * Der Abschnitt beantwortet die Frage, die nach „was ist passiert" immer als
 * zweite kommt: wie viele sind betroffen — einer oder alle. Ohne ihn hat jeder
 * Fehler dasselbe Gewicht.
 *
 * Die bekannten Felder (`id`, `username`, `email`, `ip_address`, `geo`) haben
 * eigene Fächer, weil sie überall wieder gebraucht werden: die Kennung zum
 * Zählen der Betroffenen, die Adresse zum Anschreiben, der Ort für die
 * Verteilung. Alles Weitere, was ein SDK mitgibt, bleibt daneben erhalten —
 * verworfen wird hier nichts, was die Anwendung für wichtig genug hielt.
 *
 * Was davon überhaupt gespeichert werden darf, entscheidet nicht dieser
 * Abschnitt, sondern das Scrubbing (I7) davor.
 */
final class User
{
    /**
     * Felder, die Sentry kennt — alles andere zählt als Zugabe der Anwendung.
     */
    private const KNOWN = ['id', 'username', 'email', 'ip_address', 'name', 'segment', 'geo'];

    public function __construct(
        private readonly Sanitizer $sanitizer,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function normalize(mixed $user, string $path): ?array
    {
        $user = $this->sanitizer->map($user, $path);

        if ($user === null) {
            return null;
        }

        $normalized = [];

        foreach (['id', 'username', 'email', 'name', 'segment'] as $field) {
            $value = $this->sanitizer->text($user[$field] ?? null, $path.'.'.$field, 400);

            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        $ip = $this->ipAddress($user['ip_address'] ?? null, $path.'.ip_address');

        if ($ip !== null) {
            $normalized['ip_address'] = $ip;
        }

        $geo = $this->geo($user['geo'] ?? null, $path.'.geo');

        if ($geo !== null) {
            $normalized['geo'] = $geo;
        }

        $extra = [];

        foreach ($user as $key => $value) {
            if (! in_array($key, self::KNOWN, true)) {
                $extra[$key] = $value;
            }
        }

        if ($extra !== []) {
            $normalized['data'] = $this->sanitizer->freeform($extra, $path.'.data');
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * Die Adresse des Clients.
     *
     * `{{auto}}` ist keine Adresse, sondern die Bitte des SDK, die des
     * Absenders einzusetzen. Die Aufnahme kennt sie, dieser Schritt nicht mehr
     * — hier bliebe nur, die Platzhalter-Zeichenkette als Adresse abzulegen,
     * und danach hätten alle Betroffenen dieselbe.
     */
    private function ipAddress(mixed $value, string $path): ?string
    {
        $text = $this->sanitizer->text($value, $path, 45);

        // Der Vermerk des Scrubbings gehört zu denselben Fällen: er ist keine
        // Adresse, aber auch keine kaputte Angabe — hier stand eine, und sie
        // durfte nicht bleiben. Ohne ihn stünde bei jeder Meldung eines Projekts
        // ohne IP-Speicherung ein „ungültig"-Vermerk an einem Feld, an dem alles
        // richtig gelaufen ist.
        if ($text === null || $text === '{{auto}}' || $text === Scrubber::FILTERED) {
            return null;
        }

        if (filter_var($text, FILTER_VALIDATE_IP) === false) {
            $this->sanitizer->notes()->invalid($path);

            return null;
        }

        return $text;
    }

    /**
     * @return array<string, string>|null
     */
    private function geo(mixed $geo, string $path): ?array
    {
        $geo = $this->sanitizer->map($geo, $path);

        if ($geo === null) {
            return null;
        }

        $normalized = [];

        foreach (['city', 'country_code', 'region', 'subdivision'] as $field) {
            $value = $this->sanitizer->text($geo[$field] ?? null, $path.'.'.$field, 200);

            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        return $normalized === [] ? null : $normalized;
    }
}

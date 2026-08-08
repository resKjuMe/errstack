<?php

namespace App\Support\Issues;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;

/**
 * Nennungen in einem Kommentar: „@Anna Beck, kannst du dir das ansehen?"
 *
 * Zwei Aufgaben, und beide liegen auf dem Server:
 *
 *   {@see resolve()}   — aus dem geschriebenen Text die gemeinten Konten und
 *                        Teams heraussuchen. Grundlage der Benachrichtigung.
 *   {@see segments()}  — denselben Text fürs Anzeigen in Abschnitte zerlegen,
 *                        damit die Oberfläche die Nennungen hervorheben kann,
 *                        ohne sie ein zweites Mal zu erkennen.
 *
 * **Warum ohne Kürzel wie `@abeck`?** Weil es sie in dieser Anwendung nicht
 * gibt: ein Konto hat einen Namen und eine E-Mail-Adresse, ein Team hat einen
 * Namen. Ein Kürzel eigens für Nennungen einzuführen hieße, jedem Nutzer eine
 * zweite Kennung zu geben, die er sich merken muss — und sie wäre in einer
 * Oberfläche, die überall den vollen Namen zeigt, nirgends abzulesen. Genannt
 * wird deshalb mit dem Namen, so wie er dasteht, Leerzeichen inbegriffen.
 *
 * **Der Preis dafür ist die Mehrdeutigkeit**, und sie wird bewusst großzügig
 * aufgelöst: heißen zwei Personen einer Organisation gleich, sind beide
 * gemeint. Eine nicht zugestellte Nennung ist der teurere Fehler — sie fällt
 * niemandem auf, während eine überflüssige Benachrichtigung sich selbst
 * erklärt.
 */
final class Mentions
{
    /**
     * Wie viele Nennungen ein Kommentar tragen darf.
     *
     * Nicht als Schikane, sondern gegen das „@alle" von Hand: ein Kommentar,
     * der dreißig Personen anschreibt, ist keine Nennung mehr, sondern ein
     * Verteiler — und der gehört in die Kanäle der Organisation (A1).
     */
    public const LIMIT = 20;

    /**
     * Wie viele Wörter ein Name hinter dem `@` haben darf.
     *
     * Die Grenze macht das Suchen berechenbar: ohne sie müsste jede Fundstelle
     * gegen den Rest des Absatzes geprüft werden. Vier Wörter decken „Anna
     * Beck", „Team Kasse & Versand" und alles ab, was in der Praxis ein Name
     * ist.
     */
    private const MAX_WORDS = 4;

    /**
     * Vor dem `@` darf kein Wortzeichen und kein zweites `@` stehen.
     *
     * Das erste hält Adressen heraus: in „post@example.com" ist „example" kein
     * genanntes Team. Das zweite hält den Fall heraus, dass jemand seine eigene
     * Adresse schreibt und der Name hinter dem `@` zufällig passt.
     */
    private const BOUNDARY_BEFORE = '(?<![\p{L}\p{N}_@])';

    /**
     * Nach dem Namen darf kein Wortzeichen stehen: das „@Anna" in „@Annabelle"
     * ist nicht Anna.
     */
    private const BOUNDARY_AFTER = '(?![\p{L}\p{N}_])';

    /**
     * Wer in diesem Text genannt wird.
     *
     * Gesucht wird gegen die Mitglieder und Teams **dieser** Organisation und
     * gegen nichts sonst: eine Nennung, die jemanden erreicht, der den Fehler
     * gar nicht sehen darf, wäre eine Auskunft über fremde Projekte.
     *
     * Der Weg ist ein Wörterbuch und kein großer regulärer Ausdruck: aus dem
     * Text werden zuerst alle Fundstellen der Form „@ plus bis zu vier Wörter"
     * geholt und dann von hinten verkürzt, bis ein Name passt. Das ist von der
     * Größe der Organisation unabhängig — ein Ausdruck mit allen Namen als
     * Alternativen wäre bei fünfhundert Mitgliedern ein Ausdruck mit
     * fünfhundert Alternativen.
     *
     * @return list<array{user_id: int|null, team_id: int|null, label: string}>
     */
    public static function resolve(string $body, Organization $organization): array
    {
        $candidates = self::candidates($organization);

        if ($candidates === []) {
            return [];
        }

        $found = [];

        foreach (self::spans($body) as $span) {
            $words = explode(' ', $span);

            // Von der längsten Lesart zur kürzesten: „@Anna Beck" ist Anna Beck
            // und nicht Anna, und „@Team Kasse hat gemeldet" ist das Team Kasse
            // und nicht das Team „Kasse hat gemeldet".
            for ($length = count($words); $length >= 1; $length--) {
                $label = implode(' ', array_slice($words, 0, $length));
                $key = mb_strtolower($label);

                if (! isset($candidates[$key])) {
                    continue;
                }

                foreach ($candidates[$key] as $target) {
                    // Je Ziel ein Eintrag, auch wenn es dreimal im Text steht:
                    // die Nennung ist eine Aussage über den Genannten und keine
                    // über die Zahl der Fundstellen.
                    $found[$target['user_id'].':'.$target['team_id']] ??= $target + ['label' => $label];
                }

                break;
            }

            if (count($found) >= self::LIMIT) {
                break;
            }
        }

        return array_values(array_slice($found, 0, self::LIMIT));
    }

    /**
     * Der Text in Abschnitten: gewöhnlicher Text und Nennungen im Wechsel.
     *
     * **Warum nicht Anfang und Länge der Fundstelle?** Weil die beiden Seiten
     * verschieden zählen: PHP in Bytes, JavaScript in UTF-16-Einheiten. Ein
     * einziges „ä" vor einer Nennung, und die Hervorhebung im Browser säße
     * daneben. Der Server liefert deshalb fertige Abschnitte — dieselbe
     * Entscheidung wie beim fertigen Satz im Aktivitätsverlauf
     * ({@see IssueActivityFeed}).
     *
     * Gesucht wird nach den **festgehaltenen** Beschriftungen und nicht erneut
     * nach Namen: ein Konto, das seither umbenannt wurde, soll im alten
     * Kommentar hervorgehoben bleiben — dort steht der alte Name, und er war
     * gemeint.
     *
     * @param  list<string>  $labels
     * @return list<array{type: string, value: string}>
     */
    public static function segments(string $body, array $labels): array
    {
        $labels = array_values(array_unique(array_filter($labels, static fn (string $label): bool => $label !== '')));

        if ($labels === []) {
            return $body === '' ? [] : [['type' => 'text', 'value' => $body]];
        }

        // Längste zuerst: sonst gewinnt „Anna" gegen „Anna Beck", und hinter der
        // Hervorhebung bliebe ein loses „Beck" stehen.
        usort($labels, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $pattern = '/'.self::BOUNDARY_BEFORE.'@('
            .implode('|', array_map(static fn (string $label): string => preg_quote($label, '/'), $labels))
            .')'.self::BOUNDARY_AFTER.'/u';

        $segments = [];
        $offset = 0;

        preg_match_all($pattern, $body, $matches, PREG_OFFSET_CAPTURE);

        /** @var list<array{0: string, 1: int}> $hits */
        $hits = $matches[0] ?? [];

        foreach ($hits as $hit) {
            [$text, $start] = $hit;

            if ($start > $offset) {
                $segments[] = ['type' => 'text', 'value' => substr($body, $offset, $start - $offset)];
            }

            $segments[] = ['type' => 'mention', 'value' => $text];
            $offset = $start + strlen($text);
        }

        if ($offset < strlen($body)) {
            $segments[] = ['type' => 'text', 'value' => substr($body, $offset)];
        }

        return $segments;
    }

    /**
     * Die Namen, die genannt werden können — kleingeschrieben als Schlüssel.
     *
     * Personen und Teams im selben Wörterbuch, und ein Name kann auf mehrere
     * Ziele zeigen: zwei gleichnamige Mitglieder, oder ein Team, das heißt wie
     * jemand. Alle sind gemeint (siehe Klassenkommentar).
     *
     * @return array<string, list<array{user_id: int|null, team_id: int|null}>>
     */
    private static function candidates(Organization $organization): array
    {
        $map = [];

        $members = User::query()
            ->select(['users.id', 'users.name'])
            ->join('organization_user', 'organization_user.user_id', '=', 'users.id')
            ->where('organization_user.organization_id', $organization->id)
            ->get();

        foreach ($members as $member) {
            $map[mb_strtolower($member->name)][] = ['user_id' => $member->id, 'team_id' => null];
        }

        $teams = Team::query()
            ->where('organization_id', $organization->id)
            ->get(['id', 'name']);

        foreach ($teams as $team) {
            $map[mb_strtolower($team->name)][] = ['user_id' => null, 'team_id' => $team->id];
        }

        return $map;
    }

    /**
     * Die Fundstellen der Form „@ plus bis zu vier Wörter", ohne das `@`.
     *
     * @return list<string>
     */
    private static function spans(string $body): array
    {
        $word = '[\p{L}\p{N}_.\-&]+';

        preg_match_all(
            '/'.self::BOUNDARY_BEFORE.'@('.$word.'(?: '.$word.'){0,'.(self::MAX_WORDS - 1).'})/u',
            $body,
            $matches,
        );

        /** @var list<string> $spans */
        $spans = $matches[1] ?? [];

        return $spans;
    }
}

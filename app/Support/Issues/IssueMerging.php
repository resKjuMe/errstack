<?php

namespace App\Support\Issues;

use App\Models\Issue;
use App\Models\IssueUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fehler-Einträge von Hand zusammenführen und wieder auftrennen.
 *
 * Die automatische Gruppierung (I5) liegt manchmal daneben: derselbe Fehler
 * bekommt zwei Fingerabdrücke, weil eine Zeilennummer gewandert ist oder eine
 * Meldung eine Kennung enthält. Dann stehen zwei Einträge in der Liste, jeder
 * mit der Hälfte der Zahlen — und keiner davon ist die Antwort auf „wie schlimm
 * ist das".
 *
 * **Zusammengeführt wird durch einen Verweis, nicht durch Umschichten.** Der
 * beitretende Eintrag bleibt vollständig stehen: seine Gruppen, seine Zeitreihe,
 * seine Betroffenen, seine Merkmale, seine Zähler. Er bekommt nur
 * `merged_into_id` gesetzt. Das ist der Grund, warum das Auftrennen verlustfrei
 * ist — es gibt nichts zurückzurechnen, alles steht noch da.
 *
 * Bewegt wird genau eine Zahl: die **Häufigkeit** des Kopfes. Sie muss am
 * Eintrag stehen, weil die Fehlerliste danach sortiert und filtert; eine Summe
 * über Mitglieder wäre dort eine Gruppierung über die größte Tabelle dieser
 * Anwendung, bei jedem Aufschlagen der Seite. Sie ist die einzige Fortschreibung
 * mit einer Umkehrung: beim Beitritt addiert, beim Auftrennen wieder abgezogen —
 * und weil ein beigetretener Eintrag ab dem Beitritt eingefroren ist, ist der
 * Betrag beide Male derselbe.
 *
 * **Was eingefroren heißt:** ab dem Beitritt zählen die Meldungen der
 * beigetretenen Gruppen am Kopf ({@see Issue::forGroup()}). Der beigetretene
 * Eintrag steht damit für seinen Stand zum Zeitpunkt des Beitritts, und was
 * währenddessen aufgelaufen ist, bleibt beim Auftrennen am Kopf. Das ist eine
 * Entscheidung und kein Versehen: die Zeit als zusammengeführter Fehler war eine
 * Zeit **dieses** Fehlers, und sie im Nachhinein aufzuteilen hieße zu raten,
 * welches Ereignis zu welcher Untergruppe gehörte — die Antwort steht in den
 * Meldungen, und die sind irgendwann weggeräumt.
 *
 * **Die beiden Zeitpunkte schreibt der Kopf nicht zurück.** Beim Beitritt rücken
 * `first_seen` und `last_seen` auf die gemeinsame Spanne; beim Auftrennen
 * bleiben sie stehen. Ein „zuerst gesehen", das nach dem Auftrennen wieder
 * später wird, wäre eine Geschichte, die sich rückwärts ändert — und die Spanne
 * ist eine Aussage darüber, was dieser Eintrag schon gesehen hat, nicht darüber,
 * woraus er gerade besteht. Häufigkeit und Betroffene sind davon nicht berührt;
 * die sind nach dem Auftrennen wieder genau richtig.
 */
final class IssueMerging
{
    /**
     * Führt Einträge zu einem zusammen und gibt den Kopf zurück.
     *
     * Der Kopf wird **nicht** gewählt, sondern bestimmt: der Eintrag mit der
     * größten Häufigkeit, bei Gleichstand der ältere. Die Alternative wäre „der
     * zuerst angeklickte", und die hinge davon ab, in welcher Reihenfolge jemand
     * die Haken gesetzt hat — bei einer Aktion, die den Titel und die Adresse
     * des Ergebnisses festlegt, ist das die falsche Grundlage.
     *
     * Ein Eintrag, der selbst schon Untergruppen hat, darf beitreten: seine
     * Mitglieder kommen mit und hängen danach am neuen Kopf. Eine Kette entsteht
     * dabei nicht — es bleibt bei einer Stufe ({@see Issue::head()}).
     *
     * @param  Collection<int, Issue>  $issues  mindestens zwei, alle aus demselben Projekt
     */
    public static function merge(Collection $issues): Issue
    {
        $target = self::head($issues);

        $sources = $issues->reject(fn (Issue $issue): bool => $issue->is($target));

        DB::transaction(function () use ($target, $sources): void {
            foreach ($sources as $source) {
                self::join($source, $target);
            }

            self::rollUp($target);
        });

        return $target->refresh();
    }

    /**
     * Löst eine Untergruppe wieder heraus.
     *
     * Der herausgelöste Eintrag steht danach wieder für sich — mit genau den
     * Zahlen, mit denen er beigetreten ist. Am Kopf wird die Häufigkeit um
     * denselben Betrag verringert und die Zahl der Betroffenen neu ausgezählt.
     */
    public static function unmerge(Issue $source): void
    {
        $target = $source->mergedInto;

        DB::transaction(function () use ($source, $target): void {
            $source->forceFill(['merged_into_id' => null])->save();

            if ($target === null) {
                return;
            }

            self::addTimesSeen($target, -$source->times_seen);

            self::rollUpUsers($target);
        });
    }

    /**
     * Lässt einen Eintrag beitreten — samt seiner bisherigen Untergruppen.
     *
     * Zwei Buchungen, die zusammengehören: der Kopf bekommt die **ganze**
     * Häufigkeit des Beitretenden gutgeschrieben (die schließt dessen bisherige
     * Untergruppen ein), und der Beitretende behält davon nur seinen eigenen
     * Anteil. Ohne den zweiten Teil stünde der Anteil der mitgekommenen
     * Untergruppen zweimal in der Summe — einmal über sie selbst und einmal über
     * den Eintrag, unter dem sie bisher hingen.
     *
     * Damit gilt für jeden Eintrag dieselbe Zusage, und zwar dauerhaft: seine
     * Häufigkeit ist sein eigener Anteil plus der seiner Mitglieder. Genau
     * darauf beruht das Auftrennen — es zieht ab, was hier addiert wurde.
     */
    private static function join(Issue $source, Issue $target): void
    {
        $contribution = $source->times_seen;
        $inherited = (int) $source->mergedSources()->sum('times_seen');

        $source->mergedSources()->update(['merged_into_id' => $target->id]);

        $source->forceFill(['merged_into_id' => $target->id])->save();

        self::addTimesSeen($source, -$inherited);
        self::addTimesSeen($target, $contribution);
    }

    /**
     * Verschiebt die Häufigkeit eines Eintrags um einen Betrag.
     *
     * `times_seen = times_seen + ?` und **nicht** ein gelesener Wert plus Betrag
     * — genau wie beim Aufnehmen einer Meldung ({@see Issue::bump()}) und aus
     * demselben Grund: auf denselben Eintrag schreibt möglicherweise gerade die
     * Datenaufnahme. Wer hier eine absolute Zahl zurückschreibt, wirft jedes
     * Ereignis weg, das zwischen Lesen und Schreiben gezählt wurde — und das ist
     * bei einem Fehler, der gerade läuft, nicht der Ausnahmefall.
     *
     * Nach unten begrenzt: die Spalte ist vorzeichenlos, und ein Zähler, der aus
     * einer früheren Fassung heraus nicht zur Summe seiner Mitglieder passt, soll
     * hier keinen Datenbankfehler auslösen, sondern bei null enden.
     */
    private static function addTimesSeen(Issue $issue, int $by): void
    {
        if ($by === 0) {
            return;
        }

        DB::update(
            'update '.$issue->getTable().' set '
            .'times_seen = case when times_seen + ? >= 0 then times_seen + ? else 0 end, '
            .'updated_at = ? '
            .'where id = ?',
            [$by, $by, Carbon::now()->format('Y-m-d H:i:s'), $issue->id],
        );
    }

    /**
     * Fasst Spanne, Grad und Betroffene des Kopfes über seine Mitglieder
     * zusammen.
     *
     * Die Häufigkeit steht hier nicht: sie wird beim Beitritt gebucht
     * ({@see join()}), weil nur eine Buchung eine Umkehrung hat. Spanne und Grad
     * sind dagegen Kleinst- und Größtwerte — sie noch einmal über **alle**
     * Mitglieder zu rechnen ändert nichts, wenn schon alles zusammengefasst ist,
     * und ist deshalb gefahrlos wiederholbar.
     *
     * Der Grad folgt dem jüngsten Auftreten — dieselbe Regel wie beim Aufnehmen
     * einer Meldung ({@see Issue::record()}); an ihm hängen die Alarmregeln, und
     * eine Verschärfung muss ankommen.
     *
     * Geschrieben wird mit `case when` und nicht mit fertig gerechneten Werten,
     * wieder wegen der gleichzeitig laufenden Datenaufnahme: eine Meldung, die
     * zwischen Lesen und Schreiben eingeht, hat `last_seen` vielleicht schon
     * weitergerückt, und eine absolute Zuweisung würde sie zurückdrehen. Der
     * Grad steht dabei **vor** `last_seen` — MySQL wertet die Zuweisungen von
     * links nach rechts aus, und er muss gegen den alten Zeitpunkt vergleichen
     * (dieselbe Reihenfolge wie in {@see Issue::bump()}).
     */
    private static function rollUp(Issue $target): void
    {
        /** @var Collection<int, Issue> $members */
        $members = $target->mergedSources()->get();

        if ($members->isEmpty()) {
            return;
        }

        /** @var Issue $newest */
        $newest = $members->sortByDesc('last_seen')->firstOrFail();

        $first = CarbonImmutable::parse($members->min('first_seen'))->utc()->format('Y-m-d H:i:s');
        $last = CarbonImmutable::parse($members->max('last_seen'))->utc()->format('Y-m-d H:i:s');
        $newestAt = $newest->last_seen->utc()->format('Y-m-d H:i:s');

        DB::update(
            'update '.$target->getTable().' set '
            .'first_seen = case when first_seen > ? then ? else first_seen end, '
            .'level = case when last_seen <= ? then ? else level end, '
            .'last_seen = case when last_seen < ? then ? else last_seen end, '
            .'updated_at = ? '
            .'where id = ?',
            [
                $first, $first,
                $newestAt, $newest->level->value,
                $last, $last,
                Carbon::now()->format('Y-m-d H:i:s'), $target->id,
            ],
        );

        self::rollUpUsers($target);
    }

    /**
     * Zählt die Betroffenen des Kopfes neu aus.
     *
     * Ausgezählt und nicht summiert: derselbe Nutzer kann von beiden
     * Untergruppen betroffen gewesen sein, und eine Summe zählte ihn zweimal.
     * Die Frage „einer betroffen oder zehntausend" ist genau die, wegen der
     * dieser Zähler existiert — sie darf nicht am Zusammenführen scheitern.
     *
     * Der `count(distinct …)` läuft über `issue_users` und nicht über die
     * Meldungen: das ist dieselbe Tabelle, aus der der Zähler ohnehin stammt,
     * und sie hat je Eintrag und Nutzer genau eine Zeile.
     *
     * Das ist die eine Stelle, an der eine absolute Zahl geschrieben wird — ein
     * `+ ?` gibt es hier nicht, weil sich zwei Mengen von Nutzern nicht addieren
     * lassen. Geht in genau diesem Augenblick eine Meldung eines neuen
     * Betroffenen ein, fällt sie unter den Tisch. Der Preis ist eine um eins zu
     * kleine Zahl in einer Statistik, und der ist gegenüber der Alternative
     * (Betroffene bei jeder Anzeige auszählen) klein — dieselbe Abwägung wie beim
     * Zählen selbst.
     */
    private static function rollUpUsers(Issue $target): void
    {
        $users = IssueUser::query()
            ->whereIn('issue_id', $target->memberIds())
            ->distinct()
            ->count('user_key');

        DB::update(
            'update '.$target->getTable().' set users_seen = ?, updated_at = ? where id = ?',
            [$users, Carbon::now()->format('Y-m-d H:i:s'), $target->id],
        );
    }

    /**
     * Der Eintrag, unter dem die übrigen zusammenkommen.
     *
     * @param  Collection<int, Issue>  $issues
     */
    private static function head(Collection $issues): Issue
    {
        return $issues
            ->sortBy([
                ['times_seen', 'desc'],
                ['first_seen', 'asc'],
                ['id', 'asc'],
            ])
            ->firstOrFail();
    }
}

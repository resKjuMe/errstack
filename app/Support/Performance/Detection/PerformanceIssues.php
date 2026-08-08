<?php

namespace App\Support\Performance\Detection;

use App\Events\IssueCreated;
use App\Models\EventGroup;
use App\Models\Issue;
use App\Models\PerformanceDetection;
use App\Models\Transaction;
use App\Models\TransactionSpan;
use Illuminate\Support\Str;

/**
 * Aus einem Fund wird ein Eintrag.
 *
 * Die Stelle, an der die Leistungserkennung auf die vorhandene Maschinerie
 * trifft — und sie tut es auf demselben Weg wie ein Fehler: Fingerabdruck →
 * Gruppe → Eintrag → zählen. Nicht „so ähnlich wie", sondern dieselben Klassen
 * ({@see EventGroup::forFingerprint()}, {@see Issue::forPerformance()}). Damit
 * gelten Zustand, Priorität, Zuweisung und Alarme sofort, und zwar ohne dass
 * irgendeine dieser Funktionen von Leistungsproblemen wissen müsste.
 *
 * **Die Reihenfolge ist nicht beliebig.** Zuerst der Fund, dann der Eintrag:
 * der Fund trägt den eindeutigen Index über (Ablauf, Fingerabdruck) und ist
 * damit die Stelle, an der die Datenbank entscheidet, ob dieser Vorfall neu
 * ist. Wer den Eintrag zuerst anlegte und danach den Fund, hätte den Zähler
 * schon erhöht, wenn die Datenbank „gibt es bereits" sagt — und die Häufigkeit
 * würde mit jedem wiederholten Durchlauf wachsen statt mit den Vorfällen.
 */
final class PerformanceIssues
{
    /**
     * Wie lang die Überschrift eines Eintrags höchstens wird.
     *
     * Die Spalte fasst 500 Zeichen; was in der Liste lesbar sein soll, deutlich
     * weniger. Eine Abfrage über zwanzig Spalten als Überschrift ist keine
     * Überschrift mehr, sondern eine Zeile, die die Liste zerreißt — der
     * vollständige Text steht am Beleg.
     */
    private const TITLE_LIMIT = 160;

    /**
     * Nimmt einen Fund auf und gibt den Eintrag zurück — oder `null`, wenn
     * dieser Vorfall schon erfasst war.
     */
    public function record(Transaction $transaction, Finding $finding): ?Issue
    {
        $fingerprint = $finding->fingerprint($transaction->name);

        $detection = PerformanceDetection::claim([
            'project_id' => $transaction->project_id,
            'transaction_id' => $transaction->id,
            'trace_id' => $transaction->trace_id,
            'problem' => $finding->problem->value,
            'fingerprint' => $fingerprint->hash,
            'span_ids' => $finding->spanIds,
            // Dieselbe Grenze wie an einem Einzelschritt: der Beleg zeigt die
            // Abfrage, wie sie dort steht, und nicht eine zweite, kürzere
            // Fassung davon.
            'description' => Str::limit($finding->description, TransactionSpan::DESCRIPTION_LIMIT, ''),
            'span_count' => count($finding->spanIds),
            'time_lost_us' => $finding->timeLostUs,
            'evidence' => $finding->evidence,
            'occurred_at' => $transaction->started_at,
        ]);

        if ($detection === null) {
            return null;
        }

        $group = EventGroup::forFingerprint($transaction->project_id, $fingerprint);

        $issue = Issue::forPerformance(
            $group,
            $detection,
            $this->title($finding),
            $transaction->name,
        );

        $isNew = $issue->wasRecentlyCreated;

        $detection->issue_id = $issue->id;
        $detection->save();

        $issue->recordDetection($detection, self::userKey($transaction));

        if ($isNew) {
            event(IssueCreated::fromIssue($issue));
        }

        return $issue;
    }

    /**
     * Die Überschrift: das Muster und woran es hängt.
     *
     * „N+1-Abfragen: select … from bestellungen where kunde_id = ?" — beides
     * zusammen, weil weder das eine noch das andere allein reicht. Zehn
     * Einträge mit derselben Überschrift „N+1-Abfragen" wären nicht
     * auseinanderzuhalten, und die Abfrage allein sagt nicht, was mit ihr nicht
     * stimmt.
     */
    private function title(Finding $finding): string
    {
        $subject = trim($finding->subject);

        if ($subject === '') {
            return $finding->problem->label();
        }

        return Str::limit($finding->problem->label().': '.$subject, self::TITLE_LIMIT);
    }

    /**
     * Der Betroffene eines Ablaufs, als Streuwert.
     *
     * Eine Transaktion führt **eine** Kennung mit, ohne zu sagen, ob es eine
     * Nutzer-Nummer, ein Name oder eine Adresse ist — die Auswahl hat schon die
     * Aufnahme getroffen. Deshalb ein fester Feldname im Streuwert: er muss nur
     * innerhalb eines Eintrags unterscheiden, und dort ist die Herkunft immer
     * dieselbe.
     */
    private static function userKey(Transaction $transaction): ?string
    {
        $identifier = $transaction->user_identifier;

        if (! is_string($identifier) || trim($identifier) === '') {
            return null;
        }

        return md5('transaction|'.trim($identifier));
    }
}

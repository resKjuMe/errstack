<?php

namespace App\Enums;

use App\Models\IngestDiscard;

/**
 * Warum die Aufnahme ein Element verworfen hat.
 *
 * Nur die Gründe der **eigenen** Seite stehen hier. Was ein SDK verwirft,
 * begründet es mit seinen eigenen Bezeichnungen (`queue_overflow`,
 * `ratelimit_backoff`, `before_send` …), und die Liste wächst mit jeder
 * SDK-Fassung — die wird deshalb als Zeichenkette übernommen und nicht hier
 * nachgepflegt. Siehe {@see IngestDiscard}.
 */
enum DiscardReason: string
{
    /**
     * Ein Element-Typ, den wir nicht kennen. Sentry erweitert die Liste
     * laufend; ein unbekannter Typ ist deshalb ein normaler Vorgang und kein
     * Fehler.
     */
    case UnknownType = 'unknown_type';

    /** Kopf oder Nutzdaten des Elements ließen sich nicht lesen. */
    case Unreadable = 'unreadable';

    /** Das Element allein überschreitet die erlaubte Größe. */
    case TooLarge = 'too_large';

    /** Der Envelope enthielt mehr Elemente, als wir annehmen. */
    case TooManyItems = 'too_many_items';

    /**
     * Dieselbe Meldung war schon ausgewertet. Anders als die Gründe darüber
     * fällt dieser nicht bei der Annahme an, sondern erst in der Verarbeitung:
     * eine wiederholte Zustellung wird angenommen wie jede andere, sonst müsste
     * der Endpunkt vor seiner Antwort nachsehen.
     */
    case Duplicate = 'duplicate';

    /**
     * Die Stichprobe hat die Messung nicht behalten (I9).
     *
     * Kein Mangel an der Meldung: sie war vollständig und in Ordnung, sie wurde
     * nur nicht gebraucht. Der Grund steht deshalb ausdrücklich als eigener da
     * und nicht bei den übrigen — eine Statistik, in der ausgesiebte
     * Antwortzeiten neben unlesbaren Nutzdaten stehen, würde jeden Betreiber zu
     * Recht beunruhigen. Betroffen sind nur Transaktionen; Fehler werden
     * vollständig behalten.
     */
    case Sampled = 'sampled';

    /**
     * Die Datenschutz-Einstellungen des Projekts verbieten das Speichern. Fällt
     * nur bei Anhängen und Aufzeichnungen an: an einem Feld-Baum wird geschwärzt,
     * eine Datei ist entweder erlaubt oder nicht.
     */
    case Scrubbed = 'scrubbed';

    /**
     * Ein Eingangsfilter des Projekts hat die Meldung aussortiert.
     *
     * **Welcher** Filter, steht in der Kategorie: sie trägt den Wert von
     * {@see InboundFilterKind}. Ein Grund je Filterart wäre die naheliegende
     * Alternative und die falsche — die Kategorie ist genau dafür da, und mit
     * sieben Gründen müsste jede Auswertung, die „wie viel wurde gefiltert?"
     * beantwortet, sieben Werte kennen statt einen.
     */
    case Filtered = 'filtered';

    /**
     * Der Fehler wurde gelöscht, und künftige Meldungen desselben
     * Fingerabdrucks sollen verworfen werden (S6).
     *
     * Ein eigener Grund und nicht `filtered`, obwohl beide dasselbe tun: der
     * Eingangsfilter ist eine Einstellung des Projekts, die jemand in der
     * Verwaltung nachlesen kann. Dieser hier entsteht aus **einer** Handlung an
     * **einem** Fehler — und wer sich fragt, warum eine bekannte Meldung nicht
     * mehr ankommt, findet die Antwort nur, wenn die Zählung beide
     * auseinanderhält.
     */
    case Discarded = 'discarded';

    /**
     * Die Meldung, auf die sich das Element bezieht, gibt es nicht.
     *
     * Bislang genau ein Fall: ein Sample-Profil, dessen Transaktion nicht
     * ankam — weil das SDK sie nicht geschickt hat, weil die Stichprobe sie
     * aussortiert hat oder weil ihre Verarbeitung noch in einer Wiederholung
     * hängt (M4). Ein eigener Grund und nicht `unreadable`, weil die Antwort
     * darauf eine andere ist: an einem unlesbaren Rumpf ist etwas kaputt, hier
     * fehlt die Gegenseite — und wer die Zahlen ansieht, muss das
     * auseinanderhalten können.
     */
    case Orphaned = 'orphaned';

    public function label(): string
    {
        return __('enums.discard_reason.'.$this->value);
    }
}

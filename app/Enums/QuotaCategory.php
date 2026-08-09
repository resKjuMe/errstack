<?php

namespace App\Enums;

/**
 * Die Datenart, gegen die ein Kontingent zählt.
 *
 * Gröber geschnitten als {@see IngestType}, und das ist der Zweck: ein
 * Kontingent ist eine Betreiber-Entscheidung („wie viel Aufzeichnungen wollen
 * wir aufheben?"), keine Aussage über Envelope-Elemente. Kopfdaten und
 * Bilddaten einer Sitzungs-Aufzeichnung sind zwei Element-Typen und eine
 * Datenart; wer sie getrennt einstellen müsste, stellte die eine Hälfte falsch
 * ein.
 *
 * Was hier **nicht** steht, ist ebenso Absicht: Sitzungen, Verworfen-Meldungen
 * des SDK und Rückmeldungen betroffener Personen zählen gegen nichts. Sie sind
 * Angaben **über** Ereignisse, die bereits gezählt wurden — sie ein zweites Mal
 * zu begrenzen hieße, die Buchhaltung zu bepreisen.
 */
enum QuotaCategory: string
{
    /** Fehlermeldungen — was über `/store/` und als `event` hereinkommt. */
    case Errors = 'errors';

    /** Antwortzeiten samt ihrer Einzelschritte. */
    case Transactions = 'transactions';

    /** Sitzungs-Aufzeichnungen: Kopfdaten und Bilddaten zusammen. */
    case Replays = 'replays';

    /** Dateien zu einer Meldung: Screenshot, Logdatei, Speicherabbild. */
    case Attachments = 'attachments';

    /** Lebenszeichen überwachter Cronjobs. */
    case Monitors = 'monitors';

    /**
     * Gegen welches Kontingent ein aufgenommenes Element zählt — oder `null`,
     * wenn es gegen keines zählt.
     *
     * Das Profil ist bewusst bei den Transaktionen: es wird an der Transaktion
     * abgelegt, die es vermessen hat, und ohne sie verworfen. Ein eigenes
     * Kontingent dafür wäre eine Einstellung, deren Wirkung niemand vorhersagen
     * kann — abgeschaltete Profile sähen aus wie ein gerissenes Kontingent.
     */
    public static function forIngestType(IngestType $type): ?self
    {
        return match ($type) {
            IngestType::Event => self::Errors,
            IngestType::Transaction, IngestType::Profile => self::Transactions,
            IngestType::ReplayEvent, IngestType::ReplayRecording => self::Replays,
            IngestType::Attachment => self::Attachments,
            IngestType::CheckIn => self::Monitors,
            default => null,
        };
    }

    public function label(): string
    {
        return __('enums.quota_category.'.$this->value);
    }

    /**
     * Alle Datenarten mit ihrer Bezeichnung — die Vorlage jeder Auswahl und
     * jeder Tabelle in der Oberfläche.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $category): array => ['value' => $category->value, 'label' => $category->label()],
            self::cases(),
        );
    }
}

<?php

namespace App\Support\Ingest\Filtering;

use App\Enums\InboundFilterKind;

/**
 * Das Urteil eines Eingangsfilters: welche Art hat aussortiert und woran.
 *
 * Die Art wird gezählt, der Anlass steht in der Protokollzeile. Beides zu
 * zählen wäre verlockend und falsch — der Anlass ist bei einem Muster der
 * Eintrag, bei einer Erweiterung ein Dateipfad, und ein Zähler je Dateipfad
 * wäre eine Tabelle, die mit jeder Erweiterung dieser Welt wächst.
 */
final class Verdict
{
    public function __construct(
        public readonly InboundFilterKind $kind,
        public readonly string $matched,
    ) {}
}

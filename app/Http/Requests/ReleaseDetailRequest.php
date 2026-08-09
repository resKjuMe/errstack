<?php

namespace App\Http\Requests;

use App\Support\FilterData;

/**
 * Die Detailseite einer Auslieferung als Eingabe.
 *
 * Dieselben Felder wie überall — die Seite zeigt Kennzahlen, und für die gilt
 * die Zusage aus F7: **alle Zahlen respektieren die globale Filterleiste.** Eine
 * Crash-Free-Rate ohne Zeitraum wäre die über die gesamte Betriebsdauer und
 * damit die Zahl, die sich nach einer schlechten Auslieferung am wenigsten
 * bewegt.
 *
 * Die Projektauswahl der Leiste bleibt hier ohne Wirkung: welches Projekt
 * gemeint ist, sagt die Version in der Adresszeile. Sie wird deshalb gar nicht
 * erst angeboten ({@see FilterData::bar()}) statt stillschweigend
 * übergangen.
 */
class ReleaseDetailRequest extends GlobalFilterRequest {}

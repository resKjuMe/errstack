<?php

namespace App\Support\Performance\Detection;

use App\Enums\PerformanceProblem;

/**
 * Ein Erkenner: sieht sich die Schritte eines Ablaufs an und meldet, was ihm
 * auffällt.
 *
 * **Ein Erkenner schreibt nichts.** Er kennt weder Einträge noch Gruppen noch
 * die Datenbank; er bekommt Schritte und Schwellen und gibt Funde zurück. Das
 * ist die ganze Regel, und sie hält die Muster prüfbar: ein Test reicht eine
 * Handvoll Schritte hinein und vergleicht die Funde, ohne dass eine Migration
 * gelaufen sein muss.
 *
 * Welche Erkenner es gibt, steht in `config/ingest.php` und nicht in einer
 * `match`-Anweisung — ein neues Muster ist eine Klasse und eine Zeile Konfig,
 * kein Eingriff in den Ablauf der Erkennung.
 */
interface Detector
{
    /**
     * Das Muster, das dieser Erkenner findet.
     *
     * Er nennt es selbst, damit der Ablauf ihn danach fragen kann: „ist das
     * abgeschaltet?" und „welche Schwellen gelten?" beantwortet die Konfig,
     * nicht der Erkenner.
     */
    public function problem(): PerformanceProblem;

    /**
     * @return list<Finding>
     */
    public function detect(TraceSnapshot $trace, Thresholds $thresholds): array;
}

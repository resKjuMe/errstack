<?php

namespace App\Support\Operations;

use RuntimeException;

/**
 * Eine Prüfung, die nicht von selbst geworfen hat, aber trotzdem gescheitert
 * ist — der Zwischenspeicher hat den Probewert vergessen, die Ablage hat etwas
 * anderes zurückgegeben.
 *
 * Eine eigene Ausnahme und nicht `RuntimeException` direkt: so ist an der
 * Fangstelle zu erkennen, dass es unsere Feststellung war und nicht die eines
 * Treibers.
 */
final class HealthCheckFailed extends RuntimeException {}

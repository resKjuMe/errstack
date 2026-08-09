<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Grenzen der freien Auswertungen
    |--------------------------------------------------------------------------
    |
    | Eine freie Auswertung ist eine Abfrage, die niemand vorher gesehen hat: der
    | Fragende bestimmt Quelle, Zeitraum und Gruppierung. Diese Zahlen sind die
    | Zusage an alle übrigen Leser der Datenbank — nicht Misstrauen gegen den
    | Fragenden. Sie sind bewusst großzügig für Fragen, die jemand stellt, und
    | knapp für solche, die aus Versehen entstehen.
    |
    |   max_rows          — wie viele Zeilen eine Antwort höchstens hat.
    |   max_groups        — wie viele Gruppen der Motor liest, wenn er selbst
    |                       sortieren muss (Sortierung nach einem Perzentil).
    |   max_group_fields  — wie viele Felder eine Gruppierung tief sein darf. Jedes
    |                       weitere vervielfacht die Zeilenzahl.
    |   max_aggregations  — wie viele Kennzahlen eine Abfrage anfordern darf.
    |   max_points        — wie viele Stützstellen eine Zeitreihe hat. 1440 ist ein
    |                       Tag in Minuten und damit die feinste sinnvolle Reihe.
    |   max_series_groups — wie viele Linien eine gruppierte Zeitreihe zeigt.
    |   max_range_days    — wie weit ein Zeitraum zurückreichen darf.
    |   timeout_ms        — die Zeit, nach der MySQL die Abfrage selbst abbricht.
    |
    */

    'max_rows' => (int) env('DISCOVER_MAX_ROWS', 1000),

    'max_groups' => (int) env('DISCOVER_MAX_GROUPS', 5000),

    'max_group_fields' => (int) env('DISCOVER_MAX_GROUP_FIELDS', 3),

    'max_aggregations' => (int) env('DISCOVER_MAX_AGGREGATIONS', 6),

    'max_points' => (int) env('DISCOVER_MAX_POINTS', 1440),

    'max_series_groups' => (int) env('DISCOVER_MAX_SERIES_GROUPS', 10),

    'max_range_days' => (int) env('DISCOVER_MAX_RANGE_DAYS', 90),

    'timeout_ms' => (int) env('DISCOVER_TIMEOUT_MS', 10_000),

    /*
    |--------------------------------------------------------------------------
    | Zwischenspeicher
    |--------------------------------------------------------------------------
    |
    | Dieselbe Auswertung wird selten einmal gelesen: eine Seite mit sechs Kacheln
    | (D4) fragt sechsmal, ein Blick aufs Nachbardiagramm noch einmal, und beim
    | Neuladen beginnt es von vorn.
    |
    | `cache_granularity` ist die Rasterung, mit der ein **gleitender** Zeitraum
    | überhaupt treffen kann: „die letzten 24 Stunden" ist ohne sie bei jedem
    | Aufruf ein anderer Zeitraum und damit eine andere Abfrage. Der Preis ist,
    | dass die Antwort bis zu einem Raster alt ist — dieselbe Verzögerung, die die
    | vorberechneten Fenster ohnehin haben. Wer sie nicht will, fragt mit einem
    | genauen Zeitraum oder ohne Zwischenspeicher.
    |
    */

    'cache_ttl' => (int) env('DISCOVER_CACHE_TTL', 60),

    'cache_granularity' => (int) env('DISCOVER_CACHE_GRANULARITY', 60),

];

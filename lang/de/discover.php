<?php

// Die freie Auswertung (Discover): Abfrage zusammenstellen, als Tabelle und
// Diagramm ansehen, ausgeben.
return [

    'title' => 'Auswertung',

    'help' => 'Stellen Sie eine Auswertung selbst zusammen: Quelle, Gruppierung, Kennzahlen, Suchbedingung und Sortierung. Tabelle und Diagramm zeigen dieselbe Abfrage — die Linien des Diagramms sind die obersten Zeilen der Tabelle. Der vollständige Zustand steht in der Adresszeile: ein Neuladen behält ihn, und ein geteilter Link zeigt beim Empfänger dieselbe Auswertung.',

    // Die Abfrage-Leiste.
    'query' => [
        'dataset' => 'Quelle',
        'group_by' => 'Gruppieren nach',
        'group_by_none' => 'Keine Gruppierung',
        'group_by_add' => 'Feld hinzufügen',
        'metrics' => 'Kennzahlen',
        'metrics_add' => 'Kennzahl hinzufügen',
        'metric_field' => 'Feld',
        'metric_field_none' => 'ohne Feld',
        'search' => 'Suchbedingung',
        'search_placeholder' => 'z. B. level:error environment:production',
        'sort' => 'Sortierung',
        'limit' => 'Zeilen',
        'interval' => 'Schrittweite',
        'submit' => 'Auswerten',
        'reset' => 'Zurücksetzen',
        'remove' => 'Entfernen',
    ],

    // Was das Ergebnis über sich selbst sagt.
    'notes' => [
        'truncated' => 'Es gibt mehr Gruppen als angezeigt. Zu sehen sind die obersten :limit nach der gewählten Sortierung.',
        'cached' => 'Aus dem Zwischenspeicher — die Zahlen sind bis zu einer Minute alt.',
        'unavailable' => 'In dieser Quelle gibt es diese Felder nicht, sie haben nichts eingeschränkt: :fields',
        'search_error' => 'Die Suchbedingung wurde nicht verstanden (Stelle :position): :message',
        'search_error_hint' => 'Die Auswertung steht deshalb ungefiltert da.',
        'series_limit' => 'Das Diagramm zeigt die obersten :count Zeilen der Tabelle.',
    ],

    // Abgelehnte Abfragen — der Motor sagt, wo die Grenze liegt.
    'error' => [
        'title' => 'Diese Auswertung wurde nicht ausgeführt',
        'limit' => 'Die Grenze „:limit" ist überschritten: erlaubt sind :allowed, verlangt waren :given.',
        'timeout' => 'Die Abfrage hat länger gedauert als erlaubt (:timeout ms) und wurde abgebrochen. Ein kleinerer Zeitraum oder eine gröbere Gruppierung hilft.',
        'unsupported' => 'Die Quelle kann diese Kennzahl nicht rechnen: :what',
        'unknown_field' => 'Das Feld „:field" gibt es in dieser Quelle nicht.',
        'invalid' => ':message',
    ],

    // Tabelle und Zeilen.
    'table' => [
        'empty' => 'Keine Daten im gewählten Zeitraum.',
        'empty_hint' => 'Ein größerer Zeitraum, eine andere Quelle oder eine weniger enge Suchbedingung zeigt vielleicht etwas.',
        'missing' => 'ohne Wert',
        'rows' => ':count Zeilen',
        'drilldown' => 'Zu den zugrunde liegenden Ereignissen',
        'no_drilldown' => 'Für diese Zeile gibt es keine Ereignisliste, die genau dieselbe Menge zeigt.',
    ],

    'chart' => [
        'all' => 'Gesamt',
        'title' => 'Verlauf',
        'metric' => 'Kennzahl im Diagramm',
        'empty' => 'Nichts zu zeichnen.',
        'label' => 'Verlauf der gewählten Kennzahl',
        'total' => 'Gesamt: :total',
    ],

    'export' => [
        'action' => 'Als CSV ausgeben',
        'filename' => 'auswertung-:dataset-:date.csv',
    ],

    // Discover rechnet über genau ein Projekt.
    'project' => [
        'required' => 'Wählen Sie genau ein Projekt.',
        'reason' => 'Eine freie Auswertung ist eine Abfrage über eine Datenmenge, und ihre Grenzen — Zeit, Zeilen, Stützstellen — gelten je Abfrage. Über mehrere Projekte gerechnet, wäre es je Projekt eine Abfrage: die Grenze gälte dann für keine davon. Kennzahlen wie ein Perzentil oder die Zufriedenheit ließen sich hinterher ohnehin nicht zusammenzählen.',
        'choose' => 'Projekt wählen:',
        'none' => 'In dieser Organisation gibt es noch kein Projekt.',
        'current' => 'Ausgewertet wird :project.',
    ],

];

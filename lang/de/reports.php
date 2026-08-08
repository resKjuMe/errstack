<?php

// Wochenbericht je Projekt (App\Support\Reports\WeeklyProjectReport,
// App\Mail\WeeklyReportMail, resources/views/mail/weekly-report.blade.php).
return [

    'weekly' => [
        'subject' => 'Wochenbericht :project · ab :week',
        'heading' => 'Wochenbericht :project',
        'period' => 'Zeitraum: :from bis :until',
        'events' => 'Ereignisse',
        'new_issues' => 'Neue Fehler',
        'resolved_issues' => 'Erledigte Fehler',
        'trend' => 'Gegenüber der Vorwoche',
        'trend_value' => ':sign:percent %',
        'trend_unknown' => 'kein Vergleich möglich — in der Vorwoche gab es nichts',
        'top_issues' => 'Die häufigsten Fehler',
        'top_areas' => 'Die meistbetroffenen Bereiche',
        'times' => ':count mal',
        'untitled' => 'Ohne Titel',
        'open_project' => 'Projekt ansehen',
    ],

];

<?php

// Weekly project report (App\Support\Reports\WeeklyProjectReport,
// App\Mail\WeeklyReportMail, resources/views/mail/weekly-report.blade.php).
return [

    'weekly' => [
        'subject' => 'Weekly report :project · from :week',
        'heading' => 'Weekly report :project',
        'period' => 'Period: :from to :until',
        'events' => 'Events',
        'new_issues' => 'New issues',
        'resolved_issues' => 'Resolved issues',
        'trend' => 'Compared to the previous week',
        'trend_value' => ':sign:percent %',
        'trend_unknown' => 'no comparison possible — there was nothing in the previous week',
        'top_issues' => 'Most frequent issues',
        'top_areas' => 'Most affected areas',
        'times' => ':count times',
        'untitled' => 'Untitled',
        'open_project' => 'View project',
    ],

];

<?php

// Browser loading experience — overview and detail page
// (resources/js/shell/pages/performance/WebVitals.jsx and WebVital.jsx,
// App\Http\Controllers\WebVitalController).
return [

    'title' => 'Web Vitals',

    'help' => [
        'purpose' => 'The list shows every page that reported browser measurements in the selected period — ordered by how many visitors had a poor experience.',
        'percentile' => 'Every value is the p75: three out of four page loads were at least this good. The Web Vitals specification prescribes this point — an average would hide the slow half.',
        'thresholds' => 'The thresholds for good, needs improvement and poor come from the specification and cannot be configured. That is their value: the number can be compared with other applications.',
        'core' => 'A page is rated by the three core vitals LCP, INP and CLS. FCP and TTFB explain them; they are not findings in their own right.',
        'no_data' => 'Pages without measurements are listed with a “no data” marker. They are not missing because they are fine, but because nothing was reported — usually the browser SDK is not wired up.',
    ],

    'search' => [
        'label' => 'Search',
        'placeholder' => 'Part of a page name',
        'submit' => 'Search',
        'clear' => 'Clear search',
    ],

    'truncated' => 'There are more than :limit pages. Shown are those needing attention most — narrow the period or the search.',

    'columns' => [
        'page' => 'Page',
        'rating' => 'Rating',
        'measurements' => 'Page loads',
    ],

    'row' => [
        'no_data' => 'no data',
        'no_data_hint' => 'This page has known requests, but no browser measurements.',
        'measurements' => ':count page loads reporting this measurement',
        'distribution' => ':good good · :needs needs improvement · :poor poor',
        'threshold' => 'Good up to :good, poor above :poor',
    ],

    'pagination' => [
        'summary' => 'Page :page of :pages · :total pages',
        'previous' => 'Previous',
        'next' => 'Next',
    ],

    'empty' => [
        'no_data' => 'No browser measurements were reported in the selected period.',
        'no_data_hint' => 'Wire up the browser SDK with performance monitoring enabled and load a few pages — the measurements will show up here.',
        'no_results' => 'No page matches this search.',
        'no_results_hint' => 'The search matches part of a page name.',
    ],

    'detail' => [
        'title' => 'Loading experience of a page',
        'back' => 'Back to the overview',

        'help' => [
            'purpose' => 'Every browser measurement of this page, plus the history and breakdown of the selected one.',
            'select' => 'History, distribution and breakdown apply to the selected measurement. Six charts side by side answer no question that one does not answer already.',
            'facets' => 'The breakdown is based on a sample of individual measurements — it shows shares, not complete counts. The metrics above are complete.',
            'thresholds' => 'The rating is derived from the exact value of every measurement as it arrives and is therefore exact; the number shown is accurate to within a few percent.',
        ],

        'empty' => 'No browser measurements were reported for this page in the selected period.',
        'empty_hint' => 'The period may be off, or the browser SDK reports nothing for this page.',

        'select' => 'Select measurement',
        'no_measurement' => 'Nothing was reported for this measurement.',

        'summary' => [
            'p75' => 'p75',
            'avg' => 'Average',
            'min' => 'Best',
            'max' => 'Worst',
            'count' => 'Measurements',
        ],

        'histogram' => [
            'title' => 'Distribution',
            'bar' => ':count measurements between :from and :to',
            'open_end' => 'and above',
            'hint' => 'A second hill far to the right is a different finding than one broad mountain: the first means a group of devices with a problem of its own, the second means the page is too heavy as a whole.',
        ],

        'series' => [
            'title' => 'History',
            'point' => ':at — :value from :count measurements',
            'period_hour' => 'One bar per hour.',
            'period_day' => 'One bar per day.',
        ],

        'facets' => [
            'title' => 'Breakdown',
            'hint' => 'From :sampled of at most :limit individual measurements read.',
            'truncated' => 'Further values are not listed.',
            'empty' => 'Too few individual measurements for a breakdown.',
            'value' => 'Value',
            'count' => 'Measurements',
            'measured' => 'p75',
        ],
    ],

    'facets' => [
        'device' => 'Device',
        'browser' => 'Browser',
        'country' => 'Country',
    ],

];

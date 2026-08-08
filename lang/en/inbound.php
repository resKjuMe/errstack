<?php

// Labels for the inbound filters (app/Support/InboundFilterData,
// resources/js/shell/pages/projects/Filters.jsx). Not to be confused with
// `filters.php` — that is the global filter bar of the reporting pages.
return [

    'title' => 'Inbound filters · :project',
    'help' => 'What is filtered here never reaches the issue list. It is still counted.',

    'options' => [
        'title' => 'Filter types',
        'description' => 'What is discarded on arrival instead of being stored.',
        'read_only' => 'Only administrators can change this.',
        'counted' => 'Filtered in the last :days days: :count events.',
        'filtered' => ':count filtered',
        'submit' => 'Save filters',
    ],

    'kinds' => [
        'browser_extension_hint' => 'Errors from a visitor’s browser extension — recognised by where the file came from or by the error text.',
        'legacy_browser_hint' => 'Errors from browsers too old to support. The thresholds are listed below.',
        'localhost_hint' => 'Reports from local development (localhost, .test, .localhost).',
        'crawler_hint' => 'Reports triggered by a search engine robot or an auditing tool.',
        'message_pattern_hint' => 'Errors whose text matches one of the patterns below.',
        'ip_address_hint' => 'Reports from the addresses or networks blocked below. Only takes effect when the report carries an address — browser SDKs leave it to the server and send none.',
        'release_hint' => 'Reports from the releases blocked below.',
    ],

    'rules' => [
        'title' => 'List: :kind',
        'kind' => 'Filter type',
        'expression' => 'Entry',
        'empty' => 'No entry yet — the filter therefore does nothing.',
        'disabled_hint' => 'This filter type is switched off. The entries are kept but have no effect.',
        'inactive_badge' => 'paused',
        'browser_defaults' => 'Without an entry of your own, these thresholds apply:',
        'create' => 'Add',
        'save' => 'Save',
        'enable' => 'Switch back on',
        'disable' => 'Pause',
        'delete' => 'Delete',

        'legacy_browser_description' => 'One browser per line: “safari:6” filters everything below version 6, “ie” every version. The name must match the one the SDK reports.',
        'message_pattern_description' => 'Matched against the whole error text, “*” stands for any number of characters. For a partial match write “*text*”.',
        'ip_address_description' => 'An address or a network in CIDR notation. Matched against the affected user’s address and the web server’s — forwarded headers do not count, because they can be set freely.',
        'release_description' => 'The release name, “*” stands for any number of characters.',

        'legacy_browser_placeholder' => 'safari:6',
        'message_pattern_placeholder' => '*ResizeObserver loop*',
        'ip_address_placeholder' => '203.0.113.0/24',
        'release_placeholder' => '1.4.*',
    ],

    'known' => [
        'title' => 'Treated as local',
        'description' => 'These host names count as local development — built in, not configurable.',
    ],

    'validation' => [
        'address' => 'That is not a valid address or network.',
        'browser' => 'Expected “browser” or “browser:version”, for example “safari:6”.',
        'too_broad' => 'An entry made only of wildcards matches every report.',
        'too_many' => 'More than :max entries per filter type are not supported.',
    ],

    'flash' => [
        'options_updated' => 'The filters have been saved.',
        'rule_created' => 'Entry “:expression” added.',
        'rule_updated' => 'Entry “:expression” changed.',
        'rule_enabled' => 'Entry “:expression” switched back on.',
        'rule_disabled' => 'Entry “:expression” paused.',
        'rule_deleted' => 'Entry “:expression” deleted.',
    ],

];

<?php

// Quotas and rate limiting (O1): the page per project and per organization, the
// warning when a monthly quota runs low, and the count of what was rejected.
return [

    'title' => 'Quotas — :subject',
    'help' => 'How much may come in per data type, how much of it is used this month, and what was rejected.',

    'intro' => [
        'title' => 'Why quotas',
        'description' => 'A single misbehaving project can flood an entire installation with data. Quotas limit what comes in per data type — anything beyond is throttled, counted and reported.',
        'unlimited_hint' => 'An empty field means "unlimited". That is the default: a quota is a decision, not a preset that quietly kicks in one day.',
        'separate_hint' => 'Data types are limited separately. A used-up transaction quota does not hold back error events.',
        'warning_hint' => 'At 80 % and at 100 % of a monthly quota a message goes to the organization administrators — once per threshold and month.',
        'period' => 'Billing period: :period',
    ],

    'settings' => [
        'title' => 'Limits per data type',
        'description' => 'The monthly quota limits the amount, the rate limits the speed. They are independent of each other and both may stay empty.',
        'read_only_description' => 'Only organization administrators may change these. Every member may look: this page answers "why has nothing arrived since yesterday?".',
        'category' => 'Data type',
        'per_month' => 'Quota per month',
        'per_minute' => 'Rate per minute',
        'per_month_hint' => 'Empty = unlimited.',
        'per_minute_hint' => 'Empty = unlimited.',
        'usage' => 'Used',
        'unlimited' => 'unlimited',
        'submit' => 'Save quotas',
        'usage_of' => ':usage of :limit',
        'percent' => ':percent %',
    ],

    'categories' => [
        'errors_hint' => 'Error events from `/store/` and envelope items of type `event`.',
        'transactions_hint' => 'Transactions with their spans — and the profiles attached to them.',
        'replays_hint' => 'Session replays: headers and recording data together.',
        'attachments_hint' => 'Files attached to an event: screenshot, log file, memory dump.',
        'monitors_hint' => 'Check-ins of monitored cron jobs.',
    ],

    'inherited' => [
        'title' => 'Organization limits',
        'description' => 'They sit above this project\'s limits. Once the organization is out, even a project with a generous quota of its own is rejected.',
        'link' => 'Organization quotas',
        'empty' => 'The organization has not set any quotas.',
    ],

    'keys' => [
        'title' => 'Client key rates',
        'description' => 'A key\'s rate applies to everything that comes in through it and knows no data types — it is the emergency brake for a single application. It is changed on the keys page.',
        'name' => 'Key',
        'per_minute' => 'Rate per minute',
        'usage' => 'this minute',
        'unlimited' => 'unlimited',
        'inactive' => 'disabled',
        'empty' => 'The project has no keys.',
    ],

    'discards' => [
        'title' => 'What was discarded',
        'description' => 'The last :days days, by reason. "rate limit" and "quota used up" come from this page; the other reasons explain what else got lost on the way here.',
        'reason' => 'Reason',
        'origin' => 'Origin',
        'quantity' => 'Count',
        'empty' => 'Nothing was discarded in this period.',
    ],

    'notification' => [
        'title' => ':percent % of the quota for :category used (:subject)',
        'body' => 'Of :limit :category this month, :usage are used. Once the quota is used up, further events of this data type are rejected — they do not arrive later.',
        'context_scope' => 'Level',
        'context_subject' => 'Applies to',
    ],

    'flash' => [
        'updated' => 'The quotas have been saved.',
    ],

];

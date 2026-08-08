<?php

// Trace view (resources/js/shell/pages/traces/Show.jsx,
// App\Http\Controllers\TraceController).
return [

    'title' => 'Trace',

    'help' => [
        'purpose' => 'This view shows the complete course of one call: every service involved, their individual steps, and the errors that happened along the way.',
        'waterfall' => 'Each bar starts where the step started and is as wide as it took. Indented means it ran inside another step — gaps between two bars are waiting time.',
        'gaps' => 'Where a parent step is missing, a gap is shown. It does not mean “nothing happened” but “we do not have it”: a service is not connected, its report is still on its way, or sampling dropped it.',
        'errors' => 'An error is marked on the step that reported it. From there you get to the error itself — and from every error back into its trace.',
        'select' => 'Clicking a step shows its details: the full SQL, the target of a call, the extra data the SDK sent. The opened step is part of the address and can therefore be shared.',
    ],

    'summary' => [
        'duration' => 'Total duration',
        'services' => 'Services',
        'transactions' => 'Transactions',
        'spans' => 'Steps',
        'errors' => 'Errors',
        'started' => 'Start',
        'trace' => 'Trace ID',
        'copy' => 'Copy ID',
    ],

    'empty' => [
        'title' => 'Nothing is on record for this trace.',
        'hint' => 'Either nothing has arrived yet, or the measurements belong to projects you are not allowed to see. They may also have been cleaned up in the meantime.',
    ],

    'truncated' => 'This trace is larger than what is shown here: at most :transactions transactions, :spans steps and :errors errors. The rest is missing from the view.',

    'waterfall' => [
        'heading' => 'Course',
        'expand' => 'Expand',
        'collapse' => 'Collapse',
        'expand_all' => 'Expand all',
        'collapse_all' => 'Collapse all',
        'no_description' => 'no description',
        'missing' => 'Missing step',
        'missing_hint' => 'This step is referenced but not on record. Everything below it belongs to it.',
        'root' => 'Root',
        'errors' => ':count errors in this step',
        'error' => 'One error in this step',
        'rows' => ':shown of :total rows visible',
    ],

    'errors' => [
        'heading' => 'Errors in this trace',
        'loose' => 'Errors without a matching step',
        'loose_hint' => 'These errors belong to the trace but name no step, or one that is missing here.',
        'open' => 'Open error',
        'no_link' => 'No detail page available',
    ],

    'detail' => [
        'heading' => 'Details',
        'close' => 'Close',
        'loading' => 'Loading …',
        'gone' => 'No details are (still) on record for this step.',
        'description' => 'Description',
        'operation' => 'Operation',
        'status' => 'Status',
        'project' => 'Service',
        'transaction' => 'Transaction',
        'environment' => 'Environment',
        'release' => 'Release',
        'span_id' => 'Span ID',
        'parent_span_id' => 'Parent step',
        'started' => 'Start',
        'duration' => 'Duration',
        'data' => 'Data',
        'no_data' => 'No extra data.',
        'errors' => 'Errors in this step',
    ],
];

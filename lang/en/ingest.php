<?php

// Rejections of the data intake (App\Exceptions\IngestRejection and its
// callers). They go to the reporting SDK as the error text.
return [

    'unauthorized' => 'The client key is unknown or does not belong to this project.',
    'too_large' => 'The report is too large — the limit is :bytes bytes.',
    'not_json' => 'The report is not a JSON object.',
    'no_content' => 'The report has no content.',
    'not_decodable' => 'The report could not be decompressed.',
    'feedback_incomplete' => 'The feedback has no text — without a description it is not feedback.',
    'envelope_header' => 'The envelope does not start with a JSON header line.',
    'security_unknown' => 'Not a known security report — expected csp-report, expect-ct-report or expect-staple-report.',

    'rate_limited' => 'Too many events — please try again in :seconds seconds.',
    'quota_exceeded' => 'The quota for this data type is used up for this month.',

];

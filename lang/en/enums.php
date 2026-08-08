<?php

// Labels for the enumerations in app/Enums. One key per case, named after the
// stored value so the reference stays readable in exports as well.
return [

    'alert_status' => [
        'ok' => 'OK',
        'warning' => 'Warning',
        'critical' => 'Critical',
    ],

    'alert_direction' => [
        'above' => 'Rises above the threshold',
        'below' => 'Falls below the threshold',
    ],

    'alert_comparison' => [
        'absolute' => 'Value itself',
        'percent_change_week' => 'Change against the previous week',
    ],

    'alert_metric' => [
        'error_count' => 'Error reports',
        'transaction_throughput' => 'Throughput (calls)',
        'transaction_failure_rate' => 'Failure rate of calls',
        'transaction_duration_avg' => 'Response time (average)',
        'transaction_duration_p50' => 'Response time (p50)',
        'transaction_duration_p95' => 'Response time (p95)',
        'transaction_duration_p99' => 'Response time (p99)',
    ],

    'grouping_source' => [
        'rule' => 'Project rule',
        'custom' => 'Fingerprint sent by the SDK',
        'stacktrace' => 'Stack trace',
        'exception' => 'Exception',
        'message' => 'Message',
        'fallback' => 'Title and culprit',
        'empty' => 'Nothing to tell them apart',
        'performance' => 'Performance detection',
    ],

    'api_scope' => [
        'org:read' => 'Read organization',
        'org:write' => 'Change organization',
        'member:read' => 'Read members',
        'member:write' => 'Manage members',
        'team:read' => 'Read teams',
        'team:write' => 'Manage teams',
        'project:read' => 'Read projects',
        'project:write' => 'Change projects',
        'project:admin' => 'Manage projects',
        'event:read' => 'Read events',
        'event:write' => 'Submit events',
        'issue:read' => 'Read issues',
        'issue:write' => 'Edit issues',
        'alerts:read' => 'Read alerts',
        'alerts:write' => 'Manage alerts',
    ],

    'api_scope_group' => [
        'org' => 'Organization',
        'member' => 'Members',
        'team' => 'Teams',
        'project' => 'Projects',
        'event' => 'Events',
        'issue' => 'Issues',
        'alerts' => 'Alerts',
        'other' => 'Other',
    ],

    'api_token_kind' => [
        'personal' => 'Personal',
        'organization' => 'Organization-wide',
    ],

    'api_token_kind_description' => [
        'personal' => 'Acts on your behalf and ends with your membership.',
        'organization' => 'Belongs to the organization and works independently of individual accounts.',
    ],

    'audit_action' => [
        'organization_created' => 'Organization created',
        'organization_updated' => 'Organization changed',
        'invitation_sent' => 'Invitation sent',
        'invitation_role_changed' => 'Invitation role changed',
        'invitation_revoked' => 'Invitation revoked',
        'invitation_accepted' => 'Invitation accepted',
        'membership_role_changed' => 'Role changed',
        'membership_removed' => 'Member removed',
        'membership_left' => 'Left organization',
        'team_created' => 'Team created',
        'team_updated' => 'Team changed',
        'team_deleted' => 'Team deleted',
        'team_member_added' => 'Member added to team',
        'team_member_removed' => 'Member removed from team',
    ],

    'cron_schedule_type' => [
        'crontab' => 'Cron expression',
        'interval' => 'Interval',
    ],

    'cron_interval_unit' => [
        'minute' => 'minutes',
        'hour' => 'hours',
        'day' => 'days',
        'week' => 'weeks',
        'month' => 'months',
        'year' => 'years',
    ],

    'cron_check_in_status' => [
        'in_progress' => 'running',
        'ok' => 'completed',
        'error' => 'failed',
        'missed' => 'missed',
        'timeout' => 'ran too long',
    ],

    'cron_monitor_status' => [
        'unknown' => 'no run yet',
        'ok' => 'healthy',
        'running' => 'running',
        'missed' => 'missed',
        'timeout' => 'ran too long',
        'error' => 'failed',
        'disabled' => 'turned off',
    ],

    'delivery_status' => [
        'pending' => 'in transit',
        'sent' => 'delivered',
        'failed' => 'failed',
    ],

    'discard_origin' => [
        'server' => 'discarded by the server',
        'client' => 'discarded by the SDK',
    ],

    'discard_reason' => [
        'unknown_type' => 'unknown type',
        'unreadable' => 'unreadable',
        'too_large' => 'too large',
        'too_many_items' => 'too many items',
        'duplicate' => 'duplicate delivery',
        'sampled' => 'not part of the sample',
        'scrubbed' => 'not stored for privacy reasons',
        'filtered' => 'discarded by an inbound filter',
        'orphaned' => 'no matching event',
    ],

    'inbound_filter_kind' => [
        'browser_extension' => 'Browser extensions',
        'legacy_browser' => 'Legacy browsers',
        'localhost' => 'Local development',
        'crawler' => 'Web crawlers',
        'message_pattern' => 'Error messages by pattern',
        'ip_address' => 'Sender blocklist',
        'release' => 'Release blocklist',
    ],

    'event_level' => [
        'fatal' => 'Fatal',
        'error' => 'Error',
        'warning' => 'Warning',
        'info' => 'Info',
        'debug' => 'Debug',
    ],

    'filter_period' => [
        '1h' => 'Last hour',
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
        '14d' => 'Last 14 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        'custom' => 'Custom range',
    ],

    'ingest_type' => [
        'event' => 'Error report',
        'transaction' => 'Transaction',
        'session' => 'Session',
        'sessions' => 'Sessions',
        'attachment' => 'Attachment',
        'check_in' => 'Cron job check-in',
        'replay_event' => 'Replay (header)',
        'replay_recording' => 'Replay (data)',
        'profile' => 'Profile',
        'client_report' => 'Client report of the SDK',
        'user_report' => 'User feedback',
    ],

    'security_report_type' => [
        'csp' => 'Content Security Policy violation',
        'expect-ct' => 'Certificate Transparency violation',
        'expect-staple' => 'OCSP stapling failure',
    ],

    'processing_state' => [
        'pending' => 'awaiting processing',
        'processed' => 'processed',
        'duplicate' => 'duplicate',
        'dropped' => 'dropped',
        'failed' => 'failed',
    ],

    'notification_event' => [
        'alert' => 'Alerts',
        'assignment' => 'Assignments',
        'mention' => 'Mentions',
        'workflow_change' => 'Workflow changes',
        'deploy' => 'Deploys',
        'weekly_digest' => 'Weekly digest',
        'quota_warning' => 'Quota warnings',
    ],

    'notification_event_description' => [
        'alert' => 'An alert fired — something is broken.',
        'assignment' => 'An issue was assigned to you.',
        'mention' => 'Someone mentioned you in a comment.',
        'workflow_change' => 'An issue was resolved, ignored or reopened.',
        'deploy' => 'A new version has been released.',
        'weekly_digest' => 'The weekly summary of your projects.',
        'quota_warning' => 'The ingest quota is running low.',
    ],

    'notification_level' => [
        'info' => 'Information',
        'warning' => 'Warning',
        'error' => 'Error',
    ],

    'notification_transport' => [
        'mail' => 'Email',
        'in_app' => 'In the application',
    ],

    'notification_transport_description' => [
        'mail' => 'To the email address of this account.',
        'in_app' => 'In the inbox inside Errstack.',
    ],

    'organization_role' => [
        'owner' => 'Owner',
        'admin' => 'Administration',
        'member' => 'Member',
        'viewer' => 'Read-only',
    ],

    'platform' => [
        'php' => 'PHP',
        'javascript' => 'JavaScript',
        'python' => 'Python',
        'node' => 'Node.js',
        'java' => 'Java',
        'go' => 'Go',
        'ruby' => 'Ruby',
        'dotnet' => '.NET',
        'other' => 'Other',
    ],

    'scrub_rule_type' => [
        'field' => 'Field name',
        'pattern' => 'Pattern in the value',
    ],

    'resolution_behavior' => [
        'manual' => 'Resolve manually only',
        'after_week' => 'After 7 days without a new occurrence',
        'after_month' => 'After 30 days without a new occurrence',
    ],

    'issue_status' => [
        'unresolved' => 'Unresolved',
        'resolved' => 'Resolved',
        'ignored' => 'Ignored',
    ],

    'issue_priority' => [
        'high' => 'High',
        'medium' => 'Medium',
        'low' => 'Low',
    ],

    'count_period' => [
        'hour' => 'Hourly',
        'day' => 'Daily',
    ],

    'trend_direction' => [
        'new' => 'New — not measured in the previous period',
        'unknown' => 'Too few measurements to compare',
        'flat' => 'Unchanged',
        'better' => 'Got faster',
        'worse' => 'Got slower',
    ],

    'issue_category' => [
        'error' => 'Error',
        'performance' => 'Performance issue',
    ],

    'performance_problem' => [
        'n_plus_one_queries' => 'N+1 queries',
        'consecutive_queries' => 'Consecutive similar queries',
        'duplicate_queries' => 'Duplicate queries',
        'slow_http_call' => 'Slow HTTP call',
        'oversized_asset' => 'Oversized or uncompressed asset',
        'render_blocking_asset' => 'Render-blocking resource',
        'main_thread_block' => 'Main thread block',
        'cache_misses' => 'Cache misses',
    ],

    'performance_problem_description' => [
        'n_plus_one_queries' => 'One query fetches a list, then each entry is looked '
            .'up separately. A join or eager loading replaces the whole series with a '
            .'single query.',
        'consecutive_queries' => 'The same query shape runs several times in a row, '
            .'each waiting for the previous one. Batched or concurrent, it costs the '
            .'wait only once.',
        'duplicate_queries' => 'The same query with the same values runs more than '
            .'once in a single trace. Every repetition returns the same answer and can '
            .'simply be dropped.',
        'slow_http_call' => 'A call to an external service takes longer than the '
            .'configured threshold.',
        'oversized_asset' => 'A file is very large or served uncompressed — visible '
            .'from the transferred and decoded sizes being the same.',
        'render_blocking_asset' => 'A script or stylesheet holds up the browser before '
            .'it can display anything at all.',
        'main_thread_block' => 'A piece of work keeps the browser busy long enough '
            .'that it cannot respond to input.',
        'cache_misses' => 'Cache lookups repeatedly come back empty — usually a badly '
            .'built key or an entry that is never written.',
    ],

    'performance_threshold' => [
        'min_count' => 'Minimum count',
        'min_total_ms' => 'Minimum total in ms',
        'min_duration_ms' => 'Minimum duration in ms',
        'min_size_kb' => 'Minimum size in KB',
    ],

];

<?php

// Notifications: an organization's channels, personal preferences and
// unsubscribing straight from an email
// (resources/js/shell/pages/notifications, App\Http\Controllers\Notification*).
return [

    'channels' => [
        'title' => 'Notifications',
        'help' => 'Every channel goes to a target of its own. "Send test message" sends a real message the same way — the result appears in the log. The layout of the webhook signature is documented in :docs.',

        'new_title' => 'New channel',
        'new_description' => 'Where Errstack should report to. Credentials are stored encrypted and never shown again.',
        'channel' => 'Channel',
        'name' => 'Name',
        'name_placeholder' => 'On call',
        'create' => 'Set up channel',

        'list_title' => 'Channels',
        'list_description' => 'The configured routes of this organization.',
        'empty' => 'No channel configured yet — messages stay inside Errstack.',
        'inactive' => '(switched off)',
        'test' => 'Send test message',
        'edit' => 'Edit',
        'close' => 'Close',
        'delete' => 'Delete',
        'secrets_hint' => 'Credentials stay unchanged as long as the field is left empty.',
        'active' => 'Channel is active',
        'unknown' => 'Unknown channel',
        'save' => 'Save',
    ],

    'deliveries' => [
        'title' => 'Delivery log',
        'description' => 'Every attempt with its result. Failed ones are retried by the queue automatically; after that "Try again" helps.',
        'empty' => 'Nothing delivered yet.',
        'test' => '(test)',
        'attempt' => '1 attempt',
        'attempts' => ':count attempts',
        'retry' => 'Try again',
        'channel_off' => 'The channel is switched off.',
    ],

    'preferences' => [
        'title' => 'Notifications',
        'help' => 'Decide per occasion how you are informed. A setting for a project beats the one of the organization, and that one beats "Everywhere". Critical alerts reach you during quiet hours and after unsubscribing from everything — switching them off is only possible here, explicitly.',

        'scope_title' => 'Scope',
        'scope_description' => 'The finer the scope, the stronger it applies.',
        'scope_project' => 'Project: :name',
        'scope_organization' => 'Organization: :name',
        'scope_global' => 'Everywhere',
        'scope_global_hint' => 'Applies as long as an organization or a project says nothing of its own.',
        'scope_organization_hint' => 'Differs from "Everywhere" wherever something is set here.',
        'scope_project_hint' => 'The finest level — it beats the organization and "Everywhere".',

        'matrix_description' => 'One occasion per row, one route per column.',
        'event_column' => 'Occasion',
        'critical' => 'critical',
        'choice_inherit' => 'Inherits',
        'choice_on' => 'On',
        'choice_off' => 'Off',
        'effective_on' => 'in effect: on',
        'effective_off' => 'in effect: off',
        'cell_label' => ':event — :transport',
        'save' => 'Save',
        'saved' => 'Saved.',

        'critical_warning' => 'Critical alerts do not reach you everywhere.',
        'critical_entry' => ':event — :scope: not a single route is active.',

        'quiet_title' => 'Quiet hours',
        'quiet_description' => 'During this span it stays silent. Critical alerts still arrive.',
        'quiet_active' => 'Quiet hours right now — back from :time.',
        'quiet_enabled' => 'Observe quiet hours',
        'quiet_from' => 'From',
        'quiet_until' => 'To',
        'timezone' => 'Time zone',

        'unsubscribed_title' => 'Unsubscribed from everything',
        'unsubscribed_description' => 'Since :date. Critical alerts still reach you.',
        'resubscribe' => 'Receive everything again',
        'unsubscribe_title' => 'Unsubscribe from everything',
        'unsubscribe_description' => 'Switches off all non-critical notifications — in one go and without losing the individual settings.',
        'unsubscribe' => 'Unsubscribe from everything',
        'digest_title' => 'Bundle notifications',
        'digest_description_on' => 'Bursts reach you as a single digest, provided the project has set a time window for it. Urgent notifications still arrive immediately and on their own.',
        'digest_description_off' => 'You receive every notification individually — even where the project bundles them.',
        'digest_disable' => 'Turn bundling off',
        'digest_enable' => 'Turn bundling on',
    ],

    'unsubscribe' => [
        'title' => 'Unsubscribe',
        'heading' => 'Unsubscribe from notifications',
        'recipient' => 'For :email.',
        'event_off' => 'No more emails about ":event"',
        'event_off_done' => 'Already switched off.',
        'event_off_hint' => 'Takes effect immediately — including messages already sitting in the queue.',
        'all_off' => 'Unsubscribe from everything',
        'all_off_done' => 'Already unsubscribed from everything.',
        'all_off_hint' => 'Switches off all non-critical notifications. Critical alerts still arrive.',
        'settings_link' => 'Open all settings',
        'critical_title' => '":event" is a critical alert.',
        'critical_body_before' => 'It reaches you during quiet hours and after unsubscribing from everything. Switching it off is only possible explicitly in the',
        'critical_link' => 'notification settings',
        'unknown_event' => 'Unknown occasion: :event',
    ],

    'flash' => [
        'channel_created' => 'Channel ":name" set up.',
        'channel_updated' => 'Channel ":name" saved.',
        'channel_deleted' => 'Channel ":name" deleted.',
        'channel_tested' => 'Test message to ":name" queued. The result will appear in the log shortly.',
        'delivery_not_failed' => 'This delivery did not fail — there is nothing to retry.',
        'delivery_retried' => 'Delivery queued again.',
        'preferences_saved' => 'Notifications saved.',
        'quiet_hours_saved' => 'Quiet hours saved.',
        'digest_enabled' => 'Bundling switched on. Bursts will arrive as a digest from now on.',
        'digest_disabled' => 'Bundling switched off. You receive every notification individually.',
        'unsubscribed' => 'Unsubscribed from everything. Critical alerts still arrive.',
        'resubscribed' => 'Notifications switched back on.',
        'unsubscribed_all' => 'Unsubscribed. Critical alerts still arrive — everything else does not.',
        'unsubscribed_event' => 'No more emails about ":event".',
    ],

];

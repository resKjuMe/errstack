<?php

// Access tokens (resources/js/shell/pages/api-tokens/Index.jsx and
// App\Http\Controllers\ApiTokenController).
return [

    'title' => 'Access tokens',
    'help' => 'With a token, scripts, CI runs and tools talk to the interface under /api/0/ — on behalf of the organization ":organization" and only within the chosen scopes. The value replaces a password: it belongs in a secret store, not in the repository.',
    'organization' => 'Organization: :name',

    'empty_title' => 'No token yet',
    'empty_description' => 'Create one to use the interface from the outside.',

    'created' => [
        'title' => 'Token ":name" is ready',
        'description' => 'Copy it now and store it safely — this value is never shown again.',
        'copy' => 'Copy',
    ],

    'card' => [
        'expired' => 'Expired',
        'owner' => 'Belongs to: :owner',
        'created_by' => 'Created by :name',
        'last_used' => 'Last used on :date',
        'never_used' => 'Not used yet',
        'valid_until' => 'Valid until :date',
        'unlimited' => 'No expiry',
        'revoke' => 'Revoke',
    ],

    'create' => [
        'title' => 'New token',
        'description' => 'The value is shown only once.',
        'name' => 'Name',
        'name_placeholder' => 'e.g. release from CI',
        'kind' => 'Kind',
        'scopes' => 'Scopes',
        'scope_forbidden' => 'Your own role does not allow this scope.',
        'expires' => 'Validity',
        'expires_never' => 'No expiry',
        'expires_30' => '30 days',
        'expires_90' => '90 days',
        'expires_365' => '1 year',
        'submit' => 'Create token',
    ],

    'flash' => [
        'created' => 'Token ":name" created.',
        'revoked' => 'Token ":name" revoked.',
    ],

];

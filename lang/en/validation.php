<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Translated are the rules that actually occur in this application's forms.
    | Everything else falls back key by key to the framework's own lines, so
    | this file does not have to be complete.
    |
    */

    'confirmed' => ':attribute does not match the confirmation.',
    'current_password' => 'The password is incorrect.',
    'email' => ':attribute must be a valid email address.',
    'enum' => ':attribute is not a valid choice.',
    'exists' => ':attribute is unknown.',
    'integer' => ':attribute must be a number.',
    'lowercase' => ':attribute may only contain lowercase letters.',
    'max' => [
        'array' => ':attribute may have at most :max entries.',
        'file' => ':attribute may be at most :max kilobytes.',
        'numeric' => ':attribute may be at most :max.',
        'string' => ':attribute may be at most :max characters long.',
    ],
    'min' => [
        'array' => ':attribute must have at least :min entries.',
        'file' => ':attribute must be at least :min kilobytes.',
        'numeric' => ':attribute must be at least :min.',
        'string' => ':attribute must be at least :min characters long.',
    ],
    'password' => [
        'letters' => ':attribute must contain at least one letter.',
        'mixed' => ':attribute must contain upper and lower case letters.',
        'numbers' => ':attribute must contain at least one digit.',
        'symbols' => ':attribute must contain at least one special character.',
        'uncompromised' => ':attribute appears in a data breach. Please choose another one.',
    ],
    'required' => ':attribute is required.',
    'string' => ':attribute must be text.',
    'unique' => ':attribute is already in use.',

    /*
    |--------------------------------------------------------------------------
    | Messages of this application's forms
    |--------------------------------------------------------------------------
    |
    | What the forms of this application have to say beyond the framework's own
    | rules (App\Http\Requests).
    |
    */

    'messages' => [
        'range_reversed' => 'The end of the period lies before its start.',
        'range_from_missing' => 'A custom range needs a start.',
        'range_to_missing' => 'A custom range needs an end.',
        'channel_name_taken' => 'A channel of that name already exists here.',
        'environment_pattern' => 'Allowed are lowercase letters, digits, dot, hyphen and underscore.',
        'invitation_already_member' => 'This address already belongs to the organization.',
        'invitation_already_open' => 'An invitation to this address is already open.',
        'invitation_expired' => 'This invitation has expired. Please ask for a new one.',
        'organization_required' => 'API tokens need an organization first.',
        'token_kind_forbidden' => 'Only administrators may create organization-wide tokens.',
        'token_scope_forbidden' => 'Your own role does not allow granting ":scope".',
        'scope_role_too_low' => 'Your own role in the organization is not enough for ":scope".',
        'membership_gone' => 'The account no longer belongs to this organization.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | Replaces :attribute with a readable name — without it the message would
    | show the raw form field name ("password_confirmation").
    |
    */

    'attributes' => [
        'current_password' => 'The current password',
        'email' => 'The email address',
        'name' => 'The name',
        'password' => 'The password',
        'password_confirmation' => 'The password confirmation',
        'platform' => 'Platform',
        'default_environment' => 'Default environment',
        'resolution_behavior' => 'Resolution behaviour',
        'retention_days' => 'Data retention',
        'type' => 'Channel',
        'role' => 'The role',
        'user_id' => 'The member',
    ],

];

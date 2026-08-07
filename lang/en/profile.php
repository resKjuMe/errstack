<?php

// Profile page (resources/js/shell/pages/Profile.jsx).
return [

    'title' => 'Profile',
    'help' => 'Change your name, email address, language and password — or delete the account for good.',
    'saved' => 'Saved.',

    'information' => [
        'title' => 'Account details',
        'hint' => 'Name, email address and language of this account.',
        'name' => 'Name',
        'email' => 'Email address',
        'locale' => 'Interface language',
        'locale_hint' => 'Applies to emails and notifications as well. Without a choice of your own the interface follows your browser setting.',
        'locale_browser' => 'Browser language',
        'unverified' => 'This email address has not been confirmed yet.',
        'resend' => 'Send confirmation link again',
        'resent' => 'Confirmation link sent.',
        'submit' => 'Save',
    ],

    'password' => [
        'title' => 'Change password',
        'hint' => 'A long, random password protects the account best.',
        'current' => 'Current password',
        'new' => 'New password',
        'confirmation' => 'Repeat new password',
        'submit' => 'Save',
    ],

    'delete' => [
        'title' => 'Delete account',
        'hint' => 'Deleting the account removes all data attached to it — irreversibly.',
        'button' => 'Delete account',
        'dialog_title' => 'Really delete this account?',
        'dialog_hint' => 'Please enter your password to confirm. After that the account is gone for good.',
        'password' => 'Password',
        'cancel' => 'Cancel',
        'confirm' => 'Delete account',
    ],

];

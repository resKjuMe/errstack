<?php

// Text of the sign-in pages (resources/js/shell/pages/auth). Kept apart from
// `auth`, which holds the framework's own messages.
return [

    'login' => [
        'title' => 'Sign in',
        'email' => 'Email address',
        'password' => 'Password',
        'remember' => 'Stay signed in',
        'register' => 'Create account',
        'forgot' => 'Forgot your password?',
        'submit' => 'Sign in',
    ],

    'register' => [
        'title' => 'Create account',
        'name' => 'Name',
        'email' => 'Email address',
        'password' => 'Password',
        'password_confirmation' => 'Repeat password',
        'have_account' => 'Already registered?',
        'submit' => 'Create account',
    ],

    'forgot' => [
        'title' => 'Forgot password',
        'intro' => 'Enter your email address — a link to set a new password will follow.',
        'email' => 'Email address',
        'back' => 'Back to sign-in',
        'submit' => 'Request link',
    ],

    'reset' => [
        'title' => 'Set a new password',
        'email' => 'Email address',
        'password' => 'New password',
        'password_confirmation' => 'Repeat new password',
        'submit' => 'Save password',
    ],

    'confirm' => [
        'title' => 'Confirm password',
        'intro' => 'This area is specially protected. Please enter your password again.',
        'password' => 'Password',
        'submit' => 'Confirm',
    ],

    'verify' => [
        'title' => 'Verify email address',
        'intro' => 'One last step: confirm your email address by clicking the link in the message we sent. No message? We are happy to send a new one.',
        'sent' => 'A new confirmation link is on its way to the address on file.',
        'resend' => 'Send message again',
        'change_address' => 'Change address',
        'sign_out' => 'Sign out',
    ],

];

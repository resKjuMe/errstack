<?php

// Connected repositories (app/Http/Controllers/RepositoryController,
// resources/js/shell/pages/repositories).
return [

    'title' => 'Repositories',
    'help' => 'Where this organization\'s code comes from. Connecting means entering '
        .'the name a build sends its commits under ("acme/webshop"). A repository '
        .'also appears on its own as soon as a hand-off carries an unknown name — all '
        .'it is missing then is the address under which its commits can be viewed.',

    'list' => [
        'empty' => 'No repository connected yet.',
        'empty_hint' => 'As soon as a deploy hands over its commits, the repository '
            .'appears here.',
        'commits' => ':count commits',
        'no_url' => 'No address on file',
    ],

    'fields' => [
        'name' => 'Name',
        'name_hint' => 'What the repository is called at the provider, e.g. '
            .'"acme/webshop". This is the exact name a build sends when handing over '
            .'its commits.',
        'url' => 'Address',
        'url_hint' => 'The repository\'s page on the web. Without it every commit hash '
            .'stays a string that leads nowhere.',
    ],

    'actions' => [
        'connect' => 'Connect',
        'disconnect' => 'Disconnect',
        'disconnect_confirm' => 'Disconnect this repository? Its commits disappear from '
            .'every release they are part of. The releases themselves remain.',
    ],

    'validation' => [
        'duplicate' => 'This repository is already connected.',
    ],

    'flash' => [
        'connected' => 'Repository connected.',
        'disconnected' => 'Repository disconnected.',
    ],

];

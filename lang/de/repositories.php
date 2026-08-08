<?php

// Verbundene Repositories (app/Http/Controllers/RepositoryController,
// resources/js/shell/pages/repositories).
return [

    'title' => 'Repositories',
    'help' => 'Woher der Code dieser Organisation kommt. Verbinden heißt hier: den '
        .'Namen eintragen, unter dem eine Bauumgebung ihre Commits übergibt '
        .'(„acme/webshop"). Ein Repository entsteht auch von selbst, sobald eine '
        .'Übergabe einen unbekannten Namen mitbringt — dann fehlt ihm nur noch die '
        .'Adresse, unter der sich seine Commits ansehen lassen.',

    'list' => [
        'empty' => 'Noch kein Repository verbunden.',
        'empty_hint' => 'Sobald eine Auslieferung ihre Commits übergibt, steht das '
            .'Repository hier.',
        'commits' => ':count Commits',
        'no_url' => 'Keine Adresse hinterlegt',
    ],

    'fields' => [
        'name' => 'Name',
        'name_hint' => 'Wie das Repository beim Anbieter heißt, z. B. „acme/webshop". '
            .'Genau diesen Namen schickt eine Bauumgebung beim Übergeben ihrer Commits.',
        'url' => 'Adresse',
        'url_hint' => 'Die Seite des Repositories im Netz. Ohne sie bleibt jeder '
            .'Commit-Hash eine Zeichenkette ohne Ziel.',
    ],

    'actions' => [
        'connect' => 'Verbinden',
        'disconnect' => 'Lösen',
        'disconnect_confirm' => 'Dieses Repository lösen? Seine Commits verschwinden '
            .'damit aus allen Auslieferungen, in denen sie stecken. Die Versionen '
            .'selbst bleiben.',
    ],

    'validation' => [
        'duplicate' => 'Dieses Repository ist bereits verbunden.',
    ],

    'flash' => [
        'connected' => 'Repository verbunden.',
        'disconnected' => 'Repository gelöst.',
    ],

];

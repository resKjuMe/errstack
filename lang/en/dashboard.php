<?php

// Overview page (resources/js/shell/pages/Dashboard.jsx).
return [

    'title' => 'Overview',

    'help' => [
        'sample' => 'This page is a sample page of the interface shell.',
        'frame' => 'Every page sits in the shared frame with navigation and dark mode.',
        'filter' => 'The filter bar at the top applies to all reports. Your selection lives in the address bar — the link shows the recipient the very same view.',
    ],

    'selection' => [
        'title' => 'Current selection',
        'description' => 'As long as there are no reports yet, this card shows what the filter points at.',
        'projects' => 'Projects',
        'environment' => 'Environment',
        'period' => 'Period',
        'all_environments' => 'All environments',
        'no_projects' => 'No projects',
    ],

    'cards' => [
        'frame_title' => 'Frame',
        'frame_description' => 'Header, navigation, mobile menu, user menu and theme switch are in place.',
        'components_title' => 'Components',
        'components_description' => 'Page head, cards, flash messages, loading placeholders and toasts are reusable.',
        'next_title' => 'Next steps',
        'next_description' => 'Feature pages (sign-in, projects, issues) replace this sample page step by step.',
    ],

];

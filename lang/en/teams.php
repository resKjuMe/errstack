<?php

// Team page (resources/js/shell/pages/teams/Show.jsx) and the messages from
// App\Http\Controllers\TeamController and TeamMemberController.
return [

    'help' => 'Teams group members inside an organization. Permissions still hang on the role in the organization, not on the team.',

    'settings' => [
        'title' => 'Details',
        'description' => 'The name of the team.',
        'name' => 'Name',
        'submit' => 'Save',
    ],

    'members' => [
        'title' => 'Members',
        'description' => 'Who belongs to this team.',
        'empty' => 'Nobody assigned yet.',
        'remove' => 'Remove',
        'add' => 'Add member',
        'choose' => 'Please choose',
        'submit' => 'Add',
        'all_assigned' => 'All members of the organization already belong to this team.',
    ],

    'delete' => [
        'title' => 'Delete team',
        'description' => 'The members stay in the organization.',
        'submit' => 'Delete team',
    ],

    'flash' => [
        'created' => 'Team ":name" created.',
        'updated' => 'Team saved.',
        'deleted' => 'Team ":name" deleted.',
        'member_added' => ':name now belongs to ":team".',
        'member_removed' => ':name has been removed from ":team".',
    ],

];

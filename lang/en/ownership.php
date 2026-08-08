<?php

// Ownership rules (resources/js/shell/pages/projects/Ownership.jsx,
// App\Http\Controllers\OwnershipRuleController). Matcher labels live in
// enums.php with every other enumeration.
return [

    'title' => 'Ownership · :project',
    'help' => 'Who looks after an error usually depends on where it happened. This list writes that down once: a pattern on the path, the URL, the module or a tag, plus the owners. If you already keep a CODEOWNERS file, import it as it is.',

    'rules' => [
        'title' => 'Rules',
        'description' => 'Evaluated top to bottom. If several match, the last matching rule wins.',
        'empty' => 'No rules yet — errors stay unowned until somebody assigns them by hand.',
        'last_wins_hint' => 'The last matching rule wins — just like in a CODEOWNERS file. That is why the general rule goes on top and the exception at the bottom: a new line at the end is the exception to everything above it.',
        'not_retroactive_hint' => 'A rule applies to errors from now on and does not distribute existing ones. For those it only suggests in the assignment dialog; to distribute them in bulk, use the bulk action on the issue list.',
        'inactive_badge' => 'disabled',
        'from_codeowners' => 'from CODEOWNERS',
        'position' => 'Rank :position',
    ],

    // The reason a suggestion carries into the assignment dialog (R6).
    'suggestion' => 'Rule :pattern',

    'matcher' => [
        'path' => 'Path',
        'url' => 'URL',
        'module' => 'Module',
        'tag' => 'Tag',
    ],

    'fields' => [
        'matcher' => 'Applies to',
        'tag_key' => 'Tag',
        'pattern' => 'Pattern',
        'position' => 'Rank',
        'owners' => 'Owners',
        'owners_placeholder' => "#Billing\nanna@example.com",
        'owners_hint' => 'One owner per line, at most :max. A team with a leading hash (#Billing), a person by their email address. The first one that resolves gets the assignment; all of them are suggested.',
    ],

    'auto' => [
        'title' => 'Assign automatically',
        'description' => 'Whether a suggestion turns into an assignment.',
        'label' => 'Assign new errors according to these rules',
        'hint' => 'Only affects errors as they appear, and only those without an owner — an assignment made by hand is never overwritten. With the switch off the rules still show up as suggestions in the assignment dialog.',
    ],

    'preview' => [
        'title' => 'Preview',
        'description' => 'Who would own an event like this?',
        'submit' => 'Check',
        'tag_key' => 'Tag',
        'tag_value' => 'Tag value',
        'placeholder' => [
            'path' => 'src/billing/Invoice.php',
            'url' => 'https://example.com/checkout/total',
            'module' => 'com.acme.billing.Invoice',
            'tag_key' => 'server_name',
            'tag_value' => 'web-01',
        ],
        'nothing_given' => 'Nothing given — without a path, URL, module or tag no rule can match.',
        'nobody' => 'No rule matches, or its owners no longer exist in this organization. The error would stay unowned.',
        'would_assign' => 'Would be assigned to: :assignee',
        'would_suggest' => 'Would be suggested: :assignee — nothing is assigned while automatic assignment is off.',
        'winner' => 'wins',
    ],

    'import' => [
        'title' => 'Import CODEOWNERS',
        'description' => 'Paste the file; every usable line becomes a path rule.',
        'label' => 'File contents',
        'placeholder' => "# comments and blank lines are skipped\n/src/billing/  @acme/billing\n*.tsx          anna@example.com",
        'hint' => 'The lines are appended and therefore override everything above them — the same resolution as in the file itself. Lines whose owners do not exist here are skipped: a rule naming nobody would look complete without being it.',
        'submit' => 'Import',
    ],

    'create' => [
        'title' => 'Add a rule',
        'description' => 'New rules are appended and therefore override everything above them.',
        'submit' => 'Add',
    ],

    'save' => 'Save',
    'enable' => 'Enable',
    'disable' => 'Disable',
    'delete' => 'Delete',

    'flash' => [
        'created' => 'Rule :pattern created.',
        'updated' => 'Rule :pattern saved.',
        'enabled' => 'Rule :pattern enabled.',
        'disabled' => 'Rule :pattern disabled.',
        'deleted' => 'Rule :pattern deleted.',
        'imported' => ':count rules imported, :skipped lines skipped.',
        'auto_on' => 'New errors will be assigned according to these rules from now on.',
        'auto_off' => 'Automatic assignment disabled — the rules keep suggesting.',
    ],

    'validation' => [
        'too_many' => 'A project may have at most :max ownership rules.',
        'tag_key_required' => 'A tag rule needs the tag name — without it the rule could never match.',
        'owner_invalid' => 'That does not name anybody in particular. Use a team with a leading hash (#Billing) or a person’s email address.',
    ],

];

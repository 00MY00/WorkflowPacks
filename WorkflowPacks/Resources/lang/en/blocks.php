<?php

// Blocks of the internal flow engine (Blocks/, Services/Engine). Titles are composed when shown, never stored.
return [
    'families'  => [
        'trigger'   => 'Triggers',
        'condition' => 'Conditions',
        'wait'      => 'Waits',
        'action'    => 'Actions',
        'util'      => 'Tools',
    ],
    'outputs'   => [
        'next'  => 'next',
        'yes'   => 'yes',
        'no'    => 'no',
        'error' => 'error',
    ],
    'already'   => 'already done, nothing to change',
    'trigger'   => [
        'new_mail' => [
            'name'  => 'New e-mail',
            'title' => 'New e-mail',
            'help'  => 'A customer writes a new e-mail in a mailbox of the flow.',
        ],
    ],
    'condition' => [
        'ticket' => [
            'name'       => 'If…',
            'title'      => 'If :var :operator “:value”',
            'title_bool' => 'If :var: :operator',
            'decision'   => ':var “:actual” :operator “:value” → :output',
            'help'       => 'Tests the ticket or the result of a block before.',
        ],
    ],
    'wait'      => [
        'duration' => [
            'name'  => 'Wait',
            'title' => 'Wait :delay',
            'help'  => 'The ticket waits, then goes on.',
        ],
    ],
    'action'    => [
        'status'      => [
            'name'  => 'Status',
            'title' => 'Status: :status',
            'help'  => 'Changes the status of the ticket.',
        ],
        'assign'      => [
            'name'  => 'Assign',
            'title' => 'Assign to :user',
            'help'  => 'Assigns the ticket to a user of the mailbox, or to nobody.',
        ],
        'note'        => [
            'name'  => 'Internal note',
            'title' => 'Note: :text',
            'help'  => 'Adds an internal note; {{ticket.subject}} is replaced.',
        ],
        'imap_folder' => [
            'name'  => 'File into a folder',
            'title' => 'Folder: :folder',
            'help'  => 'Files the e-mail into a folder of the mail server.',
        ],
    ],
    'vars'      => [
        'ticket' => [
            'subject'  => 'Subject',
            'body'     => 'Text of the e-mail',
            'from'     => 'Sender',
            'status'   => 'Status',
            'assigned' => 'Assigned',
            'replied'  => 'The team replied',
        ],
    ],
    'ops'       => [
        'contains'     => 'contains',
        'not_contains' => 'does not contain',
        'equal'        => 'equals',
        'not_equal'    => 'differs from',
        'starts'       => 'starts with',
        'ends'         => 'ends with',
        'regex'        => 'matches the expression',
        'is'           => 'is',
        'is_not'       => 'is not',
        'yes'          => 'yes',
        'no'           => 'no',
        'gte'          => '≥',
        'lte'          => '≤',
        'eq'           => '=',
    ],
    'values'    => [
        'status' => [
            'active'  => 'Active',
            'pending' => 'Pending',
            'closed'  => 'Closed',
            'spam'    => 'Spam',
        ],
        'nobody' => 'nobody',
    ],
    'errors'    => [
        'required'     => 'Required.',
        'choice'       => 'Value not allowed.',
        'id'           => 'Invalid or duplicate block id.',
        'link'         => 'Invalid link (unknown block or output already linked).',
        'count'        => 'A flow has 1 to :max blocks.',
        'type'         => 'Unknown type of block: “:type”.',
        'var'          => 'This variable is not given by a block before.',
        'trigger'      => 'A flow has exactly one trigger.',
        'port'         => 'This block has no “:port” output.',
        'into_trigger' => 'A link cannot lead to a trigger.',
        'unreached'    => 'This block is not linked to the trigger.',
        'loop'         => 'Loop without a wait: it would never end.',
        'steps'        => 'Stopped after :max blocks in a row (loop?).',
        'internal'     => 'Internal error, tried again later.',
        'delay'        => 'Invalid duration (1 to 100,000 minutes, hours or days).',
        'regex'        => 'Invalid or too long expression (:max characters at most).',
        'long'         => 'Text too long (:max bytes at most).',
        'user'         => 'This user no longer has access to the mailbox.',
        'folder'       => 'Invalid folder name.',
        'imap_off'     => 'Option “File into an e-mail folder” is off (Manage › Settings › Workflow Packs).',
        'imap_nothing' => 'No e-mail to file (see the history).',
    ],
];

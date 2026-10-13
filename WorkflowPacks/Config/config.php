<?php

return [
    // ------------------------------------------------------------------------------------------ Workflows module
    // Values checked against the public copy of Modules/Workflows/Entities/Workflow.php (see docs/analyse.md).
    // They are verified again on the instance by "php artisan workflowpacks:check" before any import.
    'workflows' => [
        'module_alias'   => 'workflows',
        'model'          => 'Modules\\Workflows\\Entities\\Workflow',
        // columns written by the import (blocking when missing)
        'columns'        => ['mailbox_id', 'name', 'type', 'active', 'conditions', 'actions', 'apply_to_prev', 'max_executions', 'sort_order'],
        // columns read when present
        'optional_columns' => ['complete'],
        // name rule of WorkflowsController::createSave(): required|string|max:75, unique per mailbox
        'name_max'       => 75,
        // actions whose "value" is stored as a JSON string (Workflow::performActions() json_decode()s it)
        'json_value_actions' => ['reply', 'email_customer', 'forward', 'note'],
        // fields of those JSON values that hold HTML (thread bodies): variables are HTML-escaped there
        'html_fields'    => ['body'],
        // actions and conditions that point to records of this instance (reported on export, checked on import)
        'local_refs' => [
            'conditions' => ['user' => 'user'],
            'actions'    => ['assign' => 'user', 'notification' => 'user', 'move' => 'mailbox'],
        ],
        // types whose "values" list in conditionsConfig()/actionsConfig() is complete: the value must be one of them
        // (for other types the list is only a suggestion, e.g. users added by the interface)
        'strict_values' => [
            'conditions' => ['type', 'status', 'state', 'channel'],
            'actions'    => ['status'],
        ],
        // special values accepted in place of a user id (Conversation::USER_UNASSIGNED, Workflow::ASSIGNEE_CURRENT,
        // "assignee" / "last_user" of the notification action)
        'user_specials'  => ['-1', '-10', 'assignee', 'last_user'],
        // actions shown as sensitive in the preview (sending, forwarding, deleting, moving to another mailbox)
        'sensitive_actions' => [
            'notification'   => 'send',
            'reply'          => 'send',
            'email_customer' => 'send',
            'forward'        => 'forward',
            'delete'         => 'delete',
            'delete_forever' => 'delete',
            'move'           => 'move',
        ],
    ],

    // ------------------------------------------------------------------------------- logical resources (pack v2)
    // A resource of a pack ({{resource.key}}) is mapped to a LOCAL record by the user; the mapping is stored in
    // workflowpacks_resources and the key, never the name, is the identity. Automatic creation is only offered for a
    // type whose model offers a verified way to create it: none so far (see docs/resources.md).
    'resources' => [
        'mailbox' => ['source' => 'freescout', 'create' => false],
        'user'    => ['source' => 'freescout', 'create' => false],
        // Tags module: model and column checked at runtime; the value given to actions ("id" or "name") is declared
        // by each resource of the pack ("use"), since the format of the Tags actions is not known in advance
        'tag'     => ['source' => 'model', 'module' => 'tags', 'model' => 'Modules\\Tags\\Entities\\Tag', 'label' => 'name', 'create' => false],
    ],

    // ------------------------------------------------------------------------------------------- import limits
    'limits' => [
        'file_kb'          => 512,
        'json_depth'       => 32,
        'rules'            => 200,
        'groups'           => 20,   // AND groups per rule
        'conditions'       => 50,   // conditions per OR group
        'actions'          => 50,
        'string'           => 20000,
        'regex'            => 2000,
        'params'           => 50,
        'mailboxes'        => 100,
    ],

];

<?php

namespace Modules\Workflows\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * TEST STUB ONLY — written for the tests of WorkflowPacks, NOT the official module. It reproduces the public
 * members WorkflowPacks relies on (names and shapes found during the analysis, docs/analyse.md), with a small
 * subset of the catalog. Never install it on a real FreeScout.
 */
class Workflow extends Model
{
    const TYPE_AUTOMATIC = 1;
    const TYPE_MANUAL = 2;
    const PERM_EDIT_WORKFLOWS = 6;

    protected $fillable = ['name', 'type', 'max_executions', 'apply_to_prev', 'active', 'conditions', 'actions'];

    protected $attributes = ['type' => self::TYPE_AUTOMATIC, 'max_executions' => 1];

    protected $casts = ['conditions' => 'array', 'actions' => 'array'];

    public static function conditionsConfig($mailbox_id)
    {
        $text = ['equal' => 'Is equal to', 'contains' => 'Contains', 'not_contains' => 'Does not contain', 'not_equal' => 'Is not equal to',
            'starts' => 'Starts with', 'ends' => 'Ends with', 'regex' => 'Matches regex pattern'];
        $config = [
            'people' => ['title' => 'People', 'items' => [
                'customer_email' => ['title' => 'Customer Email', 'operators' => $text],
            ]],
            'conversation' => ['title' => 'Conversation', 'items' => [
                'status'       => ['title' => 'Status', 'operators' => ['equal' => '=', 'not_equal' => '!='], 'values' => [1 => 'Active', 2 => 'Pending', 3 => 'Closed', 4 => 'Spam']],
                'user'         => ['title' => 'Assigned to User', 'operators' => ['equal' => '=', 'not_equal' => '!=']],
                'to'           => ['title' => 'To', 'operators' => $text],
                'subject'      => ['title' => 'Subject', 'operators' => $text],
                'new_or_reply' => ['title' => 'New / Reply / Moved', 'operators' => ['new' => 'New', 'reply' => 'Reply', 'moved' => 'Moved'], 'values' => []],
            ]],
            // date conditions (value { number, metric }), evaluated by the scheduler of the real module
            'dates' => ['title' => 'Dates', 'items' => [
                'waiting'        => ['title' => 'Waiting Since', 'operators' => ['longer' => 'Is longer than', 'not_longer' => 'Is not longer than']],
                'user_reply'     => ['title' => 'Last User Reply', 'operators' => ['in_last' => 'Is in the last', 'not_in_last' => 'Is not in the last']],
                'customer_reply' => ['title' => 'Last Customer Reply', 'operators' => ['in_last' => 'Is in the last', 'not_in_last' => 'Is not in the last']],
                'created'        => ['title' => 'Date Created', 'operators' => ['in_last' => 'Is in the last', 'not_in_last' => 'Is not in the last']],
            ]],
        ];

        return \Eventy::filter('workflows.conditions_config', $config, $mailbox_id);
    }

    public static function actionsConfig($mailbox_id)
    {
        $config = ['dummy' => ['items' => [
            'notification' => ['title' => 'Send Email Notification'],
            'forward'      => ['title' => 'Forward'],
            'note'         => ['title' => 'Add a Note'],
            'status'       => ['title' => 'Change Status', 'values' => [1 => 'Active', 2 => 'Pending', 3 => 'Closed', 4 => 'Spam']],
            'assign'       => ['title' => 'Assign to User', 'values' => [-1 => 'Unassigned']],
            'move'         => ['title' => 'Move to Mailbox'],
            'delete'       => ['title' => 'Move to Deleted Folder', 'values' => []],
        ]]];

        return \Eventy::filter('workflows.actions_config', $config, $mailbox_id);
    }

    public static function canEditWorkflows($user = null, $mailbox_id = null)
    {
        $user = $user ?: auth()->user();
        if (!$user) {
            return false;
        }
        if ($mailbox_id) {
            return $user->isAdmin() || ($user->hasAccessToMailbox($mailbox_id) && $user->hasPermission(self::PERM_EDIT_WORKFLOWS));
        }

        return $user->isAdmin() || $user->hasPermission(self::PERM_EDIT_WORKFLOWS);
    }

    /**
     * Subset of the real checkConditions(): every AND group needs a true OR condition. Like the real module, "New /
     * Reply / Moved" is only true for the event given in $action: without one (background processing) it is skipped.
     */
    public function checkConditions($conversation, $action = '')
    {
        foreach ((array) $this->conditions as $ands) {
            $or_true = false;
            foreach ((array) $ands as $row) {
                $operator = $row['operator'] ?? '';
                $value = (string) ($row['value'] ?? '');
                switch ($row['type'] ?? '') {
                    case 'subject':
                        $text = mb_strtolower((string) $conversation->subject);
                        $or_true = $operator === 'equal' ? $text === mb_strtolower($value) : mb_strpos($text, mb_strtolower($value)) !== false;
                        break;
                    case 'status':
                        $or_true = ((string) $conversation->status === $value) === ($operator !== 'not_equal');
                        break;
                    case 'new_or_reply':
                        if (!in_array($action, ['conversation.created_by_customer', 'conversation.created_by_user', 'conversation.customer_replied', 'conversation.user_replied', 'conversation.moved'])) {
                            continue 2;
                        }
                        $or_true = $operator === 'new' && strpos($action, 'created') !== false;
                        break;
                    default:
                        // conditions added by other modules (real module: Workflow.php, default of checkConditions())
                        $or_true = \Eventy::filter('workflow.check_condition', false, $row['type'] ?? '', $operator, $row['value'] ?? '', $conversation, $this);
                        break;
                }
                if ($or_true) {
                    break;
                }
            }
            if (!$or_true) {
                return false;
            }
        }

        return true;
    }

    /** Conversations created before the rule: only with "apply to previous conversations" (real module). */
    public function checkPrevious($conversation)
    {
        return $this->apply_to_prev || $conversation->created_at > $this->created_at;
    }

    /** Subset of the real performActions(): status, the actions of other modules, then the execution is recorded. */
    public function performActions($conversation)
    {
        foreach ((array) $this->actions as $ands) {
            foreach ((array) $ands as $action) {
                $type = $action['type'] ?? '';
                if ($type === 'status') {
                    $conversation->status = (int) ($action['value'] ?? 1);
                    $conversation->save();
                } else {
                    \Eventy::filter('workflow.perform_action', false, $type, $action['operator'] ?? '', $action['value'] ?? '', $conversation, $this);
                }
            }
        }
        $this->markProcessed($conversation->id);
    }

    public function markProcessed($conversation_id)
    {
        \DB::table('conversation_workflow')->insert(['conversation_id' => $conversation_id, 'workflow_id' => $this->id, 'counter' => 1]);
    }

    public function setSortOrderLast()
    {
        $this->sort_order = (int) self::max('sort_order') + 1;
    }

    public function validate()
    {
        $errors = [];
        if ($this->type != self::TYPE_MANUAL && !$this->conditions) {
            $errors['conditions'] = [];
        }
        if (!$this->actions) {
            $errors['actions'] = [];
        }
        foreach ((array) $this->actions as $group) {
            foreach ((array) $group as $action) {
                if (($action['type'] ?? '') === 'assign' && (string) ($action['value'] ?? '') !== '-1' && !\App\User::find((int) ($action['value'] ?? 0))) {
                    $errors['actions'][] = 'assign';
                }
            }
        }

        return $errors;
    }

    public function checkComplete($save = false)
    {
        $deactivated = false;
        if (count($this->validate())) {
            $this->complete = false;
            $deactivated = (bool) $this->active;
            $this->active = false;
        } else {
            $this->complete = true;
        }
        if ($save) {
            $this->save();
        }

        return $deactivated;
    }
}

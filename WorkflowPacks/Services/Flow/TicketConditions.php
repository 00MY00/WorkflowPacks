<?php

namespace Modules\WorkflowPacks\Services\Flow;

/**
 * Two conditions of Workflow Packs, evaluated by Workflows itself (filter workflow.check_condition), like the e-mail
 * tags of RefreshGlobal (MailTags):
 *   wp_assigned  "Assigned to someone": yes / no
 *   wp_replied   "A reply was sent" (a published message of an agent in the ticket): yes / no
 * They are the "branch" blocks of the flow view. Typical use with a delay: "after 2 days, not replied -> close".
 * Without Workflow Packs, Workflows gets no answer for these types: the rule never starts (safe).
 */
class TicketConditions
{
    const ASSIGNED = 'wp_assigned';
    const REPLIED = 'wp_replied';
    const TYPES = [self::ASSIGNED, self::REPLIED];
    const OPERATORS = ['yes', 'no'];

    public static function register()
    {
        \Eventy::addFilter('workflows.conditions_config', function ($config, $mailboxId = null) {
            if (!is_array($config)) {
                return $config;
            }
            $operators = ['yes' => __('workflowpacks::messages.cond_yes'), 'no' => __('workflowpacks::messages.cond_no')];
            // the events of "New / Reply", and the scheduler for the rules with a delay
            $triggers = ['conversation.created_by_customer', 'conversation.customer_replied', 'conversation.created_by_user', 'conversation.user_replied', 'conversation.moved'];
            $config['workflowpacks_ticket'] = [
                'title' => __('workflowpacks::messages.cond_group_ticket'),
                'items' => [
                    self::ASSIGNED => ['title' => __('workflowpacks::messages.cond_assigned'), 'operators' => $operators, 'values' => [], 'triggers' => $triggers],
                    self::REPLIED  => ['title' => __('workflowpacks::messages.cond_replied'), 'operators' => $operators, 'values' => [], 'triggers' => $triggers],
                ],
            ];

            return $config;
        }, 20, 2);

        \Eventy::addFilter('workflow.check_condition', function ($result, $type = '', $operator = '', $value = '', $conversation = null, $workflow = null) {
            if (!in_array($type, self::TYPES, true) || !$conversation) {
                return $result;
            }

            return self::answer($operator, $type === self::ASSIGNED ? self::isAssigned($conversation) : self::hasReply($conversation));
        }, 20, 6);
    }

    /** "yes" is true when the fact is true, "no" when it is false; any other operator is false. */
    public static function answer($operator, $fact)
    {
        if ($operator === 'yes') {
            return (bool) $fact;
        }

        return $operator === 'no' ? !$fact : false;
    }

    public static function isAssigned($conversation)
    {
        return (int) $conversation->user_id > 0;
    }

    /** One indexed query: a published message written by an agent (notes and drafts are not replies). */
    public static function hasReply($conversation)
    {
        return \App\Thread::where('conversation_id', $conversation->id)
            ->where('type', \App\Thread::TYPE_MESSAGE)
            ->where('state', \App\Thread::STATE_PUBLISHED)
            ->exists();
    }
}

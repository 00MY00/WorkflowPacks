<?php

/*
 * Catalog in the shape returned by Bridge::catalog(): identifiers of the Workflows module found during the analysis.
 * "values": null = free value, [] = no value, list = values offered by the interface.
 */
$text = ['equal', 'contains', 'not_contains', 'not_equal', 'starts', 'ends', 'regex'];
$c = function ($operators, $values = null) {
    return ['title' => '', 'group' => '', 'operators' => $operators, 'values' => $values];
};

return [
    'conditions' => [
        'customer_name'   => $c($text),
        'customer_email'  => $c($text),
        'user_action'     => $c(['replied', 'noted']),
        'type'            => $c(['equal', 'not_equal'], ['1', '2', '3']),
        'status'          => $c(['equal', 'not_equal'], ['1', '2', '3', '4']),
        'state'           => $c(['equal', 'not_equal'], ['1', '2', '3']),
        'user'            => $c(['equal', 'not_equal']),
        'to'              => $c($text),
        'cc'              => $c($text),
        'subject'         => $c($text),
        'body'            => $c(['customer', 'note', 'regex']),
        'headers'         => $c(['contains', 'not_contains', 'regex']),
        'attachment'      => $c(['yes', 'no'], []),
        'bounce'          => $c(['yes', 'no'], []),
        'customer_viewed' => $c(['yes', 'no'], []),
        'new_or_reply'    => $c(['new', 'reply', 'moved'], []),
        'channel'         => $c(['equal', 'not_equal'], ['1']),
        'waiting'         => $c(['longer', 'not_longer']),
        'user_reply'      => $c(['in_last', 'not_in_last']),
        'customer_reply'  => $c(['in_last', 'not_in_last']),
        'created'         => $c(['in_last', 'not_in_last']),
    ],
    'actions' => [
        'notification'   => $c(null),
        'reply'          => $c(null),
        'email_customer' => $c(null),
        'no_autoreply'   => $c(null, []),
        'forward'        => $c(null),
        'note'           => $c(null),
        'status'         => $c(null, ['1', '2', '3', '4']),
        'assign'         => $c(null, ['-1']),
        'move'           => $c(null),
        'delete'         => $c(null, []),
        'delete_forever' => $c(null, []),
    ],
];

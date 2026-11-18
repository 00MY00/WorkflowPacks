<?php

namespace Modules\WorkflowPacks\Services\Engine;

use Modules\WorkflowPacks\Blocks\Conditions\Similar;
use Modules\WorkflowPacks\Services\Ai\TicketText;
use Modules\WorkflowPacks\Services\Ai\Vectorizer;

/**
 * "An e-mail that goes through": a sample e-mail built for the simulation of a draft, so that it takes the positive path
 * (the "yes" of each condition) from the trigger to the end of the flow.
 *  - "If…" on the subject, the text, the sender, the status, assigned, replied: the fields are filled to make it true;
 *  - "Looks like…": a text near the example e-mails (one of them, a few words dropped or swapped at random), kept only
 *    when its similarity reaches the threshold;
 * then the e-mail is played through the draft (dry run, nothing written) to say whether it reached the end.
 * What cannot be made true from an e-mail (a value given by another block, a complex expression) is said in "notes".
 */
class SampleMail
{
    const VARIANTS = 12;

    /** @var BlockRegistry */
    protected $registry;

    public function __construct(BlockRegistry $registry = null)
    {
        $this->registry = $registry ?: new BlockRegistry();
    }

    /** @return array ['mail' => [subject, from, body, status, assigned, replied], 'path' => [block ids], 'notes' => [texts], 'reached' => bool, 'end' => state] */
    public function build(array $draft)
    {
        $path = $this->path($draft);
        $mail = ['subject' => __('workflowpacks::flows.sample_subject'), 'from' => 'client@example.test',
            'body' => __('workflowpacks::flows.sample_body'), 'status' => 'active', 'assigned' => false, 'replied' => false];
        $notes = [];
        // the text of "Looks like" first (it gives the whole subject and text), then the words the "If…" need
        foreach ($path as $step) {
            if ($step['type'] === 'condition.similar') {
                $this->similar($mail, $step['params'], $step['port'] === 'yes', $notes);
            }
        }
        foreach ($path as $step) {
            if ($step['type'] === 'condition.ticket') {
                $this->ticket($mail, $step['params'], $step['port'] === 'yes', $notes);
            }
        }
        $trace = (new Engine($this->registry))->simulate(Definition::from($draft), $this->ticketOf($mail));
        $last = end($path);
        $played = array_column($trace['events'], 'block');
        $reached = $last && in_array($last['id'], $played, true) && $trace['end'] !== \Modules\WorkflowPacks\Entities\FlowRun::FAILED;

        return ['mail' => $mail, 'path' => array_column($path, 'id'), 'notes' => array_values(array_unique($notes)), 'reached' => $reached,
            'end' => $trace['end']];
    }

    /** The fields of the simulation (the same as FlowsController::simulate). */
    public function ticketOf(array $mail)
    {
        return ['subject' => mb_substr((string) $mail['subject'], 0, 255), 'from' => mb_substr((string) $mail['from'], 0, 191),
            'body' => mb_substr((string) $mail['body'], 0, 20000), 'status' => $mail['status'], 'user_id' => !empty($mail['assigned']) ? 1 : 0,
            'replied' => !empty($mail['replied']), 'folder' => ''];
    }

    /**
     * The positive path: from the trigger, the "yes" of each condition (else its "no" when only that one is linked), the
     * "next" of the other blocks; ends where nothing follows (or on a loop).
     *
     * @return array [['id', 'type', 'params', 'port']]
     */
    public function path(array $draft)
    {
        $blocks = [];
        foreach ((array) ($draft['blocks'] ?? []) as $b) {
            $blocks[(string) ($b['id'] ?? '')] = $b;
        }
        $next = [];
        foreach ((array) ($draft['links'] ?? []) as $l) {
            $next[(string) ($l['from'] ?? '')][(string) ($l['port'] ?? 'next')] = (string) ($l['to'] ?? '');
        }
        $id = null;
        foreach ($blocks as $key => $b) {
            if (strpos((string) ($b['type'] ?? ''), 'trigger.') === 0) {
                $id = $key;
                break;
            }
        }
        $path = [];
        $seen = [];
        while ($id !== null && isset($blocks[$id]) && !isset($seen[$id])) {
            $seen[$id] = true;
            $b = $blocks[$id];
            $outs = $next[$id] ?? [];
            $port = isset($outs['yes']) ? 'yes' : (isset($outs['next']) ? 'next' : (isset($outs['no']) ? 'no' : null));
            $path[] = ['id' => $id, 'type' => (string) ($b['type'] ?? ''), 'params' => (array) ($b['params'] ?? []), 'port' => $port ?: 'next'];
            $id = $port ? $outs[$port] : null;
        }

        return $path;
    }

    /** "If…": the field of the ticket set so that the test answers $want. */
    protected function ticket(array &$mail, array $p, $want, array &$notes)
    {
        $var = (string) ($p['var'] ?? '');
        $op = (string) ($p['operator'] ?? '');
        $value = (string) ($p['value'] ?? '');
        $fields = ['ticket.subject' => 'subject', 'ticket.body' => 'body', 'ticket.from' => 'from'];
        if (isset($fields[$var])) {
            $f = $fields[$var];
            $mail[$f] = $this->text((string) $mail[$f], $op, $value, $want, $f === 'from', $notes);

            return;
        }
        if ($var === 'ticket.status') {
            $statuses = ['active', 'pending', 'closed', 'spam'];
            $is = ($op === 'is') === $want;
            $mail['status'] = $is ? (in_array($value, $statuses, true) ? $value : 'active') : current(array_diff($statuses, [$value]));

            return;
        }
        if ($var === 'ticket.assigned' || $var === 'ticket.replied') {
            $mail[substr($var, 7)] = ($op === 'yes') === $want;

            return;
        }
        // a value given by another block (a scan, a score): not something an e-mail sets
        $notes[] = __('workflowpacks::flows.sample_note_var', ['var' => $var]);
    }

    /** A text made to answer $want to "contains / equals / starts / ends / matches" $value. */
    protected function text($current, $op, $value, $want, $isAddress, array &$notes)
    {
        $has = function ($text) use ($value) {
            return $value === '' || mb_stripos($text, $value) !== false;
        };
        $without = function ($text) use ($value) {
            return trim(preg_replace('/'.preg_quote($value, '/').'/iu', '', $text)) ?: 'x';
        };
        $positive = in_array($op, ['contains', 'equal', 'starts', 'ends', 'regex'], true) ? $want : !$want;
        $base = in_array($op, ['not_contains', 'not_equal'], true) ? ['not_contains' => 'contains', 'not_equal' => 'equal'][$op] : $op;
        if (!$positive) {
            return $base === 'equal' ? ($current === $value ? $current.' x' : $current) : ($has($current) ? $without($current) : $current);
        }
        switch ($base) {
            case 'equal':
                return $value;
            case 'starts':
                return $value.($isAddress ? '' : ' ').$current;
            case 'ends':
                return $current.($isAddress ? '' : ' ').$value;
            case 'regex':
                $pattern = '/'.str_replace('/', '\\/', $value).'/iu';
                if (@preg_match($pattern, $current)) {
                    return $current;
                }
                // a simple expression: its letters and digits, as words
                $literal = trim(preg_replace('/[^\p{L}\p{N}@._ -]+/u', ' ', $value));
                $candidate = trim($current.' '.$literal);
                if (@preg_match($pattern, $candidate)) {
                    return $candidate;
                }
                $notes[] = __('workflowpacks::flows.sample_note_regex', ['value' => $value]);

                return $candidate;
            default:
                if ($has($current)) {
                    return $current;
                }
                if ($isAddress) {
                    return strpos($value, '@') !== false ? $value : 'client@'.ltrim($value, '@.');
                }

                return trim($current.' '.$value);
        }
    }

    /**
     * "Looks like…": a text near the examples (one example, a few words dropped or swapped), checked against the
     * threshold with the same profile and weights as the block; for "no", a text unlike them.
     */
    protected function similar(array &$mail, array $p, $want, array &$notes)
    {
        $ids = Similar::ids($p['examples'] ?? []);
        if (!$want) {
            $mail['subject'] = __('workflowpacks::flows.sample_unlike_subject');
            $mail['body'] = __('workflowpacks::flows.sample_unlike_body');

            return;
        }
        $examples = $ids ? \App\Conversation::whereIn('id', $ids)->get() : collect();
        if (!$examples->count()) {
            $notes[] = __('workflowpacks::flows.sample_note_examples');

            return;
        }
        $profile = Similar::profile($ids);
        $threshold = (int) ($p['threshold'] ?? 75);
        $ws = (float) ($p['subject'] ?? 50);
        $wb = (float) ($p['body'] ?? 50);
        $score = function ($subject, $body) use ($profile, $ws, $wb) {
            $s = Vectorizer::similarity(Vectorizer::vector($subject), $profile['subject']);
            $b = Vectorizer::similarity(Vectorizer::vector($body), $profile['body']);

            return (int) round(100 * ($ws * $s + $wb * $b) / max(1, $ws + $wb));
        };
        $best = null;
        for ($i = 0; $i < self::VARIANTS; $i++) {
            $text = TicketText::of($examples->random(), false);
            // the first tries change more, the last ones stay close to the example
            $rate = max(0, 0.2 - $i * 0.02);
            $candidate = ['subject' => self::vary($text['subject'], $rate), 'body' => self::vary($text['body'], $rate)];
            $candidate['score'] = $score($candidate['subject'], $candidate['body']);
            if (!$best || $candidate['score'] > $best['score']) {
                $best = $candidate;
            }
            if ($candidate['score'] >= $threshold) {
                $best = $candidate;
                break;
            }
        }
        $mail['subject'] = $best['subject'] ?: $mail['subject'];
        $mail['body'] = $best['body'] ?: $mail['body'];
        if ($best['score'] < $threshold) {
            $notes[] = __('workflowpacks::flows.sample_note_similar', ['score' => $best['score'], 'threshold' => $threshold]);
        }
    }

    /** A few words dropped and two neighbours swapped at random (rate: share of words touched). */
    public static function vary($text, $rate)
    {
        $words = preg_split('/\s+/u', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 4 || $rate <= 0) {
            return implode(' ', $words);
        }
        $out = [];
        foreach ($words as $w) {
            if (mt_rand() / mt_getrandmax() >= $rate) {
                $out[] = $w;
            }
        }
        if (count($out) > 2) {
            $i = mt_rand(0, count($out) - 2);
            list($out[$i], $out[$i + 1]) = [$out[$i + 1], $out[$i]];
        }

        return implode(' ', $out ?: $words);
    }
}

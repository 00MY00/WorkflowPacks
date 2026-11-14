<?php

namespace Modules\WorkflowPacks\Blocks\Conditions;

use Modules\WorkflowPacks\Blocks\AbstractBlock;
use Modules\WorkflowPacks\Services\Ai\TicketText;
use Modules\WorkflowPacks\Services\Ai\Vectorizer;
use Modules\WorkflowPacks\Services\Engine\Context;
use Modules\WorkflowPacks\Services\Engine\Result;

/**
 * "Looks like": the ticket compared with example tickets (their ids), part by part (subject, text), locally
 * (Services\Ai\Vectorizer). Yes when the weighted similarity reaches the threshold. The profile of the examples is
 * computed once and kept a day in the cache; a ticket costs a few milliseconds.
 */
class Similar extends AbstractBlock
{
    const MAX_EXAMPLES = 50;
    const CACHE_MINUTES = 1440;

    public static function type()
    {
        return 'condition.similar';
    }

    public static function family()
    {
        return 'condition';
    }

    public static function fields()
    {
        return [
            'examples'  => ['type' => 'tickets', 'required' => true],
            'threshold' => ['type' => 'percent', 'default' => 75, 'required' => true],
            'subject'   => ['type' => 'percent', 'default' => 50],
            'body'      => ['type' => 'percent', 'default' => 50],
        ];
    }

    public static function outputs()
    {
        return ['yes', 'no'];
    }

    public static function variables(array $params)
    {
        return ['similar.score' => ['type' => 'number']];
    }

    protected static function titleParams(array $params)
    {
        return ['count' => count(self::ids($params['examples'] ?? [])), 'threshold' => (int) ($params['threshold'] ?? 75)];
    }

    public static function validate(array $params)
    {
        $problems = [];
        $ids = self::ids($params['examples'] ?? []);
        if (!$ids || count($ids) > self::MAX_EXAMPLES) {
            $problems['examples'] = __('workflowpacks::blocks.errors.examples', ['max' => self::MAX_EXAMPLES]);
        }
        foreach (['threshold', 'subject', 'body'] as $key) {
            $v = $params[$key] ?? ($key === 'threshold' ? null : 50);
            if (!is_numeric($v) || $v < 0 || $v > 100 || ($key === 'threshold' && $v < 1)) {
                $problems[$key] = __('workflowpacks::blocks.errors.percent');
            }
        }
        if (!$problems && (float) ($params['subject'] ?? 50) + (float) ($params['body'] ?? 50) <= 0) {
            $problems['subject'] = __('workflowpacks::blocks.errors.percent');
        }

        return $problems;
    }

    public function run(Context $ctx, array $params)
    {
        $profile = self::profile(self::ids($params['examples'] ?? []));
        $ws = (float) ($params['subject'] ?? 50);
        $wb = (float) ($params['body'] ?? 50);
        $s = Vectorizer::similarity(Vectorizer::vector($ctx->get('ticket.subject')), $profile['subject']);
        $b = Vectorizer::similarity(Vectorizer::vector($ctx->get('ticket.body')), $profile['body']);
        $score = (int) round(100 * ($ws * $s + $wb * $b) / max(1, $ws + $wb));
        $threshold = (int) ($params['threshold'] ?? 75);
        $ctx->set('similar.score', $score);
        $yes = $score >= $threshold;

        return Result::next($yes ? 'yes' : 'no', __('workflowpacks::blocks.condition.similar.decision', [
            'score' => $score, 'subject' => (int) round(100 * $s), 'body' => (int) round(100 * $b), 'threshold' => $threshold,
            'output' => __('workflowpacks::blocks.outputs.'.($yes ? 'yes' : 'no')),
        ]));
    }

    /** "12, 45 78" or [12, 45] -> [12, 45] (positive whole numbers, no duplicate). */
    public static function ids($value)
    {
        $list = is_array($value) ? $value : preg_split('/[^0-9]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter(array_map('intval', $list), function ($id) {
            return $id > 0;
        })));
    }

    /** Centre of the examples, part by part (raw text: the vectors never leave the server). */
    public static function profile(array $ids)
    {
        sort($ids);
        $compute = function () use ($ids) {
            $subjects = [];
            $bodies = [];
            foreach (\App\Conversation::whereIn('id', array_slice($ids, 0, self::MAX_EXAMPLES))->get() as $conversation) {
                $text = TicketText::of($conversation, false);
                $subjects[] = Vectorizer::vector($text['subject']);
                $bodies[] = Vectorizer::vector($text['body']);
            }

            return ['subject' => Vectorizer::centroid($subjects), 'body' => Vectorizer::centroid($bodies)];
        };

        return \Cache::remember('workflowpacks.similar.'.Vectorizer::VERSION.'.'.md5(implode(',', $ids)), self::CACHE_MINUTES, $compute);
    }
}

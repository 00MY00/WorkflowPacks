<?php

namespace Modules\WorkflowPacks\Blocks;

use Modules\WorkflowPacks\Blocks\Contracts\Block;

/** Usual defaults of a block: one output, no variable, required fields checked, title from its texts. */
abstract class AbstractBlock implements Block
{
    public static function outputs()
    {
        return ['next'];
    }

    public static function variables(array $params)
    {
        return [];
    }

    public static function title(array $params)
    {
        return self::text(static::type(), 'title', static::titleParams($params));
    }

    /** Values put in the title ("Status: :status"). */
    protected static function titleParams(array $params)
    {
        return [];
    }

    public static function validate(array $params)
    {
        $problems = [];
        foreach (static::fields() as $key => $field) {
            $value = $params[$key] ?? null;
            if (!empty($field['required']) && ($value === null || $value === '' || $value === [])) {
                $problems[$key] = __('workflowpacks::blocks.errors.required');
            } elseif ($value !== null && isset($field['values']) && ($field['type'] ?? '') === 'choice'
                && !in_array((string) $value, array_map('strval', $field['values']), true)) {
                $problems[$key] = __('workflowpacks::blocks.errors.choice');
            }
        }

        return $problems;
    }

    /** Label of a chosen value (blocks.<group>.<value>); not chosen yet or unknown: "…", never the name of a text. */
    protected static function choice($group, $value)
    {
        $value = (string) $value;
        if ($value === '') {
            return '…';
        }
        $key = 'workflowpacks::blocks.'.$group.'.'.$value;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : '…';
    }

    /** Text of a block: blocks.<family>.<name>.<what>, "trigger.new_mail" -> blocks.trigger.new_mail.title. */
    public static function text($type, $what, array $params = [])
    {
        return __('workflowpacks::blocks.'.$type.'.'.$what, $params);
    }

    /** A value shown in a decision or a change, kept short (never a whole e-mail). */
    public static function short($value, $max = 60)
    {
        $value = is_scalar($value) ? trim(preg_replace('/\s+/u', ' ', (string) $value)) : json_encode($value);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
    }
}

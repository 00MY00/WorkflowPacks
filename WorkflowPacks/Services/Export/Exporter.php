<?php

namespace Modules\WorkflowPacks\Services\Export;

use Modules\WorkflowPacks\Entities\Link;
use Modules\WorkflowPacks\Entities\Pack;
use Modules\WorkflowPacks\Services\Pack\ConditionTranslator;
use Modules\WorkflowPacks\Services\Pack\Fingerprint;
use Modules\WorkflowPacks\Services\Pack\PackValidator;
use Modules\WorkflowPacks\Services\Pack\Problems;
use Modules\WorkflowPacks\Services\Workflows\Bridge;

/**
 * Export of workflows to a pack file.
 *
 *  - rules created by a pack: the original template of the pack is exported (variables kept as written), or the
 *    current version when the rule was changed since the import (the user's changes are never lost);
 *  - other rules: their stored values, with ONLY the replacements chosen by the user (exact text -> variable).
 *    Nothing is guessed: an address equal to the mailbox's one stays as is unless the user asks for a replacement.
 *  - references to records of this instance (users, mailboxes) and types brought by other modules are reported;
 *  - anything that looks like a secret (password, token, key, signed URL) is removed and replaced by a sensitive
 *    parameter that must be filled in at import.
 */
class Exporter
{
    const SECRET_KEYS = '/pass(word)?|secret|token|api[_-]?key|auth|signature|private/i';

    /** Types documented by Workflows itself (others come from extra modules). */
    const NATIVE_CONDITIONS = ['customer_name', 'customer_email', 'user_action', 'type', 'status', 'state', 'user', 'to', 'cc', 'subject',
        'body', 'headers', 'attachment', 'bounce', 'customer_viewed', 'new_or_reply', 'channel', 'waiting', 'user_reply', 'customer_reply', 'created'];
    const NATIVE_ACTIONS = ['notification', 'reply', 'email_customer', 'no_autoreply', 'forward', 'note', 'status', 'assign', 'move', 'delete', 'delete_forever'];

    /** Values removed as secrets (parameter key => value), for the grouping only (Grouper): never written to a file. */
    protected $secretValues = [];

    /**
     * @param \Illuminate\Support\Collection $workflows Workflow models (already filtered by permission)
     * @param array $meta         key, name, description, pack_version, requires (module aliases)
     * @param array $replacements list of ['text' => exact text, 'variable' => 'mailbox.email'|'mailbox.name'|'mailbox.id'|'param.<key>',
     *                            'type' => param type when a new parameter is declared, 'label' => …]
     * @return array ['pack' => array, 'report' => ['refs' => [], 'modules' => [], 'secrets' => [], 'replaced' => [], 'from_packs' => []]]
     */
    /**
     * @return array ['pack' => array, 'report' => array, 'rule_of' => [workflow id => index in pack.workflows],
     *                'param_values' => [key => value known for the variables kept], 'secret_values' => [key => value]]
     */
    public function exportWorkflows($workflows, array $meta, array $replacements)
    {
        $this->secretValues = [];
        $ruleOf = [];
        $paramValues = [];
        $jsonActions = (array) Bridge::config('json_value_actions');
        $report = ['refs' => [], 'modules' => [], 'secrets' => [], 'replaced' => [], 'from_packs' => []];
        $params = [];
        $resources = [];
        foreach ($replacements as $r) {
            $report['replaced'][$r['text']] = 0;
            if (strpos($r['variable'], 'param.') === 0) {
                $key = substr($r['variable'], 6);
                $params[$key] = ['key' => $key, 'label' => $r['label'] ?? $key, 'type' => $r['type'] ?? 'string', 'required' => true];
            } elseif (strpos($r['variable'], 'resource.') === 0) {
                // a local identifier (user, mailbox, tag) becomes a logical resource, mapped again at each import
                $key = substr($r['variable'], 9);
                $resources[$key] = ['key' => $key, 'type' => $r['type'], 'name' => $r['label'] ?? $key, 'on_missing' => 'ask', 'use' => $r['use'] ?? 'id'];
            }
        }
        $selectors = false;

        $rules = [];
        $keys = [];
        foreach ($workflows as $workflow) {
            $link = Link::where('workflow_id', $workflow->id)->where('mailbox_id', $workflow->mailbox_id)->first();
            $modified = $link && Fingerprint::of(Bridge::attributesOf($workflow), $jsonActions) !== $link->fingerprint;
            if ($link && $modified) {
                // created by a pack, then changed here: the current version, under the same key
                $rule = $this->ruleFromWorkflow($workflow, $jsonActions, $replacements, $report);
                $rule['key'] = $link->workflow_key;
                $report['from_packs'][] = $workflow->name.' ← '.$link->pack_key.'/'.$link->workflow_key;
                $report['from_packs'][] = '⚠ '.Problems::t('exp_modified', ['name' => $workflow->name]);
            } elseif ($link) {
                // created by a pack: original template with its variables (and the values given at its import)
                $rule = json_decode($link->template, true);
                $paramValues += (array) json_decode((string) $link->params, true);
                $pack = Pack::where('pack_key', $link->pack_key)->first();
                $source = $pack ? json_decode($pack->source, true) : null;
                foreach ((array) ($source['params'] ?? []) as $p) {
                    $params[$p['key']] = $params[$p['key']] ?? $p;
                }
                // portable intention kept: resources of the pack and its mailbox selector (never a frozen list)
                foreach ((array) ($source['resources'] ?? []) as $res) {
                    $resources[$res['key']] = $resources[$res['key']] ?? $res;
                }
                if (!isset($rule['mailboxes']) && !empty($source['mailboxes'])) {
                    $rule['mailboxes'] = $source['mailboxes'];
                }
                $report['from_packs'][] = $workflow->name.' ← '.$link->pack_key.'/'.$link->workflow_key;
            } else {
                $rule = $this->ruleFromWorkflow($workflow, $jsonActions, $replacements, $report);
            }
            $rule['key'] = $this->uniqueKey($rule['key'] ?? $this->slug($workflow->name), $keys);
            $selectors = $selectors || !empty($rule['mailboxes']);
            $ruleOf[(int) $workflow->id] = count($rules);
            $rules[] = $rule;
        }

        // secrets -> sensitive parameters
        foreach ($rules as $i => $rule) {
            $rules[$i] = $this->stripSecrets($rule, $rule['key'], $params, $report);
        }

        $pack = [
            'format'       => PackValidator::FORMAT,
            // v2 only when a v2 function is used (resources, selectors), so that older versions keep reading the file
            'version'      => ($resources || $selectors) ? 2 : 1,
            'key'          => $meta['key'],
            'name'         => $meta['name'],
            'description'  => $meta['description'] ?? '',
            'pack_version' => $meta['pack_version'] ?? '1.0.0',
            'requires'     => array_values(array_unique($meta['requires'] ?? [])),
            'params'       => array_values($params),
            'resources'    => array_values($resources),
            'workflows'    => $rules,
        ];
        foreach (['description', 'requires', 'params', 'resources'] as $k) {
            if ($pack[$k] === '' || $pack[$k] === []) {
                unset($pack[$k]);
            }
        }

        foreach ($replacements as $r) {
            if (strpos($r['variable'], 'param.') === 0) {
                $paramValues[substr($r['variable'], 6)] = (string) $r['text'];
            }
        }

        return ['pack' => $pack, 'report' => $report, 'rule_of' => $ruleOf, 'param_values' => $paramValues, 'secret_values' => $this->secretValues];
    }

    /** A pack exactly as it was imported (template with variables), optionally limited to some rules. */
    public function exportPack($packKey, array $ruleKeys = [])
    {
        $pack = Pack::where('pack_key', $packKey)->first();
        if (!$pack) {
            return null;
        }
        $data = json_decode($pack->source, true);
        if ($ruleKeys) {
            $data['workflows'] = array_values(array_filter($data['workflows'], function ($rule) use ($ruleKeys) {
                return in_array($rule['key'], $ruleKeys, true);
            }));
        }

        return $data;
    }

    protected function ruleFromWorkflow($workflow, array $jsonActions, array $replacements, array &$report)
    {
        // same reading of a stored rule as the editor of the diagram
        $rule = \Modules\WorkflowPacks\Services\Graph\RuleCodec::fromWorkflow($workflow);
        $name = $rule['name'];
        $conditions = $rule['conditions'] ?? null;
        $actions = $rule['actions'];

        // references to this instance + types brought by other modules
        $refs = (array) Bridge::config('local_refs');
        foreach ($this->flatConditions($conditions) as $c) {
            if (isset($refs['conditions'][$c['type']])) {
                $report['refs'][] = Problems::t('exp_ref_condition', ['name' => $name, 'type' => $c['type'], 'kind' => $refs['conditions'][$c['type']], 'value' => json_encode($c['value'] ?? null)]);
            }
            if (!in_array($c['type'], self::NATIVE_CONDITIONS, true)) {
                $report['modules'][] = Problems::t('exp_module_condition', ['name' => $name, 'type' => $c['type']]);
            }
        }
        foreach ($actions as $a) {
            if (isset($refs['actions'][$a['type']])) {
                $report['refs'][] = Problems::t('exp_ref_action', ['name' => $name, 'type' => $a['type'], 'kind' => $refs['actions'][$a['type']], 'value' => json_encode($a['value'] ?? null)]);
            }
            if (!in_array($a['type'], self::NATIVE_ACTIONS, true)) {
                $report['modules'][] = Problems::t('exp_module_action', ['name' => $name, 'type' => $a['type']]);
            }
        }

        return $this->replace($rule, $replacements, $report, false);
    }

    /** Applies the user's replacements (exact text) to every string; in a regex the quoted form is replaced too. */
    protected function replace($value, array $replacements, array &$report, $regex, $field = '')
    {
        if (is_array($value)) {
            $isRegex = isset($value['operator']) && $value['operator'] === 'regex';
            foreach ($value as $k => $v) {
                if ($k === 'key' || $k === 'type' || $k === 'operator' || $k === 'mailboxes' || strpos((string) $k, 'x-') === 0) {
                    continue;
                }
                $value[$k] = $this->replace($v, $replacements, $report, $regex || ($isRegex && $k === 'value'), $k);
            }

            return $value;
        }
        if (is_int($value) || is_float($value)) {
            // an identifier stored as a number (assign, move…): replaced only when it is exactly the text given
            foreach ($replacements as $r) {
                if ((string) $r['text'] !== '' && (string) $value === (string) $r['text']) {
                    $report['replaced'][$r['text']]++;

                    return '{{'.$r['variable'].'}}';
                }
            }

            return $value;
        }
        if (!is_string($value) || $value === '') {
            return $value;
        }
        foreach ($replacements as $r) {
            if ($value === (string) $r['text'] && strpos($r['variable'], 'resource.') === 0) {
                $report['replaced'][$r['text']]++;

                return '{{'.$r['variable'].'}}';
            }
            $text = (string) $r['text'];
            if ($text === '') {
                continue;
            }
            $placeholder = '{{'.$r['variable'].'}}';
            $forms = [$text];
            if ($regex && $value !== '') {
                $forms[] = preg_quote($text, $value[0]);
            }
            foreach (array_unique($forms) as $form) {
                $n = substr_count($value, $form);
                if ($n) {
                    $value = str_replace($form, $placeholder, $value);
                    $report['replaced'][$text] += $n;
                }
            }
        }

        return $value;
    }

    protected function stripSecrets($value, $path, array &$params, array &$report, $key = '')
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (in_array($k, ['key', 'type', 'operator', 'mailboxes'], true) || strpos((string) $k, 'x-') === 0) {
                    continue;
                }
                $value[$k] = $this->stripSecrets($v, $path.'.'.$k, $params, $report, (string) $k);
            }

            return $value;
        }
        if (!is_string($value) || $value === '' || strpos($value, '{{') !== false) {
            return $value;
        }
        if ($this->looksSecret($key, $value)) {
            $param = 'secret_'.(count(array_filter(array_keys($params), function ($k) {
                return strpos($k, 'secret_') === 0;
            })) + 1);
            $params[$param] = ['key' => $param, 'label' => Problems::t('exp_secret_label', ['path' => $path]), 'type' => 'string', 'required' => true, 'sensitive' => true];
            $report['secrets'][] = Problems::t('exp_secret', ['path' => $path, 'param' => $param]);
            $this->secretValues[$param] = $value;

            return '{{param.'.$param.'}}';
        }

        return $value;
    }

    protected function looksSecret($key, $value)
    {
        if ($key !== '' && !is_numeric($key) && preg_match(self::SECRET_KEYS, $key)) {
            return true;
        }
        if (preg_match('/\b(bearer|basic)\s+[A-Za-z0-9._~+\/=-]{16,}/i', $value)) {
            return true;
        }
        if (preg_match('/[?&](token|key|api_key|apikey|secret|signature|sig|password|access_token)=[^&\s]+/i', $value)) {
            return true;
        }
        if (preg_match('#https?://[^/\s:@]+:[^/\s@]+@#i', $value)) {
            return true;
        }
        // long random-looking strings (letters AND digits, no space)
        foreach (preg_split('/[\s"\'<>(),;]+/', $value) as $word) {
            if (strlen($word) >= 32 && preg_match('/^[A-Za-z0-9_\-+\/=.]+$/', $word) && preg_match('/\d/', $word) && preg_match('/[A-Za-z]/', $word)
                && !preg_match('/^[a-z.-]+$/i', $word) && !filter_var($word, FILTER_VALIDATE_EMAIL)) {
                return true;
            }
        }

        return false;
    }

    protected function flatConditions($tree)
    {
        $out = [];
        foreach ((array) ($tree['all'] ?? $tree['any'] ?? []) as $item) {
            if (isset($item['any'])) {
                foreach ($item['any'] as $c) {
                    $out[] = $c;
                }
            } else {
                $out[] = $item;
            }
        }

        return $out;
    }

    public function slug($text)
    {
        $text = \Illuminate\Support\Str::slug((string) $text);

        return $text !== '' ? substr($text, 0, 90) : 'regle';
    }

    protected function uniqueKey($key, array &$keys)
    {
        $base = $key;
        $i = 2;
        while (isset($keys[$key])) {
            $key = substr($base, 0, 90).'-'.$i++;
        }
        $keys[$key] = true;

        return $key;
    }
}

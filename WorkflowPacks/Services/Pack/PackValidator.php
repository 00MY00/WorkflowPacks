<?php

namespace Modules\WorkflowPacks\Services\Pack;

/**
 * Structure of a pack file (format "freescout-workflow-pack", schema version 1), before any variable is replaced.
 * See schema/freescout-workflow-pack.schema.json. The types, operators and values of the conditions and actions
 * are checked later against the catalog of the installed Workflows module (RuleCompiler).
 *
 * Unknown keys are refused (except "$schema" and "x-…" at the top level): a misspelt key must not be ignored silently.
 */
class PackValidator
{
    const FORMAT = 'freescout-workflow-pack';
    const SCHEMA_VERSIONS = [1];
    const PARAM_TYPES = ['string', 'email', 'integer', 'user', 'mailbox'];

    const TOP_KEYS = ['format', 'version', 'key', 'name', 'description', 'pack_version', 'requires', 'params', 'workflows'];
    const PARAM_KEYS = ['key', 'label', 'description', 'type', 'required', 'default', 'sensitive'];
    const RULE_KEYS = ['key', 'name', 'description', 'type', 'settings', 'requires', 'conditions', 'actions'];
    const SETTINGS_KEYS = ['apply_to_prev', 'max_executions'];
    const ACTION_KEYS = ['type', 'operator', 'value'];

    /** @var array */
    protected $limits;

    public function __construct(array $limits = [])
    {
        $this->limits = $limits + [
            'file_kb' => 512, 'json_depth' => 32, 'rules' => 200, 'groups' => 20, 'conditions' => 50,
            'actions' => 50, 'string' => 20000, 'regex' => 2000, 'params' => 50,
        ];
    }

    /**
     * @return array ['pack' => normalized pack|null, 'problems' => Problems]
     */
    public function validateJson($json)
    {
        $problems = new Problems();
        if (is_string($json) && strlen($json) > $this->limits['file_kb'] * 1024) {
            $problems->add('', 'WP-FILE-02', 'fichier trop volumineux ('.ceil(strlen($json) / 1024).' Ko, maximum '.$this->limits['file_kb'].' Ko).');

            return ['pack' => null, 'problems' => $problems];
        }
        if (!is_string($json) || trim($json) === '') {
            $problems->add('', 'WP-FILE-01', 'fichier vide.');

            return ['pack' => null, 'problems' => $problems];
        }
        if (substr($json, 0, 3) === "\xEF\xBB\xBF") {
            $json = substr($json, 3);
        }
        $data = json_decode($json, true, (int) $this->limits['json_depth']);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $problems->add('', 'WP-FILE-03', 'JSON invalide : '.json_last_error_msg().'.');

            return ['pack' => null, 'problems' => $problems];
        }

        return $this->validate($data);
    }

    public function validate($data)
    {
        $problems = new Problems();
        if (!is_array($data) || ConditionTranslator::isList($data)) {
            $problems->add('', 'WP-PACK-01', 'le fichier doit contenir un objet JSON.');

            return ['pack' => null, 'problems' => $problems];
        }
        foreach (array_keys($data) as $k) {
            if (!in_array($k, self::TOP_KEYS, true) && $k !== '$schema' && strpos((string) $k, 'x-') !== 0) {
                $problems->add($k, 'WP-PACK-02', 'clé inconnue au premier niveau.');
            }
        }
        if (($data['format'] ?? null) !== self::FORMAT) {
            $problems->add('format', 'WP-PACK-03', 'doit valoir « '.self::FORMAT.' ».');

            return ['pack' => null, 'problems' => $problems];
        }
        if (!isset($data['version']) || !is_int($data['version'])) {
            $problems->add('version', 'WP-PACK-04', 'version du schéma manquante (nombre entier).');

            return ['pack' => null, 'problems' => $problems];
        }
        if (!in_array($data['version'], self::SCHEMA_VERSIONS, true)) {
            $problems->add('version', 'WP-PACK-05', 'version de schéma '.$data['version'].' non prise en charge par ce module (versions acceptées : '
                .implode(', ', self::SCHEMA_VERSIONS).'). Mettez à jour Workflow Packs.');

            return ['pack' => null, 'problems' => $problems];
        }

        $pack = [
            'format'       => self::FORMAT,
            'version'      => $data['version'],
            'key'          => $this->key($data, 'key', '', $problems),
            'name'         => $this->text($data, 'name', 'name', $problems, true, 191),
            'description'  => $this->text($data, 'description', 'description', $problems, false, 5000),
            'pack_version' => $this->text($data, 'pack_version', 'pack_version', $problems, false, 50),
            'requires'     => $this->requires($data['requires'] ?? [], 'requires', $problems),
            'params'       => $this->params($data['params'] ?? [], $problems),
            'workflows'    => [],
        ];
        $paramKeys = array_column($pack['params'], 'key');

        $rules = $data['workflows'] ?? null;
        if (!is_array($rules) || !ConditionTranslator::isList($rules) || !$rules) {
            $problems->add('workflows', 'WP-PACK-06', 'liste de règles manquante ou vide.');
        } elseif (count($rules) > $this->limits['rules']) {
            $problems->add('workflows', 'WP-PACK-07', 'trop de règles ('.count($rules).', maximum '.$this->limits['rules'].').');
        } else {
            $keys = [];
            foreach ($rules as $i => $rule) {
                $normalized = $this->rule($rule, 'workflows['.$i.']', $paramKeys, $problems);
                if ($normalized === null) {
                    continue;
                }
                if (isset($keys[$normalized['key']])) {
                    $problems->add('workflows['.$i.'].key', 'WP-RULE-02', 'clé « '.$normalized['key'].' » déjà utilisée par une autre règle du pack.');
                }
                $keys[$normalized['key']] = true;
                $pack['workflows'][] = $normalized;
            }
        }

        $this->checkStrings($data, '', $problems);

        return ['pack' => $problems->isEmpty() ? $pack : null, 'problems' => $problems];
    }

    protected function key(array $data, $field, $path, Problems $problems)
    {
        $value = $data[$field] ?? null;
        if (!is_string($value) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $value)) {
            $problems->add(ltrim($path.'.'.$field, '.'), 'WP-PACK-08', 'identifiant invalide : lettres minuscules, chiffres, « . », « _ » ou « - » (100 caractères au plus).');

            return '';
        }

        return $value;
    }

    protected function text(array $data, $field, $path, Problems $problems, $required, $max)
    {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            if ($required) {
                $problems->add($path, 'WP-PACK-09', 'texte obligatoire.');
            }

            return '';
        }
        if (!is_string($data[$field]) || ($required && trim($data[$field]) === '')) {
            $problems->add($path, 'WP-PACK-09', 'doit être un texte'.($required ? ' non vide' : '').'.');

            return '';
        }
        if (mb_strlen($data[$field]) > $max) {
            $problems->add($path, 'WP-PACK-10', 'texte trop long (maximum '.$max.' caractères).');
        }

        return $data[$field];
    }

    protected function requires($value, $path, Problems $problems)
    {
        if (!is_array($value) || !ConditionTranslator::isList($value)) {
            $problems->add($path, 'WP-PACK-11', 'doit être une liste d\'alias de modules (ex. ["tags"]).');

            return [];
        }
        $out = [];
        foreach ($value as $i => $alias) {
            if (!is_string($alias) || !preg_match('/^[a-z0-9_-]{1,50}$/', strtolower($alias))) {
                $problems->add($path.'['.$i.']', 'WP-PACK-11', 'alias de module invalide.');
                continue;
            }
            $out[] = strtolower($alias);
        }

        return array_values(array_unique($out));
    }

    protected function params($value, Problems $problems)
    {
        if (!is_array($value) || !ConditionTranslator::isList($value)) {
            $problems->add('params', 'WP-PARAM-01', 'doit être une liste.');

            return [];
        }
        if (count($value) > $this->limits['params']) {
            $problems->add('params', 'WP-PARAM-01', 'trop de paramètres (maximum '.$this->limits['params'].').');

            return [];
        }
        $out = [];
        $seen = [];
        foreach ($value as $i => $param) {
            $path = 'params['.$i.']';
            if (!is_array($param) || ConditionTranslator::isList($param)) {
                $problems->add($path, 'WP-PARAM-02', 'un paramètre doit être un objet.');
                continue;
            }
            foreach (array_diff(array_keys($param), self::PARAM_KEYS) as $k) {
                $problems->add($path.'.'.$k, 'WP-PARAM-03', 'clé inconnue.');
            }
            $key = $param['key'] ?? null;
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,49}$/', $key)) {
                $problems->add($path.'.key', 'WP-PARAM-04', 'nom invalide : minuscules, chiffres et « _ », commence par une lettre.');
                continue;
            }
            if (isset($seen[$key])) {
                $problems->add($path.'.key', 'WP-PARAM-05', 'paramètre « '.$key.' » déclaré deux fois.');
                continue;
            }
            $seen[$key] = true;
            $type = $param['type'] ?? 'string';
            if (!in_array($type, self::PARAM_TYPES, true)) {
                $problems->add($path.'.type', 'WP-PARAM-06', 'type inconnu (types acceptés : '.implode(', ', self::PARAM_TYPES).').');
                continue;
            }
            foreach (['required', 'sensitive'] as $flag) {
                if (isset($param[$flag]) && !is_bool($param[$flag])) {
                    $problems->add($path.'.'.$flag, 'WP-PARAM-07', 'doit valoir true ou false.');
                }
            }
            $default = $param['default'] ?? null;
            if ($default !== null && self::paramError($type, $default) !== null) {
                $problems->add($path.'.default', 'WP-PARAM-08', 'valeur par défaut invalide : '.self::paramError($type, $default));
                $default = null;
            }
            if ($default !== null && in_array($type, ['user', 'mailbox'], true)) {
                $problems->add($path.'.default', 'WP-PARAM-09', 'un paramètre de type « '.$type.' » désigne un élément local à chaque instance : pas de valeur par défaut.');
                $default = null;
            }
            $out[] = [
                'key'         => $key,
                'label'       => is_string($param['label'] ?? null) && $param['label'] !== '' ? $param['label'] : $key,
                'description' => is_string($param['description'] ?? null) ? $param['description'] : '',
                'type'        => $type,
                'required'    => (bool) ($param['required'] ?? false),
                'default'     => $default,
                'sensitive'   => (bool) ($param['sensitive'] ?? false),
            ];
        }

        return $out;
    }

    /** Message when a value does not match the type of a parameter, or null. */
    public static function paramError($type, $value)
    {
        switch ($type) {
            case 'email':
                return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'adresse e-mail invalide.';
            case 'integer':
            case 'user':
            case 'mailbox':
                return (is_int($value) || (is_string($value) && preg_match('/^-?\d{1,10}$/', $value))) ? null : 'nombre entier attendu.';
            default:
                return is_string($value) || is_int($value) ? null : 'texte attendu.';
        }
    }

    protected function rule($rule, $path, array $paramKeys, Problems $problems)
    {
        if (!is_array($rule) || ConditionTranslator::isList($rule)) {
            $problems->add($path, 'WP-RULE-01', 'une règle doit être un objet.');

            return null;
        }
        $before = $problems->count();
        foreach (array_diff(array_keys($rule), self::RULE_KEYS) as $k) {
            $problems->add($path.'.'.$k, 'WP-RULE-03', 'clé inconnue.');
        }
        $key = $this->key($rule, 'key', $path, $problems);
        $type = $rule['type'] ?? 'automatic';
        if (!in_array($type, ['automatic', 'manual'], true)) {
            $problems->add($path.'.type', 'WP-RULE-04', 'doit valoir « automatic » ou « manual ».');
        }

        $settings = $rule['settings'] ?? [];
        $normalizedSettings = ['apply_to_prev' => false, 'max_executions' => 1];
        if (!is_array($settings) || ($settings && ConditionTranslator::isList($settings))) {
            $problems->add($path.'.settings', 'WP-RULE-05', 'doit être un objet.');
        } else {
            foreach (array_diff(array_keys($settings), self::SETTINGS_KEYS) as $k) {
                $problems->add($path.'.settings.'.$k, 'WP-RULE-05', 'paramètre d\'exécution inconnu (acceptés : '.implode(', ', self::SETTINGS_KEYS).').');
            }
            if (array_key_exists('apply_to_prev', $settings)) {
                if (!is_bool($settings['apply_to_prev'])) {
                    $problems->add($path.'.settings.apply_to_prev', 'WP-RULE-06', 'doit valoir true ou false.');
                } else {
                    $normalizedSettings['apply_to_prev'] = $settings['apply_to_prev'];
                }
            }
            if (array_key_exists('max_executions', $settings)) {
                $max = $settings['max_executions'];
                if (!is_int($max) || $max < 1 || $max > 1000) {
                    $problems->add($path.'.settings.max_executions', 'WP-RULE-07', 'nombre entier entre 1 et 1000 attendu.');
                } else {
                    $normalizedSettings['max_executions'] = $max;
                }
            }
        }

        $conditions = ConditionTranslator::toNative($rule['conditions'] ?? null, $path.'.conditions', $problems, $this->limits);
        if ($conditions === [] && $type === 'automatic') {
            $problems->add($path.'.conditions', 'WP-RULE-08', 'une règle automatique doit avoir au moins une condition (Workflow::validate()).');
        }

        $actions = $rule['actions'] ?? null;
        $normalizedActions = [];
        if (!is_array($actions) || !ConditionTranslator::isList($actions) || !$actions) {
            $problems->add($path.'.actions', 'WP-RULE-09', 'liste d\'actions manquante ou vide.');
        } elseif (count($actions) > $this->limits['actions']) {
            $problems->add($path.'.actions', 'WP-RULE-10', 'trop d\'actions (maximum '.$this->limits['actions'].').');
        } else {
            foreach ($actions as $i => $action) {
                $p = $path.'.actions['.$i.']';
                if (!is_array($action) || ConditionTranslator::isList($action) || !isset($action['type']) || !is_string($action['type']) || $action['type'] === '') {
                    $problems->add($p, 'WP-RULE-11', 'une action doit être un objet avec un « type ».');
                    continue;
                }
                foreach (array_diff(array_keys($action), self::ACTION_KEYS) as $k) {
                    $problems->add($p.'.'.$k, 'WP-RULE-12', 'clé inconnue.');
                }
                $normalizedActions[] = array_intersect_key($action, array_flip(self::ACTION_KEYS));
            }
        }

        // variables: syntax and names
        $vars = array_merge(
            VariableResolver::namesIn($rule['name'] ?? '', $path.'.name', $problems),
            VariableResolver::namesIn($rule['conditions'] ?? [], $path.'.conditions', $problems),
            VariableResolver::namesIn($rule['actions'] ?? [], $path.'.actions', $problems)
        );
        foreach (array_unique($vars) as $name) {
            if (!VariableResolver::knownName($name, $paramKeys)) {
                $problems->add($path, 'WP-VAR-02', 'variable inconnue {{'.$name.'}} : déclarez-la dans « params » ou utilisez {{mailbox.id}}, {{mailbox.email}}, {{mailbox.name}}.');
            }
        }
        // regular expressions without variables can be checked now (with variables: after replacement)
        foreach ((array) $conditions as $g => $group) {
            foreach ($group as $c => $condition) {
                if ($condition['operator'] === 'regex' && is_string($condition['value'] ?? null) && strpos($condition['value'], '{{') === false) {
                    $error = self::regexError($condition['value'], $this->limits['regex']);
                    if ($error !== null) {
                        $problems->add($path.'.conditions', 'WP-REGEX-01', 'expression régulière « '.$condition['value'].' » : '.$error);
                    }
                }
            }
        }

        $name = $this->text($rule, 'name', $path.'.name', $problems, true, 500);
        $description = $this->text($rule, 'description', $path.'.description', $problems, false, 5000);
        $requires = $this->requires($rule['requires'] ?? [], $path.'.requires', $problems);
        if ($problems->count() > $before) {
            return null;
        }

        return [
            'key'         => $key,
            'name'        => $name,
            'description' => $description,
            'type'        => $type,
            'settings'    => $normalizedSettings,
            'requires'    => $requires,
            'conditions'  => $rule['conditions'] ?? null,
            'actions'     => $normalizedActions,
        ];
    }

    /** Why a PCRE pattern cannot be used by Workflows (preg_match($pattern, $text)), or null. */
    public static function regexError($pattern, $max = 2000)
    {
        if (!is_string($pattern) || $pattern === '') {
            return 'motif vide.';
        }
        if (strlen($pattern) > $max) {
            return 'motif trop long (maximum '.$max.' caractères).';
        }
        $d = $pattern[0];
        if (ctype_alnum($d) || $d === '\\' || ctype_space($d)) {
            return 'le motif doit commencer et finir par un délimiteur, par exemple /motif/i (Workflows appelle preg_match() sans en ajouter).';
        }
        $close = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];
        $end = $close[$d] ?? $d;
        if (!preg_match('/'.preg_quote($end, '/').'[imsxuADSUXJ]*$/', substr($pattern, 1))) {
            return 'délimiteur de fin « '.$end.' » absent ou modificateur inconnu.';
        }
        set_error_handler(function () {
            return true;
        });
        $ok = @preg_match($pattern, '');
        restore_error_handler();
        if ($ok === false) {
            return 'motif invalide ('.(function_exists('preg_last_error_msg') ? preg_last_error_msg() : 'erreur PCRE').').';
        }

        return null;
    }

    protected function checkStrings($value, $path, Problems $problems)
    {
        if (is_string($value) && strlen($value) > $this->limits['string']) {
            $problems->add($path, 'WP-PACK-10', 'texte trop long (maximum '.$this->limits['string'].' octets).');
        } elseif (is_array($value)) {
            foreach ($value as $k => $v) {
                $this->checkStrings($v, ltrim($path.'.'.$k, '.'), $problems);
            }
        }
    }
}

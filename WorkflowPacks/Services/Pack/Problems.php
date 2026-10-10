<?php

namespace Modules\WorkflowPacks\Services\Pack;

/**
 * List of problems found in a pack, each with the place in the file ("workflows[2].conditions.all[0]"), a code and a
 * translated explanation. Used by the validator, the variable resolver and the compiler (no Laravel dependency).
 *
 * Texts: Resources/lang/<locale>/texts.php. Inside FreeScout the service provider plugs Laravel's translator
 * (user's language, fallback English); without Laravel the French reference catalogue is used.
 */
class Problems
{
    /** @var callable|null function (string $key, array $params): ?string */
    public static $translator = null;

    /** @var array|null */
    protected static $reference = null;

    /** @var array[] */
    protected $items = [];

    /** Translated text of a key, with :placeholders replaced. */
    public static function t($key, array $params = [])
    {
        if (self::$translator) {
            $text = call_user_func(self::$translator, $key, $params);
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }
        if (self::$reference === null) {
            self::$reference = require __DIR__.'/../../Resources/lang/fr/texts.php';
        }

        return self::replace(self::$reference[$key] ?? $key, $params);
    }

    /** Same replacement as Laravel's translator (longest placeholders first). */
    public static function replace($text, array $params)
    {
        uksort($params, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[':'.$k] = is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
        }

        return strtr((string) $text, $pairs);
    }

    public function add($path, $code, $key, array $params = [])
    {
        $this->items[] = ['path' => (string) $path, 'code' => (string) $code, 'message' => self::t($key, $params)];

        return $this;
    }

    public function merge(Problems $other)
    {
        foreach ($other->all() as $item) {
            $this->items[] = $item;
        }

        return $this;
    }

    public function all()
    {
        return $this->items;
    }

    public function count()
    {
        return count($this->items);
    }

    public function isEmpty()
    {
        return !$this->items;
    }

    /** Plain lines, e.g. for the console. */
    public function lines()
    {
        return array_map(function ($p) {
            return '['.$p['code'].'] '.($p['path'] !== '' ? $p['path'].' : ' : '').$p['message'];
        }, $this->items);
    }
}

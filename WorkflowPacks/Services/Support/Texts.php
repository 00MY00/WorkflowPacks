<?php

namespace Modules\WorkflowPacks\Services\Support;

/**
 * Texts of Resources/lang/<locale>/texts.php (checks, folders, delays), usable without Laravel (unit tests): inside
 * FreeScout the service provider plugs Laravel's translator (user's language, fallback English); without Laravel the
 * French reference catalogue is used.
 */
class Texts
{
    /** @var callable|null function (string $key, array $params): ?string */
    public static $translator = null;

    /** @var array|null */
    protected static $reference = null;

    /** Translated text of a key, with :placeholders replaced. */
    public static function t($key, array $params = [])
    {
        if (self::$translator) {
            $translate = self::$translator;
            $text = $translate($key, $params);
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
}

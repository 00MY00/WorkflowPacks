<?php

namespace Modules\WorkflowPacks\Services\Imap;

/**
 * Names of the e-mail folders managed by the "file into an e-mail folder" action (no Laravel dependency).
 *
 * The value of the action is a path written by people: "Invoices/Suppliers". On the mail server it becomes
 * "<prefix> Invoices" + delimiter + "Suppliers" — the prefix ("[WP]" by default) marks the top folder as managed by the
 * module, and the module never creates nor moves anything outside a folder carrying it.
 */
class FolderPath
{
    /** Type of the action added to the Workflows catalog. */
    const ACTION = 'wp_imap_folder';
    const DEFAULT_PREFIX = '[WP]';
    const MAX_LEVELS = 4;
    const MAX_SEGMENT = 60;
    const MAX_PREFIX = 20;

    /**
     * Levels of a folder path, or the reason of the refusal.
     *
     * @return array ['segments' => string[], 'error' => null|string] error = key of texts.php
     */
    public static function parse($value)
    {
        if (!is_string($value)) {
            return ['segments' => [], 'error' => 'imap_folder_empty'];
        }
        $segments = [];
        foreach (explode('/', $value) as $segment) {
            $segment = trim(preg_replace('/\s+/u', ' ', $segment));
            if ($segment === '') {
                continue;
            }
            // wildcards and quotes of IMAP, control characters, other delimiters, hidden or relative names
            if (preg_match('/[\x00-\x1F\x7F*%"\\\\]|^\./u', $segment)) {
                return ['segments' => [], 'error' => 'imap_folder_chars'];
            }
            if (mb_strlen($segment) > self::MAX_SEGMENT) {
                return ['segments' => [], 'error' => 'imap_folder_long'];
            }
            $segments[] = $segment;
        }
        if (!$segments) {
            return ['segments' => [], 'error' => 'imap_folder_empty'];
        }
        if (count($segments) > self::MAX_LEVELS) {
            return ['segments' => [], 'error' => 'imap_folder_deep'];
        }

        return ['segments' => $segments, 'error' => null];
    }

    /** Prefix from the settings, cleaned (letters, digits, spaces, brackets, - and _), default "[WP]". */
    public static function prefix($raw)
    {
        $prefix = trim(preg_replace('/[^\p{L}\p{N} _\-\[\]()]/u', '', (string) $raw));
        $prefix = mb_substr($prefix, 0, self::MAX_PREFIX);

        return $prefix !== '' ? $prefix : self::DEFAULT_PREFIX;
    }

    /**
     * Full path of every level on the server, from the top: ["[WP] Invoices", "[WP] Invoices/Suppliers"].
     * $parent: personal namespace of servers that keep every folder under INBOX ("INBOX" with delimiter ".").
     */
    public static function levels(array $segments, $prefix, $delimiter = '/', $parent = '')
    {
        $delimiter = (string) $delimiter !== '' ? (string) $delimiter : '/';
        $path = $parent !== '' ? $parent.$delimiter : '';
        $out = [];
        foreach (array_values($segments) as $i => $segment) {
            // the delimiter of the server cannot appear inside a name
            $segment = str_replace($delimiter, '-', $segment);
            $path .= ($i ? $delimiter : '').($i ? $segment : $prefix.' '.$segment);
            $out[] = $path;
        }

        return $out;
    }

    /** Text shown in the interface: "[WP] Invoices › Suppliers". */
    public static function label($value, $prefix)
    {
        $parsed = self::parse($value);
        if ($parsed['error'] !== null) {
            return (string) $value;
        }
        $segments = $parsed['segments'];
        $segments[0] = $prefix.' '.$segments[0];

        return implode(' › ', $segments);
    }

    /** Is this server path one of the folders the module manages (top level carries the prefix)? */
    public static function isManaged($path, $prefix, $delimiter = '/', $parent = '')
    {
        $path = (string) $path;
        if ($parent !== '') {
            if (strpos($path, $parent.$delimiter) !== 0) {
                return false;
            }
            $path = substr($path, strlen($parent.$delimiter));
        }

        return strpos($path, $prefix.' ') === 0;
    }
}

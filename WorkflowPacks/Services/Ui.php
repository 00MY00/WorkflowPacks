<?php

namespace Modules\WorkflowPacks\Services;


/** Helpers of the views: icons, initials and colours of the mailboxes, info blocks of a description. Values escaped. */
class Ui
{
    const ICONS = [
        'package' => ['M4 7.5 12 4l8 3.5v9L12 20l-8-3.5z', 'M4 7.5l8 3.5 8-3.5', 'M12 11v9'],
        'refresh' => ['M20 11a8 8 0 0 0-14.9-3.9L4 8', 'M4 4v4h4', 'M4 13a8 8 0 0 0 14.9 3.9L20 16', 'M20 20v-4h-4'],
        'trash'   => ['M4 7h16', 'M9 7V4h6v3', 'M6 7l1 13h10l1-13'],
        'upload'  => ['M12 15V4', 'M7 9l5-5 5 5', 'M5 20h14'],
        'download' => ['M12 4v11', 'M7 10l5 5 5-5', 'M5 20h14'],
        'zap'     => ['M13 3 5 13.5h6L10 21l8-10.5h-6z'],
        'note'    => ['M7 3h7l5 5v13H7z', 'M14 3v5h5', 'M10 13h6', 'M10 17h6'],
        'arrow'   => ['M5 12h14', 'M13 6l6 6-6 6'],
        'clock'   => ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M12 7v5l3 2'],
        'x'       => ['M6 6l12 12', 'M18 6 6 18'],
        'check'   => ['M5 12.5 10 17l9-10'],
        'power'   => ['M12 3v8', 'M6.3 6.3a8 8 0 1 0 11.4 0'],
        'folder'  => ['M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z'],
        'person'  => ['M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z', 'M4 21a8 8 0 0 1 16 0'],
        'closed'  => ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M8 12.5l3 3 5-6'],
        'info'    => ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M12 11v6', 'M12 7.5v.5'],
        'mail'    => ['M3 6h18v12H3z', 'M3 7l9 6 9-6'],
        'shield'  => ['M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z'],
        'tag'     => ['M3 12V4h8l10 10-8 8z', 'M7.5 7.5h.01'],
        'layers'  => ['M12 3 3 8l9 5 9-5z', 'M3 13l9 5 9-5'],
    ];
    /** Colour families (background / text) of the mailboxes: chosen by the name, never listed. */
    const FAMILIES = 4;

    /** Inline SVG icon (decorative: the text next to it, or the aria-label of its button, says what it means). */
    public static function icon($name, $class = '')
    {
        $paths = self::ICONS[$name] ?? self::ICONS['info'];

        return '<svg class="wp-ic '.e($class).'" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
            .implode('', array_map(function ($d) {
                return '<path d="'.$d.'"></path>';
            }, $paths)).'</svg>';
    }

    /** Two letters of a name ("Support Clients" -> "SC", "Ventes" -> "VE"). */
    public static function initials($name)
    {
        // a leading tag ("[Tri] Factures") and punctuation are not initials
        $name = preg_replace('/^\s*\[[^\]]*\]\s*/u', '', (string) $name);
        $words = preg_split('/[^\p{L}\p{N}]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
        $two = count($words) > 1 ? mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1) : mb_substr($words[0], 0, 2);

        return mb_strtoupper($two);
    }

    /** Colour family of a mailbox (0 to FAMILIES - 1), always the same for the same name. */
    public static function family($name)
    {
        return (int) (sprintf('%u', crc32(mb_strtolower(trim((string) $name)))) % self::FAMILIES);
    }


    /**
     * A description cut into info blocks, one idea each: the sentences of the original text, nothing removed. A
     * sentence "Title: text" keeps its title in bold.
     *
     * @return array [['icon', 'title', 'text']]
     */
    public static function infoBlocks($text)
    {
        $icons = ['info', 'zap', 'folder', 'shield', 'clock', 'tag'];
        $out = [];
        foreach (preg_split('/(?<=[.!?])\s+(?=\p{Lu})/u', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY) as $i => $sentence) {
            $title = '';
            if (preg_match('/^(.{2,60}?)\s?:\s+(.+)$/us', $sentence, $m)) {
                list(, $title, $sentence) = $m;
            }
            $out[] = ['icon' => $icons[$i % count($icons)], 'title' => $title, 'text' => $sentence];
        }

        return $out;
    }
}

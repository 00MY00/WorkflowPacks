<?php

namespace Modules\WorkflowPacks\Services\Ai;

/**
 * Text -> vector, locally (no service, no library): words and 3-letter pieces hashed on 512 dimensions, weighted
 * (log of the count), normalised. Similarity = cosine, 0 to 1. Independent of the language, a few milliseconds per
 * e-mail. One vector per part of an e-mail (subject, body, sender), so that each part can weigh differently.
 */
class Vectorizer
{
    const DIMENSIONS = 512;
    const VERSION = 1;

    /** @return array dimension => weight (sparse), norm 1; [] for an empty text */
    public static function vector($text)
    {
        $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $text)));
        if ($text === '') {
            return [];
        }
        $counts = [];
        $add = function ($feature) use (&$counts) {
            $d = (int) (sprintf('%u', crc32($feature)) % self::DIMENSIONS);
            $counts[$d] = ($counts[$d] ?? 0) + 1;
        };
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $add('w:'.$word);
            $padded = ' '.$word.' ';
            $len = mb_strlen($padded);
            for ($i = 0; $i + 3 <= $len; $i++) {
                $add('t:'.mb_substr($padded, $i, 3));
            }
        }
        $norm = 0.0;
        foreach ($counts as $d => $n) {
            $counts[$d] = 1 + log($n);
            $norm += $counts[$d] * $counts[$d];
        }
        $norm = sqrt($norm);
        foreach ($counts as $d => $w) {
            $counts[$d] = $w / $norm;
        }

        return $counts;
    }

    /** Cosine of two normalised vectors (0 to 1). */
    public static function similarity(array $a, array $b)
    {
        if (count($a) > count($b)) {
            list($a, $b) = [$b, $a];
        }
        $dot = 0.0;
        foreach ($a as $d => $w) {
            if (isset($b[$d])) {
                $dot += $w * $b[$d];
            }
        }

        return max(0.0, min(1.0, $dot));
    }

    /** Centre of several vectors (the profile of examples), normalised. */
    public static function centroid(array $vectors)
    {
        $sum = [];
        foreach ($vectors as $v) {
            foreach ($v as $d => $w) {
                $sum[$d] = ($sum[$d] ?? 0) + $w;
            }
        }
        $norm = sqrt(array_sum(array_map(function ($w) {
            return $w * $w;
        }, $sum)));

        return $norm > 0 ? array_map(function ($w) use ($norm) {
            return $w / $norm;
        }, $sum) : [];
    }
}

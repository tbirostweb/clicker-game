<?php

namespace App\Security;

/**
 * Public leaderboard nickname moderation (first line only: the admin
 * DELETE endpoint stays the real moderation tool).
 *
 * Names are stored NFKC-normalized (full-width / stylised Unicode letters
 * become their plain form). The banned-word check works on a folded form:
 * lowercase, accents removed, common leetspeak undone. Short words must
 * match a whole word (avoids "Scunthorpe" false positives such as "Conrad");
 * long, unambiguous words match anywhere, even with separators removed.
 */
final class NameModeration
{
    /** Whole-word matches only. */
    private const BANNED_WORDS = [
        'con', 'conne', 'cons', 'pute', 'putes', 'fdp', 'ntm', 'nazi', 'nazis', 'bite', 'bites',
        'nique', 'niquer', 'retard', 'retards', 'negro', 'negros', 'pedale', 'tapette', 'chatte',
        'fag', 'fags', 'cunt', 'whore', 'slut', 'kkk', 'hitler',
    ];

    /** Matched anywhere in the compacted name. */
    private const BANNED_FRAGMENTS = [
        'connard', 'connasse', 'salope', 'salaud', 'encule', 'enfoire', 'batard', 'bougnoul',
        'nigger', 'nigga', 'faggot', 'pedophile', 'youpin', 'bitch', 'fuck', 'asshole',
        'siegheil', 'heilhitler', 'niquetamere', 'tamere', 'suceuse', 'couille',
    ];

    private const LEET = ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '!' => 'i'];

    public static function normalize(string $name): string
    {
        $name = trim($name);
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($name, \Normalizer::FORM_KC);
            if (is_string($normalized)) {
                $name = trim($normalized);
            }
        }

        return $name;
    }

    public static function isAllowed(string $name): bool
    {
        $folded = self::fold($name);
        $words = preg_split('/[^a-z]+/', $folded, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        if (array_intersect($words, self::BANNED_WORDS)) {
            return false;
        }

        $compact = preg_replace('/[^a-z]+/', '', $folded) ?? '';
        foreach (self::BANNED_FRAGMENTS as $fragment) {
            if (str_contains($compact, $fragment)) {
                return false;
            }
        }

        return true;
    }

    private static function fold(string $name): string
    {
        $name = self::normalize($name);
        if (function_exists('transliterator_transliterate')) {
            $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);
            if (is_string($ascii)) {
                $name = $ascii;
            }
        }
        $name = mb_strtolower($name);

        return strtr($name, self::LEET);
    }
}

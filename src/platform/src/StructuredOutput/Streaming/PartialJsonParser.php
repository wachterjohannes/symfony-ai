<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\StructuredOutput\Streaming;

/**
 * Best-effort parser for incomplete JSON strings produced by streaming structured output.
 *
 * Attempts a strict `json_decode` first and falls back to a sequence of recovery passes
 * (trailing commas, unclosed strings, partial literals, unclosed structures) so callers
 * can render partial objects before a model finishes emitting them.
 *
 * Byte-indexed scanning is intentional: `"` and `\` are always single-byte in UTF-8,
 * so the string/escape tracking remains correct even for multi-byte payloads.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class PartialJsonParser
{
    /**
     * @param-out string|null $errorMessage null on success, json_last_error_msg() text on failure
     */
    public static function parse(string $json, ?string &$errorMessage = null): mixed
    {
        $data = @json_decode($json, true);

        if (\JSON_ERROR_NONE === json_last_error()) {
            $errorMessage = null;

            return $data;
        }

        $errorMessage = json_last_error_msg();

        return self::fixAndParse($json, $errorMessage);
    }

    private static function fixAndParse(string $json, ?string &$errorMessage): mixed
    {
        $fixed = self::fixSyntax($json);

        $data = @json_decode($fixed, true);

        if (\JSON_ERROR_NONE === json_last_error()) {
            $errorMessage = null;

            return $data;
        }

        $errorMessage = json_last_error_msg();

        return null;
    }

    private static function fixSyntax(string $json): string
    {
        $json = preg_replace('/,\s*([\]}])/', '$1', $json) ?? $json;

        $json = self::closeUnclosedStrings($json);
        $json = self::fixIncompleteValues($json);

        return self::closeUnclosedStructures($json);
    }

    private static function closeUnclosedStrings(string $json): string
    {
        $inString = false;
        $escaped = false;
        $length = \strlen($json);

        for ($i = 0; $i < $length; ++$i) {
            $char = $json[$i];

            if ('"' === $char && !$escaped) {
                $inString = !$inString;
            }

            $escaped = '\\' === $char && !$escaped;
        }

        if ($inString) {
            $json .= '"';
        }

        return $json;
    }

    private static function fixIncompleteValues(string $json): string
    {
        $trimmed = rtrim($json);

        if (preg_match('/:\s*$/', $trimmed)) {
            $json = $trimmed.'null';
        }

        $trimmed = rtrim($json);
        if (preg_match('/([\s:,\[\{])tru$/i', $trimmed)) {
            $json = substr($trimmed, 0, -3).'true';
        } elseif (preg_match('/([\s:,\[\{])tr$/i', $trimmed)) {
            $json = substr($trimmed, 0, -2).'true';
        } elseif (preg_match('/([\s:,\[\{])fals$/i', $trimmed)) {
            $json = substr($trimmed, 0, -4).'false';
        } elseif (preg_match('/([\s:,\[\{])fal$/i', $trimmed)) {
            $json = substr($trimmed, 0, -3).'false';
        } elseif (preg_match('/([\s:,\[\{])fa$/i', $trimmed)) {
            $json = substr($trimmed, 0, -2).'false';
        } elseif (preg_match('/([\s:,\[\{])nul$/i', $trimmed)) {
            $json = substr($trimmed, 0, -3).'null';
        } elseif (preg_match('/([\s:,\[\{])nu$/i', $trimmed)) {
            $json = substr($trimmed, 0, -2).'null';
        }

        $trimmed = rtrim($json);
        if (preg_match('/,\s*$/', $trimmed)) {
            $json = preg_replace('/,\s*$/', '', $trimmed) ?? $trimmed;
        }

        return $json;
    }

    private static function closeUnclosedStructures(string $json): string
    {
        $stack = [];
        $inString = false;
        $escaped = false;
        $length = \strlen($json);

        for ($i = 0; $i < $length; ++$i) {
            $char = $json[$i];

            if ('"' === $char && !$escaped) {
                $inString = !$inString;
            }

            $escaped = '\\' === $char && !$escaped;

            if ($inString) {
                continue;
            }

            if ('[' === $char || '{' === $char) {
                $stack[] = $char;
            } elseif (']' === $char) {
                if ([] !== $stack && '[' === $stack[\count($stack) - 1]) {
                    array_pop($stack);
                }
            } elseif ('}' === $char) {
                if ([] !== $stack && '{' === $stack[\count($stack) - 1]) {
                    array_pop($stack);
                }
            }
        }

        while ([] !== $stack) {
            $open = array_pop($stack);
            $json .= '[' === $open ? ']' : '}';
        }

        return $json;
    }
}

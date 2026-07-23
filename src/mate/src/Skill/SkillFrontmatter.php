<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Skill;

/**
 * Reads and rewrites the leading YAML front matter of a SKILL.md file.
 *
 * SKILL.md front matter only needs the flat "name" and "description" scalars, so a tiny
 * line-based parser is used deliberately instead of pulling in a full YAML dependency.
 * Values may be wrapped in single or double quotes; those are stripped on read.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillFrontmatter
{
    private const PATTERN = '/\A(?:\xEF\xBB\xBF)?---\r?\n(.*?)\r?\n---[ \t]*(?:\r?\n|\z)/s';

    /**
     * Parses the front matter block into a flat key => value map.
     *
     * @return array<string, string>|null null when no parseable front matter block is present
     */
    public function parse(string $content): ?array
    {
        if (1 !== preg_match(self::PATTERN, $content, $matches)) {
            return null;
        }

        $result = [];
        foreach (explode("\n", $matches[1]) as $line) {
            $line = rtrim($line, "\r");
            $trimmed = trim($line);
            if ('' === $trimmed || str_starts_with($trimmed, '#')) {
                continue;
            }

            $separator = strpos($line, ':');
            if (false === $separator) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            if ('' === $key) {
                continue;
            }

            $result[$key] = $this->unquote(trim(substr($line, $separator + 1)));
        }

        return $result;
    }

    /**
     * Rewrites the front matter "name" value, leaving the rest of the document untouched.
     *
     * When no front matter or no "name" line is present, the content is returned unchanged.
     */
    public function rewriteName(string $content, string $newName): string
    {
        if (1 !== preg_match(self::PATTERN, $content, $matches, \PREG_OFFSET_CAPTURE)) {
            return $content;
        }

        $blockContent = $matches[1][0];
        $blockOffset = $matches[1][1];

        $rewrittenBlock = preg_replace(
            '/^([ \t]*name[ \t]*:[ \t]*).*$/m',
            '${1}'.$newName,
            $blockContent,
            1,
            $count
        );

        if (null === $rewrittenBlock || 0 === $count) {
            return $content;
        }

        return substr($content, 0, $blockOffset).$rewrittenBlock.substr($content, $blockOffset + \strlen($blockContent));
    }

    private function unquote(string $value): string
    {
        if (\strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[\strlen($value) - 1];
            if (('"' === $first && '"' === $last) || ("'" === $first && "'" === $last)) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}

<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Sandbox;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Symfony\AI\Mate\Sandbox\Exception\SandboxViolationException;

/**
 * Rejects code that names something the sandbox must never touch, whatever shape the code
 * is in.
 *
 * This runs as its own full pass *before* the allowlist, and that ordering is the whole
 * point. Run inside the allowlist walk it would be unreachable: a name is always a child of
 * an expression, so the structural rule would reject `new ReflectionClass(…)` for the `new`
 * and never look at the name. A rule that only fires when another rule already fired is not
 * a second layer.
 *
 * @internal
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class DeniedNameVisitor extends NodeVisitorAbstract
{
    /**
     * Matched case-insensitively as a substring, against class names, function names,
     * method names and constant names alike.
     *
     * @var list<string>
     */
    private const DENIED_FRAGMENTS = ['reflection'];

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Node\Name) {
            $this->check($node->toString(), $node->getStartLine());

            return null;
        }

        if ($node instanceof Node\Identifier) {
            $this->check($node->toString(), $node->getStartLine());
        }

        return null;
    }

    private function check(string $name, int $line): void
    {
        foreach (self::DENIED_FRAGMENTS as $fragment) {
            if (!str_contains(strtolower($name), $fragment)) {
                continue;
            }

            throw new SandboxViolationException(\sprintf('"%s" refers to %s, which the sandbox never exposes.', $name, $fragment), $line);
        }
    }
}

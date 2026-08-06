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

use PhpParser\Error as ParserError;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Symfony\AI\Mate\Sandbox\Exception\SandboxViolationException;

/**
 * First of the two sandbox layers: a strict allowlist over the parsed AST.
 *
 * The rule is "everything is forbidden unless it is explicitly allowed", not the other way
 * round. A blocklist has to anticipate every escape; an allowlist only has to enumerate the
 * handful of constructs the sandbox actually needs, and anything the language grows later
 * is rejected by default instead of silently permitted.
 *
 * This layer alone is not the security boundary — see {@see SandboxRunner} for the second,
 * independent layer that runs the validated code in its own locked-down subprocess.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class CodeValidator
{
    /**
     * Pure builtin functions the sandbox exposes. Deliberately small: extending the list is
     * a later, deliberate step, while a function that turns out to reach the filesystem,
     * the network or the callable machinery cannot be taken back once agents rely on it.
     *
     * Everything callback-shaped (`array_map`, `usort`, `array_filter`, …) is absent on
     * purpose, because v1 has no closures to pass to it.
     *
     * @var list<string>
     */
    public const ALLOWED_FUNCTIONS = [
        'count',
        'sprintf',
        'min',
        'max',
        'round',
        'array_sum',
        'strlen',
        'substr',
        'str_contains',
        'number_format',
    ];

    /**
     * Upper bound on the submitted snippet. The sandbox is meant for a handful of lines of
     * glue; anything larger is a sign the work belongs in a real tool.
     */
    public const MAX_CODE_LENGTH = 20000;

    private readonly Parser $parser;

    /**
     * @param list<string> $allowedMethods Method names callable on `$mate`
     */
    public function __construct(
        private readonly array $allowedMethods = MateApi::METHODS,
        ?Parser $parser = null,
    ) {
        $this->parser = $parser ?? (new ParserFactory())->createForHostVersion();
    }

    /**
     * @throws SandboxViolationException when the code does not parse, or contains a
     *                                   construct outside the allowlist
     */
    public function validate(string $code): void
    {
        if ('' === trim($code)) {
            throw new SandboxViolationException('the snippet is empty.');
        }

        if (\strlen($code) > self::MAX_CODE_LENGTH) {
            throw new SandboxViolationException(\sprintf('the snippet is %d bytes long, the limit is %d. Sandbox code is glue, not a program.', \strlen($code), self::MAX_CODE_LENGTH));
        }

        try {
            $statements = $this->parser->parse('<?php '.$code);
        } catch (ParserError $e) {
            throw new SandboxViolationException(\sprintf('the snippet is not valid PHP (%s).', $e->getRawMessage()), $e->getStartLine());
        }

        if (null === $statements) {
            throw new SandboxViolationException('the snippet could not be parsed.');
        }

        // Two independent passes, in this order on purpose. The name pass would never fire
        // from inside the allowlist walk, because the structural rule always rejects the
        // parent expression first — see DeniedNameVisitor.
        (new NodeTraverser(new DeniedNameVisitor()))->traverse($statements);
        (new NodeTraverser(new AllowlistVisitor($this->allowedMethods)))->traverse($statements);
    }
}

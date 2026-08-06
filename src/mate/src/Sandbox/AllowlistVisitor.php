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
 * Walks the parsed snippet and throws on the first node that is not on the allowlist.
 *
 * @internal
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class AllowlistVisitor extends NodeVisitorAbstract
{
    /**
     * Node classes the sandbox accepts. Two of the entries ({@see Node\Expr\BinaryOp} and
     * {@see Node\Expr\AssignOp}) are abstract bases matched with `instanceof`, which is why
     * this is not a plain class-name lookup.
     *
     * @var list<class-string<Node>>
     */
    private const ALLOWED = [
        // Statements
        Node\Stmt\Expression::class,
        Node\Stmt\If_::class,
        Node\Stmt\ElseIf_::class,
        Node\Stmt\Else_::class,
        Node\Stmt\For_::class,
        Node\Stmt\Foreach_::class,
        Node\Stmt\While_::class,
        Node\Stmt\Do_::class,
        Node\Stmt\Return_::class,
        Node\Stmt\Break_::class,
        Node\Stmt\Continue_::class,
        Node\Stmt\Nop::class,

        // Expressions
        Node\Expr\Assign::class,
        Node\Expr\AssignOp::class,
        Node\Expr\BinaryOp::class,
        Node\Expr\Variable::class,
        Node\Expr\UnaryMinus::class,
        Node\Expr\UnaryPlus::class,
        Node\Expr\BooleanNot::class,
        Node\Expr\PreInc::class,
        Node\Expr\PostInc::class,
        Node\Expr\PreDec::class,
        Node\Expr\PostDec::class,
        Node\Expr\Ternary::class,
        Node\Expr\ArrayDimFetch::class,
        Node\Expr\Array_::class,
        Node\Expr\ConstFetch::class,
        Node\Expr\FuncCall::class,
        Node\Expr\MethodCall::class,

        // Leaves
        Node\Arg::class,
        Node\ArrayItem::class,
        Node\Identifier::class,
        Node\Name::class,
        Node\Scalar\Int_::class,
        Node\Scalar\Float_::class,
        Node\Scalar\String_::class,
        Node\Scalar\InterpolatedString::class,
        Node\InterpolatedStringPart::class,
    ];

    /**
     * Tailored rejection reasons. Every one of these is already covered by the allowlist
     * above — they exist so the agent reading the error learns why the construct is out and
     * what to do instead, rather than "not on the allowlist".
     *
     * @var array<class-string<Node>, string>
     */
    private const DENIED = [
        Node\Expr\New_::class => 'creating objects with `new` is not allowed; the sandbox knows exactly one object, the injected `$mate`',
        Node\Expr\Closure::class => 'closures are not supported in v1; write the loop out instead of passing a callback',
        Node\Expr\ArrowFunction::class => 'arrow functions are not supported in v1; write the loop out instead of passing a callback',
        Node\Stmt\Class_::class => 'defining classes inside sandbox code is not allowed',
        Node\Stmt\Function_::class => 'defining functions inside sandbox code is not allowed',
        Node\Stmt\Interface_::class => 'defining interfaces inside sandbox code is not allowed',
        Node\Stmt\Trait_::class => 'defining traits inside sandbox code is not allowed',
        Node\Stmt\Enum_::class => 'defining enums inside sandbox code is not allowed',
        Node\Expr\StaticCall::class => 'static method calls are not allowed; they would reach every class loaded in the process',
        Node\Expr\StaticPropertyFetch::class => 'static property access is not allowed; it would reach every class loaded in the process',
        Node\Expr\ClassConstFetch::class => 'class constants and `::class` are not allowed; they would reach every class loaded in the process',
        Node\Expr\Eval_::class => '`eval()` is not allowed',
        Node\Expr\Include_::class => '`include`/`require` is not allowed',
        Node\Expr\ShellExec::class => 'backtick shell execution is not allowed; use `$mate->runCommand()` with an allowlisted command',
        Node\Expr\PropertyFetch::class => 'property access is not allowed; `$mate` exposes methods only',
        Node\Expr\NullsafePropertyFetch::class => 'property access is not allowed; `$mate` exposes methods only',
        Node\Expr\NullsafeMethodCall::class => 'nullsafe method calls are not allowed; call `$mate->method()` directly',
        Node\Expr\AssignRef::class => 'assignment by reference is not allowed',
        Node\Expr\Exit_::class => '`exit`/`die` is not allowed; return a value instead',
        Node\Stmt\Echo_::class => 'the sandbox has no output stream; `return` the value you want to see instead of echoing it',
        Node\Expr\Print_::class => 'the sandbox has no output stream; `return` the value you want to see instead of printing it',
        Node\Stmt\Global_::class => 'the sandbox has no global scope to import',
        Node\Stmt\Static_::class => 'static variables are not allowed',
        Node\Stmt\TryCatch::class => 'try/catch is not supported in v1; a failing `$mate` call aborts the run and reports the error',
        Node\Stmt\Switch_::class => '`switch` is not supported in v1; use `if`/`elseif`',
        Node\Expr\Match_::class => '`match` is not supported in v1; use `if`/`elseif`',
        Node\Expr\Instanceof_::class => '`instanceof` is not allowed',
        Node\Expr\Clone_::class => '`clone` is not allowed',
        Node\Expr\Throw_::class => '`throw` is not allowed; return a value describing the problem instead',
        Node\Expr\ErrorSuppress::class => 'the `@` error-suppression operator is not allowed',
        Node\Expr\List_::class => 'list()/array destructuring is not supported in v1',
        Node\Expr\Yield_::class => 'generators are not allowed',
        Node\Expr\YieldFrom::class => 'generators are not allowed',
        Node\Expr\Isset_::class => '`isset()` is not supported in v1; initialise your variables before the loop',
        Node\Expr\Empty_::class => '`empty()` is not supported in v1; compare explicitly instead',
        Node\Stmt\Unset_::class => '`unset()` is not supported in v1',
        Node\Stmt\InlineHTML::class => 'closing the PHP tag is not allowed; submit statements only',
    ];

    /**
     * Rejected by name. Structurally unreachable already — every one of them needs `new`,
     * a static call or a dynamic callable, all of which are out — but a second, independent
     * bar costs nothing and does not depend on the first one being airtight.
     */
    private const DENIED_NAME_FRAGMENT = 'reflection';

    /**
     * @var list<string>
     */
    private const SUPERGLOBALS = [
        'GLOBALS',
        '_SERVER',
        '_ENV',
        '_GET',
        '_POST',
        '_COOKIE',
        '_FILES',
        '_REQUEST',
        '_SESSION',
    ];

    /**
     * @param list<string> $allowedMethods
     */
    public function __construct(
        private readonly array $allowedMethods,
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        $this->rejectReflectionByName($node);
        $this->rejectExplicitly($node);
        $this->rejectUnlessAllowed($node);
        $this->checkNodeDetails($node);

        return null;
    }

    private function rejectReflectionByName(Node $node): void
    {
        if (!$node instanceof Node\Name && !$node instanceof Node\Identifier) {
            return;
        }

        if (!str_contains(strtolower($node->toString()), self::DENIED_NAME_FRAGMENT)) {
            return;
        }

        throw new SandboxViolationException(\sprintf('"%s" refers to reflection, which the sandbox never exposes.', $node->toString()), $node->getStartLine());
    }

    private function rejectExplicitly(Node $node): void
    {
        if ($node instanceof Node\Expr\Cast) {
            throw new SandboxViolationException('type casts are not supported in v1; use `round()` or `sprintf()` to shape a value.', $node->getStartLine());
        }

        $reason = self::DENIED[$node::class] ?? null;
        if (null === $reason) {
            return;
        }

        throw new SandboxViolationException($reason.'.', $node->getStartLine());
    }

    private function rejectUnlessAllowed(Node $node): void
    {
        foreach (self::ALLOWED as $allowed) {
            if ($node instanceof $allowed) {
                return;
            }
        }

        throw new SandboxViolationException(\sprintf('`%s` is not on the sandbox allowlist.', $this->describe($node)), $node->getStartLine());
    }

    private function checkNodeDetails(Node $node): void
    {
        if ($node instanceof Node\Expr\Variable) {
            $this->checkVariable($node);
        }

        if ($node instanceof Node\Expr\ConstFetch) {
            $this->checkConstFetch($node);
        }

        if ($node instanceof Node\Expr\FuncCall) {
            $this->checkFuncCall($node);
        }

        if ($node instanceof Node\Expr\MethodCall) {
            $this->checkMethodCall($node);
        }

        if ($node instanceof Node\Arg) {
            $this->checkArg($node);
        }

        if ($node instanceof Node\Stmt\Foreach_ && $node->byRef) {
            throw new SandboxViolationException('iterating by reference is not allowed.', $node->getStartLine());
        }
    }

    private function checkVariable(Node\Expr\Variable $node): void
    {
        if (!\is_string($node->name)) {
            throw new SandboxViolationException('variable variables (`$$name`) are not allowed; the name of every variable has to be visible in the source.', $node->getStartLine());
        }

        if (\in_array($node->name, self::SUPERGLOBALS, true)) {
            throw new SandboxViolationException(\sprintf('`$%s` is a superglobal and carries request, environment and session data the sandbox must not see.', $node->name), $node->getStartLine());
        }
    }

    private function checkConstFetch(Node\Expr\ConstFetch $node): void
    {
        if (\in_array($node->name->toLowerString(), ['true', 'false', 'null'], true)) {
            return;
        }

        throw new SandboxViolationException(\sprintf('the constant `%s` is not available; only `true`, `false` and `null` are.', $node->name->toString()), $node->getStartLine());
    }

    private function checkFuncCall(Node\Expr\FuncCall $node): void
    {
        if (!$node->name instanceof Node\Name) {
            throw new SandboxViolationException('dynamic function calls are not allowed; the function name has to be written out literally.', $node->getStartLine());
        }

        if (\in_array($node->name->toLowerString(), CodeValidator::ALLOWED_FUNCTIONS, true)) {
            return;
        }

        throw new SandboxViolationException(\sprintf('the function `%s()` is not on the sandbox allowlist (allowed: %s).', $node->name->toString(), implode(', ', CodeValidator::ALLOWED_FUNCTIONS)), $node->getStartLine());
    }

    private function checkMethodCall(Node\Expr\MethodCall $node): void
    {
        if (!$node->var instanceof Node\Expr\Variable || 'mate' !== $node->var->name) {
            throw new SandboxViolationException('method calls are only allowed on `$mate`, the single object the sandbox injects.', $node->getStartLine());
        }

        if (!$node->name instanceof Node\Identifier) {
            throw new SandboxViolationException('dynamic method names are not allowed; the method has to be written out literally.', $node->getStartLine());
        }

        if (\in_array($node->name->toString(), $this->allowedMethods, true)) {
            return;
        }

        throw new SandboxViolationException(\sprintf('`$mate` has no method `%s()` (available: %s).', $node->name->toString(), implode('(), ', $this->allowedMethods).'()'), $node->getStartLine());
    }

    private function checkArg(Node\Arg $node): void
    {
        if ($node->unpack) {
            throw new SandboxViolationException('argument unpacking (`...$args`) is not allowed.', $node->getStartLine());
        }

        if ($node->byRef) {
            throw new SandboxViolationException('passing arguments by reference is not allowed.', $node->getStartLine());
        }
    }

    private function describe(Node $node): string
    {
        return str_replace('_', ' ', $node->getType());
    }
}

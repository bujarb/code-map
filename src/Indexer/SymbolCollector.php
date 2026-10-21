<?php

declare(strict_types=1);

namespace CodeMap\Indexer;

use PhpParser\Comment\Doc;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Walks a name-resolved AST and records declarations, inheritance, attributes and references.
 *
 * Receiver types for "$x->method()" are resolved best-effort and only from information that is
 * local and explicit: $this, typed properties (incl. promoted constructor params), typed parameters,
 * and variables assigned from "new Foo". Anything else is stored with an unknown target class.
 */
final class SymbolCollector extends NodeVisitorAbstract
{
    private const int MAX_VALUE_LENGTH = 80;

    public FileSymbols $result;

    private string $namespace = '';

    /** @var list<array{id: ?int, fqn: ?string, parent: ?string, props: array<string, string>}> */
    private array $classStack = [];

    /** @var list<array{id: ?int, locals: array<string, string>}> */
    private array $functionStack = [];

    private PrettyPrinter $printer;

    public function __construct()
    {
        $this->printer = new PrettyPrinter();
        $this->result = new FileSymbols();
    }

    public function beforeTraverse(array $nodes): null
    {
        $this->result = new FileSymbols();
        $this->namespace = '';
        $this->classStack = [];
        $this->functionStack = [];

        return null;
    }

    public function enterNode(Node $node): null
    {
        match (true) {
            $node instanceof Stmt\Namespace_ => $this->namespace = $node->name?->toString() ?? '',
            $node instanceof Stmt\ClassLike => $this->enterClassLike($node),
            $node instanceof Stmt\ClassMethod => $this->enterMethod($node),
            $node instanceof Stmt\Function_ => $this->enterFunction($node),
            $node instanceof Stmt\Property => $this->addProperty($node),
            $node instanceof Stmt\ClassConst => $this->addClassConstants($node),
            $node instanceof Stmt\EnumCase => $this->addEnumCase($node),
            $node instanceof Stmt\TraitUse => $this->addTraitUse($node),
            default => $this->collectReference($node),
        };

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Stmt\ClassLike) {
            array_pop($this->classStack);
        } elseif ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) {
            array_pop($this->functionStack);
        } elseif ($node instanceof Stmt\Namespace_) {
            $this->namespace = '';
        }

        return null;
    }

    private function enterClassLike(Stmt\ClassLike $node): void
    {
        $fqn = $node->namespacedName?->toString();
        if (null === $fqn) {
            // Anonymous class: don't index it as a symbol, but keep $this resolution from leaking from the outer class.
            $this->classStack[] = ['id' => null, 'fqn' => null, 'parent' => null, 'props' => []];

            return;
        }

        [$kind, $flags, $header] = match (true) {
            $node instanceof Stmt\Interface_ => ['interface', [], 'interface'],
            $node instanceof Stmt\Trait_ => ['trait', [], 'trait'],
            $node instanceof Stmt\Enum_ => ['enum', [], 'enum'],
            default => ['class', $this->classFlags($node), 'class'],
        };

        $signature = trim(implode(' ', $flags).' '.$header.' '.$fqn);
        if ($node instanceof Stmt\Enum_ && null !== $node->scalarType) {
            $signature .= ': '.$node->scalarType->toString();
        }

        $parent = null;
        $extends = [];
        $implements = [];
        if ($node instanceof Stmt\Class_) {
            $parent = $node->extends?->toString();
            $extends = null === $parent ? [] : [$parent];
            $implements = array_map(static fn (Name $n) => $n->toString(), $node->implements);
        } elseif ($node instanceof Stmt\Interface_) {
            $extends = array_map(static fn (Name $n) => $n->toString(), $node->extends);
        } elseif ($node instanceof Stmt\Enum_) {
            $implements = array_map(static fn (Name $n) => $n->toString(), $node->implements);
        }
        if ([] !== $extends) {
            $signature .= ' extends '.implode(', ', $extends);
        }
        if ([] !== $implements) {
            $signature .= ' implements '.implode(', ', $implements);
        }

        $id = $this->result->addSymbol([
            'kind' => $kind,
            'fqn' => $fqn,
            'name' => $node->name->toString(),
            'namespace' => $this->namespace,
            'signature' => $signature,
            'visibility' => null,
            'flags' => implode(' ', $flags),
            'doc' => $this->docSummary($node->getDocComment()),
            'start_line' => $node->getStartLine(),
            'end_line' => $node->getEndLine(),
            'parent' => null,
        ]);

        foreach ($extends as $target) {
            $this->result->relations[] = ['symbol' => $id, 'kind' => 'extends', 'target' => $target];
        }
        foreach ($implements as $target) {
            $this->result->relations[] = ['symbol' => $id, 'kind' => 'implements', 'target' => $target];
        }
        $this->addAttributes($id, $node->attrGroups);

        $this->classStack[] = ['id' => $id, 'fqn' => $fqn, 'parent' => $parent, 'props' => $this->propertyTypes($node)];
    }

    private function enterMethod(Stmt\ClassMethod $node): void
    {
        $class = $this->currentClass();
        if (null === $class['fqn']) {
            $this->functionStack[] = ['id' => $this->currentSymbolId(), 'locals' => $this->paramTypes($node->params)];

            return;
        }

        $name = $node->name->toString();
        $visibility = $node->isPrivate() ? 'private' : ($node->isProtected() ? 'protected' : 'public');
        $flags = array_values(array_filter([
            $node->isAbstract() ? 'abstract' : null,
            $node->isFinal() ? 'final' : null,
            $node->isStatic() ? 'static' : null,
        ]));

        $id = $this->result->addSymbol([
            'kind' => 'method',
            'fqn' => $class['fqn'].'::'.$name,
            'name' => $name,
            'namespace' => $this->namespace,
            'signature' => trim(implode(' ', [...$flags, $visibility]).' function '.$name.$this->functionSignature($node)),
            'visibility' => $visibility,
            'flags' => implode(' ', $flags),
            'doc' => $this->docSummary($node->getDocComment()),
            'start_line' => $node->getStartLine(),
            'end_line' => $node->getEndLine(),
            'parent' => $class['id'],
        ]);
        $this->addAttributes($id, $node->attrGroups);

        foreach ($node->params as $param) {
            if ($param->isPromoted()) {
                $this->addPromotedProperty($param, $class['id'], $class['fqn']);
            }
        }

        $this->functionStack[] = ['id' => $id, 'locals' => $this->paramTypes($node->params)];
        $this->addTypeRefs($id, [...array_map(static fn (Node\Param $p) => $p->type, $node->params), $node->returnType], $node->getStartLine());
    }

    private function enterFunction(Stmt\Function_ $node): void
    {
        $fqn = $node->namespacedName?->toString() ?? $node->name->toString();

        $id = $this->result->addSymbol([
            'kind' => 'function',
            'fqn' => $fqn,
            'name' => $node->name->toString(),
            'namespace' => $this->namespace,
            'signature' => 'function '.$fqn.$this->functionSignature($node),
            'visibility' => null,
            'flags' => '',
            'doc' => $this->docSummary($node->getDocComment()),
            'start_line' => $node->getStartLine(),
            'end_line' => $node->getEndLine(),
            'parent' => null,
        ]);
        $this->addAttributes($id, $node->attrGroups);

        $this->functionStack[] = ['id' => $id, 'locals' => $this->paramTypes($node->params)];
        $this->addTypeRefs($id, [...array_map(static fn (Node\Param $p) => $p->type, $node->params), $node->returnType], $node->getStartLine());
    }

    private function addProperty(Stmt\Property $node): void
    {
        $class = $this->currentClass();
        if (null === $class['fqn']) {
            return;
        }

        $modifiers = $this->modifiers($node->flags ?: Modifiers::PUBLIC);
        $type = $this->typeToString($node->type);
        foreach ($node->props as $prop) {
            $name = $prop->name->toString();
            $default = null === $prop->default ? '' : ' = '.$this->printValue($prop->default);
            $id = $this->result->addSymbol([
                'kind' => 'property',
                'fqn' => $class['fqn'].'::$'.$name,
                'name' => $name,
                'namespace' => $this->namespace,
                'signature' => trim($modifiers.' '.$type).' $'.$name.$default,
                'visibility' => $this->visibility($node->flags),
                'flags' => $this->extraFlags($node->flags),
                'doc' => $this->docSummary($node->getDocComment()),
                'start_line' => $prop->getStartLine(),
                'end_line' => $prop->getEndLine(),
                'parent' => $class['id'],
            ]);
            $this->addAttributes($id, $node->attrGroups);
            $this->addTypeRefs($id, [$node->type], $prop->getStartLine());
        }
    }

    private function addPromotedProperty(Node\Param $param, ?int $classId, string $classFqn): void
    {
        $name = (string) $param->var->name;
        $id = $this->result->addSymbol([
            'kind' => 'property',
            'fqn' => $classFqn.'::$'.$name,
            'name' => $name,
            'namespace' => $this->namespace,
            'signature' => trim($this->modifiers($param->flags).' '.$this->typeToString($param->type)).' $'.$name,
            'visibility' => $this->visibility($param->flags),
            'flags' => trim('promoted '.$this->extraFlags($param->flags)),
            'doc' => null,
            'start_line' => $param->getStartLine(),
            'end_line' => $param->getEndLine(),
            'parent' => $classId,
        ]);
        $this->addAttributes($id, $param->attrGroups);
    }

    private function addClassConstants(Stmt\ClassConst $node): void
    {
        $class = $this->currentClass();
        if (null === $class['fqn']) {
            return;
        }

        $visibility = $this->visibility($node->flags);
        foreach ($node->consts as $const) {
            $name = $const->name->toString();
            $type = null === $node->type ? '' : $this->typeToString($node->type).' ';
            $id = $this->result->addSymbol([
                'kind' => 'constant',
                'fqn' => $class['fqn'].'::'.$name,
                'name' => $name,
                'namespace' => $this->namespace,
                'signature' => trim(($node->isFinal() ? 'final ' : '').$visibility.' const '.$type.$name.' = '.$this->printValue($const->value)),
                'visibility' => $visibility,
                'flags' => $node->isFinal() ? 'final' : '',
                'doc' => $this->docSummary($node->getDocComment()),
                'start_line' => $const->getStartLine(),
                'end_line' => $const->getEndLine(),
                'parent' => $class['id'],
            ]);
            $this->addAttributes($id, $node->attrGroups);
        }
    }

    private function addEnumCase(Stmt\EnumCase $node): void
    {
        $class = $this->currentClass();
        if (null === $class['fqn']) {
            return;
        }

        $name = $node->name->toString();
        $id = $this->result->addSymbol([
            'kind' => 'case',
            'fqn' => $class['fqn'].'::'.$name,
            'name' => $name,
            'namespace' => $this->namespace,
            'signature' => 'case '.$name.(null === $node->expr ? '' : ' = '.$this->printValue($node->expr)),
            'visibility' => 'public',
            'flags' => '',
            'doc' => $this->docSummary($node->getDocComment()),
            'start_line' => $node->getStartLine(),
            'end_line' => $node->getEndLine(),
            'parent' => $class['id'],
        ]);
        $this->addAttributes($id, $node->attrGroups);
    }

    private function addTraitUse(Stmt\TraitUse $node): void
    {
        $class = $this->currentClass();
        if (null === $class['id']) {
            return;
        }
        foreach ($node->traits as $trait) {
            $this->result->relations[] = ['symbol' => $class['id'], 'kind' => 'uses_trait', 'target' => $trait->toString()];
        }
    }

    private function collectReference(Node $node): void
    {
        $line = $node->getStartLine();

        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            if ($node->name instanceof Identifier) {
                $this->addRef('call', $this->receiverType($node->var), $node->name->toString(), $line);
            }
        } elseif ($node instanceof Expr\StaticCall) {
            if ($node->class instanceof Name && $node->name instanceof Identifier) {
                $this->addRef('static_call', $this->resolveClassName($node->class), $node->name->toString(), $line);
            }
        } elseif ($node instanceof Expr\New_) {
            if ($node->class instanceof Name) {
                $this->addRef('new', $this->resolveClassName($node->class), null, $line);
            }
        } elseif ($node instanceof Expr\ClassConstFetch) {
            if ($node->class instanceof Name && $node->name instanceof Identifier) {
                $const = $node->name->toString();
                $isClassRef = 'class' === strtolower($const);
                $this->addRef($isClassRef ? 'class_ref' : 'const', $this->resolveClassName($node->class), $isClassRef ? null : $const, $line);
            }
        } elseif ($node instanceof Expr\StaticPropertyFetch) {
            if ($node->class instanceof Name && $node->name instanceof Node\VarLikeIdentifier) {
                $this->addRef('static_prop', $this->resolveClassName($node->class), '$'.$node->name->toString(), $line);
            }
        } elseif ($node instanceof Expr\Instanceof_) {
            if ($node->class instanceof Name) {
                $this->addRef('instanceof', $this->resolveClassName($node->class), null, $line);
            }
        } elseif ($node instanceof Stmt\Catch_) {
            foreach ($node->types as $type) {
                $this->addRef('catch', $this->resolveClassName($type), null, $line);
            }
        } elseif ($node instanceof Expr\FuncCall) {
            if ($node->name instanceof Name) {
                $this->addFunctionRef($node->name, $line);
            }
        } elseif ($node instanceof Expr\Assign) {
            // "$x = new Foo()" lets a later "$x->bar()" resolve to Foo::bar.
            if ($node->var instanceof Expr\Variable && \is_string($node->var->name) && [] !== $this->functionStack) {
                $type = $node->expr instanceof Expr\New_ && $node->expr->class instanceof Name
                    ? $this->resolveClassName($node->expr->class)
                    : null;
                $top = array_key_last($this->functionStack);
                if (null === $type) {
                    unset($this->functionStack[$top]['locals'][$node->var->name]);
                } else {
                    $this->functionStack[$top]['locals'][$node->var->name] = $type;
                }
            }
        } elseif ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            if ([] !== $this->functionStack) {
                $top = array_key_last($this->functionStack);
                $this->functionStack[$top]['locals'] = [...$this->functionStack[$top]['locals'], ...$this->paramTypes($node->params)];
            }
        }
    }

    private function addRef(string $kind, ?string $class, ?string $member, int $line): void
    {
        $this->result->refs[] = [
            'symbol' => $this->currentSymbolId(),
            'kind' => $kind,
            'target_class' => $class,
            'target_member' => $member,
            'line' => $line,
        ];
    }

    private function addFunctionRef(Name $name, int $line): void
    {
        // NameResolver can't know whether foo() means \Ns\foo or global \foo, so prefer whichever exists.
        $namespaced = $name->getAttribute('namespacedName');
        $candidate = $namespaced instanceof Name ? $namespaced->toString() : $name->toString();
        $global = $name->getLast();
        if ($namespaced instanceof Name && !function_exists($candidate) && function_exists($global)) {
            $candidate = $global;
        }
        if (\function_exists($candidate) && (new \ReflectionFunction($candidate))->isInternal()) {
            return; // builtins like count() or array_map() are noise for code navigation
        }
        $this->addRef('func', $candidate, null, $line);
    }

    /**
     * @param array<Node|null> $types
     */
    private function addTypeRefs(?int $symbolId, array $types, int $line): void
    {
        foreach ($types as $type) {
            foreach ($this->classTypes($type) as $class) {
                $this->result->refs[] = ['symbol' => $symbolId, 'kind' => 'type', 'target_class' => $class, 'target_member' => null, 'line' => $line];
            }
        }
    }

    /**
     * @param Node\AttributeGroup[] $groups
     */
    private function addAttributes(int $symbolId, array $groups): void
    {
        foreach ($groups as $group) {
            foreach ($group->attrs as $attr) {
                $args = array_map(
                    fn (Node\Arg $arg) => (null === $arg->name ? '' : $arg->name->toString().': ').$this->printer->prettyPrintExpr($arg->value),
                    $attr->args,
                );
                $this->result->attributes[] = [
                    'symbol' => $symbolId,
                    'name' => $attr->name->toString(),
                    'args' => mb_substr(implode(', ', $args), 0, 1000),
                ];
            }
        }
    }

    private function receiverType(Expr $var): ?string
    {
        $class = $this->currentClass();

        return match (true) {
            $var instanceof Expr\Variable && 'this' === $var->name => $class['fqn'],
            $var instanceof Expr\Variable && \is_string($var->name) => $this->currentLocals()[$var->name] ?? null,
            ($var instanceof Expr\PropertyFetch || $var instanceof Expr\NullsafePropertyFetch)
                && $var->var instanceof Expr\Variable && 'this' === $var->var->name
                && $var->name instanceof Identifier => $class['props'][$var->name->toString()] ?? null,
            $var instanceof Expr\StaticPropertyFetch && $var->name instanceof Node\VarLikeIdentifier
                && $var->class instanceof Name && \in_array(strtolower($var->class->toString()), ['self', 'static'], true)
                => $class['props'][$var->name->toString()] ?? null,
            $var instanceof Expr\New_ && $var->class instanceof Name => $this->resolveClassName($var->class),
            default => null,
        };
    }

    private function resolveClassName(Name $name): ?string
    {
        $class = $this->currentClass();

        return match (strtolower($name->toString())) {
            'self', 'static' => $class['fqn'],
            'parent' => $class['parent'],
            default => $name->toString(),
        };
    }

    /**
     * @return array<string, string> property name => class FQCN, for properties typed with exactly one class
     */
    private function propertyTypes(Stmt\ClassLike $node): array
    {
        $types = [];
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property) {
                $class = $this->classTypes($stmt->type)[0] ?? null;
                foreach ($stmt->props as $prop) {
                    if (null !== $class) {
                        $types[$prop->name->toString()] = $class;
                    }
                }
            } elseif ($stmt instanceof Stmt\ClassMethod && '__construct' === $stmt->name->toLowerString()) {
                foreach ($stmt->params as $param) {
                    $class = $this->classTypes($param->type)[0] ?? null;
                    if ($param->isPromoted() && null !== $class && $param->var instanceof Expr\Variable && \is_string($param->var->name)) {
                        $types[$param->var->name] = $class;
                    }
                }
            }
        }

        return $types;
    }

    /**
     * @param Node\Param[] $params
     *
     * @return array<string, string>
     */
    private function paramTypes(array $params): array
    {
        $types = [];
        foreach ($params as $param) {
            $class = $this->classTypes($param->type)[0] ?? null;
            if (null !== $class && $param->var instanceof Expr\Variable && \is_string($param->var->name)) {
                $types[$param->var->name] = $class;
            }
        }

        return $types;
    }

    /**
     * @return list<string> class names mentioned in a type declaration (builtins skipped)
     */
    private function classTypes(?Node $type): array
    {
        return match (true) {
            null === $type, $type instanceof Identifier => [],
            $type instanceof Node\NullableType => $this->classTypes($type->type),
            $type instanceof Node\UnionType, $type instanceof Node\IntersectionType => array_merge(...array_map($this->classTypes(...), $type->types)),
            $type instanceof Name => array_values(array_filter([$this->resolveClassName($type)])),
            default => [],
        };
    }

    private function typeToString(?Node $type): string
    {
        return match (true) {
            null === $type => '',
            $type instanceof Node\NullableType => '?'.$this->typeToString($type->type),
            $type instanceof Node\UnionType => implode('|', array_map(fn ($t) => $t instanceof Node\IntersectionType ? '('.$this->typeToString($t).')' : $this->typeToString($t), $type->types)),
            $type instanceof Node\IntersectionType => implode('&', array_map($this->typeToString(...), $type->types)),
            $type instanceof Name, $type instanceof Identifier => $type->toString(),
            default => '',
        };
    }

    private function functionSignature(Node\FunctionLike $node): string
    {
        $params = [];
        foreach ($node->getParams() as $param) {
            $promoted = $param->isPromoted() ? $this->modifiers($param->flags).' ' : '';
            $type = $this->typeToString($param->type);
            $name = $param->var instanceof Expr\Variable && \is_string($param->var->name) ? $param->var->name : '?';
            $params[] = $promoted
                .('' === $type ? '' : $type.' ')
                .($param->byRef ? '&' : '')
                .($param->variadic ? '...' : '')
                .'$'.$name
                .(null === $param->default ? '' : ' = '.$this->printValue($param->default));
        }

        $return = $this->typeToString($node->getReturnType());

        return '('.implode(', ', $params).')'.('' === $return ? '' : ': '.$return);
    }

    private function printValue(Expr $expr): string
    {
        $value = $this->printer->prettyPrintExpr($expr);
        $value = (string) preg_replace('/\s+/', ' ', $value);

        return mb_strlen($value) > self::MAX_VALUE_LENGTH ? mb_substr($value, 0, self::MAX_VALUE_LENGTH).'…' : $value;
    }

    private function docSummary(?Doc $doc): ?string
    {
        if (null === $doc) {
            return null;
        }

        $lines = preg_split('/\R/', $doc->getText()) ?: [];
        $summary = [];
        foreach ($lines as $line) {
            $line = trim((string) preg_replace('#^\s*(/\*\*|\*/|\*)#', '', $line));
            $line = trim((string) preg_replace('#\*/$#', '', $line));
            if (str_starts_with($line, '@')) {
                break;
            }
            if ('' === $line) {
                if ([] !== $summary) {
                    break; // the summary is the first paragraph
                }
                continue;
            }
            $summary[] = $line;
        }
        $text = implode(' ', $summary);

        return '' === $text ? null : mb_substr($text, 0, 300);
    }

    /**
     * @return list<string>
     */
    private function classFlags(Stmt\ClassLike $node): array
    {
        if (!$node instanceof Stmt\Class_) {
            return [];
        }

        return array_values(array_filter([
            $node->isAbstract() ? 'abstract' : null,
            $node->isFinal() ? 'final' : null,
            $node->isReadonly() ? 'readonly' : null,
        ]));
    }

    private function modifiers(int $flags): string
    {
        $parts = [$this->visibility($flags)];
        foreach ([Modifiers::PUBLIC_SET => 'public(set)', Modifiers::PROTECTED_SET => 'protected(set)', Modifiers::PRIVATE_SET => 'private(set)'] as $bit => $label) {
            if ($flags & $bit) {
                $parts[] = $label;
            }
        }
        if ($flags & Modifiers::STATIC) {
            $parts[] = 'static';
        }
        if ($flags & Modifiers::READONLY) {
            $parts[] = 'readonly';
        }

        return implode(' ', $parts);
    }

    private function visibility(int $flags): string
    {
        return match (true) {
            (bool) ($flags & Modifiers::PRIVATE) => 'private',
            (bool) ($flags & Modifiers::PROTECTED) => 'protected',
            default => 'public',
        };
    }

    private function extraFlags(int $flags): string
    {
        return implode(' ', array_filter([
            $flags & Modifiers::STATIC ? 'static' : null,
            $flags & Modifiers::READONLY ? 'readonly' : null,
        ]));
    }

    /**
     * @return array{id: ?int, fqn: ?string, parent: ?string, props: array<string, string>}
     */
    private function currentClass(): array
    {
        return $this->classStack[array_key_last($this->classStack) ?? -1] ?? ['id' => null, 'fqn' => null, 'parent' => null, 'props' => []];
    }

    /**
     * @return array<string, string>
     */
    private function currentLocals(): array
    {
        return [] === $this->functionStack ? [] : $this->functionStack[array_key_last($this->functionStack)]['locals'];
    }

    private function currentSymbolId(): ?int
    {
        if ([] !== $this->functionStack) {
            return $this->functionStack[array_key_last($this->functionStack)]['id'];
        }

        return $this->currentClass()['id'];
    }
}

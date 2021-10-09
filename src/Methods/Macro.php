<?php

declare(strict_types=1);

namespace NunoMaduro\Larastan\Methods;

use Carbon\Traits\Macro as CarbonMacro;
use Closure;
use ErrorException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Traits\Macroable;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\Php\BuiltinMethodReflection;
use PHPStan\TrinaryLogic;
use ReflectionClass;
use ReflectionFunction;
use ReflectionParameter;
use stdClass;

final class Macro implements BuiltinMethodReflection
{
    /**
     * The class name.
     *
     * @var class-string
     */
    private $className;

    /**
     * The method name.
     *
     * @var string
     */
    private $methodName;

    /**
     * The reflection function.
     *
     * @var ReflectionFunction
     */
    private $reflectionFunction;

    /**
     * The parameters.
     *
     * @var ReflectionParameter[]
     */
    private $parameters;

    /**
     * The is static.
     *
     * @var bool
     */
    private $isStatic = false;

    /**
     * Macro constructor.
     *
     * @param  string  $className
     * @phpstan-param class-string $className
     *
     * @param  string  $methodName
     * @param  ReflectionFunction  $reflectionFunction
     */
    public function __construct(string $className, string $methodName, ReflectionFunction $reflectionFunction)
    {
        $this->className = $className;
        $this->methodName = $methodName;
        $this->reflectionFunction = $reflectionFunction;
        $this->parameters = $this->reflectionFunction->getParameters();

        if ($this->reflectionFunction->isClosure()) {
            try {
                /** @var Closure $closure */
                $closure = $this->reflectionFunction->getClosure();
                // The bind method will return null on failure
                // If closure can be bound it was not explicitly marked as static
                $this->isStatic = Closure::bind($closure, new stdClass) === null;
            } catch (ErrorException $e) {
                // If warnings are converted to exceptions, then it'll throw instead of return null
                // The closure was explicitly marked as static
                $this->isStatic = true;
            }
        }
    }

    /**
     * {@inheritdoc}
     *
     * @phpstan-ignore-next-line
     */
    public function getDeclaringClass(): ReflectionClass
    {
        return new ReflectionClass($this->className);
    }

    /**
     * {@inheritdoc}
     */
    public function isPrivate(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isPublic(): bool
    {
        return true;
    }

    public function isFinal(): bool
    {
        return false;
    }

    public function isInternal(): bool
    {
        return false;
    }

    public function isAbstract(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isStatic(): bool
    {
        return $this->isStatic;
    }

    /**
     * Set the is static value.
     *
     * @param  bool  $isStatic
     * @return void
     */
    public function setIsStatic(bool $isStatic): void
    {
        $this->isStatic = $isStatic;
    }

    /**
     * {@inheritdoc}
     */
    public function getDocComment(): ?string
    {
        return $this->reflectionFunction->getDocComment() ?: null;
    }

    /**
     * {@inheritdoc}
     */
    public function getFileName()
    {
        return $this->reflectionFunction->getFileName();
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->methodName;
    }

    /**
     * {@inheritdoc}
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Set the parameters value.
     *
     * @param  ReflectionParameter[]  $parameters
     * @return void
     */
    public function setParameters(array $parameters): void
    {
        $this->parameters = $parameters;
    }

    /**
     * {@inheritdoc}
     */
    public function getReturnType(): ?\ReflectionType
    {
        return $this->reflectionFunction->getReturnType();
    }

    /**
     * {@inheritdoc}
     */
    public function getStartLine()
    {
        return $this->reflectionFunction->getStartLine();
    }

    /**
     * {@inheritdoc}
     */
    public function getEndLine()
    {
        return $this->reflectionFunction->getEndLine();
    }

    /**
     * {@inheritdoc}
     */
    public function isDeprecated(): TrinaryLogic
    {
        return TrinaryLogic::createFromBoolean($this->reflectionFunction->isDeprecated());
    }

    /**
     * {@inheritdoc}
     */
    public function isVariadic(): bool
    {
        return $this->reflectionFunction->isVariadic();
    }

    /**
     * {@inheritdoc}
     */
    public function getPrototype(): BuiltinMethodReflection
    {
        return $this;
    }

    public function getReflection(): ?\ReflectionMethod
    {
        return null;
    }

    private static function hasIndirectTraitUse(ClassReflection $class, string $traitName): bool
    {
        foreach ($class->getTraits() as $trait) {
            if (static::hasIndirectTraitUse($trait, $traitName)) {
                return true;
            }
        }

        return $class->hasTraitUse($traitName);
    }

    /**
     * If the passed in class supports macros, return the property that holds the macros
     * Otherwise, it'll return null.
     *
     * @param ClassReflection $classReflection
     * @return string|null
     */
    public static function getMacroProperty(ClassReflection $classReflection): ?string
    {
        if ($classReflection->hasTraitUse(Macroable::class) ||
            $classReflection->getName() === Builder::class) {
            return 'macros';
        }

        if (Macro::hasIndirectTraitUse($classReflection, CarbonMacro::class)) {
            return 'globalMacros';
        }

        return null;
    }

    /**
     * If the passed in class supports macros, return a Macro
     * Otherwise, it'll return null.
     *
     * @param ClassReflection $classReflection
     * @param string $methodName
     * @return self|null
     */
    public static function makeMacro(ClassReflection $classReflection, string $methodName): ?self
    {
        $macroProperty = self::getMacroProperty($classReflection);

        if ($macroProperty === null) {
            return null;
        }

        $className = $classReflection->getName();

        $refObject = new \ReflectionClass($className);
        $refProperty = $refObject->getProperty($macroProperty);
        $refProperty->setAccessible(true);

        $found = $className === Builder::class
            ? $className::hasGlobalMacro($methodName)
            : $className::hasMacro($methodName);

        if (!$found) {
            return null;
        }

        $reflectionFunction = new \ReflectionFunction($refProperty->getValue()[$methodName]);
        return new Macro($className, $methodName, $reflectionFunction);
    }
}

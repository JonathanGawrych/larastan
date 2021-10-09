<?php

declare(strict_types=1);

namespace NunoMaduro\Larastan\Analyser;

use function get_class;
use function gettype;
use function is_object;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use NunoMaduro\Larastan\Concerns;
use NunoMaduro\Larastan\Methods\Macro;
use NunoMaduro\Larastan\Properties\ReflectionTypeContainer;
use NunoMaduro\Larastan\Types\TypeResolver;
use PhpParser\Node\Expr;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\MutatingScope as BaseScope;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\ScopeContext;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\Analyser\TypeSpecifier;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Properties\PropertyReflectionFinder;
use PHPStan\ShouldNotHappenException;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicReturnTypeExtensionRegistry;
use PHPStan\Type\ObjectType;
use PHPStan\Type\OperatorTypeSpecifyingExtensionRegistry;
use PHPStan\Type\Type;
use PHPStan\Type\TypehintHelper;
use ReflectionClass;

/**
 * @internal
 */
class Scope extends BaseScope
{
    use Concerns\HasContainer;

    /**
     * $inFunctionCallsStack is private in the BaseScope so we have to reimplement the logic
     * @var array<MethodReflection|FunctionReflection>
     */
    private array $inFunctionCallsStack;

    /**
     * $reflectionProvider is private in the BaseScope so we have to save it for ourselves
     * @var ReflectionProvider
     */
    private ReflectionProvider $reflectionProvider;

    /**
     * {@inheritdoc}
     */
    public function __construct(
        ScopeFactory $scopeFactory,
        ReflectionProvider $reflectionProvider,
        DynamicReturnTypeExtensionRegistry $dynamicReturnTypeExtensionRegistry,
        OperatorTypeSpecifyingExtensionRegistry $operatorTypeSpecifyingExtensionRegistry,
        Standard $printer,
        TypeSpecifier $typeSpecifier,
        PropertyReflectionFinder $propertyReflectionFinder,
        Parser $parser,
        NodeScopeResolver $nodeScopeResolver,
        ScopeContext $context,
        bool $declareStrictTypes = false,
        array $constantTypes = [],
        $function = null,
        ?string $namespace = null,
        array $variablesTypes = [],
        array $moreSpecificTypes = [],
        array $conditionalExpressions = [],
        ?string $inClosureBindScopeClass = null,
        ?ParametersAcceptor $anonymousFunctionReflection = null,
        bool $inFirstLevelStatement = true,
        array $currentlyAssignedExpressions = [],
        array $nativeExpressionTypes = [],
        array $inFunctionCallsStack = [],
        array $dynamicConstantNames = [],
        bool $treatPhpDocTypesAsCertain = true,
        bool $objectFromNewClass = false,
        bool $afterExtractCall = false,
        ?BaseScope $parentScope = null
    )
    {
        $this->inFunctionCallsStack = $inFunctionCallsStack;
        $this->reflectionProvider = $reflectionProvider;

        parent::__construct(
            $scopeFactory,
            $reflectionProvider,
            $dynamicReturnTypeExtensionRegistry,
            $operatorTypeSpecifyingExtensionRegistry,
            $printer,
            $typeSpecifier,
            $propertyReflectionFinder,
            $parser,
            $nodeScopeResolver,
            $context,
            $declareStrictTypes,
            $constantTypes,
            $function,
            $namespace,
            $variablesTypes,
            $moreSpecificTypes,
            $conditionalExpressions,
            $inClosureBindScopeClass,
            $anonymousFunctionReflection,
            $inFirstLevelStatement,
            $currentlyAssignedExpressions,
            $nativeExpressionTypes,
            $inFunctionCallsStack,
            $dynamicConstantNames,
            $treatPhpDocTypesAsCertain,
            $objectFromNewClass,
            $afterExtractCall,
            $parentScope
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getType(Expr $node): Type
    {
        $type = parent::getType($node);

        if ($this->isContainer($type) && strpos(get_class($type), 'Mockery') !== 0) {
            $type = \Mockery::mock($type);
            $type->shouldReceive('isOffsetAccessible')
                ->andReturn(TrinaryLogic::createYes());
            $type->shouldReceive('hasOffsetValueType')
                ->andReturn(TrinaryLogic::createYes());
        }

        return $this->getContainer()
            ->make(TypeResolver::class)
            ->resolveFrom($type);
    }

    /**
     * {@inheritdoc}
     */
    protected function getTypeFromArrayDimFetch(
        Expr\ArrayDimFetch $arrayDimFetch,
        Type $offsetType,
        Type $offsetAccessibleType
    ): Type {
        if ($arrayDimFetch->dim === null) {
            throw new ShouldNotHappenException();
        }

        $parentType = parent::getTypeFromArrayDimFetch($arrayDimFetch, $offsetType, $offsetAccessibleType);
        if ($this->isContainer($offsetAccessibleType)) {
            $dimType = $this->getType($arrayDimFetch->dim);
            if (! $dimType instanceof ConstantStringType) {
                return $parentType;
            }

            $concrete = $this->resolve($dimType->getValue());

            if ($concrete === null) {
                return new \PHPStan\Type\NullType();
            }

            $type = is_object($concrete) ? get_class($concrete) : gettype($concrete);

            $reflectionType = new ReflectionTypeContainer($type);

            return TypehintHelper::decideTypeFromReflection(
                $reflectionType,
                null,
                is_object($concrete) ? get_class($concrete) : null
            );
        }

        return $parentType;
    }

    /**
     * Checks if the provided type implements
     * the Illuminate Container Contract.
     *
     * @param  \PHPStan\Type\Type  $type
     * @return bool
     *
     * @throws \ReflectionException
     */
    private function isContainer(Type $type): bool
    {
        /** @var class-string $referencedClass */
        foreach ($type->getReferencedClasses() as $referencedClass) {
            $isClassOrInterface = class_exists($referencedClass) || interface_exists($referencedClass);
            if ($isClassOrInterface && (new ReflectionClass($referencedClass))->implementsInterface(Container::class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function pushInFunctionCall($reflection): BaseScope
    {
        $stack = $this->inFunctionCallsStack;
        $stack[] = $reflection;

        return parent::pushInFunctionCall($reflection);
    }

    /**
     * When entering an anonymous function, we may be adding a macro to a class
     * that uses the Macroable trait. When called, if the macro is a Closure, it
     * will bind to the static::class and additionally $this if non-static.
     * In order for phpstan to not throw "Undefined Variable: $this" inside the
     * macro we will wrap the enterAnonymousFunction call with a enterClosureCall.
     *
     * {@inheritdoc}
     */
    public function enterAnonymousFunction(
        Expr\Closure $closure,
        ?array $callableParameters = null
    ): BaseScope
    {
        $currentFunctionCall = $this->inFunctionCallsStack[count($this->inFunctionCallsStack)-1];
        if ($currentFunctionCall instanceof MethodReflection && $currentFunctionCall->getName() === 'macro') {
            $classReflection = $currentFunctionCall->getDeclaringClass();
            if ($classReflection->isInterface() && Str::startsWith($classReflection->getName(), 'Illuminate\Contracts')) {
                $concrete = $this->resolve($classReflection->getName());
                if ($concrete !== null) {
                    $classReflection = $this->reflectionProvider->getClass(get_class($concrete));
                }
            }

            // If we can find a macro property on the class reflection, this support macros.
            // Enter the closure call scope so that the Closure will gain the correct $this.
            if (Macro::getMacroProperty($classReflection) !== null) {
                return $this->enterClosureCall(new ObjectType($classReflection->getName()))
                    ->enterAnonymousFunction($closure, $callableParameters);
            }
        }

        return parent::enterAnonymousFunction($closure, $callableParameters);
    }

    /**
     * {@inheritdoc}
     */
    public function popInFunctionCall(): BaseScope
    {
        $stack = $this->inFunctionCallsStack;
        array_pop($stack);

        return parent::popInFunctionCall();
    }
}

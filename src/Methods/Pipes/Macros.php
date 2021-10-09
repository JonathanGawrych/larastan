<?php

declare(strict_types=1);

namespace NunoMaduro\Larastan\Methods\Pipes;

use Closure;
use Illuminate\Support\Str;
use NunoMaduro\Larastan\Concerns;
use NunoMaduro\Larastan\Contracts\Methods\PassableContract;
use NunoMaduro\Larastan\Contracts\Methods\Pipes\PipeContract;
use NunoMaduro\Larastan\Methods\Macro;
use PHPStan\Type\Generic\TemplateTypeMap;

/**
 * @internal
 */
final class Macros implements PipeContract
{
    use Concerns\HasContainer;

    /**
     * {@inheritdoc}
     */
    public function handle(PassableContract $passable, Closure $next): void
    {
        $classReflection = $passable->getClassReflection();
        if ($classReflection->isInterface() && Str::startsWith($classReflection->getName(), 'Illuminate\Contracts')) {
            $concrete = $this->resolve($classReflection->getName());
            if ($concrete !== null) {
                $classReflection = $passable->getBroker()->getClass(get_class($concrete));
            }
        }

        $methodReflection = Macro::makeMacro($classReflection, $passable->getMethodName());

        if ($methodReflection !== null) {
            /** @var \PHPStan\Type\Type[] $parameters */
            $parameters = $methodReflection->getParameters();

            $passable->setMethodReflection(
                $passable->getMethodReflectionFactory()
                    ->create(
                        $passable->getClassReflection(),
                        null,
                        $methodReflection,
                        TemplateTypeMap::createEmpty(),
                        $parameters,
                        null,
                        null,
                        null,
                        false,
                        false,
                        false,
                        null
                    )
            );
        } else {
            $next($passable);
        }
    }
}

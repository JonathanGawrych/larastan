<?php

declare(strict_types=1);

namespace Tests\Features\Methods;

use Carbon\Carbon;

Carbon::macro('foo', static function (): string {
    return 'foo';
});

Carbon::macro('bar', function (): Carbon {
    /** @var Carbon $this */
    return $this->addDays(2);
});

class CarbonExtension
{
    public function testCarbonMacroCalledStatically(): string
    {
        return Carbon::foo();
    }

    public function testCarbonMacroCalledDynamically(): Carbon
    {
        return Carbon::now()->bar();
    }
}

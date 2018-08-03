<?php

namespace Jurager\Activity\Exceptions;

use Exception;
use Jurager\Activity\Models\Activity;

class InvalidConfiguration extends Exception
{
    public static function modelIsNotValid(string $className)
    {
        return new static("The given model class `$className` does not extend `".Activity::class.'`');
    }
}

<?php

namespace App\Support;

class FormulaDrillScope
{
    public const GLOBAL_BASICS = 'global_basics';

    public static function isGlobalBasics(?string $scope): bool
    {
        return $scope === self::GLOBAL_BASICS;
    }
}

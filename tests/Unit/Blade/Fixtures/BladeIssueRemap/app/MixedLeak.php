<?php

declare(strict_types=1);

namespace BladeIssueRemapFixture;

/** A model-like base whose magic `__call` lets a narrowed undeclared receiver reach UndefinedMagicMethod. */
class BaseModel
{
    /** @return mixed */
    public function __call(string $name, array $arguments)
    {
        return null;
    }
}

final class Book extends BaseModel
{
    public function getUrl(string $path = ''): string
    {
        return $path;
    }
}

final class Gate
{
    public static function userCan(string $ability, ?BaseModel $subject = null): bool
    {
        return $subject !== null;
    }
}

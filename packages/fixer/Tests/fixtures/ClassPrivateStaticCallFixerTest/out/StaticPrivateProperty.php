<?php

class StaticPrivateProperty
{
    private static string $foo = 'foo';

    public function execute()
    {
        echo self::$foo;
    }
}
<?php

final class Ids
{
    public static function generate(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(10));
    }
}

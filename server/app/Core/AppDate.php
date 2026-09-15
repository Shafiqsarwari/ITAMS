<?php

class AppDate
{
    public static function dateTime(mixed $value, string $fallback = '-'): string
    {
        $date = self::parse($value);
        return $date ? $date->format('Y-m-d h:i A') : $fallback;
    }

    public static function date(mixed $value, string $fallback = '-'): string
    {
        $date = self::parse($value);
        return $date ? $date->format('Y-m-d') : $fallback;
    }

    public static function nowDateTime(): string
    {
        return (new DateTimeImmutable('now'))->format('Y-m-d h:i A');
    }

    private static function parse(mixed $value): ?DateTimeImmutable
    {
        $value = trim((string)$value);
        if ($value === '' || $value === '-') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}

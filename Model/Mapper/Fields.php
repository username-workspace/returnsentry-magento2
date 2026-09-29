<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Mapper;

class Fields
{
    /**
     * @param array<mixed> $values
     * @return array<mixed>
     */
    public static function compact(array $values): array
    {
        $compacted = [];
        foreach ($values as $key => $value) {
            $value = is_array($value) ? self::compact($value) : $value;
            if ($value !== null && $value !== '' && $value !== []) {
                $compacted[$key] = $value;
            }
        }
        return $compacted;
    }

    public static function iso(?string $utcDateTime): ?string
    {
        if ($utcDateTime === null || $utcDateTime === '') {
            return null;
        }
        return (new \DateTimeImmutable($utcDateTime, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    public static function isoFrom(?\DateTimeInterface $dateTime): ?string
    {
        if ($dateTime === null) {
            return null;
        }
        return \DateTimeImmutable::createFromInterface($dateTime)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    public static function money(float|int|string|null $amount): float
    {
        return round(max(0.0, (float) $amount), 2);
    }

    public static function clip(?string $value, int $length): ?string
    {
        $trimmed = $value === null ? '' : trim($value);
        return $trimmed === '' ? null : mb_substr($trimmed, 0, $length);
    }
}

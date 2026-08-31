<?php

namespace OxygenSuite\AadeMyData\Mapping;

use BackedEnum;

final class Values
{
    /**
     * Enums travel as their backing value; everything else as-is.
     */
    public static function scalar(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    /**
     * The provider prohibits several boolean flags for some invoice types even
     * when false, so a flag is only ever sent when it is true.
     */
    public static function flag(?bool $value): ?bool
    {
        return $value === true ? true : null;
    }

    /**
     * Drop null values and empty arrays recursively; lists are re-indexed so
     * they still encode as JSON arrays.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public static function compact(array $data): array
    {
        $isList = array_is_list($data);
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = self::compact($value);

                if ($value === []) {
                    continue;
                }
            }

            if ($value === null) {
                continue;
            }

            $result[$key] = $value;
        }

        return $isList ? array_values($result) : $result;
    }
}

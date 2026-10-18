<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Util;

/**
 * PHP encodes an empty array as JSON `[]`, but a field a vendor types as an
 * object (a template model, a settings map) must be `{}` even when empty —
 * postmark-php special-cases exactly this (`empty($model) ? (object) [] : …`).
 *
 * @internal
 */
final class JsonObject
{
    /**
     * @param array<array-key,mixed> $value
     *
     * @return array<array-key,mixed>|\stdClass
     */
    public static function of(array $value): array|\stdClass
    {
        return $value === [] ? new \stdClass() : $value;
    }
}

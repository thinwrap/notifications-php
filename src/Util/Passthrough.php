<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Util;

/**
 * Static merge helper for the per-request `_passthrough` shape.
 *
 * Body is deep-merged (associative arrays merge recursively; numeric-indexed
 * arrays overwrite, last-write-wins). Headers and query are shallow-merged with
 * the consumer winning. Mirrors TS `mergePassthrough()` semantics verbatim.
 *
 * Keys are forwarded verbatim — no casing transform invoked here.
 */
final class Passthrough
{
    /**
     * Merge the consumer's `_passthrough` shape into the connector's own
     * request shape. Consumer-supplied keys win over connector-supplied keys
     * at the same path.
     *
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>} $base
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string>}|null $passthrough
     *
     * @return array{body:array<string,mixed>,headers:array<string,string>,query:array<string,string>}
     */
    public static function merge(#[\SensitiveParameter] array $base, #[\SensitiveParameter] ?array $passthrough): array
    {
        if ($passthrough === null) {
            return [
                'body'    => $base['body']    ?? [],
                'headers' => $base['headers'] ?? [],
                'query'   => $base['query']   ?? [],
            ];
        }

        return [
            'body'    => self::deepMerge($base['body']    ?? [], $passthrough['body']    ?? []),
            'headers' => array_merge($base['headers'] ?? [], $passthrough['headers'] ?? []),
            'query'   => array_merge($base['query']   ?? [], $passthrough['query']   ?? []),
        ];
    }

    /**
     * Recursively merge associative arrays; numeric-indexed arrays overwrite
     * (last-write-wins per a documented design decision).
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     *
     * @return array<string,mixed>
     */
    private static function deepMerge(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            if (
                is_array($value)
                && isset($a[$key])
                && is_array($a[$key])
                && self::isAssoc($value)
                && self::isAssoc($a[$key])
            ) {
                /** @var array<string,mixed> $aChild */
                $aChild = $a[$key];
                $a[$key] = self::deepMerge($aChild, $value);
            } else {
                $a[$key] = $value;
            }
        }

        return $a;
    }

    /**
     * @param array<int|string,mixed> $arr
     */
    private static function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}

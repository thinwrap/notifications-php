<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Util;

/**
 * Formats the single-string `Name <addr>` sender that SES, Resend, Postmark and
 * Mailgun take (the other email vendors take a separate `{email, name}` object).
 *
 * RFC 5322 §3.2.3/§3.4: a display-name is a `phrase` of atoms or quoted-strings,
 * so a name holding a special (`, . : ; @ ( ) < > [ ] \ "`) must be quoted —
 * `Acme, Inc. <a@x>` is two broken mailboxes, not one. RFC 2047 §5(3) lets a
 * non-ASCII name be one or more encoded-words, which "MUST NOT appear within a
 * quoted-string" and "may not be more than 75 characters long"; SES also
 * requires encoded-word syntax for a non-ASCII "friendly from" name.
 *
 * @internal
 */
final class Mailbox
{
    /** RFC 2047 §2: an encoded-word is at most 75 characters. */
    private const MAX_ENCODED_WORD = 75;

    /** `=?UTF-8?B?` + `?=`. */
    private const ENCODED_WORD_OVERHEAD = 12;

    /**
     * Build the mailbox string. `$address` may itself already be
     * `Name <addr>`; its own display name then wins over `$name`, because it is
     * the per-message value and wrapping it again would nest angle brackets.
     */
    public static function format(?string $name, string $address): string
    {
        $address = self::stripCrlf($address);
        if (preg_match('/^(.*)<([^<>]*)>$/sD', $address, $m) === 1) {
            $embedded = trim($m[1]);
            $address = trim($m[2]);
            if ($embedded !== '') {
                $name = self::unquote($embedded);
            }
        }

        $name = trim(self::stripCrlf($name ?? ''));
        if ($name === '') {
            return $address;
        }

        return self::displayName($name) . ' <' . $address . '>';
    }

    /** True when every byte is 7-bit ASCII. */
    public static function isAscii(string $value): bool
    {
        return preg_match('/[^\x00-\x7F]/', $value) !== 1;
    }

    private static function displayName(string $name): string
    {
        if (!self::isAscii($name)) {
            return self::encodedWords($name);
        }
        // atext (RFC 5322 §3.2.3) plus the spaces between atoms: a plain phrase.
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~ -]+$/D', $name) === 1) {
            return $name;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"';
    }

    /**
     * RFC 2047 `B` encoded-words for a value, split on UTF-8 character
     * boundaries so no word cuts a multi-byte character in half, each word
     * within the 75-character limit, joined by single spaces.
     */
    public static function encodedWords(string $name): string
    {
        // Base64 length is 4 per 3 bytes, so this is the largest payload whose
        // encoded form keeps the word within the limit.
        $maxBytes = intdiv(self::MAX_ENCODED_WORD - self::ENCODED_WORD_OVERHEAD, 4) * 3;

        $chars = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            // Not valid UTF-8: there are no character boundaries to respect.
            $chars = str_split($name);
        }

        $words = [];
        $chunk = '';
        foreach ($chars as $char) {
            if ($chunk !== '' && strlen($chunk) + strlen($char) > $maxBytes) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $char;
        }
        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }

        return implode(' ', $words);
    }

    /** Undo RFC 5322 quoting on an already-quoted display name. */
    private static function unquote(string $name): string
    {
        if (strlen($name) >= 2 && $name[0] === '"' && $name[strlen($name) - 1] === '"') {
            return (string) preg_replace('/\\\\(.)/s', '$1', substr($name, 1, -1));
        }

        return $name;
    }

    private static function stripCrlf(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }
}

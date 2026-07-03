<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\DTO\Email;

/**
 * Email attachment DTO. `$content` is either raw bytes (string) or a PHP
 * stream resource; the base class `normalizeAttachmentContent()` resolves a
 * resource to a string uniformly so every email connector accepts both.
 *
 * `$contentType` is nullable-with-default `null` to match TS's optional
 * `contentType?` — a `null` content type defers MIME sniffing to the vendor.
 * Connectors only emit the type field when it is a
 * non-empty string.
 */
final readonly class EmailAttachment
{
    /**
     * @param string|resource $content
     */
    public function __construct(
        public string $filename,
        public mixed $content,
        public ?string $contentType = null,
        public ?string $contentId = null,
    ) {}
}

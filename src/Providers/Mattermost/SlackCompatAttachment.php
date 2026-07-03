<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Mattermost;

/**
 * Slack-compatible attachment shape carried verbatim by Mattermost (and several
 * other webhook chat providers). Narrowed input keys are camelCase; the
 * connector hand-maps the 7 snake_case wire keys (`authorName→author_name`,
 * `authorLink→author_link`, `authorIcon→author_icon`, `titleLink→title_link`,
 * `imageUrl→image_url`, `thumbUrl→thumb_url`, `footerIcon→footer_icon`) via
 * `toWireAttachment` per casing-locality.
 */
final readonly class SlackCompatAttachment
{
    /**
     * @param list<array{title:string,value:string,short?:bool}>|null $fields
     */
    public function __construct(
        public ?string $color = null,
        public ?string $pretext = null,
        public ?string $authorName = null,
        public ?string $authorLink = null,
        public ?string $authorIcon = null,
        public ?string $title = null,
        public ?string $titleLink = null,
        public ?string $text = null,
        public ?array $fields = null,
        public ?string $imageUrl = null,
        public ?string $thumbUrl = null,
        public ?string $footer = null,
        public ?string $footerIcon = null,
        public int|string|null $timestamp = null,
        public ?string $fallback = null,
    ) {}
}

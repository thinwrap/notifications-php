<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Twilio;

/**
 * Twilio processing region for the REST API.
 *
 * Twilio: "`ie1` and `au1` are still valid as processing Region identifiers";
 * us1 is the default. br1, de1, jp1, sg1 and us2 are edge locations, not REST
 * processing regions — they "process in US1 — use `api.twilio.com`" — and the
 * `api.{region}.twilio.com` form stopped working on 2026-04-28. Those cases are
 * kept so existing code still compiles, and are rejected with `InvalidRequest`
 * before sending; pick a `TwilioEdge` instead.
 */
enum TwilioRegion: string
{
    case Us1 = 'us1';
    /** @deprecated Not a REST processing region; use TwilioEdge::Umatilla with Us1. */
    case Us2 = 'us2';
    case Ie1 = 'ie1';
    /** Twilio's product-availability page lists no Programmable Messaging for AU1. */
    case Au1 = 'au1';
    /** @deprecated Not a REST processing region; use TwilioEdge::SaoPaulo with Us1. */
    case Br1 = 'br1';
    /** @deprecated Not a REST processing region; use TwilioEdge::Frankfurt with Us1. */
    case De1 = 'de1';
    /** @deprecated Not a REST processing region; use TwilioEdge::Tokyo with Us1. */
    case Jp1 = 'jp1';
    /** @deprecated Not a REST processing region; use TwilioEdge::Singapore with Us1. */
    case Sg1 = 'sg1';
}

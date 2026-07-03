<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Enum;

/**
 * Canonical Thinwrap provider IDs spanning all 35 notification providers
 * across Email / SMS / Push / Chat. Values match Novu enum strings, with
 * `ms-teams` and `rocket-chat` using kebab-case.
 *
 * Channel membership is enforced by each facade's `match` arms — the enum
 * itself is channel-agnostic.
 */
enum NotificationProviderId: string
{
    // Email (10)
    case Ses = 'ses';
    case Resend = 'resend';
    case Mailgun = 'mailgun';
    case Sendgrid = 'sendgrid';
    case Postmark = 'postmark';
    case Mailersend = 'mailersend';
    case Mailtrap = 'mailtrap';
    case Brevo = 'brevo';
    case Sparkpost = 'sparkpost';
    case Scaleway = 'scaleway';

    // SMS (10)
    case Vonage = 'vonage';
    case Twilio = 'twilio';
    case Plivo = 'plivo';
    case Sns = 'sns';
    case Sinch = 'sinch';
    case Telnyx = 'telnyx';
    case Infobip = 'infobip';
    case Messagebird = 'messagebird';
    case Textmagic = 'textmagic';
    case D7networks = 'd7networks';

    // Push (6)
    case Fcm = 'fcm';
    case Expo = 'expo';
    case Apns = 'apns';
    case OneSignal = 'one-signal';
    case PusherBeams = 'pusher-beams';
    case Wonderpush = 'wonderpush';

    // Chat (9)
    case Telegram = 'telegram';
    case Slack = 'slack';
    case WhatsappBusiness = 'whatsapp-business';
    case Discord = 'discord';
    case Msteams = 'ms-teams';
    case GoogleChat = 'google-chat';
    case Mattermost = 'mattermost';
    case RocketChat = 'rocket-chat';
    case Line = 'line';
}

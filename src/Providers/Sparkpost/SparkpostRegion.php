<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Sparkpost;

/**
 * SparkPost regional cluster selector — US (default) vs EU.
 */
enum SparkpostRegion: string
{
    case Us = 'us';
    case Eu = 'eu';
}

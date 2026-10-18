<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Util;

use PHPUnit\Framework\TestCase;
use Thinwrap\Notifications\Providers\Fcm\FcmConfig;
use Thinwrap\Notifications\Providers\Sendgrid\SendgridConfig;
use Thinwrap\Notifications\Providers\Sendgrid\SendgridEmailConnector;
use Thinwrap\Notifications\Providers\Ses\SesConfig;
use Thinwrap\Notifications\Providers\Slack\SlackConfig;
use Thinwrap\Notifications\Util\RedactsSecrets;

/**
 * `#[\SensitiveParameter]` keeps credentials out of stack traces only; a
 * dumped config (or a connector holding one) must not print them either.
 */
final class RedactsSecretsTest extends TestCase
{
    public function testEveryProviderConfigRedactsItsSecrets(): void
    {
        $files = glob(__DIR__ . '/../../src/Providers/*/*Config.php');
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $class = 'Thinwrap\\Notifications\\Providers\\' . basename(dirname($file)) . '\\' . basename($file, '.php');
            self::assertContains(RedactsSecrets::class, class_uses($class), "{$class} must use RedactsSecrets");
        }
    }

    /**
     * @return iterable<string,array{object,string}>
     */
    public static function configs(): iterable
    {
        yield 'api key' => [new SendgridConfig(apiKey: 'SG.SECRET-1', from: 'a@example.com'), 'SECRET-1'];
        yield 'AWS secret + session token' => [new SesConfig(region: 'us-east-1', accessKeyId: 'AKIA', secretAccessKey: 'SECRET-2', sessionToken: 'SECRET-3'), 'SECRET-'];
        yield 'webhook URL' => [new SlackConfig(webhookUrl: 'https://hooks.slack.com/services/SECRET-4'), 'SECRET-4'];
        yield 'FCM private key, also the copied property' => [
            new FcmConfig(serviceAccount: ['project_id' => 'p', 'client_email' => 'e@p', 'private_key' => 'SECRET-5']),
            'SECRET-5',
        ];
        yield 'connector holding a config' => [new SendgridEmailConnector(new SendgridConfig(apiKey: 'SG.SECRET-6', from: 'a@example.com')), 'SECRET-6'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('configs')]
    public function testDumpsDoNotPrintSecrets(object $object, string $secret): void
    {
        ob_start();
        var_dump($object);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString($secret, print_r($object, true));
        self::assertStringNotContainsString($secret, $dump);
        self::assertStringContainsString('[redacted]', $dump);
    }
}

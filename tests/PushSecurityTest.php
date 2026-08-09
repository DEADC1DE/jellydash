<?php

declare(strict_types=1);

use Mk\Framework\Push\PushSubscriptionValidator;
use Mk\Framework\Push\WebPushSender;
use PHPUnit\Framework\TestCase;

final class PushSecurityTest extends TestCase
{
    public function testAcceptsNormalHttpsSubscription(): void
    {
        $this->assertTrue(PushSubscriptionValidator::isValid(
            'https://updates.push.services.mozilla.com/wpush/v2/example-token',
            $this->base64Url("\x04" . str_repeat('p', 64)),
            $this->base64Url(str_repeat('a', 16))
        ));
    }

    public function testRejectsUnsafeEndpointsAndMalformedKeys(): void
    {
        $publicKey = $this->base64Url("\x04" . str_repeat('p', 64));
        $auth = $this->base64Url(str_repeat('a', 16));

        $this->assertFalse(PushSubscriptionValidator::isValid('http://push.example.test/send', $publicKey, $auth));
        $this->assertFalse(PushSubscriptionValidator::isValid('https://user:pass@push.example.test/send', $publicKey, $auth));
        $this->assertFalse(PushSubscriptionValidator::isValid('https://push.example.test/send#fragment', $publicKey, $auth));
        $this->assertFalse(PushSubscriptionValidator::isValid('https://push.example.test/send', 'not-a-public-key', $auth));
        $this->assertFalse(PushSubscriptionValidator::isValid('https://push.example.test/send', $publicKey, 'not-an-auth-secret'));
    }

    public function testWebPushClientDisablesRedirects(): void
    {
        $options = (new \ReflectionClass(WebPushSender::class))
            ->getMethod('clientOptions')
            ->invoke(null);

        $this->assertIsArray($options);
        $this->assertArrayHasKey('allow_redirects', $options);
        $this->assertFalse($options['allow_redirects']);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

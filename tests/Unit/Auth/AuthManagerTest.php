<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Tests\Unit\Auth;

use Lumen\JsonRpc\Auth\AuthManager;
use Lumen\JsonRpc\Auth\AuthenticatorInterface;
use Lumen\JsonRpc\Auth\UserContext;
use PHPUnit\Framework\TestCase;

final class AuthManagerTest extends TestCase
{
    public function testDisabledByDefault(): void
    {
        $manager = new AuthManager();
        $this->assertFalse($manager->isEnabled());
    }

    public function testEnabledWhenConstructedWithTrue(): void
    {
        $manager = new AuthManager(true);
        $this->assertTrue($manager->isEnabled());
    }

    public function testAuthenticateReturnsNullWhenDisabled(): void
    {
        $authenticator = $this->createMock(AuthenticatorInterface::class);
        $authenticator->expects($this->never())->method('authenticate');

        $manager = new AuthManager(false);
        $manager->setAuthenticator($authenticator);
        $this->assertNull($manager->authenticate('some-token'));
    }

    public function testAuthenticateReturnsNullWhenNoAuthenticator(): void
    {
        $manager = new AuthManager(true);
        $this->assertNull($manager->authenticate('some-token'));
    }

    public function testAuthenticateDelegatesToAuthenticator(): void
    {
        $expectedContext = new UserContext('user-1', ['sub' => 'user-1'], ['admin']);
        $authenticator = $this->createMock(AuthenticatorInterface::class);
        $authenticator->method('authenticate')->with('valid-token')->willReturn($expectedContext);

        $manager = new AuthManager(true);
        $manager->setAuthenticator($authenticator);
        $result = $manager->authenticate('valid-token');

        $this->assertNotNull($result);
        $this->assertEquals('user-1', $result->userId);
        $this->assertEquals(['admin'], $result->roles);
    }

    public function testAuthenticateReturnsNullWhenAuthenticatorReturnsNull(): void
    {
        $authenticator = $this->createMock(AuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(null);

        $manager = new AuthManager(true);
        $manager->setAuthenticator($authenticator);
        $this->assertNull($manager->authenticate('bad-token'));
    }

    public function testSetAuthenticatorOverwritesPrevious(): void
    {
        $first = $this->createMock(AuthenticatorInterface::class);
        $first->method('authenticate')->willReturn(new UserContext('first', [], []));

        $second = $this->createMock(AuthenticatorInterface::class);
        $second->method('authenticate')->willReturn(new UserContext('second', [], []));

        $manager = new AuthManager(true);
        $manager->setAuthenticator($first);
        $this->assertEquals('first', $manager->authenticate('t')->userId);

        $manager->setAuthenticator($second);
        $this->assertEquals('second', $manager->authenticate('t')->userId);
    }
}

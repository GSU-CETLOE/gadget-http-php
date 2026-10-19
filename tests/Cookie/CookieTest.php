<?php

declare(strict_types=1);

namespace Gadget\Http\Tests\Cookie;

use Gadget\Http\Cookie\Cookie;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CookieTest extends TestCase
{
    private const EXPIRES_HEADER = 'Wed, 21 Oct 2015 07:28:00 GMT';

    private const EXPIRES_TIMESTAMP = 1445412480;


    /**
     * @return array<string,array{string,string}>
     */
    public static function cookiePathProvider(): array
    {
        return [
            'empty path' => ['', '/'],
            'relative path' => ['foo', '/'],
            'root' => ['/', '/'],
            'single segment' => ['/foo', '/'],
            'nested path' => ['/foo/bar', '/foo'],
            'trailing slash' => ['/foo/bar/', '/foo/bar'],
        ];
    }


    #[DataProvider('cookiePathProvider')]
    public function testGetCookiePathIsTheDirectoryOfTheRequestPath(string $uriPath, string $expected): void
    {
        self::assertSame($expected, Cookie::getCookiePath($uriPath));
    }


    public function testGetCookieKeyCombinesDomainPathAndName(): void
    {
        self::assertSame(
            'Domain=example.com; Path=/app; Name=sid;',
            Cookie::getCookieKey('example.com', '/app', 'sid')
        );
    }


    public function testFromStringParsesEveryStandardAttribute(): void
    {
        $cookie = Cookie::fromString(
            'sid=abc123; Domain=example.com; Path=/app; Max-Age=60; '
            . 'Expires=' . self::EXPIRES_HEADER . '; Secure; HttpOnly; SameSite=Lax'
        );

        self::assertSame('sid', $cookie->getName());
        self::assertSame('abc123', $cookie->getValue());
        self::assertSame('example.com', $cookie->getDomain());
        self::assertSame('/app', $cookie->getPath());
        self::assertSame(60, $cookie->getMaxAge());
        self::assertSame(self::EXPIRES_TIMESTAMP, $cookie->getExpires());
        self::assertTrue($cookie->getSecure());
        self::assertTrue($cookie->getHttpOnly());
        self::assertSame('Lax', $cookie->getValues()['SameSite'] ?? null);
    }


    public function testFromStringWithOnlyANameAndValueUsesDefaults(): void
    {
        $cookie = Cookie::fromString('sid=abc123');

        self::assertSame('sid', $cookie->getName());
        self::assertSame('abc123', $cookie->getValue());
        self::assertNull($cookie->getDomain());
        self::assertSame('/', $cookie->getPath());
        self::assertNull($cookie->getMaxAge());
        self::assertNull($cookie->getExpires());
        self::assertFalse($cookie->getSecure());
        self::assertFalse($cookie->getHttpOnly());
        self::assertFalse($cookie->isExpired());
    }


    public function testFromStringKeepsAnEqualsSignInsideTheValue(): void
    {
        $cookie = Cookie::fromString('token=a=b=c; Path=/');

        self::assertSame('a=b=c', $cookie->getValue());
    }


    public function testFromStringReadsAttributeNamesCaseInsensitively(): void
    {
        $cookie = Cookie::fromString('sid=abc; domain=Example.com; path=/x; max-age=5; secure; httponly');

        self::assertSame('Example.com', $cookie->getDomain());
        self::assertSame('/x', $cookie->getPath());
        self::assertSame(5, $cookie->getMaxAge());
        self::assertTrue($cookie->getSecure());
        self::assertTrue($cookie->getHttpOnly());
    }


    public function testMaxAgeAloneSetsExpiresRelativeToNow(): void
    {
        $before = time();
        $cookie = Cookie::fromString('sid=abc; Max-Age=60');

        self::assertSame(60, $cookie->getMaxAge());
        self::assertNotNull($cookie->getExpires());
        self::assertGreaterThanOrEqual($before + 60, $cookie->getExpires());
        self::assertLessThanOrEqual(time() + 60, $cookie->getExpires());
    }


    public function testMaxAgeDoesNotOverrideAnExplicitExpires(): void
    {
        $cookie = new Cookie(['Name' => 'sid', 'Expires' => self::EXPIRES_TIMESTAMP]);

        $cookie->setMaxAge(60);

        self::assertSame(self::EXPIRES_TIMESTAMP, $cookie->getExpires());
    }


    public function testSetMaxAgeNullLeavesExpiresUntouched(): void
    {
        $cookie = new Cookie(['Name' => 'sid']);

        $cookie->setMaxAge(null);

        self::assertNull($cookie->getMaxAge());
        self::assertNull($cookie->getExpires());
    }


    public function testSetExpiresAcceptsIntegersNumericStringsAndDateStrings(): void
    {
        $cookie = new Cookie(['Name' => 'sid']);

        self::assertSame(self::EXPIRES_TIMESTAMP, $cookie->setExpires(self::EXPIRES_TIMESTAMP)->getExpires());
        self::assertSame(self::EXPIRES_TIMESTAMP, $cookie->setExpires((string) self::EXPIRES_TIMESTAMP)->getExpires());
        self::assertSame(self::EXPIRES_TIMESTAMP, $cookie->setExpires(self::EXPIRES_HEADER)->getExpires());
        self::assertNull($cookie->setExpires('not a date')->getExpires());
        self::assertNull($cookie->setExpires(null)->getExpires());
    }


    public function testSetPathTreatsAnEmptyPathAsRoot(): void
    {
        $cookie = new Cookie(['Name' => 'sid', 'Path' => '/app']);

        self::assertSame('/', $cookie->setPath('')->getPath());
    }


    public function testGetKeyUsesAnEmptyDomainWhenNoneIsSet(): void
    {
        $cookie = new Cookie(['Name' => 'sid', 'Path' => '/app']);

        self::assertSame('Domain=; Path=/app; Name=sid;', $cookie->getKey());

        $cookie->setDomain('example.com');

        self::assertSame('Domain=example.com; Path=/app; Name=sid;', $cookie->getKey());
    }


    public function testSettersKeepTheValuesArrayInSync(): void
    {
        $cookie = new Cookie(['Name' => 'sid']);

        $cookie->setName('token')->setValue('xyz')->setDomain('example.com')->setSecure(true)->setHttpOnly(true);

        $values = $cookie->getValues();
        self::assertSame('token', $values['Name']);
        self::assertSame('xyz', $values['Value']);
        self::assertSame('example.com', $values['Domain']);
        self::assertTrue($values['Secure']);
        self::assertTrue($values['HttpOnly']);
    }


    public function testSetValuesReplacesEverythingAndKeepsUnknownAttributes(): void
    {
        $cookie = new Cookie(['Name' => 'sid', 'Value' => 'abc', 'Domain' => 'example.com']);

        $cookie->setValues(['Name' => 'other', 'SameSite' => 'Strict']);

        self::assertSame('other', $cookie->getName());
        self::assertNull($cookie->getValue());
        self::assertNull($cookie->getDomain());
        self::assertSame('Strict', $cookie->getValues()['SameSite'] ?? null);
    }


    public function testToStringRendersTheSetCookieForm(): void
    {
        $cookie = new Cookie([
            'Name' => 'sid',
            'Value' => 'abc123',
            'Domain' => 'example.com',
            'Path' => '/app',
            'Expires' => self::EXPIRES_TIMESTAMP,
            'Secure' => true,
            'HttpOnly' => true,
        ]);

        self::assertSame(
            'sid=abc123; Domain=example.com; Path=/app; Expires=' . self::EXPIRES_HEADER . '; Secure; HttpOnly',
            (string) $cookie
        );
    }


    public function testToStringSkipsNullAndFalseAttributes(): void
    {
        self::assertSame('sid=; Path=/', (string) new Cookie(['Name' => 'sid']));
    }


    public function testIsExpiredComparesExpiresToNow(): void
    {
        self::assertTrue((new Cookie(['Name' => 'sid', 'Expires' => self::EXPIRES_TIMESTAMP]))->isExpired());
        self::assertFalse((new Cookie(['Name' => 'sid', 'Expires' => time() + 3600]))->isExpired());
        self::assertFalse((new Cookie(['Name' => 'sid']))->isExpired());
    }


    public function testMatchesRequiresHttpsForASecureCookie(): void
    {
        $cookie = new Cookie(['Name' => 'sid', 'Domain' => 'example.com', 'Secure' => true]);

        self::assertTrue($cookie->matches('https', 'example.com', '/'));
        self::assertFalse($cookie->matches('http', 'example.com', '/'));
    }


    public function testMatchesRejectsAnExpiredCookie(): void
    {
        $cookie = new Cookie(['Name' => 'sid', 'Domain' => 'example.com', 'Expires' => self::EXPIRES_TIMESTAMP]);

        self::assertFalse($cookie->matches('https', 'example.com', '/'));
    }


    public function testMatchesDomainAcceptsTheDomainAndItsSubdomains(): void
    {
        $cookie = new Cookie(['Name' => 'sid', 'Domain' => 'example.com']);

        self::assertTrue($cookie->matchesDomain('example.com'));
        self::assertTrue($cookie->matchesDomain('EXAMPLE.com'));
        self::assertTrue($cookie->matchesDomain('api.example.com'));
        self::assertFalse($cookie->matchesDomain('example.org'));
        self::assertFalse($cookie->matchesDomain('notexample.com'));
    }


    public function testMatchesDomainIgnoresALeadingDot(): void
    {
        $cookie = new Cookie(['Name' => 'sid', 'Domain' => '.example.com']);

        self::assertTrue($cookie->matchesDomain('example.com'));
        self::assertTrue($cookie->matchesDomain('api.example.com'));
    }


    public function testMatchesDomainRequiresAnExactMatchForIpAddresses(): void
    {
        $cookie = new Cookie(['Name' => 'sid', 'Domain' => '0.0.1']);

        self::assertFalse($cookie->matchesDomain('10.0.0.1'));
        self::assertTrue((new Cookie(['Name' => 'sid', 'Domain' => '10.0.0.1']))->matchesDomain('10.0.0.1'));
    }


    public function testMatchesDomainWithNoDomainMatchesAnyHost(): void
    {
        self::assertTrue((new Cookie(['Name' => 'sid']))->matchesDomain('anything.test'));
    }


    public function testMatchesPathFollowsSegmentBoundaries(): void
    {
        $cookie = new Cookie(['Name' => 'sid', 'Path' => '/app']);

        self::assertTrue($cookie->matchesPath('/app'));
        self::assertTrue($cookie->matchesPath('/app/login'));
        self::assertFalse($cookie->matchesPath('/application'));
        self::assertFalse($cookie->matchesPath('/'));
    }


    public function testMatchesPathWithATrailingSlashMatchesChildren(): void
    {
        $cookie = new Cookie(['Name' => 'sid', 'Path' => '/app/']);

        self::assertTrue($cookie->matchesPath('/app/login'));
        self::assertFalse($cookie->matchesPath('/app'));
    }


    public function testRootPathMatchesEverything(): void
    {
        self::assertTrue((new Cookie(['Name' => 'sid']))->matchesPath('/deep/nested/path'));
    }
}

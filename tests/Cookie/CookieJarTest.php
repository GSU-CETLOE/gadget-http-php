<?php

declare(strict_types=1);

namespace Gadget\Http\Tests\Cookie;

use Gadget\Http\Cookie\Cookie;
use Gadget\Http\Cookie\CookieJar;
use PHPUnit\Framework\TestCase;

final class CookieJarTest extends TestCase
{
    /**
     * @param Cookie[] $cookies
     * @return string[]
     */
    private static function names(array $cookies): array
    {
        return array_values(array_map(fn(Cookie $cookie): string => $cookie->getName(), $cookies));
    }


    public function testConstructorAcceptsStringsArraysAndCookieObjects(): void
    {
        $jar = new CookieJar([
            'a=1; Domain=example.com; Path=/',
            ['Name' => 'b', 'Value' => '2', 'Domain' => 'example.com', 'Path' => '/'],
            new Cookie(['Name' => 'c', 'Value' => '3', 'Domain' => 'example.com', 'Path' => '/']),
        ]);

        self::assertCount(3, $jar->getCookies());
        self::assertSame('1', $jar->getCookie('example.com', '/', 'a')?->getValue());
        self::assertSame('2', $jar->getCookie('example.com', '/', 'b')?->getValue());
        self::assertSame('3', $jar->getCookie('example.com', '/', 'c')?->getValue());
    }


    public function testACookieWithNoExpiryGetsTheDefaultMaxAge(): void
    {
        $before = time();
        $jar = new CookieJar();

        $jar->setCookie('sid=abc; Domain=example.com');
        $cookie = $jar->getCookie('example.com', '/', 'sid');

        self::assertNotNull($cookie);
        self::assertSame(600, $cookie->getMaxAge());
        self::assertGreaterThanOrEqual($before + 600, $cookie->getExpires());
    }


    public function testTheDefaultMaxAgeIsConfigurable(): void
    {
        $jar = new CookieJar([], 30);

        $jar->setCookie('sid=abc; Domain=example.com');

        self::assertSame(30, $jar->getCookie('example.com', '/', 'sid')?->getMaxAge());
    }


    public function testANullDefaultMaxAgeLeavesSessionCookiesWithoutAnExpiry(): void
    {
        $jar = new CookieJar([], null);

        $jar->setCookie('sid=abc; Domain=example.com');

        self::assertNull($jar->getCookie('example.com', '/', 'sid')?->getExpires());
    }


    public function testAnExplicitExpiryIsKept(): void
    {
        $jar = new CookieJar();
        $expires = time() + 5;

        $jar->setCookie(new Cookie(['Name' => 'sid', 'Domain' => 'example.com', 'Expires' => $expires]));

        self::assertSame($expires, $jar->getCookie('example.com', '/', 'sid')?->getExpires());
    }


    public function testGetCookieReturnsNullWhenNothingMatchesTheKey(): void
    {
        $jar = new CookieJar(['sid=abc; Domain=example.com; Path=/app']);

        self::assertNull($jar->getCookie('example.com', '/', 'sid'));
        self::assertNull($jar->getCookie('other.com', '/app', 'sid'));
        self::assertNull($jar->getCookie('example.com', '/app', 'other'));
    }


    public function testSetCookieReturnsTrueForANewCookie(): void
    {
        $jar = new CookieJar();

        self::assertTrue($jar->setCookie('sid=abc; Domain=example.com'));
        self::assertCount(1, $jar->getCookies());
    }


    public function testSetCookieReplacesACookieWithALaterExpiry(): void
    {
        $jar = new CookieJar();
        $jar->setCookie(new Cookie([
            'Name' => 'sid',
            'Value' => 'abc',
            'Domain' => 'example.com',
            'Expires' => time() + 10,
        ]));
        $later = new Cookie([
            'Name' => 'sid',
            'Value' => 'abc',
            'Domain' => 'example.com',
            'Expires' => time() + 20,
        ]);

        self::assertTrue($jar->setCookie($later));
        self::assertSame($later, $jar->getCookie('example.com', '/', 'sid'));
    }


    public function testSetCookieReplacesACookieWhoseValueChanged(): void
    {
        $jar = new CookieJar();
        $expires = time() + 10;
        $jar->setCookie(new Cookie([
            'Name' => 'sid',
            'Value' => 'abc',
            'Domain' => 'example.com',
            'Expires' => $expires,
        ]));
        $changed = new Cookie([
            'Name' => 'sid',
            'Value' => 'xyz',
            'Domain' => 'example.com',
            'Expires' => $expires - 5,
        ]);

        self::assertTrue($jar->setCookie($changed));
        self::assertSame('xyz', $jar->getCookie('example.com', '/', 'sid')?->getValue());
    }


    public function testSetCookieIgnoresAnIdenticalCookieThatDoesNotExpireLater(): void
    {
        $jar = new CookieJar();
        $expires = time() + 10;
        $first = new Cookie(['Name' => 'sid', 'Value' => 'abc', 'Domain' => 'example.com', 'Expires' => $expires]);
        $jar->setCookie($first);
        $same = new Cookie(['Name' => 'sid', 'Value' => 'abc', 'Domain' => 'example.com', 'Expires' => $expires]);
        $older = new Cookie(['Name' => 'sid', 'Value' => 'abc', 'Domain' => 'example.com', 'Expires' => $expires - 5]);

        self::assertFalse($jar->setCookie($same));
        self::assertFalse($jar->setCookie($older));
        self::assertSame($first, $jar->getCookie('example.com', '/', 'sid'));
        self::assertCount(1, $jar->getCookies());
    }


    public function testCookiesWithDifferentPathsAreStoredSeparately(): void
    {
        $jar = new CookieJar();

        $jar->setCookie('sid=root; Domain=example.com; Path=/');
        $jar->setCookie('sid=app; Domain=example.com; Path=/app');

        self::assertCount(2, $jar->getCookies());
        self::assertSame('root', $jar->getCookie('example.com', '/', 'sid')?->getValue());
        self::assertSame('app', $jar->getCookie('example.com', '/app', 'sid')?->getValue());
    }


    public function testIteratingYieldsCookiesKeyedByTheirCookieKey(): void
    {
        $jar = new CookieJar(['sid=abc; Domain=example.com; Path=/']);

        $entries = iterator_to_array($jar);

        self::assertSame(['Domain=example.com; Path=/; Name=sid;'], array_keys($entries));
        self::assertSame('abc', $entries['Domain=example.com; Path=/; Name=sid;']->getValue());
    }


    public function testGetMatchesFiltersBySchemeHostAndPath(): void
    {
        $jar = new CookieJar([
            'plain=1; Domain=example.com; Path=/',
            'secure=1; Domain=example.com; Path=/; Secure',
            'scoped=1; Domain=example.com; Path=/app',
            'elsewhere=1; Domain=example.org; Path=/',
        ]);

        self::assertSame(
            ['plain', 'secure', 'scoped'],
            self::names($jar->getMatches('https', 'example.com', '/app/x'))
        );
        self::assertSame(['plain', 'scoped'], self::names($jar->getMatches('http', 'example.com', '/app')));
        self::assertSame(['plain', 'secure'], self::names($jar->getMatches('https', 'api.example.com', '/')));
        self::assertSame(['elsewhere'], self::names($jar->getMatches('http', 'example.org', '/')));
    }


    public function testGetMatchesExcludesExpiredCookies(): void
    {
        $jar = new CookieJar([
            new Cookie(['Name' => 'old', 'Domain' => 'example.com', 'Expires' => time() - 1]),
            new Cookie(['Name' => 'fresh', 'Domain' => 'example.com', 'Expires' => time() + 60]),
        ]);

        $matches = $jar->getMatches('https', 'example.com', '/');

        self::assertCount(1, $matches);
        self::assertSame('fresh', array_values($matches)[0]->getName());
    }


    public function testClearExpiredDropsExpiredCookiesAndReturnsTheJar(): void
    {
        $jar = new CookieJar([
            new Cookie(['Name' => 'old', 'Domain' => 'example.com', 'Expires' => time() - 1]),
            new Cookie(['Name' => 'fresh', 'Domain' => 'example.com', 'Expires' => time() + 60]),
        ]);

        self::assertSame($jar, $jar->clearExpired());
        self::assertCount(1, $jar->getCookies());
        self::assertNotNull($jar->getCookie('example.com', '/', 'fresh'));
    }


    public function testClearExpiredDropsCookiesThatHaveNoExpiryAtAll(): void
    {
        $jar = new CookieJar(['session=abc; Domain=example.com'], null);

        $jar->clearExpired();

        self::assertSame([], $jar->getCookies());
    }
}

<?php

declare(strict_types=1);

namespace Gadget\Http\Cookie;

use Gadget\Io\Cast;

class Cookie
{
    /**
     * @param string $uriPath
     * @return string
     */
    public static function getCookiePath(string $uriPath): string
    {
        if ('' === $uriPath) {
            return '/';
        }
        if (0 !== \strpos($uriPath, '/')) {
            return '/';
        }
        if ('/' === $uriPath) {
            return '/';
        }
        $lastSlashPos = \strrpos($uriPath, '/');
        if (0 === $lastSlashPos || false === $lastSlashPos) {
            return '/';
        }
        return \substr($uriPath, 0, $lastSlashPos);
    }


    /**
     * @param string $domain
     * @param string $path
     * @param string $name
     * @return string
     */
    public static function getCookieKey(
        string $domain,
        string $path,
        string $name
    ): string {
        return sprintf(
            "Domain=%s; Path=%s; Name=%s;",
            $domain,
            $path,
            $name
        );
    }


    /**
     * Create a new SetCookie object from a string.
     *
     * @param string $cookie Set-Cookie header string
     */
    public static function fromString(string $cookie): self
    {
        $pieces = self::getPieces($cookie);
        return new self([
            'Name' => $pieces['Name'][2] ?? '',
            'Value' => $pieces['Value'][2] ?? null,
            'Domain' => $pieces['Domain'][2] ?? null,
            'Path' => $pieces['Path'][2] ?? null,
            'Max-Age' => $pieces['Max-age'][2] ?? null,
            'Expires' => $pieces['Expires'][2] ?? null,
            'Secure' => isset($pieces['Secure']),
            'HttpOnly' => isset($pieces['Httponly']),
        ] + array_column($pieces, 2, 0));
    }


    /**
     * @param string $cookie
     * @return array<string,array{string,string,string|null}>
     */
    private static function getPieces(string $cookie): array
    {
        // Explode the cookie string using a series of semicolons
        /** @var array{string,string,string|null}[] $pieces */
        $pieces = array_map(
            fn (array $part) => [
                trim($part[0]),
                ucfirst(strtolower(trim($part[0]))),
                isset($part[1]) ? trim($part[1], " \n\r\t\0\x0B") : null
            ],
            array_map(
                fn(string $part) => explode('=', $part, 2),
                array_filter(
                    array_map(trim(...), explode(';', $cookie)),
                    fn(string $v) => $v !== ''
                )
            )
        );

        // The name of the cookie (first kvp) must exist and include an equal sign.
        list($name, ,$value) = array_shift($pieces) ?? [null, null, null];
        return [
            'Name' => ['Name', 'Name', $name],
            'Value' => ['Value', 'Value', $value]
        ] + array_column($pieces, null, 1);
    }


    private string $name = '';
    private string|null $value = null;
    private string|null $domain = null;
    private string $path = '/';
    private int|null $maxAge = null;
    private int|null $expires = null;
    private bool $secure = false;
    private bool $httpOnly = false;

    /**
     * @var array<string,string|int|bool|null> $values
     */
    private array $values = [];


    /**
     * @param array<string,int|string|bool|null> $values
     */
    public function __construct(array $values)
    {
        $this->setValues($values);
    }


    /**
     * @return string
     */
    public function getKey(): string
    {
        return self::getCookieKey(
            $this->getDomain() ?? '',
            $this->getPath(),
            $this->getName()
        );
    }


    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }


    /**
     * @param string $name
     * @return static
     */
    public function setName(string $name): static
    {
        $this->values['Name'] = $this->name = $name;
        return $this;
    }


    /**
     * @return string|null
     */
    public function getValue(): string|null
    {
        return $this->value;
    }


    /**
     * @param string|null $value
     * @return static
     */
    public function setValue(string|null $value): static
    {
        $this->values['Value'] = $this->value = $value;
        return $this;
    }


    /**
     * @return string|null
     */
    public function getDomain(): string|null
    {
        return $this->domain;
    }


    /**
     * @param string|null $domain
     * @return static
     */
    public function setDomain(string|null $domain): static
    {
        $this->values['Domain'] = $this->domain = $domain;
        return $this;
    }


    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }


    /**
     * @param string $path
     */
    public function setPath(string $path): static
    {
        if ($path === '') {
            $path = '/';
        }
        $this->values['Path'] = $this->path = $path;
        return $this;
    }


    /**
     * @return int|null
     */
    public function getMaxAge(): int|null
    {
        return $this->maxAge;
    }


    /**
     * @param int|null $maxAge
     * @return static
     */
    public function setMaxAge(int|null $maxAge): static
    {
        $this->values['Max-Age'] = $this->maxAge = $maxAge;
        return ($maxAge !== null) && ($this->getExpires() === null)
            ? $this->setExpires(time() + $maxAge)
            : $this;
    }


    /**
     * @return int|null
     */
    public function getExpires(): int|null
    {
        return $this->expires;
    }


    /**
     * @param int|string|null $timestamp
     */
    public function setExpires(int|string|null $timestamp): static
    {
        $timestamp = match (true) {
            is_numeric($timestamp) => intval($timestamp),
            is_string($timestamp) => strtotime($timestamp),
            default => null
        };
        $this->values['Expires'] = $this->expires = is_int($timestamp) ? $timestamp : null;
        return $this;
    }


    /**
     * @return bool
     */
    public function getSecure()
    {
        return $this->secure;
    }


    /**
     * @param bool $secure
     * @return static
     */
    public function setSecure(bool $secure): static
    {
        $this->values['Secure'] = $this->secure = $secure;
        return $this;
    }


    /**
     * @return bool
     */
    public function getHttpOnly()
    {
        return $this->httpOnly;
    }


    /**
     * @param bool $httpOnly
     * @return static
     */
    public function setHttpOnly(bool $httpOnly): static
    {
        $this->values['HttpOnly'] = $this->httpOnly = $httpOnly;
        return $this;
    }


    /**
     * @return array<string,string|int|bool|null>
     */
    public function getValues(): array
    {
        return $this->values;
    }


    /**
     * @param array<string,string|int|bool|null> $values
     * @return static
     */
    public function setValues(array $values): static
    {
        $this->values = [];
        $this
            ->setName(Cast::toValueOrNull($values['Name'] ?? null, Cast::toString(...)) ?? '')
            ->setValue(Cast::toValueOrNull($values['Value'] ?? null, Cast::toString(...)))
            ->setDomain(Cast::toValueOrNull($values['Domain'] ?? null, Cast::toString(...)))
            ->setPath(Cast::toValueOrNull($values['Path'] ?? null, Cast::toString(...)) ?? '/')
            ->setExpires(Cast::toValueOrNull($values['Expires'] ?? null, Cast::toString(...)))
            ->setMaxAge(Cast::toValueOrNull($values['Max-Age'] ?? null, Cast::toInt(...)))
            ->setSecure(Cast::toValueOrNull($values['Secure'] ?? null, Cast::toBool(...)) === true)
            ->setHttpOnly(Cast::toValueOrNull($values['HttpOnly'] ?? null, Cast::toBool(...)) === true)
            ;

        /** @var string[] $keys */
        $keys = array_keys($this->values);
        $this->values += array_filter(
            $values,
            fn(string $k) => !in_array($k, $keys, true),
            ARRAY_FILTER_USE_KEY
        );

        return $this;
    }


    /**
     * @return string
     */
    public function __toString(): string
    {
        $str = $this->getName() . '=' . ($this->getValue() ?? '') . '; ';

        foreach ($this->values as $k => $v) {
            if ($k !== 'Name' && $k !== 'Value' && $v !== null && $v !== false) {
                if ($k === 'Expires') {
                    $str .= 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', intval($v)) . '; ';
                } else {
                    $str .= ($v === true ? $k : "{$k}={$v}") . '; ';
                }
            }
        }

        return rtrim($str, '; ');
    }


    /**
     * @return bool
     */
    public function isExpired(): bool
    {
        return ($this->getExpires() ?? PHP_INT_MAX) <= time();
    }


    /**
     * @param string $scheme
     * @param string $host
     * @param string $path
     * @return bool
     */
    public function matches(
        string $scheme,
        string $host,
        string $path
    ): bool {
        return !$this->isExpired()
            && (!$this->getSecure() || $scheme === 'https')
            && $this->matchesDomain($host)
            && $this->matchesPath($path);
    }


    /**
     * @param string $domain
     * @return bool
     */
    public function matchesDomain(string $domain): bool
    {
        $cookieDomain = ltrim(strtolower($this->getDomain() ?? ''), '.');
        $domain = strtolower($domain);
        return $cookieDomain === '' || $cookieDomain === $domain || (
            filter_var($domain, \FILTER_VALIDATE_IP) === false &&
            preg_match('/\.' . preg_quote($cookieDomain, '/') . '$/', $domain) === 1
        );
    }


    /**
     * @param string $path
     * @return bool
     */
    public function matchesPath(string $path): bool
    {
        $cookiePath = $this->getPath();
        return $cookiePath === '/' || $cookiePath === $path || (
            strpos($path, $cookiePath) === 0 && (
                substr($cookiePath, -1, 1) === '/' ||
                substr($path, strlen($cookiePath), 1) === '/'
            )
        );
    }
}

<?php

/**
 * PHP Domain Parser: Public Suffix List based URL parsing.
 *
 * @see http://github.com/jeremykendall/php-domain-parser for the canonical source repository
 *
 * @copyright Copyright (c) 2017 Jeremy Kendall (http://jeremykendall.net)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Pdp\Storage\Cache;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Pdp\PublicSuffixList;
use Pdp\Rules;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use TypeError;
use function filter_var;
use function get_class;
use function gettype;
use function is_object;
use function is_string;
use function json_encode;
use function md5;
use function sprintf;
use function strtolower;
use const FILTER_VALIDATE_INT;

final class PublicSuffixListCachePsr16Adapter implements PublicSuffixListCache
{
    private const CACHE_PREFIX = 'PSL_FULL_';

    private CacheInterface $cache;

    private LoggerInterface $logger;

    private ?DateInterval $ttl;

    private string $prefix;

    /**
     * @param mixed|null $ttl the time to live for the given cache
     */
    public function __construct(CacheInterface $cache, $ttl = null, LoggerInterface $logger = null, string $prefix = self::CACHE_PREFIX)
    {
        $this->cache = $cache;
        $this->ttl = $this->setTtl($ttl);
        $this->logger = $logger ?? new NullLogger();
        $this->prefix = $prefix;
    }

    /**
     * Set the cache TTL.
     *
     * @param mixed $ttl the cache TTL
     *
     * @throws TypeError if the value type is not recognized
     */
    private function setTtl($ttl): ?DateInterval
    {
        if ($ttl instanceof DateInterval || null === $ttl) {
            return $ttl;
        }

        if ($ttl instanceof DateTimeInterface) {
            return (new DateTimeImmutable('NOW', $ttl->getTimezone()))->diff($ttl);
        }

        if (false !== ($res = filter_var($ttl, FILTER_VALIDATE_INT))) {
            return new DateInterval('PT'.$res.'S');
        }

        if (!is_string($ttl)) {
            throw new TypeError(sprintf(
                'The ttl must null, an integer, a string a DateTimeInterface or a DateInterval object %s given',
                is_object($ttl) ? get_class($ttl) : gettype($ttl)
            ));
        }

        /** @var DateInterval|false $date */
        $date = @DateInterval::createFromDateString($ttl);
        if (!$date instanceof DateInterval) {
            throw new Psr16CacheException(sprintf(
                'The ttl value "%s" can not be parsable by `DateInterval::createFromDateString`',
                $ttl
            ));
        }

        return $date;
    }

    /**
     * Gets the Public Suffix List Rules.
     */
    public function fetchByUri(string $uri): ?Rules
    {
        $cacheKey = $this->getCacheKey($uri);
        $cacheData = $this->cache->get($cacheKey);
        if (null === $cacheData) {
            return $cacheData;
        }

        try {
            $rules = Rules::fromJsonString($cacheData);
        } catch (Throwable $exception) {
            $this->cache->delete($cacheKey);
            $this->logger->warning($exception->getMessage());

            return null;
        }

        return $rules;
    }

    /**
     * Returns the cache key according to the source URL.
     */
    private function getCacheKey(string $str): string
    {
        return $this->prefix.md5(strtolower($str));
    }

    /**
     * Cache the Top Level Domain List.
     */
    public function storeByUri(string $uri, PublicSuffixList $publicSuffixList): bool
    {
        try {
            $result = $this->cache->set($this->getCacheKey($uri), json_encode($publicSuffixList), $this->ttl);
        } catch (Throwable $exception) {
            $this->logger->info(
                'The Public Suffix List could not be saved with the following URI: `'.$uri.'`:'.$exception->getMessage(),
                ['exception' => $exception]
            );

            return false;
        }

        $message = 'The Public Suffix List is stored for the following URI: `'.$uri.'`.';
        if (!$result) {
            $message = 'The Public Suffix List could not be stored for the following URI: `'.$uri.'`.';
        }

        $this->logger->info($message);

        return $result;
    }
}
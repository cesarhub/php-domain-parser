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

namespace Pdp;

use JsonSerializable;

interface PublicSuffixList extends IDNConversion, JsonSerializable
{
    /**
     * Returns an array representation of the Public Suffix List Rules JSON serializable.
     */
    public function jsonSerialize(): array;

    /**
     * Returns PSL info for a given domain.
     *
     * If the effective TLD can not be resolved it returns a ResolvedDomainName with a null public suffix
     * If the host is not a valid domaine it returns a ResolvedDomainName with a null Domain name
     */
    public function resolve(Host $host): ResolvedDomainName;

    /**
     * Returns PSL info for a given domain against the PSL rules for cookie domain detection.
     *
     * @throws InvalidDomainName     if the domain is invalid
     * @throws UnableToResolveDomain if the domain or the TLD are not resolvable of not resolved
     */
    public function getCookieDomain(Host $host): ResolvedDomainName;

    /**
     * Returns PSL info for a given domain against the PSL rules for ICANN domain detection.
     *
     * @throws InvalidDomainName     if the domain is invalid
     * @throws UnableToResolveDomain if the domain or the TLD are not resolvable of not resolved
     */
    public function getICANNDomain(Host $host): ResolvedDomainName;

    /**
     * Returns PSL info for a given domain against the PSL rules for private domain detection.
     *
     * @throws InvalidDomainName     if the domain is invalid
     * @throws UnableToResolveDomain if the domain or the TLD are not resolvable of not resolved
     */
    public function getPrivateDomain(Host $host): ResolvedDomainName;
}
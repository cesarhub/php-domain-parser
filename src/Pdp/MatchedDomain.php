<?php
/**
 * PHP Domain Parser: Public Suffix List based URL parsing.
 *
 * @see http://github.com/jeremykendall/php-domain-parser for the canonical source repository
 *
 * @copyright Copyright (c) 2017 Jeremy Kendall (http://jeremykendall.net)
 * @license   http://github.com/jeremykendall/php-domain-parser/blob/master/LICENSE MIT License
 */
declare(strict_types=1);

namespace Pdp;

final class MatchedDomain implements Domain
{
    use LabelsTrait;

    /**
     * @var string
     */
    private $domain;

    /**
     * @var string
     */
    private $publicSuffix;

    /**
     * @var bool
     */
    private $isValid;

    /**
     * New instance
     *
     * @param string|null $domain
     * @param string|null $publicSuffix
     * @param bool        $isValid
     */
    public function __construct(string $domain = null, string $publicSuffix = null, bool $isValid = true)
    {
        $this->domain = $domain;
        $this->publicSuffix = $publicSuffix;
        $this->isValid = $isValid;
    }

    /**
     * @inheritdoc
     */
    public function getDomain()
    {
        return $this->domain;
    }

    /**
     * @inheritdoc
     */
    public function getPublicSuffix()
    {
        return $this->publicSuffix;
    }

    /**
     * @inheritdoc
     */
    public function isValid(): bool
    {
        return $this->isValid;
    }

    /**
     * @inheritdoc
     */
    public function getRegistrableDomain()
    {
        if (!$this->hasRegistrableDomain()) {
            return null;
        }

        $publicSuffixLabels = $this->getLabels($this->publicSuffix);
        $domainLabels = $this->getLabels($this->domain);
        $additionalLabel = $this->getAdditionalLabel($domainLabels, $publicSuffixLabels);

        return implode('.', array_merge($additionalLabel, $publicSuffixLabels));
    }

    /**
     * Tells whether the domain has a registrable domain part
     *
     * @return bool
     */
    private function hasRegistrableDomain(): bool
    {
        if (!$this->hasLabels($this->domain)) {
            return false;
        }

        if ($this->publicSuffix === null) {
            return false;
        }

        if ($this->publicSuffix === $this->domain) {
            return false;
        }

        return true;
    }

    /**
     * Returns the additional label to generate the registrable domain
     *
     * @param string[] $domainLabels
     * @param string[] $publicSuffixLabels
     *
     * @return string[]
     */
    private function getAdditionalLabel($domainLabels, $publicSuffixLabels): array
    {
        return array_slice($domainLabels, count($domainLabels) - count($publicSuffixLabels) - 1, 1);
    }
}
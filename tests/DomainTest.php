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

namespace Pdp\Tests;

use Pdp\Domain;
use Pdp\Exception\CouldNotResolvePublicSuffix;
use Pdp\Exception\CouldNotResolveSubDomain;
use Pdp\Exception\InvalidDomain;
use Pdp\Exception\InvalidLabel;
use Pdp\Exception\InvalidLabelKey;
use Pdp\PublicSuffix;
use Pdp\Rules;
use PHPUnit\Framework\TestCase;
use TypeError;
use function date_create;
use const IDNA_NONTRANSITIONAL_TO_ASCII;
use const IDNA_NONTRANSITIONAL_TO_UNICODE;

/**
 * @coversDefaultClass \Pdp\Domain
 */
class DomainTest extends TestCase
{
    /**
     * @covers ::__construct
     * @covers ::setPublicSuffix
     * @covers ::setRegistrableDomain
     * @covers ::setSubDomain
     * @covers ::getPublicSuffix
     * @covers ::getRegistrableDomain
     * @covers ::getSubDomain
     */
    public function testRegistrableDomainIsNullWithFoundDomain(): void
    {
        $domain = new Domain('faketld', null);
        self::assertNull($domain->getPublicSuffix());
        self::assertNull($domain->getRegistrableDomain());
        self::assertNull($domain->getSubDomain());
    }

    /**
     * @covers ::__construct
     * @covers ::setPublicSuffix
     * @covers ::normalize
     * @dataProvider provideWrongConstructor
     *
     * @param mixed $domain
     * @param mixed $publicSuffix
     */
    public function testConstructorThrowsExceptionOnMisMatchPublicSuffixDomain($domain, $publicSuffix): void
    {
        self::expectException(CouldNotResolvePublicSuffix::class);
        new Domain($domain, new PublicSuffix($publicSuffix));
    }

    public function provideWrongConstructor(): iterable
    {
        return [
            'public suffix mismatch' => [
                'domain' => 'www.ulb.ac.be',
                'publicSuffix' => 'com',
            ],
            'domain and public suffix are the same' => [
                'domain' => 'co.uk',
                'publicSuffix' => 'co.uk',
            ],
            'domain has no labels' => [
                'domain' => 'localhost',
                'publicSuffix' => 'localhost',
            ],
            'domain is null' => [
                'domain' => null,
                'publicSuffix' => 'com',
            ],
        ];
    }

    /**
     * @dataProvider invalidDomainProvider
     * @covers ::__construct
     * @covers ::parse
     * @covers ::idnToAscii
     * @covers ::getIdnErrors
     * @param string $domain
     */
    public function testToAsciiThrowsException(string $domain): void
    {
        self::expectException(InvalidDomain::class);
        new Domain($domain);
    }

    public function invalidDomainProvider(): iterable
    {
        return [
            'invalid IDN domain' => ['a⒈com'],
            'invalid IDN domain full size' => ['％００.com'],
            'invalid IDN domain full size rawurlencode ' => ['%ef%bc%85%ef%bc%94%ef%bc%91.com'],
        ];
    }

    /**
     * @covers ::toUnicode
     * @covers ::idnToUnicode
     * @covers ::getIdnErrors
     */
    public function testToUnicodeThrowsException(): void
    {
        self::expectException(InvalidDomain::class);
        (new Domain('xn--a-ecp.ru'))->toUnicode();
    }

    /**
     * @covers ::__construct
     * @covers ::__set_state
     * @covers ::__debugInfo
     * @covers ::__toString
     * @covers ::jsonSerialize
     * @covers ::getIterator
     */
    public function testDomainInternalPhpMethod(): void
    {
        $domain = new Domain('www.ulb.ac.be', new PublicSuffix('ac.be'));
        $generateDomain = eval('return '.var_export($domain, true).';');
        self::assertEquals($domain, $generateDomain);
        self::assertSame(['be', 'ac', 'ulb', 'www'], iterator_to_array($domain));
        self::assertEquals($domain->__debugInfo(), $domain->jsonSerialize());
        self::assertSame('www.ulb.ac.be', (string) $domain);
    }

    /**
     * @covers ::normalize
     * @covers ::getIterator
     * @covers ::count
     * @dataProvider countableProvider
     *
     * @param string[] $labels
     * @param ?string  $domain
     * @param int      $nbLabels
     */
    public function testCountable(?string $domain, int $nbLabels, array $labels): void
    {
        $domain = new Domain($domain);
        self::assertCount($nbLabels, $domain);
        self::assertSame($labels, iterator_to_array($domain));
    }

    public function countableProvider(): iterable
    {
        return [
            'null' => [null, 0, []],
            'empty string' => ['', 1, ['']],
            'simple' => ['foo.bar.baz', 3, ['baz', 'bar', 'foo']],
            'unicode' => ['www.食狮.公司.cn', 4, ['cn', '公司', '食狮', 'www']],
        ];
    }

    /**
     * @covers ::getLabel
     */
    public function testGetLabel(): void
    {
        $domain = new Domain('master.example.com');
        self::assertSame('com', $domain->getLabel(0));
        self::assertSame('example', $domain->getLabel(1));
        self::assertSame('master', $domain->getLabel(-1));
        self::assertNull($domain->getLabel(23));
        self::assertNull($domain->getLabel(-23));
    }

    /**
     * @covers ::keys
     */
    public function testOffsets(): void
    {
        $domain = new Domain('master.com.example.com');
        self::assertSame([0, 2], $domain->keys('com'));
        self::assertSame([], $domain->keys('toto'));
    }

    /**
     * @covers ::labels
     */
    public function testLabels(): void
    {
        $domain = new Domain('master.com.example.com');
        self::assertSame([
            'com',
            'example',
            'com',
            'master',
        ], $domain->labels());

        $domain = new Domain();
        self::assertSame([], $domain->labels());
    }

    /**
     * @covers ::parse
     * @covers ::setPublicSuffix
     * @covers ::normalize
     * @covers ::setRegistrableDomain
     * @covers ::setSubDomain
     * @covers ::getDomain
     * @covers ::getContent
     * @covers ::getPublicSuffix
     * @covers ::idnToUnicode
     * @covers ::toUnicode
     * @covers \Pdp\PublicSuffix::toUnicode
     * @dataProvider toUnicodeProvider
     * @param ?string $domain
     * @param ?string $publicSuffix
     * @param ?string $expectedDomain
     * @param ?string $expectedSuffix
     * @param ?string $expectedIDNDomain
     * @param ?string $expectedIDNSuffix
     */
    public function testToIDN(
        ?string $domain,
        ?string $publicSuffix,
        ?string $expectedDomain,
        ?string $expectedSuffix,
        ?string $expectedIDNDomain,
        ?string $expectedIDNSuffix
    ): void {
        $domain = new Domain($domain, new PublicSuffix($publicSuffix));
        self::assertSame($expectedDomain, $domain->getDomain());
        self::assertSame($expectedSuffix, $domain->getPublicSuffix());

        /** @var Domain $domainIDN */
        $domainIDN = $domain->toUnicode();
        self::assertSame($expectedIDNDomain, $domainIDN->getDomain());
        self::assertSame($expectedIDNSuffix, $domainIDN->getPublicSuffix());
    }

    public function toUnicodeProvider(): iterable
    {
        return [
            'simple domain' => [
                'domain' => 'www.ulb.ac.be',
                'publicSuffix' => 'ac.be',
                'expectedDomain' => 'www.ulb.ac.be',
                'expectedSuffix' => 'ac.be',
                'expectedIDNDomain' => 'www.ulb.ac.be',
                'expectedIDNSuffix' => 'ac.be',
            ],
            'ASCII to IDN domain' => [
                'domain' => 'www.xn--85x722f.xn--55qx5d.cn',
                'publicSuffix' => 'xn--85x722f.xn--55qx5d.cn',
                'expectedDomain' => 'www.xn--85x722f.xn--55qx5d.cn',
                'expectedSuffix' => 'xn--85x722f.xn--55qx5d.cn',
                'expectedIDNDomain' => 'www.食狮.公司.cn',
                'expectedIDNSuffix' => '食狮.公司.cn',
            ],
            'IDN to IDN domain' => [
                'domain' => 'www.食狮.公司.cn',
                'publicSuffix' => '食狮.公司.cn',
                'expectedDomain' => 'www.食狮.公司.cn',
                'expectedSuffix' => '食狮.公司.cn',
                'expectedIDNDomain' => 'www.食狮.公司.cn',
                'expectedIDNSuffix' => '食狮.公司.cn',
            ],
            'empty string domain and null suffix' => [
                'domain' => '',
                'publicSuffix' => null,
                'expectedDomain' => '',
                'expectedSuffix' => null,
                'expectedIDNDomain' => '',
                'expectedIDNSuffix' => null,
            ],
            'null domain and suffix' => [
                'domain' => null,
                'publicSuffix' => null,
                'expectedDomain' => null,
                'expectedSuffix' => null,
                'expectedIDNDomain' => null,
                'expectedIDNSuffix' => null,
            ],
            'domain with null suffix' => [
                'domain' => 'www.xn--85x722f.xn--55qx5d.cn',
                'publicSuffix' => null,
                'expectedDomain' => 'www.xn--85x722f.xn--55qx5d.cn',
                'expectedSuffix' => null,
                'expectedIDNDomain' => 'www.食狮.公司.cn',
                'expectedIDNSuffix' => null,
            ],
            'domain with URLencoded data' => [
                'domain' => 'b%C3%A9b%C3%A9.be',
                'publicSuffix' => 'be',
                'expectedDomain' => 'bébé.be',
                'expectedSuffix' => 'be',
                'expectedIDNDomain' => 'bébé.be',
                'expectedIDNSuffix' => 'be',
            ],
        ];
    }

    /**
     * @covers ::parse
     * @covers ::setPublicSuffix
     * @covers ::normalize
     * @covers ::setRegistrableDomain
     * @covers ::setSubDomain
     * @covers ::getDomain
     * @covers ::getContent
     * @covers ::getPublicSuffix
     * @covers ::idnToAscii
     * @covers ::toAscii
     * @covers \Pdp\PublicSuffix::toAscii
     *
     * @dataProvider toAsciiProvider
     * @param ?string $domain
     * @param ?string $publicSuffix
     * @param ?string $expectedDomain
     * @param ?string $expectedSuffix
     * @param ?string $expectedAsciiDomain
     * @param ?string $expectedAsciiSuffix
     */
    public function testToAscii(
        ?string $domain,
        ?string $publicSuffix,
        ?string $expectedDomain,
        ?string $expectedSuffix,
        ?string $expectedAsciiDomain,
        ?string $expectedAsciiSuffix
    ): void {
        $domain = new Domain($domain, new PublicSuffix($publicSuffix));
        self::assertSame($expectedDomain, $domain->getDomain());
        self::assertSame($expectedSuffix, $domain->getPublicSuffix());

        /** @var Domain $domainIDN */
        $domainIDN = $domain->toAscii();
        self::assertSame($expectedAsciiDomain, $domainIDN->getDomain());
        self::assertSame($expectedAsciiSuffix, $domainIDN->getPublicSuffix());
    }

    public function toAsciiProvider(): iterable
    {
        return [
            'simple domain' => [
                'domain' => 'www.ulb.ac.be',
                'publicSuffix' => 'ac.be',
                'expectedDomain' => 'www.ulb.ac.be',
                'expectedSuffix' => 'ac.be',
                'expectedIDNDomain' => 'www.ulb.ac.be',
                'expectedIDNSuffix' => 'ac.be',
            ],
            'ASCII to ASCII domain' => [
                'domain' => 'www.xn--85x722f.xn--55qx5d.cn',
                'publicSuffix' => 'xn--85x722f.xn--55qx5d.cn',
                'expectedDomain' => 'www.xn--85x722f.xn--55qx5d.cn',
                'expectedSuffix' => 'xn--85x722f.xn--55qx5d.cn',
                'expectedIDNDomain' => 'www.xn--85x722f.xn--55qx5d.cn',
                'expectedIDNSuffix' => 'xn--85x722f.xn--55qx5d.cn',
            ],
            'ASCII to IDN domain' => [
                'domain' => 'www.食狮.公司.cn',
                'publicSuffix' => '食狮.公司.cn',
                'expectedDomain' => 'www.食狮.公司.cn',
                'expectedSuffix' => '食狮.公司.cn',
                'expectedIDNDomain' => 'www.xn--85x722f.xn--55qx5d.cn',
                'expectedIDNSuffix' => 'xn--85x722f.xn--55qx5d.cn',
            ],
            'null domain and suffix' => [
                'domain' => null,
                'publicSuffix' => null,
                'expectedDomain' => null,
                'expectedSuffix' => null,
                'expectedIDNDomain' => null,
                'expectedIDNSuffix' => null,
            ],
            'domain with null suffix' => [
                'domain' => 'www.食狮.公司.cn',
                'publicSuffix' => null,
                'expectedDomain' => 'www.食狮.公司.cn',
                'expectedSuffix' => null,
                'expectedIDNDomain' => 'www.xn--85x722f.xn--55qx5d.cn',
                'expectedIDNSuffix' => null,
            ],
        ];
    }

    /**
     * @covers ::resolve
     * @covers ::normalize
     * @dataProvider resolvePassProvider
     *
     * @param mixed   $publicSuffix
     * @param Domain  $domain
     * @param ?string $expected
     */
    public function testResolveWorks(Domain $domain, $publicSuffix, ?string $expected): void
    {
        self::assertSame($expected, $domain->resolve($publicSuffix)->getPublicSuffix());
    }

    public function resolvePassProvider(): iterable
    {
        $publicSuffix = new PublicSuffix('ac.be', Rules::ICANN_DOMAINS);
        $domain = new Domain('ulb.ac.be', $publicSuffix);

        return [
            'null public suffix' => [
                'domain' => $domain,
                'public suffix' => new PublicSuffix(),
                'expected' => null,
            ],
            'null public suffix (with null value)' => [
                'domain' => $domain,
                'public suffix' => null,
                'expected' => null,
            ],
            'same public suffix' => [
                'domain' => $domain,
                'public suffix' => $publicSuffix,
                'expected' => 'ac.be',
            ],
            'same public suffix (with string value)' => [
                'domain' => $domain,
                'public suffix' => 'ac.be',
                'expected' => 'ac.be',
            ],
            'update public suffix' => [
                'domain' => $domain,
                'public suffix' => new PublicSuffix('be', Rules::ICANN_DOMAINS),
                'expected' => 'be',
            ],
            'idn domain name' => [
                'domain' =>  new Domain('Яндекс.РФ', new PublicSuffix('рф', Rules::ICANN_DOMAINS)),
                'public suffix' => new PublicSuffix('рф', Rules::ICANN_DOMAINS),
                'expected' => 'рф',
            ],
            'idn domain name with ascii public suffix' => [
                'domain' =>  new Domain('Яндекс.РФ', new PublicSuffix('рф', Rules::ICANN_DOMAINS)),
                'public suffix' => new PublicSuffix('xn--p1ai', Rules::ICANN_DOMAINS),
                'expected' => 'рф',
            ],
        ];
    }

    /**
     * @covers ::resolve
     * @dataProvider resolveFailsProvider
     * @param Domain       $domain
     * @param PublicSuffix $publicSuffix
     */
    public function testResolveFails(Domain $domain, PublicSuffix $publicSuffix): void
    {
        self::expectException(CouldNotResolvePublicSuffix::class);
        $domain->resolve($publicSuffix);
    }

    public function resolveFailsProvider(): iterable
    {
        $publicSuffix = new PublicSuffix('ac.be', Rules::ICANN_DOMAINS);
        $domain = new Domain('ulb.ac.be', $publicSuffix);

        return [
            'public suffix mismatch' => [
                'domain' => $domain,
                'public suffix' => new PublicSuffix('ac.fr'),
            ],
            'domain name can not contains public suffix' => [
                'domain' => new Domain('localhost'),
                'public suffix' => $publicSuffix,
            ],
            'domain name is equal to public suffix' => [
                'domain' => new Domain('ac.be'),
                'public suffix' => $publicSuffix,
            ],
            'partial public suffix' => [
                'domain' => $domain,
                'public suffix' => new PublicSuffix('c.be'),
            ],
            'mismatch idn public suffix' => [
                'domain' => new Domain('www.食狮.公司.cn'),
                'public suffix' => new PublicSuffix('cn.公司'),
            ],
        ];
    }

    /**
     * @covers ::resolve
     */
    public function testResolveReturnsInstance(): void
    {
        $publicSuffix = new PublicSuffix('ac.be', Rules::ICANN_DOMAINS);
        $domain = new Domain('ulb.ac.be', $publicSuffix);
        self::assertSame($domain, $domain->resolve($publicSuffix));
        self::assertNotSame($domain, $domain->resolve(new PublicSuffix('ac.be', Rules::PRIVATE_DOMAINS)));
    }

    /**
     * @covers ::withSubDomain
     * @covers ::normalizeContent
     * @dataProvider withSubDomainWorksProvider
     *
     * @param mixed   $subdomain
     * @param Domain  $domain
     * @param ?string $expected
     */
    public function testWithSubDomainWorks(Domain $domain, $subdomain, ?string $expected): void
    {
        $result = $domain->withSubDomain($subdomain);
        self::assertSame($expected, $result->getSubDomain());
        self::assertSame($domain->getPublicSuffix(), $result->getPublicSuffix());
        self::assertSame($domain->getRegistrableDomain(), $result->getRegistrableDomain());
        self::assertSame($domain->isKnown(), $result->isKnown());
        self::assertSame($domain->isICANN(), $result->isICANN());
        self::assertSame($domain->isPrivate(), $result->isPrivate());
    }

    public function withSubDomainWorksProvider(): iterable
    {
        return [
            'simple addition' => [
                'domain' => new Domain('example.com', new PublicSuffix('com', Rules::ICANN_DOMAINS)),
                'subdomain' => 'www',
                'expected' => 'www',
            ],
            'simple addition IDN (1)' => [
                'domain' => new Domain('example.com', new PublicSuffix('com', Rules::ICANN_DOMAINS)),
                'subdomain' => new Domain('bébé'),
                'expected' => 'xn--bb-bjab',
            ],
            'simple addition IDN (2)' => [
                'domain' => new Domain('Яндекс.РФ', new PublicSuffix('рф', Rules::ICANN_DOMAINS)),
                'subdomain' => 'bébé',
                'expected' => 'bébé',
            ],
            'simple removal' => [
                'domain' => new Domain('www.example.com', new PublicSuffix('com', Rules::ICANN_DOMAINS)),
                'subdomain' => null,
                'expected' => null,
            ],
            'simple removal IDN' => [
                'domain' =>  new Domain('bébé.Яндекс.РФ', new PublicSuffix('рф', Rules::ICANN_DOMAINS)),
                'subdomain' => 'xn--bb-bjab',
                'expected' => 'bébé',
            ],
        ];
    }

    /**
     * @covers ::withSubDomain
     * @covers ::normalizeContent
     */
    public function testWithSubDomainFailsWithNullDomain(): void
    {
        self::expectException(CouldNotResolveSubDomain::class);
        (new Domain())->withSubDomain('www');
    }

    /**
     * @covers ::withSubDomain
     * @covers ::normalizeContent
     */
    public function testWithSubDomainFailsWithOneLabelDomain(): void
    {
        self::expectException(CouldNotResolveSubDomain::class);
        (new Domain('localhost'))->withSubDomain('www');
    }

    /**
     * @covers ::withSubDomain
     * @covers ::normalizeContent
     */
    public function testWithEmptySubdomain(): void
    {
        self::expectException(InvalidDomain::class);
        (new Domain(
            'www.example.com',
            new PublicSuffix('com', PublicSuffix::ICANN_DOMAINS)
        ))->withSubDomain('');
    }

    /**
     * @covers ::withSubDomain
     * @covers ::normalizeContent
     */
    public function testWithSubDomainFailsWithNonStringableObject(): void
    {
        self::expectException(TypeError::class);
        (new Domain(
            'example.com',
            new PublicSuffix('com', PublicSuffix::ICANN_DOMAINS)
        ))->withSubDomain(date_create());
    }


    /**
     * @covers ::withSubDomain
     * @covers ::normalizeContent
     */
    public function testWithSubDomainWithoutPublicSuffixInfo(): void
    {
        self::expectException(CouldNotResolveSubDomain::class);
        (new Domain('www.example.com'))->withSubDomain('www');
    }

    /**
     * @covers ::withPublicSuffix
     * @dataProvider withPublicSuffixWorksProvider
     *
     * @param mixed   $publicSuffix
     * @param Domain  $domain
     * @param ?string $expected
     * @param bool    $isKnown
     * @param bool    $isICANN
     * @param bool    $isPrivate
     */
    public function testWithPublicSuffixWorks(
        Domain $domain,
        $publicSuffix,
        ?string $expected,
        bool $isKnown,
        bool $isICANN,
        bool $isPrivate
    ): void {
        $result = $domain->withPublicSuffix($publicSuffix);
        self::assertSame($expected, $result->getPublicSuffix());
        self::assertSame($isKnown, $result->isKnown());
        self::assertSame($isICANN, $result->isICANN());
        self::assertSame($isPrivate, $result->isPrivate());
    }

    public function withPublicSuffixWorksProvider(): iterable
    {
        $base_domain = new Domain('example.com', new PublicSuffix('com', Rules::ICANN_DOMAINS));

        return [
            'simple update (1)' => [
                'domain' => $base_domain,
                'publicSuffix' => 'be',
                'expected' => 'be',
                'isKnown' => false,
                'isICANN' => false,
                'isPrivate' => false,
            ],
            'simple update (2)' => [
                'domain' => $base_domain,
                'publicSuffix' => new PublicSuffix('github.io', Rules::PRIVATE_DOMAINS),
                'expected' => 'github.io',
                'isKnown' => true,
                'isICANN' => false,
                'isPrivate' => true,
            ],
            'same public suffix but PSL info is changed' => [
                'domain' => $base_domain,
                'publicSuffix' => new PublicSuffix('com', Rules::PRIVATE_DOMAINS),
                'expected' => 'com',
                'isKnown' => true,
                'isICANN' => false,
                'isPrivate' => true,
            ],
            'same public suffix but PSL info does not changed' => [
                'domain' => $base_domain,
                'publicSuffix' => new PublicSuffix('com', Rules::ICANN_DOMAINS),
                'expected' => 'com',
                'isKnown' => true,
                'isICANN' => true,
                'isPrivate' => false,
            ],
            'simple update IDN (1)' => [
                'domain' => $base_domain,
                'publicSuffix' => new PublicSuffix('рф', Rules::ICANN_DOMAINS),
                'expected' => 'xn--p1ai',
                'isKnown' => true,
                'isICANN' => true,
                'isPrivate' => false,
            ],
            'simple update IDN (2)' => [
                'domain' => new Domain('www.bébé.be', new PublicSuffix('be', Rules::ICANN_DOMAINS)),
                'publicSuffix' => new PublicSuffix('xn--p1ai', Rules::ICANN_DOMAINS),
                'expected' => 'рф',
                'isKnown' => true,
                'isICANN' => true,
                'isPrivate' => false,
            ],
            'adding the public suffix to a single label domain' => [
                'domain' => new Domain('localhost'),
                'publicSuffix' => 'www',
                'expected' => 'www',
                'isKnown' => false,
                'isICANN' => false,
                'isPrivate' => false,
            ],
            'removing the public suffix list' => [
                'domain' => new Domain('www.bébé.be', new PublicSuffix('be', Rules::ICANN_DOMAINS)),
                'publicSuffix' => null,
                'expected' => null,
                'isKnown' => false,
                'isICANN' => false,
                'isPrivate' => false,
            ],
            'with custom IDNA domain options' =>[
                'domain' => new Domain('www.bébé.be', new PublicSuffix('be', Rules::ICANN_DOMAINS), IDNA_NONTRANSITIONAL_TO_ASCII, IDNA_NONTRANSITIONAL_TO_UNICODE),
                'publicSuffix' => null,
                'expected' => null,
                'isKnown' => false,
                'isICANN' => false,
                'isPrivate' => false,
            ],
        ];
    }

    /**
     * @covers ::withPublicSuffix
     */
    public function testWithPublicSuffixFailsWithNullDomain(): void
    {
        self::expectException(InvalidDomain::class);
        (new Domain())->withPublicSuffix('www');
    }

    /**
     * @covers ::withLabel
     * @covers ::normalizeContent
     * @dataProvider withLabelWorksProvider
     *
     * @param mixed   $label
     * @param Domain  $domain
     * @param int     $key
     * @param ?string $expected
     * @param bool    $isKnown
     * @param bool    $isICANN
     * @param bool    $isPrivate
     */
    public function testWithLabelWorks(
        Domain $domain,
        int $key,
        $label,
        ?string $expected,
        bool $isKnown,
        bool $isICANN,
        bool $isPrivate
    ): void {
        $result = $domain->withLabel($key, $label);
        self::assertSame($expected, $result->getContent());
        self::assertSame($isKnown, $result->isKnown());
        self::assertSame($isICANN, $result->isICANN());
        self::assertSame($isPrivate, $result->isPrivate());
    }

    public function withLabelWorksProvider(): iterable
    {
        $base_domain = new Domain('www.example.com', new PublicSuffix('com', Rules::ICANN_DOMAINS));

        return [
            'null domain' => [
                'domain' => new Domain(),
                'key' => 0,
                'label' => 'localhost',
                'expected' => 'localhost',
                'isKnown' => false,
                'isICANN' => false,
                'isPrivate' => false,
            ],
            'simple replace positive offset' => [
                'domain' => $base_domain,
                'key' => 2,
                'label' => 'shop',
                'expected' => 'shop.example.com',
                'isKnown' => true,
                'isICANN' => true,
                'isPrivate' => false,
            ],
            'simple replace negative offset' => [
                'domain' => $base_domain,
                'key' => -1,
                'label' => 'shop',
                'expected' => 'shop.example.com',
                'isKnown' => true,
                'isICANN' => true,
                'isPrivate' => false,
            ],
            'simple addition positive offset' => [
                'domain' => $base_domain,
                'key' => 3,
                'label' => 'shop',
                'expected' => 'shop.www.example.com',
                'isKnown' => true,
                'isICANN' => true,
                'isPrivate' => false,
            ],
            'simple addition negative offset' => [
                'domain' => $base_domain,
                'key' => -4,
                'label' => 'shop',
                'expected' => 'www.example.com.shop',
                'isKnown' => false,
                'isICANN' => false,
                'isPrivate' => false,
            ],
            'simple replace remove PSL info' => [
                'domain' => $base_domain,
                'key' => 0,
                'label' => 'fr',
                'expected' => 'www.example.fr',
                'isKnown' => false,
                'isICANN' => false,
                'isPrivate' => false,
            ],
            'replace without any change' => [
                'domain' => $base_domain,
                'key' => 2,
                'label' => 'www',
                'expected' => 'www.example.com',
                'isKnown' => true,
                'isICANN' => true,
                'isPrivate' => false,
            ],
            'simple update IDN (1)' => [
                'domain' => $base_domain,
                'key' => 2,
                'label' => 'рф',
                'expected' => 'xn--p1ai.example.com',
                'isKnown' => true,
                'isICANN' => true,
                'isPrivate' => false,
            ],
            'simple update IDN (2)' => [
                'domain' => new Domain('www.bébé.be', new PublicSuffix('be', Rules::ICANN_DOMAINS)),
                'key' => 2,
                'label' => 'xn--p1ai',
                'expected' => 'рф.bébé.be',
                'isKnown' => true,
                'isICANN' => true,
                'isPrivate' => false,
            ],
            'replace a domain with multiple label' => [
                'domain' => $base_domain,
                'key' => -1,
                'label' => 'www.shop',
                'expected' => 'www.shop.example.com',
                'isKnown' => true,
                'isICANN' => true,
                'isPrivate' => false,
            ],
        ];
    }

    /**
     * @covers ::withLabel
     */
    public function testWithLabelFailsWithTypeError(): void
    {
        self::expectException(InvalidLabel::class);
        (new Domain('example.com'))->withLabel(1, null);
    }

    /**
     * @covers ::withLabel
     */
    public function testWithLabelFailsWithInvalidKey(): void
    {
        self::expectException(InvalidLabelKey::class);
        (new Domain('example.com'))->withLabel(-4, 'www');
    }

    /**
     * @covers ::withLabel
     */
    public function testWithLabelFailsWithInvalidLabel2(): void
    {
        self::expectException(InvalidDomain::class);
        (new Domain('example.com'))->withLabel(-1, '');
    }

    /**
     * @covers ::append
     * @covers ::withLabel
     *
     * @dataProvider validAppend
     * @param string $raw
     * @param string $append
     * @param string $expected
     */
    public function testAppend(string $raw, string $append, string $expected): void
    {
        self::assertSame($expected, (string) (new Domain($raw))->append($append));
    }

    public function validAppend(): iterable
    {
        return [
            ['secure.example.com', '8.8.8.8', 'secure.example.com.8.8.8.8'],
            ['secure.example.com', 'master', 'secure.example.com.master'],
            ['secure.example.com', 'master.', 'secure.example.com.master.'],
            ['example.com', '', 'example.com.'],
        ];
    }

    /**
     * @covers ::prepend
     * @covers ::withLabel
     *
     * @dataProvider validPrepend
     * @param string $raw
     * @param string $prepend
     * @param string $expected
     */
    public function testPrepend(string $raw, string $prepend, string $expected): void
    {
        self::assertSame($expected, (string) (new Domain($raw))->prepend($prepend));
    }

    public function validPrepend(): iterable
    {
        return [
            ['secure.example.com', 'master', 'master.secure.example.com'],
            ['secure.example.com', '127.0.0.1', '127.0.0.1.secure.example.com'],
            ['secure.example.com.', 'master', 'master.secure.example.com.'],
        ];
    }

    /**
     * @covers ::withoutLabel
     * @dataProvider withoutLabelWorksProvider
     * @param Domain  $domain
     * @param int     $key
     * @param ?string $expected
     * @param bool    $isKnown
     * @param bool    $isICANN
     * @param bool    $isPrivate
     */
    public function testwithoutLabelWorks(
        Domain $domain,
        int $key,
        ?string $expected,
        bool $isKnown,
        bool $isICANN,
        bool $isPrivate
    ): void {
        $result = $domain->withoutLabel($key);
        self::assertSame($expected, $result->getContent());
        self::assertSame($isKnown, $result->isKnown());
        self::assertSame($isICANN, $result->isICANN());
        self::assertSame($isPrivate, $result->isPrivate());
    }

    public function withoutLabelWorksProvider(): iterable
    {
        $base_domain = new Domain('www.example.com', new PublicSuffix('com', Rules::ICANN_DOMAINS));

        return [
            'simple removal positive offset' => [
                'domain' => $base_domain,
                'key' => 2,
                'expected' => 'example.com',
                'isKnown' => true,
                'isICANN' => true,
                'isPrivate' => false,
            ],
            'simple removal negative offset' => [
                'domain' => $base_domain,
                'key' => -1,
                'expected' => 'example.com',
                'isKnown' => true,
                'isICANN' => true,
                'isPrivate' => false,
            ],
            'simple removal strip PSL info positive offset' => [
                'domain' => $base_domain,
                'key' => 0,
                'expected' => 'www.example',
                'isKnown' => false,
                'isICANN' => false,
                'isPrivate' => false,
            ],
            'simple removal strip PSL info negative offset' => [
                'domain' => $base_domain,
                'key' => -3,
                'expected' => 'www.example',
                'isKnown' => false,
                'isICANN' => false,
                'isPrivate' => false,
            ],
        ];
    }

    /**
     * @covers ::withoutLabel
     */
    public function testwithoutLabelFailsWithInvalidKey(): void
    {
        self::expectException(InvalidLabelKey::class);
        (new Domain('example.com'))->withoutLabel(-3);
    }

    /**
     * @covers ::withoutLabel
     */
    public function testwithoutLabelWorksWithMultipleKeys(): void
    {
        self::assertNull((new Domain('www.example.com'))->withoutLabel(0, 1, 2)->getContent());
    }

    /**
     * @covers ::__construct
     */
    public function testConstructWithCustomIDNAOptions(): void
    {
        $domain = new Domain('example.com', null, IDNA_NONTRANSITIONAL_TO_ASCII, IDNA_NONTRANSITIONAL_TO_UNICODE);
        self::assertSame(
            [IDNA_NONTRANSITIONAL_TO_ASCII, IDNA_NONTRANSITIONAL_TO_UNICODE],
            [$domain->getAsciiIDNAOption(), $domain->getUnicodeIDNAOption()]
        );
    }

    /**
     * @dataProvider resolveCustomIDNAOptionsProvider
     * @param string      $domainName
     * @param string      $publicSuffix
     * @param string      $withLabel
     * @param null|string $expectedContent
     * @param null|string $expectedAscii
     * @param null|string $expectedUnicode
     * @param null|string $expectedRegistrable
     * @param null|string $expectedSubDomain
     * @param null|string $expectedWithLabel
     */
    public function testResolveWorksWithCustomIDNAOptions(
        string $domainName,
        string $publicSuffix,
        string $withLabel,
        ?string $expectedContent,
        ?string $expectedAscii,
        ?string $expectedUnicode,
        ?string $expectedRegistrable,
        ?string $expectedSubDomain,
        ?string $expectedWithLabel
    ): void {
        $domain = new Domain(
            $domainName,
            new PublicSuffix($publicSuffix),
            IDNA_NONTRANSITIONAL_TO_ASCII,
            IDNA_NONTRANSITIONAL_TO_UNICODE
        );
        self::assertSame($expectedContent, $domain->getContent());
        self::assertSame($expectedAscii, $domain->toAscii()->getContent());
        self::assertSame($expectedUnicode, $domain->toUnicode()->getContent());
        self::assertSame($expectedRegistrable, $domain->getRegistrableDomain());
        self::assertSame($expectedSubDomain, $domain->getSubDomain());
        self::assertSame($expectedWithLabel, $domain->withLabel(-1, $withLabel)->getContent());
    }

    public function resolveCustomIDNAOptionsProvider(): iterable
    {
        return [
            'without deviation characters' => [
                'example.com',
                'com',
                'größe',
                'example.com',
                'example.com',
                'example.com',
                'example.com',
                 null,
                'xn--gre-6ka8i.com',
            ],
            'without deviation characters with label' => [
                'www.example.com',
                'com',
                'größe',
                'www.example.com',
                'www.example.com',
                'www.example.com',
                'example.com',
                'www',
                'xn--gre-6ka8i.example.com',
            ],
            'with deviation in domain' => [
                'www.faß.de',
                'de',
                'größe',
                'www.faß.de',
                'www.xn--fa-hia.de',
                'www.faß.de',
                'faß.de',
                'www',
                'größe.faß.de',
            ],
            'with deviation in label' => [
                'faß.test.de',
                'de',
                'größe',
                'faß.test.de',
                'xn--fa-hia.test.de',
                'faß.test.de',
                'test.de',
                'faß',
                'größe.test.de',
            ],
        ];
    }

    public function testInstanceCreationWithCustomIDNAOptions(): void
    {
        $domain = new Domain(
            'example.com',
            new PublicSuffix('com'),
            IDNA_NONTRANSITIONAL_TO_ASCII,
            IDNA_NONTRANSITIONAL_TO_UNICODE
        );

        /** @var Domain $instance */
        $instance = $domain->toAscii();
        self::assertSame(
            [$domain->getAsciiIDNAOption(), $domain->getUnicodeIDNAOption()],
            [$instance->getAsciiIDNAOption(), $instance->getUnicodeIDNAOption()]
        );
        /** @var Domain $instance */
        $instance = $domain->toUnicode();
        self::assertSame(
            [$domain->getAsciiIDNAOption(), $domain->getUnicodeIDNAOption()],
            [$instance->getAsciiIDNAOption(), $instance->getUnicodeIDNAOption()]
        );
        $instance = $domain->withLabel(0, 'foo');
        self::assertSame(
            [$domain->getAsciiIDNAOption(), $domain->getUnicodeIDNAOption()],
            [$instance->getAsciiIDNAOption(), $instance->getUnicodeIDNAOption()]
        );
        $instance = $domain->withoutLabel(0);
        self::assertSame(
            [$domain->getAsciiIDNAOption(), $domain->getUnicodeIDNAOption()],
            [$instance->getAsciiIDNAOption(), $instance->getUnicodeIDNAOption()]
        );
        $instance = $domain->withPublicSuffix(new PublicSuffix('us'));
        self::assertSame(
            [$domain->getAsciiIDNAOption(), $domain->getUnicodeIDNAOption()],
            [$instance->getAsciiIDNAOption(), $instance->getUnicodeIDNAOption()]
        );
        $instance = $domain->withSubDomain('foo');
        self::assertSame(
            [$domain->getAsciiIDNAOption(), $domain->getUnicodeIDNAOption()],
            [$instance->getAsciiIDNAOption(), $instance->getUnicodeIDNAOption()]
        );
        $instance = $domain->append('bar');
        self::assertSame(
            [$domain->getAsciiIDNAOption(), $domain->getUnicodeIDNAOption()],
            [$instance->getAsciiIDNAOption(), $instance->getUnicodeIDNAOption()]
        );
        $instance = $domain->prepend('bar');
        self::assertSame(
            [$domain->getAsciiIDNAOption(), $domain->getUnicodeIDNAOption()],
            [$instance->getAsciiIDNAOption(), $instance->getUnicodeIDNAOption()]
        );
        $instance = $domain->resolve('com');
        self::assertSame(
            [$domain->getAsciiIDNAOption(), $domain->getUnicodeIDNAOption()],
            [$instance->getAsciiIDNAOption(), $instance->getUnicodeIDNAOption()]
        );
    }

    /**
     * @covers ::isTransitionalDifferent
     * @dataProvider transitionalProvider
     * @param Domain $domain
     * @param bool   $expected
     */
    public function testIsTransitionalDifference(Domain $domain, bool $expected): void
    {
        self::assertSame($expected, $domain->isTransitionalDifferent());
    }

    public function transitionalProvider(): iterable
    {
        return [
            'simple' => [new Domain('example.com'), false],
            'idna' => [new Domain('français.fr'), false],
            'in domain 1' => [new Domain('faß.de'), true],
            'in domain 2' => [new Domain('βόλος.com'), true],
            'in domain 3' => [new Domain('ශ්‍රී.com'), true],
            'in domain 4' => [new Domain('نامه‌ای.com'), true],
            'in domain 5' => [new Domain('faß.test.de'), true],
        ];
    }

    /**
     * @covers ::getAsciiIDNAOption
     * @covers ::getUnicodeIDNAOption
     * @covers ::withAsciiIDNAOption
     * @covers ::withUnicodeIDNAOption
     */
    public function testwithIDNAOptions(): void
    {
        $domain = new Domain('example.com', new PublicSuffix('com'));

        self::assertSame($domain, $domain->withAsciiIDNAOption(
            $domain->getAsciiIDNAOption()
        ));

        self::assertNotEquals($domain, $domain->withAsciiIDNAOption(
            IDNA_NONTRANSITIONAL_TO_ASCII
        ));

        self::assertSame($domain, $domain->withUnicodeIDNAOption(
            $domain->getUnicodeIDNAOption()
        ));

        self::assertNotEquals($domain, $domain->withUnicodeIDNAOption(
            IDNA_NONTRANSITIONAL_TO_UNICODE
        ));
    }
}

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

namespace Pdp\Storage\Http;

use Pdp\PublicSuffixList;
use Pdp\UnableToLoadPublicSuffixList;

interface PublicSuffixListClient
{
    public const PSL_URL = 'https://publicsuffix.org/list/public_suffix_list.dat';

    /**
     * @throws ClientException
     * @throws UnableToLoadPublicSuffixList
     */
    public function getByUri(string $uri = self::PSL_URL): PublicSuffixList;
}

<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Query;

/**
 * Defines the types of queries supported by the Store component.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
enum QueryType: string
{
    case Vector = 'vector';
    case Text = 'text';
    case Hybrid = 'hybrid';
}

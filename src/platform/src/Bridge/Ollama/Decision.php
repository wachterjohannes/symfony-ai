<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Ollama;

use Symfony\AI\Platform\Model;

/**
 * A decision (classification) model, served by Ollama through "POST /v1/systemone" instead of the chat API.
 *
 * It has no capabilities on purpose, like the TypeSafe Jev model: it only accepts an
 * {@see \Symfony\AI\Platform\Bridge\TypeSafe\Evaluation} and answers with
 * {@see \Symfony\AI\Platform\Bridge\TypeSafe\Answer\Answers}.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class Decision extends Model
{
}

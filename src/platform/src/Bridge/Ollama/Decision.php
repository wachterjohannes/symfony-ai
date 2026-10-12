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
 * It has no capabilities on purpose, like other decision models (e.g. TypeSafe's Jev): it only accepts an
 * {@see \Symfony\AI\Platform\Decision\Evaluation} and answers with
 * {@see \Symfony\AI\Platform\Decision\Answer\Answers}.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class Decision extends Model
{
}

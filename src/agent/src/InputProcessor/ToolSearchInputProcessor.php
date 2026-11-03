<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\InputProcessor;

use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Agent\Toolbox\SearchableToolbox;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\ExceptionInterface;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Lets models with a native tool search search the deferred tools themselves, instead of the {@see SearchableToolbox}.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ToolSearchInputProcessor implements InputProcessorInterface
{
    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly SearchableToolbox $toolbox,
    ) {
    }

    public function processInput(Input $input): void
    {
        try {
            $providerSearches = $this->platform->getModelCatalog()->getModel($input->getModel())->supports(Capability::TOOL_SEARCH);
        } catch (ExceptionInterface) {
            $providerSearches = false;
        }

        $this->toolbox->useProviderSearch($providerSearches);
    }
}

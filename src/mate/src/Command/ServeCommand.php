<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Command;

use Mcp\Capability\Discovery\Discoverer;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Icon;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StdioTransport;
use Psr\Log\LoggerInterface;
use Symfony\AI\Mate\App;
use Symfony\AI\Mate\Discovery\ExtensionDiscovery;
use Symfony\AI\Mate\Discovery\FilteredDiscoveryLoader;
use Symfony\AI\Mate\Discovery\ServiceDiscovery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Starts the MCP server with stdio transport.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
#[AsCommand('serve', 'Starts the MCP server with stdio transport')]
class ServeCommand extends Command
{
    private ExtensionDiscovery $extensionDiscovery;
    private string $rootDir;
    private string $cacheDir;

    public function __construct(
        private LoggerInterface $logger,
        private ContainerBuilder $container,
    ) {
        parent::__construct(self::getDefaultName());
        $rootDir = $container->getParameter('mate.root_dir');
        \assert(\is_string($rootDir));
        $this->rootDir = $rootDir;

        $cacheDir = $container->getParameter('mate.cache_dir');
        \assert(\is_string($cacheDir));
        $this->cacheDir = $cacheDir;

        $enabledExtensions = $this->container->getParameter('mate.enabled_extensions');
        \assert(\is_array($enabledExtensions));

        $this->extensionDiscovery = new ExtensionDiscovery($rootDir, $enabledExtensions, $logger);
    }

    public static function getDefaultName(): string
    {
        return 'serve';
    }

    public static function getDefaultDescription(): string
    {
        return 'Starts the MCP server with stdio transport';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $discovery = new Discoverer($this->logger);
        $extensions = $this->extensionDiscovery->discover();
        (new ServiceDiscovery())->registerServices($discovery, $this->container, $this->rootDir, $extensions);

        $disabledFeatures = $this->container->getParameter('mate.disabled_features') ?? [];
        \assert(\is_array($disabledFeatures));
        /* @var array<string, array<string, array{enabled: bool}>> $disabledFeatures */

        $this->container->compile();

        $loader = new FilteredDiscoveryLoader(
            basePath: $this->rootDir,
            extensions: $extensions,
            disabledFeatures: $disabledFeatures,
            discoverer: $discovery,
            logger: $this->logger
        );

        $server = Server::builder()
            ->setProtocolVersion(
                ProtocolVersion::tryFrom(
                    $this->container->getParameter('mate.mcp_protocol_version') ?? ProtocolVersion::V2025_03_26->value
                ) ?? ProtocolVersion::V2025_03_26
            )
            ->setServerInfo(
                App::NAME,
                App::VERSION,
                'AI development assistant MCP server',
                $this->getIcon(),
                'https://symfony.com/doc/current/ai/components/mate.html',
            )
            ->setContainer($this->container)
            ->addLoader($loader)
            ->setSession(new FileSessionStore($this->cacheDir.'/sessions'))
            ->setLogger($this->logger)
            ->build();

        $server->run(new StdioTransport());

        return Command::SUCCESS;
    }

    /**
     * @return Icon[]
     */
    private function getIcon(): array
    {
        $image = file_get_contents(__DIR__.'/../../resources/symfony.ico');

        if (false === $image) {
            return [];
        }

        return [
            new Icon('data:image/x-icon;base64,'.base64_encode($image), 'image/x-icon', ['any']),
        ];
    }
}

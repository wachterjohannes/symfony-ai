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

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Icon;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StdioTransport;
use Psr\Log\LoggerInterface;
use Symfony\AI\Mate\Agent\AgentInstructionsAggregator;
use Symfony\AI\Mate\App;
use Symfony\AI\Mate\Exception\PidFileException;
use Symfony\AI\Mate\Security\Authentication\ApiKeyAuthenticator;
use Symfony\AI\Mate\Service\RegistryProvider;
use Symfony\AI\Mate\Utility\PidFileManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Starts the MCP server with stdio transport.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
#[AsCommand('serve', 'Starts the MCP server with stdio transport')]
class ServeCommand extends Command
{
    public function __construct(
        private string $cacheDir,
        private string $mcpProtocolVersion,
        private RegistryProvider $registryProvider,
        private AgentInstructionsAggregator $instructionsAggregator,
        private LoggerInterface $logger,
        private ContainerInterface $container,
    ) {
        parent::__construct(self::getDefaultName());
    }

    public static function getDefaultName(): string
    {
        return 'serve';
    }

    public static function getDefaultDescription(): string
    {
        return 'Starts the MCP server with stdio transport';
    }

    protected function configure(): void
    {
        $this->addOption('force-keep-alive', null, InputOption::VALUE_NONE, 'Force a restart of the server if it stops.');
        $this->addOption('generate-api-key', null, InputOption::VALUE_NONE, 'Generate and display a new API key for authentication');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Handle API key generation request
        if ($input->getOption('generate-api-key')) {
            $apiKey = ApiKeyAuthenticator::generateApiKey();
            $output->writeln('<info>Generated API Key:</info>');
            $output->writeln("<comment>$apiKey</comment>");
            $output->writeln('');
            $output->writeln('To enable authentication, set the following environment variables:');
            $output->writeln("  MATE_API_KEY=$apiKey");
            $output->writeln('  MATE_AUTH_ENABLED=true');
            $output->writeln('');
            $output->writeln('Then connect to the MCP server with the API key in the Authorization header:');
            $output->writeln('  Authorization: Bearer <API_KEY>');

            return Command::SUCCESS;
        }

        if ($input->getOption('force-keep-alive')) {
            $output->writeln('The option --force-keep-alive requires using the "bin/mate" file. Try running "./vendor/bin/mate serve --force-keep-alive"');

            return Command::INVALID;
        }

        // Initialize authentication
        $authenticator = ApiKeyAuthenticator::fromEnvironment($this->logger);
        
        if ($authenticator->isEnabled()) {
            $this->logger->info('MCP server authentication is enabled');
        } else {
            $this->logger->debug('MCP server authentication is disabled');
        }

        $serverBuilder = Server::builder()
            ->setProtocolVersion(
                ProtocolVersion::tryFrom($this->mcpProtocolVersion) ?? ProtocolVersion::V2025_03_26
            )
            ->setServerInfo(
                App::NAME,
                App::VERSION,
                'AI development assistant MCP server',
                $this->getIcon(),
                'https://symfony.com/doc/current/ai/components/mate.html',
            )
            ->setRegistry($this->registryProvider->getRegistry())
            ->setSession(new FileSessionStore($this->cacheDir.'/sessions'))
            ->setLogger($this->logger)
            ->setContainer($this->container);

        $instructions = $this->instructionsAggregator->aggregate();
        if (null !== $instructions) {
            $serverBuilder->setInstructions($instructions);
        }

        $server = $serverBuilder->build();

        // Create PID file manager for secure PID file handling
        $pidFileName = \sprintf('%s/server_%d.pid', $this->cacheDir, getmypid());
        $pidFileManager = new PidFileManager($pidFileName);

        try {
            // Create PID file atomically with locking
            $pidFileManager->create();

            $this->logger->debug('PID file created', ['path' => $pidFileName]);
        } catch (PidFileException $e) {
            $this->logger->warning('Failed to create PID file', [
                'path' => $pidFileName,
                'error' => $e->getMessage(),
            ]);
            // Continue without PID file - not critical for operation
        }

        try {
            $server->run(new StdioTransport());
        } finally {
            // Clean up PID file securely
            try {
                $pidFileManager->cleanup();
                $this->logger->debug('PID file cleaned up', ['path' => $pidFileName]);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to clean up PID file', [
                    'path' => $pidFileName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

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

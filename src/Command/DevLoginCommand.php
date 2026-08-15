<?php

declare(strict_types=1);

namespace Zestly\DevLoginBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zestly\DevLoginBundle\Identity\IdentityProviderInterface;
use Zestly\DevLoginBundle\Security\AccessGuard;

/**
 * Prints a login URL rather than performing a login.
 *
 * A console process has no browser session to write to, so minting a session here would help
 * nobody. What it can do is answer "what should I open?" — which is the question an agent
 * driving the CLI actually has, and the one that otherwise costs it a round trip through the
 * application's fixtures.
 */
#[AsCommand(
    name: 'dev:login',
    description: 'Print a password-free dev login URL for a user',
)]
final class DevLoginCommand extends Command
{
    public function __construct(
        private readonly IdentityProviderInterface $identityProvider,
        private readonly AccessGuard $guard,
        private readonly string $pathPrefix,
        private readonly string $defaultScheme = 'http',
        private readonly string $defaultHost = 'localhost',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('identifier', InputArgument::OPTIONAL, 'User identifier to log in as. Omit to list known identities.')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host to build the URL for', $this->defaultHost)
            ->addOption('scheme', null, InputOption::VALUE_REQUIRED, 'URL scheme', $this->defaultScheme)
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Path to land on after login')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Shared secret, if zestly_dev_login.secret is set')
            ->setHelp(<<<'HELP'
                List the identities this application knows about:

                    <info>php %command.full_name%</info>

                Print a URL to log in as a specific user, then open it:

                    <info>php %command.full_name% admin@example.com</info>
                    <info>php %command.full_name% admin@example.com --host=tenant-a.localhost --target=/dashboard</info>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->guard->assertEnvironment();

        $identifier = $input->getArgument('identifier');

        if (!\is_string($identifier) || '' === $identifier) {
            return $this->listIdentities($io);
        }

        $io->writeln($this->buildUrl($input, $identifier));

        return Command::SUCCESS;
    }

    private function listIdentities(SymfonyStyle $io): int
    {
        $identities = $this->identityProvider->getIdentities();

        if ([] === $identities) {
            $io->warning('No identities are configured.');
            $io->writeln('Add them under <info>zestly_dev_login.identities</info>, or pass any identifier your user provider accepts.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Identifier', 'Label', 'Roles'],
            array_map(
                static fn ($identity): array => [
                    $identity->identifier,
                    $identity->displayName(),
                    implode(', ', $identity->roles),
                ],
                $identities,
            ),
        );

        return Command::SUCCESS;
    }

    private function buildUrl(InputInterface $input, string $identifier): string
    {
        $url = \sprintf(
            '%s://%s%s/%s',
            (string) $input->getOption('scheme'),
            (string) $input->getOption('host'),
            $this->pathPrefix,
            rawurlencode($identifier),
        );

        $query = array_filter([
            'target' => $input->getOption('target'),
            'token' => $input->getOption('token'),
        ], static fn ($v): bool => \is_string($v) && '' !== $v);

        return [] === $query ? $url : $url.'?'.http_build_query($query);
    }
}

<?php

namespace App\Command;

use App\Repository\NotificationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:notifications:purge',
    description: 'Supprime les notifications lues depuis plus de N jours (90 par défaut)',
)]
class PurgeNotificationsCommand extends Command
{
    private const DEFAULT_DAYS = 90;

    public function __construct(private readonly NotificationRepository $repository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Ancienneté minimale (en jours) de la lecture', (string) self::DEFAULT_DAYS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = filter_var($input->getOption('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($days === false) {
            $io->error('L\'option --days doit être un entier supérieur ou égal à 1.');

            return Command::INVALID;
        }

        $deleted = $this->repository->purgeReadBefore(new \DateTimeImmutable(sprintf('-%d days', $days)));
        $io->success(sprintf('%d notification(s) lue(s) depuis plus de %d jour(s) supprimée(s).', $deleted, $days));

        return Command::SUCCESS;
    }
}

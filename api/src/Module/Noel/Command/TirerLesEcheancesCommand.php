<?php

declare(strict_types=1);

namespace App\Module\Noel\Command;

use App\Module\Noel\Service\ServiceDesTiragesNoel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Tire les tirages de Noël arrivés à leur date. Lancée toutes les cinq minutes par le
 * service `noel-echeances` de `compose.prod.yaml`.
 */
#[AsCommand(name: 'heritage:noel:tirer-les-echeances', description: 'Tire les tirages de Noël arrivés à leur date.')]
final class TirerLesEcheancesCommand extends Command
{
    public function __construct(private readonly ServiceDesTiragesNoel $service)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $faits = $this->service->tirerLesEcheances();
        $output->writeln(\sprintf('%d tirage(s) de Noël fait(s).', $faits));

        return Command::SUCCESS;
    }
}

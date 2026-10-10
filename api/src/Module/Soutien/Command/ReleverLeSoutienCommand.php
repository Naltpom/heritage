<?php

declare(strict_types=1);

namespace App\Module\Soutien\Command;

use App\Module\Soutien\Service\BilanDuSoutien;
use App\Module\Soutien\Service\LiberapayIndisponible;
use App\Module\Soutien\Service\ReglagesDuSoutien;
use App\Module\Soutien\Service\ReleveDuJour;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pose le relevé Liberapay du jour, sans attendre qu'un membre ouvre la page.
 *
 * La page le fait d'elle-même au premier affichage de la journée ; cette commande sert à
 * vérifier un pseudo fraîchement réglé, ou à garantir un relevé par jour depuis une boucle
 * de l'hôte. Idempotente : relancée le même jour, elle relit le relevé déjà pris.
 */
#[AsCommand(
    name: 'heritage:soutien:relever',
    description: 'Relève le profil Liberapay du soutien (une fois par jour, idempotent).',
)]
final class ReleverLeSoutienCommand extends Command
{
    public function __construct(
        private readonly ReglagesDuSoutien $reglages,
        private readonly ReleveDuJour $releveDuJour,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (null === $this->reglages->pseudo()) {
            $io->warning(\sprintf('Aucun pseudo Liberapay valide dans le réglage « %s ».', ReglagesDuSoutien::CLE_PSEUDO));

            return Command::FAILURE;
        }

        try {
            $releve = $this->releveDuJour->assurer(forcer: true);
        } catch (LiberapayIndisponible $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (null === $releve) {
            return Command::FAILURE;
        }

        $io->success(\sprintf(
            '%s, %s : %s %s par semaine (≈ %s par mois), %d donateur(s).',
            $releve->getPseudo(),
            $releve->getJour()->format('Y-m-d'),
            number_format($releve->getRecuParSemaineCentimes() / 100, 2, ',', ' '),
            $releve->getDevise(),
            number_format(BilanDuSoutien::parMois($releve->getRecuParSemaineCentimes()) / 100, 2, ',', ' '),
            $releve->getDonateurs(),
        ));

        return Command::SUCCESS;
    }
}

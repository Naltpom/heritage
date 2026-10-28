<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Entity\JetonDApi;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Repository\JetonDApiRepository;
use App\Module\Core\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Révoque un jeton d'API (ADR 0021 « Jetons d'API ») : effet immédiat, la ligne reste.
 *
 * **Idempotente** : un jeton déjà révoqué n'est pas touché, ne produit pas de seconde ligne
 * d'audit et la commande réussit — rejouer une révocation ne doit jamais inquiéter.
 * Le jeton se désigne par son id, ou par son préfixe s'il est sans ambiguïté.
 */
#[AsCommand(
    name: 'heritage:jeton-api:revoquer',
    description: 'Révoque un jeton d’API (par id ou par préfixe).',
)]
final class RevoquerJetonDApiCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JetonDApiRepository $jetons,
        private readonly AuditService $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('jeton', InputArgument::REQUIRED, 'Id du jeton, ou son préfixe (hrt_ et 8 caractères)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $reference = trim((string) $input->getArgument('jeton'));
        $trouves = ctype_digit($reference)
            ? array_values(array_filter([$this->jetons->find((int) $reference)]))
            : $this->jetons->findBy(['tokenPrefix' => $reference]);

        if ([] === $trouves) {
            $io->error(\sprintf('Aucun jeton pour « %s ».', $reference));

            return Command::FAILURE;
        }
        if (\count($trouves) > 1) {
            $io->error(\sprintf('Le préfixe « %s » est ambigu (%d jetons) : utilisez l’id.', $reference, \count($trouves)));

            return Command::FAILURE;
        }

        /** @var JetonDApi $jeton */
        $jeton = $trouves[0];

        if ($jeton->estRevoque()) {
            $io->note(\sprintf('Le jeton n° %d était déjà révoqué : rien à faire.', $jeton->getId()));

            return Command::SUCCESS;
        }

        $jeton->revoquer(new \DateTimeImmutable());
        $this->em->flush();

        $this->audit->enregistrer(
            AuditAction::JetonApiRevoque,
            'Core\\JetonDApi',
            (string) $jeton->getId(),
            $jeton->getLibelle(),
            ['utilisateur_id' => $jeton->getUser()->getId(), 'portee' => $jeton->getPortee()->value],
        );

        $io->success(\sprintf('Jeton n° %d (%s) révoqué.', $jeton->getId(), $jeton->getTokenPrefix()));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Entity\JetonDApi;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\PorteeDeJeton;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Repository\UserRepository;
use App\Module\Core\Service\AuditService;
use App\Module\Core\Service\GenerateurDeJetonDApi;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crée un **jeton d'API personnel** pour un compte (ADR 0021 « Jetons d'API »).
 *
 * La console est la seule porte d'émission : il n'existe aucune route pour créer un jeton,
 * donc aucun jeton ne naît d'une session volée.
 *
 * **Le jeton n'est affiché qu'ici, une seule fois.** La base ne garde que son empreinte
 * ({@see GenerateurDeJetonDApi}) ; qui l'a perdu en crée un autre et révoque l'ancien.
 *
 * Le souscripteur Doctrine ne journalise pas hors requête HTTP : la commande écrit donc
 * elle-même la ligne `JetonApiCree`, avec l'id et le libellé du jeton, jamais le secret.
 */
#[AsCommand(
    name: 'heritage:jeton-api:creer',
    description: 'Crée un jeton d’API personnel (affiché une seule fois).',
)]
final class CreerJetonDApiCommand extends Command
{
    /** Durée par défaut : celle de {@see JetonDApi::VALIDITE_PAR_DEFAUT}. */
    public const int JOURS_PAR_DEFAUT = 90;

    /**
     * Plafond : un an. Un jeton sans fin est un mot de passe qu'on oublie ; au-delà, mieux
     * vaut en recréer un (la commande est faite pour ça).
     */
    public const int JOURS_MAX = 365;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $utilisateurs,
        private readonly GenerateurDeJetonDApi $generateur,
        private readonly AuditService $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('utilisateur', InputArgument::REQUIRED, 'Id ou courriel du compte propriétaire')
            ->addArgument('libelle', InputArgument::REQUIRED, \sprintf('Étiquette du jeton (%d caractères maximum)', JetonDApi::LIBELLE_MAX))
            ->addOption('portee', null, InputOption::VALUE_REQUIRED, 'Portée : '.implode(' | ', array_column(PorteeDeJeton::cases(), 'value')).' (obligatoire)')
            ->addOption('jours', null, InputOption::VALUE_REQUIRED, \sprintf('Durée de validité en jours (1 à %d)', self::JOURS_MAX), (string) self::JOURS_PAR_DEFAUT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $portee = PorteeDeJeton::tryFrom((string) $input->getOption('portee'));
        if (null === $portee) {
            $io->error(\sprintf('--portee est obligatoire : %s.', implode(', ', array_column(PorteeDeJeton::cases(), 'value'))));

            return Command::FAILURE;
        }

        $joursBruts = (string) $input->getOption('jours');
        $jours = ctype_digit($joursBruts) ? (int) $joursBruts : 0;
        if ($jours < 1 || $jours > self::JOURS_MAX) {
            $io->error(\sprintf('--jours doit être un entier de 1 à %d.', self::JOURS_MAX));

            return Command::FAILURE;
        }

        $libelle = trim((string) $input->getArgument('libelle'));
        if ('' === $libelle || mb_strlen($libelle) > JetonDApi::LIBELLE_MAX) {
            $io->error(\sprintf('Le libellé doit faire de 1 à %d caractères.', JetonDApi::LIBELLE_MAX));

            return Command::FAILURE;
        }

        $reference = (string) $input->getArgument('utilisateur');
        $compte = ctype_digit($reference)
            ? $this->utilisateurs->find((int) $reference)
            : $this->utilisateurs->findOneBy(['email' => $reference]);
        if (!$compte instanceof User) {
            $io->error(\sprintf('Aucun compte pour « %s ».', $reference));

            return Command::FAILURE;
        }
        if (UserStatus::Suspendu === $compte->getStatus()) {
            $io->error(\sprintf('Le compte « %s » est suspendu : aucun jeton ne lui est délivré.', $compte->getEmail()));

            return Command::FAILURE;
        }

        $maintenant = new \DateTimeImmutable();
        $neuf = $this->generateur->generer();
        $jeton = new JetonDApi(
            $compte,
            $libelle,
            $neuf['empreinte'],
            $neuf['prefixe'],
            $portee,
            $maintenant->modify(\sprintf('+%d days', $jours)),
            $maintenant,
        );
        $this->em->persist($jeton);
        $this->em->flush();

        $this->audit->enregistrer(
            AuditAction::JetonApiCree,
            'Core\\JetonDApi',
            (string) $jeton->getId(),
            $libelle,
            [
                'utilisateur_id' => $compte->getId(),
                'portee' => $portee->value,
                'expire_le' => $jeton->getExpiresAt()->format(\DateTimeInterface::ATOM),
            ],
        );

        $io->success(\sprintf(
            'Jeton n° %d créé pour %s (portée %s, expire le %s).',
            $jeton->getId(),
            $compte->getEmail(),
            $portee->value,
            $jeton->getExpiresAt()->format('d/m/Y'),
        ));
        $io->writeln($neuf['jeton']);
        $io->warning('Ce jeton ne sera PLUS JAMAIS affiché : copiez-le maintenant. En cas de perte, créez-en un autre et révoquez celui-ci.');

        return Command::SUCCESS;
    }
}

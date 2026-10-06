<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crée le **premier** administrateur — la seule façon d'entrer dans une base neuve.
 *
 * « Les comptes naissent exclusivement du parcours d'invitation ou de la commande console
 * d'amorçage » (`Core\Entity\User`). L'invitation suppose un administrateur pour
 * l'émettre ; il faut donc bien que le premier vienne d'ailleurs, et la console est le
 * seul « ailleurs » qui ne soit pas une route.
 *
 * C'est aussi la porte de secours du `409 dernier_administrateur` (§ 10.1 du contrat) :
 * se verrouiller dehors n'a pas d'autre issue que celle-ci.
 */
#[AsCommand(
    name: 'heritage:creer-administrateur',
    description: 'Crée un compte administrateur (amorçage d’une base neuve).',
)]
final class CreerAdministrateurCommand extends Command
{
    /** § 6.1 du dossier de mission. */
    private const int LONGUEUR_MINIMALE = 12;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $utilisateurs,
        private readonly UserPasswordHasherInterface $hacheur,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('courriel', InputArgument::REQUIRED, 'Adresse de courriel du compte')
            ->addArgument('nom', InputArgument::OPTIONAL, 'Nom affiché dans les files d’administration', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $courriel = (string) $input->getArgument('courriel');
        $nom = (string) $input->getArgument('nom');

        if (null !== $this->utilisateurs->findOneBy(['email' => $courriel])) {
            $io->error(\sprintf('Un compte existe déjà pour « %s ».', $courriel));

            return Command::FAILURE;
        }

        // Le mot de passe est demandé de façon masquée et n'apparaît donc ni dans
        // l'historique du terminal, ni dans la liste des processus.
        $question = (new Question('Mot de passe (12 caractères minimum) : '))
            ->setHidden(true)
            ->setHiddenFallback(false);
        $motDePasse = (string) $io->askQuestion($question);

        if (mb_strlen($motDePasse) < self::LONGUEUR_MINIMALE) {
            $io->error(\sprintf('%d caractères minimum.', self::LONGUEUR_MINIMALE));

            return Command::FAILURE;
        }

        $administrateur = new User($courriel, $nom);
        $administrateur
            ->setRoles(['ROLE_ADMIN'])
            ->setStatus(UserStatus::Actif)
            ->setPassword($this->hacheur->hashPassword($administrateur, $motDePasse));

        $this->em->persist($administrateur);
        $this->em->flush();

        $io->success(\sprintf('Administrateur « %s » créé.', $courriel));

        // Rappel utile : cette création n'est pas au journal d'audit. Le souscripteur
        // Doctrine ne journalise pas hors requête HTTP (sinon une commande en écrirait
        // des centaines de lignes sans acteur ni IP). La trace d'un amorçage est dans
        // l'historique du serveur, pas dans l'application.
        $io->note('Création en console : non journalisée dans core_audit_log.');

        return Command::SUCCESS;
    }
}

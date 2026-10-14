<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Service\AmorcageDuCompteAdministrateur;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repose le compte administrateur décrit par l'environnement — **sans rien purger**.
 *
 * ## Pourquoi cette commande existe à côté de la fixture
 *
 * `doctrine:fixtures:load` **purge la base** (avertissement de `config/bundles.php`).
 * Or l'amorçage est précisément ce qu'on veut rejouer **sur une base garnie** — par
 * exemple une base restaurée dont `core_user` ne porte pas le compte attendu. Passer par
 * les fixtures pour cela détruirait l'arbre entier — la commande qui répare devenant la
 * commande qui casse.
 *
 * Les deux chemins appellent le **même** service, donc ils ne peuvent pas diverger.
 *
 * ## Différence avec `heritage:creer-administrateur`
 *
 * L'autre commande demande le mot de passe au clavier et refuse si le compte existe :
 * c'est le geste d'amorçage d'une base neuve, ou la porte de secours du
 * `409 dernier_administrateur`. Celle-ci ne demande rien, lit l'environnement, et se
 * rejoue autant de fois qu'on veut sans effet de bord — c'est ce qui la rend utilisable
 * dans un script de déploiement.
 *
 * **Elle ne réécrit jamais un mot de passe existant.** Un amorçage qui le ferait
 * reprendrait au titulaire celui qu'il a pu changer, sans le dire.
 */
#[AsCommand(
    name: 'heritage:amorcer-comptes',
    description: 'Repose le compte administrateur décrit par HERITAGE_ADMIN_* (sans purger).',
)]
final class AmorcerLesComptesCommand extends Command
{
    public function __construct(private readonly AmorcageDuCompteAdministrateur $amorcage)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $resultat = $this->amorcage->amorcer();

        if (null === $resultat['utilisateur']) {
            // Pas une erreur d'exécution : une configuration absente. On sort en échec
            // pour qu'un script de déploiement le voie, mais on dit quoi faire.
            $io->warning($resultat['motif']);

            return Command::FAILURE;
        }

        $resultat['fait'] ? $io->success($resultat['motif']) : $io->info($resultat['motif']);

        $io->writeln(\sprintf(
            '  %s — rôles : %s — statut : %s',
            $resultat['utilisateur']->getEmail(),
            implode(', ', $resultat['utilisateur']->getRoles()),
            $resultat['utilisateur']->getStatus()->value,
        ));

        // Le rattachement à l'ego exige `Tree` et `Membership` : il vit dans la racine de
        // composition `src/DataFixtures/`, aucun module n'ayant le droit de référencer
        // les deux autres (ADR 0002). Il se pose avec `--append`, jamais sans.
        $io->note('Rattachement à l’ego : bin/console doctrine:fixtures:load --append --group=amorcage');

        return Command::SUCCESS;
    }
}

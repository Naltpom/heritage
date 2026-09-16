<?php

declare(strict_types=1);

namespace App\Module\Tree\Command;

use App\Module\Tree\Entity\Person;
use App\Module\Tree\Service\NormalisateurNom;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `heritage:recalculer-les-cles-de-recherche` — la reprise de `tree_person.search_key`.
 *
 * ## Pourquoi une commande et non une migration
 *
 * Parce qu'une migration ne peut pas replier les accents **de la bonne façon**. La
 * formule de la clé passe par {@see \App\Module\Core\Service\CleDeRecherche}, dont tout
 * l'objet est qu'il n'existe **qu'une** définition du repliement : la réécrire en SQL ou
 * en PL/pgSQL en créerait une seconde, et « le jour où elles auraient divergé d'un seul
 * caractère, la recherche aurait cessé de trouver les noms qui le portent — sans erreur,
 * sans test rouge ». Une reprise qui réintroduit le défaut qu'elle répare n'est pas une
 * reprise.
 *
 * Second motif, moins visible et tout aussi décisif : `Person` porte un
 * `#[ORM\Version]`. Toute ligne réécrite incrémente sa version, **donc périme les
 * demandes en attente qui la visaient** (§ 9.6) — leur `base_version` ne correspond plus.
 * Ce n'est pas un geste à faire pendant un déploiement, sans que personne ne l'ait
 * décidé : c'est un geste d'administration, à un moment choisi, dont on lit le compte
 * avant de le confirmer. D'où `--dry-run`, et d'où le fait que la commande **annonce
 * combien de demandes en attente seraient touchées**.
 *
 * ## On n'écrit que ce qui change
 *
 * Même règle qu'à {@see \App\Module\Tree\Service\RecalculDesColonnesDerivees} : on
 * compare avant d'écrire. Sur la base de travail, le passage du surnom dans la clé fait
 * bouger **3** lignes sur 279 — et non 12, parce que
 * `CleDeRecherche::cleDeNom()` déduplique les mots et que 9 des 12 surnoms reprennent
 * déjà l'un des prénoms. Réécrire les 12 aurait incrémenté neuf versions pour rien.
 *
 * L'entrée du **nom d'usage** dans la clé se reprend par la même commande, et la
 * déduplication y joue le même rôle : 97 fiches en portent un, une seule l'avait déjà
 * dans sa clé.
 *
 * ## Idempotente
 *
 * Rejouée, elle ne trouve plus rien à écrire. C'est ce qui la rend sûre à lancer quand on
 * doute de l'état de la colonne.
 */
#[AsCommand(
    name: 'heritage:recalculer-les-cles-de-recherche',
    description: 'Recalcule tree_person.search_key avec la formule courante, et n’écrit que les lignes qui changent.',
)]
final class RecalculerLesClesDeRechercheCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NormalisateurNom $noms,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Compte les lignes à reprendre et n’écrit rien.',
        );
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $style = new SymfonyStyle($entree, $sortie);
        $blanc = (bool) $entree->getOption('dry-run');

        /** @var list<Person> $personnes */
        $personnes = $this->em->getRepository(Person::class)->findAll();

        $balayees = \count($personnes);

        if (0 === $balayees) {
            $style->warning('Aucune personne en base : rien à recalculer.');

            return Command::SUCCESS;
        }

        $aReprendre = [];

        foreach ($personnes as $personne) {
            $attendue = $this->noms->cleDeRecherche(
                $personne->getGivenNames(),
                $personne->getSurname(),
                $personne->getNickname(),
                $personne->getMarriedName(),
            );

            if ($attendue === $personne->getSearchKey()) {
                continue;
            }

            $aReprendre[] = [$personne, $attendue];
        }

        $style->definitionList(
            ['Fiches balayées' => $balayees],
            ['Clés à reprendre' => \count($aReprendre)],
            ['Déjà conformes' => $balayees - \count($aReprendre)],
        );

        if ([] === $aReprendre) {
            $style->success('Toutes les clés sont conformes à la formule courante.');

            return Command::SUCCESS;
        }

        if ($blanc) {
            $style->note(\sprintf(
                '%d ligne(s) seraient réécrites, donc autant de `version` incrémentées — '
                .'les demandes en attente qui visent ces fiches deviendraient caduques (§ 9.6). '
                .'Relancez sans --dry-run pour appliquer.',
                \count($aReprendre),
            ));

            return Command::SUCCESS;
        }

        foreach ($aReprendre as [$personne, $attendue]) {
            $personne->setSearchKey($attendue)->toucher();
        }

        $this->em->flush();

        $style->success(\sprintf('%d clé(s) de recherche reprises.', \count($aReprendre)));

        return Command::SUCCESS;
    }
}

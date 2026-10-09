<?php

declare(strict_types=1);

namespace App\Module\Proposal\Command;

use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Repository\ChangeRequestRepository;
use App\Module\Proposal\Repository\DraftPersonRepository;
use App\Module\Proposal\Service\CleDeDoublon;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `heritage:recalculer-les-cles-de-doublon` — la reprise de `prp_change_request.dedup_key`.
 *
 * ## Pourquoi elle existe
 *
 * {@see CleDeDoublon} repliait les accents par `iconv('UTF-8', 'ASCII//TRANSLIT')`, dont
 * le résultat dépend de la bibliothèque du système. Sous la musl d'Alpine — l'image de
 * production — « Génévrolle » donnait `g n vrolle` ; le correctif la fait passer par
 * {@see \App\Module\Core\Service\CleDeRecherche::replier()}, qui rend `genevrolle`
 * partout. Les demandes **déjà soumises** portent l'ancienne clé : une nouvelle demande
 * sur la même personne ne s'en rapprocherait pas, puisque leurs deux clés diffèrent
 * désormais d'une formule. Il faut donc les reprendre, une fois, après le déploiement.
 *
 * ## Pourquoi une commande et non une migration
 *
 * La même raison qu'à `heritage:recalculer-les-cles-de-recherche` : une migration ne peut
 * pas replier les accents **de la bonne façon**. Le repliement n'a qu'une définition, en
 * PHP ; le réécrire en SQL en créerait une seconde, et c'est très exactement le défaut que
 * ce correctif retire.
 *
 * ## Ce qu'elle balaie, et ce qu'elle laisse
 *
 * **Les demandes `en_attente` de nature `creation_personne`, et elles seules.**
 *
 * - `en_attente` : `dedup_key` n'est lue que par `ChangeRequestRepository::
 *   doublonsProbablesDe()`, qui ne rapproche que des demandes en attente — c'est aussi la
 *   condition de l'index partiel `idx_prp_request_dedup`. Une demande tranchée garde sa
 *   clé d'époque, que plus personne ne compare : la réécrire changerait l'historique
 *   sans rien réparer. Un brouillon n'a pas de clé du tout, elle est posée à la
 *   soumission ;
 * - `creation_personne` : c'est la seule nature qui porte des fiches proposées, donc la
 *   seule dont la soumission pose une clé (`ConstructeurDeCreation::
 *   verifierAvantSoumission()`). Un `ajout_lien` passe par le même constructeur mais n'a
 *   aucun brouillon, donc aucune clé.
 *
 * ## Le même calcul qu'à la soumission, pas une copie
 *
 * La clé attendue est celle que rendrait la soumission **aujourd'hui** :
 * {@see CleDeDoublon::pourLesBrouillons()} sur les fiches de la demande, lues par
 * `DraftPersonRepository::pourDemande()` — la branche qu'emprunte
 * `verifierAvantSoumission()` pour une demande déjà enregistrée. Une seule nuance, écrite
 * pour qu'on ne la découvre pas : une demande créée **et** soumise dans la même requête
 * lisait ses fiches dans l'ordre du corps reçu, et non dans l'ordre des références. Les
 * deux ne diffèrent que pour une demande à plusieurs fiches dont le corps ne les rangeait
 * pas `#p1`, `#p2`… — et la clé reprise est alors celle de la première fiche **par
 * référence**, qui est aussi celle qu'une demande refaite recevrait.
 *
 * Une clé recalculée à `null` (plus aucune fiche ne donne de nom repliable) **est**
 * écrite : c'est ce que la soumission aurait laissé, et garder l'ancienne clé ferait
 * rapprocher la demande au nom d'une formule abandonnée.
 *
 * ## Ni `toucher()`, ni version
 *
 * `ChangeRequest` ne porte pas de `#[ORM\Version]`, et la clé n'est pas une donnée de la
 * demande : c'est un index de rapprochement, que ni l'auteur ni le graphe ne lisent.
 * `updated_at` reste donc tel quel — il nourrit l'`ETag` du graphe
 * (`LectureDesPropositions::empreinteDesDemandes()`), et le bouger invaliderait le cache de
 * l'arbre de chaque auteur pour un affichage qui ne change pas d'un pixel.
 *
 * ## Idempotente, et lisible avant d'agir
 *
 * On compare avant d'écrire : rejouée, elle ne trouve plus rien. `--dry-run` compte sans
 * écrire, et la commande annonce dans les deux modes **combien de demandes ont au moins un
 * doublon probable, avant et après** — c'est le nombre que l'administrateur verra bouger
 * dans sa file, et le seul qui dise si la reprise a servi. `-v` détaille les clés, demande
 * par demande ; elles portent des noms de personnes proposées, d'où leur absence de la
 * sortie par défaut.
 */
#[AsCommand(
    name: 'heritage:recalculer-les-cles-de-doublon',
    description: 'Recalcule prp_change_request.dedup_key des créations en attente avec le repliement de Core, et n’écrit que les lignes qui changent.',
)]
final class RecalculerLesClesDeDoublonCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChangeRequestRepository $demandes,
        private readonly DraftPersonRepository $brouillons,
        private readonly CleDeDoublon $cleDeDoublon,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Compte les clés à reprendre et n’écrit rien.',
        );
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $style = new SymfonyStyle($entree, $sortie);
        $blanc = (bool) $entree->getOption('dry-run');

        /** @var list<ChangeRequest> $demandes */
        $demandes = $this->demandes->findBy(
            ['status' => RequestStatus::EnAttente, 'kind' => RequestKind::CreationPersonne],
            ['id' => 'ASC'],
        );

        $balayees = \count($demandes);

        if (0 === $balayees) {
            $style->success('Aucune création de personne en attente : rien à recalculer.');

            return Command::SUCCESS;
        }

        /** @var array<int, string|null> $avant clé actuelle, par identifiant de demande */
        $avant = [];
        /** @var array<int, string|null> $apres clé attendue, par identifiant de demande */
        $apres = [];
        /** @var list<array{0: ChangeRequest, 1: string|null}> $aReprendre */
        $aReprendre = [];

        foreach ($demandes as $demande) {
            $id = (int) $demande->getId();
            $attendue = $this->cleDeDoublon->pourLesBrouillons($this->brouillons->pourDemande($demande));

            $avant[$id] = $demande->getDedupKey();
            $apres[$id] = $attendue;

            if ($attendue !== $demande->getDedupKey()) {
                $aReprendre[] = [$demande, $attendue];
            }
        }

        $style->definitionList(
            ['Créations en attente balayées' => $balayees],
            ['Clés à reprendre' => \count($aReprendre)],
            ['Déjà conformes' => $balayees - \count($aReprendre)],
            ['Demandes rapprochées d’au moins une autre' => \sprintf(
                'avant : %d, après : %d',
                $this->rapprochees($avant),
                $this->rapprochees($apres),
            )],
        );

        if ($sortie->isVerbose() && [] !== $aReprendre) {
            $style->table(
                ['Demande', 'Clé actuelle', 'Clé recalculée'],
                array_map(
                    static fn (array $ligne): array => [
                        'n° '.$ligne[0]->getId(),
                        $ligne[0]->getDedupKey() ?? '—',
                        $ligne[1] ?? '—',
                    ],
                    $aReprendre,
                ),
            );
        }

        if ([] === $aReprendre) {
            $style->success('Toutes les clés de doublon sont conformes au repliement courant.');

            return Command::SUCCESS;
        }

        if ($blanc) {
            $style->note(\sprintf(
                '%d clé(s) seraient réécrites. Rien d’autre ne bouge : ni statut, ni horodatage, '
                .'ni l’arbre — la clé ne sert qu’aux « doublons probables » de la vue d’administration. '
                .'Relancez sans --dry-run pour appliquer.',
                \count($aReprendre),
            ));

            return Command::SUCCESS;
        }

        foreach ($aReprendre as [$demande, $attendue]) {
            $demande->setDedupKey($attendue);
        }

        $this->em->flush();

        $style->success(\sprintf('%d clé(s) de doublon reprises.', \count($aReprendre)));

        return Command::SUCCESS;
    }

    /**
     * Combien de demandes partagent leur clé avec au moins une autre — ce que
     * `doublonsProbablesDe()` montrera, demande par demande, dans la vue d'administration.
     *
     * Calculé en mémoire sur l'ensemble déjà chargé : les demandes balayées sont **toutes**
     * celles qui peuvent porter une clé en attente, il n'y a donc personne à oublier.
     *
     * @param array<int, string|null> $cles
     */
    private function rapprochees(array $cles): int
    {
        /** @var array<string, int> $parCle */
        $parCle = [];

        foreach ($cles as $cle) {
            if (null !== $cle) {
                $parCle[$cle] = ($parCle[$cle] ?? 0) + 1;
            }
        }

        $rapprochees = 0;

        foreach ($parCle as $nombre) {
            if ($nombre > 1) {
                $rapprochees += $nombre;
            }
        }

        return $rapprochees;
    }
}

<?php

declare(strict_types=1);

namespace App\Module\Proposal\Repository;

use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\RequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see DraftPerson}.
 *
 * @extends ServiceEntityRepository<DraftPerson>
 */
class DraftPersonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DraftPerson::class);
    }

    /**
     * Les brouillons d'une demande, dans l'ordre de leur référence provisoire.
     *
     * @return list<DraftPerson>
     */
    public function pourDemande(ChangeRequest $demande): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.request = :demande')
            ->setParameter('demande', $demande)
            ->orderBy('b.localRef', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Le nom des personnes proposées par chaque demande, **en une requête** pour toute
     * une page de la file : « Prénoms Nom », dans l'ordre des références provisoires.
     *
     * @param list<int> $demandeIds
     *
     * @return array<int, list<string>> demande ⇒ noms
     */
    public function nomsParDemande(array $demandeIds): array
    {
        if ([] === $demandeIds) {
            return [];
        }

        /** @var list<array{demande: int|string, givenNames: string, surname: string}> $lignes */
        $lignes = $this->createQueryBuilder('b')
            ->select('IDENTITY(b.request) AS demande', 'b.givenNames', 'b.surname')
            ->andWhere('b.request IN (:demandes)')
            ->setParameter('demandes', $demandeIds)
            ->orderBy('b.localRef', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $noms = [];

        foreach ($lignes as $ligne) {
            $nom = trim($ligne['givenNames'].' '.$ligne['surname']);
            $noms[(int) $ligne['demande']][] = '' === $nom ? 'Personne sans nom' : $nom;
        }

        return $noms;
    }

    /**
     * Un brouillon par sa référence provisoire, **dans sa demande**.
     *
     * L'unicité de `local_ref` est **par demande** (`uniq_prp_draft_person_ref`) : `#p1`
     * de la demande 12 et `#p1` de la demande 13 n'ont aucun rapport. Une recherche par
     * référence seule n'aurait donc pas de sens, et c'est pour cela que cette méthode
     * exige la demande.
     */
    public function parReference(ChangeRequest $demande, string $localRef): ?DraftPerson
    {
        return $this->findOneBy(['request' => $demande, 'localRef' => $localRef]);
    }

    /**
     * Les fiches proposées par les demandes **non encore tranchées** que ce lecteur a le
     * droit de voir.
     *
     * `$auteurUserId` à `null` veut dire « toutes », et n'est passé que pour un
     * administrateur hors bascule « voir comme un membre ». C'est la règle du § 13.3,
     * la même qu'au calque et à la projection, et le paramètre est **obligatoire à
     * l'appel** plutôt que nullable par défaut : un appelant qui l'oublierait
     * montrerait à n'importe quel membre les propositions des autres.
     *
     * Les `en_attente`, et **les brouillons de `$brouillonsDe`** — les siens, jamais ceux
     * d'un autre, administrateur compris : un brouillon non envoyé n'est visible que de
     * son auteur (§ 5.7). Le commanditaire, le 05/10/2026 : « il faut que la liste des
     * personnes, même mes demandes non acceptées, fasse partie de la liste aussi ». On
     * enregistre un soir la fiche du grand-père sans l'envoyer, et le lendemain on veut
     * y rattacher son fils. Une demande tranchée, elle, n'a plus rien à offrir, soit
     * qu'elle ait produit une personne réelle, soit qu'elle ait été refusée.
     *
     * @return list<DraftPerson>
     */
    public function enAttenteVisibles(?int $auteurUserId, ?int $brouillonsDe = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->join('b.request', 'd')
            ->addOrderBy('d.submittedAt', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->addOrderBy('b.localRef', 'ASC');

        $soumises = null === $auteurUserId
            ? 'd.status = :statut'
            : '(d.status = :statut AND d.authorUserId = :auteur)';

        if (null !== $auteurUserId) {
            $qb->setParameter('auteur', $auteurUserId);
        }

        if (null !== $brouillonsDe) {
            $qb->andWhere($soumises.' OR (d.status = :brouillon AND d.authorUserId = :brouillonsDe)')
                ->setParameter('brouillon', RequestStatus::Brouillon)
                ->setParameter('brouillonsDe', $brouillonsDe);
        } else {
            $qb->andWhere($soumises);
        }

        $qb->setParameter('statut', RequestStatus::EnAttente);

        /** @var list<DraftPerson> $lignes */
        $lignes = $qb->getQuery()->getResult();

        return $lignes;
    }

    /**
     * Les fiches que la **ressemblance** parcourt (§ 6.6 quater) : celles des demandes
     * `creation_personne` **en attente, tous auteurs confondus**, et les brouillons non
     * envoyés **du lecteur effectif, et de lui seul**.
     *
     * ── Pourquoi tous les auteurs, quand {@see enAttenteVisibles()} s'en garde ────────
     *
     * C'est l'exception écrite au § 13.3 du contrat, et la décision du commanditaire du
     * 09/10/2026 : un membre doit apprendre qu'**une proposition semblable attend déjà**,
     * sans quoi il dépose le doublon que la file d'administration passera ensuite à
     * écarter. Ce dépôt ne décide **rien** de ce qui sort : il rend des lignes, et c'est
     * {@see \App\Module\Proposal\Service\RessemblanceDesPropositions} qui choisit, fiche
     * par fiche, la forme complète ou la forme discrète — un vivant proposé par un autre
     * ne sort jamais qu'en signal, sans nom, ni date, ni auteur, ni numéro de demande.
     *
     * ── Les brouillons non envoyés restent privés ───────────────────────────────────
     *
     * Ceux de `$lecteurEffectif` seulement, **administrateur compris** : un brouillon non
     * envoyé n'est visible que de son auteur (§ 5.7), et le lecteur effectif est le membre
     * emprunté sous la bascule « voir comme un membre » — c'est ce que verrait le membre.
     *
     * `$sauf` retire une demande entière : celle qu'on est en train de compléter ne doit
     * pas se signaler à elle-même.
     *
     * @return list<DraftPerson>
     */
    public function pourRessemblance(int $lecteurEffectif, ?int $sauf): array
    {
        $qb = $this->createQueryBuilder('b')
            ->addSelect('d')
            ->join('b.request', 'd')
            ->andWhere('d.kind = :creation')
            ->andWhere('d.status = :enAttente OR (d.status = :brouillon AND d.authorUserId = :lecteur)')
            ->setParameter('creation', RequestKind::CreationPersonne)
            ->setParameter('enAttente', RequestStatus::EnAttente)
            ->setParameter('brouillon', RequestStatus::Brouillon)
            ->setParameter('lecteur', $lecteurEffectif)
            ->addOrderBy('d.id', 'ASC')
            ->addOrderBy('b.localRef', 'ASC');

        if (null !== $sauf) {
            $qb->andWhere('d.id <> :sauf')->setParameter('sauf', $sauf);
        }

        /** @var list<DraftPerson> $lignes */
        $lignes = $this->sansLesComplements($qb)->getQuery()->getResult();

        return $lignes;
    }

    /**
     * **Le point d'accroche des compléments** — sans effet tant qu'ils n'existent pas.
     *
     * Le plan de la fonction (PR 4) ajoute `prp_change_request.complement_de_id` : une
     * demande qui **complète** la proposition d'un autre membre, et que l'administrateur
     * fusionne. Un complément porte une fiche, mais ce n'est pas une proposition de plus —
     * le signaler comme « une proposition semblable attend déjà » montrerait deux fois la
     * même personne, et inviterait à compléter le complément. Le jour où la colonne existe,
     * cette méthode gagne `AND d.complementDe IS NULL`, et c'est le seul endroit à toucher :
     * la ressemblance ne parcourt les fiches que par {@see pourRessemblance()}.
     */
    private function sansLesComplements(QueryBuilder $qb): QueryBuilder
    {
        return $qb;
    }

    /**
     * Une fiche proposée par son identifiant de ligne, **si ce lecteur a le droit de la
     * voir** — le reste du monde reçoit `null`, c'est-à-dire un `404`.
     *
     * Le statut n'est **pas** filtré ici, et c'est voulu : entre la recherche et le
     * dépôt, la demande citée a pu être validée. Le brouillon porte alors son
     * `resolved_person_id`, et l'appelant désigne la personne réelle plutôt que de
     * refuser une référence qui vient justement d'aboutir. C'est au point d'entrée de
     * trancher — voir {@see \App\Module\Proposal\Service\ConstructeurDeCreation} —,
     * parce que lui seul sait quoi faire de chaque cas.
     */
    public function visiblePourLecteur(int $id, ?int $auteurUserId): ?DraftPerson
    {
        $qb = $this->createQueryBuilder('b')
            ->join('b.request', 'd')
            ->andWhere('b.id = :id')
            ->setParameter('id', $id);

        if (null !== $auteurUserId) {
            $qb->andWhere('d.authorUserId = :auteur')->setParameter('auteur', $auteurUserId);
        }

        $ligne = $qb->getQuery()->getOneOrNullResult();

        return $ligne instanceof DraftPerson ? $ligne : null;
    }
}

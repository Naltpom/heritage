<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use App\Module\Assistant\Contract\ArbrePourLAssistant;
use App\Module\Assistant\Contract\CreationDeFichePourLeRattachement;
use App\Module\Core\Entity\User;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Membership\Contract\ComptesARattacher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  « CRÉER LA FICHE ET RATTACHER » — un seul geste, une seule transaction.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * L'administrateur a conclu que la personne inscrite est absente de l'arbre. Le geste :
 *
 * 1. **construire le corps côté serveur** — jamais reçu du navigateur : la base est la
 *    création de la suggestion gardée (celle que l'administrateur a relue), ou, à défaut,
 *    le corps minimal tiré de la déclaration ; l'identité de `#p1` est **reprise de la
 *    déclaration** ; les parents choisis remplacent sa filiation ({@see CorpsDuTitulaire}) ;
 * 2. `Proposal` **dépose puis valide** la demande au nom de l'administrateur
 *    (`CreationDeFichePourLeRattachement`) — ADR 0004 : la fiche naît d'une demande
 *    validée, avec ses révisions ;
 * 3. `Membership` **rattache** le compte à la fiche neuve **et l'active**
 *    (`ComptesARattacher::rattacherAFicheCreee()`) — portrait en attente compris.
 *
 * ── Pourquoi une transaction englobante ─────────────────────────────────────────
 *
 * Parce que les trois pas n'ont de sens qu'ensemble. Une fiche créée pour un compte que
 * le rattachement refuse (`409 personne_deja_rattachee`) serait une fiche que personne
 * n'a voulue — et un doublon le jour où l'on rattache le compte à la bonne. Toute erreur
 * annule tout : la demande, ses brouillons, les révisions, la fiche, les notifications
 * (des lignes en base, annulées avec le reste — c'est voulu).
 *
 * Le cas « un autre administrateur a refusé le compte entre-temps » ne va même pas
 * jusque-là : le compte est **relu sous verrou en premier pas de la transaction**
 * (`ComptesARattacher::verrouillerPourLeRattachement()`), avant que `Proposal` ne touche
 * à rien — `409 compte_deja_decide` ; un refus concurrent, lui, attend la fin.
 *
 * Les transactions internes de `Proposal` s'y logent en points de sauvegarde (DBAL 4).
 * Doctrine ferme l'`EntityManager` sur l'échec d'une transaction : la requête s'arrête
 * là, et l'erreur de domaine (`409`, `422`) remonte telle quelle au front.
 */
final readonly class CreationEtRattachement
{
    /** Le mot déposé avec la demande quand l'administrateur n'en écrit pas. */
    public const string MESSAGE_PAR_DEFAUT = 'Fiche créée au rattachement d’une inscription.';

    public function __construct(
        private ComptesARattacher $comptes,
        private CreationDeFichePourLeRattachement $creation,
        private ArbrePourLAssistant $arbre,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<int> $parents 0 à 2 fiches de l'arbre, déjà lues comme des entiers
     *
     * @return array{membre: array<string, mixed>, personId: int, demandeId: int}
     *
     * @throws NotFoundException   le compte n'est pas (ou plus) « à rattacher » et en attente
     * @throws ConflictException   `compte_deja_decide` : refusé ou rattaché depuis la lecture de la déclaration
     * @throws ValidationException un parent inexistant, ou un corps qui trahit ses invariants
     */
    public function executer(int $memberId, array $parents, ?string $message, User $administrateur): array
    {
        $declaration = $this->comptes->declaration($memberId)
            ?? throw new NotFoundException('Ce compte n’attend plus de rattachement.');

        foreach ($parents as $parent) {
            if (null === $this->arbre->personne($parent)) {
                throw new ValidationException(['parents' => \sprintf('La personne n° %d n’existe pas dans l’arbre.', $parent)]);
            }
        }

        $base = $declaration->suggestion['creation']['corps'] ?? null;
        $corps = \is_array($base)
            ? CorpsDuTitulaire::imposerLIdentite($base, $declaration)
            : CorpsDuTitulaire::minimal($declaration);
        $corps = CorpsDuTitulaire::avecLesParents($corps, $parents);
        CorpsDuTitulaire::verifier($corps);

        $message = null === $message || '' === trim($message) ? self::MESSAGE_PAR_DEFAUT : trim($message);

        return $this->em->wrapInTransaction(function () use ($corps, $message, $memberId, $administrateur): array {
            // D'abord le compte, relu sous verrou : la déclaration lue plus haut a pu être
            // refusée depuis (un autre administrateur, un autre onglet). Vérifier **avant**
            // de créer, c'est ne rien créer du tout — pas même une demande annulée ensuite.
            $this->comptes->verrouillerPourLeRattachement($memberId);

            $fiche = $this->creation->creerEtValider($corps, $message);
            $membre = $this->comptes->rattacherAFicheCreee($memberId, $fiche->personId, $fiche->libelle, $administrateur);

            return ['membre' => $membre, 'personId' => $fiche->personId, 'demandeId' => $fiche->demandeId];
        });
    }
}

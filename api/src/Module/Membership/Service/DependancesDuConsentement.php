<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Membership\Entity\AutorisationDeNaissance;
use App\Module\Proposal\Contract\DependanceDeFiche;
use App\Module\Proposal\Contract\DependanceMesuree;
use App\Module\Proposal\Contract\DependancesDUneFiche;
use App\Module\Proposal\Contract\FicheASupprimer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'autorisation signée de partager la date de naissance d'une fiche (ADR 0016).
 *
 * Le compte membre et l'invitation active — les deux autres choses que `Membership`
 * accroche à une fiche — passent déjà par `Core\Contract\AnnuaireDesMembresInterface`, qui
 * existait avant ce point de greffe ; ils n'y sont pas recopiés.
 *
 * - **Active** : la suppression est **bloquée**. C'est une preuve de consentement, déposée
 *   par un administrateur avec son document ; elle ne part pas en effet de bord d'une autre
 *   opération, et elle ne s'archive pas non plus sans qu'un administrateur l'ait décidé. Dans
 *   le cas d'un doublon, elle doit être déposée **sur l'autre fiche** — sinon la date de
 *   naissance de la personne cesse d'être partagée sans que personne ne l'ait voulu. Le geste
 *   existe : `DELETE /api/administration/personnes/{id}/autorisation-naissance`.
 * - **Archivée** : elle **reste**, et la suppression passe. « Archivée, jamais supprimée » :
 *   c'est la trace de ce qui a été consenti, et quand. `heritage:verifier-integrite` la
 *   relève comme tolérée.
 */
final readonly class DependancesDuConsentement implements DependancesDUneFiche
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function mesurer(FicheASupprimer $fiche): array
    {
        $nombre = (int) $this->em->createQuery(\sprintf(
            'SELECT COUNT(a.id) FROM %s a WHERE a.personId = :personne AND a.archivedAt IS NULL',
            AutorisationDeNaissance::class,
        ))->setParameter('personne', $fiche->personneId)->getSingleScalarResult();

        return $nombre > 0 ? [new DependanceMesuree(DependanceDeFiche::AutorisationDeNaissance, $nombre)] : [];
    }

    /** Rien à effacer : une autorisation active bloque, une archivée est une preuve. */
    public function detacher(FicheASupprimer $fiche): array
    {
        return [];
    }
}

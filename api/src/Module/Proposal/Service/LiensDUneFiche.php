<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Proposal\Entity\DraftLink;
use App\Module\Proposal\Entity\DraftPerson;

/**
 * Les extrémités de lien qui **désignent une fiche proposée**, et les deux gestes qu'on leur
 * applique quand cette fiche change : la **renommer** après une complétion, la **repointer**
 * après une fusion (ADR 0017).
 *
 * ── Deux désignations, et il faut toujours les deux ─────────────────────────────
 *
 * Une fiche se désigne par sa référence provisoire (`#p1`) **dans sa propre demande**, et
 * par l'identifiant de sa ligne (`*_draft_person_id`) **depuis une autre** (§ 6.3 bis).
 * Un geste qui ne regarderait que l'une des deux formes oublierait la moitié des liens, et
 * c'est la moitié qui ne se voit pas depuis la demande qu'on est en train de modifier.
 *
 * Fonctions **pures** : elles modifient le lien reçu et disent si elles l'ont modifié,
 * rien d'autre. C'est l'appelant qui sait s'il faut toucher la demande — et c'est lui qui
 * le fait, parce que l'`ETag` du graphe repose sur `updated_at`.
 */
final class LiensDUneFiche
{
    private function __construct()
    {
    }

    /**
     * Le nom de la fiche, reposé sur les liens qui la citent — après une complétion sur
     * place comme après une fusion.
     *
     * - **Les liens de sa propre demande**, toujours.
     * - **Les liens d'autres demandes**, seulement si elles sont **du même auteur**, ou si
     *   la personne est **décédée**. Une demande d'un autre compte qui cite cette fiche est
     *   celle d'un administrateur (un membre ne cite que ses propres fiches), ou une demande
     *   repointée par une fusion ; y écrire le nom d'un **vivant** recopierait dans la
     *   demande d'un autre une donnée que celui-ci ne voit pas (décision du commanditaire du
     *   09/10/2026 : la proposition d'un vivant faite par un autre membre ne se montre pas).
     *   Le sérialiseur applique la même règle à la lecture
     *   ({@see SerialiseurDeDemande}), si bien que rien ne s'affiche d'autre que ce
     *   qui est stocké.
     *
     * Une **autre** demande dont un libellé change est **touchée** : son nœud porte l'arête
     * vers cette fiche, et l'`ETag` du graphe doit bouger avec elle. La demande de la fiche
     * l'est par l'appelant, qui vient de la réécrire.
     *
     * @param iterable<DraftLink> $liens ceux que rend {@see \App\Module\Proposal\Repository\DraftLinkRepository::citant()},
     *                                   plus ceux que l'appelant vient de créer
     */
    public static function rafraichirLesLibelles(iterable $liens, DraftPerson $fiche): void
    {
        $sienne = $fiche->getRequest();

        foreach ($liens as $lien) {
            $autre = $lien->getRequest();

            if ($autre === $sienne) {
                self::renommer($lien, $fiche);

                continue;
            }

            if (!self::nomPartageable($fiche, $autre->getAuthorUserId())) {
                continue;
            }

            if (self::renommer($lien, $fiche)) {
                $autre->toucher();
            }
        }
    }

    /**
     * Le nom de cette fiche peut-il s'écrire — ou se relire — dans une demande de cet
     * auteur ? Oui pour son propre auteur, oui pour un défunt (un fait historique, visible
     * de tout compte : ADR 0003), non pour le vivant proposé par un autre.
     */
    public static function nomPartageable(DraftPerson $fiche, int $auteurDeLaDemandeQuiCite): bool
    {
        return $fiche->isDeceased() || $fiche->getRequest()->getAuthorUserId() === $auteurDeLaDemandeQuiCite;
    }

    /**
     * Pose le nom courant de la fiche sur chaque extrémité de ce lien qui la désigne.
     *
     * Le libellé stocké est une photographie (ADR 0002, compensation 3) : le sérialiseur
     * relit déjà le nom à chaque rendu, mais l'applicateur, lui, recopie les colonnes dans
     * `prp_revision` au moment de la validation. Une fiche complétée sous son nom
     * définitif garderait sinon, dans l'historique, le nom qu'elle portait au dépôt.
     *
     * @return bool vrai si une colonne a changé
     */
    public static function renommer(DraftLink $lien, DraftPerson $fiche): bool
    {
        $nom = $fiche->nomPropose();
        $change = false;

        if (self::designe($lien->getSubjectDraftRef(), $lien->getSubjectDraftPersonId(), $lien, $fiche)
            && $lien->getSubjectLabel() !== $nom) {
            $lien->setSubjectLabel($nom);
            $change = true;
        }

        if (self::designe($lien->getOtherDraftRef(), $lien->getOtherDraftPersonId(), $lien, $fiche)
            && $lien->getOtherLabel() !== $nom) {
            $lien->setOtherLabel($nom);
            $change = true;
        }

        if (self::designe($lien->getOther2DraftRef(), $lien->getOther2DraftPersonId(), $lien, $fiche)
            && $lien->getOther2Label() !== $nom) {
            $lien->setOther2Label($nom);
            $change = true;
        }

        return $change;
    }

    /**
     * Fait désigner `$vers` à chaque extrémité de ce lien qui désignait `$de` **par
     * l'identifiant de sa ligne** — le repointage d'une fusion : la fiche du complément
     * cesse d'exister comme proposition, celle de l'origine la remplace.
     *
     * Si le lien appartient à la demande de `$vers`, l'extrémité prend la référence
     * provisoire et non l'identifiant : dans sa propre demande, une fiche se désigne par
     * `#p1`, jamais par sa ligne — c'est le filet de
     * {@see ConstructeurDeCreation::ficheProposeeAilleurs()}, et le repointage ne doit pas
     * fabriquer la forme que le dépôt refuse.
     *
     * Les extrémités qui désignent `$de` par sa **référence** ne sont pas touchées : ce
     * sont les liens du complément lui-même, qui reste dans l'historique tel qu'il a été
     * déposé.
     *
     * **Le libellé reste celui que l'auteur connaissait** quand la fiche d'origine est
     * celle d'un vivant proposé par un autre : le nom que l'auteur du lien avait saisi pour
     * son complément, et non celui de l'origine, qu'il ne voit pas
     * ({@see nomPartageable()}).
     *
     * @return bool vrai si une extrémité a été repointée
     */
    public static function repointer(DraftLink $lien, DraftPerson $de, DraftPerson $vers): bool
    {
        $id = $de->getId();

        if (null === $id) {
            return false;
        }

        $nom = self::nomPartageable($vers, $lien->getRequest()->getAuthorUserId())
            ? $vers->nomPropose()
            : $de->nomPropose();
        $chezElle = $lien->getRequest() === $vers->getRequest();
        $versId = $chezElle ? null : $vers->getId();
        $versRef = $chezElle ? $vers->getLocalRef() : null;
        $change = false;

        if ($id === $lien->getSubjectDraftPersonId()) {
            if ($chezElle) {
                $lien->viserSujetBrouillon($vers->getLocalRef());
            } else {
                $lien->viserSujetProposeAilleurs((int) $versId);
            }
            $lien->setSubjectLabel($nom);
            $change = true;
        }

        if ($id === $lien->getOtherDraftPersonId()) {
            $lien->designerAutre(null, $versRef, $nom, $versId);
            $change = true;
        }

        if ($id === $lien->getOther2DraftPersonId()) {
            $lien->designerSecondParent(null, $versRef, $nom, $versId);
            $change = true;
        }

        return $change;
    }

    /** Cette extrémité — sa référence et son identifiant de ligne — désigne-t-elle la fiche ? */
    private static function designe(?string $ref, ?int $ligne, DraftLink $lien, DraftPerson $fiche): bool
    {
        if ($lien->getRequest() === $fiche->getRequest()) {
            return null !== $ref && $ref === $fiche->getLocalRef();
        }

        return null !== $ligne && $ligne === $fiche->getId();
    }
}

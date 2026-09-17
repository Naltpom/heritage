<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

/**
 * Ce qui se passera au clic sur « Valider » — calculé **en lecture seule**, avant.
 *
 * Sans cette information, « l'administrateur découvre la caducité en cliquant, ce qui
 * la rend indistinguable d'une panne » (§ 6.7 du contrat d'API, manque n° 2 du test des
 * trois routes). Elle n'est stockée nulle part : elle se recalcule à chaque lecture,
 * parce qu'elle dépend de l'état courant de l'arbre, qui bouge sans que la demande
 * bouge.
 *
 * **Cette énumération est dans `Service/` et non dans `Enum/`, délibérément.** `Enum/`
 * porte les valeurs qui partent en base (§ 5.7 du modèle) ; celle-ci n'y va jamais —
 * elle est un résultat de calcul, elle ne survit pas à la requête. Les mélanger
 * laisserait croire qu'il existe une colonne `resolution_prevue`, et quelqu'un
 * finirait par l'écrire.
 */
enum ResolutionPrevue: string
{
    /** Aucune version n'a bougé. Le front affiche « Valider ». */
    case Application = 'application';

    /**
     * Une version a bougé, **toutes** les `oldValue` correspondent encore.
     *
     * Le front affiche « Valider (sera rejouée sur la version courante) ». C'est le cas
     * **fréquent** — celui des champs disjoints — et le traiter comme un conflit
     * condamnerait la seconde de deux demandes déposées la même semaine, « alors que
     * contribuer est le but du produit » (arbitrage C).
     */
    case Rejeu = 'rejeu';

    /**
     * **Une** `oldValue` a changé. Le bouton valider est désactivé : la demande est
     * morte, elle ne se réessaie pas, elle se refait (§ 6.10).
     */
    case Caducite = 'caducite';

    /** La ligne visée n'existe plus — supprimée ou fusionnée. C'est une caducité aussi. */
    case CibleDisparue = 'cible_disparue';

    /**
     * Cette demande s'appuie sur une fiche proposée par **une autre demande encore en
     * attente** : il n'y a rien à condamner, il y a un ordre à respecter.
     *
     * Le front affiche « À valider après la demande n° 12 » et **éteint** le bouton sans
     * le griser comme une caducité : les deux états n'appellent pas la même action. Une
     * caducité se refait ; celle-ci s'attend, et elle se débloque toute seule dès que
     * l'autre demande est tranchée.
     *
     * C'est la seule résolution qui ne soit ni un succès ni une fin : la demande reste
     * `en_attente`, et rien n'est écrit — pas même son statut.
     */
    case DependanceEnAttente = 'dependance_en_attente';

    /**
     * La demande sur laquelle celle-ci s'appuyait a été **refusée, retirée ou rendue
     * caduque** — ou sa fiche a disparu. La personne désignée ne naîtra donc jamais.
     *
     * C'est une caducité, et de la même nature que `cible_disparue` : personne n'a
     * refusé **celle-ci**, mais elle ne peut plus s'appliquer. L'auteur est notifié et
     * peut la refaire en désignant quelqu'un d'autre.
     */
    case DependanceMorte = 'dependance_morte';

    public function estCaduque(): bool
    {
        return self::Caducite === $this
            || self::CibleDisparue === $this
            || self::DependanceMorte === $this;
    }
}

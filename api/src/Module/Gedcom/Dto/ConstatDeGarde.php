<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Dto;

/**
 * Ce que la garde a vu avant de laisser passer — ou de refuser.
 *
 * Trois nombres, et la certitude de les avoir lus : `$aPuRegarder` distingue « aucun
 * compte » de « je n'ai pas su compter les comptes ». La distinction n'est pas
 * théorique — une garde qui compte 0 sur une table qu'elle ne trouve pas laisse tout
 * passer en se croyant rassurante, et c'est la forme de panne que ce projet a rencontrée
 * neuf fois : **un dispositif de contrôle qui ne peut plus rien voir reste vert.**
 */
final readonly class ConstatDeGarde
{
    public function __construct(
        public int $comptes,
        public int $rattachements,
        public int $personnes,
        public bool $aPuRegarder,
    ) {
    }

    /**
     * L'arbre est vide alors que des comptes ou des rattachements existent.
     *
     * C'est la signature d'un schéma détruit sous des comptes vivants — ou, tout aussi
     * légitimement, d'un premier import sur une base qu'on vient d'amorcer avec
     * `heritage:creer-administrateur`. Les deux se ressemblent exactement ; c'est
     * pourquoi on demande, au lieu de deviner.
     */
    public function arbreVideSousDesComptes(): bool
    {
        return 0 === $this->personnes && ($this->comptes > 0 || $this->rattachements > 0);
    }

    /**
     * Le cas **dangereux**, à distinguer du simple amorçage.
     *
     * Un rattachement porte `mbr_member.person_id`, un entier sans clé étrangère (§ 0.4).
     * Tant que l'arbre est vide, cet entier ne désigne rien ; dès qu'on le repeuple, il
     * désignera **la personne qui atterrira sur cet identifiant**. Si le GEDCOM a changé
     * entre-temps — et il change, l'arbre est vivant —, ce n'est pas la même.
     */
    public function desRattachementsVontEtreReinterpretes(): bool
    {
        return 0 === $this->personnes && $this->rattachements > 0;
    }
}

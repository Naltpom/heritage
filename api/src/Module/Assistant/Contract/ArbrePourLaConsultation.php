<?php

declare(strict_types=1);

namespace App\Module\Assistant\Contract;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  POINT DE BRANCHEMENT DU MODULE `Tree` — ce que « Poser une question » lit EN PLUS
 *  de {@see ArbrePourLAssistant}.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Un membre demande « Qui est né à Étaples ? », « Comment suis-je lié à Marie
 * Becquelin ? », « Que sait-on du grand-père de Juliette ? ». Les deux outils de
 * l'assistant de proposition — chercher par le nom, lire une fiche — ne suffisent pas à y
 * répondre honnêtement : il faut **les sources** (un acte, ou un arbre en ligne ?), **la
 * parenté** depuis le lecteur, et une recherche **par lieu ou par période**.
 *
 * Déclaré ici, implémenté dans `Tree` (`Tree\Service\VueDeLArbrePourLaConsultation`) :
 * même forme que {@see ArbrePourLAssistant}, pour la même raison — la règle 3 de
 * l'ADR 0002 n'autorise un `use` inter-modules que vers `Core\…` ou `<Autre>\Contract\…`.
 *
 * ── Comme {@see ArbrePourLAssistant}, ce port NE FILTRE PAS PAR LE CERCLE ─────────
 *
 * Il ne sait pas qui lit, et c'est voulu : une seconde implémentation de la règle du
 * cercle est exactement ce que l'ADR 0003 interdit (« une règle de confidentialité ne se
 * trompe qu'une fois pour fuir »). **C'est l'appelant qui filtre, avant de transmettre
 * quoi que ce soit au modèle** — et la différence avec l'assistant de proposition est là :
 * en consultation, une personne que le lecteur ne voit pas en entier n'est **jamais
 * décrite au modèle**, ni par son nom ni par ses dates (`Assistant\Service\OutilsDeConsultation`).
 * Ce port ne doit donc être appelé que pour une personne que le lecteur voit en entier, ou
 * pour un balayage dont chaque ligne repasse ensuite par le cercle.
 */
interface ArbrePourLaConsultation
{
    /**
     * Ce que la fiche établit au-delà de l'identité : les faits, les **sources citées**
     * avec ce qui les tient (acte, index, arbre en ligne), les notes. `null` si la
     * personne n'existe pas.
     *
     * Les adresses d'arbres en ligne sont retirées des textes, comme sur la fiche
     * (`AdressesDArbresEnLigne`) : le modèle sait qu'une donnée vient d'un arbre en ligne
     * par le niveau d'appui, il n'a pas besoin de l'URL.
     */
    public function dossier(int $personneId): ?DossierDeLaPersonne;

    /**
     * Le libellé de parenté de `$personneId` **vu depuis `$egoPersonneId`** — « arrière-
     * grand-mère », « cousine germaine », « épouse de ton oncle » —, calculé par le même
     * moteur que le champ `parente` de `GET /api/arbre/personnes/{id}`. `null` quand
     * aucun lien n'est établi, ou que l'une des deux fiches n'existe pas.
     */
    public function parente(int $egoPersonneId, int $personneId): ?string;

    /**
     * Les personnes nées ou décédées **dans un lieu** (correspondance par mots, sans
     * accents ni casse : « etaples » trouve « Étaples, Pas-de-Calais ») et/ou **dans une
     * période** (années incluses, la date incertaine comptant dès que sa fourchette touche
     * la période), **sur tout l'arbre**.
     *
     * Au moins l'un des deux critères est donné. Le résultat est **sur-échantillonné** :
     * l'appelant en retire les personnes hors cercle **avant** de couper à son propre
     * plafond — un `LIMIT` posé avant le filtre dirait combien on cache (§ 9.2 du modèle).
     *
     * @param 'naissance'|'deces'|'tous' $evenement
     *
     * @return list<PersonneDeLArbre> au plus `$limite`, triées par date de l'événement
     */
    public function parLieuEtPeriode(?string $lieu, ?int $anneeMin, ?int $anneeMax, string $evenement, int $limite): array;
}

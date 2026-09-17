<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Tree\Service\CalculParente;
use App\Module\Tree\Service\FournisseurDeJeuDePlacement;
use App\Module\Tree\ValueObject\IdentifiantDeNoeud;
use App\Module\Tree\ValueObject\JeuDePlacement;

/**
 * Le champ `parente` d'une fiche : « arrière-grand-mère », « cousine germaine, éloignée
 * de 1 génération », « époux de ton oncle ».
 *
 * C'est le champ qui donne son sel à l'application — le commanditaire tient à ce que
 * chaque fiche dise **comment la personne se rattache à lui**. Son viewer d'origine
 * l'affichait pour 165 personnes.
 *
 * ## Le point qui décide de tout : relative à qui ?
 *
 * {@see CalculParente} calcule les libellés depuis l'**ego du jeu de placement** qu'on lui
 * passe, c'est-à-dire, tel qu'il sort du fournisseur, depuis `tree.personne_racine_id` —
 * la racine de l'arbre. Or le § 3.1 du modèle veut `parente` **relative au lecteur**, et
 * « il y aura autant de lecteurs que de comptes ».
 *
 * Servir la parenté de la racine à tout le monde serait juste pour un seul compte et faux
 * pour tous les autres, **sans en avoir l'air** : « arrière-grand-mère » est une phrase
 * parfaitement plausible, y compris quand elle désigne l'aïeule de quelqu'un d'autre.
 * C'est le genre d'erreur qu'on ne découvre qu'en interrogeant un utilisateur.
 *
 * On rebâtit donc le jeu **avec le lecteur pour ego**, ce qui ne coûte rien : le jeu est
 * un objet valeur, et seul son `egoId` change.
 *
 * Le choix de cet ego ne se décide pas ici : il vient de {@see PersonneDuLecteur}, qui
 * le sert aussi à la mise en page du graphe. Deux chemins qui en décideraient chacun de
 * leur côté finiraient par n'en décider qu'un — on servirait la parenté d'un lecteur sur
 * la mise en page d'un autre, et rien dans la charge ne le dirait.
 *
 * ## Quand on rend `null`, et pourquoi c'est la bonne réponse
 *
 * - **Compte en attente de validation** : il n'a pas de rattachement établi, donc pas de
 *   point de vue. Lui servir une parenté reviendrait à confirmer le rattachement qu'il
 *   *prétend* avoir, avant qu'un administrateur ne l'ait validé.
 * - **Compte sans rattachement, ou annuaire non branché** : rien à calculer.
 * - **Sous la bascule, si le compte du membre emprunté n'a pas pu être résolu** : on ne
 *   sert jamais la parenté de l'**administrateur** sur un écran qui prétend montrer celle
 *   d'un membre. C'est {@see LecteurDeLArbre::identiteEffective()} qui ferme, en rendant
 *   `null` — et c'est le même mécanisme qui ferme le calque, exprès.
 *
 * Sous la bascule **résolue**, en revanche, on calcule : la parenté servie est celle du
 * membre emprunté, et c'est précisément ce que la bascule existe pour montrer.
 *
 * `null` se lit « non renseigné » et non « interdit » (§ 1.3) : le front n'affiche pas la
 * ligne, il n'affiche pas un tiret.
 */
final class ParenteDuLecteur
{
    /** @var array<int, array<string, string>> les libellés par ego, pour la durée de la requête */
    private array $memoire = [];

    public function __construct(
        private readonly CalculParente $calcul,
        private readonly PersonneDuLecteur $pointDeVue,
        private readonly FournisseurDeJeuDePlacement $fournisseur,
    ) {
    }

    public function pour(LecteurDeLArbre $lecteur, ?int $personneId): ?string
    {
        if (null === $personneId) {
            return null;
        }

        $egoDuLecteur = $this->pointDeVue->pour($lecteur);

        if (null === $egoDuLecteur) {
            return null;
        }

        return $this->libelles($egoDuLecteur)[IdentifiantDeNoeud::personne($personneId)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function libelles(int $egoPersonneId): array
    {
        if (isset($this->memoire[$egoPersonneId])) {
            return $this->memoire[$egoPersonneId];
        }

        $jeu = $this->fournisseur->jeuComplet();

        // Le même arbre, vu d'ailleurs. `JeuDePlacement` est un objet valeur : on n'en
        // recharge pas les 551 lignes pour en changer l'ego.
        $vuDuLecteur = new JeuDePlacement(
            $jeu->personnes,
            $jeu->familles,
            IdentifiantDeNoeud::personne($egoPersonneId),
        );

        return $this->memoire[$egoPersonneId] = $this->calcul->pourTous($vuDuLecteur);
    }
}

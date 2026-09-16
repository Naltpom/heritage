<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Module\Core\Service\AmorcageDuCompteAdministrateur;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Repository\MemberRepository;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Repository\PersonRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * L'amorçage : le compte administrateur du commanditaire, et son rattachement à l'ego de
 * l'arbre.
 *
 * ## ⚠ `doctrine:fixtures:load` **purge la base**
 *
 * C'est écrit dans `config/bundles.php` et ça vaut d'être répété ici, parce que cette
 * fixture-ci est précisément celle qu'on serait tenté de jouer sur une base garnie :
 * elle existe pour **survivre** à un réimport, pas pour en provoquer un. Sur une base
 * qui porte les 274 personnes, on passe par
 *
 *     bin/console heritage:amorcer-comptes
 *
 * qui fait le même travail **sans rien purger**. Ou, à défaut,
 * `doctrine:fixtures:load --append`.
 *
 * ## Pourquoi cette classe compose trois modules
 *
 * Elle touche `Core` (le compte), `Tree` (la personne) et `Membership` (le
 * rattachement). Aucun module n'a le droit d'en référencer un autre (ADR 0002, règle 3),
 * et c'est justement pourquoi ce travail est ici : `src/DataFixtures/` **n'est pas un
 * module**, c'est une racine de composition, au même titre que `config/`. C'est le seul
 * endroit du dépôt où assembler trois modules est légitime.
 *
 * La création du compte, elle, n'est **pas** dupliquée : elle est déléguée à
 * {@see AmorcageDuCompteAdministrateur}, que la commande appelle aussi. Deux chemins,
 * une seule règle.
 *
 * ## Le rattachement se fait par référence GEDCOM, jamais par identifiant
 *
 * `HERITAGE_ADMIN_PERSONNE_XREF` vaut `@I500001@`. **Le `tree_person.id` n'est pas
 * stable** d'un import à l'autre : la colonne est une identité, et un réimport sur base
 * purgée la réattribue. `gedcom_xref` porte une contrainte d'unicité
 * (`uniq_tree_person_xref`) et désigne la même personne quel que soit l'import. Poser
 * l'identifiant numérique dans l'environnement aurait produit, au premier réimport, un
 * rattachement silencieusement dirigé vers quelqu'un d'autre — la pire des pannes de la
 * soirée, celle qui ment sans rien casser.
 */
final class AmorcageFixtures extends Fixture implements FixtureGroupInterface
{
    /**
     * Un groupe nommé, pour pouvoir jouer **cet** amorçage seul :
     * `doctrine:fixtures:load --append --group=amorcage`. Sans le groupe, on entraîne
     * les fixtures anonymisées du § 9 du dossier, qui n'ont rien à faire sur une base
     * portant les vraies données.
     *
     * @return list<string>
     */
    public static function getGroups(): array
    {
        return ['amorcage'];
    }

    public function __construct(
        private readonly AmorcageDuCompteAdministrateur $amorcage,
        private readonly PersonRepository $personnes,
        private readonly MemberRepository $membres,
        // `?string` et non `string` : avec `default::`, une variable d'environnement
        // **vide** est résolue en `null`, pas en chaîne vide. Le typer non nullable
        // faisait échouer la compilation du conteneur dès que la variable était déclarée
        // sans valeur — c'est-à-dire dans l'état livré de `api/.env`.
        #[Autowire(env: 'default::HERITAGE_ADMIN_PERSONNE_XREF')]
        private readonly ?string $xrefDeLEgo = null,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $resultat = $this->amorcage->amorcer();
        $administrateur = $resultat['utilisateur'];

        // Le motif est toujours dit : un amorçage muet qui n'a rien fait ressemble trait
        // pour trait à un amorçage qui a réussi.
        echo '  [amorçage] '.$resultat['motif']."\n";

        if (null === $administrateur) {
            return;
        }

        echo '  [amorçage] '.$this->rattacher($manager, $administrateur->getId(), $administrateur->getEmail())."\n";
    }

    /**
     * Pose un `mbr_member` **actif** entre le compte et l'ego.
     *
     * Un rattachement actif est aujourd'hui la donnée la plus utile de la base : sans un
     * seul, personne ne peut vérifier la bascule « voir comme un membre » ni le cercle
     * d'un membre réel — les deux dispositifs dont la fonction est de contrôler la
     * confidentialité restent donc eux-mêmes incontrôlables.
     *
     * `MatchKind::ManuelAdmin` : ce rattachement ne vient ni d'un pré-lien d'invitation
     * ni d'une recherche confirmée, il est posé par l'administration. Dire autre chose
     * ferait mentir la traçabilité du § 6.1.
     */
    private function rattacher(ObjectManager $manager, ?int $userId, string $courriel): string
    {
        if (null === $userId) {
            return 'Rattachement impossible : le compte n’a pas d’identifiant.';
        }
        if ('' === trim((string) $this->xrefDeLEgo)) {
            return 'HERITAGE_ADMIN_PERSONNE_XREF absent : aucun rattachement posé.';
        }
        if (null !== $this->membres->pourUtilisateur($userId)) {
            return 'Rattachement déjà en place, inchangé.';
        }

        $personne = $this->personnes->findOneBy(['gedcomXref' => trim((string) $this->xrefDeLEgo)]);

        if (!$personne instanceof Person) {
            // L'arbre n'est pas importé : ce n'est pas une erreur, c'est l'ordre normal
            // des choses sur une base neuve. On le dit et on continue.
            return \sprintf('Personne « %s » introuvable : importer le GEDCOM puis rejouer l’amorçage.', (string) $this->xrefDeLEgo);
        }

        $personneId = $personne->getId();

        if (null === $personneId) {
            return 'Personne trouvée sans identifiant : rattachement non posé.';
        }

        if (null !== $this->membres->findOneBy(['personId' => $personneId])) {
            // Une personne ne peut être réclamée que par **un** compte (§ 6.1).
            return 'Cette personne est déjà réclamée par un autre compte : rattachement non posé.';
        }

        $membre = new Member($userId, $courriel, $personneId, $personne->getDisplayName(), MatchKind::ManuelAdmin);
        $membre->activer($userId);

        $manager->persist($membre);
        $manager->flush();

        return \sprintf('Rattachement actif posé : %s ↔ %s (#%d).', $courriel, $personne->getDisplayName(), $personneId);
    }
}

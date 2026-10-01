<?php

declare(strict_types=1);

namespace App\Tests\Membership\Double;

use App\Module\Membership\Service\Port\FamilleDeLArbre;
use App\Module\Membership\Service\Port\PersonneDeLArbre;
use App\Module\Membership\Service\Port\PortArbre;
use App\Module\Membership\Service\Port\VueDeLArbre;

/**
 * L'arbre, joué par un double réglable — il tient la place de l'adaptateur de `Tree`.
 *
 * Ce qui est testé dans ce module, ce n'est pas la façon dont `Tree` charge ses tables :
 * c'est la **formule** du cercle, les garde-fous de la recherche de rattachement, et le
 * fait que la sérialisation obéisse. Un double rend ces tests lisibles — un arbre de
 * huit personnes dont on voit la forme d'un coup d'œil — et surtout **indépendants de
 * PostgreSQL** : la base de test est SQLite, `TreeFingerprint` est écrit en SQL
 * PostgreSQL, et les tables `tree_*` ne sont pas montées ici.
 *
 * ## Pourquoi l'état est statique
 *
 * `KernelBrowser` relance le noyau **avant chaque requête** : un double peuplé par le test
 * serait remis à zéro entre deux appels HTTP, et le test verrait un arbre vide sans que
 * rien ne le dise. L'état vit donc dans une propriété statique, réinitialisée en `setUp`.
 * Ce n'est pas élégant ; c'est ce qui rend le double utilisable depuis un test fonctionnel,
 * et la même contrainte a déjà été payée sur la base SQLite et les pools de cache
 * (`tests/bootstrap.php`).
 */
final class ArbreEnMemoire implements PortArbre
{
    /** @var list<PersonneDeLArbre> */
    private static array $personnes = [];

    /** @var list<FamilleDeLArbre> */
    private static array $familles = [];

    /**
     * L'empreinte **forcée** par {@see self::changerLEmpreinte()} ; `null`, elle se calcule
     * sur le contenu.
     *
     * Elle était une constante, `arbre-de-test`, quel que soit l'arbre posé. Or le cercle se
     * met en cache (`cache.app`, 24 h) sous `cercle.{empreinte}.{memberId}.{ancre}.{N}`, et
     * ce pool est un répertoire **par processus** qui survit d'un test à l'autre. Deux
     * tests qui posent deux arbres différents produisaient donc la même clé dès que le
     * membre et l'ancre avaient les mêmes identifiants — ce qui arrive toujours, la base
     * étant remontée à l'identique : le second recevait le cercle du premier. C'est ainsi
     * que `RoutesDeLArbreTest`, exécuté après les tests des tirages, servait au membre le
     * cercle d'`ego` des tirages (frères, conjointe…) et non le sien.
     *
     * En production l'empreinte est recalculée depuis les tables `tree_*` : un arbre
     * différent donne une clé différente. Le double doit tenir la même promesse.
     */
    private static ?string $empreinte = null;

    public static function reinitialiser(): void
    {
        self::$personnes = [];
        self::$familles = [];
        self::$empreinte = null;
    }

    public static function ajouterPersonne(
        int $id,
        string $prenoms,
        string $nom,
        bool $vivante,
        ?int $anneeNaissance = null,
        string $etiquetteNaissance = '',
        ?string $libelle = null,
    ): void {
        self::$personnes[] = new PersonneDeLArbre(
            $id,
            $prenoms,
            $nom,
            $libelle ?? trim($prenoms.' '.$nom),
            $anneeNaissance,
            $etiquetteNaissance,
            $vivante,
        );
    }

    /**
     * @param list<int> $epoux
     * @param list<int> $enfants
     */
    public static function ajouterFamille(array $epoux, array $enfants = []): void
    {
        self::$familles[] = new FamilleDeLArbre($epoux, $enfants);
    }

    /** Fait bouger l'empreinte, comme le ferait une validation qui touche `tree_person`. */
    public static function changerLEmpreinte(string $empreinte): void
    {
        self::$empreinte = $empreinte;
    }

    public function vue(): VueDeLArbre
    {
        return new VueDeLArbre(self::$personnes, self::$familles);
    }

    public function empreinte(): string
    {
        return self::$empreinte ?? 'arbre-de-test.'.hash('xxh128', serialize([self::$personnes, self::$familles]));
    }
}

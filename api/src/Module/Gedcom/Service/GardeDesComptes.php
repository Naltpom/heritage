<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Service;

use App\Module\Gedcom\Dto\ConstatDeGarde;
use Doctrine\DBAL\Connection;

/**
 * Le garde-fou qui empêche un import de repeupler l'arbre **sous des comptes vivants**
 * sans qu'on l'ait voulu.
 *
 * ## Ce dont il protège, et ce dont il ne protège pas
 *
 * **L'import ne détruit aucun compte, et c'est mesuré** : il ne supprime que
 * `tree_citation`, `tree_note` (les importées seules), `tree_fact` et `tree_child` ; il
 * ne touche jamais `tree_person` ni `tree_family`, qu'il met à jour par `gedcom_xref` ;
 * et aucune clé étrangère ne part de `tree_*` vers `core_user`, `mbr_member`, `prp_*` ou
 * `med_*` — la règle du § 0.4 veut qu'un module en référence un autre **par identifiant
 * nu**, si bien qu'aucune cascade ne peut franchir la frontière. Un témoin semé avant un
 * ré-import complet en ressort intact, rattaché à la même personne.
 *
 * **Le danger est ailleurs, et il est réel.** `mbr_member.person_id` est un entier sans
 * contrainte. Il est stable tant qu'on se contente de ré-importer — les lignes sont mises
 * à jour, jamais réinsérées. Mais si quelqu'un détruit le schéma et le remigre, les
 * identifiants repartent de 1 et sont réattribués **dans l'ordre des enregistrements du
 * fichier**. Même fichier, mêmes identifiants ; fichier différent — et il l'est, l'arbre
 * a déjà connu cinq fusions —, tout décale à partir de la première personne ajoutée ou
 * retirée. Un rattachement conservé désigne alors quelqu'un d'autre.
 *
 * **C'est silencieux.** Personne ne voit une colonne `person_id` changer de sens. On le
 * découvre le jour où un membre voit sa grand-mère sur la fiche d'un inconnu — et le
 * cercle de visibilité, qui se calcule à partir de cette ligne, aura entre-temps montré
 * des personnes vivantes au mauvais compte.
 *
 * **Le remède durable n'est pas ici** : c'est de rattacher par `gedcom_xref` et non par
 * identifiant. `@I500001@` désignera Natron Romarin dans dix ans et après vingt
 * reconstructions ; l'entier `1` ne le promet pas. Cette garde ne fait qu'attraper la
 * situation au moment où elle se crée.
 *
 * ## Pourquoi il lit des tables et non des entités
 *
 * `Gedcom` n'a le droit d'importer que `Core` (ADR 0002, règle 3) : il ne peut pas nommer
 * `Membership\Entity\Member`. Il compte donc par SQL, sur des noms de table — et
 * **vérifie d'abord que les tables existent**. Une garde qui compterait 0 sur une table
 * absente laisserait tout passer en se croyant rassurante ; celle-ci dit qu'elle n'a pas
 * su regarder, ce qui est une information et non un feu vert.
 */
final class GardeDesComptes
{
    /** Les tables qui portent ce qu'un GEDCOM ne sait pas reconstituer. */
    private const string TABLE_COMPTES = 'core_user';
    private const string TABLE_RATTACHEMENTS = 'mbr_member';
    private const string TABLE_PERSONNES = 'tree_person';

    public function __construct(private readonly Connection $connexion)
    {
    }

    public function constater(): ConstatDeGarde
    {
        $tables = [self::TABLE_COMPTES, self::TABLE_RATTACHEMENTS, self::TABLE_PERSONNES];

        if (!$this->connexion->createSchemaManager()->tablesExist($tables)) {
            return new ConstatDeGarde(0, 0, 0, aPuRegarder: false);
        }

        return new ConstatDeGarde(
            $this->compter(self::TABLE_COMPTES),
            $this->compter(self::TABLE_RATTACHEMENTS),
            $this->compter(self::TABLE_PERSONNES),
            aPuRegarder: true,
        );
    }

    /**
     * Le refus, rédigé — ou `null` si rien ne s'oppose à l'import.
     *
     * **Le message chiffre ce qu'il refuse de laisser faire.** Un refus qui n'explique
     * pas se contourne par un drapeau sans réfléchir, et le drapeau devient alors un
     * réflexe : c'est exactement ce qu'une garde doit éviter de produire.
     */
    public function refus(ConstatDeGarde $constat): ?string
    {
        if (!$constat->aPuRegarder) {
            return 'La base ne porte pas les tables attendues (core_user, mbr_member, tree_person) : '
                .'la garde des comptes n’a pas pu regarder. Migrer la base avant d’importer.';
        }

        if (!$constat->arbreVideSousDesComptes()) {
            return null;
        }

        $lignes = [
            \sprintf(
                'L’arbre est vide, mais la base porte %d compte(s) et %d rattachement(s).',
                $constat->comptes,
                $constat->rattachements,
            ),
        ];

        if ($constat->desRattachementsVontEtreReinterpretes()) {
            $lignes[] = '';
            $lignes[] = \sprintf(
                'Les %d rattachement(s) désignent une personne par son identifiant numérique. '
                .'Repeupler l’arbre réattribue ces identifiants dans l’ordre du fichier : '
                .'si le GEDCOM a changé depuis, un rattachement désignera quelqu’un d’autre — '
                .'sans erreur, et sans que personne ne le voie.',
                $constat->rattachements,
            );
        }

        $lignes[] = '';
        $lignes[] = 'Deux situations donnent exactement cette image :';
        $lignes[] = '  · un premier import sur une base qu’on vient d’amorcer — cas normal ;';
        $lignes[] = '  · un schéma détruit puis remigré sous des comptes vivants — cas à ne pas laisser passer.';
        $lignes[] = '';
        $lignes[] = 'Si c’est le premier, relancer avec --premier-import.';

        return implode("\n", $lignes);
    }

    private function compter(string $table): int
    {
        // Le nom de table vient d'une constante de cette classe, jamais d'une entrée :
        // il n'y a rien à échapper, et rien à paramétrer.
        $nombre = $this->connexion->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table));

        return \is_numeric($nombre) ? (int) $nombre : 0;
    }
}

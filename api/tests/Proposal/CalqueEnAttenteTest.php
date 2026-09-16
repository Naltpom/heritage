<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Enum\SubjectKind;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * LE CALQUE « EN ATTENTE » — le test le plus important du module.
 *
 * Le produit fait une promesse : « le demandeur voit sa modification appliquée **à son
 * seul écran** avant validation » (§ 1 du dossier de mission). Elle a une contrepartie
 * exacte, et c'est elle qu'on éprouve ici : **tout autre membre voit les données
 * validées, point.**
 *
 * Se tromper dans un sens rend l'attente pénible ; se tromper dans l'autre publie à
 * toute la famille une correction que personne n'a validée — sur des données de
 * personnes vivantes. C'est pour cela que ce test est écrit en premier.
 */
final class CalqueEnAttenteTest extends BaseDeDemandes
{
    /**
     * Le cas nominal, dans ses trois lectures.
     *
     * Une seule demande, trois lecteurs, trois réponses différentes — et c'est la
     * troisième qui porte la règle.
     */
    public function testLeCalqueEstVisibleDeSonAuteurEtDUnAdministrateurEtInvisibleDUnAutreMembre(): void
    {
        $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::OPALE),
        );

        $pourOpale = $this->calque->pour(SubjectKind::Personne, self::PERSONNE, $this->lecteur(self::OPALE));
        $pourAdmin = $this->calque->pour(SubjectKind::Personne, self::PERSONNE, $this->lecteur(self::ADMIN, administrateur: true));
        $pourTuf = $this->calque->pour(SubjectKind::Personne, self::PERSONNE, $this->lecteur(self::TUF));

        self::assertArrayHasKey('surname', $pourOpale, 'L\'auteur doit voir sa propre proposition.');
        self::assertSame('SUREAU', $pourOpale['surname']['propose']);
        self::assertSame('Sureau', $pourOpale['surname']['actuel']);

        self::assertArrayHasKey('surname', $pourAdmin, 'L\'administrateur voit toutes les demandes en attente.');
        self::assertSame('Compte '.self::OPALE, $pourAdmin['surname']['auteur']);

        // ───── La règle. Un autre membre ne voit rien : pas un calque partiel, pas une
        // valeur masquée côté client — **rien n'est envoyé**.
        self::assertSame([], $pourTuf, 'Un autre membre ne reçoit aucun calque.');
    }

    /**
     * Un compte en attente de validation de son rattachement n'a **pas de cercle**, et
     * ne peut pas déposer de demande : il n'a donc aucun calque à recevoir, même s'il
     * était par ailleurs l'auteur d'une demande (§ 12, § 16.3).
     */
    public function testUnCompteEnAttenteNeRecoitAucunCalque(): void
    {
        $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::OPALE),
        );

        $calque = $this->calque->pour(
            SubjectKind::Personne,
            self::PERSONNE,
            $this->lecteur(self::OPALE, enAttente: true),
        );

        self::assertSame([], $calque);
    }

    /**
     * Un auteur ne voit **que ses propres demandes**, pas celles des autres.
     *
     * C'est la moitié de la règle qu'on oublie le plus facilement : « l'auteur voit sa
     * modification » se code très bien en « toute demande en attente est visible de tout
     * membre », et le test nominal passerait quand même.
     */
    public function testUnAuteurNeVoitPasLaProposionDUnAutreMembre(): void
    {
        $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::OPALE),
        );

        $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('nickname', null, 'Mimi')]),
            $this->lecteur(self::TUF),
        );

        $pourOpale = $this->calque->pour(SubjectKind::Personne, self::PERSONNE, $this->lecteur(self::OPALE));

        self::assertArrayHasKey('surname', $pourOpale);
        self::assertArrayNotHasKey('nickname', $pourOpale, 'Opale ne doit pas voir la proposition de Tuf.');

        $pourAdmin = $this->calque->pour(SubjectKind::Personne, self::PERSONNE, $this->lecteur(self::ADMIN, administrateur: true));

        self::assertArrayHasKey('surname', $pourAdmin);
        self::assertArrayHasKey('nickname', $pourAdmin, 'L\'administrateur voit les deux.');
    }

    /**
     * Deux demandes sur le **même champ** : l'entrée est celle de la demande la plus
     * ancienne, les autres vont dans `autresDemandes` (§ 13.1).
     *
     * Un tableau à la place de l'objet aurait changé l'arité que le § 9.4 du modèle
     * documente, et cassé tout front écrit contre elle.
     */
    public function testDeuxDemandesSurLeMemeChampLaPlusAncienneTientLEntree(): void
    {
        $ancienne = $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::OPALE),
        );

        $recente = $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'Sureaut')]),
            $this->lecteur(self::TUF),
        );

        // Les deux demandes naissent dans la même seconde ; l'ordre secondaire par
        // identifiant tranche, et c'est voulu — deux soumissions simultanées doivent
        // rester ordonnées de façon stable d'une lecture à l'autre.
        $calque = $this->calque->pour(SubjectKind::Personne, self::PERSONNE, $this->lecteur(self::ADMIN, administrateur: true));

        self::assertSame($ancienne->getId(), $calque['surname']['demandeId']);
        self::assertSame([$recente->getId()], $calque['surname']['autresDemandes']);
    }

    /**
     * Un bloc date traverse le calque **entier**, avec sa clé `texte` rendue.
     *
     * « Un bloc date est atomique dans le diff comme dans le modèle, et il s'affiche en
     * une seule ligne » (§ 13.2). Le front n'a pas à recomposer « entre 1901 et 1909 »
     * depuis deux bornes — et s'il le faisait, il finirait par afficher un point.
     */
    public function testUnBlocDateArriveEntierDansLeCalqueAvecSonTexte(): void
    {
        $this->depot->deposer(
            $this->corpsDeModification([[
                'seq' => 1,
                'opKind' => 'champ',
                'subjectKind' => 'personne',
                'subjectId' => self::PERSONNE,
                'field' => 'birth',
                'valueKind' => 'date_incertaine',
                'oldValue' => $this->blocDate($this->annee(1987)),
                'newValue' => [
                    'raw' => 'BET 1901 AND 1909',
                    'qualifier' => 'entre',
                    'min' => '1901-01-01',
                    'max' => '1909-12-31',
                    'place' => 'Saule, Ombrage, Sylvanie',
                ],
                'baseVersion' => 7,
            ]]),
            $this->lecteur(self::OPALE),
        );

        $calque = $this->calque->pour(SubjectKind::Personne, self::PERSONNE, $this->lecteur(self::OPALE));

        self::assertSame('entre 1901 et 1909', $calque['birth']['propose']['texte']);
        self::assertSame('1987', $calque['birth']['actuel']['texte']);
        self::assertSame('BET 1901 AND 1909', $calque['birth']['propose']['raw']);
        self::assertSame('Saule, Ombrage, Sylvanie', $calque['birth']['propose']['place']);
    }

    /**
     * Une demande tranchée quitte le calque. `en_attente` est le seul statut qui le
     * peuple — un refus ou une validation n'a plus rien à proposer.
     */
    public function testUneDemandeTrancheeQuitteLeCalque(): void
    {
        $demande = $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::OPALE),
        );

        $this->refus->refuser(
            $demande,
            $this->lecteur(self::ADMIN, administrateur: true),
            'La graphie de l\'acte est bien « Sureau ».',
            null,
        );

        self::assertSame(
            [],
            $this->calque->pour(SubjectKind::Personne, self::PERSONNE, $this->lecteur(self::OPALE)),
        );
    }

    /**
     * Sous la bascule « voir comme un membre », l'administrateur voit ce que verrait le
     * membre emprunté — sinon la bascule cesse de vérifier quoi que ce soit, c'est-à-dire
     * qu'elle échoue exactement là où elle sert (§ 10.4, § 17.3).
     */
    public function testSousLaBasculeLAdministrateurVoitLeCalqueDuMembreEmprunte(): void
    {
        $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::OPALE),
        );

        $emprunt = new \App\Module\Core\Contract\Lecteur(
            self::ADMIN,
            'Natron Romarin',
            administrateur: true,
            membreEmprunteUserId: self::TUF,
        );

        self::assertSame(
            [],
            $this->calque->pour(SubjectKind::Personne, self::PERSONNE, $emprunt),
            'Sous la bascule, l\'administrateur doit voir le calque vide de Tuf, pas le sien.',
        );
    }
}

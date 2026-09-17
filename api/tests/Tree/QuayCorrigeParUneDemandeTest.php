<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Sex;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Source;
use App\Module\Tree\Enum\CitationQuality;
use App\Module\Tree\Enum\NoteScope;
use App\Module\Tree\Enum\SourceKind;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * `tree_citation.quay` SE CORRIGE ENFIN PAR UNE DEMANDE.
 *
 * ## Le champ était inatteignable par tous les chemins, et par deux causes à la fois
 *
 * `SurfaceEditable` le déclarait `ValueKind::Enum`, documenté « scalaire JSON **string** »
 * et traité par `NormaliseurDeValeur::texte()`. Or `CitationQuality` est la seule
 * énumération du projet adossée à `int` — l'exception assumée du § 2.7 du modèle, parce
 * que la colonne est un `SMALLINT` qui porte les 0-3 de la norme GEDCOM — et le port rend
 * donc un entier.
 *
 * Les deux symptômes ne se ressemblaient pas, et c'est pour cela que personne n'avait
 * rapproché les moitiés :
 *
 * - `"quay": 2` passait le dépôt et faisait lever la **validation**, dans sa transaction,
 *   sur un `422 « Cette valeur doit être une chaîne ou null »` ;
 * - `"quay": "2"` s'écrivait, mais la valeur courante était l'entier `2` et l'`old_value`
 *   la chaîne `"2"`. La détection de caducité compare en `!==`, donc `2 !== "2"` était
 *   vrai : **caduque, systématiquement**.
 *
 * Mesuré avant correction : `prp_change_op` ne portait **aucune** op visant `quay`, sous
 * aucun `value_kind`. Le champ n'avait jamais été atteint — confirmation empirique plutôt
 * que chance.
 *
 * ## Pourquoi ce test vit dans `tests/Tree` et non dans `tests/Proposal`
 *
 * Parce que la suite de `Proposal` tourne sur `ArbreEnMemoire`, dont les valeurs sont
 * posées à la main par le test : elle ne peut pas voir un désaccord de type entre ce que
 * le **vrai** port rend et ce que le normaliseur attend. Ce désaccord-là est exactement le
 * défaut, et il ne se voit qu'avec la vraie implémentation et une vraie citation.
 */
final class QuayCorrigeParUneDemandeTest extends BaseDeDemandeSurArbreReel
{
    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **LE TEST DU LOT** : déposer, valider, et constater que le `QUAY` a changé en base.
     *
     * Le cas réel est celui d'un arbre en ligne coté `2` par l'import : on le remet à `0`,
     * ce qui est la seule cote qu'une recopie mérite.
     */
    public function testUneDemandeValideeCorrigeLeQuayEnBase(): void
    {
        [$citationId, $personneId] = $this->poserUneCitation(CitationQuality::ActeOuRegistre);

        $demande = $this->deposer($citationId, ancien: 2, nouveau: 0);

        self::assertSame(RequestStatus::EnAttente, $demande->getStatus());

        $resultat = $this->validation()->valider($demande, $this->admin());

        self::assertSame('validee', $resultat->resultat, 'Ni 422 dans la transaction, ni caducité : le champ est atteignable.');

        $this->em()->clear();

        self::assertSame(
            CitationQuality::RecopieNonVerifiee,
            $this->citation($citationId)->getQuay(),
            'Le QUAY est réellement écrit.',
        );

        // Les agrégats de la **personne** suivent : `best_quay` retombe, et c'est ce qui
        // fait basculer la fiche du côté « recopie non vérifiée » de tous les filtres.
        self::assertSame(0, $this->personne($personneId)->getBestQuay());
    }

    /**
     * **L'entier voyage entier**, du corps de la requête à la colonne.
     *
     * C'est la moitié du défaut qui levait : `versPhp()` passait par `texte()`, qui refuse
     * autre chose qu'une chaîne. Le test poste un **nombre** JSON, comme le ferait un
     * formulaire honnête.
     */
    public function testLOpPorteUnEntierEtNonUneChaine(): void
    {
        [$citationId] = $this->poserUneCitation(CitationQuality::IndexOuDeduction);

        $op = $this->opDe($this->deposer($citationId, ancien: 1, nouveau: 3));

        self::assertSame('entier', $op->getValueKind()->value, 'Le value_kind écrit en base dit « entier », pas « enum ».');
        self::assertSame(1, $op->getOldValue());
        self::assertSame(3, $op->getNewValue());
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **LA CADUCITÉ FONCTIONNE — dans le bon sens.**
     *
     * C'est l'autre moitié du défaut : la comparaison entier-contre-chaîne rendait *toute*
     * demande caduque. Maintenant qu'elle compare deux entiers, elle ne doit se déclencher
     * que quand la valeur a **réellement** bougé. Les deux assertions de ce test sont donc
     * indissociables : l'une prouve qu'elle se tait, l'autre qu'elle parle encore.
     */
    public function testLaCaduciteNeSeDeclencheQueSiLeQuayABougeVraiment(): void
    {
        [$citationId] = $this->poserUneCitation(CitationQuality::RecopieNonVerifiee);

        // Personne n'a touché à la citation : la demande s'applique.
        $tranquille = $this->deposer($citationId, ancien: 0, nouveau: 2);
        self::assertSame('validee', $this->validation()->valider($tranquille, $this->admin())->resultat);

        // Quelqu'un d'autre repasse derrière, puis une seconde demande fondée sur l'état
        // d'avant : celle-là doit mourir.
        [$autreId] = $this->poserUneCitation(CitationQuality::RecopieNonVerifiee);
        $condamnee = $this->deposer($autreId, ancien: 0, nouveau: 3);

        $this->citation($autreId)->setQuay(CitationQuality::IndexOuDeduction);
        $this->em()->flush();

        try {
            $this->validation()->valider($condamnee, $this->admin());
            self::fail('Un QUAY modifié entre le dépôt et la validation doit rendre la demande caduque.');
        } catch (\App\Module\Proposal\Exception\Conflit $conflit) {
            self::assertSame('demande_caduque', $conflit->codeErreur());
        }

        $this->em()->clear();
        self::assertSame(
            CitationQuality::IndexOuDeduction,
            $this->citation($autreId)->getQuay(),
            'Une demande caduque n’écrase pas ce que l’autre a posé.',
        );
    }

    /** Hors de la plage 0-3, le port refuse : la norme GEDCOM borne, et `ck_tree_citation_quay` aussi. */
    public function testUneCoteHorsNormeEstRefusee(): void
    {
        [$citationId] = $this->poserUneCitation(CitationQuality::RecopieNonVerifiee);

        $demande = $this->deposer($citationId, ancien: 0, nouveau: 7);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/hors de la plage 0-3/');

        $this->validation()->valider($demande, $this->admin());
    }

    // ────────────────────────────────────────────────────────────── le gréement

    /** @return array{int, int} l'identifiant de la citation, puis celui de la personne */
    private function poserUneCitation(CitationQuality $quay): array
    {
        $personne = $this->personneNeuve();
        $source = new Source('Arbre en ligne de quelqu’un', SourceKind::ArbreTiers);

        $this->em()->persist($source);

        $citation = (new Citation($source, NoteScope::Naissance))
            ->setPerson($personne)
            ->setPage('Fiche recopiée')
            ->setQuay($quay);

        $this->em()->persist($citation);
        $this->em()->flush();

        // `best_quay` est un agrégat que seule une écriture par le port rafraîchit ; on le
        // pose ici pour que l'assertion d'après mesure un changement et non un départ.
        $personne->setBestQuay($quay->value);
        $this->em()->flush();

        return [(int) $citation->getId(), (int) $personne->getId()];
    }

    private function deposer(int $citationId, int $ancien, int $nouveau): ChangeRequest
    {
        return $this->deposerCorps([
            'kind' => 'modification_citation',
            'message' => 'Un arbre en ligne ne vaut pas un acte.',
            'soumettre' => true,
            'targetKind' => 'citation',
            'targetId' => $citationId,
            'ops' => [[
                'seq' => 1,
                'opKind' => 'champ',
                'subjectKind' => 'citation',
                'subjectId' => $citationId,
                'field' => 'quay',
                'valueKind' => 'entier',
                'oldValue' => $ancien,
                'newValue' => $nouveau,
                'baseVersion' => 1,
            ]],
        ]);
    }

    private function personneNeuve(): \App\Module\Tree\Entity\Person
    {
        $personne = new \App\Module\Tree\Entity\Person('Opale Sarriette');
        $personne->setGivenNames('Opale')->setSurname('Sarriette')->setSex(Sex::Feminin);

        $this->em()->persist($personne);
        $this->em()->flush();

        return $personne;
    }

    private function citation(int $id): Citation
    {
        $citation = $this->em()->find(Citation::class, $id);
        self::assertInstanceOf(Citation::class, $citation);

        return $citation;
    }

    private function opDe(ChangeRequest $demande): \App\Module\Proposal\Entity\ChangeRequestOp
    {
        $ops = $this->em()
            ->getRepository(\App\Module\Proposal\Entity\ChangeRequestOp::class)
            ->findBy(['request' => $demande], ['seq' => 'ASC']);

        self::assertCount(1, $ops);

        return $ops[0];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Core\Enum\Regime;
use App\Module\Membership\Service\CalculDuCercle;
use App\Module\Membership\Service\CercleDeVisibilite;
use App\Module\Membership\Service\LecteurDuCercle;
use App\Module\Membership\Service\Port\ArbreNonBranche;
use App\Module\Membership\Service\Port\PortArbre;
use App\Tests\Membership\Double\ArbreEnMemoire;
use App\Tests\Membership\Double\LecteurFige;
use App\Tests\Membership\Double\ReglagesFiges;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * La règle de confidentialité, au point où les quatre autres modules la consultent.
 *
 * Quatre propriétés, et ce sont exactement celles que le dossier de mission demande de
 * tester :
 *
 * 1. une personne vivante hors cercle est masquée ;
 * 2. une personne décédée ne l'est pas — **même en régime `Aucun`** ;
 * 3. `N` modifié en réglage change le résultat, sans redéploiement ;
 * 4. l'administrateur passe par un cercle complet, jamais par un court-circuit.
 *
 * Aucune n'a besoin de pare-feu ni de base : c'est ce que la couture
 * `Membership\Service\SourceDuLecteur` rend possible. Le **câblage**, lui, est vérifié à
 * part, par {@see CablageDuCercleTest}, contre le conteneur réel.
 */
final class CercleDeVisibiliteTest extends TestCase
{
    protected function setUp(): void
    {
        ArbreFamilial::poser();
    }

    protected function tearDown(): void
    {
        ArbreEnMemoire::reinitialiser();
    }

    public function testUnVivantHorsCercleEstMasqueUnDecedeNeLEstPas(): void
    {
        $cercle = $this->cercleDUnMembre(ArbreFamilial::EGO);

        self::assertSame(Regime::Cercle, $cercle->regime());

        self::assertTrue($cercle->voitPersonne(ArbreFamilial::COUSIN), 'Le cousin issu de germain est dans le cercle.');
        self::assertTrue($cercle->voitPersonne(ArbreFamilial::CONJOINT), 'Le conjoint est dans le cercle.');

        self::assertFalse(
            $cercle->voitPersonne(ArbreFamilial::ETRANGER),
            'Une personne vivante sans lien avec le lecteur est masquée.',
        );
        self::assertTrue(
            $cercle->voitPersonne(ArbreFamilial::ANCETRE_ETRANGER),
            'Une personne décédée est un fait historique : visible de tout compte connecté.',
        );
    }

    /**
     * **Le régime `Aucun` n'est pas un cercle vide.**.
     *
     * Un compte en attente de validation voit les personnes décédées, et **aucune personne
     * vivante — pas même celle à laquelle il prétend se rattacher**. Le lecteur ci-dessous
     * porte d'ailleurs une ancre : elle est ignorée, parce que le régime tranche avant.
     * *Confirmer une date de naissance n'est pas la lire.*
     */
    public function testLeRegimeAucunNeLaisseSortirAucunePersonneVivante(): void
    {
        $cercle = $this->cercle(new LecteurDuCercle(
            userId: 9,
            regime: Regime::Aucun,
            memberId: 11,
            // L'ancre du rattachement prétendu — celle du pré-lien d'invitation.
            personneAncre: ArbreFamilial::EGO,
        ));

        self::assertSame(Regime::Aucun, $cercle->regime());
        self::assertNull($cercle->taille(), 'Pas de taille : la clé est absente de la charge de session, pas à zéro.');

        self::assertFalse($cercle->voitPersonne(ArbreFamilial::EGO), 'Pas même la personne réclamée.');
        self::assertFalse($cercle->voitPersonne(ArbreFamilial::CONJOINT));
        self::assertFalse($cercle->voitPersonne(ArbreFamilial::ETRANGER));

        self::assertTrue($cercle->voitPersonne(ArbreFamilial::AIEUL), 'Les personnes décédées restent visibles.');
        self::assertTrue($cercle->voitPersonne(ArbreFamilial::ANCETRE_ETRANGER));
    }

    /**
     * « Un décès inconnu vaut vivant, donc masqué. » Un identifiant que l'arbre ne connaît
     * pas — référence pendante, contrepartie assumée de l'absence de clé étrangère
     * inter-module — est traité comme vivant.
     */
    public function testUnIdentifiantInconnuEstTraiteCommeVivantDoncMasque(): void
    {
        self::assertFalse($this->cercleDUnMembre(ArbreFamilial::EGO)->voitPersonne(999_999));
    }

    /**
     * **`N` est un réglage, pas une constante** (§ 6.2 du dossier, ADR 0003).
     *
     * On change la valeur, et le cercle suit **à l'appel suivant** : c'est le segment
     * `{N}` de la clé `cercle.{empreinte}.{memberId}.{N}` qui rend l'ancienne entrée de
     * cache inatteignable, sans rien à invalider (§ 9.3, § 10.2).
     */
    public function testLeReglageDesGenerationsChangeLeCercleSansRedeploiement(): void
    {
        $reglages = new ReglagesFiges(generations: 3);
        $cache = new ArrayAdapter();

        $aTroisGenerations = $this->cercle($this->membre(ArbreFamilial::EGO), $reglages, $cache);
        self::assertTrue($aTroisGenerations->voitPersonne(ArbreFamilial::TANTE), 'À N = 3, la tante est dans le cercle.');

        $reglages->regler(1);

        $aUneGeneration = $this->cercle($this->membre(ArbreFamilial::EGO), $reglages, $cache);
        self::assertFalse($aUneGeneration->voitPersonne(ArbreFamilial::TANTE), 'À N = 1, elle n’y est plus.');
        self::assertTrue($aUneGeneration->voitPersonne(ArbreFamilial::PERE), 'Le père reste : il est à une génération.');
    }

    /**
     * L'empreinte de l'arbre fait tourner la clé de cache : un arbre modifié donne un
     * cercle recalculé, **sans rien à invalider**.
     *
     * Le même cache est réutilisé d'un appel à l'autre — c'est ce qui rend le test
     * probant : si la clé ne portait pas l'empreinte, la première entrée serait resservie
     * et l'assertion tomberait.
     */
    public function testUnChangementDEmpreinteRecalculeLeCercle(): void
    {
        $cache = new ArrayAdapter();

        self::assertFalse($this->cercle($this->membre(ArbreFamilial::EGO), cache: $cache)->voitPersonne(ArbreFamilial::ETRANGER));

        // L'étranger devient l'enfant du père : l'arbre a changé, donc l'empreinte aussi.
        ArbreEnMemoire::ajouterFamille([ArbreFamilial::PERE, ArbreFamilial::MERE], [ArbreFamilial::ETRANGER]);
        ArbreEnMemoire::changerLEmpreinte('empreinte-apres-modification');

        self::assertTrue(
            $this->cercle($this->membre(ArbreFamilial::EGO), cache: $cache)->voitPersonne(ArbreFamilial::ETRANGER),
            'Le cercle suit l’arbre : aucune invalidation manuelle n’est nécessaire.',
        );
    }

    /**
     * L'administrateur voit tout — **par un cercle complet**, pas par un
     * `if ($estAdmin) { return true; }` que l'ADR 0003 interdit.
     *
     * La différence se prouve : son régime est `Cercle`, et sa taille vaut le nombre de
     * personnes de l'arbre. Un court-circuit n'aurait ni l'un ni l'autre.
     */
    public function testLAdministrateurPasseParUnCercleCompletEtNonParUnCourtCircuit(): void
    {
        $cercle = $this->cercle(new LecteurDuCercle(userId: 1, regime: Regime::Cercle, cercleComplet: true));

        self::assertSame(Regime::Cercle, $cercle->regime());
        self::assertSame(15, $cercle->taille(), 'Le cercle complet contient les 15 personnes de l’arbre de test.');
        self::assertTrue($cercle->voitPersonne(ArbreFamilial::ETRANGER));
    }

    /**
     * La bascule « voir comme un membre » **remplace un cercle par un autre**.
     *
     * L'administrateur, sous la bascule, cesse de voir l'étranger : c'est la moitié
     * observable de l'outil, et celle qui donne son sens à l'autre — l'`ETag` du graphe,
     * qui incorpore le membre emprunté (§ 10.4, règle 1).
     */
    public function testLaBasculeRemplaceLeCercleDeLAdministrateur(): void
    {
        $cercle = $this->cercle(new LecteurDuCercle(
            userId: 1,
            regime: Regime::Cercle,
            memberId: 7,
            personneAncre: ArbreFamilial::EGO,
            sousBascule: true,
        ));

        self::assertFalse($cercle->voitPersonne(ArbreFamilial::ETRANGER));
        self::assertTrue($cercle->voitPersonne(ArbreFamilial::COUSIN));
    }

    /**
     * `filtrerPersonnes()` rend les visibles **dans l'ordre reçu, doublons retirés** — le
     * contrat de `Core\Security\CercleDeVisibiliteInterface`. Elle existe pour que le
     * graphe résolve un cercle au lieu de 274.
     */
    public function testFiltrerPersonnesRespecteSonContrat(): void
    {
        $visibles = $this->cercleDUnMembre(ArbreFamilial::EGO)->filtrerPersonnes([
            ArbreFamilial::ETRANGER,
            ArbreFamilial::COUSIN,
            ArbreFamilial::ANCETRE_ETRANGER,
            ArbreFamilial::COUSIN,
        ]);

        self::assertSame([ArbreFamilial::COUSIN, ArbreFamilial::ANCETRE_ETRANGER], $visibles);
    }

    /**
     * Le port non branché refuse tout, **y compris les personnes décédées**.
     *
     * Ce n'est pas une incohérence avec la règle « une personne décédée est un fait
     * historique » : un arbre vide ne permet pas d'établir qu'elle l'est, et *« on ne sait
     * pas si elle est morte » n'est pas une autorisation de publier ses dates*. C'est le
     * défaut fermé, et il ne faut pas l'« arranger ».
     */
    public function testSansPortBrancheAucunePersonneNEstVisible(): void
    {
        $cercle = $this->cercle(
            $this->membre(ArbreFamilial::EGO),
            arbre: new ArbreNonBranche(new NullLogger()),
        );

        self::assertFalse($cercle->voitPersonne(ArbreFamilial::EGO));
        self::assertFalse($cercle->voitPersonne(ArbreFamilial::AIEUL), 'Même une personne décédée : l’arbre vide ne l’établit pas.');
        self::assertSame([], $cercle->filtrerPersonnes([ArbreFamilial::AIEUL, ArbreFamilial::EGO]));
    }

    private function membre(int $personneAncre): LecteurDuCercle
    {
        return new LecteurDuCercle(userId: 3, regime: Regime::Cercle, memberId: 7, personneAncre: $personneAncre);
    }

    private function cercleDUnMembre(int $personneAncre): CercleDeVisibilite
    {
        return $this->cercle($this->membre($personneAncre));
    }

    private function cercle(
        LecteurDuCercle $lecteur,
        ?ReglagesFiges $reglages = null,
        ?ArrayAdapter $cache = null,
        ?PortArbre $arbre = null,
    ): CercleDeVisibilite {
        return new CercleDeVisibilite(
            new LecteurFige($lecteur),
            $arbre ?? new ArbreEnMemoire(),
            new CalculDuCercle(),
            $reglages ?? new ReglagesFiges(),
            $cache ?? new ArrayAdapter(),
        );
    }
}

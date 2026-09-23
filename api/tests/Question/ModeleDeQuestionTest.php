<?php

declare(strict_types=1);

namespace App\Tests\Question;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Service\SurfaceEditable;
use App\Module\Question\Entity\Question;
use App\Module\Question\Entity\QuestionDemande;
use App\Module\Question\Enum\EtatDeQuestion;
use App\Module\Question\Enum\NatureDeLacune;
use App\Module\Question\Enum\RedacteurDeQuestion;
use PHPUnit\Framework\TestCase;

/**
 * Le modèle de la page « Questions » (ADR 0008), éprouvé **sans base et sans noyau**.
 *
 * Ce que ce fichier garde tient en trois points, et chacun est une façon précise de
 * rendre la page inutilisable :
 *
 * 1. **la signature**, qui est la mémoire des « je ne sais pas ». En changer la
 *    formule rouvre d'un coup tout ce que chaque membre avait écarté ;
 * 2. **les transitions d'état**, dont les invariants sont aussi des contraintes `CHECK`
 *    en base. Le schéma des tests étant reconstruit depuis le mapping Doctrine, qui ne
 *    porte aucune `CHECK`, **rien en base ne les fait respecter pendant la suite** : ce
 *    sont ces cas-ci qui les gardent côté PHP, et {@see ContraintesDuModeleTest} qui
 *    garde les expressions SQL elles-mêmes ;
 * 3. **la correspondance avec la surface éditable de `Proposal`**, sans laquelle on
 *    poserait des questions dont la réponse ne peut pas être déposée.
 *
 * Aucune personne réelle : les noms sont ceux de l'arbre d'essai, inventés (ADR 0006).
 */
final class ModeleDeQuestionTest extends TestCase
{
    /* ───────────────────────── La signature ───────────────────────── */

    /**
     * **La formule est figée.** La valeur attendue est écrite en dur, calculée à la
     * main, et c'est tout l'intérêt du cas : recalculer `hash('sha256', …)` dans le test
     * comparerait le code à lui-même et passerait après n'importe quelle réécriture.
     *
     * Ce qu'une réécriture coûterait, et qui n'est pas évident : les lignes déjà en base
     * gardent leur ancienne signature, la détection en calcule de nouvelles, l'index
     * unique ne rapproche plus rien — et **chaque membre retrouve d'un coup toutes les
     * questions qu'il avait fermées**. C'est une migration, pas une correction de détail.
     */
    public function testLaFormuleDeSignatureEstFigee(): void
    {
        self::assertSame(
            hash('sha256', 'lieu_deces_inconnu:personne:214'),
            Question::signatureDe(NatureDeLacune::LieuDeDecesInconnu, SubjectKind::Personne, 214),
            'La formule est `nature:sujet_kind:sujet_id`, dans cet ordre, séparés par « : ».',
        );
    }

    /** Deux lacunes différentes sur la même fiche sont deux questions. */
    public function testDeuxNaturesSurLaMemeFicheNePartagentPasLaSignature(): void
    {
        self::assertNotSame(
            Question::signatureDe(NatureDeLacune::MereInconnue, SubjectKind::Personne, 12),
            Question::signatureDe(NatureDeLacune::PereInconnu, SubjectKind::Personne, 12),
        );
    }

    /**
     * La personne n° 12 et la famille n° 12 ne sont pas la même ligne.
     *
     * C'est la raison d'être de `sujet_kind` dans la signature : sans elle, une question
     * d'union fermerait une question de fiche, et l'on ne saurait jamais laquelle.
     */
    public function testLaPorteeEntreDansLaSignature(): void
    {
        self::assertNotSame(
            Question::signatureDe(NatureDeLacune::DateDeNaissanceInconnue, SubjectKind::Personne, 12),
            Question::signatureDe(NatureDeLacune::DateDeNaissanceInconnue, SubjectKind::Famille, 12),
        );
    }

    /**
     * **Le texte n'entre pas dans la signature.** Deux formulations de la même lacune
     * sont la même lacune.
     *
     * Sans ce cas, rien n'empêcherait d'ajouter le texte au haché « pour être précis » —
     * et la page redemanderait alors indéfiniment ce à quoi on a déjà répondu, à chaque
     * changement de gabarit et à chaque reformulation par le modèle. Le défaut serait
     * attribué au modèle, alors qu'il viendrait d'ici.
     */
    public function testLeTexteNEntrePasDansLaSignature(): void
    {
        $parLesRegles = $this->question(texte: 'Savez-vous où Prune Bréval est décédée ?');
        $parLeModele = $this->question(
            texte: 'Vous souvenez-vous de la ville où votre grand-tante Prune s\'est éteinte ?',
            redacteur: RedacteurDeQuestion::Gemini,
        );

        self::assertSame($parLesRegles->getSignature(), $parLeModele->getSignature());
    }

    /**
     * **Le destinataire non plus**, et c'est ce qui rend possible la seule requête
     * transversale du module : « à qui d'autre a-t-on posé cette lacune, et qu'en
     * ont-ils dit ? ».
     *
     * Il est la seconde colonne de l'index unique, séparément.
     */
    public function testLeDestinataireNEntrePasDansLaSignature(): void
    {
        self::assertSame(
            $this->question(destinataire: 7)->getSignature(),
            $this->question(destinataire: 41)->getSignature(),
        );
    }

    /* ───────────────────────── Les transitions ───────────────────────── */

    public function testUneQuestionNaitPoseeEtSansReponse(): void
    {
        $question = $this->question();

        self::assertSame(EtatDeQuestion::Posee, $question->getEtat());
        self::assertNull($question->getReponduLe());
        self::assertNull($question->getRelancerApres());
        self::assertSame(0, $question->getReports());
        self::assertFalse($question->getEtat()->estDefinitif());
    }

    /**
     * « Je ne sais pas » **est une réponse** : elle n'écrit rien dans l'arbre, et elle
     * porte quand même son horodatage.
     *
     * Sans lui, rien ne distinguerait « personne n'a jamais regardé cette question » de
     * « quelqu'un a regardé et ne savait pas » — deux renseignements opposés pour qui
     * cherche où chercher. C'est l'invariant que `ck_qst_question_repondue` grave en
     * base.
     */
    public function testJeNeSaisPasFermeLaQuestionEtHorodateLaReponse(): void
    {
        $question = $this->question()->fermerFauteDeSavoir();

        self::assertSame(EtatDeQuestion::Inconnue, $question->getEtat());
        self::assertNotNull($question->getReponduLe());
        self::assertNull($question->getRelancerApres(), 'ck_qst_question_differee : seul `differee` porte une relance.');
        self::assertTrue($question->getEtat()->estDefinitif());
    }

    /**
     * « Je vais me renseigner » pose une relance **et compte le report**.
     *
     * Le compteur n'est pas décoratif : une promesse qu'on rappelle indéfiniment est un
     * harcèlement poli, au terme duquel le membre répond « je ne sais pas » pour s'en
     * débarrasser — ce qui détruit l'information au lieu de la recueillir.
     */
    public function testJeVaisMeRenseignerReporteEtCompte(): void
    {
        $quand = new \DateTimeImmutable('2026-10-23 08:00:00');
        $question = $this->question()->differer($quand);

        self::assertSame(EtatDeQuestion::Differee, $question->getEtat());
        self::assertSame($quand, $question->getRelancerApres());
        self::assertSame(1, $question->getReports());
        self::assertNull($question->getReponduLe(), 'ck_qst_question_repondue : un report n\'est pas une réponse.');
        self::assertFalse($question->getEtat()->estDefinitif(), 'Une question différée doit revenir.');

        $question->differer($quand->modify('+1 month'));
        self::assertSame(2, $question->getReports());
    }

    /**
     * Répondre **efface la relance**.
     *
     * C'est la moitié muette de `ck_qst_question_differee`, celle qu'une implication
     * simple laisserait passer : une question fermée qui garderait une date de relance
     * d'une vie antérieure ne se voit nulle part — sauf le jour où une requête de réveil
     * la ramasse et repose une question déjà répondue.
     */
    public function testRepondreEffaceLaRelance(): void
    {
        $question = $this->question()->differer(new \DateTimeImmutable('2026-10-23 08:00:00'));

        $question->marquerRepondue('Elle est morte à Quimper, je crois.');

        self::assertSame(EtatDeQuestion::Repondue, $question->getEtat());
        self::assertNull($question->getRelancerApres());
        self::assertNotNull($question->getReponduLe());
        self::assertSame('Elle est morte à Quimper, je crois.', $question->getReponseTexte());
    }

    /**
     * **Une réponse peut produire deux demandes**, et c'est le cas utile : le lieu en
     * note, la photo de l'acte en média. L'administrateur doit pouvoir accepter l'une et
     * refuser l'autre.
     *
     * C'est pour cela que `qst_question_demande` est une table et non une colonne : une
     * colonne unique aurait perdu la première en écrivant la seconde, sans rien dire.
     */
    public function testUneReponsePeutProduireDeuxDemandes(): void
    {
        $question = $this->question();

        new QuestionDemande($question, 42, 'ajout_note');
        new QuestionDemande($question, 43, 'ajout_media');
        $question->marquerRepondue('À Quimper, et voici l\'acte.');

        self::assertCount(2, $question->getDemandes());
        self::assertSame(
            ['ajout_note', 'ajout_media'],
            array_map(static fn (QuestionDemande $l): string => $l->getNature(), $question->getDemandes()->toArray()),
        );
    }

    /**
     * Le libellé suit les renommages de l'arbre ; **le texte de la question, non**.
     *
     * Une question reste telle qu'elle a été lue. Le libellé de liste, lui, suit, parce
     * qu'un écran qui affiche deux orthographes pour la même fiche fait douter des deux.
     */
    public function testLeLibelleSuitMaisPasLeTexte(): void
    {
        $question = $this->question(texte: 'Savez-vous où Prune Breval est décédée ?');

        $question->rafraichirLeLibelle('Prune Bréval');

        self::assertSame('Prune Bréval', $question->getSujetLabel());
        self::assertSame('Savez-vous où Prune Breval est décédée ?', $question->getTexte());
    }

    /* ───────────────────────── Les natures ───────────────────────── */

    /**
     * Le cas croisé : une question dont la réponse ne pourrait pas être déposée.
     *
     * {@see NatureDeLacune::champ()} rend un nom de champ qui traverse la frontière **en
     * chaîne** — `Question` n'a pas le droit de nommer `Proposal\Enum\ValueKind`. Une
     * chaîne ne prévient jamais qu'elle a vieilli : renommer `givenNames` dans la surface
     * éditable ne casserait rien ici, et la page continuerait de poser la question. C'est
     * à la **réponse** que tout tomberait, en `422`, sur le premier membre qui prend la
     * peine de contribuer.
     *
     * C'est le même défaut que celui nommé dans l'en-tête de
     * {@see SurfaceEditable} — « un champ proposable que `Tree` ne sait pas appliquer
     * produirait une demande qu'on peut déposer et pas valider, ce qui est la pire des
     * deux moitiés » — vu d'un cran plus haut.
     */
    public function testChaqueChampVisePasUneNatureExisteDansLaSurfaceEditable(): void
    {
        $surface = (new SurfaceEditable())->champs(SubjectKind::Personne);

        foreach (NatureDeLacune::cases() as $nature) {
            $champ = $nature->champ();

            if (null === $champ) {
                continue;
            }

            self::assertArrayHasKey(
                $champ,
                $surface,
                \sprintf(
                    'La nature « %s » alimente le champ « %s », absent de la surface éditable : '
                    .'la question se poserait, et la réponse serait refusée en 422.',
                    $nature->value,
                    $champ,
                ),
            );
        }
    }

    /**
     * Toute nature a une phrase de repli, et cette phrase nomme la personne.
     *
     * **C'est ce qui garantit que Gemini coupé, la page sert quand même des questions**
     * (ADR 0008, § 4). Un gabarit sans `%s` produirait « Savez-vous où est né·e ? »,
     * c'est-à-dire une question à laquelle on ne peut pas répondre.
     */
    public function testChaqueNatureAUnGabaritQuiNommeLaPersonne(): void
    {
        foreach (NatureDeLacune::cases() as $nature) {
            self::assertStringContainsString(
                '%s',
                $nature->gabarit(),
                \sprintf('La nature « %s » doit nommer la personne dans son gabarit.', $nature->value),
            );
            self::assertNotSame('', $nature->label());
        }
    }

    /**
     * Les rangs d'affichage sont **tous distincts**.
     *
     * Deux natures au même rang rendraient l'ordre de la page dépendant de l'ordre de
     * tri, donc variable d'un chargement à l'autre sans qu'aucune donnée n'ait changé.
     * C'est le genre d'instabilité qu'on attribue au serveur pendant des semaines.
     */
    public function testLesRangsDAffichageSontTousDistincts(): void
    {
        $rangs = array_map(static fn (NatureDeLacune $n): int => $n->rang(), NatureDeLacune::cases());

        self::assertSame($rangs, array_unique($rangs));
        self::assertCount(\count(NatureDeLacune::cases()), $rangs);
    }

    /**
     * La photo passe en premier.
     *
     * Une page qui s'ouvre sur « quand votre arrière-grand-père est-il né ? » se referme ;
     * une page qui s'ouvre sur « auriez-vous une photo ? » obtient une première
     * contribution — et la première contribution décide s'il y en aura une seconde.
     */
    public function testLaPhotoEstDemandeeAvantLesDates(): void
    {
        self::assertLessThan(
            NatureDeLacune::DateDeNaissanceInconnue->rang(),
            NatureDeLacune::AucunePhoto->rang(),
        );
    }

    private function question(
        int $destinataire = 7,
        NatureDeLacune $nature = NatureDeLacune::LieuDeDecesInconnu,
        int $sujetId = 214,
        string $texte = 'Savez-vous où Prune Bréval est décédée ?',
        RedacteurDeQuestion $redacteur = RedacteurDeQuestion::Regles,
    ): Question {
        return new Question(
            $destinataire,
            $nature,
            SubjectKind::Personne,
            $sujetId,
            'Prune Bréval',
            $texte,
            $redacteur,
        );
    }
}

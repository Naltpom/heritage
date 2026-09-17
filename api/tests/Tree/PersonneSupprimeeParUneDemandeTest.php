<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Enum\RevisionOperation;
use App\Module\Media\Entity\Media;
use App\Module\Membership\Entity\Invitation;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Fact;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Note;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Entity\Source;
use App\Module\Tree\Enum\FactType;
use App\Module\Tree\Enum\NoteScope;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * UNE DEMANDE VALIDÉE EFFACE UNE LIGNE DE `tree_person` — la chaîne entière.
 *
 * ## Ce que ce fichier prouve, et que `tests/Proposal/SuppressionDePersonneTest` ne peut pas
 *
 * Que la ligne disparaît **réellement**, et que ce qui la référençait disparaît avec elle
 * ou s'en détache. La suite de `Proposal` tourne sur `ArbreEnMemoire` : elle éprouve le
 * dépôt, les refus, la caducité et la révision, et elle passerait à l'identique si le port
 * n'effaçait rien.
 *
 * Or supprimer une personne n'est pas supprimer une ligne. Six clés étrangères pointent
 * sur `tree_person` — quatre en `ON DELETE CASCADE`, deux en `ON DELETE SET NULL` — et une
 * contrainte `CHECK` décide si une union survit à la perte d'un époux. C'est la partie
 * qu'un double ne peut pas tenir, et c'est celle qui coûte cher quand elle est fausse.
 *
 * ## La base est SQLite, et ce qu'elle ne prouve pas est écrit ici
 *
 * `SchemaTool` pose les clés étrangères du mapping, mais **SQLite ne les applique que si
 * `PRAGMA foreign_keys` est armé**, ce que rien ne garantit ici. C'est précisément
 * pourquoi `EcritureDeLArbrePourProposal::supprimerPersonne()` efface les lignes filles
 * **explicitement**, au lieu de s'en remettre aux cascades : ces tests vérifient donc le
 * code, et non la base. PostgreSQL vérifie la base.
 *
 * Ni `ck_tree_family_au_moins_un_epoux` ni `uniq_tree_family_couple` ne sont posés par
 * `SchemaTool` : c'est le service qui les respecte, et c'est ce qu'on éprouve.
 */
final class PersonneSupprimeeParUneDemandeTest extends BaseDeDemandeSurArbreReel
{
    private const string MOTIF = 'Doublon : la même personne figure déjà sous une autre fiche, mieux sourcée.';

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * LE TEST QUI COMPTE.
     *
     * Un membre dépose une demande motivée, un administrateur valide, et **la ligne
     * disparaît de `tree_person`** avec tout ce qu'elle portait.
     *
     * Les lignes sont recomptées par des requêtes après `clear()` : une entité que l'unité
     * de travail tient encore dirait « effacée » d'une donnée toujours en base.
     */
    public function testUneDemandeValideeEffaceLaLigneEtCeQuEllePorte(): void
    {
        $personneId = $this->poserUneFicheComplete();

        self::assertSame(1, $this->compter(Person::class));
        self::assertSame(2, $this->compter(Note::class));
        self::assertSame(1, $this->compter(Citation::class));
        self::assertSame(1, $this->compter(Fact::class));

        $demande = $this->deposer($personneId);

        // Le dépôt n'écrit pas dans l'arbre : vérifié ici contre les vraies tables.
        self::assertSame(1, $this->compter(Person::class), 'Un dépôt ne touche pas la table visée.');
        self::assertSame(2, $this->compter(Note::class));

        $resultat = $this->validation()->valider($demande, $this->admin());

        self::assertSame('validee', $resultat->resultat);
        self::assertSame(RequestStatus::Validee, $demande->getStatus());

        $this->em()->clear();

        self::assertSame(0, $this->compter(Person::class), 'La fiche a disparu.');
        self::assertSame(0, $this->compter(Note::class), 'Ses notes sont parties avec elle.');
        self::assertSame(0, $this->compter(Citation::class), 'Ses citations aussi.');
        self::assertSame(0, $this->compter(Fact::class), 'Ses faits aussi.');

        // La source, elle, **reste** : `tree_citation.source_id` est en `ON DELETE
        // RESTRICT`, et une source est un registre d'archives qui ne dépend d'aucune fiche.
        self::assertSame(1, $this->compter(Source::class), 'Une source survit à la fiche qui la citait.');
    }

    /**
     * Les notes et citations rattachées à un **fait** de la fiche partent aussi, et sont
     * comptées d'avance.
     *
     * Elles ne portent pas `person_id` mais `fact_id`, et `ck_tree_note_sujet_unique`
     * impose exactement un sujet : compter les seules notes de la personne annoncerait un
     * nombre inférieur à ce qui disparaîtra. C'est l'inverse exact de ce que l'inventaire
     * promet, et c'est le genre d'écart qu'on ne découvre qu'après.
     */
    public function testLesNotesDUnFaitDeLaFicheSontComptEesEtEmportees(): void
    {
        $personne = $this->personneNeuve('Gentiane', 'Muguet');
        $fait = new Fact(FactType::Bapteme, $personne);
        $this->em()->persist($fait);
        $this->em()->persist((new Note('Relevé sur le registre paroissial de Saule.'))->setFact($fait));
        $this->em()->flush();

        $demande = $this->deposer((int) $personne->getId());

        /** @var array<string, mixed> $inventaire */
        $inventaire = $this->opDe($demande)->getOldValue();

        self::assertSame(1, $inventaire['notes'], 'La note du fait est comptée comme celles de la fiche.');
        self::assertSame(1, $inventaire['faits']);

        $this->validation()->valider($demande, $this->admin());
        $this->em()->clear();

        self::assertSame(0, $this->compter(Note::class));
        self::assertSame(0, $this->compter(Fact::class));
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **L'UNION D'UN ÉPOUX SEUL PART AVEC LUI** — `ck_tree_family_au_moins_un_epoux`.
     *
     * La base interdit une famille sans personne. Le `ON DELETE SET NULL` de
     * `husband_id` produirait donc une ligne refusée : il faut effacer la famille, et
     * l'annoncer d'avance — c'est le champ `supprimee` de l'inventaire.
     */
    public function testUneUnionDontElleEstLeSeulEpouxDisparaitAvecElle(): void
    {
        $personne = $this->personneNeuve('Lave', 'Sarriette');
        $famille = new Family(null, $personne);
        $this->em()->persist($famille);
        $this->em()->flush();

        $demande = $this->deposer((int) $personne->getId());

        /** @var array<string, mixed> $inventaire */
        $inventaire = $this->opDe($demande)->getOldValue();
        /** @var list<array<string, mixed>> $unions */
        $unions = $inventaire['unions'];

        self::assertCount(1, $unions);
        self::assertTrue($unions[0]['supprimee'], 'Une famille sans aucun époux n\'est pas une famille.');

        $this->validation()->valider($demande, $this->admin());
        $this->em()->clear();

        self::assertSame(0, $this->compter(Family::class), 'L\'union est partie avec son seul époux.');
    }

    /**
     * **L'union d'un couple survit, amputée de l'époux effacé.**
     *
     * C'est l'autre moitié de la règle, et elle compte autant : le conjoint restant garde
     * son union, ses dates de mariage et ses propres rattachements. Effacer la famille
     * ici ferait disparaître le mariage d'une personne qui n'a rien demandé.
     */
    public function testUneUnionDeCoupleSurvitAmputeeDeLEpouxEfface(): void
    {
        $mari = $this->personneNeuve('Cobalt', 'Sureau', Sex::Masculin);
        $femme = $this->personneNeuve('Opale', 'Sarriette');

        $famille = new Family($mari, $femme);
        $this->em()->persist($famille);
        $this->em()->flush();

        $familleId = (int) $famille->getId();

        $this->validation()->valider($this->deposer((int) $mari->getId()), $this->admin());
        $this->em()->clear();

        $survivante = $this->em()->find(Family::class, $familleId);
        self::assertInstanceOf(Family::class, $survivante, 'L\'union du conjoint restant ne disparaît pas.');
        self::assertNull($survivante->getHusband());
        self::assertSame($femme->getId(), $survivante->getWife()?->getId());
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **LA RÈGLE 1, contre la vraie base** : une fiche qui porte une descendance est
     * refusée au dépôt.
     *
     * C'est le cas réel — deux fiches du même homme, l'une portant six générations et
     * l'autre vide. Le refus nomme l'enfant, parce que c'est cela qui dit à l'auteur
     * laquelle des deux fiches il s'apprêtait à effacer.
     */
    public function testUneFichePortantUneDescendanceEstRefuseeAuDepot(): void
    {
        $pere = $this->personneNeuve('Jargon', 'Sarriette', Sex::Masculin);
        $enfant = $this->personneNeuve('Cobalt', 'Sarriette', Sex::Masculin);

        $famille = new Family($pere, null);
        $this->em()->persist($famille);
        $this->em()->persist(new Child($famille, $enfant, 1));
        $this->em()->flush();

        try {
            $this->deposer((int) $pere->getId());
            self::fail('Une suppression qui détacherait une branche ne se dépose pas.');
        } catch (DonneesInvalides $erreur) {
            $champs = (array) $erreur->champs();
            self::assertArrayHasKey('targetId', $champs);
            self::assertStringContainsString('Cobalt Sarriette', (string) $champs['targetId']);
        }

        self::assertSame(2, $this->compter(Person::class), 'Rien n\'a été touché.');
    }

    /**
     * ════════════════════════════════════════════════════════════════════════════
     * **UNE PREUVE RATTACHÉE BLOQUE LA SUPPRESSION** — et ce test est le seul qui traverse
     * réellement la frontière avec `Media`.
     *
     * `med_media.subject_id` n'est protégé par **aucune clé étrangère** : la colonne porte un
     * identifiant de personne, de famille ou de citation selon `subject_kind`, et PostgreSQL
     * ne peut pas contraindre une référence polymorphe. Effacer la fiche ne ferait donc rien
     * côté médias : la ligne reste, les octets restent sur le disque, et plus aucun écran n'y
     * mène — la « preuve orpheline » que ce projet a déjà rencontrée.
     *
     * Le compte passe par `PortMedias::compterLesMediasDe()`, que `Media` décore. Le repli
     * `MediasNonBranches` répond `0` à tout : si la décoration cessait de prendre, ce test
     * serait le seul à le dire, et il le dirait en laissant passer une suppression qui
     * devrait être refusée.
     */
    public function testUnePreuveRattacheeBloqueLaSuppression(): void
    {
        $personne = $this->personneNeuve('Opale', 'Muguet');

        $media = new Media(
            MediaKind::Preuve,
            MediaSubject::Personne,
            $personne->getDisplayName(),
            'a1b2c3d4.jpg',
            'image/jpeg',
            120_000,
            str_repeat('a', 64),
            self::OPALE,
            'Compte contributeur',
        );
        $media->rattacherA((int) $personne->getId());

        $this->em()->persist($media);
        $this->em()->flush();

        try {
            $this->deposer((int) $personne->getId());
            self::fail('Une suppression qui laisserait une preuve orpheline ne se dépose pas.');
        } catch (DonneesInvalides $erreur) {
            $champs = (array) $erreur->champs();
            self::assertArrayHasKey('targetId', $champs);
            self::assertStringContainsString('preuve', (string) $champs['targetId']);
            self::assertStringContainsString('galerie', (string) $champs['targetId'], 'Le message dit où aller la déplacer.');
        }

        self::assertSame(1, $this->compter(Person::class), 'Rien n\'a été touché.');
    }

    /**
     * Sa **propre** filiation, elle, part avec elle — et c'est l'inverse du cas précédent.
     *
     * Être l'enfant de quelqu'un est un rattachement qui n'a plus d'objet une fois la
     * fiche effacée ; être le parent de quelqu'un est un rattachement dont **l'autre
     * extrémité** reste dans l'arbre. Les confondre bloquerait toute suppression d'une
     * fiche rattachée à ses parents, c'est-à-dire presque toutes.
     */
    public function testSaPropreFiliationPartAvecElleSansBloquer(): void
    {
        $parent = $this->personneNeuve('Natron', 'Romarin', Sex::Masculin);
        $enfant = $this->personneNeuve('Marne', 'Romarin');

        $famille = new Family($parent, null);
        $this->em()->persist($famille);
        $this->em()->persist(new Child($famille, $enfant, 1));
        $this->em()->flush();

        $demande = $this->deposer((int) $enfant->getId());

        /** @var array<string, mixed> $inventaire */
        $inventaire = $this->opDe($demande)->getOldValue();
        self::assertSame(1, $inventaire['filiations']);
        self::assertSame([], $inventaire['descendants'], 'Un enfant n\'a pas de descendance de ce fait.');

        $this->validation()->valider($demande, $this->admin());
        $this->em()->clear();

        self::assertSame(0, $this->compter(Child::class), 'Le rattachement à ses parents est parti.');
        self::assertSame(1, $this->compter(Family::class), 'L\'union de ses parents, elle, reste.');
        self::assertSame(1, $this->compter(Person::class));
    }

    /**
     * La révision porte l'inventaire mesuré **dans la transaction**, et elle survit à son
     * sujet.
     *
     * `prp_revision` n'a aucune clé étrangère vers `tree_person` : `subject_id` est un
     * entier nu, délibérément, et c'est ce qui permet à l'historique de rester lisible
     * après que la fiche a disparu. C'est tout ce qu'il en restera.
     */
    public function testLaRevisionSurvitALaFicheEtDecritCeQuiEstParti(): void
    {
        $personneId = $this->poserUneFicheComplete();

        $resultat = $this->validation()->valider($this->deposer($personneId), $this->admin());

        self::assertCount(1, $resultat->revisions);
        $revision = $resultat->revisions[0];

        self::assertSame(SubjectKind::Personne, $revision->getSubjectKind());
        self::assertSame($personneId, $revision->getSubjectId());
        self::assertSame(RevisionOperation::Suppression, $revision->getOperation());
        self::assertSame(['avant' => 2, 'apres' => null], $revision->getChanges()['notes']);
        self::assertSame(['avant' => 1, 'apres' => null], $revision->getChanges()['citations']);

        $this->em()->clear();

        self::assertNull($this->em()->find(Person::class, $personneId), 'La fiche n\'est plus là…');
        self::assertSame($personneId, $revision->getSubjectId(), '…et la révision la désigne toujours.');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **`uniq_tree_family_couple … NULLS NOT DISTINCT`** — le cas que rien ne signale.
     *
     * L'index porte `NULLS NOT DISTINCT` : deux familles `(NULL, W)` ne peuvent pas
     * coexister. Or vider l'emplacement du mari d'une famille `(X, W)` la transforme
     * précisément en `(NULL, W)`. Si une famille de parent seul pour `W` existe déjà —
     * et le modèle en compte, « 3 familles `WIFE` sans `HUSB` » —, la suppression de `X`
     * produit un doublon d'index.
     *
     * L'échec tomberait **dans la transaction de validation**, sous forme d'une `500`
     * portant un nom de contrainte, loin du geste qui l'a causée et sans que l'écran ait
     * rien laissé prévoir.
     *
     * Le port efface donc la ligne au lieu de la vider, et **l'annonce d'avance** : c'est
     * le champ `supprimee` de l'inventaire, rendu à l'administrateur avant le clic.
     *
     * *SQLite ne pose pas `NULLS NOT DISTINCT` — `SchemaTool` ne rend que ce que le
     * mapping exprime. Ce test vérifie donc le **comportement du service**, pas le refus
     * de la base : il affirme que l'union en conflit est effacée et que l'autre survit,
     * ce qui est vrai des deux moteurs. PostgreSQL, lui, ferait échouer la transaction si
     * le service se trompait.*
     */
    public function testUneUnionQuiEntreraitEnCollisionEstEffaceeEtNonVidee(): void
    {
        $femme = $this->personneNeuve('Opale', 'Sarriette');
        $mari = $this->personneNeuve('Cobalt', 'Sureau', Sex::Masculin);

        // Celle qui deviendrait `(NULL, Opale)` si on se contentait de vider l'époux.
        $couple = new Family($mari, $femme);
        // Celle qui est **déjà** `(NULL, Opale)`.
        $seule = new Family(null, $femme);

        $this->em()->persist($couple);
        $this->em()->persist($seule);
        $this->em()->flush();

        $coupleId = (int) $couple->getId();
        $seuleId = (int) $seule->getId();

        $demande = $this->deposer((int) $mari->getId());

        /** @var array<string, mixed> $inventaire */
        $inventaire = $this->opDe($demande)->getOldValue();
        /** @var list<array<string, mixed>> $unions */
        $unions = $inventaire['unions'];

        self::assertCount(1, $unions);
        self::assertSame($coupleId, $unions[0]['familleId']);
        self::assertTrue(
            $unions[0]['supprimee'],
            'L\'union doit être annoncée comme disparaissant : la vider produirait un doublon de uniq_tree_family_couple.',
        );

        $this->validation()->valider($demande, $this->admin());
        $this->em()->clear();

        self::assertNull($this->em()->find(Family::class, $coupleId), 'L\'union en conflit est effacée.');
        self::assertInstanceOf(
            Family::class,
            $this->em()->find(Family::class, $seuleId),
            'Celle qui existait déjà n\'est pas touchée : la personne effacée n\'en faisait pas partie.',
        );
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **LE CHIFFRE QUI ARRÊTE LA MAIN** : « N fiches descendent de celle-ci ».
     *
     * C'est le cas réel de bout en bout, en miniature. Deux fiches du même homme : l'une
     * porte trois générations, l'autre ne porte rien. Les deux se ressemblent à l'écran ;
     * seule celle-ci est une catastrophe à effacer.
     *
     * Le message de blocage mène avec le **total de la branche**, pas avec le nombre
     * d'enfants directs. « Parent de Marne Romarin » est exact et ne pèse rien — un
     * enfant, on l'efface sans y penser. « 3 fiches descendent de celle-ci » pèse. Les
     * noms suivent, parce qu'eux seuls disent **laquelle des deux fiches** on tient.
     */
    public function testLeBlocageAnnonceLaBrancheEntiereEtPasSeulementLesEnfantsDirects(): void
    {
        // Trois générations sous l'aïeul : un enfant, un petit-enfant, un arrière-petit.
        $aieul = $this->personneNeuve('Natron', 'Romarin', Sex::Masculin);
        $enfant = $this->personneNeuve('Marne', 'Romarin');
        $petit = $this->personneNeuve('Cobalt', 'Romarin', Sex::Masculin);
        $arriere = $this->personneNeuve('Lave', 'Romarin');

        $this->rattacher($aieul, $enfant);
        $this->rattacher($enfant, $petit);
        $this->rattacher($petit, $arriere);

        // La fiche vide du même homme : elle, se supprime sans discussion.
        $doublon = $this->personneNeuve('Natron', 'Romarin', Sex::Masculin);

        try {
            $this->deposer((int) $aieul->getId());
            self::fail('Une fiche qui porte trois générations ne se supprime pas.');
        } catch (DonneesInvalides $erreur) {
            $message = (string) ((array) $erreur->champs())['targetId'];

            self::assertStringStartsWith('3 fiches descendent de celle-ci', $message, 'Le total de la branche vient en tête.');
            self::assertStringContainsString('Marne Romarin', $message, 'Les noms suivent : ils disent laquelle des deux fiches on tient.');
            self::assertStringContainsString('l\'autre fiche', $message);
        }

        // Et la fiche vide, elle, passe — c'est la moitié utile du doublon.
        $demande = $this->deposer((int) $doublon->getId());

        /** @var array<string, mixed> $inventaire */
        $inventaire = $this->opDe($demande)->getOldValue();
        self::assertSame(0, $inventaire['descendanceTotale']);

        $this->validation()->valider($demande, $this->admin());
        $this->em()->clear();

        self::assertSame(4, $this->compter(Person::class), 'Les trois générations et leur aïeul sont intacts.');
    }

    /**
     * Rattache un enfant à un parent, en créant l'union de parent seul qui les relie.
     *
     * C'est la forme la plus courte d'une filiation, et elle suffit ici : ce qu'on
     * éprouve est le parcours de `tree_child`, pas la normalisation d'un couple.
     */
    private function rattacher(Person $parent, Person $enfant): void
    {
        $famille = Sex::Masculin === $parent->getSex()
            ? new Family($parent, null)
            : new Family(null, $parent);

        $this->em()->persist($famille);
        $this->em()->persist(new Child($famille, $enfant, 1));
        $this->em()->flush();
    }
    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **UN COMPTE MEMBRE RATTACHÉ BLOQUE LA SUPPRESSION** — le cas le plus silencieux
     * des quatre.
     *
     * `mbr_member.person_id` porte un `uniq_mbr_member_person` et **aucune clé
     * étrangère** : `Membership` et `Tree` sont deux modules, et le § 2.6 du modèle
     * interdit la jointure qui la poserait. Effacer `tree_person` ne fait donc rien ici —
     * la ligne reste, et le compte se retrouve rattaché à une fiche qui n'existe plus.
     *
     * Ce n'est pas cosmétique. **Le cercle de visibilité d'un membre et son champ
     * `parente` se calculent depuis cette racine.** Une racine absente ne lève pas : elle
     * produit un calcul sur rien, c'est-à-dire un compte qui ne voit plus rien ou qui voit
     * tout selon ce que le calcul en fait. Le membre le découvre en se connectant, des
     * jours plus tard, sans pouvoir relier ce qu'il voit au geste qui l'a causé — les
     * descendants détachés, eux, restent au moins visibles dans l'arbre.
     *
     * Le message **nomme le rattachement par son numéro** et pas par l'adresse du compte :
     * cette route est ouverte à tout membre, et le libellé d'un compte est la huitième
     * porte de sortie de l'ADR 0003. Le numéro suffit à le retrouver dans la file
     * d'administration, qui est le seul endroit où on peut le rattacher ailleurs.
     */
    public function testUnCompteMembreRattacheBloqueLaSuppression(): void
    {
        $personne = $this->personneNeuve('Gentiane', 'Sarriette');
        $personneId = (int) $personne->getId();

        $membre = new Member(
            42,
            'contributeur@example.invalid',
            $personneId,
            $personne->getDisplayName(),
            MatchKind::PreLie,
        );

        $this->em()->persist($membre);
        $this->em()->flush();

        try {
            $this->deposer($personneId);
            self::fail('Une suppression qui décrocherait un compte de sa racine ne se dépose pas.');
        } catch (DonneesInvalides $erreur) {
            $message = (string) ((array) $erreur->champs())['targetId'];

            self::assertStringContainsString(\sprintf('compte membre n° %d', (int) $membre->getId()), $message);
            self::assertStringContainsString('Rattachez ce compte à une autre fiche', $message, 'Le message dit quoi faire.');
            self::assertStringNotContainsString('@example.invalid', $message, 'L\'adresse du compte ne sort pas sur une route ouverte aux membres.');
        }

        self::assertSame(1, $this->compter(Person::class), 'Rien n\'a été touché.');
    }

    /**
     * **Un rattachement `en_attente` bloque aussi** — et c'est l'inverse de
     * `personneDuCompte()`.
     *
     * Celle-ci ne rend la racine que d'un membre `actif` : une parenté calculée depuis un
     * rattachement non validé serait une affirmation que personne n'a approuvée. Ici la
     * question n'est pas « que puis-je affirmer » mais « qu'est-ce que je casserais », et
     * un rattachement en attente se casse aussi bien qu'un autre — mieux, même : son
     * titulaire n'a pas encore de cercle, il ne verrait donc **rien** et n'aurait aucun
     * moyen de comprendre pourquoi.
     */
    public function testUnRattachementEnAttenteBloqueEgalement(): void
    {
        $personne = $this->personneNeuve('Lave', 'Muguet');
        $personneId = (int) $personne->getId();

        $membre = new Member(43, 'candidat@example.invalid', $personneId, $personne->getDisplayName(), MatchKind::PreLie);
        self::assertSame(MemberStatus::EnAttente, $membre->getStatus(), 'Un membre naît en attente.');

        $this->em()->persist($membre);
        $this->em()->flush();

        $this->expectException(DonneesInvalides::class);
        $this->deposer($personneId);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **UNE INVITATION EN ATTENTE BLOQUE AUSSI** — le même défaut, en différé.
     *
     * Rien ne casse aujourd'hui : l'invitation dort. Le compte sans racine apparaît le
     * jour où quelqu'un l'accepte, c'est-à-dire après la suppression et loin d'elle. C'est
     * la variante la plus difficile à relier à son geste, et c'est pour cela qu'elle
     * bloque au même titre que les autres.
     *
     * Seule une invitation `active` compte : une `utilisee` a déjà produit son membre, et
     * une `expiree` ou `revoquee` ne produira plus rien.
     */
    public function testUneInvitationActiveBloqueLaSuppression(): void
    {
        $personne = $this->personneNeuve('Cobalt', 'Muguet', Sex::Masculin);
        $personneId = (int) $personne->getId();

        $invitation = new Invitation(
            str_repeat('b', 64),
            'b1c2d3e4',
            new \DateTimeImmutable('+30 days'),
            1,
            'admin@example.invalid',
        );
        $invitation->setPerson($personneId, $personne->getDisplayName());

        $this->em()->persist($invitation);
        $this->em()->flush();

        try {
            $this->deposer($personneId);
            self::fail('Une invitation en attente sur cette fiche interdit sa suppression.');
        } catch (DonneesInvalides $erreur) {
            $message = (string) ((array) $erreur->champs())['targetId'];

            self::assertStringContainsString('invitation en attente', $message);
            self::assertStringContainsString('à son acceptation', $message, 'Le message dit que le défaut est différé.');
            self::assertStringNotContainsString('@example.invalid', $message, 'L\'adresse du destinataire ne sort pas.');
        }
    }
    // ────────────────────────────────────────────────────────────── le gréement

    private function deposer(int $personneId): ChangeRequest
    {
        return $this->deposerCorps([
            'kind' => 'suppression_personne',
            'message' => self::MOTIF,
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => $personneId,
        ]);
    }

    /**
     * Une fiche comme on en trouve : deux notes, une citation appuyée sur une source, un
     * fait.
     */
    private function poserUneFicheComplete(): int
    {
        $personne = $this->personneNeuve('Marne Dolomite', 'Sureau');
        $source = new Source('Registre paroissial de Saule, 1780-1792');

        $this->em()->persist($source);
        $this->em()->persist((new Note('La cote portée jusqu\'ici était fausse : lire 61 Q.'))->setPerson($personne));
        $this->em()->persist((new Note('Fratrie close à quatre enfants.', NoteScope::Naissance))->setPerson($personne));
        $this->em()->persist((new Citation($source))->setPerson($personne)->setPage('vue 14'));
        $this->em()->persist(new Fact(FactType::Bapteme, $personne));
        $this->em()->flush();

        return (int) $personne->getId();
    }

    private function personneNeuve(string $prenoms, string $nom, Sex $sexe = Sex::Feminin): Person
    {
        $personne = new Person(trim($prenoms.' '.$nom));
        $personne->setGivenNames($prenoms)->setSurname($nom)->setSex($sexe);

        $this->em()->persist($personne);
        $this->em()->flush();

        return $personne;
    }

    /** @param class-string $entite */
    private function compter(string $entite): int
    {
        return (int) $this->em()
            ->createQuery('SELECT COUNT(x.id) FROM '.$entite.' x')
            ->getSingleScalarResult();
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

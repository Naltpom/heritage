<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Repository\DraftLinkRepository;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * CE QUE L'ADMINISTRATEUR LIT AVANT DE TRANCHER — et ce qu'il ne lisait pas.
 *
 * Le défaut a été **mesuré sur un cas réel** : vingt-deux personnes déposées en cinq
 * demandes — cinq frères et sœurs, leurs conjoints, leurs douze enfants — ont été
 * validées depuis le navigateur. Les vingt-deux fiches étaient bien listées ; le
 * commanditaire n'a donc pas validé des personnes invisibles. Mais il les a validées
 * **sans pouvoir lire leur parenté**, pour trois raisons cumulées :
 *
 * 1. **Aucun lien entre deux brouillons ne portait de nom.** `otherLabel` valait
 *    « Personne proposée #p1 ». Pour savoir de qui un enfant était le fils, il fallait
 *    relever `#p1` dans la liste des liens et le retrouver à la main dans la liste des
 *    brouillons — vingt-deux fois.
 * 2. **Les notes n'étaient pas rendues.** Toutes les justifications écrites fiche par
 *    fiche et lien par lien — une homonymie père/fils signalée, une déduction marquée
 *    comme supposition — sont stockées depuis toujours dans `prp_draft_person.note` et
 *    `prp_draft_link.note`, et le sérialiseur ne les émettait pas. Seul le `message`
 *    global de la demande arrivait à l'écran.
 * 3. **La famille d'ancrage n'était pas nommée.** `familyId: 2`, un entier nu, quand la
 *    colonne d'à côté portait le nom des deux époux.
 *
 * Aucun des trois n'était une donnée manquante : **les trois étaient en base**. C'est la
 * sérialisation qui les laissait dedans, et rien ne le signalait — une clé absente ne se
 * distingue pas d'une valeur absente quand on ne l'a jamais vue.
 *
 * ── Pourquoi ce fichier plutôt que des assertions ajoutées ailleurs ─────────────
 *
 * {@see CreationDePersonneTest} éprouve le **circuit** d'une création — une personne, une
 * famille, une validation. Ce qui a manqué ici n'est pas le circuit mais la **lisibilité
 * de la charge** : ce que l'écran de décision reçoit. Les deux se cassent séparément, et
 * le premier est resté vert pendant tout le temps où le second était faux.
 */
final class BrancheProposeeLisibleTest extends BaseDeDemandes
{
    /** La personne de l'arbre validé à laquelle la branche se raccroche. */
    private const int ANCRE = self::PERSONNE;

    /** Le couple existant que l'une des filiations désigne. */
    private const int COUPLE = 28;

    protected function setUp(): void
    {
        parent::setUp();

        $this->arbre->poser(SubjectKind::Famille, self::COUPLE, 'Mica Chanterelle × Ocre Chanterelle', 2, [
            'marriage' => UncertainDate::inconnue(),
            'divorced' => false,
            'divorce' => UncertainDate::inconnue(),
        ]);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  UN LIEN ENTRE DEUX BROUILLONS PORTE UN NOM.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * C'est la correction la plus utile des trois, parce que c'est celle qui décidait de
     * la lisibilité de la parenté — la seule chose qu'un administrateur ait à juger sur
     * une demande de création.
     *
     * La mention « (proposée) » est dans le libellé et pas seulement dans `otherDraftRef`
     * : un lecteur qui ne regarde pas la seconde clé doit pouvoir distinguer, dans une
     * liste de liens, la personne de l'arbre de celle que la demande invente.
     */
    public function testUnLienEntreDeuxBrouillonsNommeLaPersonneProposee(): void
    {
        $demande = $this->deposerLaBranche();

        $liens = $this->liensParSeq($demande);

        // Le lien de filiation : Morille, enfant de Girolle — deux fiches de la demande.
        self::assertSame('Morille Chanterelle (proposée)', $liens[2]['subjectLabel']);
        self::assertSame('Girolle Chanterelle (proposée)', $liens[2]['otherLabel']);

        // L'extrémité qui désigne une personne **réelle** garde son libellé de l'arbre :
        // le relire ici court-circuiterait le filtre de visibilité du § 0.4.
        self::assertSame('Marne Dolomite Sureau', $liens[1]['otherLabel']);
        self::assertSame('Girolle Chanterelle (proposée)', $liens[1]['subjectLabel']);
    }

    /**
     * Le second parent d'une filiation est nommé lui aussi, et la charge dit **de quelle
     * nature il est** — personne de l'arbre ou fiche de la même demande.
     *
     * Sans `other2DraftRef`, les deux cas rendaient la même forme : un `other2Label` seul,
     * dont rien ne disait s'il désignait quelqu'un qui existe.
     */
    public function testLeSecondParentEstNommeEtSaNatureEstLisible(): void
    {
        $demande = $this->deposerLaBranche();

        $liens = $this->liensParSeq($demande);

        self::assertArrayHasKey('other2DraftRef', $liens[2], 'La clé existe, même à null : c’est elle qui distingue les deux cas.');
        self::assertNull($liens[2]['other2DraftRef'], 'Le second parent de Morille est une personne de l’arbre.');
        self::assertSame('Marne Dolomite Sureau', $liens[2]['other2Label']);

        self::assertSame('#p1', $liens[3]['other2DraftRef'], 'Le second parent de Cèpe est une fiche de la même demande.');
        self::assertSame('Girolle Chanterelle (proposée)', $liens[3]['other2Label']);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  LES NOTES SORTENT DE LA BASE.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * Une note invisible dans la charge est invisible à l'écran, et une justification
     * qu'on ne lit pas ne pèse sur aucune décision. Le front les affichait déjà — le
     * composant `FicheProposee.vue` porte depuis toujours un `v-if="brouillon.note"` et
     * un `v-if="lien.note"` — sur des clés que le serveur n'envoyait pas.
     */
    public function testLesNotesDeChaqueFicheEtDeChaqueLienSontRendues(): void
    {
        $demande = $this->deposerLaBranche();

        $charge = $this->serialiseur->pourAuteur($this->recharger($demande));

        $notes = [];
        foreach ($charge['brouillons'] as $brouillon) {
            $notes[$brouillon['localRef']] = $brouillon['note'];
        }

        self::assertCount(3, $notes, 'Les trois fiches déposées sont rendues, pas deux.');
        self::assertSame('Homonymie avec son père : c’est bien le fils.', $notes['#p2']);
        self::assertNull($notes['#p3'], 'Une fiche sans note rend `null`, pas une chaîne vide.');

        $liens = $this->liensParSeq($demande);
        self::assertSame('Déduit du registre paroissial, pas d’acte retrouvé.', $liens[2]['note']);
    }

    /**
     * Le couple d'ancrage est **nommé**, pas rendu par son seul numéro.
     *
     * « Rattaché à la famille 28 » n'est pas une information : c'est un identifiant de
     * ligne, et le front a pour règle de ne jamais en mettre un sous les yeux du lecteur.
     */
    public function testLaFamilleDAncrageEstNommeeEtPasSeulementNumerotee(): void
    {
        $demande = $this->deposerLaBranche();

        $liens = $this->liensParSeq($demande);

        self::assertSame(self::COUPLE, $liens[4]['familyId']);
        self::assertSame('Mica Chanterelle × Ocre Chanterelle', $liens[4]['familyLabel']);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  LE LIBELLÉ DÉNORMALISÉ PORTE LE NOM, DÈS LE DÉPÔT.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * La correction au sérialiseur suffit à l'écran, et elle **répare les liens déjà en
     * base**, ce qu'une correction au dépôt ne pouvait pas faire. Mais elle ne suffit pas
     * à l'historique : {@see \App\Module\Proposal\Service\ApplicateurDeCreation} recopie
     * ces colonnes dans `prp_revision.subject_label` à la validation, et le sérialiseur
     * ne passe jamais par là. Sans cette seconde correction, « Personne proposée #p1 »
     * restait pour de bon dans la trace d'audit de la fiche créée.
     *
     * La colonne porte le nom **sans** la mention « (proposée) » : elle est relue au
     * moment même où la personne cesse de l'être.
     */
    public function testLeLibelleStockeEnBasePorteLeNomEtNonLaReference(): void
    {
        $demande = $this->deposerLaBranche();

        $this->em->clear();
        $depot = self::getContainer()->get(DraftLinkRepository::class);
        \assert($depot instanceof DraftLinkRepository);

        $liens = $depot->pourDemande($this->recharger($demande));
        self::assertCount(4, $liens, 'Les quatre liens déposés sont relus, pas trois.');

        $libelles = [];
        foreach ($liens as $lien) {
            $libelles[$lien->getSeq()] = [$lien->getSubjectLabel(), $lien->getOtherLabel()];
        }

        self::assertSame(['Girolle Chanterelle', 'Marne Dolomite Sureau'], $libelles[1]);
        self::assertSame(['Morille Chanterelle', 'Girolle Chanterelle'], $libelles[2]);

        foreach ($libelles as $seq => $paire) {
            foreach ($paire as $libelle) {
                self::assertStringNotContainsString(
                    'Personne proposée',
                    (string) $libelle,
                    \sprintf('Le lien %d porte encore une référence provisoire au lieu d’un nom.', $seq),
                );
                self::assertStringNotContainsString(
                    '(proposée)',
                    (string) $libelle,
                    \sprintf('Le lien %d stocke une mention de rendu : elle sera fausse dans `prp_revision`.', $seq),
                );
            }
        }
    }

    /**
     * L'historique écrit à la validation nomme les deux extrémités.
     *
     * C'est la conséquence du test précédent, mesurée là où elle compte : sur la ligne
     * que personne ne relira avant des mois, et que plus rien ne pourra corriger.
     */
    public function testLHistoriqueDUneValidationNommeLesDeuxExtremites(): void
    {
        $demande = $this->deposerLaBranche();

        $this->validation->valider($demande, $this->admin());

        $charge = $this->serialiseur->pourAuteur($this->recharger($demande));
        $libelles = [];
        foreach ($charge['revisions'] as $revision) {
            $libelles[] = $revision['subjectLabel'];
        }

        self::assertNotSame([], $libelles, 'Une validation écrit au moins une révision.');

        foreach ($libelles as $libelle) {
            self::assertStringNotContainsString(
                'Personne proposée #',
                (string) $libelle,
                'La trace d’audit d’une fiche créée ne doit pas porter une référence provisoire.',
            );
        }
    }

    /**
     * La demande déposée : trois fiches et quatre liens, à l'image de la branche réelle.
     *
     * - `#p1` Girolle, conjointe d'une personne de l'arbre — la seule qui s'y raccroche ;
     * - `#p2` Morille, son enfant, second parent pris dans l'arbre ;
     * - `#p3` Cèpe, enfant de Morille **et** de Girolle — deux brouillons aux deux bouts ;
     * - un quatrième lien rattache Cèpe à un couple existant, pour `familyLabel`.
     */
    private function deposerLaBranche(): ChangeRequest
    {
        return $this->depot->deposer([
            'kind' => 'creation_personne',
            'message' => 'Une branche entière, relevée sur le registre paroissial.',
            'soumettre' => true,
            'brouillons' => [
                $this->fiche('#p1', 'Girolle', note: 'Épouse en secondes noces.'),
                $this->fiche('#p2', 'Morille', note: 'Homonymie avec son père : c’est bien le fils.'),
                $this->fiche('#p3', 'Cèpe', note: null),
            ],
            'liens' => [
                [
                    'seq' => 1,
                    'kind' => 'conjoint_de',
                    'subjectDraftRef' => '#p1',
                    'otherPersonId' => self::ANCRE,
                    'note' => 'Union relevée sur le registre.',
                ],
                [
                    'seq' => 2,
                    'kind' => 'enfant_de',
                    'subjectDraftRef' => '#p2',
                    'otherDraftRef' => '#p1',
                    'other2PersonId' => self::ANCRE,
                    'note' => 'Déduit du registre paroissial, pas d’acte retrouvé.',
                ],
                [
                    'seq' => 3,
                    'kind' => 'enfant_de',
                    'subjectDraftRef' => '#p3',
                    'otherDraftRef' => '#p2',
                    'other2DraftRef' => '#p1',
                ],
                [
                    'seq' => 4,
                    'kind' => 'enfant_de',
                    'subjectDraftRef' => '#p3',
                    'familyId' => self::COUPLE,
                ],
            ],
        ], $this->lecteur(self::OPALE));
    }

    /** @return array<string, mixed> */
    private function fiche(string $reference, string $prenom, ?string $note): array
    {
        return [
            'localRef' => $reference,
            'givenNames' => $prenom,
            'surname' => 'Chanterelle',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'inconnu',
            'deceased' => true,
            'birth' => $this->blocDate($this->annee(1901)),
            'death' => $this->blocDate($this->annee(1968)),
            'note' => $note,
        ];
    }

    /**
     * Les liens de la charge d'auteur, indexés par leur rang.
     *
     * L'assertion de taille n'est pas décorative : c'est exactement ce qui a manqué au
     * cas réel — un test qui parcourt un ensemble découvert sans dire combien il en
     * attend reste vert quand l'ensemble rétrécit.
     *
     * @return array<int, array<string, mixed>>
     */
    private function liensParSeq(ChangeRequest $demande): array
    {
        $charge = $this->serialiseur->pourAuteur($this->recharger($demande));

        $liens = [];
        foreach ($charge['liens'] as $lien) {
            $liens[$lien['seq']] = $lien;
        }

        self::assertCount(4, $liens, 'Les quatre liens déposés sont rendus, pas trois.');

        return $liens;
    }

    private function admin(): Lecteur
    {
        return new Lecteur(self::ADMIN, 'Natron Romarin', administrateur: true);
    }
}

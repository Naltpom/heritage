<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\CoteDeRecherche;
use App\Module\Core\Enum\MotifDeFinDeRecherche;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Repository\RevisionRepository;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Entity\ResearchClosure;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * UNE DEMANDE VALIDÉE POSE, RÉÉCRIT OU EFFACE UNE LIGNE DE `tree_research_closure`.
 *
 * Ce que ce fichier prouve et que `tests/Proposal/FinDeRechercheTest` ne peut pas prouver :
 * que la ligne apparaît réellement, **signée du déposant et datée du dépôt** (ADR 0023, § 5),
 * que la personne est touchée — sa `version` monte, donc l'empreinte et l'`ETag` — même quand
 * **seule** une fin de recherche est écrite, et que la fiche emporte ses fins de recherche
 * quand elle est supprimée. Même mécanisme que {@see NoteCreeeParUneDemandeTest} : le vrai
 * port, une vraie base, des lignes relues par requête après `clear()`.
 */
final class FinDeRechercheCreeeParUneDemandeTest extends BaseDeDemandeSurArbreReel
{
    private const string PERE = 'finDeRecherchePere';
    private const string MERE = 'finDeRechercheMere';

    private const int AUTRE_MEMBRE = 12;

    public function testUneDemandeValideePoseUneLigneSigneeDuDeposantEtDateeDuDepot(): void
    {
        $id = $this->poserUnePersonne('Marne Dolomite', 'Sureau');
        $demande = $this->deposerLaPose($id);

        self::assertSame(0, $this->compter(), 'Un dépôt n’écrit rien.');

        // La date du dépôt est reculée : elle ne peut plus se confondre avec celle de la validation.
        $this->reculerLeDepot($demande, '2026-10-01 10:00:00');

        $resultat = $this->validation()->valider($demande, $this->admin());
        self::assertSame('validee', $resultat->resultat);

        $this->em()->clear();
        self::assertSame(1, $this->compter());

        $ligne = $this->laLigne($id, CoteDeRecherche::Mere);
        self::assertSame(MotifDeFinDeRecherche::RegistresInexistants, $ligne->getReason());
        self::assertSame(1668, $ligne->getBeforeYear());
        self::assertSame('Saint-Martin commence en 1668.', $ligne->getBody());

        self::assertSame(self::OPALE, $ligne->getAuthorUserId(), 'Le déposant, pas l’administrateur qui valide.');
        self::assertSame('Compte contributeur', $ligne->getAuthorLabel());
        self::assertNotSame(self::ADMIN, $ligne->getAuthorUserId());

        self::assertSame(
            (new \DateTimeImmutable('2026-10-01 10:00:00'))->getTimestamp(),
            $ligne->getClosedAt()->getTimestamp(),
            'La date du dépôt (submitted_at), pas celle de la validation (decided_at).',
        );
    }

    public function testLesDeuxCotesFontDeuxLignes(): void
    {
        $id = $this->poserUnePersonne('Jargon', 'Sarriette');
        $demande = $this->depot()->deposer($this->corps($id, [
            $this->op($id, self::PERE, 1, null, ['reason' => 'pere_inconnu', 'beforeYear' => null, 'body' => null]),
            $this->op($id, self::MERE, 2, null, ['reason' => 'registres_detruits', 'beforeYear' => null, 'body' => null]),
        ]), $this->membre());

        $this->validation()->valider($demande, $this->admin());
        $this->em()->clear();

        self::assertSame(2, $this->compter());
        self::assertSame(MotifDeFinDeRecherche::PereInconnu, $this->laLigne($id, CoteDeRecherche::Pere)->getReason());
        self::assertSame(MotifDeFinDeRecherche::RegistresDetruits, $this->laLigne($id, CoteDeRecherche::Mere)->getReason());
    }

    public function testUneModificationReecritEtResigneLaLigne(): void
    {
        $id = $this->poserUnePersonne('Opale', 'Sarriette');
        $this->validation()->valider($this->deposerLaPose($id), $this->admin());
        $this->em()->clear();
        $premiere = $this->laLigne($id, CoteDeRecherche::Mere);
        $ligneId = $premiere->getId();

        $modification = $this->depot()->deposer($this->corps($id, [$this->op(
            $id,
            self::MERE,
            1,
            $this->brique(),
            ['reason' => 'lacune', 'beforeYear' => null, 'body' => 'Années 1789 à 1792.'],
        )]), new Lecteur(self::AUTRE_MEMBRE, 'Compte relecteur', administrateur: false));
        $this->reculerLeDepot($modification, '2026-10-15 08:30:00');

        $this->validation()->valider($modification, $this->admin());
        $this->em()->clear();

        self::assertSame(1, $this->compter(), 'Réécrite, pas ajoutée : un côté, une ligne.');
        $ligne = $this->laLigne($id, CoteDeRecherche::Mere);
        self::assertSame($ligneId, $ligne->getId());
        self::assertSame(MotifDeFinDeRecherche::Lacune, $ligne->getReason());
        self::assertNull($ligne->getBeforeYear());
        self::assertSame('Années 1789 à 1792.', $ligne->getBody());
        self::assertSame(self::AUTRE_MEMBRE, $ligne->getAuthorUserId(), 'Re-signée par l’auteur de la dernière demande.');
        self::assertSame('Compte relecteur', $ligne->getAuthorLabel());
        self::assertSame((new \DateTimeImmutable('2026-10-15 08:30:00'))->getTimestamp(), $ligne->getClosedAt()->getTimestamp());
    }

    public function testUneLeveeSupprimeLaLigneEtLaRevisionLeDit(): void
    {
        $id = $this->poserUnePersonne('Cobalt', 'Sureau');
        $this->validation()->valider($this->deposerLaPose($id), $this->admin());
        $this->em()->clear();
        self::assertSame(1, $this->compter());

        $levee = $this->depot()->deposer($this->corps($id, [$this->op($id, self::MERE, 1, $this->brique(), null)]), $this->membre());
        $this->validation()->valider($levee, $this->admin());
        $this->em()->clear();

        self::assertSame(0, $this->compter(), 'Rouvrir la recherche supprime la ligne.');

        $revisions = self::getContainer()->get(RevisionRepository::class)->pourDemande($levee);
        self::assertSame(
            ['avant' => 'Registres inexistants avant 1668 — Saint-Martin commence en 1668.', 'apres' => '(aucune)'],
            $revisions[0]->getChanges()[self::MERE],
        );
    }

    /** Le piège de `toucher()` : une fin de recherche seule doit faire monter la version. */
    public function testLaVersionDeLaPersonneMonteMemeQuandSeuleUneFinDeRechercheEstEcrite(): void
    {
        $id = $this->poserUnePersonne('Gentiane', 'Muguet');
        $avant = $this->personne($id)->getVersion();

        $this->validation()->valider($this->deposerLaPose($id), $this->admin());
        $this->em()->clear();
        $apresPose = $this->personne($id)->getVersion();
        self::assertGreaterThan($avant, $apresPose, 'Une pose touche la personne.');

        $levee = $this->depot()->deposer($this->corps($id, [$this->op($id, self::MERE, 1, $this->brique(), null)]), $this->membre());
        $this->validation()->valider($levee, $this->admin());
        $this->em()->clear();

        self::assertGreaterThan($apresPose, $this->personne($id)->getVersion(), 'Une levée aussi.');
    }

    public function testLEmpreinteDeLArbreChange(): void
    {
        $id = $this->poserUnePersonne('Lave', 'Romarin');
        $avant = $this->portReel()->empreinteDeLArbre();

        $this->validation()->valider($this->deposerLaPose($id), $this->admin());
        $this->em()->clear();

        self::assertNotSame($avant, $this->portReel()->empreinteDeLArbre(), 'La version de la personne a bougé : l’empreinte et l’ETag du graphe aussi.');
    }

    public function testUnePoseSurUnCoteOuUnParentEstLieEstRefuseeParLeVraiPort(): void
    {
        $enfant = $this->personneNeuve('Enfant', 'Persil');
        $pere = $this->personneNeuve('Pere', 'Persil');
        $famille = new Family($pere, null);
        $this->em()->persist($famille);
        $this->em()->persist(new Child($famille, $enfant, 0));
        $this->em()->flush();
        $id = (int) $enfant->getId();

        self::assertSame(['pere' => true, 'mere' => false], $this->portReel()->cotesConnus($id));
        self::assertSame(['pere' => false, 'mere' => false], $this->portReel()->cotesConnus((int) $pere->getId()));

        $this->expectException(\App\Module\Proposal\Exception\DonneesInvalides::class);
        $this->depot()->deposer($this->corps($id, [$this->op($id, self::PERE, 1, null, ['reason' => 'registres_detruits', 'beforeYear' => null, 'body' => null])]), $this->membre());
    }

    public function testLEcritureSansSignatureLeve(): void
    {
        $id = $this->poserUnePersonne('Sans', 'Signature');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('signée');

        $this->portReel()->appliquerChamps(\App\Module\Core\Enum\SubjectKind::Personne, $id, [
            self::MERE => new \App\Module\Core\ValueObject\FinDeRecherche(MotifDeFinDeRecherche::Lacune, null, null),
        ]);
    }

    public function testLesValeursSontRenduesAuPortEtLaCaduciteLesVoit(): void
    {
        $id = $this->poserUnePersonne('Marne', 'Sureau');
        $this->validation()->valider($this->deposerLaPose($id), $this->admin());
        $this->em()->clear();

        $cible = $this->portReel()->chargerCible(\App\Module\Core\Enum\SubjectKind::Personne, $id);
        self::assertNotNull($cible);
        self::assertNull($cible->valeurs[self::PERE]);
        self::assertInstanceOf(\App\Module\Core\ValueObject\FinDeRecherche::class, $cible->valeurs[self::MERE]);
    }

    // ------------------------------------------------------------------- suppression

    public function testSupprimerLaFicheEmporteSesFinsDeRechercheEtL_inventaireLAnnonce(): void
    {
        $id = $this->poserUnePersonne('Doublon', 'Vide');
        $this->validation()->valider($this->deposerLaPose($id), $this->admin());
        $this->em()->clear();

        $suppression = $this->depot()->deposer([
            'kind' => 'suppression_personne',
            'message' => 'Doublon : la même personne figure déjà sous une autre fiche.',
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => $id,
        ], $this->membre());

        $ops = $this->em()->getRepository(\App\Module\Proposal\Entity\ChangeRequestOp::class)->findBy(['request' => $suppression]);
        /** @var array<string, mixed> $inventaire */
        $inventaire = $ops[0]->getOldValue();
        self::assertSame(1, $inventaire['finsDeRecherche']);
        $cles = array_keys($inventaire);
        self::assertSame('medias', $cles[array_search('finsDeRecherche', $cles, true) - 1], 'Juste après medias (§ 6.4 quater).');

        $this->validation()->valider($suppression, $this->admin());
        $this->em()->clear();

        self::assertSame(0, $this->compter(), 'La ligne est partie avec la fiche.');
        self::assertNull($this->em()->find(Person::class, $id));
    }

    public function testUneSuppressionDeposeeAvantUnePoseDevientCaduque(): void
    {
        $id = $this->poserUnePersonne('Fiche', 'Fragile');

        $suppression = $this->depot()->deposer([
            'kind' => 'suppression_personne',
            'message' => 'Doublon : la même personne figure déjà sous une autre fiche.',
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => $id,
        ], $this->membre());

        $this->validation()->valider($this->deposerLaPose($id), $this->admin());
        $this->em()->clear();

        try {
            $this->validation()->valider($suppression, $this->admin());
            self::fail('Une fin de recherche est apparue depuis le dépôt : la suppression doit être caduque.');
        } catch (Conflit $conflit) {
            self::assertSame('demande_caduque', $conflit->codeErreur());
        }

        self::assertNotNull($this->em()->find(Person::class, $id), 'La fiche n’a pas été effacée.');
        self::assertSame(1, $this->compter());
    }

    // ------------------------------------------------------------------------ outils

    private function personneNeuve(string $prenoms, string $nom): Person
    {
        return $this->personne($this->poserUnePersonne($prenoms, $nom));
    }

    /** @return array{reason: string, beforeYear: int|null, body: string|null} */
    private function brique(): array
    {
        return ['reason' => 'registres_inexistants', 'beforeYear' => 1668, 'body' => 'Saint-Martin commence en 1668.'];
    }

    private function deposerLaPose(int $personneId): ChangeRequest
    {
        return $this->depot()->deposer($this->corps($personneId, [$this->op($personneId, self::MERE, 1, null, $this->brique())]), $this->membre());
    }

    /**
     * @param list<array<string, mixed>> $ops
     *
     * @return array<string, mixed>
     */
    private function corps(int $personneId, array $ops): array
    {
        return [
            'kind' => 'modification_personne',
            'message' => 'Dépouillé aux AD : rien avant 1668.',
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => $personneId,
            'ops' => $ops,
        ];
    }

    /**
     * @param array<string, mixed>|null $ancienne
     * @param array<string, mixed>|null $nouvelle
     *
     * @return array<string, mixed>
     */
    private function op(int $personneId, string $champ, int $seq, ?array $ancienne, ?array $nouvelle): array
    {
        return [
            'seq' => $seq,
            'opKind' => 'champ',
            'subjectKind' => 'personne',
            'subjectId' => $personneId,
            'field' => $champ,
            'valueKind' => 'fin_de_recherche',
            'oldValue' => $ancienne,
            'newValue' => $nouvelle,
            'baseVersion' => $this->personne($personneId)->getVersion(),
        ];
    }

    private function reculerLeDepot(ChangeRequest $demande, string $date): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE prp_change_request SET submitted_at = :date WHERE id = :id',
            ['date' => $date, 'id' => $demande->getId()],
        );
        $this->em()->refresh($demande);
    }

    private function compter(): int
    {
        return (int) $this->em()
            ->createQuery('SELECT COUNT(r.id) FROM '.ResearchClosure::class.' r')
            ->getSingleScalarResult();
    }

    private function laLigne(int $personneId, CoteDeRecherche $cote): ResearchClosure
    {
        $ligne = $this->em()->getRepository(ResearchClosure::class)->findOneBy(['person' => $personneId, 'side' => $cote]);
        self::assertInstanceOf(ResearchClosure::class, $ligne);

        return $ligne;
    }
}

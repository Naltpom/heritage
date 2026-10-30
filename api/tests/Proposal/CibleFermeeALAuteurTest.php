<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Repository\RevisionRepository;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Une cible **fermée** au déposant (ADR 0003) ne se relit jamais par lui : une demande
 * déposée sur une famille dont un époux est masqué, ou sur une citation de cette personne,
 * porte à l'aveugle la valeur courante lue par le serveur ({@see ConstructeurDeModification}).
 * Sa vue d'auteur, ses révisions et la reprise ne doivent donc rendre ni `oldValue` ni
 * `baseVersion` — un seul prédicat, {@see \App\Module\Proposal\Service\ValeursMasquees::cibleFermee()}.
 */
final class CibleFermeeALAuteurTest extends BaseDeDemandes
{
    private const int FAMILLE = 60;
    private const int CITATION = 70;

    /** @return iterable<string, array{string}> */
    public static function lesCibles(): iterable
    {
        yield 'une famille dont un époux est masqué' => ['famille'];
        yield 'une citation d’une personne masquée' => ['citation'];
    }

    #[DataProvider('lesCibles')]
    public function testLaVueDeLAuteurNeRendNiAncienneValeurNiVersionDeBase(string $cible): void
    {
        $demande = $this->deposerSur($cible);

        $vue = $this->serialiseur->pourAuteur($this->recharger($demande));

        self::assertCount(1, $vue['ops']);
        self::assertArrayNotHasKey('oldValue', $vue['ops'][0]);
        self::assertArrayNotHasKey('baseVersion', $vue['ops'][0]);
        self::assertArrayHasKey('newValue', $vue['ops'][0], 'Ce que l’auteur a proposé reste lisible.');
    }

    #[DataProvider('lesCibles')]
    public function testLaRevisionNeRendPasLaValeurDAvant(string $cible): void
    {
        $demande = $this->deposerSur($cible);
        $this->validation->valider($demande, $this->admin());

        $revisions = self::getContainer()->get(RevisionRepository::class)->pourDemande($demande);
        self::assertNotEmpty($revisions);

        foreach ($revisions as $revision) {
            foreach ($this->serialiseur->revision($revision)['changes'] as $changement) {
                self::assertArrayNotHasKey('avant', $changement);
            }
        }
    }

    #[DataProvider('lesCibles')]
    public function testLaRepriseOmetLOpDeLaCibleFermee(string $cible): void
    {
        $demande = $this->deposerSur($cible);
        $this->refus->refuser($demande, $this->admin(), 'Non.', null);

        $corps = $this->reprise->corpsPreRempli($this->recharger($demande));

        self::assertSame([], $corps['ops'], 'La reprise servirait fraîchement relue une valeur que l’auteur ne peut pas lire.');
    }

    // ------------------------------------------------------------------------ outils

    private function deposerSur(string $cible): ChangeRequest
    {
        // Une vivante : hors du cercle du déposant, sa fiche est réduite.
        $this->arbre->poser(SubjectKind::Personne, self::PERSONNE, 'Marne Dolomite Sureau', 7, [
            'givenNames' => 'Marne Dolomite',
            'surname' => 'Sureau',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'feminin',
            'deceased' => false,
            'birth' => $this->annee(1987),
            'death' => UncertainDate::inconnue(),
            'forcePrivacy' => false,
            'privacyNote' => null,
        ]);

        if ('famille' === $cible) {
            $this->arbre->poserCouple(self::FAMILLE, 'Marne Dolomite Sureau × Jargon', self::PERSONNE, 61);
            $this->arbre->poser(SubjectKind::Famille, self::FAMILLE, 'Marne Dolomite Sureau × Jargon', 4, [
                'marriage' => UncertainDate::inconnue(),
                'married' => true,
                'divorced' => false,
                'divorce' => UncertainDate::inconnue(),
            ]);

            return $this->depot->deposer([
                'kind' => 'modification_famille',
                'soumettre' => true,
                'targetKind' => 'famille',
                'targetId' => self::FAMILLE,
                'ops' => [[
                    'seq' => 1,
                    'opKind' => 'champ',
                    'subjectKind' => 'famille',
                    'subjectId' => self::FAMILLE,
                    'field' => 'married',
                    'valueKind' => 'booleen',
                    'oldValue' => false,
                    'newValue' => null,
                    'baseVersion' => 4,
                ]],
            ], $this->lecteur(self::OPALE));
        }

        $this->arbre->poser(SubjectKind::Citation, self::CITATION, 'Registre', 3, ['page' => 'p. 12', 'quay' => 2, 'dataText' => null, 'url' => null], self::PERSONNE);

        return $this->depot->deposer([
            'kind' => 'modification_citation',
            'soumettre' => true,
            'targetKind' => 'citation',
            'targetId' => self::CITATION,
            'ops' => [[
                'seq' => 1,
                'opKind' => 'champ',
                'subjectKind' => 'citation',
                'subjectId' => self::CITATION,
                'field' => 'page',
                'valueKind' => 'texte',
                'oldValue' => 'p. 99',
                'newValue' => 'p. 13',
                'baseVersion' => 3,
            ]],
        ], $this->lecteur(self::OPALE));
    }

    private function admin(): Lecteur
    {
        return new Lecteur(self::ADMIN, 'Natron Romarin', administrateur: true);
    }
}

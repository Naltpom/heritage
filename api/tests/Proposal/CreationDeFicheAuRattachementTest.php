<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Assistant\Contract\CreationDeFichePourLeRattachement;
use App\Module\Core\Entity\User;
use App\Module\Proposal\Exception\ErreurDeDemande;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * L'adaptateur `CreationDeFicheAuRattachement` — la moitié `Proposal` de « Créer la fiche
 * et rattacher », éprouvée **seule**, au niveau du service.
 *
 * Ce qu'on vérifie ici, et pas dans le test HTTP de l'assistant :
 *
 * - la fiche rendue est bien celle du brouillon `#p1`, avec le nom **composé par le
 *   brouillon** (`nomPropose()`), et la demande est `validee` et `auto_validated` — la
 *   fiche naît d'une demande validée (ADR 0004), pas d'une écriture directe ;
 * - les erreurs de `Proposal` remontent **telles quelles** : un corps sans lien rend
 *   `demande_sans_lien`, sans rien laisser en base ;
 * - le port refuse toute autre nature que la création.
 *
 * Le lecteur est posé dans le stockage de jetons, sans requête : `FabriqueDeLecteur` lit
 * le compte chez `Security`, et la bascule « voir comme » rend `null` hors requête.
 */
final class CreationDeFicheAuRattachementTest extends CasDeTestDemandesHttp
{
    private User $administrateur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrateur = $this->creerUtilisateur('natron@example.net', ['ROLE_ADMIN'], 'Natron');
        $jetons = self::getContainer()->get('security.token_storage');
        \assert($jetons instanceof TokenStorageInterface);
        $jetons->setToken(new UsernamePasswordToken($this->administrateur, 'main', $this->administrateur->getRoles()));
    }

    public function testLaFicheDuBrouillonP1EstCreeeParUneDemandeValidee(): void
    {
        $fiche = $this->port()->creerEtValider($this->corps([['seq' => 1, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p1', 'otherPersonId' => self::PERSONNE]]), 'Fiche créée au rattachement d’une inscription.');

        self::assertSame($this->arbre()->personnesCreees(), [$fiche->personId]);
        self::assertSame('Léa Marie Dupont', $fiche->libelle);

        $demande = $this->em()->getConnection()->fetchAssociative('SELECT id, kind, status, auto_validated, author_user_id FROM prp_change_request');
        self::assertIsArray($demande);
        self::assertSame($fiche->demandeId, (int) $demande['id']);
        self::assertSame(['creation_personne', 'validee'], [$demande['kind'], $demande['status']]);
        self::assertTrue((bool) $demande['auto_validated']);
        self::assertSame($this->administrateur->getId(), (int) $demande['author_user_id']);
        self::assertGreaterThan(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM prp_revision'), 'La création laisse ses révisions.');
    }

    public function testUnCorpsSansLienRemonteDemandeSansLienSansRienEcrire(): void
    {
        try {
            $this->port()->creerEtValider($this->corps([]), 'Sans lien.');
            self::fail('Un corps sans lien doit être refusé.');
        } catch (ErreurDeDemande $e) {
            self::assertSame('demande_sans_lien', $e->codeErreur());
        }

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM prp_change_request'));
        self::assertSame([], $this->arbre()->personnesCreees());
    }

    public function testUneAutreNatureQueLaCreationEstRefusee(): void
    {
        $this->expectException(ErreurDeDemande::class);

        $this->port()->creerEtValider(['kind' => 'ajout_lien', 'liens' => []], 'Non.');
    }

    private function port(): CreationDeFichePourLeRattachement
    {
        $port = self::getContainer()->get(CreationDeFichePourLeRattachement::class);
        \assert($port instanceof CreationDeFichePourLeRattachement);

        return $port;
    }

    /**
     * @param list<array<string, mixed>> $liens
     *
     * @return array<string, mixed>
     */
    private function corps(array $liens): array
    {
        return [
            'kind' => 'creation_personne',
            'brouillons' => [[
                'localRef' => '#p1',
                'givenNames' => 'Léa Marie',
                'surname' => 'Dupont',
                'sex' => 'feminin',
                'deceased' => false,
                'birth' => ['raw' => '12 APR 1998', 'qualifier' => 'exacte', 'min' => '1998-04-12', 'max' => '1998-04-12', 'place' => null],
            ]],
            'liens' => $liens,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use Symfony\Component\Routing\RouterInterface;

/**
 * Le diff d'administration (§ 6.7) et la carte des routes du module (§ 6, § 1.7).
 */
final class DiffEtRoutesTest extends BaseDeDemandes
{
    /**
     * L'écran d'administration a **tout ce qu'il faut pour décider avant de cliquer** :
     * la valeur courante de chaque champ, un indicateur `aBouge`, le bandeau `cible`, et
     * `resolutionPrevue`.
     */
    public function testLeDiffPorteLaValeurCouranteEtLIndicateurDeMouvement(): void
    {
        $demande = $this->depot->deposer(
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
            ]], 'l\'acte de Saule donne la fourchette'),
            $this->lecteur(self::OPALE),
        );

        $charge = $this->serialiseur->pourAdministration($demande);

        self::assertSame('application', $charge['resolutionPrevue']);
        self::assertFalse($charge['ops'][0]['aBouge']);
        self::assertSame('1987', $charge['ops'][0]['valeurCourante']['texte']);
        self::assertSame(
            'Naissance : 1987 → entre 1901 et 1909, à Saule, Ombrage, Sylvanie',
            $charge['ops'][0]['libelleDiff'],
            'Le libellé de diff ne rend jamais un point là où la base a un intervalle.',
        );
        self::assertSame(7, $charge['cible']['version']);
        self::assertSame(7, $charge['cible']['versionALaSoumission']);
        self::assertFalse($charge['cible']['aChangeDepuisSoumission']);
    }

    /** Le bandeau s'allume quand la fiche a changé, et `resolutionPrevue` dit ce qui suivra. */
    public function testLeBandeauSAllumeQuandLaFicheAChangeEtAnnonceLeRejeu(): void
    {
        $demande = $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::OPALE),
        );

        // Un autre champ a bougé : la version part en conflit, la valeur non.
        $this->arbre->modifierAilleurs(SubjectKind::Personne, self::PERSONNE, 'nickname', 'Mimi');

        $charge = $this->serialiseur->pourAdministration($this->recharger($demande));

        self::assertTrue($charge['cible']['aChangeDepuisSoumission']);
        self::assertSame('rejeu', $charge['resolutionPrevue']);
        self::assertFalse($charge['ops'][0]['aBouge'], 'C\'est un autre champ qui a bougé, pas celui-ci.');
        self::assertSame(8, $charge['cible']['version']);
    }

    /** Quand la valeur elle-même a bougé, l'écran l'annonce **avant** le clic. */
    public function testLaCaduciteSAnnonceAvantLeClic(): void
    {
        $demande = $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::OPALE),
        );

        $this->arbre->modifierAilleurs(SubjectKind::Personne, self::PERSONNE, 'surname', 'Sureaut');

        $charge = $this->serialiseur->pourAdministration($this->recharger($demande));

        self::assertSame('caducite', $charge['resolutionPrevue']);
        self::assertTrue($charge['ops'][0]['aBouge']);
        self::assertSame('Sureaut', $charge['ops'][0]['valeurCourante']);
    }

    /** Les demandes concurrentes sont vues côte à côte, avec les champs de chacune. */
    public function testLesDemandesConcurrentesSontVisiblesDansLeDiff(): void
    {
        $opale = $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::OPALE),
        );

        $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('nickname', null, 'Mimi')]),
            $this->lecteur(self::TUF),
        );

        $charge = $this->serialiseur->pourAdministration($this->recharger($opale));

        self::assertCount(1, $charge['demandesConcurrentes']);
        self::assertSame(['nickname'], $charge['demandesConcurrentes'][0]['champs']);
        self::assertSame('Compte '.self::TUF, $charge['demandesConcurrentes'][0]['authorLabel']);
    }

    /** L'historique d'une fiche est rendu **formaté** : un historique lisible, pas un vidage. */
    public function testLHistoriqueEstRenduFormate(): void
    {
        $demande = $this->depot->deposer(
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
                    'place' => null,
                ],
                'baseVersion' => 7,
            ]]),
            $this->lecteur(self::OPALE),
        );

        $this->validation->valider($demande, new Lecteur(self::ADMIN, 'Natron Romarin', administrateur: true));

        $revisions = self::getContainer()
            ->get(\App\Module\Proposal\Repository\RevisionRepository::class)
            ->historique(SubjectKind::Personne, self::PERSONNE, 1, 25);

        self::assertSame(1, $revisions['total']);

        $rendu = $this->serialiseur->revision($revisions['elements'][0]);

        self::assertSame(
            ['birth' => ['avant' => '1987', 'apres' => 'entre 1901 et 1909']],
            $rendu['changes'],
        );
        self::assertSame($demande->getId(), $rendu['requestId']);

        // Révision du 2026-10-04 de l'ADR 0003 : un historique se rattache à la fiche
        // que visaient les demandes qui l'ont écrit — c'est ainsi que celui d'une
        // citation, d'une note, d'un fait ou d'un média se ferme hors du périmètre.
        self::assertSame(
            [['kind' => SubjectKind::Personne, 'id' => self::PERSONNE]],
            self::getContainer()
                ->get(\App\Module\Proposal\Repository\RevisionRepository::class)
                ->ciblesDesDemandes(SubjectKind::Personne, self::PERSONNE),
        );
    }

    /**
     * Les routes du § 6, aux chemins exacts du contrat d'API.
     *
     * Le front est écrit contre ces chemins : une faute de frappe ici est une panne
     * là-bas, et elle se diagnostique mal. Le § 1.7 impose en outre que `/api/demandes`
     * et `/api/administration/demandes` appartiennent à `Proposal` et à lui seul.
     */
    public function testLesRoutesDuContratSontDeclarees(): void
    {
        $routeur = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $routeur);

        $attendues = [
            'proposal_demande_creer' => ['/api/demandes', 'POST'],
            'proposal_demande_lister' => ['/api/demandes', 'GET'],
            'proposal_demande_voir' => ['/api/demandes/{id}', 'GET'],
            'proposal_demande_soumettre' => ['/api/demandes/{id}/soumettre', 'POST'],
            'proposal_demande_retirer' => ['/api/demandes/{id}/retirer', 'POST'],
            'proposal_demande_refaire' => ['/api/demandes/{id}/refaire', 'GET'],
            'proposal_demande_brouillon' => ['/api/demandes/{id}/brouillons/{ref}', 'GET'],
            // Un `POST` qui n'écrit rien (§ 6.6 quater) : la saisie — souvent la naissance
            // d'un vivant — reste hors de l'URL et des journaux.
            'proposal_demande_ressemblances' => ['/api/demandes/brouillons/ressemblances', 'POST'],
            'proposal_demande_historique' => ['/api/demandes/historique/{subjectKind}/{subjectId}', 'GET'],
            'proposal_admin_demande_lister' => ['/api/administration/demandes', 'GET'],
            'proposal_admin_demande_voir' => ['/api/administration/demandes/{id}', 'GET'],
            'proposal_admin_demande_valider' => ['/api/administration/demandes/{id}/valider', 'POST'],
            'proposal_admin_demande_refuser' => ['/api/administration/demandes/{id}/refuser', 'POST'],
        ];

        $collection = $routeur->getRouteCollection();

        foreach ($attendues as $nom => [$chemin, $methode]) {
            $route = $collection->get($nom);

            self::assertNotNull($route, \sprintf('La route « %s » doit être déclarée.', $nom));
            self::assertSame($chemin, $route->getPath());
            self::assertContains($methode, $route->getMethods());
        }
    }

    /**
     * Le `#` d'une référence provisoire ne passe pas dans un chemin d'URL : tout ce qui
     * le suit est un fragment, jamais envoyé au serveur. La route prend donc la
     * référence **sans dièse** (§ 17.3, divergence 8).
     */
    public function testLaRouteDUnBrouillonPrendLaReferenceSansDiese(): void
    {
        $routeur = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $routeur);

        $route = $routeur->getRouteCollection()->get('proposal_demande_brouillon');
        self::assertNotNull($route);

        self::assertSame('[A-Za-z]\d+', $route->getRequirement('ref'));
        self::assertSame(
            '/api/demandes/12/brouillons/p1',
            $routeur->generate('proposal_demande_brouillon', ['id' => 12, 'ref' => 'p1']),
        );
    }

    /**
     * L'identifiant de nœud d'un brouillon est `#<demandeId>/<ref>`, fabriqué **par
     * l'API** : `local_ref` n'est unique que dans sa demande, et un administrateur qui
     * les voit toutes recevrait plusieurs nœuds `#p1` si le front concaténait.
     */
    public function testLIdentifiantDeNoeudDUnBrouillonPorteLaDemande(): void
    {
        self::assertSame('#12/p1', $this->serialiseur->noeudId(12, '#p1'));
        self::assertSame('#13/p1', $this->serialiseur->noeudId(13, 'p1'));
    }
}

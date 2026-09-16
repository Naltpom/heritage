<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\UserStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le journal d'audit : il se remplit tout seul, il masque, et il ne s'altère pas.
 */
final class JournalAuditTest extends CoreWebTestCase
{
    public function testUneConnexionEtUneTentativeEchoueeSontJournalisees(): void
    {
        $utilisateur = $this->creerUtilisateur('cobalt@example.net');

        $this->requete('POST', '/api/connexion', ['email' => 'cobalt@example.net', 'password' => 'faux']);
        $this->connexion('cobalt@example.net');

        $lignes = $this->journal();

        $echec = $this->premiere($lignes, AuditAction::ConnexionEchouee);
        self::assertNotNull($echec, 'Une tentative échouée doit laisser une trace.');
        self::assertSame('cobalt@example.net', $echec->getActorEmail());
        self::assertNull($echec->getActorUserId(), 'Une tentative échouée n’identifie pas de compte.');
        self::assertNotNull($echec->getIp());

        $succes = $this->premiere($lignes, AuditAction::Connexion);
        self::assertNotNull($succes);
        self::assertSame($utilisateur->getId(), $succes->getActorUserId());
        self::assertSame('Core\\User', $succes->getSubjectType());

        // Le mot de passe tenté n'apparaît nulle part, sous aucune forme.
        foreach ($lignes as $ligne) {
            self::assertStringNotContainsString('faux', json_encode($ligne->getChanges(), \JSON_THROW_ON_ERROR));
        }
    }

    /**
     * L'exigence centrale : une écriture qui passe par l'API laisse un diff **champ par
     * champ** dans le journal, sans qu'aucun service ait eu à le demander.
     */
    public function testUneEcritureLaisseUnDiffAvecLesChampsSensiblesMasques(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('cobalt@example.net');

        $this->requete('POST', '/api/session/mot-de-passe', [
            'motDePasseActuel' => 'motdepasse-de-test',
            'nouveauMotDePasse' => 'un-nouveau-mot-de-passe-long',
        ]);
        self::assertResponseIsSuccessful();

        $modification = $this->premiere($this->journal(), AuditAction::Modification);

        self::assertNotNull($modification, 'Un changement de mot de passe doit être journalisé.');
        self::assertSame('Core\\User', $modification->getSubjectType());

        $changes = $modification->getChanges();
        self::assertIsArray($changes);
        self::assertArrayHasKey('password', $changes);
        // « Masquer à la lecture laisserait le secret en base » : la valeur n'est jamais
        // écrite, ni l'ancienne empreinte, ni la nouvelle.
        self::assertSame('***', $changes['password']);
        self::assertStringNotContainsString(
            'un-nouveau-mot-de-passe-long',
            json_encode($changes, \JSON_THROW_ON_ERROR),
        );

        // Le nouveau mot de passe fonctionne : le test ne vérifie pas que le journal.
        $this->requete('POST', '/api/deconnexion');
        $this->connexion('cobalt@example.net', 'un-nouveau-mot-de-passe-long');
        self::assertResponseIsSuccessful();
    }

    public function testLeDiffPorteLAncienneEtLaNouvelleValeur(): void
    {
        $administrateur = $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        // Une écriture sur une autre colonne que `password`, pour voir la forme du diff.
        $this->requete('PATCH', '/api/administration/reglages/cercle.generations', ['value' => 4]);
        self::assertResponseIsSuccessful();

        $modification = $this->premiere($this->journal(), AuditAction::Modification);
        self::assertNotNull($modification);
        self::assertSame('Core\\Setting', $modification->getSubjectType());

        $changes = $modification->getChanges();
        self::assertIsArray($changes);
        self::assertArrayHasKey('value', $changes);
        // Clés françaises : ce ne sont pas des noms de colonnes (§ 1.2 du contrat).
        self::assertSame(['avant' => '3', 'apres' => '4'], $changes['value']);
        self::assertSame($administrateur->getId(), $modification->getActorUserId());
    }

    /**
     * « Toute décision d'administration est journalisée » (§ 8 du dossier de mission) :
     * la décision, distincte du diff mécanique de la ligne.
     */
    public function testUneDecisionDAdministrationEstJournaliseeCommeTelle(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        $this->requete('PATCH', '/api/administration/reglages/cercle.generations', ['value' => 5]);
        self::assertResponseIsSuccessful();

        $decision = $this->premiere($this->journal(), AuditAction::ReglageModifie);
        self::assertNotNull($decision, 'Un changement de réglage est une décision d’administration.');
        self::assertSame('cercle.generations', $decision->getSubjectLabel());
        self::assertSame(
            ['cercle.generations' => ['avant' => 3, 'apres' => 5]],
            $decision->getChanges(),
        );
        self::assertSame('natron@example.net', $decision->getActorEmail());
    }

    public function testLeJournalSeLitParUnAdministrateurEtParPersonneDAutre(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);

        $this->connexion('cobalt@example.net');
        $this->requete('GET', '/api/administration/journal');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('acces_refuse', $this->charge()['erreur']['code']);

        $this->requete('POST', '/api/deconnexion');
        $this->connexion('natron@example.net');
        $this->requete('GET', '/api/administration/journal');
        self::assertResponseIsSuccessful();

        $charge = $this->charge();
        // Enveloppe de liste paginée du § 1.5.
        self::assertArrayHasKey('elements', $charge);
        self::assertArrayHasKey('total', $charge);
        self::assertSame(1, $charge['page']);
        self::assertSame(25, $charge['parPage']);
        self::assertNotEmpty($charge['elements']);

        $premiere = $charge['elements'][0];
        foreach (['id', 'occurredAt', 'actorEmail', 'action', 'subjectType', 'subjectId', 'subjectLabel', 'changes', 'ip', 'userAgent'] as $cle) {
            self::assertArrayHasKey($cle, $premiere, \sprintf('La clé « %s » du § 10.3 manque.', $cle));
        }
    }

    public function testLeJournalSeFiltreParAction(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->requete('POST', '/api/connexion', ['email' => 'natron@example.net', 'password' => 'faux']);
        $this->connexion('natron@example.net');

        $this->requete('GET', '/api/administration/journal?action=connexion_echouee');
        self::assertResponseIsSuccessful();

        $elements = $this->charge()['elements'];
        self::assertNotEmpty($elements);
        foreach ($elements as $element) {
            self::assertSame('connexion_echouee', $element['action']);
        }

        // Une action inconnue n'est pas une erreur : c'est un filtre qui ne dit rien sur
        // les actions qui existent.
        $this->requete('GET', '/api/administration/journal?action=action_inventee');
        self::assertResponseIsSuccessful();
    }

    /**
     * L'inaltérabilité côté applicatif, vérifiée sur les deux fronts : aucune route
     * n'écrit dans le journal, et le souscripteur Doctrine refuse une écriture directe.
     */
    public function testAucuneRouteNEcritNiNEffaceLeJournal(): void
    {
        $routeur = self::getContainer()->get('router');
        \assert($routeur instanceof \Symfony\Component\Routing\RouterInterface);

        foreach ($routeur->getRouteCollection() as $nom => $route) {
            if (!str_contains($route->getPath(), 'journal')) {
                continue;
            }

            self::assertSame(
                ['GET'],
                $route->getMethods(),
                \sprintf('La route « %s » touche au journal avec autre chose qu’un GET.', $nom),
            );
        }
    }

    public function testLeSouscripteurRefuseDeModifierUneLigneDeJournal(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('cobalt@example.net');

        $em = $this->em();
        $ligne = $em->getRepository(AuditLog::class)->findOneBy([]);
        self::assertInstanceOf(AuditLog::class, $ligne);

        // On force une modification comme le ferait un service écrit sans y penser :
        // la garde doit tomber avant l'`UPDATE`, pas après.
        $em->createQuery('SELECT a FROM '.AuditLog::class.' a')->getResult();
        $reflexion = new \ReflectionProperty(AuditLog::class, 'ip');
        $reflexion->setValue($ligne, '10.0.0.1');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('inaltérable');
        $em->flush();
    }

    /**
     * Une connexion ne doit pas laisser une ligne `modification` vide à côté de sa ligne
     * `connexion` : `lastLoginAt` seul n'est pas un changement.
     */
    public function testUneConnexionNeLaissePasDeModificationTechnique(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('cobalt@example.net');

        self::assertNull(
            $this->premiere($this->journal(), AuditAction::Modification),
            'Se connecter ne modifie pas le compte : `lastLoginAt` est un champ technique.',
        );
    }

    public function testUnCompteSuspenduNeLaisseQuUneTentativeEchouee(): void
    {
        $this->creerUtilisateur('exclu@example.net', statut: UserStatus::Suspendu);

        $this->connexion('exclu@example.net');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $actions = array_map(static fn (AuditLog $l) => $l->getAction(), $this->journal());
        self::assertContains(AuditAction::ConnexionEchouee, $actions);
        self::assertNotContains(AuditAction::Connexion, $actions);
    }

    /** @param list<AuditLog> $lignes */
    private function premiere(array $lignes, AuditAction $action): ?AuditLog
    {
        foreach ($lignes as $ligne) {
            if ($action === $ligne->getAction()) {
                return $ligne;
            }
        }

        return null;
    }
}

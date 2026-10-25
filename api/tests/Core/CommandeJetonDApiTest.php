<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Entity\JetonDApi;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\PorteeDeJeton;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Service\GenerateurDeJetonDApi;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Les commandes `heritage:jeton-api:*` (ADR 0021 « Jetons d'API »).
 *
 * Le jeton n'est jamais écrit en littéral ici : on compare à ce que la commande a
 * affiché. Ce qui compte : le clair n'existe nulle part en base ni au journal, seule
 * l'empreinte est stockée, et la révocation est idempotente.
 */
final class CommandeJetonDApiTest extends CoreWebTestCase
{
    private const string COURRIEL = 'porteur@example.net';

    public function testLaCreationAfficheLeJetonUneFoisEtNeStockeQueSonEmpreinte(): void
    {
        $this->creerUtilisateur(self::COURRIEL);

        $testeur = $this->executer('creer', [
            'utilisateur' => self::COURRIEL,
            'libelle' => 'Script de sauvegarde',
            '--portee' => 'lecture',
            '--jours' => '30',
        ]);

        self::assertSame(Command::SUCCESS, $testeur->getStatusCode());
        $jeton = $this->jetonAffiche($testeur);
        self::assertTrue((new GenerateurDeJetonDApi())->formeValide($jeton));
        self::assertStringContainsString('PLUS JAMAIS', preg_replace('/\s+/', ' ', $testeur->getDisplay()) ?? '');

        $lignes = $this->em()->getRepository(JetonDApi::class)->findAll();
        self::assertCount(1, $lignes);
        $ligne = $lignes[0];
        self::assertSame(hash('sha256', $jeton), $ligne->getTokenHash());
        self::assertNotSame($jeton, $ligne->getTokenHash());
        self::assertSame(substr($jeton, 0, GenerateurDeJetonDApi::LONGUEUR_PREFIXE), $ligne->getTokenPrefix());
        self::assertSame(PorteeDeJeton::Lecture, $ligne->getPortee());
        self::assertSame('Script de sauvegarde', $ligne->getLibelle());
        $jours = (int) round(($ligne->getExpiresAt()->getTimestamp() - $ligne->getCreatedAt()->getTimestamp()) / 86400);
        self::assertSame(30, $jours);

        $audit = $this->lignesDAudit(AuditAction::JetonApiCree);
        self::assertCount(1, $audit);
        self::assertSame((string) $ligne->getId(), $audit[0]->getSubjectId());
        self::assertSame('Script de sauvegarde', $audit[0]->getSubjectLabel());
        $trace = json_encode($audit[0]->getChanges(), \JSON_THROW_ON_ERROR).$audit[0]->getSubjectLabel();
        self::assertStringNotContainsString($jeton, $trace);
        self::assertStringNotContainsString($ligne->getTokenHash(), $trace);
    }

    public function testLaDureeParDefautEstDeQuatreVingtDixJours(): void
    {
        $this->creerUtilisateur(self::COURRIEL);

        $this->executer('creer', ['utilisateur' => self::COURRIEL, 'libelle' => 'x', '--portee' => 'contribution']);

        $ligne = $this->em()->getRepository(JetonDApi::class)->findAll()[0];
        $jours = (int) round(($ligne->getExpiresAt()->getTimestamp() - $ligne->getCreatedAt()->getTimestamp()) / 86400);
        self::assertSame(90, $jours);
    }

    public function testUnComptePeutEtreDesigneParSonId(): void
    {
        $compte = $this->creerUtilisateur(self::COURRIEL);

        $testeur = $this->executer('creer', ['utilisateur' => (string) $compte->getId(), 'libelle' => 'x', '--portee' => 'validation']);

        self::assertSame(Command::SUCCESS, $testeur->getStatusCode());
        self::assertCount(1, $this->em()->getRepository(JetonDApi::class)->findAll());
    }

    public function testUnCompteSuspenduOuInexistantEstRefuse(): void
    {
        $this->creerUtilisateur(self::COURRIEL, statut: UserStatus::Suspendu);

        $suspendu = $this->executer('creer', ['utilisateur' => self::COURRIEL, 'libelle' => 'x', '--portee' => 'lecture']);
        $inconnu = $this->executer('creer', ['utilisateur' => 'personne@example.net', 'libelle' => 'x', '--portee' => 'lecture']);

        self::assertSame(Command::FAILURE, $suspendu->getStatusCode());
        self::assertSame(Command::FAILURE, $inconnu->getStatusCode());
        self::assertSame([], $this->em()->getRepository(JetonDApi::class)->findAll());
        self::assertSame([], $this->lignesDAudit(AuditAction::JetonApiCree));
    }

    public function testLaPorteeEstObligatoireEtValidee(): void
    {
        $this->creerUtilisateur(self::COURRIEL);

        $absente = $this->executer('creer', ['utilisateur' => self::COURRIEL, 'libelle' => 'x']);
        $invalide = $this->executer('creer', ['utilisateur' => self::COURRIEL, 'libelle' => 'x', '--portee' => 'toute-puissante']);
        $duree = $this->executer('creer', ['utilisateur' => self::COURRIEL, 'libelle' => 'x', '--portee' => 'lecture', '--jours' => '4000']);

        self::assertSame(Command::FAILURE, $absente->getStatusCode());
        self::assertSame(Command::FAILURE, $invalide->getStatusCode());
        self::assertSame(Command::FAILURE, $duree->getStatusCode());
        self::assertSame([], $this->em()->getRepository(JetonDApi::class)->findAll());
    }

    public function testLeListageNeMontreNiLeJetonNiSonEmpreinte(): void
    {
        $this->creerUtilisateur(self::COURRIEL);
        $this->creerUtilisateur('autre@example.net');
        $creation = $this->executer('creer', ['utilisateur' => self::COURRIEL, 'libelle' => 'Mon jeton', '--portee' => 'lecture']);
        $jeton = $this->jetonAffiche($creation);
        $this->executer('creer', ['utilisateur' => 'autre@example.net', 'libelle' => 'Celui de l\'autre', '--portee' => 'lecture']);

        $liste = $this->executer('lister', ['--utilisateur' => self::COURRIEL]);

        self::assertSame(Command::SUCCESS, $liste->getStatusCode());
        $sortie = $liste->getDisplay();
        self::assertStringContainsString('Mon jeton', $sortie);
        self::assertStringContainsString(substr($jeton, 0, GenerateurDeJetonDApi::LONGUEUR_PREFIXE), $sortie);
        self::assertStringContainsString('lecture', $sortie);
        self::assertStringNotContainsString('Celui de l\'autre', $sortie);
        self::assertStringNotContainsString($jeton, $sortie);
        self::assertStringNotContainsString(hash('sha256', $jeton), $sortie);
    }

    public function testLaRevocationEstIdempotenteEtJournaliseUneSeuleFois(): void
    {
        $this->creerUtilisateur(self::COURRIEL);
        $this->executer('creer', ['utilisateur' => self::COURRIEL, 'libelle' => 'À révoquer', '--portee' => 'lecture']);
        $id = (int) $this->em()->getRepository(JetonDApi::class)->findAll()[0]->getId();

        $premiere = $this->executer('revoquer', ['jeton' => (string) $id]);
        $seconde = $this->executer('revoquer', ['jeton' => (string) $id]);

        self::assertSame(Command::SUCCESS, $premiere->getStatusCode());
        self::assertSame(Command::SUCCESS, $seconde->getStatusCode());
        self::assertStringContainsString('déjà révoqué', preg_replace('/\s+/', ' ', $seconde->getDisplay()) ?? '');
        $this->em()->clear();
        self::assertTrue($this->em()->find(JetonDApi::class, $id)?->estRevoque());
        self::assertCount(1, $this->lignesDAudit(AuditAction::JetonApiRevoque));

        // Révoqué : absent du listage par défaut, présent avec --tous.
        self::assertStringContainsString('Aucun jeton', $this->executer('lister')->getDisplay());
        self::assertStringContainsString('À révoquer', $this->executer('lister', ['--tous' => true])->getDisplay());
    }

    public function testLaRevocationParPrefixeExigeQuIlSoitSansAmbiguite(): void
    {
        $this->creerUtilisateur(self::COURRIEL);
        $creation = $this->executer('creer', ['utilisateur' => self::COURRIEL, 'libelle' => 'x', '--portee' => 'lecture']);
        $prefixe = substr($this->jetonAffiche($creation), 0, GenerateurDeJetonDApi::LONGUEUR_PREFIXE);

        $inconnu = $this->executer('revoquer', ['jeton' => 'hrt_inconnu00']);
        $connu = $this->executer('revoquer', ['jeton' => $prefixe]);

        self::assertSame(Command::FAILURE, $inconnu->getStatusCode());
        self::assertSame(Command::SUCCESS, $connu->getStatusCode());
        self::assertCount(1, $this->lignesDAudit(AuditAction::JetonApiRevoque));
    }

    /** @param array<string, string|bool> $parametres */
    private function executer(string $commande, array $parametres = []): CommandTester
    {
        $application = new Application($this->client->getKernel());
        $testeur = new CommandTester($application->find('heritage:jeton-api:'.$commande));
        $testeur->execute($parametres);

        return $testeur;
    }

    /** Le jeton que la commande a affiché : la ligne qui a la forme d'un jeton. */
    private function jetonAffiche(CommandTester $testeur): string
    {
        foreach (preg_split('/\R/', $testeur->getDisplay()) ?: [] as $ligne) {
            if ((new GenerateurDeJetonDApi())->formeValide(trim($ligne))) {
                return trim($ligne);
            }
        }
        self::fail('Aucun jeton dans la sortie de la commande.');
    }

    /** @return list<AuditLog> */
    private function lignesDAudit(AuditAction $action): array
    {
        return array_values($this->em()->getRepository(AuditLog::class)->findBy(['action' => $action]));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Security\SessionsSansVerrou;
use PHPUnit\Framework\TestCase;

/**
 * Les sessions en fichiers sans verrou : mêmes fichiers que le gestionnaire natif, aucune
 * attente entre deux requêtes d'un même compte, et une session détruite qui ne ressuscite
 * pas. Voir {@see SessionsSansVerrou}.
 */
final class SessionsSansVerrouTest extends TestCase
{
    private const string ID = 'abc123def456ghi789jkl012mno345pq';

    private string $repertoire;

    protected function setUp(): void
    {
        $this->repertoire = sys_get_temp_dir().'/sessions-sans-verrou-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->repertoire.'/{,.}*', \GLOB_BRACE) ?: [] as $fichier) {
            if (is_file($fichier)) {
                unlink($fichier);
            }
        }

        if (is_dir($this->repertoire)) {
            rmdir($this->repertoire);
        }
    }

    public function testUneSessionEcriteSeRelitDansLeFichierDuGestionnaireNatif(): void
    {
        $sessions = $this->ouvrir();

        self::assertSame('', $sessions->read(self::ID), 'Une session inconnue se lit vide.');
        self::assertTrue($sessions->write(self::ID, '_sf2_attributes|a:1:{s:1:"x";i:1;}'));
        $sessions->close();

        // Le nom et le contenu du gestionnaire `files` de PHP : `sess_<id>`, données brutes.
        $fichier = $this->repertoire.'/sess_'.self::ID;
        self::assertSame('_sf2_attributes|a:1:{s:1:"x";i:1;}', file_get_contents($fichier));
        self::assertSame('0600', substr(\sprintf('%o', fileperms($fichier)), -4), 'Une session ne se lit que par le serveur.');
        self::assertSame('_sf2_attributes|a:1:{s:1:"x";i:1;}', $this->ouvrir()->read(self::ID));
        self::assertSame([$fichier], glob($this->repertoire.'/{,.}sess_*', \GLOB_BRACE), 'Aucun fichier temporaire ne reste derrière une écriture.');
    }

    public function testUneSessionOuverteDeLAncienGestionnaireResteValide(): void
    {
        mkdir($this->repertoire, 0o700, true);
        file_put_contents($this->repertoire.'/sess_'.self::ID, 'ancienne');

        self::assertSame('ancienne', $this->ouvrir()->read(self::ID), 'Le déploiement ne déconnecte personne.');
    }

    /**
     * Le cœur de la classe : un verrou exclusif posé par une autre requête (ici, le
     * gestionnaire natif d'avant, ou n'importe quel `flock`) n'arrête pas la lecture.
     */
    public function testUneSessionVerrouilleeParAilleursSeLitSansAttendre(): void
    {
        mkdir($this->repertoire, 0o700, true);
        $fichier = $this->repertoire.'/sess_'.self::ID;
        file_put_contents($fichier, 'occupee');
        $poignee = fopen($fichier, 'r');
        self::assertNotFalse($poignee);
        self::assertTrue(flock($poignee, \LOCK_EX));

        try {
            $debut = hrtime(true);
            self::assertSame('occupee', $this->ouvrir()->read(self::ID));
            self::assertLessThan(500, (hrtime(true) - $debut) / 1e6);
        } finally {
            flock($poignee, \LOCK_UN);
            fclose($poignee);
        }
    }

    /**
     * Une déconnexion pendant qu'une requête plus ancienne est en cours : celle-ci ne
     * doit pas réécrire le jeton qu'on vient d'effacer.
     */
    public function testUneSessionDetruitePendantLaRequeteNeRessuscitePas(): void
    {
        $avant = $this->ouvrir();
        self::assertTrue($avant->write(self::ID, 'connecte'));
        $avant->close();

        $lente = $this->ouvrir();
        self::assertSame('connecte', $lente->read(self::ID));

        // La déconnexion, par une autre requête.
        $this->ouvrir()->destroy(self::ID);

        self::assertTrue($lente->write(self::ID, 'connecte-modifie'));
        self::assertTrue($lente->updateTimestamp(self::ID, 'connecte'));
        self::assertFileDoesNotExist($this->repertoire.'/sess_'.self::ID);
    }

    public function testUneSessionNeuveSeCreeNormalement(): void
    {
        $sessions = $this->ouvrir();
        self::assertSame('', $sessions->read(self::ID));
        self::assertTrue($sessions->write(self::ID, 'neuve'));

        self::assertFileExists($this->repertoire.'/sess_'.self::ID);
    }

    public function testUneSessionInchangeeEstDateeSansEtreRecreee(): void
    {
        $sessions = $this->ouvrir();
        self::assertTrue($sessions->updateTimestamp(self::ID, ''));
        self::assertFileDoesNotExist($this->repertoire.'/sess_'.self::ID, 'Dater une session absente ne la crée pas.');

        $sessions->write(self::ID, 'x');
        touch($this->repertoire.'/sess_'.self::ID, time() - 3600);
        clearstatcache();
        self::assertTrue($sessions->updateTimestamp(self::ID, 'x'));
        clearstatcache();
        self::assertGreaterThan(time() - 60, filemtime($this->repertoire.'/sess_'.self::ID));
    }

    public function testUnIdentifiantQuiNEstPasUnNomDeFichierEstRefuse(): void
    {
        $sessions = $this->ouvrir();

        foreach (['../../etc/passwd', 'a/b', '', 'a b'] as $id) {
            self::assertSame('', $sessions->read($id), $id);
            self::assertFalse($sessions->write($id, 'x'), $id);
            self::assertFalse($sessions->validateId($id), $id);
        }
    }

    public function testLeRamasseMietteNeRetireQueLesSessionsPerimees(): void
    {
        $sessions = $this->ouvrir();
        $sessions->write('vieille', 'v');
        $sessions->write('recente', 'r');
        touch($this->repertoire.'/sess_vieille', time() - 7200);

        self::assertSame(1, $sessions->gc(3600));
        self::assertFalse($sessions->validateId('vieille'));
        self::assertTrue($sessions->validateId('recente'));
    }

    private function ouvrir(): SessionsSansVerrou
    {
        $sessions = new SessionsSansVerrou($this->repertoire);
        self::assertTrue($sessions->open($this->repertoire, 'PHPSESSID'));

        return $sessions;
    }
}

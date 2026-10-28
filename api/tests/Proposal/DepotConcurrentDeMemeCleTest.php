<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Service\CleDIdempotence;

/**
 * Deux dépôts de même `Idempotency-Key` au même instant (ADR 0022 § 5), au niveau du service.
 *
 * Une vraie course ne se joue pas sur le SQLite des tests. Ce qu'on rejoue ici, c'est sa
 * seconde moitié : le perdant a manqué la recherche de rejeu (le gagnant n'avait pas encore
 * validé), il a construit sa demande, et c'est `uniq_prp_request_idempotence` qui l'arrête
 * au `flush()`. On appelle donc {@see \App\Module\Proposal\Service\DepotDeDemande::deposer()}
 * deux fois avec la même clé, sans passer par la recherche, chacune dans sa requête.
 *
 * Ce qui compte, et que le test tient : le perdant reçoit `409 depot_concurrent` rejouable,
 * qui nomme la demande du gagnant, **lue par la connexion seule** puisque l'EntityManager
 * est fermé ; et rien n'est écrit ni notifié pour lui.
 */
final class DepotConcurrentDeMemeCleTest extends BaseDeDemandes
{
    public function testLePerdantDUneCourseRend409RejouableSansRienEcrireNiNotifier(): void
    {
        $corps = $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]);
        $cle = CleDIdempotence::depuisLEnTete(str_repeat('a', 64), $corps);
        self::assertNotNull($cle);

        $gagnante = $this->depot->deposer($corps, $this->lecteur(self::ADMIN, true), $cle);
        $idGagnante = $gagnante->getId();
        self::assertNotNull($idGagnante);

        $this->nouvelleRequete();

        try {
            $this->depot->deposer($corps, $this->lecteur(self::ADMIN, true), $cle);
            self::fail('La même clé chez le même auteur : la base doit refuser le second dépôt.');
        } catch (Conflit $erreur) {
            self::assertSame('depot_concurrent', $erreur->codeErreur());
            self::assertSame(409, $erreur->statutHttp());
            self::assertSame(['demandeId' => $idGagnante, 'rejouable' => true], $erreur->details());
        }

        $this->nouvelleRequete();

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM prp_change_request'));
        self::assertCount(1, $this->notificateur->de('demande_soumise'), 'Le perdant ne notifie pas : la notification suit le flush.');
    }

    /** Sans clé, rien ne change : deux dépôts identiques font deux demandes. */
    public function testSansCleDeuxDepotsFontDeuxDemandes(): void
    {
        $corps = $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]);

        $this->depot->deposer($corps, $this->lecteur(self::ADMIN, true));
        $this->nouvelleRequete();
        $this->depot->deposer($corps, $this->lecteur(self::ADMIN, true));

        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM prp_change_request'));
    }
}

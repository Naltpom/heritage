<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Core\Enum\UserStatus;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LE PRÉ-LIEN N'OUVRE AUCUN ACCÈS EN LECTURE.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * `mbr_invitation.person_id` désigne la personne que l'administrateur attend, et le
 * candidat va **confirmer sa date de naissance**. Il serait naturel d'en conclure qu'il a
 * le droit de voir cette fiche : il vient de prouver qu'il la connaît.
 *
 * C'est arbitré, et c'est non :
 *
 * > **Confirmer une date n'est pas la lire**, et le pré-lien n'ouvre aucun accès (§ 4.5 du
 * > modèle, § 12.1 du contrat, ADR 0003).
 *
 * Le motif est mécanique, et il vaut la peine d'être relu : sans cette règle, **le
 * garde-fou anti-énumération de l'étape 1 serait contourné par l'étape qui le suit.** On
 * plafonne à trois candidats en affichage minimal, on limite à cinq tentatives par heure —
 * et l'étape suivante rendrait la fiche complète. Le porteur d'un lien n'aurait qu'à
 * deviner une date pour obtenir tout le reste.
 *
 * Ce fichier suit donc un candidat d'un bout à l'autre du parcours et vérifie, à chaque
 * réponse, que **rien de la personne** n'en sort — ni le nom complet, ni les autres champs.
 */
final class PreLienSansLectureTest extends CasDeTestMembership
{
    /**
     * L'étape 1a ne dit rien de la personne pré-liée — **pas même une initiale**.
     *
     * `mode: "confirmation"` révèle qu'un administrateur a pré-lié quelqu'un : c'est exact,
     * et c'est le minimum indispensable pour que le formulaire soit le bon. « Le porteur du
     * jeton l'a reçu nominativement ; il apprend qu'on l'attendait, pas **qui** on
     * attendait » (§ 4.1).
     */
    public function testLEtape1aNeDitRienDeLaPersonne(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur, ArbreFamilial::EGO, 'Natron Romarin');

        $this->requete('POST', '/api/inscription/verifier', ['jeton' => $jeton]);

        $this->assertRienDeLaPersonne($this->corpsBrut());
    }

    /**
     * L'étape 1b, **même quand la date est bonne**, ne rend que `ref` et le libellé
     * minimal.
     *
     * `N. Romarin, 1980` — initiale, nom, année. Ni prénom entier, ni date exacte, ni
     * `personId` : « un `personId` ici donnerait au candidat l'identifiant réel d'une
     * personne vivante, c'est-à-dire le début d'une énumération par incrémentation »
     * (§ 17.1, divergence 2).
     */
    public function testLEtape1bNeRendQueLeLibelleMinimalEtUneReferenceOpaque(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur, ArbreFamilial::EGO, 'Natron Romarin');

        $this->requete('POST', '/api/inscription/rattachement', [
            'jeton' => $jeton,
            'surname' => 'Romarin',
            'givenNames' => 'Natron',
            'birthDate' => '1980-07-27',
        ]);

        $corps = $this->charge();
        self::assertIsArray($corps['candidats']);
        self::assertCount(1, $corps['candidats'], 'Au plus un candidat en mode confirmation.');

        $candidat = $corps['candidats'][0];

        self::assertSame(['ref', 'libelle'], array_keys($candidat), 'Deux clés, et pas une de plus.');
        self::assertSame('N. Romarin, 1980', $candidat['libelle']);

        // La référence est **opaque** : ni l'identifiant de la personne, ni un entier
        // qu'on pourrait incrémenter.
        self::assertIsString($candidat['ref']);
        self::assertNotSame((string) ArbreFamilial::EGO, $candidat['ref']);
        self::assertSame(0, preg_match('/^\d+$/', $candidat['ref']), 'Une référence numérique serait un identifiant déguisé.');
        // PAS de `assertStringNotContainsString(EGO, ...)` ici, et c'est délibéré.
        // La référence est `'c'.bin2hex(random_bytes(8))`, soit 16 caractères hexadécimaux
        // tirés au sort. Exiger qu'ils ne contiennent nulle part la suite « 100 », c'est
        // exiger qu'un tirage aléatoire évite un motif : 14 fenêtres de trois caractères,
        // chacune égale au motif avec une chance sur 4096, donc environ une exécution sur
        // 300 qui échoue sans qu'aucun code n'ait changé. Un tel test ne distingue pas
        // l'identifiant recopié d'une coïncidence, et sa rougeur aléatoire apprend à
        // relancer la CI plutôt qu'à la lire. Ce qu'il fallait garantir est déjà garanti :
        // la référence n'EST pas l'identifiant (ligne au-dessus, `assertNotSame`) et n'est
        // pas un entier (`preg_match`), et le test suivant vérifie que deux tentatives
        // rendent deux références différentes, donc qu'elle ne projette pas l'identifiant.

        // Et rien du reste de la fiche : ni prénom entier, ni date exacte, ni lieu.
        $brut = $this->corpsBrut();
        self::assertStringNotContainsString('Natron', $brut, 'Le prénom entier ne sort pas — seulement son initiale.');
        self::assertStringNotContainsString('1980-07-27', $brut);
        self::assertStringNotContainsString('27.7.1980', $brut);
    }

    /**
     * Deux tentatives successives rendent **deux références différentes** : la référence
     * est aléatoire et liée à la tentative, pas une projection stable de l'identifiant.
     *
     * Sans cette propriété, `ref` serait un pseudonyme réutilisable — donc un identifiant
     * de personne vivante sous un autre nom, qu'on pourrait collectionner d'un lien à
     * l'autre pour reconnaître les mêmes fiches.
     */
    public function testLaReferenceEstAleatoireEtNonUneProjectionStable(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur, ArbreFamilial::EGO, 'Natron Romarin');

        $premiere = $this->referenceObtenue($jeton);
        $seconde = $this->referenceObtenue($jeton);

        self::assertNotSame($premiere, $seconde);
    }

    /**
     * **Le compte créé par confirmation de date est actif** — et pourtant la règle tient :
     * il voit la fiche parce qu'il *est* cette personne, pas parce qu'il a confirmé une
     * date. La preuve par le contraire est le test suivant.
     */
    public function testUnComptePreLieEstActifEtVoitSonPropreCercle(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur, ArbreFamilial::EGO, 'Natron Romarin');

        $reference = $this->referenceObtenue($jeton);

        $this->requete('POST', '/api/inscription/compte', [
            'jeton' => $jeton,
            'choix' => $reference,
            'email' => 'natron@example.net',
            'password' => 'un-mot-de-passe-de-douze-signes',
        ]);

        $charge = $this->charge();

        self::assertIsArray($charge['membre']);
        self::assertSame(MatchKind::PreLie->value, $charge['membre']['matchKind']);
        self::assertSame(ArbreFamilial::EGO, $charge['membre']['personId']);
        self::assertIsArray($charge['cercle']);
        self::assertSame('cercle', $charge['cercle']['regime']);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  LA PREUVE PAR LE CONTRAIRE : un compte **en attente** pré-lié ne lit rien.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * Ce compte-là a une ligne `mbr_member` qui pointe sur une personne vivante, née d'une
     * invitation **pré-liée** : tout ce qu'il faut pour qu'une implémentation naïve lui
     * ouvre la fiche « puisque c'est lui ». Rien ne sort : ni dans la charge de session, ni
     * dans l'écran d'attente, ni par le cercle.
     */
    public function testUnComptePreLieMaisEnAttenteNeLitRienDeLaPersonne(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [$invitation] = $this->creerInvitation($administrateur, ArbreFamilial::EGO, 'Natron Romarin');

        $candidat = $this->creerUtilisateur('cousin@example.net', statut: UserStatus::EnAttente);
        $membre = $this->creerMembre(
            $candidat,
            ArbreFamilial::EGO,
            'Natron Romarin',
            MemberStatus::EnAttente,
            MatchKind::PreLie,
        );
        $membre->setInvitation($invitation);
        $this->em()->flush();

        $this->connexion('cousin@example.net');
        $this->assertRienDeLaPersonne($this->corpsBrut(), 'la charge de session');

        $this->requete('GET', '/api/inscription/etat');
        $this->assertRienDeLaPersonne($this->corpsBrut(), 'l’écran d’attente');

        $etat = $this->charge();
        self::assertArrayNotHasKey('personId', $etat);
        self::assertArrayNotHasKey('personLabel', $etat);
    }

    /**
     * Rien de la personne pré-liée n'apparaît dans une réponse : ni son nom d'affichage, ni
     * ses prénoms, ni son identifiant, ni sa date.
     */
    private function assertRienDeLaPersonne(string $brut, string $ou = 'la réponse'): void
    {
        foreach (['Natron Romarin', 'Natron', 'Romarin', '1980-07-27', '27.7.1980'] as $interdit) {
            self::assertStringNotContainsStringIgnoringCase(
                $interdit,
                $brut,
                \sprintf('« %s » ne doit pas apparaître dans %s : confirmer une date n’est pas la lire.', $interdit, $ou),
            );
        }
    }

    private function referenceObtenue(string $jeton): string
    {
        $this->requete('POST', '/api/inscription/rattachement', [
            'jeton' => $jeton,
            'surname' => 'Romarin',
            'givenNames' => 'Natron',
            'birthDate' => '1980-07-27',
        ]);

        $corps = $this->charge();
        self::assertIsArray($corps['candidats']);
        self::assertNotEmpty($corps['candidats']);

        $reference = $corps['candidats'][0]['ref'];
        self::assertIsString($reference);

        return $reference;
    }

    private function corpsBrut(): string
    {
        $contenu = $this->client->getResponse()->getContent();
        self::assertIsString($contenu);

        return $contenu;
    }
}

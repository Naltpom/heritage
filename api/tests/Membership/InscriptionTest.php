<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Repository\NotificationRepository;
use App\Module\Core\Repository\UserRepository;
use App\Module\Membership\Enum\InvitationStatus;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Membership\Repository\InvitationRepository;
use App\Module\Membership\Repository\MemberRepository;
use App\Tests\Membership\Double\ArbreEnMemoire;

/**
 * Le parcours d'inscription de bout en bout — § 4 du contrat d'API.
 *
 * Deux chemins, et ils ne finissent pas au même endroit :
 *
 * - **pré-lien + date confirmée** ⇒ `match_kind = pre_lie`, compte **actif** d'emblée ;
 * - **recherche confirmée** ⇒ `match_kind = recherche_confirmee`, compte `en_attente` et
 *   notification à l'administrateur.
 *
 * C'est le seul cas d'activation automatique du produit (§ 4.5 du modèle), et le test le
 * vérifie dans les deux sens : l'un active, l'autre non.
 */
final class InscriptionTest extends CasDeTestMembership
{
    /** Le cas nominal : l'administrateur a pré-lié, le candidat confirme sa date. */
    public function testLeParcoursPreLieActiveLeCompteImmediatement(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [$invitation, $jeton] = $this->creerInvitation($administrateur, ArbreFamilial::EGO, 'Natron Romarin');

        $reference = $this->rattacher($jeton, 'Romarin', 'Natron', '1980-07-27');

        $this->requete('POST', '/api/inscription/compte', [
            'jeton' => $jeton,
            'choix' => $reference,
            'email' => 'natron@example.net',
            'password' => 'un-mot-de-passe-de-douze-signes',
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $charge = $this->charge();

        // « C'est exactement la charge de session du § 2.2 : une seule forme à implémenter
        // côté front, un seul magasin à alimenter. »
        self::assertSame(
            ['utilisateur', 'membre', 'cercle', 'voirComme', 'incarnation', 'demandesEnAttente', 'portrait'],
            array_keys($charge),
        );
        self::assertIsArray($charge['utilisateur']);
        self::assertSame('actif', $charge['utilisateur']['status']);
        self::assertIsArray($charge['membre']);
        self::assertSame(MatchKind::PreLie->value, $charge['membre']['matchKind']);
        self::assertSame('actif', $charge['membre']['status']);
        self::assertIsArray($charge['cercle']);
        self::assertSame('cercle', $charge['cercle']['regime']);

        // La session est ouverte : « le front n'a pas à enchaîner sur POST /api/connexion ».
        $this->requete('GET', '/api/session');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Le jeton est consommé — usage unique.
        $depot = self::getContainer()->get(InvitationRepository::class);
        \assert($depot instanceof InvitationRepository);
        $relue = $depot->find($invitation->getId());
        self::assertNotNull($relue);
        self::assertSame(InvitationStatus::Utilisee, $relue->getStatus());
        self::assertNotNull($relue->getUsedAt());

        // Et il ne vaut plus rien : la deuxième arrivée sur le lien rend 404, comme un
        // jeton inventé.
        $this->requete('POST', '/api/inscription/verifier', ['jeton' => $jeton]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Sans pré-lien, la recherche floue rend des candidats en **affichage minimal**, et le
     * compte créé reste `en_attente` avec notification à l'administrateur.
     */
    public function testLaRechercheConfirmeeLaisseLeCompteEnAttente(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur);

        $this->requete('POST', '/api/inscription/rattachement', [
            'jeton' => $jeton,
            'surname' => 'Romarin',
            'givenNames' => 'Natron',
            'birthDate' => '1980-07-27',
        ]);

        $corps = $this->charge();

        self::assertSame(['candidats', 'tentativesRestantes'], array_keys($corps));
        self::assertNull($corps['tentativesRestantes']);
        self::assertIsArray($corps['candidats']);
        self::assertCount(1, $corps['candidats']);

        $candidat = $corps['candidats'][0];
        self::assertSame(['ref', 'libelle'], array_keys($candidat));
        self::assertSame('N. Romarin, 1980', $candidat['libelle'], 'L’affichage minimal du § 6.1, au caractère près.');

        $this->requete('POST', '/api/inscription/compte', [
            'jeton' => $jeton,
            'choix' => $candidat['ref'],
            'email' => 'cousin@example.net',
            'password' => 'un-mot-de-passe-de-douze-signes',
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $charge = $this->charge();
        self::assertIsArray($charge['utilisateur']);
        self::assertSame('en_attente', $charge['utilisateur']['status']);
        self::assertIsArray($charge['membre']);
        self::assertSame(MatchKind::RechercheConfirmee->value, $charge['membre']['matchKind']);
        self::assertSame(['regime' => 'aucun'], $charge['cercle']);
        self::assertArrayNotHasKey('personId', $charge['membre']);

        // Le nom de la personne réclamée n'apparaît nulle part dans la charge.
        self::assertStringNotContainsStringIgnoringCase('Romarin', json_encode($charge, \JSON_THROW_ON_ERROR));

        $notifications = self::getContainer()->get(NotificationRepository::class);
        \assert($notifications instanceof NotificationRepository);
        self::assertCount(1, $notifications->findAll(), 'L’administrateur est notifié qu’un compte attend.');
    }

    /**
     * L'écran d'attente du § 4.4 : **deux nombres, pas une liste**, et aucune clé
     * `personId` ni `personLabel`.
     */
    public function testLEtatDAttenteDonneDuSensSansRienExposer(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur);

        $reference = $this->rattacher($jeton, 'Romarin', 'Natron', '1980-07-27');

        $this->requete('POST', '/api/inscription/compte', [
            'jeton' => $jeton,
            'choix' => $reference,
            'email' => 'cousin@example.net',
            'password' => 'un-mot-de-passe-de-douze-signes',
        ]);

        $this->requete('GET', '/api/inscription/etat');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $etat = $this->charge();

        // `portraitEnAttente` depuis l'ADR 0013 : un booléen, pas l'image — l'écran propose
        // « Ajouter votre photo » à qui a passé l'étape.
        // Plus de `cequejevois` : un compte en attente ne lit plus aucun chiffre de l'arbre
        // (ADR 0003, révision du 2026-10-01).
        self::assertSame(['status', 'depuis', 'matchKind', 'regime', 'portraitEnAttente'], array_keys($etat));
        self::assertFalse($etat['portraitEnAttente']);
        self::assertSame('en_attente', $etat['status']);
        self::assertSame('aucun', $etat['regime']);
    }

    /** Un mot de passe de moins de douze signes est refusé — § 6.1 du dossier. */
    public function testLeMotDePasseFaitDouzeSignesAuMinimum(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur, ArbreFamilial::EGO, 'Natron Romarin');

        $reference = $this->rattacher($jeton, 'Romarin', 'Natron', '1980-07-27');

        $this->requete('POST', '/api/inscription/compte', [
            'jeton' => $jeton,
            'choix' => $reference,
            'email' => 'natron@example.net',
            'password' => 'court',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $corps = $this->charge();
        self::assertIsArray($corps['erreur']);
        self::assertIsArray($corps['erreur']['champs']);
        self::assertArrayHasKey('password', $corps['erreur']['champs']);
    }

    /**
     * **Le `ref` est à usage unique.** Un second appel avec la même référence rend `404` —
     * et non un message qui confirmerait qu'elle avait servi.
     */
    public function testUneReferenceDeCandidatNeSertQuUneFois(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur);

        $reference = $this->rattacher($jeton, 'Romarin', 'Natron', '1980-07-27');

        $this->requete('POST', '/api/inscription/compte', [
            'jeton' => $jeton,
            'choix' => $reference,
            'email' => 'premier@example.net',
            'password' => 'un-mot-de-passe-de-douze-signes',
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $this->requete('POST', '/api/inscription/compte', [
            'jeton' => $jeton,
            'choix' => $reference,
            'email' => 'second@example.net',
            'password' => 'un-mot-de-passe-de-douze-signes',
        ]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Une date fausse en mode confirmation rend `candidats: []` — **et surtout pas** un
     * message du genre « la date attendue est en 1980 ».
     */
    public function testUneDateFausseEnModeConfirmationNeDitRien(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur, ArbreFamilial::EGO, 'Natron Romarin');

        $this->requete('POST', '/api/inscription/rattachement', [
            'jeton' => $jeton,
            'surname' => 'Romarin',
            'givenNames' => 'Natron',
            'birthDate' => '1980-07-28',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(['candidats' => [], 'tentativesRestantes' => null], $this->charge());
    }

    /**
     * La recherche floue plafonne à `invitation.candidats_max`, et **ne dit jamais combien
     * il y en avait**.
     *
     * L'arbre de test porte deux « Romarin » et un « Lointain » nés le 27 juillet 1980 ; on
     * abaisse le plafond à 1 pour vérifier que la troncature a lieu côté serveur et
     * qu'aucun décompte ne l'accompagne — « ni un décompte, ni un *et 7 autres* : ce serait
     * un oracle de cardinalité » (§ 4.2).
     */
    public function testLeNombreDeCandidatsEstPlafonneEtLeTotalJamaisRendu(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur);

        // Un homonyme : même nom, même prénom, même année que le cousin. Deux fiches
        // correspondent donc aux critères, et le plafond n'en laissera passer qu'une.
        ArbreEnMemoire::ajouterPersonne(51, 'Marbre', 'Romarin', true, 1966, '1966');

        $this->requete('POST', '/api/inscription/rattachement', [
            'jeton' => $jeton,
            'surname' => 'Romarin',
            'givenNames' => 'Marbre',
            'birthDate' => '1966-01-01',
        ]);

        $corps = $this->charge();
        self::assertIsArray($corps['candidats']);
        self::assertCount(2, $corps['candidats'], 'Sans plafond, les deux homonymes remontent.');

        $this->reglerEntier('invitation.candidats_max', 1, 1, 5);

        $this->requete('POST', '/api/inscription/rattachement', [
            'jeton' => $jeton,
            'surname' => 'Romarin',
            'givenNames' => 'Marbre',
            'birthDate' => '1966-01-01',
        ]);

        $corps = $this->charge();

        self::assertSame(['candidats', 'tentativesRestantes'], array_keys($corps));
        self::assertIsArray($corps['candidats']);
        self::assertCount(1, $corps['candidats'], 'Le plafond tronque côté serveur.');
        self::assertNull(
            $corps['tentativesRestantes'],
            'Et rien ne dit combien il y en avait : ce serait un oracle de cardinalité.',
        );
    }

    /** Le compte créé porte bien un `mbr_member`, et la personne est marquée réclamée. */
    public function testUnePersonneNePeutEtreReclameeQueParUnCompte(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $premier] = $this->creerInvitation($administrateur, ArbreFamilial::EGO, 'Natron Romarin');

        $reference = $this->rattacher($premier, 'Romarin', 'Natron', '1980-07-27');
        $this->requete('POST', '/api/inscription/compte', [
            'jeton' => $premier,
            'choix' => $reference,
            'email' => 'premier@example.net',
            'password' => 'un-mot-de-passe-de-douze-signes',
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        // Une seconde invitation vers la même personne, créée à la main : le service la
        // refuserait en 409, mais on veut vérifier le contrôle de l'étape 2.
        [, $second] = $this->creerInvitation($administrateur, ArbreFamilial::EGO, 'Natron Romarin');
        $reference = $this->rattacher($second, 'Romarin', 'Natron', '1980-07-27');

        $this->requete('POST', '/api/inscription/compte', [
            'jeton' => $second,
            'choix' => $reference,
            'email' => 'second@example.net',
            'password' => 'un-mot-de-passe-de-douze-signes',
        ]);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        $corps = $this->charge();
        self::assertIsArray($corps['erreur']);
        self::assertSame('personne_deja_rattachee', $corps['erreur']['code']);

        $membres = self::getContainer()->get(MemberRepository::class);
        \assert($membres instanceof MemberRepository);
        self::assertCount(1, $membres->findAll());

        $comptes = self::getContainer()->get(UserRepository::class);
        \assert($comptes instanceof UserRepository);
        self::assertNull($comptes->findOneBy(['email' => 'second@example.net']), 'Aucun compte orphelin ne doit rester.');
    }

    /** Un courriel déjà pris rend `409 courriel_deja_pris` — le seul aveu d'existence admis. */
    public function testUnCourrielDejaPrisEstRefuse(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $this->creerUtilisateur('deja@example.net', statut: UserStatus::Actif);

        [, $jeton] = $this->creerInvitation($administrateur, ArbreFamilial::EGO, 'Natron Romarin');
        $reference = $this->rattacher($jeton, 'Romarin', 'Natron', '1980-07-27');

        $this->requete('POST', '/api/inscription/compte', [
            'jeton' => $jeton,
            'choix' => $reference,
            'email' => 'deja@example.net',
            'password' => 'un-mot-de-passe-de-douze-signes',
        ]);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        $corps = $this->charge();
        self::assertIsArray($corps['erreur']);
        self::assertSame('courriel_deja_pris', $corps['erreur']['code']);
    }

    /** Un compte `en_attente` activé par un administrateur obtient un cercle (§ 12.3). */
    public function testLActivationParUnAdministrateurDonneUnCercle(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur);

        $reference = $this->rattacher($jeton, 'Romarin', 'Natron', '1980-07-27');
        $this->requete('POST', '/api/inscription/compte', [
            'jeton' => $jeton,
            'choix' => $reference,
            'email' => 'cousin@example.net',
            'password' => 'un-mot-de-passe-de-douze-signes',
        ]);
        $charge = $this->charge();
        self::assertIsArray($charge['membre']);
        $memberId = $charge['membre']['id'];

        $this->connexion('admin@example.net');
        $this->requete('POST', \sprintf('/api/administration/membres/%d/activer', $memberId));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $membres = self::getContainer()->get(MemberRepository::class);
        \assert($membres instanceof MemberRepository);
        $membre = $membres->find($memberId);
        self::assertNotNull($membre);
        self::assertSame(MemberStatus::Actif, $membre->getStatus());

        $comptes = self::getContainer()->get(UserRepository::class);
        \assert($comptes instanceof UserRepository);
        $compte = $comptes->findOneBy(['email' => 'cousin@example.net']);
        self::assertNotNull($compte);
        self::assertSame(
            UserStatus::Actif,
            $compte->getStatus(),
            'Les deux statuts passent actif dans la même transaction : c’est ce qui rend le régime lisible depuis Core seul.',
        );

        $this->connexion('cousin@example.net', 'un-mot-de-passe-de-douze-signes');
        $charge = $this->charge();
        self::assertIsArray($charge['cercle']);
        self::assertSame('cercle', $charge['cercle']['regime']);
    }

    /** Rend la `ref` du premier candidat, ou fait échouer le test si la recherche n'a rien rendu. */
    /**
     * Un lien plafonné à deux : deux comptes y naissent, le troisième arrivant trouve un
     * `404` comme sur un jeton inventé, et le lien passe `utilisee`.
     */
    public function testUnLienPlafonneServitJusquAuPlafondPuisMeurt(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [$invitation, $jeton] = $this->creerInvitation($administrateur, maxUses: 2);

        foreach ([['Romarin', 'Natron', '1980-07-27', 'natron@example.net'], ['Thym', 'Spinel', '1982-03-10', 'spinel@example.net']] as [$nom, $prenoms, $naissance, $courriel]) {
            $reference = $this->rattacher($jeton, $nom, $prenoms, $naissance);

            $this->requete('POST', '/api/inscription/compte', [
                'jeton' => $jeton,
                'choix' => $reference,
                'email' => $courriel,
                'password' => 'un-mot-de-passe-de-douze-signes',
            ]);
            self::assertSame(201, $this->client->getResponse()->getStatusCode());
            $this->client->getCookieJar()->clear();
        }

        $depot = self::getContainer()->get(InvitationRepository::class);
        \assert($depot instanceof InvitationRepository);
        $relue = $depot->find($invitation->getId());
        self::assertNotNull($relue);
        self::assertSame(2, $relue->getUseCount());
        self::assertSame(InvitationStatus::Utilisee, $relue->getStatus());
        self::assertSame('spinel@example.net', $relue->getUsedByEmail());

        $membres = self::getContainer()->get(MemberRepository::class);
        \assert($membres instanceof MemberRepository);
        $inscrits = $membres->parInvitations([(int) $invitation->getId()]);
        self::assertCount(2, $inscrits[(int) $invitation->getId()] ?? []);

        $this->requete('POST', '/api/inscription/verifier', ['jeton' => $jeton]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    private function rattacher(string $jeton, string $nom, string $prenoms, string $naissance): string
    {
        $this->requete('POST', '/api/inscription/rattachement', [
            'jeton' => $jeton,
            'surname' => $nom,
            'givenNames' => $prenoms,
            'birthDate' => $naissance,
        ]);

        $corps = $this->charge();
        self::assertIsArray($corps['candidats']);
        self::assertNotEmpty($corps['candidats'], 'La recherche devait rendre au moins un candidat.');

        return $corps['candidats'][0]['ref'];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Enum\UserStatus;
use App\Module\Proposal\Repository\ChangeRequestRepository;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  « PROPOSÉE PAR , LE 16 SEPTEMBRE 2026 » — l'auteur qui n'avait pas de nom.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * `prp_change_request.author_label` est un **instantané** : il se fige au dépôt et ne se
 * recalcule jamais, pour survivre au renommage et à la suppression du compte. Il était
 * alimenté par `core_user.display_name` **brut**.
 *
 * Or un compte né du parcours d'invitation a `display_name = ''` **par construction** :
 * il n'est jamais renseigné depuis `mbr_member.person_label` avant activation, ce serait
 * donner le nom de la personne réclamée à un compte qui n'y a pas encore droit (§ 2.2).
 * L'instantané partait donc vide — et un instantané vide n'est l'instantané de rien.
 *
 * **Mesuré sur la base de travail avant correctif : 6 demandes sur 6.** Pas un cas de
 * bord : 100 % des demandes existantes, parce que leur auteur est un compte d'invitation.
 * Conséquences visibles : « proposée par , le 16 septembre 2026 » dans la file
 * d'administration (`DemandeView.vue`, `DoublonsProbables.vue`, `PanneauFiche.vue`), et un
 * titre de notification commençant par une espace.
 *
 * ── Le repli est celui du § 2.2, pas un second ──────────────────────────────────
 *
 * Trois endroits du produit portaient déjà exactement cette forme —
 * `Core\Service\SessionPayloadBuilder::utilisateur()`,
 * `Core\Service\UserAdminService::normaliser()` et
 * `Media\Security\FabriqueDeLecteur::depuisUtilisateur()`. Les deux fabriques de lecteur
 * qui ne l'avaient pas la portent désormais. C'est ce que ce fichier verrouille : non pas
 * « il y a un repli », mais « c'est **le** repli du § 2.2 ».
 */
final class LibelleDAuteurTest extends CasDeTestDemandesHttp
{
    /** Le compte d'invitation : actif, rattaché, et **sans nom d'affichage**. */
    private const string SANS_NOM = 'opale@example.net';

    /**
     * Le cas du défaut : `display_name` vide au dépôt ⇒ `author_label` prend le courriel.
     *
     * L'assertion est **`assertSame($courriel)`** et non « pas vide » : un test qui se
     * contenterait de refuser la chaîne vide laisserait passer n'importe quelle valeur de
     * remplissage — « Anonyme », « ? », le `person_label` du membre — dont la dernière
     * serait précisément la fuite que le § 2.2 interdit.
     */
    public function testUnCompteSansNomDAffichageEstDesigneParSonCourriel(): void
    {
        $this->poserLaFicheDeReference();

        $utilisateur = $this->creerUtilisateur(self::SANS_NOM, nomAffiche: '');
        $this->creerMembreActif($utilisateur, 101, 'Personne réclamée');

        self::assertSame('', $utilisateur->getDisplayName(), 'Le compte d’invitation n’a pas de nom d’affichage.');

        $this->connexion(self::SANS_NOM);
        $id = $this->deposer([$this->opTexte('surname', 'Sureau', 'Bourrache')]);

        $demande = self::getContainer()->get(ChangeRequestRepository::class)->find($id);
        self::assertNotNull($demande);

        self::assertSame(
            self::SANS_NOM,
            $demande->getAuthorLabel(),
            'Le libellé d’auteur retombe sur le courriel — la MÊME règle qu’à la charge de session (§ 2.2).',
        );

        // Et il ne prend jamais le nom de la personne réclamée : le rattachement d'un
        // compte ne lui donne pas le droit de porter ce nom dans la file d'administration.
        self::assertNotSame('Personne réclamée', $demande->getAuthorLabel());
    }

    /**
     * La réponse HTTP porte le même libellé : le défaut se voyait à l'écran, c'est à
     * l'écran qu'on vérifie qu'il a disparu.
     */
    public function testLaChargeDeLaDemandeNePorteJamaisUnAuteurVide(): void
    {
        $this->poserLaFicheDeReference();

        $utilisateur = $this->creerUtilisateur(self::SANS_NOM, nomAffiche: '');
        $this->creerMembreActif($utilisateur, 101, 'Personne réclamée');

        $this->connexion(self::SANS_NOM);
        $id = $this->deposer([$this->opTexte('surname', 'Sureau', 'Bourrache')]);

        $this->requete('GET', '/api/demandes/'.$id);
        self::assertResponseIsSuccessful();

        $charge = $this->charge();

        self::assertSame(self::SANS_NOM, $charge['authorLabel']);
        self::assertNotSame('', trim((string) $charge['authorLabel']), 'C’est cette chaîne vide qui rendait « proposée par , le … ».');
    }

    /**
     * **Un compte QUI a un nom d'affichage garde le sien.** Le repli est un repli, pas un
     * remplacement : sans cette assertion, poser `author_label = email` partout passerait
     * le test précédent tout en effaçant les vrais noms de la file d'administration.
     */
    public function testUnCompteAvecUnNomDAffichageGardeLeSien(): void
    {
        $this->poserLaFicheDeReference();

        $this->creerContributeur('agate@example.net', 102, 'Agate Romarin');

        $this->connexion('agate@example.net');
        $id = $this->deposer([$this->opTexte('surname', 'Sureau', 'Bourrache')]);

        $demande = self::getContainer()->get(ChangeRequestRepository::class)->find($id);
        self::assertNotNull($demande);

        self::assertSame('Agate Romarin', $demande->getAuthorLabel(), 'Le courriel ne doit pas écraser un nom renseigné.');
    }

    /**
     * Le libellé est un **instantané** : renommer le compte après coup ne réécrit pas les
     * demandes déjà déposées.
     *
     * C'est ce qui justifie la reprise par migration plutôt qu'un repli à la lecture — si
     * la valeur se recalculait, les six lignes vides se seraient réparées toutes seules.
     */
    public function testLeLibelleEstUnInstantaneEtNeSuitPasUnRenommage(): void
    {
        $this->poserLaFicheDeReference();

        $utilisateur = $this->creerUtilisateur(self::SANS_NOM, nomAffiche: '');
        $this->creerMembreActif($utilisateur, 101, 'Personne réclamée');

        $this->connexion(self::SANS_NOM);
        $id = $this->deposer([$this->opTexte('surname', 'Sureau', 'Bourrache')]);

        $em = $this->em();
        $utilisateur->setDisplayName('Opale Romarin');
        $em->flush();
        $em->clear();

        $demande = self::getContainer()->get(ChangeRequestRepository::class)->find($id);
        self::assertNotNull($demande);

        self::assertSame(
            self::SANS_NOM,
            $demande->getAuthorLabel(),
            'L’instantané ne se recalcule pas — d’où la reprise des lignes déjà vides par migration.',
        );
    }

    /**
     * Un compte `en_attente` ne dépose pas du tout (`403 regime_en_attente`) : le libellé
     * n'entre donc jamais en jeu pour lui. On le vérifie pour que la correction du libellé
     * ne soit pas lue comme une autorisation.
     */
    public function testUnCompteEnAttenteNeDeposeToujoursPas(): void
    {
        $this->poserLaFicheDeReference();

        $this->creerUtilisateur('attente@example.net', nomAffiche: '', statut: UserStatus::EnAttente);

        $this->connexion('attente@example.net');
        $this->requete('POST', '/api/demandes', [
            'kind' => 'modification_personne',
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => self::PERSONNE,
            'ops' => [$this->opTexte('surname', 'Sureau', 'Bourrache')],
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('regime_en_attente', $this->erreur()['code']);
    }
}

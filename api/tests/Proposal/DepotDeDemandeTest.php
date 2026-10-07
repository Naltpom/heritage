<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Exception\AccesRefuse;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\ErreurDeDemande;
use App\Module\Proposal\Exception\Introuvable;
use App\Module\Proposal\Repository\ChangeRequestOpRepository;

/**
 * Déposer une demande — et surtout, **ne pas toucher la table cible**.
 */
final class DepotDeDemandeTest extends BaseDeDemandes
{
    /**
     * La règle centrale de l'ADR 0004 : « la table `tree_person` n'est **pas** touchée
     * tant que ce n'est pas validé ».
     *
     * Le compteur d'écritures du double est à zéro, et la valeur en base est inchangée :
     * les deux, parce qu'un service qui appellerait le port avec un tableau vide
     * passerait la seconde assertion et pas la première.
     */
    public function testUneDemandeDeposeeNeModifiePasLaTableCible(): void
    {
        $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::OPALE),
        );

        self::assertSame(0, $this->arbre->ecritures(), 'Aucune écriture ne doit partir vers Tree au dépôt.');
        self::assertSame('Sureau', $this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, 'surname'));
        self::assertSame(7, $this->arbre->version(SubjectKind::Personne, self::PERSONNE));
    }

    /** Un op par champ, avec ancienne et nouvelle valeur — pas un diff global. */
    public function testUnOpEstEcritParChamp(): void
    {
        $demande = $this->depot->deposer(
            $this->corpsDeModification([
                $this->opTexte('surname', 'Sureau', 'SUREAU', seq: 1),
                $this->opTexte('nickname', null, 'Mimi', seq: 2),
            ]),
            $this->lecteur(self::OPALE),
        );

        $ops = self::getContainer()->get(ChangeRequestOpRepository::class)->pourDemande($demande);

        self::assertCount(2, $ops);
        self::assertSame('surname', $ops[0]->getField());
        self::assertSame('Sureau', $ops[0]->getOldValue());
        self::assertSame('SUREAU', $ops[0]->getNewValue());
        self::assertSame(7, $ops[0]->getBaseVersion());
        self::assertSame('nickname', $ops[1]->getField());
    }

    /**
     * ADR 0016, révision du 2026-10-07 : la naissance d'un **vivant** que l'auteur ne voit
     * pas entière se propose **à l'aveugle**. L'`oldValue` du client est ignoré — ici, il
     * est même faux — et le serveur pose la valeur courante : pas de `409` qui rendrait la
     * date complète, et l'administrateur, qui voit tout, tranche.
     */
    public function testLaNaissanceDUnVivantQuOnNeVoitPasSeProposeALAveugle(): void
    {
        $this->poserUnVivantNeEn1987();

        $op = $this->opDate();
        $op['oldValue'] = $this->blocDate($this->annee(1950));

        $demande = $this->depot->deposer($this->corpsDeModification([$op]), $this->lecteur(self::OPALE));

        $ops = self::getContainer()->get(ChangeRequestOpRepository::class)->pourDemande($demande);
        self::assertCount(1, $ops);
        self::assertSame('1987', $ops[0]->getOldValue()['raw'] ?? null, 'La valeur d’avant est celle de la base, pas celle du client.');
        self::assertSame('entre', $ops[0]->getNewValue()['qualifier'] ?? null);
    }

    /**
     * À l'aveugle, proposer **la date déjà en base** passe aussi : la refuser
     * (« une modification qui ne modifie rien ») dirait à l'auteur qu'il a deviné juste.
     */
    public function testProposerALAveugleLaDateExacteNeLaRevelePas(): void
    {
        $this->poserUnVivantNeEn1987();

        $op = $this->opDate();
        unset($op['oldValue']);
        $op['newValue'] = $this->blocDate($this->annee(1987));

        $demande = $this->depot->deposer($this->corpsDeModification([$op]), $this->lecteur(self::OPALE));

        self::assertCount(1, self::getContainer()->get(ChangeRequestOpRepository::class)->pourDemande($demande));
    }

    private function poserUnVivantNeEn1987(): void
    {
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
    }

    /**
     * Une date incertaine est **un seul op, pas cinq**.
     *
     * Le `field` est le nom du bloc — `birth` —, jamais `birthMin`. Cinq ops
     * permettraient à l'administrateur d'en valider trois et de fabriquer une date
     * incohérente.
     */
    public function testUneDateIncertaineEstUnSeulOpDontLeChampEstLeNomDuBloc(): void
    {
        $demande = $this->depot->deposer(
            $this->corpsDeModification([$this->opDate()]),
            $this->lecteur(self::OPALE),
        );

        $ops = self::getContainer()->get(ChangeRequestOpRepository::class)->pourDemande($demande);

        self::assertCount(1, $ops, 'Le bloc date est solidaire : un op, pas cinq.');
        self::assertSame('birth', $ops[0]->getField());
        self::assertIsArray($ops[0]->getNewValue());
        self::assertSame(
            ['raw', 'qualifier', 'min', 'max', 'place'],
            array_keys($ops[0]->getNewValue()),
            'Cinq clés, toujours les cinq, dans cet ordre.',
        );
    }

    /** Les cinq sous-champs d'un bloc date ne sont pas des champs éditables. */
    public function testUnChampDeSousBlocDateEstRefuse(): void
    {
        $this->expectException(DonneesInvalides::class);

        $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('birthMin', '1987-01-01', '1901-01-01')]),
            $this->lecteur(self::OPALE),
        );
    }

    /**
     * La surface éditable est close : une colonne **dérivée** n'est pas proposable.
     *
     * Sans ce contrôle, un `field` venu d'une requête HTTP atteindrait un mutateur
     * arbitraire — une affectation de masse sur les colonnes dont dépendent le placement
     * et le cercle de visibilité.
     */
    public function testUneColonneDeriveeNEstPasProposable(): void
    {
        foreach (['branch', 'generation', 'displayName', 'searchKey', 'version', 'bestQuay'] as $champ) {
            try {
                $this->depot->deposer(
                    $this->corpsDeModification([$this->opTexte($champ, 'a', 'b')]),
                    $this->lecteur(self::OPALE),
                );
                self::fail(\sprintf('Le champ dérivé « %s » ne doit pas être proposable.', $champ));
            } catch (DonneesInvalides $erreur) {
                self::assertArrayHasKey('ops[0].field', (array) $erreur->champs());
            }
        }
    }

    /** `baseVersion` est obligatoire : sans lui, le travail de mardi est écrasé vendredi. */
    public function testLaVersionDeBaseEstObligatoire(): void
    {
        $op = $this->opTexte('surname', 'Sureau', 'SUREAU');
        unset($op['baseVersion']);

        try {
            $this->depot->deposer($this->corpsDeModification([$op]), $this->lecteur(self::OPALE));
            self::fail('Une demande sans baseVersion doit être refusée.');
        } catch (DonneesInvalides $erreur) {
            self::assertArrayHasKey('ops[0].baseVersion', (array) $erreur->champs());
        }
    }

    /** Une modification qui ne modifie rien n'a rien à faire dans une demande. */
    public function testUneValeurIdentiqueEstRefusee(): void
    {
        $this->expectException(DonneesInvalides::class);

        $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'Sureau')]),
            $this->lecteur(self::OPALE),
        );
    }

    /**
     * `oldValue` doit correspondre à la valeur courante **au moment du dépôt** : sinon
     * la fiche a changé pendant que le formulaire était ouvert, et déposer une demande
     * qu'on sait déjà fausse la condamne à la caducité trois jours plus tard.
     */
    public function testUneAncienneValeurDejaFausseEstRefuseeDesLeDepot(): void
    {
        $this->expectException(Conflit::class);

        $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Bourrache', 'BOURRACHE')]),
            $this->lecteur(self::OPALE),
        );
    }

    /** Une cible qui n'existe pas rend `404`, et **rien n'est écrit**. */
    public function testUneCibleInexistanteRendIntrouvable(): void
    {
        $this->arbre->supprimer(SubjectKind::Personne, self::PERSONNE);

        $this->expectException(Introuvable::class);

        $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::OPALE),
        );
    }

    /**
     * Une demande déposée par un administrateur **n'est pas auto-validée** : elle entre
     * dans la file comme les autres. L'auto-validation est un acte distinct (§ 6.1).
     */
    public function testUneDemandeDAdministrateurEntreDansLaFileCommeLesAutres(): void
    {
        $demande = $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::ADMIN, administrateur: true),
        );

        self::assertSame(RequestStatus::EnAttente, $demande->getStatus());
        self::assertFalse($demande->isAutoValidated());
        self::assertSame(0, $this->arbre->ecritures());
    }

    /** Un brouillon reste invisible de la file, et ne notifie personne. */
    public function testUnBrouillonNeNotifiePersonneEtResteHorsDeLaFile(): void
    {
        $corps = $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]);
        $corps['soumettre'] = false;

        $demande = $this->depot->deposer($corps, $this->lecteur(self::OPALE));

        self::assertSame(RequestStatus::Brouillon, $demande->getStatus());
        self::assertNull($demande->getSubmittedAt());
        self::assertSame([], $this->notificateur->de('demande_soumise'));
        self::assertSame(
            [],
            $this->calque->pour(SubjectKind::Personne, self::PERSONNE, $this->lecteur(self::OPALE)),
            'Un brouillon ne peuple pas le calque : seul `en_attente` le fait.',
        );
    }

    /** La soumission place la demande dans la file et prévient les administrateurs. */
    public function testLaSoumissionNotifieLesAdministrateurs(): void
    {
        $corps = $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]);
        $corps['soumettre'] = false;

        $demande = $this->depot->deposer($corps, $this->lecteur(self::OPALE));
        $this->depot->soumettre($demande, $this->lecteur(self::OPALE));

        self::assertSame(RequestStatus::EnAttente, $demande->getStatus());
        self::assertNotNull($demande->getSubmittedAt());
        self::assertCount(1, $this->notificateur->de('demande_soumise'));
    }

    /** Un compte en attente de rattachement ne contribue pas (§ 12). */
    public function testUnCompteEnAttenteNePeutPasDeposer(): void
    {
        $this->expectException(AccesRefuse::class);

        $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::OPALE, enAttente: true),
        );
    }

    /**
     * Sous la bascule « voir comme un membre », **toute écriture** rend
     * `409 vue_comme_membre_active` : une `prp_revision` porterait l'administrateur
     * pendant que l'écran montre quelqu'un d'autre.
     */
    public function testAucuneEcriturePendantLaBasculeVoirCommeUnMembre(): void
    {
        $emprunt = new \App\Module\Core\Contract\Lecteur(
            self::ADMIN,
            'Natron Romarin',
            administrateur: true,
            membreEmprunteUserId: self::TUF,
        );

        try {
            $this->depot->deposer(
                $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
                $emprunt,
            );
            self::fail('Aucune écriture ne doit passer sous la bascule.');
        } catch (Conflit $conflit) {
            self::assertSame('vue_comme_membre_active', $conflit->codeErreur());
        }
    }

    /**
     * **Les sept natures de `RequestKind` ont toutes un constructeur**, et le harnais le
     * voit parce qu'il lit le conteneur.
     *
     * Ce test remplace un `testLaSeuleNatureNonBrancheeLeDitClairement` qui affirmait
     * l'inverse pour `ajout_media` — et qui est resté **vert** des heures après que
     * `Media` s'était greffé. Il ne pouvait pas voir la greffe : le socle construisait
     * alors ses constructeurs à la main, et un tableau littéral ne sait rien des services
     * tagués. *Un test qui décrit l'absence d'un greffon doit passer par le conteneur,
     * sinon il décrit l'absence de sa propre liste.*
     *
     * Écrit dans ce sens-ci — « toutes sont branchées » — il ne peut plus mentir dans la
     * direction rassurante : une nature qui perdrait son constructeur le ferait tomber.
     */
    public function testChaqueNatureDeDemandeTrouveSonConstructeur(): void
    {
        foreach (RequestKind::cases() as $nature) {
            try {
                $this->depot->deposer(['kind' => $nature->value], $this->lecteur(self::OPALE));
            } catch (DonneesInvalides $erreur) {
                // Un corps vide est refusé — c'est attendu. Ce qu'on vérifie, c'est que
                // le refus porte sur le **contenu** et non sur la nature elle-même :
                // `champs: {kind: …}` signalerait qu'aucun constructeur ne l'a réclamée.
                self::assertArrayNotHasKey(
                    'kind',
                    (array) $erreur->champs(),
                    \sprintf('La nature « %s » n\'a pas de constructeur branché.', $nature->value),
                );
            } catch (ErreurDeDemande) {
                // Introuvable, conflit… : la nature a bien trouvé son constructeur, qui
                // est allé assez loin pour se plaindre d'autre chose.
            }
        }
    }

    /** @return array<string, mixed> */
    private function opDate(): array
    {
        return [
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
        ];
    }
}

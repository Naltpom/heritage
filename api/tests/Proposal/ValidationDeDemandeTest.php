<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Contract\ApplicateurDeDemande;
use App\Module\Proposal\Contract\ApplicateurDOperation;
use App\Module\Proposal\Contract\DemandeEnCours;
use App\Module\Proposal\Contract\EcritureDeRevision;
use App\Module\Proposal\Contract\OpEnAttente;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\ChangeRequestOp;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Enum\RevisionOperation;
use App\Module\Proposal\Enum\ValueKind;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Repository\RevisionRepository;
use App\Module\Proposal\Service\ResolutionPrevue;
use App\Module\Proposal\Service\ValidationDeDemande;

/**
 * Valider — les quatre issues, et **les deux `409` aux conseils opposés**.
 *
 * C'est la partie du module où une confusion coûte le plus cher : confondre les deux
 * conflits fait boucler l'interface sur une demande morte, ou abandonner une demande
 * parfaitement récupérable.
 */
final class ValidationDeDemandeTest extends BaseDeDemandes
{
    /** Le cas ordinaire : rien n'a bougé, la demande s'applique. */
    public function testUneValidationApplique(): void
    {
        $demande = $this->deposer('surname', 'Sureau', 'SUREAU');

        $resultat = $this->validation->valider($demande, $this->admin());

        self::assertSame('validee', $resultat->resultat);
        self::assertSame(RequestStatus::Validee, $demande->getStatus());
        self::assertSame('SUREAU', $this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, 'surname'));
        self::assertSame(8, $this->arbre->version(SubjectKind::Personne, self::PERSONNE));
        self::assertNotSame('', $resultat->empreinte);
        self::assertCount(1, $this->notificateur->de('demande_validee'));
    }

    /**
     * Une révision est écrite, avec les deux versions : c'est le couple qui permet de
     * rejouer l'historique d'une fiche sans ambiguïté et **d'y repérer un trou**.
     */
    public function testUneRevisionEstEcriteAvecLesDeuxVersions(): void
    {
        $demande = $this->deposer('surname', 'Sureau', 'SUREAU');
        $this->validation->valider($demande, $this->admin());

        $revisions = self::getContainer()->get(RevisionRepository::class)->pourDemande($demande);

        self::assertCount(1, $revisions);
        self::assertSame(7, $revisions[0]->getVersionBefore());
        self::assertSame(8, $revisions[0]->getVersionAfter());
        self::assertSame(self::PERSONNE, $revisions[0]->getSubjectId());
        self::assertSame('Natron Romarin', $revisions[0]->getActorLabel());
        // `request_id` n'est jamais `NULL` pour une écriture applicative : il n'existe
        // aucun chemin qui contourne le mécanisme.
        self::assertSame($demande->getId(), $revisions[0]->getRequest()?->getId());
        self::assertSame(
            ['surname' => ['avant' => 'Sureau', 'apres' => 'SUREAU']],
            $revisions[0]->getChanges(),
        );
    }

    /**
     * Le scénario **exact** du § 9.6 du modèle de données, joué de bout en bout.
     *
     * Lundi Opale propose le nom, lundi Tuf propose la naissance, mardi A est validée
     * (version 7 → 8), mercredi B est validée : `base_version = 7 ≠ 8`, le verrou a
     * parlé — mais la naissance vaut toujours `1987`, donc **rejeu automatique**.
     *
     * C'est le cas **fréquent**, celui des champs disjoints, et le traiter comme un
     * conflit condamnerait la seconde de deux demandes de la même semaine.
     */
    public function testDeuxDemandesSurDesChampsDisjointsLaSecondeEstRejouee(): void
    {
        $opale = $this->deposer('surname', 'Sureau', 'SUREAU', self::OPALE);
        $tuf = $this->deposerNaissance(self::TUF);

        $this->validation->valider($opale, $this->admin());
        self::assertSame(8, $this->arbre->version(SubjectKind::Personne, self::PERSONNE));

        // Mercredi : l'administrateur ouvre B et sait **avant de cliquer** ce qui va se
        // passer. Sans cela, la caducité se découvrirait au clic et deviendrait
        // indistinguable d'une panne.
        $plan = $this->analyse->analyser($tuf);
        self::assertSame(ResolutionPrevue::Rejeu, $plan->resolution);
        self::assertTrue($plan->uneVersionABouge());

        $resultat = $this->validation->valider($tuf, $this->admin());

        self::assertSame('rejouee', $resultat->resultat, 'Le rejeu est un succès, pas un conflit.');
        self::assertSame(RequestStatus::Validee, $tuf->getStatus());
        self::assertNotNull($resultat->rejeu);
        self::assertSame('version_modifiee', $resultat->rejeu['motif']);
        self::assertSame(7, $resultat->rejeu['versionALaSoumission']);
        self::assertSame(8, $resultat->rejeu['versionAppliquee']);
        self::assertSame(['birth'], $resultat->rejeu['champsVerifies']);
        self::assertSame(9, $this->arbre->version(SubjectKind::Personne, self::PERSONNE));

        // Le rejeu doit se voir : une écriture qu'aucun humain n'a réexaminée doit être
        // annoncée à l'auteur comme telle.
        $notifications = $this->notificateur->de('demande_validee');
        self::assertTrue($notifications[1]['rejouee']);
    }

    /**
     * **`demande_caduque`** — la valeur a bougé. C'est une `409` **qui écrit** : rien
     * n'est appliqué à l'arbre, mais la demande passe en `caduque` et l'auteur est
     * notifié avec le diff.
     */
    public function testUneValeurQuiABougeRendLaDemandeCaduque(): void
    {
        $tuf = $this->deposerNaissance(self::TUF);

        // Quelqu'un d'autre a corrigé la naissance entre-temps.
        $this->arbre->modifierAilleurs(
            SubjectKind::Personne,
            self::PERSONNE,
            'birth',
            new UncertainDate('ABT 1904', \App\Module\Core\Enum\DateQualifier::Environ, new \DateTimeImmutable('1904-01-01'), new \DateTimeImmutable('1904-12-31')),
        );

        self::assertSame(ResolutionPrevue::Caducite, $this->analyse->analyser($tuf)->resolution);

        $ecrituresAvant = $this->arbre->ecritures();

        try {
            $this->validation->valider($tuf, $this->admin());
            self::fail('Une valeur qui a bougé doit rendre la demande caduque.');
        } catch (Conflit $conflit) {
            self::assertSame('demande_caduque', $conflit->codeErreur());
            self::assertSame(409, $conflit->statutHttp());

            $details = (array) $conflit->details();
            // **Ne pas réessayer** : la demande est morte.
            self::assertFalse($details['rejouable']);
            self::assertSame('caduque', $details['statut']);
            self::assertSame('birth', $details['champs'][0]['field']);
            self::assertSame('vers 1904', $details['champs'][0]['valeurCourante']['texte']);
            self::assertSame('1987', $details['champs'][0]['valeurALaSoumission']['texte']);
        }

        // Rien n'a été appliqué à l'arbre…
        self::assertSame($ecrituresAvant, $this->arbre->ecritures());
        // …mais le statut, lui, est bien écrit et commité : sans cela, le front
        // rechargerait une demande toujours dans la file.
        self::assertSame(RequestStatus::Caduque, $this->recharger($tuf)->getStatus());

        // L'auteur est notifié **avec le diff**, pas d'un refus sec — et la notification
        // porte exactement les champs de l'enveloppe d'erreur.
        $caducites = $this->notificateur->de('demande_caduque');
        self::assertCount(1, $caducites);
        self::assertSame('birth', $caducites[0]['champs'][0]['field']);
        self::assertSame([], $this->notificateur->de('demande_refusee'), 'Une caducité n\'est pas un refus.');
    }

    /**
     * Le second conflit : **`verrou_concurrent`**, deux transactions concurrentes.
     * **Rien n'a été écrit, la demande reste `en_attente`, et réessayer est la bonne
     * action**.
     *
     * Le contraste avec le test précédent est tout l'objet de ces deux codes.
     */
    public function testUnVerrouConcurrentNEcritRienEtResteRejouable(): void
    {
        $demande = $this->deposer('surname', 'Sureau', 'SUREAU');

        $this->arbre->simulerVerrouConcurrent(true);

        try {
            $this->validation->valider($demande, $this->admin());
            self::fail('Une collision de transaction doit rendre verrou_concurrent.');
        } catch (Conflit $conflit) {
            self::assertSame('verrou_concurrent', $conflit->codeErreur());
            self::assertSame(409, $conflit->statutHttp());

            $details = (array) $conflit->details();
            // **Réessayer** : il ne s'est rien passé. C'est l'inverse exact du conseil
            // que donne `demande_caduque`.
            self::assertTrue($details['rejouable']);
            self::assertSame('en_attente', $details['statut']);
        }

        self::assertSame('Sureau', $this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, 'surname'));
        self::assertSame([], $this->notificateur->de('demande_caduque'), 'Un verrou concurrent ne notifie pas l\'auteur.');

        // Et réessayer marche vraiment — c'est ce que le conseil promet, et c'est la
        // moitié du test qui compte. Doctrine ferme l'`EntityManager` après un échec de
        // transaction : le réessai est une **requête neuve**, comme côté front.
        $id = (int) $demande->getId();
        $this->nouvelleRequete();
        $this->arbre->simulerVerrouConcurrent(false);

        $rechargee = $this->demandes->find($id);
        self::assertNotNull($rechargee);
        self::assertSame(RequestStatus::EnAttente, $rechargee->getStatus(), 'La demande est restée dans la file.');

        $resultat = $this->validation->valider($rechargee, $this->admin());
        self::assertSame('validee', $resultat->resultat);
    }

    /**
     * Les deux `409` donnent des conseils **opposés**, et c'est pour cela qu'elles ont
     * deux codes et un booléen `rejouable`.
     *
     * Ce test ne vérifie rien que les deux précédents ne vérifient déjà ; il existe
     * parce que la propriété qui compte est la **différence**, et qu'une différence ne
     * se lit pas dans deux tests séparés.
     */
    public function testLesDeuxConflitsNeSeConfondentPas(): void
    {
        $caduque = $this->conflitDeCaducite();
        $verrou = $this->conflitDeVerrou();

        self::assertNotSame($caduque->codeErreur(), $verrou->codeErreur());
        self::assertSame(409, $caduque->statutHttp());
        self::assertSame(409, $verrou->statutHttp());

        self::assertFalse(((array) $caduque->details())['rejouable']);
        self::assertTrue(((array) $verrou->details())['rejouable']);

        self::assertSame('caduque', ((array) $caduque->details())['statut']);
        self::assertSame('en_attente', ((array) $verrou->details())['statut']);
    }

    /** Une cible supprimée ou fusionnée est une **caducité**, pas un refus. */
    public function testUneCibleDisparueEstUneCaducite(): void
    {
        $demande = $this->deposer('surname', 'Sureau', 'SUREAU');
        $this->arbre->supprimer(SubjectKind::Personne, self::PERSONNE);

        try {
            $this->validation->valider($demande, $this->admin());
            self::fail('Une cible disparue doit rendre cible_disparue.');
        } catch (Conflit $conflit) {
            self::assertSame('cible_disparue', $conflit->codeErreur());

            $details = (array) $conflit->details();
            self::assertFalse($details['rejouable']);
            self::assertSame('caduque', $details['statut']);
            self::assertSame('personne', $details['cible']['kind']);
            self::assertSame(self::PERSONNE, $details['cible']['id']);
        }

        self::assertSame(RequestStatus::Caduque, $this->recharger($demande)->getStatus());
    }

    /**
     * **Tout ou rien.** Deux ops, dont un devenu caduc : **aucun** des deux n'est
     * appliqué.
     *
     * « Un rejeu qui écrirait trois ops puis découvrirait une caducité sur le quatrième
     * laisserait l'arbre dans un état que personne n'a approuvé — même annulé par un
     * `ROLLBACK`, ce serait un motif de doute permanent. »
     */
    public function testUneValidationEstToutOuRien(): void
    {
        $demande = $this->depot->deposer(
            $this->corpsDeModification([
                $this->opTexte('surname', 'Sureau', 'SUREAU', seq: 1),
                $this->opTexte('nickname', null, 'Mimi', seq: 2),
            ]),
            $this->lecteur(self::OPALE),
        );

        // Un seul des deux champs a bougé sous les pieds de l'auteur.
        $this->arbre->modifierAilleurs(SubjectKind::Personne, self::PERSONNE, 'nickname', 'Juju');

        try {
            $this->validation->valider($demande, $this->admin());
            self::fail('La demande doit être caduque.');
        } catch (Conflit $conflit) {
            self::assertSame('demande_caduque', $conflit->codeErreur());
        }

        // Le champ non contesté n'a **pas** été appliqué : c'est ce que « tout ou rien »
        // veut dire, et c'est le contrôle intégral avant la première écriture qui le
        // garantit — pas un `ROLLBACK`.
        self::assertSame('Sureau', $this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, 'surname'));
        self::assertSame(0, $this->arbre->ecritures());
        self::assertCount(
            0,
            self::getContainer()->get(RevisionRepository::class)->pourDemande($this->recharger($demande)),
        );
    }

    /**
     * Une date incertaine se valide **d'un bloc, jamais par morceaux**.
     *
     * Les cinq valeurs arrivent ensemble chez `Tree`, dans un `UncertainDate` — un seul
     * appel, un seul champ. Il n'existe aucun chemin par lequel trois clés sur cinq
     * seraient appliquées.
     */
    public function testUneDateIncertaineSAppliqueDUnSeulBloc(): void
    {
        $demande = $this->deposerNaissance(self::OPALE);

        $this->validation->valider($demande, $this->admin());

        $naissance = $this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, 'birth');

        self::assertInstanceOf(UncertainDate::class, $naissance);
        self::assertSame('BET 1901 AND 1909', $naissance->getRaw());
        self::assertSame('entre', $naissance->getQualifier()->value);
        self::assertSame('1901-01-01', $naissance->getMin()?->format('Y-m-d'));
        self::assertSame('1909-12-31', $naissance->getMax()?->format('Y-m-d'));
        self::assertSame('Saule, Ombrage, Sylvanie', $naissance->getPlace());
        self::assertSame('entre 1901 et 1909', $naissance->format(), 'Jamais un point là où la base a un intervalle.');

        // Un seul appel au port pour la ligne : les cinq valeurs n'ont jamais été
        // séparables.
        self::assertSame(1, $this->arbre->ecritures());
    }

    /**
     * Un administrateur peut valider **sa propre** demande : c'est autorisé — il n'y en
     * a qu'un, l'interdire bloquerait le produit — et **marqué**, pas passé sous
     * silence.
     */
    public function testUnAdministrateurValidantSaPropreDemandeEstMarqueAutoValide(): void
    {
        $demande = $this->deposer('surname', 'Sureau', 'SUREAU', self::ADMIN);

        $resultat = $this->validation->valider($demande, $this->admin());

        self::assertSame('validee', $resultat->resultat);
        self::assertTrue($demande->isAutoValidated(), 'L\'auto-validation doit être visible en colonne.');
        self::assertTrue($this->recharger($demande)->isAutoValidated());
    }

    /** Valider la demande d'un autre n'est pas une auto-validation. */
    public function testValiderLaDemandeDUnAutreNEstPasUneAutoValidation(): void
    {
        $demande = $this->deposer('surname', 'Sureau', 'SUREAU', self::OPALE);

        $this->validation->valider($demande, $this->admin());

        self::assertFalse($demande->isAutoValidated());
    }

    /** On ne valide pas deux fois : un double clic ne produit pas deux révisions. */
    public function testOnNeValidePasDeuxFois(): void
    {
        $demande = $this->deposer('surname', 'Sureau', 'SUREAU');
        $this->validation->valider($demande, $this->admin());

        try {
            $this->validation->valider($demande, $this->admin());
            self::fail('Une demande déjà validée ne se revalide pas.');
        } catch (Conflit $conflit) {
            self::assertSame('demande_deja_decidee', $conflit->codeErreur());
            self::assertSame('validee', ((array) $conflit->details())['statut']);
        }

        self::assertSame(1, $this->arbre->ecritures());
    }

    /**
     * **Tous** les applicateurs qui reclament une nature sont executes, pas seulement le
     * premier.
     *
     * Le validateur faisait `return` sur le premier trouve. Deux modules ayant
     * legitimement quelque chose a faire d'une meme nature de demande — joindre un media
     * et rattacher une citation, par exemple —, le second se serait tu **sans que rien ne
     * le signale** : meme mode de defaillance que le point de greffe jamais appele, un
     * etage plus haut.
     *
     * Ce test manquait : la correction avait ete faite sans lui, ce qui la rendait
     * revocable par inadvertance.
     */
    public function testTousLesApplicateursDUneNatureSontExecutes(): void
    {
        $demande = $this->deposer('surname', 'Sureau', 'SUREAU');

        $premier = $this->espionDeNature();
        $second = $this->espionDeNature();

        $validation = new ValidationDeDemande(
            $this->em,
            $this->analyse,
            $this->arbre,
            $this->normaliseur,
            $this->notificateur,
            [],
            [$premier, $second],
        );

        $validation->valider($this->recharger($demande), $this->admin());

        self::assertTrue($premier->appele, 'Le premier applicateur doit etre execute.');
        self::assertTrue($second->appele, 'Le second aussi — un `return` le ferait taire en silence.');
    }

    /** Un applicateur de demande qui note son appel et n'ecrit rien. */
    private function espionDeNature(): object
    {
        return new class implements ApplicateurDeDemande {
            public bool $appele = false;

            public function natures(): array
            {
                return [RequestKind::ModificationPersonne];
            }

            public function appliquer(DemandeEnCours $demande, array $ops, mixed $lecteur): array
            {
                $this->appele = true;

                return [];
            }
        };
    }

    private function conflitDeCaducite(): Conflit
    {
        $demande = $this->deposer('surname', 'Sureau', 'SUREAU');
        $this->arbre->modifierAilleurs(SubjectKind::Personne, self::PERSONNE, 'surname', 'Sureaut');

        try {
            $this->validation->valider($demande, $this->admin());
        } catch (Conflit $conflit) {
            return $conflit;
        }

        self::fail('Une caducité était attendue.');
    }

    private function conflitDeVerrou(): Conflit
    {
        $this->poserLaFicheDeReference();
        $demande = $this->deposer('nickname', null, 'Mimi');
        $this->arbre->simulerVerrouConcurrent(true);

        try {
            $this->validation->valider($demande, $this->admin());
        } catch (Conflit $conflit) {
            return $conflit;
        } finally {
            $this->arbre->simulerVerrouConcurrent(false);
        }

        self::fail('Un verrou concurrent était attendu.');
    }

    private function deposer(
        string $champ,
        ?string $ancienne,
        ?string $nouvelle,
        int $auteur = self::OPALE,
    ): ChangeRequest {
        return $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte($champ, $ancienne, $nouvelle)]),
            $this->lecteur($auteur, administrateur: self::ADMIN === $auteur),
        );
    }

    /**
     * Un op qui n'est pas un champ **est réellement appliqué**.
     *
     * ── Le défaut que ce test empêche de revenir ────────────────────────────────
     *
     * Le validateur **résolvait** l'applicateur d'un op non-`champ` — pour lever si
     * aucun n'était branché — puis passait à l'op suivante sans jamais l'appeler. Pour
     * un module greffé sur ce seul point, la validation **réussissait** : les ops étaient
     * marquées appliquées, la demande passait `validee`, l'auteur recevait la
     * notification lui annonçant que sa contribution était acceptée, **et rien n'était
     * écrit**. Une perte de données silencieuse assortie d'une confirmation de succès —
     * la pire forme de défaut de ce projet.
     *
     * Il a survécu parce que le harnais construisait ses applicateurs à la main et ne
     * pouvait donc pas constater qu'un greffon n'était pas appelé. Celui-ci le constate :
     * l'espion note son appel, et son écriture doit ressortir en `prp_revision`.
     */
    /**
     * Une demande « Ajout d'un document » porte un op dont le sujet est un **média**,
     * pas une fiche : l'analyse ne doit pas le confronter à l'arbre. Elle le faisait,
     * le port réel levait, et l'écran d'administration de la demande rendait une 500
     * (demande 4160, 03/10/2026) ; le double, lui, le déclarait « disparu ».
     */
    public function testUnOpDeMediaNEstPasConfronteALArbre(): void
    {
        $demande = $this->deposer('surname', 'Sureau', 'SUREAU');

        $op = new ChangeRequestOp($demande, 2, OpKind::AjoutMedia, SubjectKind::Media, ValueKind::Media);
        $op->viserLigneExistante(4656, null)->setValues(null, ['mediaId' => 4656, 'kind' => 'portrait', 'caption' => null, 'sha256' => 'abc']);
        $this->em->persist($op);
        $this->em->flush();

        $plan = $this->analyse->analyser($demande);

        self::assertSame([], $plan->disparues, 'Le média n’est pas une cible de l’arbre : il ne peut pas en « disparaître ».');
        self::assertCount(2, $plan->ops);
    }

    public function testUnOpQuiNEstPasUnChampEstReellementApplique(): void
    {
        $demande = $this->deposer('surname', 'Sureau', 'SUREAU');

        // Un op de nature `ajout_note`, posé en base : c'est le cas d'un module qui se
        // greffe sur l'op sans passer par la nature de la demande.
        $op = new ChangeRequestOp($demande, 2, OpKind::AjoutNote, SubjectKind::Personne, ValueKind::Texte);
        $op->viserLigneExistante(self::PERSONNE, 7)->setValues(null, 'Relevé de Saule, registre 1904.');
        $this->em->persist($op);
        $this->em->flush();

        $espion = new class implements ApplicateurDOperation {
            /** @var list<int> */
            public array $opsRecus = [];

            public function natures(): array
            {
                return [OpKind::AjoutNote];
            }

            public function appliquer(DemandeEnCours $demande, array $ops, mixed $lecteur): array
            {
                $this->opsRecus = array_map(static fn (OpEnAttente $op): int => $op->seq, $ops);

                return [new EcritureDeRevision(
                    SubjectKind::Personne,
                    17,
                    'Marne Dolomite Sureau',
                    RevisionOperation::Modification,
                    ['note' => ['avant' => null, 'apres' => 'Relevé de Saule, registre 1904.']],
                )];
            }
        };

        $validation = new ValidationDeDemande(
            $this->em,
            $this->analyse,
            $this->arbre,
            $this->normaliseur,
            $this->notificateur,
            [$espion],
            [],
        );

        $resultat = $validation->valider($this->recharger($demande), $this->admin());

        self::assertSame([2], $espion->opsRecus, 'L\'applicateur doit recevoir son op, pas être seulement résolu.');

        // Et ce qu'il a rendu doit ressortir en révision, avec l'empreinte du lot : sans
        // cela, l'appel aurait lieu mais sa trace se perdrait — un demi-défaut.
        $notes = array_values(array_filter(
            $resultat->revisions,
            static fn ($revision): bool => isset($revision->getChanges()['note']),
        ));

        self::assertCount(1, $notes);
        self::assertSame($resultat->empreinte, $notes[0]->getTreeFingerprintAfter());
    }

    private function deposerNaissance(int $auteur): ChangeRequest
    {
        return $this->depot->deposer(
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
            ]]),
            $this->lecteur($auteur),
        );
    }

    private function admin(): \App\Module\Core\Contract\Lecteur
    {
        return new \App\Module\Core\Contract\Lecteur(self::ADMIN, 'Natron Romarin', administrateur: true);
    }
}

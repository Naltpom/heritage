<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Service\AuditService;
use App\Module\Core\Service\UrlPublique;
use App\Module\Membership\Entity\Invitation;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\InvitationStatus;
use App\Module\Membership\Repository\ClaimAttemptRepository;
use App\Module\Membership\Repository\InvitationRepository;
use App\Module\Membership\Repository\MemberRepository;
use App\Module\Membership\Service\Port\PortArbre;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les invitations, côté administrateur — § 3 du contrat d'API.
 *
 * **Aucune inscription ouverte** : on n'entre que par un lien créé ici (§ 6.1 du dossier).
 *
 * ## Le jeton n'existe qu'une fois, dans la réponse de création
 *
 * {@see self::creer()} rend un {@see InvitationCreee} qui porte le lien en clair. C'est le
 * **seul** endroit où il existe : la base n'a que son empreinte, et `GET
 * /api/administration/invitations` ne porte **pas** de clé `lien` — pas `null`, absente
 * (§ 1.3). Un administrateur qui a perdu le lien révoque et recrée.
 *
 * ## `person_id` est résolu par `Tree` avant écriture
 *
 * Mesure 1 de la compensation de l'ADR 0002 : « aucun identifiant n'entre en base sans
 * être passé par son propriétaire ». Un `personId` inexistant rend `422` sur le champ,
 * jamais un enregistrement silencieux — la base ne pose plus de clé étrangère
 * inter-module, c'est ici que la cohérence se gagne.
 *
 * ## Un lien, plusieurs comptes
 *
 * Un lien porte un plafond (`maxUses`, `null` = illimité) et une échéance facultative :
 * c'est ce qui permet de poster un seul lien dans le groupe de la famille. Le pré-lien,
 * lui, n'a de sens qu'à usage unique — une fiche ne se réclame qu'une fois.
 *
 * ## La date d'expiration est calculée, le statut est maintenu
 *
 * `status` est une colonne **dénormalisée** par rapport à `use_count` / `revoked_at` /
 * `expires_at` (§ 4.1 du modèle) : elle rend la liste lisible d'un coup d'œil et s'indexe.
 * `expiree` est posée **paresseusement**, à la première lecture après échéance — pas par
 * une tâche de fond, qu'aucun ADR de ce projet n'autorise.
 */
final readonly class ServiceDInvitations
{
    public function __construct(
        private EntityManagerInterface $em,
        private InvitationRepository $invitations,
        private MemberRepository $membres,
        private ClaimAttemptRepository $tentatives,
        private PortArbre $arbre,
        private ReglagesDeMembership $reglages,
        private GenerateurDeJeton $jetons,
        private EnvoiDInvitation $courriels,
        private AuditService $audit,
        // Remplace le `RequestStack` qui servait à bâtir le lien : plus rien ici ne
        // dépend de ce que le client envoie.
        private UrlPublique $urlPublique,
    ) {
    }

    /** Borne haute de `expiresInDays` : au-delà d'un siècle, « jamais » est le mot juste. */
    private const int JOURS_MAX = 36_500;

    /**
     * @param array<string, mixed> $corps le corps du § 3.1
     *
     * @throws ValidationException `note` absente, `maxUses`/`expiresInDays` invalides,
     *                             `personId` inexistant ou hors usage unique, `email` manquant ou mal formé
     * @throws ConflictException   `personne_deja_rattachee`, `invitation_en_cours`
     */
    public function creer(array $corps, User $administrateur): InvitationCreee
    {
        $personId = $this->entierFacultatif($corps, 'personId');
        $email = $this->chaineFacultative($corps, 'email');
        $envoyer = true === ($corps['envoyerCourriel'] ?? false);
        $note = $this->chaineFacultative($corps, 'note');

        $champs = [];

        $maxUses = $this->entierAuMoinsUn($corps, 'maxUses', null, $champs);
        // Clé absente ⇒ la durée réglée ; `null` explicite ⇒ jamais. La nuance « absent
        // contre null » du § 1.3, dans le sens de la requête.
        $jours = $this->entierAuMoinsUn($corps, 'expiresInDays', $this->reglages->ttlDInvitationEnJours(), $champs);

        if (null !== $jours && $jours > self::JOURS_MAX) {
            $champs['expiresInDays'] = \sprintf('%d jours maximum ; null pour un lien sans échéance.', self::JOURS_MAX);
        }
        if (null === $note) {
            $champs['note'] = 'Une description est requise.';
        }
        if (null !== $personId && 1 !== $maxUses) {
            // Un pré-lien réutilisable enverrait plusieurs personnes confirmer la même fiche,
            // et `uniq_mbr_member_person` refuserait toutes sauf la première.
            $champs['personId'] = 'Une fiche désignée n\'a de sens que pour un lien à usage unique.';
        }

        if ($envoyer && null === $email) {
            $champs['email'] = 'Un destinataire est requis pour envoyer le courriel.';
        }
        if (null !== $email && false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $champs['email'] = 'Adresse de courriel invalide.';
        }
        if (null !== $note && mb_strlen($note) > 255) {
            $champs['note'] = '255 caractères maximum.';
        }

        $personne = null;

        if (null !== $personId && !isset($champs['personId'])) {
            $personne = $this->arbre->vue()->personne($personId);

            if (null === $personne) {
                $champs['personId'] = 'Cette personne n\'existe pas dans l\'arbre.';
            }
        }

        if ([] !== $champs) {
            throw new ValidationException($champs);
        }

        if (null !== $personId) {
            if (null !== $this->membres->pourPersonne($personId)) {
                throw new ConflictException('personne_deja_rattachee', 'Cette personne est déjà rattachée à un compte.');
            }

            $enCours = $this->invitations->activePourPersonne($personId);

            if (null !== $enCours) {
                throw new ConflictException('invitation_en_cours', 'Une invitation est déjà en cours pour cette personne.', ['invitationId' => $enCours->getId()]);
            }
        }

        $jeton = $this->jetons->jeton();
        $identifiant = $administrateur->getId();
        \assert(null !== $identifiant);

        $invitation = new Invitation(
            $this->jetons->empreinte($jeton),
            $this->jetons->prefixe($jeton),
            null !== $jours ? new \DateTimeImmutable(\sprintf('+%d days', $jours)) : null,
            $identifiant,
            $administrateur->getEmail(),
            $maxUses,
        );

        $invitation->setEmail($email)->setNote($note);

        if (null !== $personne) {
            $invitation->setPerson($personne->id, $personne->libelle);
        }

        $this->em->persist($invitation);
        $this->em->flush();

        $lien = $this->lien($jeton);
        $avertissements = [];
        $envoye = false;

        if ($envoyer && null !== $email) {
            $avertissements = $this->courriels->envoyer($email, $lien, $note);
            $envoye = [] === $avertissements;
        }

        // `tokenHash` figure dans la liste des champs masqués à l'écriture du journal
        // (§ 2.4) ; on ne le passe donc pas, et surtout pas le jeton en clair — un journal
        // d'audit qui contient le secret est un journal qu'il faut protéger comme le secret.
        $this->audit->enregistrer(
            AuditAction::InvitationCreee,
            Invitation::class,
            (string) $invitation->getId(),
            $invitation->getPersonLabel(),
            [
                'tokenPrefix' => $invitation->getTokenPrefix(),
                'personId' => $invitation->getPersonId(),
                'email' => $email,
                'maxUses' => $maxUses,
                'expiresAt' => $invitation->getExpiresAt()?->format(\DateTimeInterface::ATOM),
                'courrielEnvoye' => $envoye,
            ],
            $administrateur,
        );
        $this->em->flush();

        return new InvitationCreee($invitation, $lien, $envoye, $avertissements);
    }

    /**
     * @return array{elements: array<int, Invitation>, total: int, page: int, parPage: int}
     */
    public function lister(?string $statut, int $page, int $parPage, bool $avecRevoquees = true): array
    {
        $filtre = null !== $statut ? InvitationStatus::tryFrom($statut) : null;

        if (null !== $statut && null === $filtre) {
            throw new ValidationException(['status' => 'Statut inconnu.']);
        }

        $page = max(1, $page);
        // « Au-delà, la valeur est ramenée à 100 sans erreur » (§ 1.5) : un plafond qui
        // rabote sert le résultat ; un plafond qui lève apprend à l'appelant où il est.
        $parPage = min(100, max(1, $parPage));

        $resultat = $this->invitations->lister($filtre, $page, $parPage, $avecRevoquees);

        foreach ($resultat['elements'] as $invitation) {
            $this->marquerExpireeSiBesoin($invitation);
        }
        $this->em->flush();

        return [
            'elements' => $resultat['elements'],
            'total' => $resultat['total'],
            'page' => $page,
            'parPage' => $parPage,
        ];
    }

    /**
     * Les comptes nés de ces liens, en une requête, rangés par identifiant d'invitation.
     *
     * @param array<int, Invitation> $invitations
     *
     * @return array<int, list<Member>>
     */
    public function inscrits(array $invitations): array
    {
        $ids = [];

        foreach ($invitations as $invitation) {
            $id = $invitation->getId();

            if (null !== $id) {
                $ids[] = $id;
            }
        }

        return $this->membres->parInvitations($ids);
    }

    /**
     * Révoquer arrête les inscriptions à venir ; les comptes déjà créés restent. C'est
     * pourquoi un lien partiellement utilisé se révoque, et un lien épuisé non.
     *
     * @throws NotFoundException si l'invitation n'existe pas
     * @throws ConflictException `invitation_deja_consommee` — lien épuisé
     */
    public function revoquer(int $id, User $administrateur): Invitation
    {
        $invitation = $this->invitations->find($id);

        if (null === $invitation) {
            throw new NotFoundException();
        }

        if ($invitation->estEpuisee()) {
            // Il n'y a plus d'inscription à empêcher. « Révoquer après coup ne défait pas
            // le compte créé, et laisser croire le contraire serait pire que refuser » (§ 3.3).
            throw new ConflictException('invitation_deja_consommee', 'Ce lien a déjà servi autant de fois que prévu ; le révoquer ne supprimerait aucun compte.');
        }

        $identifiant = $administrateur->getId();
        \assert(null !== $identifiant);

        $invitation->revoquer($identifiant, $administrateur->getEmail());

        $this->audit->enregistrer(
            AuditAction::InvitationRevoquee,
            Invitation::class,
            (string) $invitation->getId(),
            $invitation->getPersonLabel(),
            ['tokenPrefix' => $invitation->getTokenPrefix()],
            $administrateur,
        );

        $this->em->flush();

        return $invitation;
    }

    /** Le décompte des tentatives de l'heure écoulée, pour la liste d'administration (§ 3.3). */
    public function tentativesDeLHeure(Invitation $invitation): int
    {
        return $this->tentatives->compterDepuis($invitation, new \DateTimeImmutable('-1 hour'));
    }

    /**
     * `expiree` posée à la lecture, jamais par une tâche de fond.
     *
     * Le statut est dénormalisé ; l'échéance, elle, est la vérité. Les deux se rejoignent
     * au moment où quelqu'un regarde — ce qui suffit, puisque c'est le seul moment où la
     * différence est observable.
     */
    public function marquerExpireeSiBesoin(Invitation $invitation): void
    {
        if (InvitationStatus::Active !== $invitation->getStatus()) {
            return;
        }

        if ($invitation->estEchue()) {
            $invitation->setStatus(InvitationStatus::Expiree);
        }
    }

    /**
     * Le lien affiché à l'administrateur, et envoyé par courriel.
     *
     * URL **absolue**, bâtie sur l'adresse publique **configurée** —
     * `HERITAGE_URL_PUBLIQUE`, lue par {@see UrlPublique}. Elle l'était auparavant sur
     * `Request::getSchemeAndHttpHost()`, et c'était une vulnérabilité : l'en-tête `Host`
     * vient du client, l'API lui fait confiance (`trusted_proxies`), et aucun
     * `trusted_hosts` ne le bornait. Une requête au `Host` falsifié faisait donc
     * fabriquer **par nous** un lien vers un domaine attaquant, expédié **dans un
     * courriel authentique, depuis notre adresse**, à une personne réelle de la famille —
     * qui n'avait aucun moyen de le distinguer d'un vrai.
     *
     * Configurer plutôt que valider : il n'y a plus rien qui vienne de l'extérieur, donc
     * plus rien à contrôler ni à oublier de contrôler au prochain lien.
     *
     * `/inscription/{jeton}` est une **route front**, pas une route API : la SPA la lit
     * et appelle ensuite `POST /api/inscription/verifier`.
     */
    private function lien(string $jeton): string
    {
        return $this->urlPublique->versLeFront('/inscription/'.$jeton);
    }

    /** @param array<string, mixed> $corps */
    private function entierFacultatif(array $corps, string $cle): ?int
    {
        $valeur = $corps[$cle] ?? null;

        if (null === $valeur || '' === $valeur) {
            return null;
        }

        if (!\is_int($valeur)) {
            throw new ValidationException([$cle => 'Un entier est attendu.']);
        }

        return $valeur;
    }

    /**
     * Un entier ≥ 1, `null` explicite, ou `$siAbsent` quand la clé manque. L'erreur est
     * ajoutée à `$champs` pour que la réponse `422` les donne toutes d'un coup.
     *
     * @param array<string, mixed>  $corps
     * @param array<string, string> $champs
     */
    private function entierAuMoinsUn(array $corps, string $cle, ?int $siAbsent, array &$champs): ?int
    {
        if (!\array_key_exists($cle, $corps)) {
            return $siAbsent;
        }

        $valeur = $corps[$cle];

        if (null === $valeur) {
            return null;
        }

        if (!\is_int($valeur) || $valeur < 1) {
            $champs[$cle] = 'Un entier supérieur ou égal à 1, ou null, est attendu.';

            return $siAbsent;
        }

        return $valeur;
    }

    /** @param array<string, mixed> $corps */
    private function chaineFacultative(array $corps, string $cle): ?string
    {
        $valeur = $corps[$cle] ?? null;

        if (null === $valeur) {
            return null;
        }

        if (!\is_string($valeur)) {
            throw new ValidationException([$cle => 'Une chaîne est attendue.']);
        }

        $valeur = trim($valeur);

        return '' !== $valeur ? $valeur : null;
    }
}

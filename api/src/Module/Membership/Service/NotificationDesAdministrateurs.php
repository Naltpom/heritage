<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Entity\Notification;
use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Repository\UserRepository;
use App\Module\Core\Service\NotificationService;
use App\Module\Membership\Entity\Member;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les notifications que `Membership` émet vers les administrateurs et vers le nouveau
 * membre — étape 3 du § 6.1 du dossier : « sinon `en_attente` + notification à
 * l'administrateur ».
 *
 * ## La porte de sortie n° 6
 *
 * « Aucun `title`/`body` ne nomme la personne » (§ 11.4, porte 6). Les notifications sont
 * lues par un administrateur, dont le cercle est complet — mais la liste des notifications
 * ne traverse pas le normaliseur de `Tree\Person`, et une notification est **persistée** :
 * son texte est figé au moment de l'écriture, alors que le cercle de son lecteur, lui,
 * peut changer. Un titre qui nommerait la personne resterait lisible après une révocation
 * de rôle. On nomme donc le **courriel du compte**, qui appartient au compte et non à
 * l'arbre.
 *
 * ## Le destinataire est résolu en PHP, pas en SQL
 *
 * `core_user.roles` est une colonne `JSONB` ; l'interroger demanderait un opérateur
 * PostgreSQL que la base de test — SQLite — ne connaît pas, et la requête devrait alors
 * être écrite deux fois. L'application compte « 10 à 30 comptes, pas 274 » (§ 9.3) : un
 * filtre en mémoire coûte moins qu'une divergence entre deux dialectes.
 */
final readonly class NotificationDesAdministrateurs
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $utilisateurs,
        private NotificationService $service,
    ) {
    }

    /**
     * Un compte est né d'une invitation sans pré-lien : son rattachement n'est pas certain,
     * il attend la validation d'un administrateur (§ 4.3 du contrat).
     */
    public function compteEnAttente(Member $membre): void
    {
        foreach ($this->administrateurs() as $administrateur) {
            $identifiant = $administrateur->getId();

            if (null === $identifiant) {
                continue;
            }

            $this->em->persist(new Notification(
                $identifiant,
                NotificationKind::CompteEnAttente,
                'Un compte attend votre validation',
                \sprintf(
                    '%s s\'est inscrit par invitation. Son rattachement à une personne de l\'arbre reste à confirmer.',
                    $membre->getUserEmail(),
                ),
                'administration_membres',
                ['memberId' => $membre->getId()],
            ));
        }

        $this->em->flush();
    }

    /**
     * Un compte est né **sans fiche** : le candidat ne s'est pas trouvé dans l'arbre et a
     * décrit sa famille (ADR 0013).
     *
     * La porte de sortie n° 6 vaut ici deux fois : le titre et le corps nomment le
     * **courriel** du compte, jamais l'identité qu'il a déclarée, et **jamais un mot de sa
     * description**. Une notification est persistée, lue hors de l'écran qui instruit le
     * rattachement, et survivrait à la purge de la déclaration — elle deviendrait alors la
     * seule copie de ce que la purge venait d'effacer.
     *
     * Même nature que {@see compteEnAttente()} : c'est la même file d'attente vue de
     * l'administrateur. Le lien, lui, mène à l'onglet « À rattacher », où la carte du compte
     * porte tout ce qu'il faut pour décider.
     */
    public function compteARattacher(Member $membre): void
    {
        foreach ($this->administrateurs() as $administrateur) {
            $identifiant = $administrateur->getId();

            if (null === $identifiant) {
                continue;
            }

            $this->em->persist(new Notification(
                $identifiant,
                NotificationKind::CompteEnAttente,
                'Une inscription attend son rattachement',
                \sprintf(
                    '%s s\'est inscrit sans se trouver dans l\'arbre : sa fiche reste à identifier.',
                    $membre->getUserEmail(),
                ),
                'administration.rattachements',
                // `null` et non `[]` : un tableau PHP vide s'encode `[]` en JSON, pas `{}`,
                // et le front traite déjà l'absence de paramètres (`linkParams ?? {}`).
                null,
            ));
        }

        $this->em->flush();
    }

    /**
     * Le compte vient d'être activé : « c'est la notification qui le lui apprend, et le
     * front recharge session et graphe à sa lecture » (§ 12.3).
     *
     * Rien ne pousse le changement au client — pas de Redis, pas de file (§ 3 du dossier).
     * Sans cette notification, le nouveau membre resterait devant l'écran d'attente
     * jusqu'à ce qu'il pense à recharger.
     *
     * ## Révision du 01/10/2026
     *
     * Depuis qu'un compte en attente ne voit **plus rien** de l'arbre (ADR 0003 révisée),
     * cette notification est la seule chose qui lui annonce que l'attente est finie. Trois
     * corrections, donc :
     *
     * - **Par {@see NotificationService}**, et non par un `persist()` direct : c'est lui
     *   qui programme l'envoi **sur le téléphone**. Écrite à la main, la notification
     *   restait dans la cloche d'une application que la personne n'avait aucune raison
     *   de rouvrir. Le service ne lève jamais : un échec de dépôt ne défait pas
     *   l'activation, qui est la décision qui compte.
     * - **Sa propre nature**, `compte_active` : `compte_en_attente` s'affichait
     *   « Compte à valider » — le contraire de ce qu'elle annonce.
     * - **Un lien vers l'accueil** (`accueil`), et non `session`, qui n'est le nom
     *   d'aucune route du front : la notification n'avait pas de lien du tout.
     */
    public function compteActive(Member $membre): void
    {
        $this->service->notifier(
            $membre->getUserId(),
            NotificationKind::CompteActive,
            'Votre compte est activé',
            'Votre inscription a été validée : l’arbre de la famille vous est ouvert.',
            'accueil',
        );
    }

    /** @return list<\App\Module\Core\Entity\User> */
    private function administrateurs(): array
    {
        $administrateurs = [];

        foreach ($this->utilisateurs->findAll() as $utilisateur) {
            if (\in_array('ROLE_ADMIN', $utilisateur->getRoles(), true)) {
                $administrateurs[] = $utilisateur;
            }
        }

        return $administrateurs;
    }
}

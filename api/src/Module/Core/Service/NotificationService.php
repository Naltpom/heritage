<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Entity\Notification;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Repository\NotificationRepository;
use App\Module\Core\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Les notifications en base, lues par la cloche de l'interface (§ 8 du contrat d'API,
 * § 2.5 du modèle de données).
 *
 * C'est la pièce qui fait exister la promesse du produit : un membre propose une
 * correction, l'administrateur la valide ou la **refuse avec un message**, et ce message
 * doit **parvenir à l'auteur**. Sans notification, un refus motivé n'arrive nulle part et
 * le § 6.3 du dossier de mission est une intention sans effet.
 *
 * Pas de file d'attente, pas de Redis (§ 3 du dossier) : l'envoi est synchrone et **la
 * notification en base est la source de vérité**.
 *
 * Depuis le 30/09/2026, chaque notification déposée part aussi **en push** vers les
 * appareils abonnés du destinataire ({@see DiffusionPush}) — elle sonne sur le téléphone
 * même application fermée. Le push n'est qu'un réveil : la cloche reste la référence.
 * Il part **après la réponse**, en une salve pour toute la requête : un tirage qui
 * notifie vingt comptes ne fait pas attendre l'administrateur vingt fois un service push.
 *
 * ## `notifier()` ne lève jamais, et c'est un choix de conception
 *
 * Une notification est produite **après** le `commit` de l'écriture qui l'a causée. Si
 * elle levait, elle ferait échouer un appel dont le travail est déjà fait et validé :
 * l'administrateur verrait une erreur sur une validation qui a bel et bien eu lieu, et
 * il recommencerait. À l'inverse, une notification perdue est un dommage borné — la
 * décision reste au journal d'audit, et l'écran de l'auteur montre l'état réel.
 *
 * Elle est donc **au mieux** : en cas d'échec, elle journalise en `error` et rend `null`.
 * Cette propriété est ce qui permet à l'adaptateur de `Proposal` d'être une simple
 * délégation, sans `try/catch` à recopier dans chacune de ses quatre méthodes.
 *
 * ## Les titres et les corps se composent à l'écriture
 *
 * Jamais à la lecture (§ 16.2 du contrat). Une notification se relit des mois plus tard,
 * quand le cercle de visibilité du destinataire a pu changer ; une composition paresseuse
 * rouvrirait la question de la confidentialité à chaque affichage, et la première fois
 * qu'on l'oublierait, un membre lirait le nom d'une personne vivante hors de son cercle.
 * C'est **à l'appelant** de composer le texte en appliquant le cercle du destinataire —
 * ce service ne nomme personne de lui-même.
 */
final class NotificationService
{
    public const int PAR_PAGE_MAX = 100;

    public const int PAR_PAGE_DEFAUT = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationRepository $depot,
        private readonly UserRepository $utilisateurs,
        private readonly LoggerInterface $journal,
        private readonly DiffusionPush $push,
    ) {
    }

    /**
     * Dépose une notification pour un compte. **Ne lève jamais** — voir l'en-tête.
     *
     * @param array<string, mixed>|null $linkParams
     */
    public function notifier(
        int $destinataireUserId,
        NotificationKind $kind,
        string $titre,
        ?string $corps = null,
        ?string $linkRoute = null,
        ?array $linkParams = null,
    ): ?Notification {
        try {
            $notification = new Notification($destinataireUserId, $kind, $titre, $corps, $linkRoute, $linkParams);
            $this->em->persist($notification);
            $this->em->flush();
        } catch (\Throwable $echec) {
            $this->journal->error('Notification non remise.', [
                'destinataire' => $destinataireUserId,
                'kind' => $kind->value,
                'exception' => $echec,
            ]);

            return null;
        }

        // Le téléphone, APRÈS le dépôt réussi et HORS du `try` ci-dessus : seulement
        // programmé ici, envoyé en une salve une fois la réponse partie (`kernel.terminate`,
        // voir `DiffusionPush`). Envoyer maintenant ferait payer à la requête un délai
        // réseau **par destinataire** — et les appelants bouclent (tirages,
        // administrateurs).
        $this->push->programmer($notification);

        return $notification;
    }

    /**
     * La même chose, vers **tous les administrateurs actifs**.
     *
     * Elle existe pour que les autres modules n'aient pas à savoir qui est
     * administrateur : `Proposal` ne tient pas la liste des comptes, et une requête sur
     * `core_user` depuis un autre module rouvrirait la frontière de l'ADR 0002.
     *
     * `$sauf` écarte un compte : l'administrateur qui dépose lui-même une demande n'a
     * pas à être averti de ce qu'il vient de faire. Sans cela, chaque demande de
     * l'administrateur remplissait sa propre cloche (constaté le 30/09/2026 : des
     * dizaines de « Administration propose une modification »).
     *
     * @param array<string, mixed>|null $linkParams
     *
     * @return int le nombre de destinataires effectivement notifiés
     */
    public function notifierLesAdministrateurs(
        NotificationKind $kind,
        string $titre,
        ?string $corps = null,
        ?string $linkRoute = null,
        ?array $linkParams = null,
        ?int $sauf = null,
    ): int {
        $remises = 0;
        $destinataires = 0;

        foreach ($this->administrateurs() as $administrateur) {
            $id = $administrateur->getId();

            if (null === $id || $id === $sauf) {
                continue;
            }

            ++$destinataires;

            if (null !== $this->notifier($id, $kind, $titre, $corps, $linkRoute, $linkParams)) {
                ++$remises;
            }
        }

        // L'auteur seul administrateur : personne d'autre à prévenir, et ce n'est pas
        // une panne — il sait ce qu'il vient de déposer.
        if (0 === $remises && (0 < $destinataires || null === $sauf)) {
            // Une file d'attente dont personne n'est averti est indistinguable d'une file
            // vide, et c'est le genre de panne qu'on met des semaines à remarquer.
            $this->journal->warning('Aucun administrateur actif à notifier.', ['kind' => $kind->value]);
        }

        return $remises;
    }

    /**
     * Les administrateurs actifs.
     *
     * Filtrage en PHP et non en SQL : `roles` est un `JSONB` sur PostgreSQL et un `CLOB`
     * sur SQLite en test, et une requête portable dessus serait un `LIKE '%ROLE_ADMIN%'`
     * — qui trouverait aussi un rôle nommé `ROLE_ADMINISTRATIF`. Le produit compte une
     * dizaine de comptes (ADR 0004 : il n'y a qu'un administrateur) ; le coût est nul et
     * la lecture est exacte.
     *
     * @return list<User>
     */
    public function administrateurs(): array
    {
        $actifs = $this->utilisateurs->findBy(['status' => UserStatus::Actif]);

        return array_values(array_filter(
            $actifs,
            static fn (User $u): bool => \in_array('ROLE_ADMIN', $u->getRoles(), true),
        ));
    }

    /**
     * `GET /api/notifications` — liste paginée, de la plus récente à la plus ancienne.
     *
     * @return array{elements: list<array<string, mixed>>, total: int, page: int, parPage: int}
     */
    public function lister(User $destinataire, bool $nonLuesSeulement, int $page, int $parPage): array
    {
        $page = max(1, $page);
        $parPage = min(self::PAR_PAGE_MAX, max(1, $parPage));

        $qb = $this->depot->createQueryBuilder('n')
            ->andWhere('n.recipientUserId = :destinataire')
            ->setParameter('destinataire', $destinataire->getId());

        if ($nonLuesSeulement) {
            $qb->andWhere('n.readAt IS NULL');
        }

        $total = (int) (clone $qb)->select('COUNT(n.id)')->getQuery()->getSingleScalarResult();

        /** @var list<Notification> $lignes */
        $lignes = $qb
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setFirstResult(($page - 1) * $parPage)
            ->setMaxResults($parPage)
            ->getQuery()
            ->getResult();

        return [
            'elements' => array_map($this->normaliser(...), $lignes),
            'total' => $total,
            'page' => $page,
            'parPage' => $parPage,
        ];
    }

    public function compterNonLues(User $destinataire): int
    {
        return (int) $this->depot->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.recipientUserId = :destinataire')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('destinataire', $destinataire->getId())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * `POST /api/notifications/{id}/lue`.
     *
     * Une notification qui n'appartient pas au lecteur rend **`404` et non `403`** : son
     * existence même dirait qu'il se passe quelque chose sur une fiche qu'il ne voit
     * peut-être pas (§ 15.3, et § 11.4 porte n° 6 — « aucun `title`/`body` ne nomme la
     * personne »).
     *
     * @throws NotFoundException
     */
    public function marquerLue(User $destinataire, int $id): Notification
    {
        $notification = $this->depot->findOneBy(['id' => $id, 'recipientUserId' => $destinataire->getId()])
            ?? throw new NotFoundException('Cette notification n’existe pas.');

        $notification->marquerLue();
        $this->em->flush();

        return $notification;
    }

    /** `POST /api/notifications/lues` — rend le nombre de notifications marquées. */
    public function marquerToutesLues(User $destinataire): int
    {
        /** @var list<Notification> $nonLues */
        $nonLues = $this->depot->createQueryBuilder('n')
            ->andWhere('n.recipientUserId = :destinataire')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('destinataire', $destinataire->getId())
            ->getQuery()
            ->getResult();

        $instant = new \DateTimeImmutable();
        foreach ($nonLues as $notification) {
            $notification->marquerLue($instant);
        }
        $this->em->flush();

        return \count($nonLues);
    }

    /** @return array<string, mixed> */
    public function normaliser(Notification $notification): array
    {
        return [
            'id' => $notification->getId(),
            'kind' => $notification->getKind()->value,
            'title' => $notification->getTitle(),
            'body' => $notification->getBody(),
            'linkRoute' => $notification->getLinkRoute(),
            'linkParams' => $notification->getLinkParams(),
            'readAt' => $notification->getReadAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $notification->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}

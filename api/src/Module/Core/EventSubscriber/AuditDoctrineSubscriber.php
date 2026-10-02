<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

/**
 * Le **journal d'audit mécanique** : toute création, modification et suppression d'entité
 * laisse une ligne dans `core_audit_log`, sans qu'aucun service ait à y penser.
 *
 * C'est la méthode du dépôt de référence, et son intérêt tient en une phrase : un journal
 * alimenté à la main a des trous, et ses trous sont exactement les chemins d'écriture
 * qu'on a oubliés — c'est-à-dire ceux qu'on aurait eu le plus besoin de relire.
 *
 * Quatre règles, chacune payée par un mode de défaillance connu :
 *
 * 1. **`AuditLog` est inaltérable côté applicatif.** Le journal s'ignore lui-même en
 *    création (sinon récursion) et **refuse** d'être modifié ou supprimé : une tentative
 *    lève une `LogicException` plutôt que de passer en silence. Aucune route n'écrit ici
 *    autrement qu'en insertion ; cette garde est là pour que ça reste vrai après le
 *    prochain remaniement.
 * 2. **Champs sensibles masqués à l'écriture.** `password`, `totpSecret`, `tokenHash`
 *    valent `"***"` et leur valeur n'est **jamais** écrite — ni l'ancienne, ni la
 *    nouvelle. Masquer à la lecture laisserait le secret en base.
 * 3. **Champs techniques ignorés.** `updatedAt` et `lastLoginAt` seuls ne sont pas un
 *    changement : sans cette règle, chaque connexion laisserait une ligne `modification`
 *    vide à côté de sa ligne `connexion`, et le journal deviendrait illisible là où il
 *    sert le plus.
 * 4. **Hors requête HTTP, on ne journalise pas.** Un import GEDCOM de 274 personnes
 *    écrirait 551 lignes sans acteur ni IP. Les commandes consignent ce qui les concerne
 *    par `AuditService::enregistrer()` — une ligne `import_gedcom`, pas 551.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AuditDoctrineSubscriber
{
    /**
     * Voir règle 2. Noms de **propriétés Doctrine**, pas de colonnes (§ 1.2 du contrat).
     *
     * `p256dh` et `auth` (un abonnement push réattribué) et `privateKey` (la paire VAPID)
     * sont des secrets de chiffrement : écrits en clair au journal, ils permettraient de
     * lire ou de signer des notifications à la place du serveur.
     *
     * Les cinq qui suivent `privateKey` sont celles d'un compte inscrit **sans fiche** (`Membership\Member`,
     * ADR 0013) : la description de famille que le candidat a tapée, l'identité qu'il a
     * déclarée et la suggestion calculée par l'assistant. Elles parlent de personnes
     * **vivantes**, et elles sont **purgées** au rattachement comme au refus. Sans cette
     * liste, la purge elle-même écrirait l'ancienne valeur en clair dans le diff
     * `avant`/`apres` — le journal garderait pour toujours ce que la table vient d'effacer.
     * Même règle pour le message vocal (`voiceMessageName`) et, depuis le 2 octobre 2026
     * au soir, pour sa **transcription** (`voiceMessageTranscript`) : c'est la description,
     * dite au lieu d'écrite.
     *
     * `publicKey` et `credentialId` sont ceux d'une clé d'accès (`Core\Passkey`). Rien de
     * secret — la clé privée ne quitte jamais l'appareil —, mais du matériel de clé n'a
     * rien à faire dans un journal qu'on ne purge jamais. Aucune route ne les modifie :
     * s'ils apparaissaient un jour dans un diff, ce serait une écriture à regarder, pas
     * une valeur à relire.
     */
    private const array CHAMPS_SENSIBLES = [
        'password',
        'totpSecret',
        'tokenHash',
        'p256dh',
        'auth',
        'privateKey',
        'familyDescription',
        'declaredSurname',
        'declaredGivenNames',
        'declaredBirthDate',
        'attachmentSuggestion',
        'voiceMessageName',
        'voiceMessageTranscript',
        'publicKey',
        'credentialId',
    ];

    /**
     * Voir règle 3.
     *
     * `lastUsedAt` et `signCount` sont ceux d'une clé d'accès (`Core\Passkey`) : chaque
     * connexion par clé les avance. Sans eux ici, chaque connexion laisserait une ligne
     * `modification` à côté de sa ligne `connexion`.
     */
    private const array CHAMPS_TECHNIQUES = ['updatedAt', 'lastLoginAt', 'lastUsedAt', 'signCount'];

    /** @var list<AuditLog> */
    private array $enAttente = [];

    private bool $ecritLeJournal = false;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entite = $args->getObject();

        if ($entite instanceof AuditLog || $this->horsRequeteHttp()) {
            return;
        }

        $this->enAttente[] = $this->construire(AuditAction::Creation, $entite, null);
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entite = $args->getObject();

        if ($entite instanceof AuditLog) {
            throw new \LogicException('Le journal d\'audit est inaltérable : aucune ligne de core_audit_log ne se modifie.');
        }
        if ($this->horsRequeteHttp()) {
            return;
        }

        $diff = $this->diff($args->getEntityChangeSet());

        // Rien de significatif n'a changé — typiquement un `updatedAt` seul. On ne
        // journalise pas une modification qui n'en est pas une.
        if ([] === $diff) {
            return;
        }

        $this->enAttente[] = $this->construire(AuditAction::Modification, $entite, $diff);
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entite = $args->getObject();

        if ($entite instanceof AuditLog) {
            throw new \LogicException('Le journal d\'audit est inaltérable : aucune ligne de core_audit_log ne se supprime.');
        }
        if ($this->horsRequeteHttp()) {
            return;
        }

        $this->enAttente[] = $this->construire(AuditAction::Suppression, $entite, null);
    }

    /**
     * Les lignes sont écrites **après** le vidage qui les a produites : à l'intérieur,
     * l'unité de travail est en cours de calcul et un `persist` y serait ignoré ou
     * corromprait le changeset. Le drapeau `$ecritLeJournal` coupe la récursion — notre
     * propre `flush()` déclenche un second `postFlush`.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->ecritLeJournal || [] === $this->enAttente) {
            return;
        }

        $em = $args->getObjectManager();
        \assert($em instanceof EntityManagerInterface);

        $lignes = $this->enAttente;
        $this->enAttente = [];

        $this->ecritLeJournal = true;
        try {
            foreach ($lignes as $ligne) {
                $em->persist($ligne);
            }
            $em->flush();
        } finally {
            $this->ecritLeJournal = false;
        }
    }

    /**
     * Le diff du § 2.4 du modèle : `{"surname": {"avant": …, "apres": …}}`.
     *
     * Clés **françaises** `avant`/`apres` : ce ne sont pas des noms de colonnes, donc la
     * règle mécanique du § 1.2 du contrat les veut en français. Le dépôt de référence
     * écrit `from`/`to` ; ne pas le recopier.
     *
     * @param array<string, mixed> $changeSet
     *
     * @return array<string, mixed>
     */
    private function diff(array $changeSet): array
    {
        $diff = [];

        foreach ($changeSet as $champ => $changement) {
            if (\in_array($champ, self::CHAMPS_TECHNIQUES, true)) {
                continue;
            }
            if (\in_array($champ, self::CHAMPS_SENSIBLES, true)) {
                // Ni avant, ni après : le fait qu'il a changé, et c'est tout.
                $diff[$champ] = '***';
                continue;
            }
            // Une association de type collection n'est pas une paire [avant, après].
            if (!\is_array($changement) || 2 !== \count($changement)) {
                continue;
            }

            $diff[$champ] = [
                'avant' => $this->normaliser($changement[0]),
                'apres' => $this->normaliser($changement[1]),
            ];
        }

        return $diff;
    }

    /** @param array<string, mixed>|null $changes */
    private function construire(AuditAction $action, object $entite, ?array $changes): AuditLog
    {
        $acteur = $this->security->getUser();
        $requete = $this->requestStack->getCurrentRequest();
        // Sous une incarnation, l'acteur est le compte incarné ; l'administrateur qui tient
        // le clavier est écrit à côté, sur chaque ligne (ADR 0010).
        $jeton = $this->security->getToken();
        $incarnant = $jeton instanceof SwitchUserToken ? $jeton->getOriginalToken()->getUser() : null;

        return new AuditLog(
            $action,
            $this->typeDuSujet($entite),
            $this->identifiant($entite),
            $this->libelle($entite),
            $changes,
            $acteur instanceof User ? $acteur->getId() : null,
            $acteur?->getUserIdentifier(),
            $requete?->getClientIp(),
            $this->tronquer($requete?->headers->get('User-Agent')),
            $incarnant instanceof User ? $incarnant->getId() : null,
            $incarnant?->getUserIdentifier(),
        );
    }

    /**
     * `App\Module\Tree\Entity\Person` → `Tree\Person`, la forme attendue par
     * `subject_type` (§ 2.4 du modèle, § 10.3 du contrat). Le module est conservé : deux
     * modules peuvent légitimement avoir une entité de même nom court.
     */
    private function typeDuSujet(object $entite): string
    {
        $parties = explode('\\', $entite::class);
        $court = end($parties);
        $rang = array_search('Module', $parties, true);
        $module = \is_int($rang) && isset($parties[$rang + 1]) ? $parties[$rang + 1] : null;

        return null !== $module ? $module.'\\'.$court : $court;
    }

    private function identifiant(object $entite): ?string
    {
        if (!method_exists($entite, 'getId')) {
            return null;
        }

        $id = $entite->getId();

        return \is_scalar($id) ? (string) $id : null;
    }

    /**
     * « `subject_label` est un libellé de personne : il est rempli avec le `display_name`
     * **et jamais avec une ligne de dates** » (§ 10.3 du contrat, § 9.2 du modèle) — une
     * ligne de dates vaudrait la constante « vivant·e » et trahirait ce que la
     * sérialisation masque.
     */
    private function libelle(object $entite): ?string
    {
        if (!method_exists($entite, 'getDisplayName')) {
            return null;
        }

        $libelle = $entite->getDisplayName();

        return \is_string($libelle) && '' !== $libelle ? mb_substr($libelle, 0, 255) : null;
    }

    private function normaliser(mixed $valeur): mixed
    {
        return match (true) {
            $valeur instanceof \DateTimeInterface => $valeur->format(\DateTimeInterface::ATOM),
            $valeur instanceof \BackedEnum => $valeur->value,
            \is_scalar($valeur), null === $valeur, \is_array($valeur) => $valeur,
            default => get_debug_type($valeur),
        };
    }

    /** Voir règle 4. */
    private function horsRequeteHttp(): bool
    {
        return null === $this->requestStack->getCurrentRequest();
    }

    private function tronquer(?string $valeur): ?string
    {
        if (null === $valeur || '' === $valeur) {
            return null;
        }

        return mb_substr($valeur, 0, 255);
    }
}

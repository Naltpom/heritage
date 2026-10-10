<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service\Port;

use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Service\NotificationService;
use App\Module\Proposal\Entity\ChangeRequest;

/**
 * L'implémentation de {@see NotificateurDeDemandes} : elle dépose des
 * `core_notification`.
 *
 * ## Pourquoi cette classe est dans `Proposal` et non dans `Core`
 *
 * L'en-tête du port demande « une classe de `Core` qui implémente cette interface ».
 * C'est le seul point sur lequel il faut s'en écarter, et le motif est structurel : cette
 * classe nomme `Proposal\Entity\ChangeRequest` dans toutes ses signatures. Une classe de
 * `Core` qui la nommerait ferait référencer `Proposal` par le noyau, ce que le § 2.6 du
 * modèle interdit sans ambiguïté — « `Core` est le noyau : il est référencé, il ne
 * référence pas ». La dépendance va donc dans le bon sens : `Proposal` connaît
 * {@see NotificationService}, `Core` ignore jusqu'à l'existence des demandes.
 *
 * ## Ce qu'elle a le droit de ne pas faire
 *
 * **Elle n'a aucun `try/catch`, et ce n'est pas un oubli.** Le port exige qu'une
 * implémentation ne lève jamais : ces méthodes sont appelées **après** le `commit` de la
 * transaction de validation, et une notification qui échouerait ferait échouer un appel
 * dont le travail est déjà fait et validé. `NotificationService::notifier()` porte cette
 * garantie chez lui — il journalise en `error` et rend `null`. La répéter ici en quatre
 * exemplaires serait quatre occasions d'en oublier une.
 *
 * ## Ce qu'elle doit faire, et qui ne se devine pas
 *
 * **Les titres et les corps se composent ici, à l'écriture, jamais à la lecture**
 * (§ 16.2 du contrat d'API). Une notification se relit des mois plus tard, quand le
 * cercle du destinataire a pu changer ; une composition paresseuse rouvrirait la question
 * de la confidentialité à chaque affichage.
 *
 * D'où la règle sur les libellés : on nomme la cible avec `targetLabel`, **le libellé
 * dénormalisé que la demande porte déjà**. C'est la huitième porte de sortie de
 * l'ADR 0003, et elle est fermée ici par construction : le destinataire est soit l'auteur
 * de la demande — qui a saisi ce libellé lui-même —, soit un administrateur, qui voit
 * tout. Aucun autre compte ne reçoit ces notifications.
 */
final readonly class NotificateurParNotifications implements NotificateurDeDemandes
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function demandeSoumise(ChangeRequest $demande): void
    {
        // Vers les administrateurs, jamais l'auteur : il vient de cliquer — y compris
        // quand l'auteur est lui-même administrateur. `Proposal` ne tient pas la liste
        // des comptes — c'est `Core` qui sait qui est administrateur.
        // Un complément se dit comme tel (ADR 0017) : l'administrateur n'a pas à le valider
        // mais à le fusionner, et le titre l'envoie au bon geste avant même d'ouvrir la
        // demande. Le numéro de l'origine suffit — les destinataires voient tout.
        $origine = $demande->getComplementDe()?->getRequest()->getId();
        $titre = null === $origine
            ? \sprintf('%s propose une modification%s.', $demande->getAuthorLabel(), $this->sur($demande))
            : \sprintf('%s propose un complément à la demande n° %d.', $demande->getAuthorLabel(), $origine);

        $this->notifications->notifierLesAdministrateurs(
            NotificationKind::DemandeSoumise,
            $titre,
            $demande->getMessage(),
            'demande.detail',
            ['id' => $demande->getId()],
            $demande->getAuthorUserId(),
        );
    }

    public function demandeValidee(ChangeRequest $demande, bool $rejouee): void
    {
        if ($this->decideeParSonAuteur($demande)) {
            return;
        }

        // Le rejeu se dit à l'auteur : c'est une écriture qu'aucun humain n'a réexaminée
        // champ par champ, et elle doit se voir (§ 6.8 du contrat).
        $corps = $rejouee
            ? 'La fiche avait changé entre-temps, mais aucune des valeurs que vous corrigiez : votre demande a été appliquée automatiquement sur la version courante.'
            : null;

        $this->notifications->notifier(
            $demande->getAuthorUserId(),
            NotificationKind::DemandeValidee,
            \sprintf('Votre demande%s a été validée.', $this->sur($demande)),
            $corps,
            'demande.detail',
            ['id' => $demande->getId()],
        );
    }

    public function demandeRefusee(ChangeRequest $demande, string $message): void
    {
        if ($this->decideeParSonAuteur($demande)) {
            return;
        }

        $this->notifications->notifier(
            $demande->getAuthorUserId(),
            NotificationKind::DemandeRefusee,
            \sprintf('Votre demande%s a été refusée.', $this->sur($demande)),
            // **Le motif part tel quel.** Le tronquer ou le résumer viderait de son sens
            // la règle « le message est obligatoire au refus et parvient à l'auteur dans
            // sa notification » (§ 6.3 du dossier de mission).
            $message,
            'demande.detail',
            ['id' => $demande->getId()],
        );
    }

    public function demandeCaduque(ChangeRequest $demande, array $champsEnConflit): void
    {
        // `caduque` n'est pas `refusee` : personne n'a refusé, le monde a bougé. Et une
        // caducité sans le lien « refaire cette demande » est une contribution perdue —
        // « un membre dont la première contribution se perd n'en dépose pas de seconde ».
        $this->notifications->notifier(
            $demande->getAuthorUserId(),
            NotificationKind::DemandeCaduque,
            \sprintf('Votre demande%s est devenue caduque.', $this->sur($demande)),
            $this->raconterLesConflits($champsEnConflit),
            'demande.refaire',
            ['id' => $demande->getId()],
        );
    }

    public function demandeCaduqueParDependance(ChangeRequest $demande, array $dependances): void
    {
        $this->notifications->notifier(
            $demande->getAuthorUserId(),
            // **Le même `NotificationKind` que la caducité ordinaire, et c'est voulu** :
            // ce qui arrive à l'auteur est identique — sa demande est morte sans que
            // personne ne l'ait refusée —, seule la raison diffère. Un cas
            // d'énumération de plus coûterait une migration pour une phrase.
            NotificationKind::DemandeCaduque,
            \sprintf('Votre demande%s est devenue caduque.', $this->sur($demande)),
            $this->raconterLesDependances($dependances),
            'demande.refaire',
            ['id' => $demande->getId()],
        );
    }

    /**
     * « L'administration a complété votre demande n° 12 » — ou, dans l'autre sens, « Opale
     * Romarin a complété sa demande n° 12 » pour les administrateurs (ADR 0017).
     *
     * **Le numéro de la demande, jamais le nom de la personne.** Le titre est relu des mois
     * plus tard ; le numéro mène à la demande, où le nom se lit sous les règles du jour. Le
     * corps nomme les **champs** touchés, pas leurs valeurs — une naissance de vivant n'a
     * rien à faire dans une ligne de `core_notification`. Et il ne nomme pas le membre qui
     * a fourni un complément : pour l'auteur de l'origine, c'est l'administration qui a
     * complété sa demande, et c'est exactement ce qui s'est passé.
     */
    public function demandeCompletee(ChangeRequest $demande, bool $parLAdministration, array $champs, int $liensAjoutes): void
    {
        $corps = $this->raconterLaCompletion($champs, $liensAjoutes);

        if ($parLAdministration) {
            $this->notifications->notifier(
                $demande->getAuthorUserId(),
                NotificationKind::DemandeCompletee,
                \sprintf('L\'administration a complété votre demande n° %d.', $demande->getId() ?? 0),
                $corps,
                'demande.detail',
                ['id' => $demande->getId()],
            );

            return;
        }

        // Vers les administrateurs, sauf l'auteur : ce qu'ils avaient lu a changé, et la
        // validation se fera sur la version complétée.
        $this->notifications->notifierLesAdministrateurs(
            NotificationKind::DemandeCompletee,
            \sprintf('%s a complété sa demande n° %d.', $demande->getAuthorLabel(), $demande->getId() ?? 0),
            $corps,
            'demande.detail',
            ['id' => $demande->getId()],
            $demande->getAuthorUserId(),
        );
    }

    /**
     * « Votre complément (demande n° 21) a été fusionné dans la demande n° 12. »
     *
     * Le complément est clos en `refusee` — c'est le statut qui le sort de la file —, mais
     * cette notification n'est **pas** un refus, et elle ne passe pas par
     * {@see demandeRefusee()} : son travail est repris, pas écarté. Elle ne nomme pas la
     * personne : l'origine est le plus souvent la proposition d'un autre membre, et ce que
     * celui-ci a écrit n'a pas à ressortir ici.
     */
    public function complementFusionne(ChangeRequest $complement, ChangeRequest $origine): void
    {
        if ($this->decideeParSonAuteur($complement)) {
            return;
        }

        $this->notifications->notifier(
            $complement->getAuthorUserId(),
            NotificationKind::ComplementFusionne,
            \sprintf(
                'Votre complément (demande n° %d) a été fusionné dans la demande n° %d.',
                $complement->getId() ?? 0,
                $origine->getId() ?? 0,
            ),
            'L\'administration l\'a repris dans la proposition qu\'il complétait, qui sera validée avec lui. Vous n\'avez rien d\'autre à faire.',
            'demande.detail',
            ['id' => $complement->getId()],
        );
    }

    /**
     * « Ce qui a changé : Naissance, Décès ; 2 liens ajoutés. » — ou `null` si rien n'est à
     * dire, ce que l'appelant évite déjà : il ne notifie pas une complétion vide.
     *
     * @param list<string> $champs
     */
    private function raconterLaCompletion(array $champs, int $liensAjoutes): ?string
    {
        $morceaux = [];

        if ([] !== $champs) {
            $morceaux[] = implode(', ', $champs);
        }

        if ($liensAjoutes > 0) {
            $morceaux[] = 1 === $liensAjoutes ? '1 lien ajouté' : \sprintf('%d liens ajoutés', $liensAjoutes);
        }

        return [] === $morceaux ? null : 'Ce qui a changé : '.implode(' ; ', $morceaux).'.';
    }

    /**
     * L'administrateur qui tranche sa **propre** demande vient de cliquer : lui annoncer
     * sa décision serait du bruit (2026-10-07). La caducité, elle, part toujours — c'est
     * le monde qui a bougé, pas lui.
     */
    private function decideeParSonAuteur(ChangeRequest $demande): bool
    {
        return null !== $demande->getDecidedByUserId() && $demande->getDecidedByUserId() === $demande->getAuthorUserId();
    }

    /** « sur Marne Dolomite Sureau », ou rien si la demande ne nomme pas sa cible. */
    private function sur(ChangeRequest $demande): string
    {
        $libelle = $demande->getTargetLabel();

        return null !== $libelle && '' !== $libelle ? ' sur '.$libelle : '';
    }

    /**
     * @param list<array{field: string, propose: mixed, valeurALaSoumission: mixed, valeurCourante: mixed}> $champs
     */
    private function raconterLesConflits(array $champs): ?string
    {
        if ([] === $champs) {
            return null;
        }

        $phrases = [];
        foreach ($champs as $conflit) {
            $phrases[] = \sprintf(
                'Vous proposiez « %s » pour %s, mais la fiche porte désormais « %s ».',
                $this->lisible($conflit['propose']),
                $conflit['field'],
                $this->lisible($conflit['valeurCourante']),
            );
        }

        return implode(' ', $phrases).' Vous pouvez refaire cette demande à partir des valeurs actuelles.';
    }

    /**
     * « Vous la rattachiez à Girolle Chanterelle, proposée par la demande n° 12, qui
     * n'a pas abouti. ».
     *
     * Le libellé peut manquer — la fiche a pu être effacée avec sa demande —, et la
     * phrase se replie alors sur « une personne proposée » plutôt que sur un numéro de
     * ligne interne, qui n'apprendrait rien à l'auteur.
     *
     * @param list<array{draftPersonId: int, demandeId: int|null, libelle: string, etat: string}> $dependances
     */
    private function raconterLesDependances(array $dependances): ?string
    {
        if ([] === $dependances) {
            return null;
        }

        $phrases = [];

        foreach ($dependances as $dependance) {
            $qui = '' === $dependance['libelle'] ? 'une personne proposée' : $dependance['libelle'];
            $demandeId = $dependance['demandeId'];

            $phrases[] = null === $demandeId
                ? \sprintf('Vous la rattachiez à %s, qui n\'est plus proposée.', $qui)
                : \sprintf(
                    'Vous la rattachiez à %s, proposée par la demande n° %d, qui n\'a pas abouti.',
                    $qui,
                    $demandeId,
                );
        }

        return implode(' ', $phrases).' Vous pouvez refaire cette demande en désignant quelqu\'un d\'autre.';
    }

    private function lisible(mixed $valeur): string
    {
        return match (true) {
            null === $valeur => '(vide)',
            \is_bool($valeur) => $valeur ? 'oui' : 'non',
            \is_scalar($valeur) => (string) $valeur,
            default => json_encode($valeur, \JSON_UNESCAPED_UNICODE) ?: '(valeur composée)',
        };
    }
}

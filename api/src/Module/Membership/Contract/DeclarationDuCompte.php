<?php

declare(strict_types=1);

namespace App\Module\Membership\Contract;

/**
 * Ce qu'un compte « à rattacher » a déclaré à l'inscription — l'objet de transfert de
 * {@see ComptesARattacher::declaration()}.
 *
 * Un objet valeur et non l'entité `Member` : un contrat ne transporte ni entité ni dépôt
 * (ADR 0002). Le consommateur reçoit exactement ce qu'il lui faut pour instruire le
 * rattachement, et rien qui lui permettrait d'écrire dans `mbr_member`.
 *
 * **Des données de vivants, en clair.** Elles ne vivent que le temps de la décision, et
 * aucun appelant ne doit les recopier dans un journal (ADR 0013).
 */
final readonly class DeclarationDuCompte
{
    public function __construct(
        public int $memberId,
        public int $userId,
        public string $email,
        public string $surname,
        public string $givenNames,
        public \DateTimeImmutable $birthDate,
        /** Vide quand la personne n'a rien écrit et a laissé un message vocal à la place. */
        public string $description,
        /** La personne pré-liée par l'invitation utilisée, s'il y en avait une : un indice pour l'analyse. */
        public ?int $invitationPersonId,
        /** @var array<string, mixed>|null */
        public ?array $suggestion,
        public ?\DateTimeImmutable $suggestionAt,
        /**
         * La personne a-t-elle laissé un **message vocal** ? Seul le fait voyage : la voix
         * reste dans `Membership`, et seul un administrateur l'écoute.
         */
        public bool $aUnMessageVocal = false,
        /**
         * La **transcription** de ce message vocal, quand Gemini l'a rendue (addendum du
         * 2 octobre 2026, soir) : un texte de vivant, à traiter comme la description.
         * `null` sans vocal, avant la première tentative, ou après un échec.
         */
        public ?string $transcription = null,
        /** Le motif du dernier échec de transcription, s'il y en a eu un. */
        public ?string $transcriptionErreur = null,
        /** La dernière tentative de transcription, réussie ou non. */
        public ?\DateTimeImmutable $transcriptionLe = null,
        /**
         * La dernière **correction à la main** de la transcription par un administrateur
         * (addendum du 3 octobre 2026) ; `null` tant que le texte est celui du modèle.
         * L'analyse en tire que les noms propres ont été relus.
         */
        public ?\DateTimeImmutable $transcriptionCorrigeeLe = null,
    ) {
    }
}

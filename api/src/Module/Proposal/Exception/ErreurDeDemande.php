<?php

declare(strict_types=1);

namespace App\Module\Proposal\Exception;

use App\Module\Core\Exception\DomainException;

/**
 * La base des erreurs métier de `Proposal`.
 *
 * ── Pourquoi elle hérite de `Core` plutôt que de porter sa propre enveloppe ──────
 *
 * Elle a un temps composé elle-même l'enveloppe du § 15.1, avec un souscripteur
 * d'événements propre au module : `Core\EventSubscriber\ApiExceptionSubscriber`
 * n'existait pas, et sans lui les routes de `Proposal` auraient rendu du HTML sur
 * exactement les cas où le front a le plus besoin d'un JSON — le refus sans motif, la
 * demande caduque, le verrou concurrent.
 *
 * Il existe désormais, et c'est **le seul endroit qui traduit une erreur en réponse
 * `/api`**. Deux souscripteurs pour une enveloppe, ce serait deux définitions de la
 * forme d'erreur, dont la seconde finirait par diverger sur un détail que personne ne
 * relit — l'ordre des clés, un `null` devenu absent. Le souscripteur du module a donc
 * été supprimé, et la seule chose que cette classe garde, c'est **ce qu'elle a à dire**.
 *
 * ── Ce que le contrat impose et qui se joue ici ─────────────────────────────────
 *
 * **Le `code` est le contrat, pas le message.** C'est sur lui que le front branche ; il
 * ne change jamais, alors que le message peut être réécrit. Et il ne nomme **jamais une
 * personne** (§ 16.2) : un message d'erreur qui cite une personne vivante est une fuite,
 * il nomme un champ, un code, un identifiant de demande.
 *
 * `champs` est rempli **sur les 422 seulement**, `details` porte une structure propre au
 * code et décrite là où ce code apparaît. Les deux sont **présents à `null`** plutôt
 * qu'absents — seule exception assumée à la règle « absent contre `null` » du § 1.3,
 * parce qu'une enveloppe d'erreur n'est pas une charge de données et qu'un front qui
 * doit tester la présence de deux clés avant de les lire finira par oublier un test.
 */
abstract class ErreurDeDemande extends DomainException
{
    /**
     * @param array<string, string>|null $champs  sur les 422 seulement : `{"nomDeChamp": "message"}`
     * @param array<string, mixed>|null  $details structure propre au code, décrite là où le code apparaît
     */
    public function __construct(
        private readonly string $codeErreur,
        string $message,
        private readonly int $statut,
        private readonly ?array $champs = null,
        private readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }

    public function codeErreur(): string
    {
        return $this->codeErreur;
    }

    public function statutHttp(): int
    {
        return $this->statut;
    }

    /** @return array<string, string>|null */
    public function champs(): ?array
    {
        return $this->champs;
    }

    /** @return array<string, mixed>|null */
    public function details(): ?array
    {
        return $this->details;
    }
}

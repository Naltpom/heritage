<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use App\Module\Assistant\Entity\Conversation;
use App\Module\Assistant\Entity\Echange;

/**
 * Une conversation telle que son auteur la relit — **à travers son cercle du moment**.
 *
 * Ce qu'on a répondu hier ne change pas ; ce qu'on a le droit d'en voir, si. Les noms et
 * les dates des fiches citées sont donc relus à chaque lecture ({@see MentionsDeFiches}),
 * jamais servis depuis ce qui a été gardé : la règle de lecture de l'ADR 0008, § 3, qui
 * vaut pour une réponse gardée comme pour une question détectée.
 */
final readonly class SerialiseurDeConversation
{
    public function __construct(private MentionsDeFiches $mentions)
    {
    }

    /**
     * @return array{id: int|null, titre: string, createdAt: string, updatedAt: string, nombreDEchanges: int}
     */
    public function resume(Conversation $conversation, int $nombreDEchanges): array
    {
        return [
            'id' => $conversation->getId(),
            'titre' => $conversation->getTitre(),
            'createdAt' => $conversation->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $conversation->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'nombreDEchanges' => $nombreDEchanges,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Conversation $conversation): array
    {
        $echanges = $conversation->getEchanges();

        return [
            ...$this->resume($conversation, \count($echanges)),
            'echanges' => array_map($this->echange(...), $echanges),
        ];
    }

    /**
     * @return array{id: int|null, question: string, reponse: string, personnes: list<array<string, mixed>>, createdAt: string}
     */
    public function echange(Echange $echange): array
    {
        $relue = $this->mentions->nettoyer($echange->getReponse(), $echange->getPersonnesCitees());

        return [
            'id' => $echange->getId(),
            'question' => $echange->getQuestion(),
            'reponse' => $relue['texte'],
            'personnes' => $this->mentions->personnes($relue['personIds']),
            'createdAt' => $echange->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}

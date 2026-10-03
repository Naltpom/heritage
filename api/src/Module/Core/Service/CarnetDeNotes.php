<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Entity\NoteAdministrateur;
use App\Module\Core\Entity\User;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Repository\NoteAdministrateurRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le carnet de notes de l'administrateur — voir {@see NoteAdministrateur}.
 *
 * Chaque méthode prend le compte connecté : une note d'un autre compte est
 * **introuvable** (`404`), pas interdite (`403`). Dire « elle existe mais elle n'est pas
 * à vous » révélerait déjà qu'elle existe.
 */
final class CarnetDeNotes
{
    public function __construct(
        private readonly NoteAdministrateurRepository $notes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<NoteAdministrateur> */
    public function lister(User $auteur): array
    {
        return $this->notes->duCompte($this->idDe($auteur));
    }

    public function trouver(User $auteur, int $id): NoteAdministrateur
    {
        return $this->notes->duCompteParId($this->idDe($auteur), $id)
            ?? throw new NotFoundException('Note introuvable.');
    }

    /** @param array<mixed> $corps */
    public function creer(User $auteur, array $corps): NoteAdministrateur
    {
        [$titre, $texte, $epinglee] = $this->valider($corps);

        $note = new NoteAdministrateur($this->idDe($auteur), $titre, $texte, $epinglee);
        $this->em->persist($note);
        $this->em->flush();

        return $note;
    }

    /**
     * `PUT` **remplace** : un `texte` omis est vidé, un `epinglee` omis vaut `false`.
     *
     * @param array<mixed> $corps
     */
    public function modifier(User $auteur, int $id, array $corps): NoteAdministrateur
    {
        $note = $this->trouver($auteur, $id);
        [$titre, $texte, $epinglee] = $this->valider($corps);

        $note->reecrire($titre, $texte, $epinglee);
        $this->em->flush();

        return $note;
    }

    public function supprimer(User $auteur, int $id): void
    {
        $this->em->remove($this->trouver($auteur, $id));
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    public function normaliser(NoteAdministrateur $note): array
    {
        return [
            'id' => $note->getId(),
            'titre' => $note->getNoteTitle(),
            'texte' => $note->getNoteBody(),
            'epinglee' => $note->isPinned(),
            'createdAt' => $note->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $note->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @param array<mixed> $corps
     *
     * @return array{string, string, bool}
     */
    private function valider(array $corps): array
    {
        $champs = [];

        $titre = $corps['titre'] ?? null;
        $texte = $corps['texte'] ?? '';
        $epinglee = $corps['epinglee'] ?? false;

        if (!\is_string($titre) || '' === trim($titre)) {
            $champs['titre'] = 'Le titre est requis.';
        } elseif (mb_strlen(trim($titre)) > NoteAdministrateur::LONGUEUR_TITRE) {
            $champs['titre'] = \sprintf('%d caractères au plus.', NoteAdministrateur::LONGUEUR_TITRE);
        }

        if (!\is_string($texte)) {
            $champs['texte'] = 'Le texte doit être une chaîne.';
        } elseif (mb_strlen($texte) > NoteAdministrateur::LONGUEUR_TEXTE) {
            $champs['texte'] = \sprintf('%d caractères au plus.', NoteAdministrateur::LONGUEUR_TEXTE);
        }

        if (!\is_bool($epinglee)) {
            $champs['epinglee'] = 'Vrai ou faux.';
        }

        if ([] !== $champs) {
            throw new ValidationException($champs);
        }

        \assert(\is_string($titre) && \is_string($texte) && \is_bool($epinglee));

        return [trim($titre), $texte, $epinglee];
    }

    private function idDe(User $auteur): int
    {
        return $auteur->getId() ?? throw new \LogicException('Un compte connecté a un identifiant.');
    }
}

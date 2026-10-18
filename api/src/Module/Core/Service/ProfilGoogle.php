<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

/**
 * Ce qu'un ID token Google vérifié dit d'une personne : son identifiant stable (`sub`),
 * son courriel **vérifié par Google**, et ce qui pré-remplit une inscription.
 */
final readonly class ProfilGoogle
{
    public function __construct(
        public string $sub,
        public string $email,
        public ?string $prenom,
        public ?string $nom,
        public ?string $photo,
    ) {
    }

    /** @return array{sub: string, email: string, prenom: string|null, nom: string|null, photo: string|null} */
    public function enTableau(): array
    {
        return [
            'sub' => $this->sub,
            'email' => $this->email,
            'prenom' => $this->prenom,
            'nom' => $this->nom,
            'photo' => $this->photo,
        ];
    }
}

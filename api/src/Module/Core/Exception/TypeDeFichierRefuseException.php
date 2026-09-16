<?php

declare(strict_types=1);

namespace App\Module\Core\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * `415 type_de_fichier_refuse` (§ 15.2 du contrat d'API).
 *
 * **Le message ne dit pas quel type a été détecté** (§ 7.1 du contrat), et cette
 * propriété est à conserver telle quelle : renvoyer le type reniflé d'un fichier refusé,
 * c'est offrir un service d'identification de format gratuit et non journalisé à qui
 * téléverse n'importe quoi. Elle dit en revanche ce qui est **accepté** — refuser sans
 * dire quoi faire oblige l'utilisateur à deviner.
 *
 * Même provenance que {@see FichierTropVolumineuxException} : écrite par l'agent `Media`,
 * montée dans `Core` parce que `Gedcom` aura le même besoin et qu'un code d'erreur du
 * contrat ne se définit qu'une fois.
 */
final class TypeDeFichierRefuseException extends DomainException
{
    /** @param list<string> $mimesAutorises */
    public function __construct(private readonly array $mimesAutorises)
    {
        parent::__construct('Ce type de fichier n\'est pas accepté.');
    }

    public function statutHttp(): int
    {
        return Response::HTTP_UNSUPPORTED_MEDIA_TYPE;
    }

    public function codeErreur(): string
    {
        return 'type_de_fichier_refuse';
    }

    /** @return array{mimesAutorises: list<string>} */
    public function details(): array
    {
        return ['mimesAutorises' => $this->mimesAutorises];
    }
}

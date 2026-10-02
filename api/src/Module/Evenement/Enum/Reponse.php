<?php

declare(strict_types=1);

namespace App\Module\Evenement\Enum;

/**
 * Ce qu'un invité répond : il vient, ou il ne vient pas.
 *
 * **Deux valeurs, et pas de « peut-être »** — c'est la demande du commanditaire, et elle a
 * une raison : un « peut-être » est une réponse qui n'en est pas une, et l'organisateur qui
 * compte ses couverts le lit comme un silence. Ne pas répondre reste possible — la colonne
 * est `NULL` —, et c'est précisément ce que le suivi de l'organisateur sait montrer :
 * « vu, sans réponse ».
 */
enum Reponse: string
{
    case Present = 'present';
    case Absent = 'absent';

    public function libelle(): string
    {
        return match ($this) {
            self::Present => 'Présent',
            self::Absent => 'Absent',
        };
    }
}

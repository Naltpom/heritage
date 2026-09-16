<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaSubject;

/**
 * Les parties du `multipart/form-data` du § 7.1 du contrat, déjà typées.
 *
 * Le service de téléversement reçoit **un chemin**, pas un `UploadedFile` : c'est ce qui
 * permet aux tests de ce module — ceux qui comptent, ceux qui vérifient qu'une photo
 * ressort sans ses coordonnées GPS — de tourner sans pare-feu, sans requête HTTP et sans
 * conteneur. La traduction depuis HTTP est le travail du contrôleur, et elle tient en
 * une méthode.
 */
final readonly class RequeteDeTeleversement
{
    public function __construct(
        /** Chemin du fichier reçu. Il n'est jamais modifié : la purge écrit ailleurs. */
        public string $cheminTemporaire,
        /**
         * Le nom fourni au téléversement. Conservé en base comme **libellé**, jamais
         * comme chemin : c'est `storage_name` qui désigne le fichier, et lui seul.
         */
        public ?string $nomOrigine,
        public MediaKind $kind,
        public MediaSubject $subjectKind,
        /** Exclusif avec le couple `demandeId` + `brouillonRef`. */
        public ?int $subjectId = null,
        public ?int $demandeId = null,
        /** Référence provisoire d'une personne pas encore créée : `p1`, **sans dièse**. */
        public ?string $brouillonRef = null,
        public ?string $caption = null,
        public ?UncertainDate $taken = null,
    ) {
    }
}

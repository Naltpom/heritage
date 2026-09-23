<?php

declare(strict_types=1);

namespace App\Module\Question\Service;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Question\Entity\Question;
use App\Module\Question\Enum\NatureDeLacune;

/**
 * Un trou constaté dans l'arbre — **avant** qu'il devienne une question.
 *
 * C'est le produit de {@see DetecteurDeLacunes} et l'entrée de
 * {@see RedacteurDeQuestions}. La distinction entre une lacune et une question est toute
 * l'ADR 0008 (§ 4) : *le code trouve la lacune, le rédacteur la met en français*. Une
 * lacune n'a donc **aucun texte**, et c'est ce qui rend le repli possible — on peut
 * toujours en fabriquer un depuis {@see NatureDeLacune::gabarit()}.
 *
 * Elle n'a pas non plus de destinataire : la même lacune est posée à plusieurs personnes,
 * et c'est en la confrontant aux lignes déjà en base, **par signature**, qu'on décide à
 * qui la poser encore.
 */
final readonly class Lacune
{
    public function __construct(
        public NatureDeLacune $nature,
        public SubjectKind $sujetKind,
        public int $sujetId,
        public string $sujetLabel,
    ) {
    }

    /**
     * L'empreinte de la lacune, **calculée par l'entité** et non recopiée ici.
     *
     * Deux implémentations de cette formule finiraient par diverger, et la divergence
     * serait silencieuse : la détection ne retrouverait plus les lignes en base, et
     * chaque membre se verrait reposer tout ce qu'il avait écarté — sans la moindre
     * erreur, sans la moindre trace.
     */
    public function signature(): string
    {
        return Question::signatureDe($this->nature, $this->sujetKind, $this->sujetId);
    }

    /** La question telle que le repli l'écrit, sans aucun appel sortant. */
    public function texteParDefaut(): string
    {
        return \sprintf($this->nature->gabarit(), $this->sujetLabel);
    }
}

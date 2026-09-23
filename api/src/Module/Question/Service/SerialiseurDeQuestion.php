<?php

declare(strict_types=1);

namespace App\Module\Question\Service;

use App\Module\Question\Entity\Question;
use App\Module\Question\Entity\QuestionDemande;
use App\Module\Question\Enum\NatureDeLacune;
use App\Module\Question\Enum\RedacteurDeQuestion;

/**
 * La forme JSON d'une question.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  IL N'Y A AUCUN FILTRE DE CONFIDENTIALITÉ ICI, ET C'EST VOULU.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Ce sérialiseur reçoit une liste **déjà filtrée** par {@see LacunesVisibles}, point
 * unique du module. C'est l'idiome de `Almanach` : *« les normaliseurs ne contiennent
 * aucune logique de confidentialité — ils reçoivent un flux déjà filtré et le mettent en
 * forme »*. Un test de forme l'impose (`FiltrageDuCercleTest`).
 *
 * La nuance avec `Tree` mérite d'être comprise plutôt que subie : là-bas le filtre **est**
 * dans la sérialisation, parce qu'une fiche sort amputée de certains champs. Ici, rien
 * n'est amputé — **une question se sert entière ou pas du tout**, son texte contenant le
 * nom de la personne. Le filtre porte donc sur la liste, en amont, et pas sur les champs.
 *
 * ── Ce que la charge dit au lecteur, et pourquoi ────────────────────────────────
 *
 * `redacteur` sort **dans la charge**, et l'enveloppe porte `redigeesParModele`. Ce n'est
 * pas un ornement de transparence : l'ADR 0008 le décide en toutes lettres — *« un membre
 * qui découvre par hasard que ses données familiales sont passées chez un tiers le vit
 * autrement que celui à qui on l'a dit »*. Le front a donc de quoi le dire sans avoir à
 * deviner.
 *
 * `attendPhoto` existe pour que le front n'ait pas à connaître la liste des natures : une
 * seule d'entre elles appelle une image plutôt qu'une phrase, et c'est au serveur de le
 * dire. Un front qui testerait `nature === 'aucune_photo'` recopierait une règle métier
 * qu'il faudrait corriger à deux endroits le jour où une seconde nature s'y ajoute.
 */
final class SerialiseurDeQuestion
{
    /**
     * L'enveloppe de la page.
     *
     * @param list<Question> $questions déjà filtrées, déjà triées, déjà bornées
     *
     * @return array<string, mixed>
     */
    public function page(array $questions): array
    {
        $parModele = \count(array_filter(
            $questions,
            static fn (Question $q): bool => RedacteurDeQuestion::Gemini === $q->getRedacteur(),
        ));

        return [
            'questions' => array_map($this->question(...), $questions),
            'total' => \count($questions),
            // Le front affiche la mention une fois, en tête, plutôt que sous chaque
            // question : répétée vingt fois, elle cesse d'être lue.
            'redigeesParModele' => $parModele,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function question(Question $question): array
    {
        $nature = $question->getNature();

        return [
            'id' => $question->getId(),
            'texte' => $question->getTexte(),
            'nature' => $nature->value,
            'natureLibelle' => $nature->label(),
            'redacteur' => $question->getRedacteur()->value,
            'redacteurLibelle' => $question->getRedacteur()->label(),
            'attendPhoto' => NatureDeLacune::AucunePhoto === $nature,
            'sujet' => [
                'kind' => $question->getSujetKind()->value,
                'id' => $question->getSujetId(),
                'libelle' => $question->getSujetLabel(),
            ],
            'etat' => $question->getEtat()->value,
            'etatLibelle' => $question->getEtat()->label(),
            'reports' => $question->getReports(),
            // `null` tant que rien n'a été déposé. Le front en fait « votre réponse est
            // partie, demande n° 42 », qui est la moitié de la valeur d'une contribution :
            // savoir qu'elle est arrivée quelque part.
            'demandes' => array_map(
                static fn (QuestionDemande $lien): array => [
                    'id' => $lien->getDemandeId(),
                    'nature' => $lien->getNature(),
                ],
                $question->getDemandes()->toArray(),
            ),
        ];
    }
}

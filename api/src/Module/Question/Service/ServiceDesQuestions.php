<?php

declare(strict_types=1);

namespace App\Module\Question\Service;

use App\Module\Question\Contract\PortArbrePourQuestions;
use App\Module\Question\Contract\PortMediasPourQuestions;
use App\Module\Question\Entity\Question;
use App\Module\Question\Enum\RedacteurDeQuestion;
use App\Module\Question\Exception\DetectionConcurrente;
use App\Module\Question\Repository\QuestionRepository;
use App\Module\Question\Service\Port\MediasNonBranchesPourQuestions;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La page « Questions » d'un compte, de bout en bout (ADR 0008).
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  CETTE MÉTHODE ÉCRIT EN LISANT, ET C'EST VOULU.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Ouvrir la page rejoue la détection et crée les lignes manquantes. Ce n'est pas un effet
 * de bord oublié, c'est la conséquence directe de l'ADR 0008 § 3 : la détection ne peut
 * pas tourner en tâche de fond, parce que `CercleDeVisibiliteInterface` résout le lecteur
 * **depuis la session** et qu'il n'existe pas — et qu'il ne doit pas exister — d'API « le
 * cercle du compte n° 12 » qu'un balayage nocturne appellerait pour tous les comptes. En
 * fabriquer une aurait voulu dire réécrire la formule du cercle une seconde fois, et
 * *« une règle de confidentialité ne se trompe qu'une fois pour fuir »*.
 *
 * L'écriture est **idempotente** : `uniq_qst_question_lacune` fait que rejouer la
 * détection mille fois ne crée rien de plus. C'est ce qui rend l'effet de bord
 * inoffensif, et c'est la seule raison pour laquelle il est acceptable.
 *
 * ── LE JOUR OÙ CE SERA LENT : UN CACHE, PAS UN CRON ─────────────────────────────
 *
 * Lire 274 fiches par ouverture de page est dérisoire ; à dix mille, ce ne le sera plus.
 * **La sortie est écrite d'avance, et ce n'est pas un balayage de fond.**
 *
 * C'est le mécanisme que l'ADR 0005 emploie déjà pour le placement : **un cache indexé
 * sur l'empreinte de l'arbre**. L'écriture change l'empreinte d'elle-même, donc
 * l'invalidation ne demande aucun geste — ni compteur, ni table, ni purge. Ce qu'on met
 * en cache est le **produit de la détection**, qui ne dépend que de l'arbre ; le filtre
 * du cercle, lui, reste appliqué par lecteur, à chaque requête.
 *
 * **La règle du cercle reste donc unique, et c'est tout l'enjeu.** Un cron aurait exigé
 * une seconde implémentation de la formule (§ 3 de l'ADR 0008) ; un cache sur empreinte
 * n'en exige aucune. *Si vous vous surprenez à écrire une commande de console qui
 * parcourt les comptes, relisez ce paragraphe.*
 *
 * ── LES SEPT ÉTAPES, ET CE QUE CHACUNE EMPÊCHE ──────────────────────────────────
 *
 * | # | étape | sans elle |
 * |---|---|---|
 * | 1 | lire l'arbre et les médias | rien à détecter |
 * | 2 | détecter les lacunes | — |
 * | 3 | écarter les signatures déjà connues | on repose ce qui a été répondu |
 * | 4 | **filtrer les nouvelles par le cercle** | on crée une question nommant un vivant hors cercle |
 * | 5 | rédiger, puis écrire | — |
 * | 6 | ne servir que ce qui est **encore détecté** | une lacune comblée revient indéfiniment |
 * | 7 | **filtrer les servables par le cercle** | un cercle rétréci laisse fuir les questions d'hier |
 *
 * Les étapes 4 et 7 sont les deux passages obligés par {@see LacunesVisibles}, et elles
 * ne se remplacent pas : la première protège ce qu'on **crée**, la seconde ce qu'on
 * **sert**. Un cercle rétrécit — `cercle.generations` est un réglage —, et les lignes
 * déjà en base, elles, ne bougent pas.
 *
 * L'étape 3 se fait **avant** l'étape 4, délibérément : une question déjà en base pour
 * une personne devenue invisible ne doit pas être recréée, et l'étape 7 se charge de ne
 * pas la servir. Filtrer d'abord aurait fait croire la lacune « nouvelle » à chaque
 * passage.
 *
 * ── CE QUI N'EST PAS ICI ────────────────────────────────────────────────────────
 *
 * **Aucune logique de confidentialité** : tout passe par {@see LacunesVisibles}, point
 * unique du module. **Aucune règle de détection** : elles sont dans
 * {@see DetecteurDeLacunes}, qui est pur. **Aucun appel sortant** : la rédaction est
 * derrière {@see RedacteurDeQuestions}, et le repli seul est branché tant que Gemini ne
 * l'est pas.
 */
final class ServiceDesQuestions
{
    public function __construct(
        private readonly PortArbrePourQuestions $arbre,
        private readonly PortMediasPourQuestions $medias,
        private readonly DetecteurDeLacunes $detecteur,
        private readonly RedacteurDeQuestions $redacteur,
        private readonly LacunesVisibles $visibles,
        private readonly QuestionRepository $questions,
        private readonly ReglagesDesQuestions $reglages,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Les questions à servir à ce compte, **dans l'ordre d'affichage**, bornées.
     *
     * @return list<Question>
     *
     * @throws DetectionConcurrente si une autre requête a créé les mêmes lignes pendant
     *                              celle-ci — l'appelant rend `409 réessayez`
     */
    public function pour(int $destinataireUserId, \DateTimeImmutable $maintenant): array
    {
        $lacunes = $this->detecter($maintenant);

        $this->creerLesQuestionsManquantes($destinataireUserId, $lacunes);

        return $this->servir($destinataireUserId, $lacunes, $maintenant);
    }

    /**
     * Étapes 1 et 2.
     *
     * @return array<string, Lacune> indexé par signature — l'indexation sert deux fois :
     *                               à écarter les connues, et à reconnaître ce qui est
     *                               **encore** détecté au moment de servir
     */
    private function detecter(\DateTimeImmutable $maintenant): array
    {
        $lacunes = $this->detecteur->detecter(
            $this->arbre->fiches(),
            $this->medias->comptesParPersonne(),
            $this->photosDemandables(),
            $this->reglages->anneePlancher($maintenant),
        );

        $parSignature = [];

        foreach ($lacunes as $lacune) {
            $parSignature[$lacune->signature()] = $lacune;
        }

        return $parSignature;
    }

    /**
     * La nature `aucune_photo` est-elle exploitable ?
     *
     * **C'est le détecteur qui doit décider, pas le repli**, et voici pourquoi : un repli
     * répond honnêtement à ce qu'on lui demande, et « je ne connais aucun média » est une
     * réponse honnête. Prise au mot, elle ouvrirait la lacune `aucune_photo` sur **les
     * 274 fiches** — la page demanderait à toute la famille une photo de gens dont l'arbre
     * a déjà le portrait. C'est la forme d'erreur la plus coûteuse possible ici : massive,
     * fausse, et sur la page d'accueil de la contribution.
     *
     * On reconnaît donc le repli **par son type**, jamais par un tableau vide : un arbre
     * dont aucune fiche n'a de média rendrait le même tableau vide, et ce cas-là est une
     * vraie lacune sur 274 fiches, qu'il faut bien pouvoir signaler.
     */
    private function photosDemandables(): bool
    {
        return !$this->medias instanceof MediasNonBranchesPourQuestions;
    }

    /**
     * Étapes 3 à 5.
     *
     * @param array<string, Lacune> $lacunes
     */
    private function creerLesQuestionsManquantes(int $destinataireUserId, array $lacunes): void
    {
        $connues = $this->questions->connuesPour($destinataireUserId);

        // Étape 3. On charge **tous** les états, `inconnue` et `repondue` compris : c'est
        // précisément là qu'est la mémoire, et en omettre un reposerait ce à quoi il a
        // été répondu.
        $nouvelles = array_values(array_diff_key($lacunes, $connues));

        // Étape 4. La garde, avant toute écriture.
        $nouvelles = $this->visibles->filtrerLesLacunes($nouvelles);

        // Le libellé d'une fiche renommée suit, même si aucune question n'est créée : un
        // écran qui affiche deux orthographes pour la même personne fait douter des deux.
        // Le texte de la question, lui, ne bouge pas — elle reste telle qu'elle a été lue.
        foreach ($connues as $signature => $question) {
            if (isset($lacunes[$signature])) {
                $question->rafraichirLeLibelle($lacunes[$signature]->sujetLabel);
            }
        }

        if ([] === $nouvelles) {
            $this->enregistrer($destinataireUserId);

            return;
        }

        // Étape 5. Le contrat exige une entrée par lacune reçue, quelle que soit la panne
        // du tiers ; le `??` n'est donc pas un repli mais un filet contre une
        // implémentation qui enfreindrait son contrat — sans lui, une question naîtrait
        // sans texte, et `texte` est `NOT NULL`.
        $textes = $this->redacteur->rediger($nouvelles);

        foreach ($nouvelles as $lacune) {
            $texte = $textes[$lacune->signature()] ?? new TexteDeQuestion(
                $lacune->texteParDefaut(),
                RedacteurDeQuestion::Regles,
            );

            $this->em->persist(new Question(
                $destinataireUserId,
                $lacune->nature,
                $lacune->sujetKind,
                $lacune->sujetId,
                $lacune->sujetLabel,
                $texte->texte,
                $texte->redacteur,
            ));
        }

        $this->enregistrer($destinataireUserId);
    }

    private function enregistrer(int $destinataireUserId): void
    {
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // `uniq_qst_question_lacune` a tranché : une autre requête du même compte a
            // créé les mêmes lignes. Rien n'est en double, rien n'est perdu — mais
            // l'`EntityManager` est fermé, et l'on ne peut plus rien lire avec.
            throw DetectionConcurrente::pourLeCompte($destinataireUserId, $e);
        }
    }

    /**
     * Étapes 6 et 7, puis l'ordre et la borne.
     *
     * @param array<string, Lacune> $lacunes
     *
     * @return list<Question>
     */
    private function servir(int $destinataireUserId, array $lacunes, \DateTimeImmutable $maintenant): array
    {
        $servables = $this->questions->servablesPour($destinataireUserId, $maintenant);

        // Étape 6. **Une lacune comblée ne se balaie pas, elle cesse d'être détectée.**
        // La ligne reste en base avec son état ; elle ne revient simplement plus. Même
        // discipline que la caducité de l'ADR 0004 : « constatée à la validation, jamais
        // par une tâche de fond ».
        $servables = array_values(array_filter(
            $servables,
            static fn (Question $q): bool => isset($lacunes[$q->getSignature()]),
        ));

        // Étape 7. La garde à la lecture — celle qui attrape le cercle rétréci.
        $servables = $this->visibles->filtrerLesQuestions($servables);

        // L'ordre d'affichage : le plus facile à répondre d'abord. Une page qui s'ouvre
        // sur « quand votre arrière-grand-père est-il né ? » se referme ; une page qui
        // s'ouvre sur « auriez-vous une photo ? » obtient une première contribution.
        //
        // Le second critère n'est pas décoratif : à rang égal, l'identifiant garantit un
        // ordre **total**, donc stable d'un chargement à l'autre. `usort` n'est pas
        // stable, et deux chargements qui montreraient la même page dans deux ordres
        // feraient chercher la cause dans la base.
        usort($servables, static function (Question $a, Question $b): int {
            return [$a->getNature()->rang(), $a->getSujetId(), $a->getId() ?? 0]
                <=> [$b->getNature()->rang(), $b->getSujetId(), $b->getId() ?? 0];
        });

        return \array_slice($servables, 0, $this->reglages->maximumParPage());
    }
}

<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\ValueKind;
use App\Module\Proposal\Exception\ErreurDeDemande;
use App\Module\Proposal\Service\Port\PortArbre;
use App\Module\Question\Contract\DepotDeReponse;
use App\Module\Question\Contract\DepotImpossible;
use App\Module\Question\Contract\ReponseDeposee;

/**
 * L'adaptateur de `Proposal` pour les réponses de la page « Questions » (ADR 0008 § 7).
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  AUCUN CHEMIN D'ÉCRITURE NOUVEAU N'APPARAÎT ICI.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Les trois méthodes construisent un **corps de requête** et le passent à
 * {@see DepotDeDemande::deposer()} — c'est-à-dire exactement le service que
 * `POST /api/demandes` appelle, avec les mêmes constructeurs, les mêmes contrôles, le
 * même verrou optimiste et la même file. Ce fichier ne persiste rien, ne valide rien,
 * n'écrit dans aucune table.
 *
 * *C'est délibérément plus verbeux qu'un appel direct aux constructeurs.* Passer par le
 * corps de requête, c'est traverser les six contrôles de `ConstructeurDeModification` et
 * les deux de `ConstructeurDAjoutDeNote` — ceux-là mêmes qui attrapent une valeur
 * inchangée, un champ hors surface, une cible disparue. Court-circuiter le dépôt pour
 * « gagner » la construction du tableau aurait fait de cette page un second chemin
 * d'écriture, avec ses propres trous.
 *
 * ── L'auteur n'est pas un paramètre ────────────────────────────────────────────
 *
 * {@see FabriqueDeLecteur::courant()} le résout depuis la session. Trois garde-fous
 * viennent avec, sans une ligne de plus : un compte en attente de rattachement ne
 * contribue pas, la bascule « voir comme un membre » interdit l'écriture, et
 * `author_label` est l'instantané que ce module sait figer.
 *
 * ── Ce que cet adaptateur doit savoir et que `Question` ne peut pas savoir ──────
 *
 * Une `modification_personne` exige `valueKind`, `baseVersion` et `oldValue`, qui se
 * lisent **sur la fiche courante**. Ils sont lus ici, par les services que ce module
 * possède déjà. Les faire traverser le contrat aurait obligé `Question` à connaître le
 * vocabulaire de `ValueKind` et à relire la version de la ligne — c'est-à-dire à
 * recopier la moitié du constructeur de modification, qui est exactement le mécanisme
 * par lequel une frontière se dissout.
 *
 * ── Pourquoi tout est enveloppé dans un `try` ──────────────────────────────────
 *
 * `Question` n'a pas le droit de nommer `Proposal\Exception\ErreurDeDemande` (règle 3 de
 * l'ADR 0002), et il ne doit pas non plus avoir à la connaître : ce qui l'intéresse est
 * « refusé, avec ce message pour le membre ». On traduit donc au bord, en gardant le
 * message — il est déjà écrit pour un humain — et en reportant le nom de champ quand il
 * y en a un.
 *
 * **L'exception d'origine est chaînée**, jamais avalée : le journal garde la cause
 * exacte, et c'est ce qui permet de diagnostiquer un refus dont le message, seul, ne dit
 * pas d'où il vient.
 */
final readonly class DepotDeReponsesAuxQuestions implements DepotDeReponse
{
    public function __construct(
        private DepotDeDemande $depot,
        private FabriqueDeLecteur $lecteurs,
        private PortArbre $arbre,
        private SurfaceEditable $surface,
    ) {
    }

    public function corrigerUnChamp(int $personneId, string $champ, string $valeur, string $message): ReponseDeposee
    {
        // La table de correspondance est close et explicite (§ 9.4 du modèle) : un champ
        // absent n'est pas une faute de frappe, c'est une nature de lacune qui a été
        // écrite avec un nom de champ qui n'existe plus. On lève **avant** de toucher au
        // dépôt, avec un message qui nomme le défaut de configuration plutôt qu'une
        // prétendue faute du membre.
        $nature = $this->surface->natureDe(SubjectKind::Personne, $champ);

        if (null === $nature) {
            throw new DepotImpossible(
                'Cette réponse ne peut pas être enregistrée automatiquement.',
                champ: null,
                statut: 503,
            );
        }

        $cible = $this->arbre->chargerCible(SubjectKind::Personne, $personneId);

        if (null === $cible) {
            throw new DepotImpossible('Cette fiche n’existe plus.', statut: 409);
        }

        return $this->deposer([
            'kind' => RequestKind::ModificationPersonne->value,
            'message' => $message,
            'targetKind' => SubjectKind::Personne->value,
            'targetId' => $personneId,
            'ops' => [[
                'seq' => 1,
                'opKind' => OpKind::Champ->value,
                'subjectKind' => SubjectKind::Personne->value,
                'subjectId' => $personneId,
                'field' => $champ,
                'valueKind' => $nature->value,
                // Les deux verrous de l'ADR 0004, et c'est ici qu'ils se posent :
                // `baseVersion` protège les trois jours entre le dépôt et la validation,
                // `oldValue` rend la demande caduque plutôt que d'écraser.
                'baseVersion' => $cible->version,
                'oldValue' => $cible->valeurs[$champ] ?? null,
                'newValue' => $valeur,
            ]],
        ]);
    }

    public function annoterUneFiche(int $personneId, string $corps, string $message): ReponseDeposee
    {
        return $this->deposer([
            'kind' => RequestKind::AjoutNote->value,
            'message' => $message,
            'targetKind' => SubjectKind::Personne->value,
            'targetId' => $personneId,
            'ops' => [[
                'seq' => 1,
                'opKind' => OpKind::AjoutNote->value,
                'subjectKind' => SubjectKind::Personne->value,
                'subjectId' => $personneId,
                'valueKind' => ValueKind::Note->value,
                'oldValue' => null,
                // La portée par défaut : la note documente la fiche entière, pas un fait
                // précis. C'est juste — une réponse de page ne sait pas à quel `tree_fact`
                // elle se rattache, et prétendre le contraire produirait une note accrochée
                // au mauvais événement.
                'newValue' => ['body' => $corps, 'scope' => 'generale'],
            ]],
        ]);
    }

    public function joindreUnMedia(int $personneId, int $mediaId, string $message): ReponseDeposee
    {
        return $this->deposer([
            'kind' => RequestKind::AjoutMedia->value,
            'message' => $message,
            // La cible **annoncée** est confrontée au sujet réel du média par
            // `ConstructeurDAjoutDeMedia::poserLaCible()`. Un média téléversé sur une
            // autre personne est donc refusé ici, et pas découvert à la validation.
            'targetKind' => SubjectKind::Personne->value,
            'targetId' => $personneId,
            'ops' => [[
                'seq' => 1,
                'opKind' => OpKind::AjoutMedia->value,
                'newValue' => ['mediaId' => $mediaId],
            ]],
        ]);
    }

    /**
     * Le seul point d'appel vers `DepotDeDemande`, et la seule traduction d'exception.
     *
     * @param array<string, mixed> $corps
     */
    private function deposer(array $corps): ReponseDeposee
    {
        try {
            $demande = $this->depot->deposer($corps, $this->lecteurs->courant());
        } catch (ErreurDeDemande $e) {
            throw new DepotImpossible(
                $e->getMessage(),
                champ: self::premierChamp($e),
                statut: $e->statutHttp(),
                precedente: $e,
            );
        }

        return new ReponseDeposee($this->identifiant($demande), $demande->getKind()->value);
    }

    /**
     * Le nom de champ du refus, quand il y en a un.
     *
     * `Proposal` nomme ses champs dans le vocabulaire de son corps de requête —
     * `ops[0].newValue`, `ops[0].field`. Ce n'est pas celui du formulaire de la page, et
     * le front n'a aucun moyen de le rattacher à une zone de saisie. On ne rend donc que
     * le **premier segment**, qui suffit à distinguer « c'est la réponse qui est en
     * cause » de « c'est autre chose ».
     */
    private static function premierChamp(ErreurDeDemande $e): ?string
    {
        $champs = $e->champs();

        if (null === $champs || [] === $champs) {
            return null;
        }

        $nom = array_key_first($champs);

        return str_starts_with($nom, 'ops') ? 'reponse' : $nom;
    }

    /**
     * L'identifiant de la demande déposée.
     *
     * `DepotDeDemande::deposer()` a déjà fait son `flush()`, donc l'identifiant existe.
     * On ne le suppose pas pour autant : un `null` ici voudrait dire que le dépôt a rendu
     * une entité non persistée, et laisser passer un `0` dans `qst_question_demande`
     * créerait un lien pendant que **rien ne signalerait** — une trace pendante se lit,
     * elle ne se répare pas.
     */
    private function identifiant(ChangeRequest $demande): int
    {
        return $demande->getId()
            ?? throw new DepotImpossible('Votre réponse n’a pas pu être enregistrée.', statut: 500);
    }
}

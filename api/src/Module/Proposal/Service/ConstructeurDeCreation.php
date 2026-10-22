<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftLink;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Enum\LinkKind;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Enum\ValueKind;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\Introuvable;
use App\Module\Proposal\Repository\DraftLinkRepository;
use App\Module\Proposal\Repository\DraftPersonRepository;
use App\Module\Proposal\Service\Port\PortArbre;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * PROPOSER L'AJOUT D'UNE PERSONNE — le dépôt (§ 6.3 du contrat d'API).
 *
 * C'est le § 6.4 du dossier de mission, et son premier mot est le plus important :
 * **même circuit que la modification**. Une création est une `ChangeRequest` comme une
 * autre — même file, même diff, même refus motivé, même message obligatoire. Deux
 * mécanismes, ce serait « deux écrans d'administration, deux formats de diff, deux
 * chemins de notification, et deux endroits où oublier le message obligatoire au
 * refus » (ADR 0004).
 *
 * D'où ce fichier : il se branche sur le point de greffe de {@see ConstructeurDeDemande}
 * et **rien d'autre ne change** — ni la route, ni le contrôleur, ni le sérialiseur, ni
 * le validateur, ni les deux `409`.
 *
 * ── Ce qu'une demande de création porte, et ce qu'elle ne porte pas ─────────────
 *
 * Elle porte des `prp_draft_person` et des `prp_draft_link`, et **aucun
 * `prp_change_op`** : le § 6.5 du contrat la montre avec `"ops": []`. Elle ne porte
 * **aucune famille** non plus, et c'est le point que le modèle insiste à écrire deux
 * fois : « la demande décrit l'intention (“conjoint de X”), le service en déduit les
 * familles à créer **au moment de la validation**, pas au moment de la demande »
 * (§ 6.4 du dossier, § 9.5 du modèle). Un front qui enverrait un `familleRef` est
 * refusé en `422`.
 *
 * *Pourquoi.* Figer la famille à la saisie, c'est figer quelque chose qu'une autre
 * demande validée entre-temps a pu créer — on en insérerait alors une seconde, que
 * `uniq_tree_family_couple` refuserait. Déduire à la validation, c'est déduire à partir
 * de l'état réel.
 *
 * ── `ajout_lien` est la même chose sans fiche ──────────────────────────────────
 *
 * « Ils étaient frère et sœur », « elle s'est remariée avec lui » : mêmes liens, mêmes
 * contrôles, sans `brouillons`. Le traiter ici plutôt que dans un troisième fichier est
 * la même décision qu'au-dessus.
 *
 * ── Une extrémité a **trois** formes, pas deux ──────────────────────────────────
 *
 * | Clé | Ce qu'elle désigne | Portée |
 * |---|---|---|
 * | `*PersonId` | une personne de l'arbre validé | globale |
 * | `*DraftRef` | `#p1`, `#p2`… | **cette demande seulement** |
 * | `*DraftPersonId` | une fiche proposée par une **autre** demande en attente | globale |
 *
 * La troisième répond au défaut signalé mot pour mot par le commanditaire : « si je
 * propose une personne puis que j'en propose une autre, le père que j'ai proposé juste
 * avant ne m'est pas offert ». Sans elle, une branche ne se décrit qu'en une seule
 * demande, et le contributeur qui revient le lendemain n'a le choix qu'entre attendre
 * une validation et ressaisir la même personne — le doublon que le § 9.5 écarte.
 *
 * Trois contrôles la gardent, et chacun attrape une chose différente :
 *
 * 1. **le lecteur doit avoir le droit de la voir** — ses propres demandes, ou toutes
 *    s'il est administrateur (§ 13.3). Sinon `404`, jamais `403` : un `403` apprendrait
 *    que la ligne existe ;
 * 2. **la demande citée doit être `en_attente`** — sauf si elle vient d'être validée,
 *    auquel cas la fiche porte son `resolved_person_id` et le lien désigne la
 *    **personne réelle**. C'est le cas d'une validation qui tombe entre la recherche et
 *    le dépôt, et le refuser ferait échouer une demande que la réalité vient de
 *    conforter ;
 * 3. **elle ne doit pas appartenir à la demande en cours** — dans sa propre demande, une
 *    fiche se désigne par `*DraftRef`, et laisser les deux formes cohabiter donnerait
 *    deux façons de dire la même chose, dont une que l'applicateur résout par une carte
 *    et l'autre par une relecture. Ce contrôle a longtemps été **un filet et non un
 *    cas** : une demande naissait vide, ses brouillons n'avaient pas d'identifiant au
 *    moment où ses liens se construisaient. **Il est devenu atteignable le 10/10/2026** :
 *    une demande enregistrée se complète désormais sur place (§ 6.6 ter,
 *    {@see CompletionDeProposition}), et ses fiches ont alors un identifiant qu'un lien
 *    ajouté pourrait citer. Il est éprouvé par `CompletionDePropositionTest`.
 *
 * ── Le premier contrôle porte sur l'**auteur de la demande**, pas sur le lecteur ──
 *
 * Tant qu'une demande ne s'écrivait qu'au dépôt, les deux étaient la même personne. Ce
 * n'est plus vrai : un administrateur complète la demande d'un membre. S'il pouvait y
 * citer n'importe quelle fiche — il les voit toutes —, il glisserait dans la demande d'un
 * membre une fiche que ce membre ne voit pas, et dont la validation dépendrait. La fiche
 * citée doit donc être visible de **celui qui signe la demande**.
 *
 * ── Les compléments (ADR 0017) ──────────────────────────────────────────────────
 *
 * Une création peut **compléter** la proposition d'un autre membre : `complementDe`, au
 * premier niveau du corps, désigne la fiche complétée. Les règles sont dans
 * {@see rattacherALaProposition()} ; la fusion, dans {@see FusionDeComplement}.
 */
final readonly class ConstructeurDeCreation implements ConstructeurDeDemande
{
    /** `#p1`, `#p2`… — la forme imposée par `uniq_prp_draft_person_ref` et le § 9.5. */
    private const string FORME_DE_REFERENCE = '/^#p[1-9]\d{0,2}$/';

    /**
     * Les champs d'une fiche proposée que le dépôt écrit **et** que la complétion réécrit,
     * dans l'ordre où la notification les nomme. Ce sont exactement les colonnes éditables
     * de `prp_draft_person` (§ 5.3 du modèle) — `localRef` et `resolvedPersonId` n'en sont
     * pas : la première est l'identité de la fiche dans sa demande, la seconde le résultat
     * d'une validation.
     */
    public const array CHAMPS_DE_FICHE = [
        'givenNames', 'surname', 'marriedName', 'nickname', 'sex', 'deceased', 'birth', 'death', 'note',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private PortArbre $arbre,
        private NormaliseurDeValeur $normaliseur,
        private CleDeDoublon $cleDeDoublon,
        private DraftPersonRepository $brouillons,
        private DraftLinkRepository $liens,
    ) {
    }

    public function natures(): array
    {
        return [RequestKind::CreationPersonne, RequestKind::AjoutLien];
    }

    public function construire(ChangeRequest $demande, array $corps, Lecteur $lecteur): void
    {
        $creation = RequestKind::CreationPersonne === $demande->getKind();

        $brouillons = $this->construireLesBrouillons($demande, $corps, $creation);
        $this->rattacherALaProposition($demande, $corps, $brouillons);
        $this->construireLesLiens($demande, $corps, $brouillons, $creation, $lecteur);
    }

    /**
     * **Le complément** (§ 6.3 ter du contrat, ADR 0017) : cette création complète la
     * fiche `complementDe`, proposée par un autre membre et encore en attente.
     *
     * ── Les règles, dans l'ordre où elles sont jugées ───────────────────────────────
     *
     * | Cas | Réponse |
     * |---|---|
     * | `complementDe` sur une autre nature que `creation_personne` | `422` — refusé plus tôt, par {@see DepotDeDemande}, pour toutes les natures ; ici pour `ajout_lien`, que ce constructeur partage |
     * | pas un entier | `422` |
     * | plus d'une fiche dans le corps | `422` — un complément décrit **la** personne qu'il complète, et la fusion ne saurait pas laquelle verser |
     * | fiche inexistante, ou brouillon non envoyé d'un autre | `404` — comme au § 6.3 bis, l'existence d'un brouillon d'autrui ne s'annonce pas |
     * | demande citée tranchée | `409 proposition_plus_en_attente`, **sans détails** |
     * | sa propre proposition | `422` — elle se complète sur place (§ 6.6 ter) |
     * | une fiche qui est elle-même un complément | `422` — pas de chaîne : on complète l'origine |
     *
     * **Au moins un lien reste exigé à la soumission**, comme pour toute création — et ce
     * n'est pas un réflexe recopié : le complément doit rester **validable seul**, parce
     * que l'origine peut mourir (refusée, retirée, caduque) avant la fusion, et qu'il
     * devient alors une création ordinaire. Sans lien, il flotterait hors de l'arbre.
     *
     * La recherche de la fiche ne passe pas par {@see DraftPersonRepository::visiblePourLecteur()},
     * qui borne un membre à ses propres fiches : un complément vise précisément celle d'un
     * autre. Voir {@see DraftPersonRepository::complementable()}.
     *
     * @param array<string, mixed>       $corps
     * @param array<string, DraftPerson> $brouillons
     */
    private function rattacherALaProposition(ChangeRequest $demande, array $corps, array $brouillons): void
    {
        $cible = $corps['complementDe'] ?? null;

        if (null === $cible) {
            return;
        }

        if (RequestKind::CreationPersonne !== $demande->getKind()) {
            throw DonneesInvalides::pourChamp('complementDe', 'Seule une création de personne complète une proposition.');
        }

        if (!\is_int($cible)) {
            throw DonneesInvalides::pourChamp('complementDe', 'L\'identifiant de la fiche complétée est un entier.');
        }

        if (1 !== \count($brouillons)) {
            throw DonneesInvalides::pourChamp('brouillons', 'Un complément décrit une seule personne : celle qu\'il complète.');
        }

        $fiche = $this->brouillons->complementable($cible, $demande->getAuthorUserId());

        if (null === $fiche) {
            throw Introuvable::brouillonPropose($cible);
        }

        $origine = $fiche->getRequest();
        $statut = $origine->getStatus();

        // Tranchée : la fiche ne deviendra pas une personne par cette demande-là — ou elle
        // l'est déjà devenue —, et il n'y a plus rien à fusionner. Sans dire laquelle des
        // issues : voir {@see Conflit::propositionPlusEnAttente()}.
        if (RequestStatus::EnAttente !== $statut && RequestStatus::Brouillon !== $statut) {
            throw Conflit::propositionPlusEnAttente();
        }

        if ($origine->getAuthorUserId() === $demande->getAuthorUserId()) {
            throw DonneesInvalides::pourChamp('complementDe', 'C\'est votre propre proposition : complétez-la sur place plutôt que d\'y joindre un complément.');
        }

        if (null !== $origine->getComplementDe()) {
            throw DonneesInvalides::pourChamp('complementDe', 'Cette fiche complète déjà une autre proposition : un complément se rattache à la proposition d\'origine, pas à un autre complément.');
        }

        $demande->setComplementDe($fiche);
    }

    /**
     * **Au moins un lien**, « sinon la personne flotterait hors de l'arbre » (§ 6.4 du
     * dossier).
     *
     * Contrôlé **à la soumission** et non à la création : on doit pouvoir enregistrer un
     * brouillon incomplet, et un membre qui décrit une fiche en trois fois ne doit pas
     * se faire refuser sa première sauvegarde.
     */
    public function verifierAvantSoumission(ChangeRequest $demande): void
    {
        if ([] === $this->liensDe($demande)) {
            throw DonneesInvalides::demandeSansLien();
        }

        // Un complément enregistré en brouillon se soumet plus tard : la proposition qu'il
        // complète a pu être tranchée entre-temps. La règle du dépôt vaut à la soumission —
        // sans quoi il entrerait dans la file en désignant une origine morte, et
        // l'administrateur hériterait d'un complément qu'aucune fusion ne peut prendre.
        $origine = $demande->getComplementDe()?->getRequest();

        if (null !== $origine && RequestStatus::EnAttente !== $origine->getStatus()) {
            throw Conflit::propositionPlusEnAttente();
        }

        $this->poserLaCleDeDoublon($demande);
    }

    /**
     * La clé de doublon d'une demande soumise — **et de nouveau à chaque complétion** d'une
     * demande en attente (§ 6.6 ter) : un nom ou une naissance corrigés déplacent la
     * demande dans `idx_prp_request_dedup`, et la laisser sous l'ancienne clé ferait
     * manquer le rapprochement que l'administrateur attend. Une demande dont plus aucune
     * fiche n'a de clé la perd.
     */
    public function poserLaCleDeDoublon(ChangeRequest $demande): void
    {
        // La clé de doublon est calculée **par le serveur, à la soumission** (§ 6.3) :
        // le client ne l'envoie pas et ne la voit que dans la vue d'administration. À la
        // soumission parce qu'un brouillon n'a pas à encombrer l'index partiel, qui ne
        // porte que sur les demandes `en_attente`.
        //
        // La règle « la première fiche qui a une clé » vit dans `CleDeDoublon`, et non
        // plus ici : `heritage:recalculer-les-cles-de-doublon` doit refaire exactement ce
        // calcul, et deux copies d'une règle finissent par en faire deux.
        $demande->setDedupKey($this->cleDeDoublon->pourLesBrouillons($this->brouillonsDe($demande)));
    }

    /**
     * Les liens de la demande, **qu'elle soit déjà en base ou non**.
     *
     * Ce n'est pas une précaution théorique : `POST /api/demandes` avec
     * `soumettre: true` construit la demande et la soumet dans le même geste, et le
     * contrôle de complétude tombe donc **avant le premier `flush`** — la demande n'a
     * alors aucun identifiant, et une requête qui la lie en paramètre lève
     * « does not have an identifier ».
     *
     * Interroger l'unité de travail plutôt que de flusher d'abord est ce qui garde le
     * dépôt atomique **sans transaction** : un corps refusé n'a rien écrit du tout,
     * parce que rien n'est encore parti. Flusher pour pouvoir compter laisserait une
     * demande orpheline en base à chaque `422`.
     *
     * @return list<DraftLink>
     */
    private function liensDe(ChangeRequest $demande): array
    {
        if (null !== $demande->getId()) {
            return $this->liens->pourDemande($demande);
        }

        return $this->enAttenteDInsertion($demande, DraftLink::class);
    }

    /** @return list<DraftPerson> */
    private function brouillonsDe(ChangeRequest $demande): array
    {
        if (null !== $demande->getId()) {
            return $this->brouillons->pourDemande($demande);
        }

        return $this->enAttenteDInsertion($demande, DraftPerson::class);
    }

    /**
     * @template T of DraftLink|DraftPerson
     *
     * @param class-string<T> $classe
     *
     * @return list<T>
     */
    private function enAttenteDInsertion(ChangeRequest $demande, string $classe): array
    {
        $lignes = [];

        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entite) {
            if ($entite instanceof $classe && $entite->getRequest() === $demande) {
                $lignes[] = $entite;
            }
        }

        return $lignes;
    }

    /**
     * @param array<string, mixed> $corps
     *
     * @return array<string, DraftPerson> les fiches déclarées, **indexées par leur
     *                                    référence provisoire** : les liens s'y
     *                                    rapportent, et ils ont besoin du nom autant que
     *                                    de la référence
     */
    private function construireLesBrouillons(ChangeRequest $demande, array $corps, bool $creation): array
    {
        $brut = $corps['brouillons'] ?? [];

        if (!\is_array($brut)) {
            throw DonneesInvalides::pourChamp('brouillons', 'Les brouillons forment un tableau.');
        }

        if (!$creation) {
            // Un `ajout_lien` relie deux personnes qui existent : accepter une fiche ici
            // donnerait deux façons de créer une personne, dont une non documentée.
            if ([] !== $brut) {
                throw DonneesInvalides::pourChamp('brouillons', 'Un ajout de lien ne crée aucune fiche.');
            }

            return [];
        }

        if ([] === $brut) {
            throw DonneesInvalides::pourChamp('brouillons', 'Une création décrit au moins une personne.');
        }

        $brouillons = [];

        foreach (array_values($brut) as $index => $fiche) {
            if (!\is_array($fiche)) {
                throw DonneesInvalides::pourChamp("brouillons[$index]", 'Chaque brouillon est un objet.');
            }

            $reference = $this->reference($fiche, $index, array_keys($brouillons));
            $construit = $this->brouillon($demande, $reference, $fiche, $index);

            $brouillons[$reference] = $construit;
            $this->em->persist($construit);
        }

        return $brouillons;
    }

    /**
     * @param array<string, mixed> $fiche
     * @param list<string>         $dejaVues
     */
    private function reference(array $fiche, int $index, array $dejaVues): string
    {
        $reference = $fiche['localRef'] ?? null;

        if (!\is_string($reference) || 1 !== preg_match(self::FORME_DE_REFERENCE, $reference)) {
            throw DonneesInvalides::pourChamp("brouillons[$index].localRef", 'Une référence provisoire s\'écrit #p1, #p2…');
        }

        if (\in_array($reference, $dejaVues, true)) {
            throw DonneesInvalides::pourChamp("brouillons[$index].localRef", 'Cette référence provisoire est déjà prise dans cette demande.');
        }

        return $reference;
    }

    /** @param array<string, mixed> $fiche */
    private function brouillon(ChangeRequest $demande, string $reference, array $fiche, int $index): DraftPerson
    {
        $brouillon = new DraftPerson($demande, $reference);
        $this->reecrireLaFiche($brouillon, $this->lireLaFiche($fiche, "brouillons[$index]"));

        return $brouillon;
    }

    /**
     * Lit et **valide** les champs d'une fiche proposée, **sans rien écrire** — la moitié
     * commune du dépôt et de la complétion sur place (§ 6.6 ter).
     *
     * ── Pourquoi lire et écrire sont deux méthodes ──────────────────────────────────
     *
     * Au dépôt, la fiche est neuve : une `422` la laisse tomber avec la demande, rien n'est
     * parti. À la complétion, elle est **en base et gérée** par l'unité de travail. Si ses
     * champs étaient posés un à un puis la règle du décès refusée au dernier, l'entité
     * resterait à moitié réécrite, et le premier `flush()` venu de la même requête — un
     * journal, une notification — écrirait en base une fiche que la `422` venait de
     * refuser. Valider tout, puis écrire tout, ferme la fenêtre par construction.
     *
     * **Toute clé absente vaut `null`**, comme au dépôt : la complétion remplace la fiche
     * entière, elle ne la rapièce pas. Un client qui veut garder une valeur la renvoie.
     *
     * @param array<string, mixed> $fiche
     *
     * @return array{givenNames: string, surname: string, marriedName: string|null, nickname: string|null, sex: Sex, deceased: bool, birth: UncertainDate, death: UncertainDate, note: string|null}
     */
    public function lireLaFiche(array $fiche, string $chemin): array
    {
        $valeurs = [
            'givenNames' => $this->texte($fiche, 'givenNames', $chemin) ?? '',
            'surname' => $this->texte($fiche, 'surname', $chemin) ?? '',
            'marriedName' => $this->texte($fiche, 'marriedName', $chemin),
            'nickname' => $this->texte($fiche, 'nickname', $chemin),
            'sex' => $this->sexe($fiche, $chemin),
            'deceased' => $this->booleen($fiche, 'deceased', $chemin),
            'birth' => $this->date($fiche, 'birth', $chemin),
            'death' => $this->date($fiche, 'death', $chemin),
            'note' => $this->texte($fiche, 'note', $chemin),
        ];

        // `ck_prp_draft_person_nom` refuse en base une fiche sans le moindre nom. On le
        // dit ici en 422 plutôt que de laisser la contrainte produire une 500 : les
        // quatre individus sans `NAME` du GEDCOM existent depuis l'import initial, on n'en crée pas
        // de nouveaux à la main.
        if ('' === trim($valeurs['surname']) && '' === trim($valeurs['givenNames'])) {
            throw DonneesInvalides::pourChamp("$chemin.surname", 'Une fiche proposée porte au moins un nom ou un prénom.');
        }

        // Même raison, un cran plus loin : `ck_tree_person_deces_coherent` ne mord qu'à
        // la **validation**, quand le brouillon devient une vraie fiche. Sans ce contrôle,
        // le dépôt passe, et c'est l'administrateur qui reçoit une erreur de pilote de
        // base de données trois jours plus tard, sur une demande qu'il n'a pas écrite.
        //
        // On refuse plutôt que de cocher la case : `deceased` **ouvre la visibilité** —
        // un vivant hors cercle est absent de l'arbre, un défunt est visible de tout
        // compte connecté (ADR 0003). On ne bascule pas cela à la place de quelqu'un qui
        // n'a saisi qu'une date.
        if (!$valeurs['deceased'] && self::porteUneDate($valeurs['death'])) {
            throw DonneesInvalides::pourChamp("$chemin.death", 'Une date de décès implique le décès : cochez « personne décédée », ou retirez la date. Une fiche marquée décédée devient visible de tout compte connecté, et on ne bascule pas cela à votre place.');
        }

        return $valeurs;
    }

    /**
     * Écrit sur la fiche des valeurs **déjà validées** par {@see lireLaFiche()} — **en
     * place** : la ligne garde son identifiant, et c'est l'essentiel.
     *
     * Une fiche proposée se cite depuis d'autres demandes par l'identifiant de sa ligne
     * (`*_draft_person_id`, § 6.3 bis), **sans clé étrangère**. La supprimer pour en
     * recréer une rendrait ces liens pendants en silence, et leur validation tomberait en
     * `dependance_morte` — une caducité prononcée sur une personne qui existe toujours.
     *
     * @param array{givenNames: string, surname: string, marriedName: string|null, nickname: string|null, sex: Sex, deceased: bool, birth: UncertainDate, death: UncertainDate, note: string|null} $valeurs
     *
     * @return list<string> les champs qui ont **réellement** changé, dans l'ordre de
     *                      {@see CHAMPS_DE_FICHE} — de quoi dire à l'auteur ce que
     *                      l'administration a complété, sans comparer deux fois
     */
    public function reecrireLaFiche(DraftPerson $fiche, array $valeurs): array
    {
        $avant = $this->instantane($fiche);

        $fiche
            ->setGivenNames($valeurs['givenNames'])
            ->setSurname($valeurs['surname'])
            ->setMarriedName($valeurs['marriedName'])
            ->setNickname($valeurs['nickname'])
            ->setSex($valeurs['sex'])
            ->setDeceased($valeurs['deceased'])
            ->setBirth($valeurs['birth'])
            ->setDeath($valeurs['death'])
            ->setNote($valeurs['note']);

        $apres = $this->instantane($fiche);

        return array_values(array_filter(
            self::CHAMPS_DE_FICHE,
            static fn (string $champ): bool => $avant[$champ] !== $apres[$champ],
        ));
    }

    /**
     * Les champs d'une fiche sous une forme **comparable par `===`** : les dates passent
     * par leur forme JSON normalisée — deux `UncertainDate` de même valeur ne sont pas
     * `===`, et `==` comparerait des propriétés privées dans un ordre non garanti (voir
     * l'en-tête de {@see AnalyseDeDemande}).
     *
     * @return array<string, mixed>
     */
    public function instantane(DraftPerson $fiche): array
    {
        return [
            'givenNames' => $fiche->getGivenNames(),
            'surname' => $fiche->getSurname(),
            'marriedName' => $fiche->getMarriedName(),
            'nickname' => $fiche->getNickname(),
            'sex' => $fiche->getSex()->value,
            'deceased' => $fiche->isDeceased(),
            'birth' => $this->normaliseur->versJson(ValueKind::DateIncertaine, $fiche->getBirth()),
            'death' => $this->normaliseur->versJson(ValueKind::DateIncertaine, $fiche->getDeath()),
            'note' => $fiche->getNote(),
        ];
    }

    /**
     * Des liens **ajoutés** à une demande déjà enregistrée — la seconde moitié de la
     * complétion sur place (§ 6.6 ter). Construits et validés, **pas encore persistés** :
     * l'appelant les persiste une fois tout le corps accepté, pour la même raison que
     * {@see lireLaFiche()} sépare lire et écrire.
     *
     * ── Le rang est attribué ici, jamais reçu ───────────────────────────────────────
     *
     * `uniq_prp_draft_link_seq` refuse deux liens de même rang dans une demande, et le
     * client ne sait pas lesquels sont pris — une complétion de l'administrateur a pu en
     * ajouter depuis son affichage. Un `seq` envoyé est donc **refusé** en `422` plutôt
     * qu'ignoré : un client qui croit choisir l'ordre doit apprendre qu'il ne le choisit
     * pas. Le rang vient du plus grand **en base** : {@see liensDe()} lirait la base dès
     * que la demande a un identifiant, et ne verrait pas les liens persistés et non
     * encore écrits — l'appelant n'en a pas, il compte donc juste.
     *
     * Les références `*DraftRef` désignent les fiches de la demande **telles qu'elles sont
     * en base** : le libellé d'un lien qui cite la fiche qu'on est en train de compléter
     * porte donc l'ancien nom, et c'est l'appelant qui le rafraîchit avec les autres liens
     * qui la citent ({@see CompletionDeProposition}).
     *
     * @return list<DraftLink>
     */
    public function liensEnPlus(ChangeRequest $demande, mixed $brut, Lecteur $lecteur): array
    {
        if (null === $brut) {
            return [];
        }

        if (!\is_array($brut)) {
            throw DonneesInvalides::pourChamp('liens', 'Les liens forment un tableau.');
        }

        $brouillons = [];

        foreach ($this->brouillons->pourDemande($demande) as $brouillon) {
            $brouillons[$brouillon->getLocalRef()] = $brouillon;
        }

        $creation = RequestKind::CreationPersonne === $demande->getKind();
        $rang = $this->liens->plusGrandRang($demande);
        $construits = [];

        foreach (array_values($brut) as $index => $lien) {
            if (!\is_array($lien)) {
                throw DonneesInvalides::pourChamp("liens[$index]", 'Chaque lien est un objet.');
            }

            if (null !== ($lien['seq'] ?? null)) {
                throw DonneesInvalides::pourChamp("liens[$index].seq", 'Le rang d\'un lien ajouté est attribué par le serveur : ne l\'envoyez pas.');
            }

            $construits[] = $this->lien($demande, $lien, $index, ++$rang, $brouillons, $creation, $lecteur);
        }

        return $construits;
    }

    /** Un bloc date porte-t-il une **date**, au-delà d'un lieu seul ? */
    private static function porteUneDate(UncertainDate $date): bool
    {
        return null !== $date->getRaw() || null !== $date->getMin() || null !== $date->getMax();
    }

    /**
     * @param array<string, mixed>       $corps
     * @param array<string, DraftPerson> $brouillons indexés par référence provisoire
     */
    private function construireLesLiens(
        ChangeRequest $demande,
        array $corps,
        array $brouillons,
        bool $creation,
        Lecteur $lecteur,
    ): void {
        $brut = $corps['liens'] ?? [];

        if (!\is_array($brut)) {
            throw DonneesInvalides::pourChamp('liens', 'Les liens forment un tableau.');
        }

        if ([] !== ($corps['familles'] ?? []) || [] !== ($corps['familleRefs'] ?? [])) {
            throw DonneesInvalides::pourChamp('familles', 'Une demande ne décrit aucune famille : le service les déduit à la validation.');
        }

        $rangs = [];
        $premierSujetReel = null;

        foreach (array_values($brut) as $index => $lien) {
            if (!\is_array($lien)) {
                throw DonneesInvalides::pourChamp("liens[$index]", 'Chaque lien est un objet.');
            }

            $seq = $lien['seq'] ?? null;
            if (!\is_int($seq) || $seq < 1) {
                throw DonneesInvalides::pourChamp("liens[$index].seq", 'Le rang d\'un lien est un entier positif.');
            }

            $construit = $this->lien($demande, $lien, $index, $seq, $brouillons, $creation, $lecteur);

            if (\in_array($construit->getSeq(), $rangs, true)) {
                throw DonneesInvalides::pourChamp("liens[$index].seq", 'Deux liens ne peuvent pas porter le même rang.');
            }

            $rangs[] = $construit->getSeq();
            $premierSujetReel ??= $construit->getSubjectPersonId();

            $this->em->persist($construit);
        }

        // Une création ne vise rien d'existant : `targetKind` et `targetId` restent
        // `null`, et c'est légal — `ck_prp_request_cible` n'exige que leur cohérence
        // mutuelle. Un `ajout_lien`, lui, porte sur une personne de l'arbre : le dire
        // permet à la file de grouper par fiche sans jointure (§ 5.1).
        if ($creation) {
            return;
        }

        // **La cible annoncée l'emporte, si elle est bien une extrémité des liens.**
        //
        // Le repli — le sujet du premier lien — se trompe précisément dans le cas qui a
        // motivé l'écran des liens de parenté : « ajouter un frère à Untel » s'écrit
        // « ce frère est enfant du couple de ses parents », dont le **sujet est le
        // frère**. La file afficherait alors la demande sur la fiche du frère, alors
        // que le contributeur travaillait sur celle d'Untel, et l'administrateur
        // chercherait longtemps.
        //
        // Le contrôle d'appartenance n'est pas décoratif : sans lui, `targetId` serait
        // un champ libre permettant de faire apparaître n'importe quelle demande sur
        // n'importe quelle fiche de la file. Il compte **les époux des couples
        // désignés** autant que les extrémités nommées — sans quoi il interdirait
        // précisément le cas qui l'a motivé, la fratrie n'y citant pas la fiche.
        $annoncee = $this->cibleAnnoncee($corps, $this->personnesTouchees($brut));

        $sujet = $annoncee ?? $premierSujetReel;

        if (null !== $sujet) {
            $cible = $this->arbre->chargerCible(SubjectKind::Personne, $sujet);
            $demande->setTarget(SubjectKind::Personne, $sujet, $cible?->libelle);
        }
    }

    /**
     * `targetId` du corps, **s'il désigne une personne que les liens touchent**.
     *
     * `targetKind` est refusé s'il vaut autre chose que `personne` : un `ajout_lien` ne
     * relie que des personnes, et laisser passer une autre portée écrirait dans la file
     * une cible qu'aucun écran ne sait ouvrir.
     *
     * @param array<string, mixed> $corps
     * @param list<int>            $touchees
     */
    private function cibleAnnoncee(array $corps, array $touchees): ?int
    {
        $kind = $corps['targetKind'] ?? null;

        if (null !== $kind && SubjectKind::Personne->value !== $kind) {
            throw DonneesInvalides::pourChamp('targetKind', 'Un ajout de lien ne vise qu\'une personne.');
        }

        $id = $corps['targetId'] ?? null;

        if (null === $id) {
            return null;
        }

        if (!\is_int($id)) {
            throw DonneesInvalides::pourChamp('targetId', 'L\'identifiant de la cible est un entier.');
        }

        if (!\in_array($id, $touchees, true)) {
            throw DonneesInvalides::pourChamp('targetId', 'La cible annoncée n\'est pas une des personnes que ces liens désignent.');
        }

        return $id;
    }

    /**
     * Les personnes **de l'arbre** que ce corps de liens touche : celles qu'une extrémité
     * désigne, **et les époux des couples désignés**.
     *
     * ── Pourquoi les époux comptent ────────────────────────────────────────────────
     *
     * Parce que le geste le plus courant de l'écran des liens de parenté ne cite pas la
     * fiche sur laquelle il porte. « Ajouter un frère à Untel » s'écrit « ce frère est
     * enfant du couple de ses parents » : le sujet est **le frère**, l'autre extrémité
     * est une `family_id`, et Untel n'apparaît nulle part. Sans cette lecture, annoncer
     * Untel en cible serait refusé — c'est-à-dire que le contrôle interdirait le seul
     * cas pour lequel `targetId` a été ajouté.
     *
     * Lu sur le corps et non sur les entités construites : le contrôle porte sur ce que
     * l'appelant a écrit, et c'est la seule chose dont il soit comptable. Les valeurs
     * qui ne sont pas des entiers sont ignorées ici — elles ont déjà été refusées, une
     * par une, par {@see extremite()} et {@see designerLAutreExtremite()}.
     *
     * @param array<mixed> $liens
     *
     * @return list<int>
     */
    private function personnesTouchees(array $liens): array
    {
        $touchees = [];

        foreach ($liens as $lien) {
            if (!\is_array($lien)) {
                continue;
            }

            foreach (['subjectPersonId', 'otherPersonId', 'other2PersonId'] as $cle) {
                $valeur = $lien[$cle] ?? null;

                if (\is_int($valeur)) {
                    $touchees[] = $valeur;
                }
            }

            $famille = $lien['familyId'] ?? null;

            if (\is_int($famille)) {
                foreach ($this->arbre->epouxDeFamille($famille) as $epoux) {
                    $touchees[] = $epoux;
                }
            }
        }

        return array_values(array_unique($touchees));
    }

    /**
     * Un lien, à son rang. Le rang est **décidé par l'appelant** : lu dans le corps au
     * dépôt, où le client ordonne ses liens ; attribué par le serveur à la complétion, où
     * il ne sait pas lesquels sont pris ({@see liensEnPlus()}).
     *
     * @param array<string, mixed>       $brut
     * @param array<string, DraftPerson> $brouillons indexés par référence provisoire
     */
    private function lien(
        ChangeRequest $demande,
        array $brut,
        int $index,
        int $seq,
        array $brouillons,
        bool $creation,
        Lecteur $lecteur,
    ): DraftLink {
        $chemin = "liens[$index]";

        $kind = $brut['kind'] ?? null;
        $nature = \is_string($kind) ? LinkKind::tryFrom($kind) : null;
        if (null === $nature) {
            throw DonneesInvalides::pourChamp("$chemin.kind", 'Nature de lien inconnue : enfant_de, parent_de ou conjoint_de.');
        }

        [$sujetId, $sujetRef, $sujetLibelle, $sujetBrouillonId] = $this->extremite(
            $brut,
            'subject',
            $chemin,
            $brouillons,
            $creation,
            $demande,
            $lecteur,
            obligatoire: true,
        );

        $lien = new DraftLink($demande, $seq, $nature, $sujetLibelle);

        if (null !== $sujetId) {
            $lien->viserSujetExistant($sujetId);
        } elseif (null !== $sujetBrouillonId) {
            $lien->viserSujetProposeAilleurs($sujetBrouillonId);
        } else {
            $lien->viserSujetBrouillon((string) $sujetRef);
        }

        $this->designerLAutreExtremite($lien, $brut, $chemin, $brouillons, $creation, $nature, $demande, $lecteur);
        $this->porterLesDatesDUnion($lien, $brut, $chemin, $nature);

        $rang = $brut['siblingOrder'] ?? null;
        if (null !== $rang && !\is_int($rang)) {
            throw DonneesInvalides::pourChamp("$chemin.siblingOrder", 'Le rang dans la fratrie est un entier ou null.');
        }
        $lien->setSiblingOrder($rang);
        $lien->setNote($this->texte($brut, 'note', $chemin));

        return $lien;
    }

    /**
     * L'autre extrémité : **soit** un couple existant, **soit** une ou deux personnes.
     * Jamais les deux — `ck_prp_link_famille_filiation` l'impose en base, et « le
     * service ne saurait pas laquelle des deux intentions respecter ».
     *
     * @param array<string, mixed>       $brut
     * @param array<string, DraftPerson> $brouillons indexés par référence provisoire
     */
    private function designerLAutreExtremite(
        DraftLink $lien,
        array $brut,
        string $chemin,
        array $brouillons,
        bool $creation,
        LinkKind $nature,
        ChangeRequest $demande,
        Lecteur $lecteur,
    ): void {
        $familleId = $brut['familyId'] ?? null;

        if (null !== $familleId) {
            if (!\is_int($familleId)) {
                throw DonneesInvalides::pourChamp("$chemin.familyId", 'L\'identifiant de famille est un entier.');
            }

            if (LinkKind::EnfantDe !== $nature) {
                throw DonneesInvalides::pourChamp("$chemin.familyId", 'Seule une filiation peut désigner un couple existant.');
            }

            if (null !== ($brut['otherPersonId'] ?? null)
                || null !== ($brut['otherDraftRef'] ?? null)
                || null !== ($brut['otherDraftPersonId'] ?? null)
            ) {
                throw DonneesInvalides::pourChamp("$chemin.familyId", 'On désigne soit un couple existant, soit un ou deux parents, jamais les deux.');
            }

            $famille = $this->arbre->chargerCible(SubjectKind::Famille, $familleId);

            if (null === $famille) {
                throw Introuvable::cible(SubjectKind::Famille->value, $familleId);
            }

            $lien->designerCoupleExistant($familleId, $famille->libelle);

            return;
        }

        [$autreId, $autreRef, $autreLibelle, $autreBrouillonId] = $this->extremite(
            $brut,
            'other',
            $chemin,
            $brouillons,
            $creation,
            $demande,
            $lecteur,
            obligatoire: true,
        );
        $lien->designerAutre($autreId, $autreRef, $autreLibelle, $autreBrouillonId);

        // Le second parent n'a de sens que pour une filiation
        // (`ck_prp_link_second_parent`).
        if (null === ($brut['other2PersonId'] ?? null)
            && null === ($brut['other2DraftRef'] ?? null)
            && null === ($brut['other2DraftPersonId'] ?? null)
        ) {
            return;
        }

        if (LinkKind::EnfantDe !== $nature) {
            throw DonneesInvalides::pourChamp("$chemin.other2PersonId", 'Un second parent ne se désigne que sur une filiation.');
        }

        [$deuxiemeId, $deuxiemeRef, $deuxiemeLibelle, $deuxiemeBrouillonId] = $this->extremite(
            $brut,
            'other2',
            $chemin,
            $brouillons,
            $creation,
            $demande,
            $lecteur,
            obligatoire: true,
        );
        $lien->designerSecondParent($deuxiemeId, $deuxiemeRef, $deuxiemeLibelle, $deuxiemeBrouillonId);
    }

    /**
     * Une extrémité : **soit** un `*PersonId`, **soit** un `*DraftRef`, jamais les deux
     * (`ck_prp_link_sujet_unique`, `ck_prp_link_cible`).
     *
     * Toute référence réelle est **validée au point d'entrée** par le module
     * propriétaire avant d'entrer en base, et c'est de là que vient le libellé
     * dénormalisé : « aucun identifiant n'entre en base sans être passé par son
     * propriétaire » (ADR 0002, compensation 1).
     *
     * ── Le libellé d'un brouillon est son nom, et il l'est depuis le dépôt ──────────
     *
     * Il a longtemps valu `'Personne proposée '.$reference`, au motif écrit que « le
     * libellé d'un brouillon n'est connu qu'après sa construction ». **C'était faux** :
     * {@see construire()} appelle {@see construireLesBrouillons()} avant
     * {@see construireLesLiens()}, les fiches sont donc toutes bâties quand le premier
     * lien se construit. La seule chose qui manquait, c'est que la première méthode ne
     * rendait que les références et jetait les objets.
     *
     * Le coût était payé à l'écran de validation : une branche de vingt-deux personnes
     * dont les liens ne nommaient personne — pour savoir de qui un enfant était le fils,
     * il fallait relever `#p1` dans le lien et le retrouver à la main dans les
     * brouillons. Le second motif écrit — « la validation le remplacera par le
     * `display_name` réel » — était faux aussi : **rien** ne réécrit ces colonnes, et
     * `ApplicateurDeCreation::libelleDuLien()` les recopie telles quelles dans
     * `prp_revision.subject_label`, où « Personne proposée #p1 » reste pour de bon.
     *
     * Ce que le motif d'origine avait de juste reste vrai et n'est pas contourné : un
     * libellé dénormalisé « n'est jamais une source de vérité ; il sert à l'affichage et
     * au tri, jamais à une décision » (ADR 0002, compensation 3). C'est pourquoi
     * {@see SerialiseurDeDemande} ne s'en contente pas et relit le nom sur la fiche à
     * chaque rendu : les liens déjà en base, eux, portent encore l'ancienne forme.
     *
     * @param array<string, mixed>       $brut
     * @param array<string, DraftPerson> $brouillons indexés par référence provisoire
     *
     * @return array{int|null, string|null, string, int|null} identifiant réel, référence
     *                                                        provisoire, libellé, ligne
     *                                                        `prp_draft_person` d'une
     *                                                        autre demande
     */
    private function extremite(
        array $brut,
        string $prefixe,
        string $chemin,
        array $brouillons,
        bool $creation,
        ChangeRequest $demande,
        Lecteur $lecteur,
        bool $obligatoire,
    ): array {
        $cleId = $prefixe.'PersonId';
        $cleRef = $prefixe.'DraftRef';
        $cleBrouillon = $prefixe.'DraftPersonId';

        $id = $brut[$cleId] ?? null;
        $ref = $brut[$cleRef] ?? null;
        $brouillonId = $brut[$cleBrouillon] ?? null;

        $formes = \count(array_filter([$id, $ref, $brouillonId], static fn (mixed $v): bool => null !== $v));

        if ($formes > 1) {
            throw DonneesInvalides::pourChamp("$chemin.$cleId", 'Une extrémité désigne une personne existante, un brouillon de cette demande ou une fiche proposée ailleurs — une seule des trois.');
        }

        if (null !== $id) {
            if (!\is_int($id)) {
                throw DonneesInvalides::pourChamp("$chemin.$cleId", 'Un identifiant de personne est un entier.');
            }

            $cible = $this->arbre->chargerCible(SubjectKind::Personne, $id);

            if (null === $cible) {
                throw Introuvable::cible(SubjectKind::Personne->value, $id);
            }

            return [$id, null, $cible->libelle, null];
        }

        if (null !== $ref) {
            if (!$creation) {
                throw DonneesInvalides::pourChamp("$chemin.$cleRef", 'Un ajout de lien ne crée aucune fiche : désignez une personne de l\'arbre, ou une fiche proposée par une autre demande.');
            }

            if (!\is_string($ref) || !isset($brouillons[$ref])) {
                throw DonneesInvalides::pourChamp("$chemin.$cleRef", 'Cette référence provisoire ne correspond à aucun brouillon de cette demande.');
            }

            // Le libellé dénormalisé porte le **nom** de la fiche proposée, pas sa
            // référence. Les deux boucles de `construire()` sont ordonnées pour cela :
            // les brouillons sont construits d'abord, et les liens les reçoivent.
            return [null, $ref, $brouillons[$ref]->nomPropose(), null];
        }

        if (null !== $brouillonId) {
            return $this->ficheProposeeAilleurs($brouillonId, "$chemin.$cleBrouillon", $demande, $lecteur);
        }

        if ($obligatoire) {
            throw DonneesInvalides::pourChamp("$chemin.$cleId", 'Cette extrémité est obligatoire.');
        }

        return [null, null, '', null];
    }

    /**
     * Une extrémité qui désigne la fiche proposée par **une autre demande**.
     *
     * Les trois contrôles de l'en-tête, dans l'ordre où ils coûtent le moins cher, et un
     * quatrième cas qui n'est pas un contrôle mais une **résolution** : si la demande
     * citée a été validée entre la recherche et le dépôt, la fiche porte son
     * `resolved_person_id` et le lien désigne désormais la personne réelle. Refuser
     * serait faire échouer une demande que la réalité vient de conforter ; garder la
     * référence au brouillon serait pire — le lien pointerait sur une ligne dont plus
     * rien ne doit être créé.
     *
     * @return array{int|null, string|null, string, int|null}
     */
    private function ficheProposeeAilleurs(
        mixed $brouillonId,
        string $chemin,
        ChangeRequest $demande,
        Lecteur $lecteur,
    ): array {
        if (!\is_int($brouillonId)) {
            throw DonneesInvalides::pourChamp($chemin, 'Un identifiant de fiche proposée est un entier.');
        }

        // `404` et jamais `403` : la fiche proposée d'un autre membre ne doit pas
        // s'annoncer par le code de retour (§ 15.2, § 13.3).
        $brouillon = $this->brouillons->visiblePourLecteur($brouillonId, $this->portee($demande, $lecteur));

        if (null === $brouillon) {
            throw Introuvable::brouillonPropose($brouillonId);
        }

        // Le filet du point 3 de l'en-tête, **atteignable depuis la complétion sur place** :
        // les fiches d'une demande enregistrée ont un identifiant, et un lien ajouté
        // pourrait citer l'une d'elles par lui plutôt que par sa référence. C'est la seule
        // ligne qui empêche deux désignations — et deux résolutions — de la même fiche.
        if ($brouillon->getRequest() === $demande) {
            throw DonneesInvalides::pourChamp($chemin, 'Cette fiche appartient à la demande en cours : désignez-la par sa référence provisoire.');
        }

        $resolue = $brouillon->getResolvedPersonId();

        if (null !== $resolue) {
            $cible = $this->arbre->chargerCible(SubjectKind::Personne, $resolue);

            if (null === $cible) {
                throw Introuvable::cible(SubjectKind::Personne->value, $resolue);
            }

            return [$resolue, null, $cible->libelle, null];
        }

        // Un brouillon non envoyé de l'auteur se désigne comme une demande en attente :
        // la recherche le propose, et la validation attendra qu'il soit envoyé et validé.
        $statut = $brouillon->getRequest()->getStatus();

        if (RequestStatus::EnAttente !== $statut && RequestStatus::Brouillon !== $statut) {
            throw DonneesInvalides::pourChamp($chemin, \sprintf('La demande n° %d n\'est plus en attente : cette fiche ne deviendra pas une personne de l\'arbre.', $brouillon->getRequest()->getId() ?? 0));
        }

        return [null, null, $brouillon->nomPropose(), $brouillonId];
    }

    /**
     * **Les fiches que cette demande a le droit de citer** : celles que voit son **auteur**,
     * pas forcément le lecteur — `null` veut dire « toutes ».
     *
     * Au dépôt, l'auteur **est** le lecteur (la demande porte son identifiant), et la règle
     * reste celle du § 13.3 : ses propres fiches, ou toutes pour un administrateur hors
     * bascule — l'écriture sous bascule est refusée plus haut.
     *
     * À la complétion d'une demande **d'un autre** (§ 6.6 ter), le lecteur est un
     * administrateur : il voit tout, mais la demande ne lui appartient pas. Lui laisser sa
     * propre portée, ce serait le laisser glisser dans la demande d'un membre une fiche que
     * ce membre ne voit pas — et dont la validation dépendrait ensuite. La portée est donc
     * celle de l'auteur, **ses fiches et elles seules**, même si cet auteur est lui-même
     * administrateur : le compte ne dit pas son rôle, et la règle la plus étroite ne fait
     * de tort à personne — l'auteur reste libre d'ajouter ce lien lui-même.
     */
    private function portee(ChangeRequest $demande, Lecteur $lecteur): ?int
    {
        if ($demande->getAuthorUserId() !== $lecteur->userId) {
            return $demande->getAuthorUserId();
        }

        return $lecteur->voitToutesLesDemandes() ? null : $lecteur->identiteEffective();
    }

    /**
     * Les dates d'union ne sont acceptées que sur `conjoint_de`
     * (`ck_prp_link_dates_union`).
     *
     * **Toutes facultatives : une union sans date reste une union** (§ 6.4 du dossier).
     * C'est une règle de fond, pas une tolérance : une grande partie des unions de cet
     * arbre n'a pas de date d'acte, et les exiger reviendrait à interdire de les
     * enregistrer.
     *
     * **La nature de l'union** (`married`, ADR 0020) suit la même règle, et une de plus :
     * un mariage daté ou situé est un mariage. Absente ou `null`, elle vaut « on ne sait
     * pas » — et devient `true` si le lien porte une date ou un lieu de mariage, parce que
     * la déduction est certaine et n'ouvre la visibilité de personne (à la différence du
     * décès, qu'on refuse de cocher à la place de l'auteur). `false` sous une date, c'est
     * une contradiction qu'on ne tranche pas : `422`.
     *
     * @param array<string, mixed> $brut
     */
    private function porterLesDatesDUnion(DraftLink $lien, array $brut, string $chemin, LinkKind $nature): void
    {
        $porteUneDate = null !== ($brut['marriage'] ?? null)
            || null !== ($brut['divorce'] ?? null)
            || true === ($brut['divorced'] ?? null)
            // `false` aussi : « union libre » dit autant qu'une date que le lien est une union.
            || null !== ($brut['married'] ?? null);

        if (LinkKind::ConjointDe !== $nature) {
            if ($porteUneDate) {
                throw DonneesInvalides::pourChamp("$chemin.marriage", 'Seule une union porte des dates de mariage ou de rupture, ou dit si le couple était marié.');
            }

            return;
        }

        $mariage = $this->date($brut, 'marriage', $chemin);
        $marie = $this->booleenOuNul($brut, 'married', $chemin);

        if ($mariage->estRenseignee()) {
            if (false === $marie) {
                throw DonneesInvalides::pourChamp("$chemin.married", 'Une date ou un lieu de mariage attestent le mariage : choisissez « mariés : oui », ou retirez la date et le lieu du mariage.');
            }

            $marie = true;
        }

        $lien->setMarriage($mariage);
        $lien->setMarried($marie);
        $lien->setDivorced($this->booleen($brut, 'divorced', $chemin));
        $lien->setDivorce($this->date($brut, 'divorce', $chemin));
    }

    /** @param array<string, mixed> $source */
    private function texte(array $source, string $cle, string $chemin): ?string
    {
        $valeur = $source[$cle] ?? null;

        if (null === $valeur) {
            return null;
        }

        if (!\is_string($valeur)) {
            throw DonneesInvalides::pourChamp("$chemin.$cle", 'Cette valeur doit être une chaîne ou null.');
        }

        return '' === trim($valeur) ? null : $valeur;
    }

    /** @param array<string, mixed> $source */
    private function booleen(array $source, string $cle, string $chemin): bool
    {
        $valeur = $source[$cle] ?? false;

        if (!\is_bool($valeur)) {
            throw DonneesInvalides::pourChamp("$chemin.$cle", 'Cette valeur doit être true ou false.');
        }

        return $valeur;
    }

    /**
     * Un booléen **tri-état** : `true`, `false`, ou `null` — absent compris — pour « on ne
     * sait pas ». La nature d'une union seulement (ADR 0020).
     *
     * @param array<string, mixed> $source
     */
    private function booleenOuNul(array $source, string $cle, string $chemin): ?bool
    {
        $valeur = $source[$cle] ?? null;

        if (null !== $valeur && !\is_bool($valeur)) {
            throw DonneesInvalides::pourChamp("$chemin.$cle", 'Cette valeur doit être true, false ou null.');
        }

        return $valeur;
    }

    /** @param array<string, mixed> $source */
    private function sexe(array $source, string $chemin): Sex
    {
        $valeur = $source['sex'] ?? null;

        if (null === $valeur) {
            return Sex::Inconnu;
        }

        $sexe = \is_string($valeur) ? Sex::tryFrom($valeur) : null;

        if (null === $sexe) {
            throw DonneesInvalides::pourChamp("$chemin.sex", 'Sexe inconnu : masculin, feminin ou inconnu.');
        }

        return $sexe;
    }

    /**
     * Un bloc date passe par le normaliseur : **cinq clés, toujours les cinq**, `texte`
     * refusé en entrée, et les deux `CHECK` sur les qualificatifs appliqués avant la
     * base.
     *
     * @param array<string, mixed> $source
     */
    private function date(array $source, string $cle, string $chemin): UncertainDate
    {
        $valeur = $source[$cle] ?? null;

        if (null === $valeur) {
            return UncertainDate::inconnue();
        }

        $date = $this->normaliseur->versPhp(ValueKind::DateIncertaine, $valeur, "$chemin.$cle");

        return $date instanceof UncertainDate
            ? $date
            : UncertainDate::inconnue();
    }
}

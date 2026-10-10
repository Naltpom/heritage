<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftLink;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Enum\LinkKind;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Enum\ValueKind;
use App\Module\Proposal\Exception\AccesRefuse;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Repository\DraftLinkRepository;
use App\Module\Proposal\Repository\DraftPersonRepository;
use App\Module\Proposal\Service\Port\NotificateurDeDemandes;
use App\Module\Proposal\Service\Port\PortMedias;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * FUSIONNER UN COMPLÉMENT DANS LA PROPOSITION QU'IL COMPLÈTE — § 6.7 bis, ADR 0017.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Un membre a reconnu, dans la proposition d'un autre, la personne qu'il allait saisir,
 * et a déposé un **complément** (§ 6.3 ter) : sa fiche, ses liens, ses pièces. Ce service
 * les verse dans l'origine **en un clic**, et clôt le complément — sans refus sec : son
 * auteur apprend que son travail est repris.
 *
 * ── La règle des champs : l'origine l'emporte, le complément remplit ────────────
 *
 * | Champ | L'origine est vide | Les deux disent la même chose | Ils divergent |
 * |---|---|---|---|
 * | `givenNames`, `surname` (vide = `''`) | rempli | rien | conflit |
 * | `marriedName`, `nickname` (vide = `null`) | rempli | rien | conflit |
 * | `sex` (vide = `inconnu`) | rempli | rien | conflit |
 * | `deceased` | `false` → `true` recopié | rien | **jamais** : « décédé » ne se contredit pas, il s'ignore |
 * | `birth`, `death` | date inconnue → bloc recopié ; **lieu vide, date connue → lieu seul recopié** | rien | conflit (sur le bloc entier) |
 * | `note` | **jamais en conflit** : celle du complément s'ajoute, sous « — Complément (demande n° C) : … », avec le message de son auteur |
 *
 * **Un conflit se tranche en faveur de l'origine**, sauf `retenir[champ] = 'complement'`,
 * qui prend la valeur du complément — un bloc date entier, jamais une moitié. `retenir` sur
 * un champ **sans** conflit est refusé en `422` : l'administrateur croirait avoir décidé
 * quelque chose que la fusion n'aurait pas fait. Un décès recopié — rempli ou retenu — pose
 * `deceased` : la contrainte `ck_tree_person_deces_coherent` le refuserait sinon à la
 * validation, et c'est le seul champ que la fusion coche d'elle-même, parce qu'une date de
 * décès que l'administration a acceptée vaut décès.
 *
 * Les dates se comparent **par leur qualificatif et leurs bornes**, jamais par leur `raw` :
 * « 1901 » et « en 1901 » sont la même année, et un conflit sur l'orthographe d'une date
 * enverrait l'administrateur trancher un faux désaccord.
 *
 * ── Les liens ───────────────────────────────────────────────────────────────────
 *
 * Chaque lien du complément est **traduit** : la référence de sa fiche (`#p1` du
 * complément) devient celle de la fiche d'origine (`#p2` de l'origine), et il s'ajoute à
 * l'origine au rang suivant (`uniq_prp_draft_link_seq`). Trois cas l'écartent, et le
 * rapport dit lequel :
 *
 * - `deja_present` — même nature, mêmes extrémités (dans les deux sens pour une union ou
 *   pour les deux parents d'une filiation) : l'origine le porte déjà ;
 * - `fiche_proposee_ailleurs` — il désigne une fiche d'**une autre demande** par
 *   l'identifiant de sa ligne. L'origine n'a pas forcément le droit de la citer : un membre
 *   ne cite que ses propres fiches (§ 6.3 bis), et la fusion n'est pas un moyen de faire
 *   entrer dans la demande de l'un une fiche de l'autre ;
 * - `meme_personne` — une fois traduit, il relierait la personne à elle-même.
 *
 * ── Les pièces, les demandes qui citaient le complément, la clôture ─────────────
 *
 * - les **médias** joints à la fiche du complément passent sur la fiche d'origine
 *   ({@see PortMedias::faireSuivreLesMedias()}) : ils suivront la personne à la validation,
 *   avec les autres ;
 * - les **demandes non tranchées** qui citaient la fiche du complément par sa ligne la
 *   désignent désormais par celle de l'origine, et sont **touchées** (`ETag` du graphe) ;
 * - l'**origine** reçoit sa nouvelle clé de doublon et est touchée ;
 * - le **complément** est clos `refusee`, `duplicateOf` = l'origine, motif « Fusionné dans
 *   la demande n° O. » — directement, sans {@see RefusDeDemande}, qui enverrait à son auteur
 *   « votre demande a été refusée » quand son travail vient d'être repris.
 *
 * Tout cela **en une transaction** : une fusion à moitié faite laisserait des liens
 * ajoutés à une origine dont le complément resterait ouvert — et une seconde fusion les
 * ajouterait deux fois. Les notifications partent **après** le `commit`.
 *
 * ── L'aperçu ────────────────────────────────────────────────────────────────────
 *
 * {@see apercu()} calcule **exactement** le même rapport sans rien écrire : c'est ce que
 * l'administrateur lit avant de cliquer, sur la vue de l'origine (`complements[].apercu`)
 * et par la route `GET`. Un seul calcul pour les deux, sans quoi l'écran annoncerait une
 * fusion et le clic en ferait une autre.
 */
final readonly class FusionDeComplement
{
    public const string RETENIR_ORIGINE = 'origine';
    public const string RETENIR_COMPLEMENT = 'complement';

    public const string RAISON_DEJA_PRESENT = 'deja_present';
    public const string RAISON_FICHE_PROPOSEE_AILLEURS = 'fiche_proposee_ailleurs';
    public const string RAISON_MEME_PERSONNE = 'meme_personne';

    /** Les champs qui peuvent être en conflit — `deceased` et `note` ne le sont jamais. */
    private const array CHAMPS_EN_CONFLIT_POSSIBLE = [
        'givenNames', 'surname', 'marriedName', 'nickname', 'sex', 'birth', 'death',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private DraftPersonRepository $brouillons,
        private DraftLinkRepository $liens,
        private ConstructeurDeCreation $constructeur,
        private EmpreinteDeDemande $empreintes,
        private PortMedias $medias,
        private NotificateurDeDemandes $notificateur,
        private NormaliseurDeValeur $normaliseur,
        private SurfaceEditable $surface,
    ) {
    }

    /**
     * Ce que la fusion **ferait**, sans rien écrire (`GET …/fusionner/{complementId}`).
     *
     * Les mêmes préconditions que la fusion — les deux demandes en attente, le complément
     * rattaché à cette origine —, pour que l'écran apprenne avant le clic ce que le clic
     * lui répondrait. Pas l'empreinte : il n'y a rien à protéger, rien n'est écrit.
     *
     * @return array<string, mixed> le rapport du § 6.7 bis
     */
    public function apercu(ChangeRequest $origine, ChangeRequest $complement): array
    {
        [$ficheOrigine, $ficheComplement] = $this->verifierLeCouple($origine, $complement);

        return $this->calculer($origine, $complement, $ficheOrigine, $ficheComplement, [])['rapport'];
    }

    /**
     * L'aperçu de la vue d'administration (`complements[].apercu`, § 6.7) : `null` quand la
     * fusion n'est pas possible — complément ou origine tranchés —, plutôt qu'une exception
     * qui ferait tomber l'écran entier pour un complément d'il y a six mois.
     *
     * @return array<string, mixed>|null
     */
    public function apercuSiPossible(ChangeRequest $origine, ChangeRequest $complement): ?array
    {
        try {
            return $this->apercu($origine, $complement);
        } catch (Conflit|DonneesInvalides) {
            return null;
        }
    }

    /**
     * Fusionner (`POST …/fusionner/{complementId}`), corps
     * `{"retenir": {"<champ>": "origine"|"complement"}, "empreinte": "…", "empreinteComplement": "…"}`.
     *
     * @param array<string, mixed> $corps
     *
     * @return array<string, mixed> le rapport de ce qui a été fait
     */
    public function fusionner(ChangeRequest $origine, ChangeRequest $complement, array $corps, Lecteur $lecteur): array
    {
        DepotDeDemande::verifierQuIlPeutEcrire($lecteur);

        // Le contrôleur exige déjà le rôle (voter `DECIDER`) ; ce contrôle est le filet du
        // jour où une route l'oublierait. Une fusion clôt la demande d'un membre : c'est
        // une décision, et seule l'administration en prend.
        if (!$lecteur->administrateur) {
            throw AccesRefuse::demandeDAutrui();
        }

        [$ficheOrigine, $ficheComplement] = $this->verifierLeCouple($origine, $complement);

        $this->empreintes->exiger($origine, $corps['empreinte'] ?? null);
        $this->empreintes->exiger($complement, $corps['empreinteComplement'] ?? null, 'empreinteComplement');

        $retenir = $this->lireRetenir($corps['retenir'] ?? null);
        $calcul = $this->calculer($origine, $complement, $ficheOrigine, $ficheComplement, $retenir);

        $this->em->wrapInTransaction(function () use ($origine, $complement, $ficheOrigine, $ficheComplement, $calcul, $lecteur): void {
            $this->ecrire($origine, $complement, $ficheOrigine, $ficheComplement, $calcul, $lecteur);
        });

        $this->notifier($origine, $complement, $calcul, $lecteur);

        return $calcul['rapport'];
    }

    /**
     * Les préconditions communes à l'aperçu et à la fusion, dans l'ordre du contrat :
     * les deux demandes **en attente** (`409 demande_deja_decidee`), puis le complément
     * **rattaché à cette origine** (`422`).
     *
     * @return array{DraftPerson, DraftPerson} la fiche d'origine, celle du complément
     */
    private function verifierLeCouple(ChangeRequest $origine, ChangeRequest $complement): array
    {
        foreach ([$origine, $complement] as $demande) {
            if (RequestStatus::EnAttente !== $demande->getStatus()) {
                throw Conflit::dejaDecidee($demande->getStatus());
            }
        }

        $ficheOrigine = $complement->getComplementDe();

        if (null === $ficheOrigine || $ficheOrigine->getRequest() !== $origine || $complement === $origine) {
            throw DonneesInvalides::pourChamp('complementId', \sprintf('Cette demande ne complète pas la proposition n° %d.', $origine->getId() ?? 0));
        }

        // Un complément porte une fiche et une seule (§ 6.3 ter) : la complétion sur place
        // ajoute des liens, jamais de fiche. Le repli est un filet, pas un cas.
        $fiches = $this->brouillons->pourDemande($complement);

        if (1 !== \count($fiches)) {
            throw DonneesInvalides::pourChamp('complementId', 'Un complément décrit une seule personne : celui-ci ne peut pas être fusionné.');
        }

        return [$ficheOrigine, $fiches[0]];
    }

    /**
     * `retenir` : un objet `{champ: "origine"|"complement"}`, ou rien. La forme seule est
     * jugée ici ; qu'un champ soit réellement en conflit se juge dans {@see calculer()}.
     *
     * @return array<string, string>
     */
    private function lireRetenir(mixed $brut): array
    {
        if (null === $brut) {
            return [];
        }

        if (!\is_array($brut)) {
            throw DonneesInvalides::pourChamp('retenir', 'Les choix forment un objet : {"<champ>": "origine" ou "complement"}.');
        }

        $retenir = [];

        foreach ($brut as $champ => $choix) {
            if (!\is_string($champ)) {
                throw DonneesInvalides::pourChamp('retenir', 'Les choix forment un objet : {"<champ>": "origine" ou "complement"}.');
            }

            if (self::RETENIR_ORIGINE !== $choix && self::RETENIR_COMPLEMENT !== $choix) {
                throw DonneesInvalides::pourChamp("retenir.$champ", 'On retient « origine » ou « complement ».');
            }

            $retenir[$champ] = $choix;
        }

        return $retenir;
    }

    /**
     * **Le calcul entier, sans écriture** — partagé par l'aperçu et la fusion.
     *
     * @param array<string, string> $retenir
     *
     * @return array{
     *     valeurs: array{givenNames: string, surname: string, marriedName: string|null, nickname: string|null, sex: Sex, deceased: bool, birth: UncertainDate, death: UncertainDate, note: string|null},
     *     liens: list<array{seqComplement: int, seq: int, source: DraftLink, sujet: array{personId: int|null, ref: string|null, label: string}, autre: array{personId: int|null, ref: string|null, label: string}|null, autre2: array{personId: int|null, ref: string|null, label: string}|null}>,
     *     repointes: list<DraftLink>,
     *     libellesModifies: list<string>,
     *     rapport: array<string, mixed>,
     * }
     */
    private function calculer(
        ChangeRequest $origine,
        ChangeRequest $complement,
        DraftPerson $ficheOrigine,
        DraftPerson $ficheComplement,
        array $retenir,
    ): array {
        $avant = $this->valeursDe($ficheOrigine);
        $leur = $this->valeursDe($ficheComplement);
        $valeurs = $avant;
        $remplis = [];
        $conflits = [];

        foreach (self::CHAMPS_EN_CONFLIT_POSSIBLE as $champ) {
            [$fusionnee, $enConflit] = $this->fusionnerLeChamp($champ, $avant[$champ], $leur[$champ]);

            if ($enConflit) {
                $retenu = $retenir[$champ] ?? self::RETENIR_ORIGINE;
                $conflits[$champ] = $retenu;

                if (self::RETENIR_COMPLEMENT === $retenu) {
                    $valeurs[$champ] = $leur[$champ];
                }

                continue;
            }

            if (null !== $fusionnee) {
                $valeurs[$champ] = $fusionnee;
                $remplis[] = $champ;
            }
        }

        // `retenir` sur un champ sans conflit : l'administrateur croirait avoir tranché
        // quelque chose que la fusion ne ferait pas. Refusé plutôt qu'ignoré.
        foreach (array_keys($retenir) as $champ) {
            if (!isset($conflits[$champ])) {
                throw DonneesInvalides::pourChamp("retenir.$champ", 'Ce champ n\'est pas en conflit entre les deux fiches : il n\'y a rien à trancher.');
            }
        }

        // `deceased` : faux → vrai se recopie, et une **date** de décès recopiée — remplie ou
        // retenue — le pose aussi. Une date, pas un lieu seul : c'est la règle du dépôt
        // (`ConstructeurDeCreation::lireLaFiche()`) et celle de `ck_tree_person_deces_coherent`,
        // et cocher « décédé » ouvre la visibilité (ADR 0003) — on ne le fait pas sur moins
        // que ce que le dépôt exige. Jamais l'inverse : « vivant » n'est pas une affirmation
        // qu'un complément puisse opposer à un décès connu.
        $deces = $valeurs['death'];
        $decesRecopie = (\in_array('death', $remplis, true) || self::RETENIR_COMPLEMENT === ($conflits['death'] ?? null))
            && (null !== $deces->getRaw() || null !== $deces->getMin() || null !== $deces->getMax());

        if (!$valeurs['deceased'] && ($leur['deceased'] || $decesRecopie)) {
            $valeurs['deceased'] = true;
            $remplis[] = 'deceased';
        }

        $note = $this->noteFusionnee($avant['note'], $ficheComplement, $complement);

        if (null !== $note) {
            $valeurs['note'] = $note;
            $remplis[] = 'note';
        }

        $liens = $this->traduireLesLiens($origine, $complement, $ficheOrigine, $ficheComplement);
        $repointes = $this->aRepointer($ficheComplement, $complement);
        $medias = \count($this->medias->mediasDeBrouillon($complement->getId() ?? 0, $ficheComplement->getLocalRef()));

        $champsOrdonnes = array_values(array_filter(
            ConstructeurDeCreation::CHAMPS_DE_FICHE,
            static fn (string $champ): bool => \in_array($champ, $remplis, true)
                || self::RETENIR_COMPLEMENT === ($conflits[$champ] ?? null),
        ));
        $libelles = array_map($this->surface->libelle(...), $champsOrdonnes);

        if ($medias > 0) {
            $libelles[] = 'Pièces jointes';
        }

        $demandesRepointees = [];

        foreach ($repointes as $lien) {
            $demandesRepointees[$lien->getRequest()->getId() ?? 0] = true;
        }

        return [
            'valeurs' => $valeurs,
            'liens' => $liens['ajoutes'],
            'repointes' => $repointes,
            'libellesModifies' => $libelles,
            'rapport' => [
                'remplis' => array_map(
                    fn (string $champ): array => ['champ' => $champ, 'valeur' => $this->affichage($valeurs[$champ])],
                    array_values(array_filter(
                        ConstructeurDeCreation::CHAMPS_DE_FICHE,
                        static fn (string $champ): bool => \in_array($champ, $remplis, true),
                    )),
                ),
                'conflits' => array_map(
                    fn (string $champ): array => [
                        'champ' => $champ,
                        'origine' => $this->affichage($avant[$champ]),
                        'complement' => $this->affichage($leur[$champ]),
                        'retenu' => $conflits[$champ],
                    ],
                    array_keys($conflits),
                ),
                'liensAjoutes' => array_map(
                    static fn (array $lien): array => ['seqComplement' => $lien['seqComplement'], 'seq' => $lien['seq']],
                    $liens['ajoutes'],
                ),
                'liensIgnores' => $liens['ignores'],
                'mediasDeplaces' => $medias,
                'demandesRepointees' => array_keys($demandesRepointees),
            ],
        ];
    }

    /**
     * Les champs d'une fiche, sous la forme de {@see ConstructeurDeCreation::lireLaFiche()}.
     *
     * @return array{givenNames: string, surname: string, marriedName: string|null, nickname: string|null, sex: Sex, deceased: bool, birth: UncertainDate, death: UncertainDate, note: string|null}
     */
    private function valeursDe(DraftPerson $fiche): array
    {
        return [
            'givenNames' => $fiche->getGivenNames(),
            'surname' => $fiche->getSurname(),
            'marriedName' => $fiche->getMarriedName(),
            'nickname' => $fiche->getNickname(),
            'sex' => $fiche->getSex(),
            'deceased' => $fiche->isDeceased(),
            'birth' => $fiche->getBirth(),
            'death' => $fiche->getDeath(),
            'note' => $fiche->getNote(),
        ];
    }

    /**
     * Un champ de l'origine face au même champ du complément.
     *
     * @return array{mixed, bool} la valeur **remplie** (ou `null` si rien ne change), et
     *                            vrai si les deux divergent
     */
    private function fusionnerLeChamp(string $champ, mixed $origine, mixed $complement): array
    {
        if ($origine instanceof UncertainDate && $complement instanceof UncertainDate) {
            return $this->fusionnerLaDate($origine, $complement);
        }

        if ($origine instanceof Sex && $complement instanceof Sex) {
            return match (true) {
                Sex::Inconnu === $complement, $origine === $complement => [null, false],
                Sex::Inconnu === $origine => [$complement, false],
                default => [null, true],
            };
        }

        // Les quatre textes : `''` pour les noms, `null` pour les deux autres — le même
        // « vide », comparé après `trim`, comme le dépôt le lit.
        $o = trim((string) $origine);
        $c = trim((string) $complement);

        return match (true) {
            '' === $c, $o === $c => [null, false],
            '' === $o => [\in_array($champ, ['givenNames', 'surname'], true) ? (string) $complement : $complement, false],
            default => [null, true],
        };
    }

    /**
     * Deux blocs date : la **date** (qualificatif et bornes) et le **lieu** se fusionnent
     * chacun de leur côté, et le moindre désaccord fait du bloc entier un conflit.
     *
     * - date inconnue d'un côté → l'autre l'emporte ; égales → rien ; différentes → conflit ;
     * - lieu vide d'un côté → l'autre l'emporte ; égaux → rien ; différents → conflit.
     *
     * D'où les deux cas du contrat : une date d'origine **inconnue** reçoit le bloc du
     * complément entier, et une date **connue sans lieu** reçoit le lieu seul.
     *
     * @return array{UncertainDate|null, bool}
     */
    private function fusionnerLaDate(UncertainDate $origine, UncertainDate $complement): array
    {
        $date = match (true) {
            !$complement->isKnown(), self::memeDate($origine, $complement) => $origine,
            !$origine->isKnown() => $complement,
            default => null,
        };

        $lieuOrigine = self::lieu($origine);
        $lieuComplement = self::lieu($complement);

        $lieu = match (true) {
            '' === $lieuComplement, $lieuOrigine === $lieuComplement => $origine->getPlace(),
            '' === $lieuOrigine => $complement->getPlace(),
            default => false,
        };

        if (null === $date || false === $lieu) {
            return [null, true];
        }

        $fusionnee = new UncertainDate($date->getRaw(), $date->getQualifier(), $date->getMin(), $date->getMax(), $lieu);

        $inchangee = $this->normaliseur->versJson(ValueKind::DateIncertaine, $fusionnee)
            === $this->normaliseur->versJson(ValueKind::DateIncertaine, $origine);

        return [$inchangee ? null : $fusionnee, false];
    }

    private static function memeDate(UncertainDate $a, UncertainDate $b): bool
    {
        return $a->getQualifier() === $b->getQualifier()
            && $a->getMin()?->format('Y-m-d') === $b->getMin()?->format('Y-m-d')
            && $a->getMax()?->format('Y-m-d') === $b->getMax()?->format('Y-m-d');
    }

    private static function lieu(UncertainDate $date): string
    {
        return trim($date->getPlace() ?? '');
    }

    /**
     * La note de l'origine, suivie de ce que le complément apporte — sa note de fiche et le
     * mot de son auteur —, ou `null` s'il n'apporte rien.
     *
     * **Jamais un conflit** : deux notes ne se contredisent pas, elles s'ajoutent. Le
     * complément est désigné par son numéro, pas par son auteur : la note se lit par
     * l'auteur de l'origine, à qui la fusion ne nomme pas le contributeur.
     */
    private function noteFusionnee(?string $noteOrigine, DraftPerson $ficheComplement, ChangeRequest $complement): ?string
    {
        $apport = implode("\n", array_values(array_filter(
            [trim($ficheComplement->getNote() ?? ''), trim($complement->getMessage() ?? '')],
            static fn (string $morceau): bool => '' !== $morceau,
        )));

        if ('' === $apport) {
            return null;
        }

        $section = \sprintf('— Complément (demande n° %d) : %s', $complement->getId() ?? 0, $apport);
        $origine = trim($noteOrigine ?? '');

        return '' === $origine ? $section : $origine."\n\n".$section;
    }

    /**
     * Les liens du complément, **traduits** vers l'origine, et ceux qui ne passent pas.
     *
     * @return array{
     *     ajoutes: list<array{seqComplement: int, seq: int, source: DraftLink, sujet: array{personId: int|null, ref: string|null, label: string}, autre: array{personId: int|null, ref: string|null, label: string}|null, autre2: array{personId: int|null, ref: string|null, label: string}|null}>,
     *     ignores: list<array{seqComplement: int, raison: string}>,
     * }
     */
    private function traduireLesLiens(
        ChangeRequest $origine,
        ChangeRequest $complement,
        DraftPerson $ficheOrigine,
        DraftPerson $ficheComplement,
    ): array {
        $deja = [];

        foreach ($this->liens->pourDemande($origine) as $lien) {
            $deja[self::cleDuLien(
                $lien->getKind(),
                self::cleDExtremite($lien->getSubjectPersonId(), $lien->getSubjectDraftRef(), $lien->getSubjectDraftPersonId()),
                self::cleDExtremite($lien->getOtherPersonId(), $lien->getOtherDraftRef(), $lien->getOtherDraftPersonId()),
                self::cleDExtremite($lien->getOther2PersonId(), $lien->getOther2DraftRef(), $lien->getOther2DraftPersonId()),
                $lien->getFamilyId(),
            )] = true;
        }

        $rang = $this->liens->plusGrandRang($origine);
        $ajoutes = [];
        $ignores = [];

        foreach ($this->liens->pourDemande($complement) as $lien) {
            $sujet = $this->traduire($lien->getSubjectPersonId(), $lien->getSubjectDraftRef(), $lien->getSubjectDraftPersonId(), $lien->getSubjectLabel(), $origine, $ficheOrigine, $ficheComplement);
            $autre = $this->traduire($lien->getOtherPersonId(), $lien->getOtherDraftRef(), $lien->getOtherDraftPersonId(), $lien->getOtherLabel(), $origine, $ficheOrigine, $ficheComplement);
            $autre2 = $this->traduire($lien->getOther2PersonId(), $lien->getOther2DraftRef(), $lien->getOther2DraftPersonId(), $lien->getOther2Label(), $origine, $ficheOrigine, $ficheComplement);

            if (false === $sujet || false === $autre || false === $autre2 || null === $sujet) {
                $ignores[] = ['seqComplement' => $lien->getSeq(), 'raison' => self::RAISON_FICHE_PROPOSEE_AILLEURS];

                continue;
            }

            $extremites = array_filter([$sujet, $autre, $autre2]);
            $cles = array_map(static fn (array $e): string => self::cleDExtremite($e['personId'], $e['ref'], null), $extremites);

            if (\count($cles) !== \count(array_unique($cles))) {
                $ignores[] = ['seqComplement' => $lien->getSeq(), 'raison' => self::RAISON_MEME_PERSONNE];

                continue;
            }

            $cle = self::cleDuLien(
                $lien->getKind(),
                self::cleDExtremite($sujet['personId'], $sujet['ref'], null),
                null === $autre ? '' : self::cleDExtremite($autre['personId'], $autre['ref'], null),
                null === $autre2 ? '' : self::cleDExtremite($autre2['personId'], $autre2['ref'], null),
                $lien->getFamilyId(),
            );

            if (isset($deja[$cle])) {
                $ignores[] = ['seqComplement' => $lien->getSeq(), 'raison' => self::RAISON_DEJA_PRESENT];

                continue;
            }

            $deja[$cle] = true;
            $ajoutes[] = [
                'seqComplement' => $lien->getSeq(),
                'seq' => ++$rang,
                'source' => $lien,
                'sujet' => $sujet,
                'autre' => $autre,
                'autre2' => $autre2,
            ];
        }

        return ['ajoutes' => $ajoutes, 'ignores' => $ignores];
    }

    /**
     * Une extrémité du complément, dite dans les termes de l'origine.
     *
     * @return array{personId: int|null, ref: string|null, label: string}|false|null `null`
     *                                                                                 pour une
     *                                                                                 extrémité
     *                                                                                 vide, `false`
     *                                                                                 pour une
     *                                                                                 fiche
     *                                                                                 proposée
     *                                                                                 ailleurs
     */
    private function traduire(
        ?int $personId,
        ?string $ref,
        ?int $ligne,
        ?string $libelle,
        ChangeRequest $origine,
        DraftPerson $ficheOrigine,
        DraftPerson $ficheComplement,
    ): array|false|null {
        if (null !== $personId) {
            return ['personId' => $personId, 'ref' => null, 'label' => $libelle ?? ''];
        }

        if (null !== $ref) {
            // Dans le complément, une référence ne peut désigner que sa fiche unique.
            return $ref === $ficheComplement->getLocalRef()
                ? ['personId' => null, 'ref' => $ficheOrigine->getLocalRef(), 'label' => $ficheOrigine->nomPropose()]
                : false;
        }

        if (null === $ligne) {
            return null;
        }

        // Une fiche **de l'origine** citée par sa ligne — un complément d'administrateur
        // peut le faire — se désigne, une fois chez elle, par sa référence.
        $citee = $this->brouillons->find($ligne);

        if (null !== $citee && $citee->getRequest() === $origine) {
            return ['personId' => null, 'ref' => $citee->getLocalRef(), 'label' => $citee->nomPropose()];
        }

        return false;
    }

    private static function cleDExtremite(?int $personId, ?string $ref, ?int $ligne): string
    {
        return match (true) {
            null !== $personId => 'p:'.$personId,
            null !== $ref => 'r:'.$ref,
            null !== $ligne => 'd:'.$ligne,
            default => '',
        };
    }

    /**
     * La clé d'un lien, pour reconnaître un doublon : même nature, mêmes extrémités — **sans
     * ordre** quand l'ordre ne veut rien dire, les deux époux d'une union, les deux parents
     * d'une filiation.
     */
    private static function cleDuLien(LinkKind $kind, string $sujet, string $autre, string $autre2, ?int $famille): string
    {
        if (LinkKind::ConjointDe === $kind) {
            $epoux = [$sujet, $autre];
            sort($epoux);

            return $kind->value.'|'.implode('+', $epoux);
        }

        if (LinkKind::EnfantDe === $kind) {
            if (null !== $famille) {
                return $kind->value.'|'.$sujet.'|f:'.$famille;
            }

            $parents = array_values(array_filter([$autre, $autre2], static fn (string $p): bool => '' !== $p));
            sort($parents);

            return $kind->value.'|'.$sujet.'|'.implode('+', $parents);
        }

        return $kind->value.'|'.$sujet.'|'.$autre;
    }

    /**
     * Les liens des **autres** demandes non tranchées qui citent la fiche du complément par
     * l'identifiant de sa ligne — ceux que la fusion repointe sur l'origine. Les liens du
     * complément lui-même ne bougent pas : il reste dans l'historique tel qu'il a été
     * déposé.
     *
     * @return list<DraftLink>
     */
    private function aRepointer(DraftPerson $ficheComplement, ChangeRequest $complement): array
    {
        return array_values(array_filter(
            $this->liens->citant($ficheComplement),
            static fn (DraftLink $lien): bool => $lien->getRequest() !== $complement,
        ));
    }

    /**
     * Le corps de la transaction : la fiche, les liens, les pièces, les citations, la
     * clôture. Dans cet ordre, et rien d'autre.
     *
     * @param array{valeurs: array{givenNames: string, surname: string, marriedName: string|null, nickname: string|null, sex: Sex, deceased: bool, birth: UncertainDate, death: UncertainDate, note: string|null}, liens: list<array{seqComplement: int, seq: int, source: DraftLink, sujet: array{personId: int|null, ref: string|null, label: string}, autre: array{personId: int|null, ref: string|null, label: string}|null, autre2: array{personId: int|null, ref: string|null, label: string}|null}>, repointes: list<DraftLink>, libellesModifies: list<string>, rapport: array<string, mixed>} $calcul
     */
    private function ecrire(
        ChangeRequest $origine,
        ChangeRequest $complement,
        DraftPerson $ficheOrigine,
        DraftPerson $ficheComplement,
        array $calcul,
        Lecteur $lecteur,
    ): void {
        // La fiche d'origine, **en place** — elle garde son identifiant, que d'autres
        // demandes citent sans clé étrangère.
        $this->constructeur->reecrireLaFiche($ficheOrigine, $calcul['valeurs']);

        foreach ($calcul['liens'] as $prevu) {
            $this->em->persist($this->lienTraduit($origine, $ficheOrigine, $prevu));
        }

        // Le nom de la fiche d'origine a pu changer (un prénom rempli) : les liens qui la
        // citent le reprennent, sous la même règle qu'après une complétion sur place.
        LiensDUneFiche::rafraichirLesLibelles($this->liens->citant($ficheOrigine), $ficheOrigine);

        $this->medias->faireSuivreLesMedias(
            $complement->getId() ?? 0,
            $ficheComplement->getLocalRef(),
            $origine->getId() ?? 0,
            $ficheOrigine->getLocalRef(),
        );

        foreach ($calcul['repointes'] as $lien) {
            if (LiensDUneFiche::repointer($lien, $ficheComplement, $ficheOrigine)) {
                $lien->getRequest()->toucher();
            }
        }

        $this->constructeur->poserLaCleDeDoublon($origine);
        $origine->toucher();

        // Clos **directement**, pas par `RefusDeDemande` : celui-ci enverrait « votre
        // demande a été refusée » à un auteur dont le travail vient d'être repris. Le
        // statut `refusee` est ce qui sort le complément de la file ; `duplicateOf` et le
        // motif disent où il est allé — « votre proposition rejoint la demande n° 12 »,
        // exactement le sens que `duplicate_of_id` porte depuis le premier jour.
        $complement->decider(
            RequestStatus::Refusee,
            $lecteur->userId,
            $lecteur->libelle,
            \sprintf('Fusionné dans la demande n° %d.', $origine->getId() ?? 0),
        );
        $complement->setDuplicateOf($origine);
    }

    /**
     * Le lien du complément, recréé sur l'origine à son nouveau rang.
     *
     * @param array{seqComplement: int, seq: int, source: DraftLink, sujet: array{personId: int|null, ref: string|null, label: string}, autre: array{personId: int|null, ref: string|null, label: string}|null, autre2: array{personId: int|null, ref: string|null, label: string}|null} $prevu
     */
    private function lienTraduit(ChangeRequest $origine, DraftPerson $ficheOrigine, array $prevu): DraftLink
    {
        $source = $prevu['source'];
        $sujet = $prevu['sujet'];

        // Le libellé d'une extrémité qui désigne la fiche d'origine prend son nom **après**
        // la fusion : un prénom rempli par le complément doit s'y lire.
        $nom = static fn (array $extremite): string => null !== $extremite['ref'] && $extremite['ref'] === $ficheOrigine->getLocalRef()
            ? $ficheOrigine->nomPropose()
            : $extremite['label'];

        $lien = new DraftLink($origine, $prevu['seq'], $source->getKind(), $nom($sujet));

        if (null !== $sujet['personId']) {
            $lien->viserSujetExistant($sujet['personId']);
        } else {
            $lien->viserSujetBrouillon((string) $sujet['ref']);
        }

        $famille = $source->getFamilyId();

        if (null !== $famille) {
            $lien->designerCoupleExistant($famille, $source->getFamilyLabel());
        } elseif (null !== $prevu['autre']) {
            $lien->designerAutre($prevu['autre']['personId'], $prevu['autre']['ref'], $nom($prevu['autre']));

            if (null !== $prevu['autre2']) {
                $lien->designerSecondParent($prevu['autre2']['personId'], $prevu['autre2']['ref'], $nom($prevu['autre2']));
            }
        }

        if (LinkKind::ConjointDe === $source->getKind()) {
            $lien->setMarriage($source->getMarriage());
            $lien->setDivorced($source->isDivorced());
            $lien->setDivorce($source->getDivorce());
        }

        $lien->setSiblingOrder($source->getSiblingOrder());
        $lien->setNote($source->getNote());

        return $lien;
    }

    /**
     * Après le `commit`, jamais dedans.
     *
     * - **l'auteur du complément** apprend que son travail est repris — sauf s'il est
     *   l'administrateur qui vient de fusionner (le notificateur le sait : le complément
     *   porte son décideur) ;
     * - **l'auteur de l'origine** apprend que l'administration a complété sa demande, avec
     *   le nom des champs touchés et jamais celui du contributeur — sauf s'il est
     *   l'administrateur qui vient de cliquer, ou si la fusion n'a rien changé chez lui.
     *
     * @param array{libellesModifies: list<string>, liens: list<mixed>} $calcul
     */
    private function notifier(ChangeRequest $origine, ChangeRequest $complement, array $calcul, Lecteur $lecteur): void
    {
        $this->notificateur->complementFusionne($complement, $origine);

        if ($origine->getAuthorUserId() === $lecteur->userId) {
            return;
        }

        if ([] === $calcul['libellesModifies'] && [] === $calcul['liens']) {
            return;
        }

        $this->notificateur->demandeCompletee($origine, true, $calcul['libellesModifies'], \count($calcul['liens']));
    }

    /** Une valeur de champ telle que le rapport la montre : un bloc date gagne son `texte`. */
    private function affichage(mixed $valeur): mixed
    {
        return match (true) {
            $valeur instanceof UncertainDate => $this->normaliseur->dateAvecTexte($valeur),
            $valeur instanceof Sex => $valeur->value,
            default => $valeur,
        };
    }
}

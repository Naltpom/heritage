<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Contract\ApplicateurDeDemande;
use App\Module\Proposal\Contract\ApplicateurDOperation;
use App\Module\Proposal\Contract\DemandeEnCours;
use App\Module\Proposal\Contract\EcritureDeRevision;
use App\Module\Proposal\Contract\OpEnAttente;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\ChangeRequestOp;
use App\Module\Proposal\Entity\Revision;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Enum\RevisionOperation;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Service\Port\EtatCible;
use App\Module\Proposal\Service\Port\NotificateurDeDemandes;
use App\Module\Proposal\Service\Port\PortArbre;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * VALIDER — quatre issues, et deux `409` aux conseils opposés.
 *
 * | Issue                | HTTP  | Écrit dans `tree_*` | Statut de la demande | Conseil au front        |
 * |----------------------|-------|---------------------|----------------------|-------------------------|
 * | appliquée            | `200` | oui                 | `validee`            | —                       |
 * | **rejouée**          | `200` | oui                 | `validee`            | le dire                 |
 * | **caduque**          | `409` | **non**             | **`caduque`**        | « Refaire cette demande »|
 * | cible disparue       | `409` | non                 | **`caduque`**        | « Refaire cette demande »|
 * | dépendance morte     | `409` | non                 | **`caduque`**        | « Refaire cette demande »|
 * | dépendance en attente| `409` | non                 | **`en_attente`**     | « Valider d'abord la n° X »|
 * | compléments en attente | `409` | non               | **`en_attente`**     | « Fusionner d'abord »   |
 * | complément à fusionner | `409` | non               | **`en_attente`**     | « Fusionner dans la n° X »|
 * | **verrou concurrent**| `409` | non                 | **`en_attente`**     | **« Réessayer »**       |
 * | modifiée entre-temps | `409` | non                 | inchangé             | relire, puis décider    |
 * | déjà décidée         | `409` | non                 | inchangé             | recharger               |
 *
 * ── La séquence, et pourquoi elle est dans cet ordre ─────────────────────────────
 *
 * 1. **Contrôle intégral, hors transaction, aucune écriture** ({@see AnalyseDeDemande}).
 *    « Une validation est tout ou rien. Un rejeu qui écrirait trois ops puis
 *    découvrirait une caducité sur le quatrième laisserait l'arbre dans un état que
 *    personne n'a approuvé — même annulé par un `ROLLBACK`, ce serait un motif de doute
 *    permanent. » (§ 9.4.)
 * 2. **Si caducité : une transaction courte qui écrit le statut, puis on lève.** C'est
 *    la `409` qui écrit, et c'est contre-intuitif au point que le contrat d'API a dû
 *    l'écrire en toutes lettres (§ 6.8, divergence 6). Lever sans avoir commité
 *    laisserait la demande dans la file, et le front la rechargerait inchangée.
 * 3. **Sinon : une transaction, toutes les écritures, l'empreinte, les révisions, la
 *    décision.** Une `OptimisticLockException` y annule tout et devient
 *    `verrou_concurrent` — **rien n'a été écrit, réessayer est la bonne action**.
 * 4. **Les notifications partent après le `commit`**, jamais dedans : une notification
 *    annulée par un `ROLLBACK` annoncerait une validation qui n'a pas eu lieu, et une
 *    notification qui échoue annulerait une validation parfaitement valide.
 *
 * ── Ne pas confondre les deux verrous ────────────────────────────────────────────
 *
 * - `#[ORM\Version]` protège **l'instant** de la transaction. C'est lui qui produit
 *   `verrou_concurrent`.
 * - `prp_change_op.base_version` protège **les trois jours** entre le dépôt et la
 *   validation, que le premier ne peut structurellement pas voir passer, l'entité étant
 *   rechargée fraîche entre les deux. C'est lui qui ouvre le choix entre rejeu et
 *   caducité — tranché, lui, par la comparaison des `old_value`.
 */
final readonly class ValidationDeDemande
{
    /**
     * Ce que PHP accorde à une validation : 55 s, sous les 60 s du `proxy_read_timeout`
     * du nginx de l'hôte — même borne, même raison que l'assistant
     * (`Assistant\Service\ReglagesDeLAssistant::accorderLeTemps()`).
     *
     * **Un filet, pas la correction.** La demande 4178 tombait à 30 s parce que chaque
     * geste relisait l'arbre entier ; c'est {@see PortArbre::enUnLot()} qui l'a ramenée à
     * quelques secondes. Mais une validation est une seule transaction, tout ou rien :
     * la tuer à mi-course rend une `500` et oblige à tout refaire, là où quelques
     * secondes de plus la laissent aboutir. Le recalcul final, lui, grandit avec l'arbre.
     */
    public const int DUREE_PHP_SECONDES = 55;

    /**
     * @param iterable<ApplicateurDOperation> $applicateurs          découverts par le tag
     *                                                               `app.proposal.applicateur_d_operation`
     * @param iterable<ApplicateurDeDemande>  $applicateursDeDemande découverts par le tag
     *                                                               `app.proposal.applicateur_de_demande`
     */
    public function __construct(
        private EntityManagerInterface $em,
        private AnalyseDeDemande $analyse,
        private PortArbre $arbre,
        private NormaliseurDeValeur $normaliseur,
        private NotificateurDeDemandes $notificateur,
        private EmpreinteDeDemande $empreintes,
        #[AutowireIterator('app.proposal.applicateur_d_operation')]
        private iterable $applicateurs,
        #[AutowireIterator('app.proposal.applicateur_de_demande')]
        private iterable $applicateursDeDemande = [],
        // Dernier et facultatif : la table est sans état, et les tests qui montent ce
        // service à la main n'ont pas à la fournir.
        private SurfaceEditable $surface = new SurfaceEditable(),
    ) {
    }

    /**
     * @param mixed $empreinte l'empreinte de la demande telle que l'écran l'a lue (§ 6.5),
     *                         ou `null` — **facultative**, ajoutée le 10/10/2026 quand les
     *                         demandes en attente ont commencé à se compléter : valider
     *                         une fiche que son auteur vient de modifier, c'est valider ce
     *                         qu'on n'a pas lu (`409 demande_modifiee_entre_temps`)
     */
    public function valider(ChangeRequest $demande, Lecteur $lecteur, mixed $empreinte = null): ResultatDeValidation
    {
        $this->verifierQuIlPeutEcrire($lecteur);
        self::accorderLeTemps();

        if (RequestStatus::EnAttente !== $demande->getStatus()) {
            // Un double clic ne doit pas produire deux révisions.
            throw Conflit::dejaDecidee($demande->getStatus());
        }

        $this->empreintes->exiger($demande, $empreinte);

        $plan = $this->analyse->analyser($demande);

        if ($plan->resolution->estCaduque()) {
            $this->consigner($demande, $plan);
        }

        // **Les deux `409` des compléments n'écrivent rien non plus** (ADR 0017). Une
        // proposition qui a des compléments en attente se valide **après** leur fusion :
        // la valider avant créerait la personne sans eux, et chacun deviendrait une
        // seconde création de la même personne. Un complément ne se valide pas tant que
        // son origine vit : on le fusionne. Ni l'un ni l'autre n'est mort — rien n'est
        // consigné, l'auteur n'est pas notifié.
        if (ResolutionPrevue::ComplementsEnAttente === $plan->resolution) {
            throw Conflit::complementsEnAttente($demande->getId() ?? 0, $plan->complementsEnAttente);
        }

        if (ResolutionPrevue::ComplementAFusionner === $plan->resolution
            && null !== $plan->origineDemandeId
            && null !== $plan->origineStatut
        ) {
            throw Conflit::complementAFusionner($demande->getId() ?? 0, $plan->origineDemandeId, $plan->origineStatut);
        }

        // **La seule `409` qui n'écrit rien, pas même un statut.** Cette demande
        // s'appuie sur une personne proposée par une autre demande encore en attente :
        // il y a un ordre à respecter, pas une demande à condamner. Elle reste dans la
        // file, telle quelle, et se validera dès que l'autre sera tranchée.
        if (ResolutionPrevue::DependanceEnAttente === $plan->resolution) {
            throw Conflit::dependanceEnAttente($demande->getId() ?? 0, $plan->dependancesEnAttente());
        }

        return $this->appliquer($demande, $plan, $lecteur);
    }

    /**
     * La `409` qui écrit : la demande passe en `caduque`, **et rien d'autre ne bouge**.
     *
     * L'auteur est notifié avec le diff de ce qui a changé sous ses pieds, et sa demande
     * reste consultable avec un bouton « refaire cette demande » — « une caducité sans
     * ce bouton est une contribution perdue, et un membre dont la première contribution
     * se perd n'en dépose pas de seconde ».
     */
    private function consigner(ChangeRequest $demande, PlanDeValidation $plan): never
    {
        $demande->setStatus(RequestStatus::Caduque)->toucher();
        $this->em->flush();

        $id = $demande->getId() ?? 0;

        // La dépendance morte se notifie **autrement** : rien n'a bougé sur aucune
        // fiche, et le diff champ par champ de la caducité ordinaire n'a donc rien à
        // raconter. Ce qui est arrivé, c'est que la personne désignée ne naîtra jamais.
        if (ResolutionPrevue::DependanceMorte === $plan->resolution) {
            $dependances = $plan->dependancesMortes();
            $this->notificateur->demandeCaduqueParDependance($demande, $dependances);

            throw Conflit::dependanceMorte($id, $dependances);
        }

        $champs = $plan->champsEnConflit($this->normaliseur);
        $this->notificateur->demandeCaduque($demande, $champs);

        if (ResolutionPrevue::CibleDisparue === $plan->resolution) {
            $disparue = $plan->disparues[0] ?? ['kind' => SubjectKind::Personne->value, 'id' => 0];
            $kind = SubjectKind::tryFrom($disparue['kind']) ?? SubjectKind::Personne;

            throw Conflit::cibleDisparue($id, $kind, $disparue['id'], $demande->getTargetLabel());
        }

        throw Conflit::demandeCaduque($id, $champs);
    }

    private function appliquer(ChangeRequest $demande, PlanDeValidation $plan, Lecteur $lecteur): ResultatDeValidation
    {
        $rejeu = ResolutionPrevue::Rejeu === $plan->resolution;

        try {
            /** @var array{list<Revision>, string} $issue */
            $issue = $this->em->wrapInTransaction(
                fn (): array => $this->ecrire($demande, $plan, $lecteur),
            );
        } catch (OptimisticLockException) {
            // Verrou n° 1. **Rien n'a été écrit** — `wrapInTransaction` a annulé —, la
            // demande reste `en_attente`, et réessayer est exactement la bonne action.
            // Ne surtout pas la confondre avec une caducité, qui conseille l'inverse.
            throw Conflit::verrouConcurrent($demande->getId() ?? 0);
        }

        [$revisions, $empreinte] = $issue;

        $this->notificateur->demandeValidee($demande, $rejeu);

        return new ResultatDeValidation(
            $rejeu ? 'rejouee' : 'validee',
            $demande,
            $revisions,
            $empreinte,
            $rejeu ? [
                'motif' => 'version_modifiee',
                'versionALaSoumission' => $plan->versionALaSoumission(),
                'versionAppliquee' => $this->versionAppliquee($plan),
                'champsVerifies' => $plan->champsVerifies(),
            ] : null,
        );
    }

    /**
     * Le corps de la transaction : les écritures, l'empreinte, les révisions, la
     * décision. Dans cet ordre, et c'est l'ordre du § 9.4.
     *
     * @return array{list<Revision>, string}
     */
    private function ecrire(ChangeRequest $demande, PlanDeValidation $plan, Lecteur $lecteur): array
    {
        $maintenant = new \DateTimeImmutable();

        // Deux familles d'écriture, un seul format de sortie. Les ops de champ visent des
        // lignes existantes ; l'applicateur de nature crée des lignes qui n'existaient
        // pas. Les faire converger sur `EcritureDeRevision` est ce qui permet de calculer
        // l'empreinte **une fois**, après tout, et de la poser sur tout le lot.
        //
        // `enUnLot()` : les colonnes dérivées de l'arbre (génération, branche, rang) sont
        // recalculées **une fois**, après toutes les écritures, et non après chaque geste.
        // Geste par geste, une création de 22 fiches relisait l'arbre entier une
        // quarantaine de fois et dépassait les 30 s de PHP (demande 4178, 04/10/2026).
        /** @var list<EcritureDeRevision> $ecritures */
        $ecritures = $this->arbre->enUnLot(fn (): array => [
            ...$this->appliquerLesChamps($plan, $demande),
            ...$this->appliquerLesAutresOps($plan, $demande, $lecteur),
            ...$this->appliquerLaNature($plan, $demande, $lecteur),
        ]);

        foreach ($plan->ops as $etat) {
            $etat->op->marquerApplique($maintenant);
        }

        // L'empreinte se calcule **après** les écritures et **dans** la transaction :
        // elle est dérivée de la donnée, il n'y a rien à incrémenter et rien à
        // synchroniser. C'est aussi ce qui invalide le cache de placement et les cercles
        // de visibilité — sans geste (§ 2.3, ADR 0005).
        $empreinte = $this->arbre->empreinteDeLArbre();

        $revisions = [];

        foreach ($ecritures as $ecriture) {
            $revision = new Revision(
                $ecriture->subjectKind,
                $ecriture->subjectId,
                $ecriture->subjectLabel,
                $ecriture->operation,
                $ecriture->changes,
                $lecteur->userId,
                $lecteur->libelle,
                $empreinte,
            );
            $revision->setRequest($demande)->setVersions($ecriture->versionAvant, $ecriture->versionApres);

            $this->em->persist($revision);
            $revisions[] = $revision;
        }

        // `decider()` pose `auto_validated` lui-même quand le validateur est l'auteur.
        // Autorisé — il n'y a qu'un administrateur, l'interdire bloquerait le produit —
        // mais **rendu visible**, et en colonne, pour que « montre-moi toutes les
        // auto-validations » soit une requête et non une fouille (ADR 0004).
        $demande->decider(RequestStatus::Validee, $lecteur->userId, $lecteur->libelle, null, $maintenant);

        $this->reporterLeLibelleDeLaCible($demande, $ecritures);

        $this->em->flush();

        return [$revisions, $empreinte];
    }

    /**
     * Les ops de champ, **un appel au port par ligne** et non par champ : c'est ce qui
     * permet à `Tree` de recalculer ses colonnes dérivées une fois, et c'est le niveau
     * auquel le verrou optimiste s'exerce.
     *
     * @return list<EcritureDeRevision>
     */
    private function appliquerLesChamps(PlanDeValidation $plan, ChangeRequest $demande): array
    {
        $ecritures = [];

        foreach ($this->regrouperParLigne($plan) as $lot) {
            $applique = $this->arbre->appliquerChamps($lot['kind'], $lot['id'], $lot['valeurs']);

            $ecritures[] = new EcritureDeRevision(
                $lot['kind'],
                $lot['id'],
                $applique->libelle,
                RevisionOperation::Modification,
                $lot['changes'],
                $applique->versionAvant,
                $applique->versionApres,
            );
        }

        return $ecritures;
    }

    /**
     * Les ops qui ne sont pas des champs : un média joint, une citation ajoutée, un lien
     * retiré. **Chacune est appliquée par le module qui la possède.**.
     *
     * Ce sont ces appels qui manquaient. Le validateur se contentait de *résoudre*
     * l'applicateur — pour lever si aucun n'était branché — puis passait à l'op
     * suivante : la demande se validait, les ops étaient marquées appliquées, et rien
     * n'était écrit. Voir l'en-tête de {@see ApplicateurDOperation}.
     *
     * Les ops sont **regroupées par nature** et l'applicateur reçoit son lot en une
     * fois, dans l'ordre de `seq` : un module qui joint trois médias les voit ensemble
     * et peut décider lequel devient le portrait actif.
     *
     * @return list<EcritureDeRevision>
     */
    private function appliquerLesAutresOps(PlanDeValidation $plan, ChangeRequest $demande, Lecteur $lecteur): array
    {
        /** @var array<string, list<OpEnAttente>> $parNature */
        $parNature = [];

        foreach ($plan->ops as $etat) {
            if (OpKind::Champ !== $etat->op->getOpKind()) {
                $parNature[$etat->op->getOpKind()->value][] = $this->opEnAttente($etat->op);
            }
        }

        $ecritures = [];
        $vue = $this->demandeEnCours($demande);

        foreach ($parNature as $nature => $ops) {
            $applicateur = $this->applicateurPour(OpKind::from($nature));

            foreach ($applicateur->appliquer($vue, $ops, $lecteur) as $ecriture) {
                $ecritures[] = $this->exigerUneEcriture($ecriture, $nature);
            }
        }

        return $ecritures;
    }

    /**
     * Refuse bruyamment un retour d'un autre type.
     *
     * Le défaut que ces deux points de greffe ont porté était une **écriture
     * silencieusement perdue** ; on ne le remplace pas par un silence d'un autre genre.
     */
    private function exigerUneEcriture(mixed $ecriture, string $origine): EcritureDeRevision
    {
        if (!$ecriture instanceof EcritureDeRevision) {
            throw new \LogicException(\sprintf('L\'applicateur de « %s » doit rendre des EcritureDeRevision, pas des %s.', $origine, get_debug_type($ecriture)));
        }

        return $ecriture;
    }

    /**
     * La demande, **en lecture seule**, telle qu'un module extérieur la voit.
     *
     * Rien de ce qu'un applicateur reçoit ne lui permet de changer son statut : seul
     * `Proposal` décide de ce qu'une demande devient.
     */
    private function demandeEnCours(ChangeRequest $demande): DemandeEnCours
    {
        return new DemandeEnCours(
            $demande->getId(),
            $demande->getKind()->value,
            $demande->getAuthorUserId(),
            $demande->getAuthorLabel(),
            $demande->getTargetKind()?->value,
            $demande->getTargetId(),
            $demande->getTargetLabel(),
            $demande->getMessage(),
        );
    }

    /** Un op, en lecture seule — sans `marquerApplique()`, qui appartient au validateur. */
    private function opEnAttente(ChangeRequestOp $op): OpEnAttente
    {
        return new OpEnAttente(
            $op->getSeq(),
            $op->getOpKind()->value,
            $op->getSubjectKind()->value,
            $op->getSubjectId(),
            $op->getSubjectDraftRef(),
            $op->getField(),
            $op->getValueKind()->value,
            $op->getOldValue(),
            $op->getNewValue(),
            $op->getBaseVersion(),
        );
    }

    /**
     * Ce que la **nature** de la demande impose au-delà de ses ops : une création de
     * personne pose une fiche, une famille et des filiations, et n'a aucun op.
     *
     * Une nature sans applicateur n'est pas une erreur : c'est le cas de toutes les
     * `modification_*`, qui sont entièrement décrites par leurs ops.
     *
     * **Tous** les applicateurs qui réclament la nature sont exécutés, et non le
     * premier : deux modules peuvent légitimement avoir quelque chose à faire d'une même
     * nature de demande, et un `return` dans la boucle ferait taire le second sans que
     * rien ne le signale — exactement le mode de défaillance que ce fichier vient de
     * corriger un étage plus haut.
     *
     * @return list<EcritureDeRevision>
     */
    private function appliquerLaNature(PlanDeValidation $plan, ChangeRequest $demande, Lecteur $lecteur): array
    {
        $ecritures = [];

        $vue = $this->demandeEnCours($demande);
        $ops = array_map(
            fn (EtatDOp $etat): OpEnAttente => $this->opEnAttente($etat->op),
            $plan->ops,
        );

        foreach ($this->applicateursDeDemande as $applicateur) {
            if (!\in_array($demande->getKind(), $applicateur->natures(), true)) {
                continue;
            }

            foreach ($applicateur->appliquer($vue, $ops, $lecteur) as $ecriture) {
                $ecritures[] = $this->exigerUneEcriture($ecriture, $demande->getKind()->value);
            }
        }

        return $ecritures;
    }

    /**
     * Le libellé dénormalisé de la cible suit le renommage : sans ce report, la file et
     * « mes demandes » afficheraient encore l'ancien nom de la fiche.
     *
     * @param list<EcritureDeRevision> $ecritures
     */
    private function reporterLeLibelleDeLaCible(ChangeRequest $demande, array $ecritures): void
    {
        $kind = $demande->getTargetKind();
        $id = $demande->getTargetId();

        if (null === $kind || null === $id) {
            return;
        }

        foreach ($ecritures as $ecriture) {
            if ($ecriture->subjectKind === $kind && $ecriture->subjectId === $id) {
                $demande->setTarget($kind, $id, $ecriture->subjectLabel);

                return;
            }
        }
    }

    /**
     * Regroupe les ops par ligne visée, en convertissant chaque valeur en type PHP.
     *
     * `changes` est construit au passage, dans la forme de `core_audit_log.changes`
     * qu'exige `prp_revision` (§ 5.5), et **rendu formaté** : l'historique d'une fiche
     * est fait pour être lu, pas pour être analysé (§ 6.11).
     *
     * @return array<string, array{kind: SubjectKind, id: int, valeurs: array<string, mixed>, changes: array<string, mixed>}>
     */
    private function regrouperParLigne(PlanDeValidation $plan): array
    {
        $lots = [];

        foreach ($plan->ops as $etat) {
            $op = $etat->op;
            $sujet = $op->getSubjectId();
            $champ = $op->getField();

            if (OpKind::Champ !== $op->getOpKind()) {
                // Les autres natures d'op (lien, média, citation, note) ont leur
                // applicateur dédié ; ce service ne les invente pas.
                $this->applicateurPour($op->getOpKind());

                continue;
            }

            if (null === $sujet || null === $champ) {
                continue;
            }

            $cle = EtatCible::cleDe($op->getSubjectKind(), $sujet);

            $lots[$cle] ??= [
                'kind' => $op->getSubjectKind(),
                'id' => $sujet,
                'valeurs' => [],
                'changes' => [],
            ];

            $lots[$cle]['valeurs'][$champ] = $this->normaliseur->versPhp(
                $op->getValueKind(),
                $op->getNewValue(),
                'ops.'.$champ,
                // « On ne sait pas » est une valeur de la nature d'une union (ADR 0020).
                $this->surface->admetNul($op->getSubjectKind(), $champ),
            );

            $lots[$cle]['changes'][$champ] = [
                'avant' => $this->normaliseur->enUneLigne($op->getValueKind(), $op->getOldValue(), $champ),
                'apres' => $this->normaliseur->enUneLigne($op->getValueKind(), $op->getNewValue(), $champ),
            ];
        }

        return $lots;
    }

    private function versionAppliquee(PlanDeValidation $plan): ?int
    {
        foreach ($plan->cibles as $cible) {
            return $cible->version;
        }

        return null;
    }

    /**
     * Le point de greffe des natures d'op — un lien, un média, une citation.
     *
     * La nature `champ` est traitée en ligne, parce qu'elle seule se regroupe par ligne
     * visée. Les autres passent par ici, découvertes par le tag : `ajout_media`, par
     * `Media\Service\Proposition\ApplicateurDOperationMedia`, `ajout_citation`, par
     * {@see ApplicateurDOperationCitation}, `ajout_note`, `suppression_personne` et
     * `retrait_lien`, par leurs applicateurs respectifs. `ajout_lien` (comme nature d'op),
     * `retrait_media` et `retrait_citation` viendront avec leur module. *(`ajout_lien`
     * est branchée comme **nature de demande**, par `ApplicateurDeCreation` ; comme
     * nature d'**op**, elle ne l'est pas.)*
     *
     * Le message nomme celle qui manque, plutôt que de laisser passer une validation qui
     * n'écrirait rien. **Ne pas remplacer cette levée par un `continue`** : c'est la
     * seule chose qui distingue « pas encore branché » de « appliqué », et la confusion
     * entre les deux est le défaut le plus coûteux qu'ait porté ce module.
     */
    private function applicateurPour(OpKind $kind): ApplicateurDOperation
    {
        foreach ($this->applicateurs as $applicateur) {
            if (\in_array($kind, $applicateur->natures(), true)) {
                return $applicateur;
            }
        }

        throw new \LogicException(\sprintf('Aucun applicateur n\'est branché pour les ops de nature « %s » : voir ApplicateurDOperation.', $kind->value));
    }

    /**
     * Relève `max_execution_time` pour la requête en cours — **relever, jamais
     * abaisser** : `0` (illimité, la valeur de la ligne de commande et donc des tests) ou
     * une valeur déjà plus haute restent tels quels.
     */
    private static function accorderLeTemps(): void
    {
        $actuel = (int) \ini_get('max_execution_time');

        if (0 === $actuel || $actuel >= self::DUREE_PHP_SECONDES) {
            return;
        }

        set_time_limit(self::DUREE_PHP_SECONDES);
    }

    private function verifierQuIlPeutEcrire(Lecteur $lecteur): void
    {
        if (null !== $lecteur->membreEmprunteUserId) {
            throw Conflit::vueCommeMembreActive($lecteur->membreEmprunteUserId);
        }
    }
}

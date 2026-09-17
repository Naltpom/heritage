<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Source;
use App\Module\Tree\Enum\CitationQuality;
use App\Module\Tree\Enum\NoteScope;
use App\Tests\Proposal\Double\ArbreEnMemoire;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * UNE DEMANDE VALIDÉE POSE UNE LIGNE DANS `tree_citation` — la chaîne entière.
 *
 * ## Pourquoi ce fichier existe alors que `tests/Proposal/AjoutDeCitationTest` est vert
 *
 * Toute la suite de `Proposal` tourne sur `ArbreEnMemoire`, un double déclaré en
 * `when@test` dans `config/services/proposal.yaml`. C'est le bon choix pour éprouver la
 * logique de validation — le plan, les deux verrous, les révisions —, mais la conséquence
 * est qu'**aucun** de ces tests ne touche `tree_citation`. Ils passeraient à l'identique
 * si `EcritureDeLArbrePourProposal::creerCitation()` ne faisait rien.
 *
 * Le trou est exactement là, et c'est celui-ci que ce fichier comble : le **vrai** port,
 * une **vraie** base, et une ligne qu'on relit par une requête.
 *
 * ## Le port réel est réinjecté dans le conteneur, et c'est le seul artifice
 *
 * `EcritureDeLArbrePourProposal` est déclaré `public: true` en `when@test` dans
 * `config/services/tree.yaml`, précisément « pour un seul test : celui qui éprouve
 * l'écriture de l'arbre par une proposition validée ». On le récupère, et on le pose à la
 * place du double **avant** de demander `DepotDeDemande` et `ValidationDeDemande` : le
 * conteneur les construit alors avec lui. Tout le reste — les tags, les itérateurs, les
 * constructeurs, les applicateurs — reste celui de la production, et c'est ce qui compte :
 * un applicateur qui ne serait pas découvert ferait tomber ce test comme les autres.
 *
 * L'ordre n'est pas négociable. Un service déjà construit garde la dépendance qu'il a
 * reçue ; c'est pourquoi la substitution est la **première** chose que fait `setUp()`
 * après l'amorçage, et qu'aucun service de `Proposal` n'est touché avant elle.
 *
 * ## La base est SQLite, et ce qu'elle ne prouve pas est écrit ici
 *
 * `SchemaTool` ne pose que ce que le mapping exprime : ni les contraintes `CHECK` de la
 * migration — `ck_tree_citation_sujet_unique`, `ck_tree_citation_quay` —, ni les index
 * partiels. Ce test prouve que le port écrit ce qu'il annonce ; c'est PostgreSQL qui
 * prouve que le schéma le tolère.
 */
final class CitationCreeeParUneDemandeTest extends BaseDeDemandeSurArbreReel
{
    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * LE TEST QUI COMPTE.
     *
     * Un membre dépose une demande portant une op `ajout_citation`, un administrateur la
     * valide, et **une ligne apparaît dans `tree_citation`** : rattachée à la bonne fiche,
     * avec la bonne source, la bonne page et le bon `QUAY`.
     *
     * La ligne est relue par une requête et non par l'entité en mémoire : une entité que
     * l'unité de travail tient encore dirait « écrite » d'une donnée qui n'a jamais quitté
     * PHP. `clear()` d'abord, `find()` ensuite.
     */
    public function testUneDemandeValideePoseUneLigneDansTreeCitation(): void
    {
        $personneId = $this->poserUnePersonne('Marne Dolomite', 'Sureau');
        $sourceId = $this->poserUneSource('Registre paroissial de Saule, 1801-1820');

        self::assertSame(0, $this->compterLesCitations(), 'La fiche part sans aucune source.');

        $demande = $this->deposer($personneId, $sourceId);

        // Le dépôt n'écrit pas dans l'arbre : la règle centrale de l'ADR 0004, vérifiée
        // ici contre la vraie table et non contre un compteur de double.
        self::assertSame(0, $this->compterLesCitations(), 'Un dépôt ne touche pas la table visée.');

        $resultat = $this->validation()->valider($demande, $this->admin());

        self::assertSame('validee', $resultat->resultat);
        self::assertSame(RequestStatus::Validee, $demande->getStatus());

        $this->em()->clear();

        self::assertSame(1, $this->compterLesCitations(), 'La validation pose une ligne, et une seule.');

        $citation = $this->laCitation();

        self::assertSame($personneId, $citation->getPerson()?->getId(), 'La citation est rattachée à la fiche visée.');
        self::assertSame($sourceId, $citation->getSource()->getId());
        self::assertSame('AD Sylvanie, 5 MI 118, vue 12', $citation->getPage());
        self::assertSame(CitationQuality::ActeOuRegistre, $citation->getQuay());
        self::assertSame('Le douze frimaire an IX, devant nous…', $citation->getDataText());
        self::assertSame(NoteScope::Naissance, $citation->getScope());

        // Ni famille ni fait : `ck_tree_citation_sujet_unique` veut exactement un sujet,
        // et c'est la personne.
        self::assertNull($citation->getFamily());
        self::assertNull($citation->getFact());
    }

    /**
     * Les deux colonnes dérivées de la **personne** suivent, et c'est la moitié utile.
     *
     * `citation_count` et `best_quay` décident de quel côté du filtre « appuyée sur un
     * acte » la fiche se range. Une citation écrite sans elles laisserait la fiche
     * comptée pour non sourcée, ce qui est exactement le symptôme qu'on cherche à faire
     * disparaître — et rien ne le signalerait.
     */
    public function testLesAgregatsDeLaFicheSuiventLaCitation(): void
    {
        $personneId = $this->poserUnePersonne('Jargon', 'Sarriette', Sex::Masculin);
        $sourceId = $this->poserUneSource('Recensement d\'Ombrage, 1836');

        $personne = $this->personne($personneId);
        self::assertSame(0, $personne->getCitationCount());
        self::assertNull($personne->getBestQuay());

        $this->validation()->valider($this->deposer($personneId, $sourceId), $this->admin());

        $this->em()->clear();
        $personne = $this->personne($personneId);

        self::assertSame(1, $personne->getCitationCount(), 'La fiche compte désormais sa source.');
        self::assertSame(CitationQuality::ActeOuRegistre->value, $personne->getBestQuay());
    }

    /**
     * La révision est écrite sur la **citation**, avec l'identifiant réel de la ligne —
     * celui qui n'existait pas au dépôt.
     *
     * C'est ce qui rend l'historique d'une fiche capable de dire « telle pièce a été
     * versée le tel jour, par telle personne », et de le dire six mois plus tard.
     */
    public function testLaRevisionDesigneLaLigneReellementCreee(): void
    {
        $personneId = $this->poserUnePersonne('Opale', 'Sarriette');
        $sourceId = $this->poserUneSource('Table décennale de Saule, 1893-1902');

        $resultat = $this->validation()->valider($this->deposer($personneId, $sourceId), $this->admin());

        self::assertCount(1, $resultat->revisions);
        $revision = $resultat->revisions[0];

        self::assertSame(SubjectKind::Citation, $revision->getSubjectKind());
        self::assertSame('Table décennale de Saule, 1893-1902', $revision->getSubjectLabel());

        $this->em()->clear();

        self::assertSame(
            $this->laCitation()->getId(),
            $revision->getSubjectId(),
            'La révision doit désigner la citation réellement insérée, pas un identifiant provisoire.',
        );
    }

    /**
     * **`modification_citation` n'est pas dégradée** — et rien ne le vérifiait.
     *
     * Ce test a été écrit en constatant que la nature qui sait *corriger* une citation
     * n'était couverte par **aucun** test du dépôt : `grep -rn modification_citation
     * tests/` ne rendait rien avant ce lot. « La modification continue de fonctionner »
     * était donc une affirmation sans preuve, et c'est exactement ce qu'un lot qui touche
     * au même terrain doit fournir.
     *
     * Le scénario est celui qui compte : on **crée** une citation par la nouvelle nature,
     * puis on **corrige sa page** par l'ancienne, sur la ligne réellement insérée. Les
     * deux natures se succèdent sur la même citation, et la seconde n'a rien perdu.
     *
     * ── Ce que ce test ne couvre pas, et pourquoi ────────────────────────────────
     *
     * Il corrige `page`, pas `quay`. Ce n'est pas un choix de commodité : **`quay` n'est
     * pas modifiable aujourd'hui**, et c'est un défaut antérieur à ce lot. La surface
     * éditable le déclare `ValueKind::Enum`, dont le contrat de port est `string|null` ;
     * or `EtatCible::valeurs['quay']` rend un `int`, `CitationQuality` étant la seule
     * énumération du projet adossée à `int`. La comparaison stricte d'`oldValue` ne peut
     * donc jamais réussir : envoyé en entier, il rend `422 donnees_invalides` (le
     * normaliseur exige une chaîne) ; envoyé en chaîne, il rend `409 conflit` sur
     * « la fiche a changé pendant la saisie », alors que rien n'a changé.
     *
     * Ce lot ne le corrige pas — corriger la lecture d'une valeur de port se répercute
     * sur la détection de caducité de la modification, ce qui est une décision et non un
     * geste. Il le **contourne** pour la création : `ajout_citation` n'emprunte pas
     * `ValueKind::Enum` mais `ValueKind::Citation`, où `quay` voyage en entier et est
     * validé contre le vocabulaire que `Tree` déclare.
     */
    public function testModifierLaPageDUneCitationCreeeParUneDemandeFonctionneToujours(): void
    {
        $personneId = $this->poserUnePersonne('Cobalt', 'Sureau', Sex::Masculin);
        $sourceId = $this->poserUneSource('Registre paroissial de Saule, 1801-1820');

        $this->validation()->valider($this->deposer($personneId, $sourceId), $this->admin());
        $this->em()->clear();

        $citation = $this->laCitation();
        $citationId = (int) $citation->getId();

        $correction = $this->depot()->deposer([
            'kind' => 'modification_citation',
            'soumettre' => true,
            'ops' => [[
                'seq' => 1,
                'opKind' => 'champ',
                'subjectKind' => 'citation',
                'subjectId' => $citationId,
                'field' => 'page',
                'valueKind' => 'texte',
                'oldValue' => 'AD Sylvanie, 5 MI 118, vue 12',
                'newValue' => 'AD Sylvanie, 5 MI 118, vue 13',
                'baseVersion' => $citation->getVersion(),
            ]],
        ], new Lecteur(self::OPALE, 'Compte contributeur', administrateur: false));

        $this->validation()->valider($correction, $this->admin());
        $this->em()->clear();

        self::assertSame(1, $this->compterLesCitations(), 'Une correction ne duplique pas la citation.');
        self::assertSame('AD Sylvanie, 5 MI 118, vue 13', $this->laCitation()->getPage());
        self::assertSame(CitationQuality::ActeOuRegistre, $this->laCitation()->getQuay(), 'La correction d\'une page ne touche pas le QUAY.');
    }

    /**
     * **Le vocabulaire que le port déclare est bien celui des deux énumérations.**.
     *
     * `ArbreEnMemoire` recopie ces deux listes — il ne peut pas nommer `Tree\Enum\…` sans
     * enfreindre la règle 3 de l'ADR 0002. Une copie qui vieillirait ferait passer au vert,
     * dans `tests/Proposal`, un dépôt que la production refuse : ce test est ce qui
     * l'empêche, et c'est pourquoi il vit ici, du côté qui possède le vocabulaire.
     */
    public function testLeVocabulaireDuDoubleEstCeluiDeLArbre(): void
    {
        $reel = $this->portReel()->vocabulaireDAnnotation();
        $double = (new ArbreEnMemoire())->vocabulaireDAnnotation();

        self::assertSame($reel->portees, $double->portees, 'Les portées du double ont divergé de NoteScope.');
        self::assertSame($reel->qualites, $double->qualites, 'Les QUAY du double ont divergé de CitationQuality.');
    }

    /** Une source absente rend `null` — une erreur de saisie, jamais une exception. */
    public function testUneSourceAbsenteRendNull(): void
    {
        self::assertNull($this->portReel()->chargerSource(4242));
    }

    // ────────────────────────────────────────────────────────────── le gréement

    private function deposer(int $personneId, int $sourceId): ChangeRequest
    {
        return $this->deposerCorps([
            'kind' => 'ajout_citation',
            'message' => 'Relevé aux archives départementales.',
            'soumettre' => true,
            'ops' => [[
                'seq' => 1,
                'opKind' => 'ajout_citation',
                'subjectKind' => 'personne',
                'subjectId' => $personneId,
                'valueKind' => 'citation',
                'oldValue' => null,
                'newValue' => [
                    'sourceId' => $sourceId,
                    'page' => 'AD Sylvanie, 5 MI 118, vue 12',
                    'quay' => 2,
                    'dataText' => 'Le douze frimaire an IX, devant nous…',
                    'scope' => 'naissance',
                ],
            ]],
        ]);
    }

    private function poserUneSource(string $titre): int
    {
        $source = new Source($titre);

        $this->em()->persist($source);
        $this->em()->flush();

        $id = $source->getId();
        \assert(null !== $id);

        return $id;
    }

    private function compterLesCitations(): int
    {
        return (int) $this->em()
            ->createQuery('SELECT COUNT(c.id) FROM '.Citation::class.' c')
            ->getSingleScalarResult();
    }

    private function laCitation(): Citation
    {
        $citation = $this->em()->getRepository(Citation::class)->findOneBy([]);
        self::assertInstanceOf(Citation::class, $citation);

        return $citation;
    }
}

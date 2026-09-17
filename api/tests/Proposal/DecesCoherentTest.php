<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Exception\DonneesInvalides;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * UNE DATE DE DÉCÈS SUR QUELQU'UN QU'ON LAISSE MARQUÉ VIVANT.
 *
 * La règle existait, et elle n'existait qu'en base : `ck_tree_person_deces_coherent`,
 * « une date de décès implique le décès ». Elle s'appliquait au `flush`. Le contributeur
 * recevait donc une erreur de pilote de base de données — un message qui ne nomme ni le
 * champ ni le geste à faire — et n'avait aucun moyen de deviner qu'il lui manquait une
 * case à cocher. Signalé sur une fiche réelle, avec trois autres qui allaient buter
 * dessus.
 *
 * ── Pourquoi on refuse plutôt que de cocher la case à sa place ─────────────────
 *
 * Parce que `deceased` **ouvre la visibilité**. Un vivant hors du cercle du lecteur est
 * absent de l'arbre (ADR 0003) ; un défunt est « un fait historique, visible de tout
 * compte connecté ». Basculer ce drapeau au nom de quelqu'un qui n'a saisi qu'une date
 * élargirait l'audience d'une fiche sans que personne ne l'ait demandé.
 *
 * C'est l'arbitrage déjà pris à la saisie pour la rupture d'union : « on ne devine pas
 * une rupture à partir d'une date — le lecteur a pu saisir la date avant de cocher ».
 * Le message dit les deux gestes possibles et laisse choisir.
 * ═══════════════════════════════════════════════════════════════════════════════
 */
final class DecesCoherentTest extends BaseDeDemandes
{
    /**
     * Le cas signalé : la date part seule, la case reste décochée.
     *
     * On le refuse **au dépôt**, là où le contributeur a encore son formulaire ouvert.
     */
    public function testUneDateDeDecesSansLaCaseEstRefuseeAuDepot(): void
    {
        $this->ficheVivante();

        try {
            $this->depot->deposer(
                $this->corpsDeModification([$this->opDeces(1991)]),
                $this->lecteur(self::OPALE),
            );
            self::fail('Le dépôt devait être refusé.');
        } catch (DonneesInvalides $refus) {
            // Le message utile est dans `champs`, pas dans celui de l'exception : c'est
            // ce que le § 6.2 du contrat annonce, et c'est ce qui permet à un formulaire
            // de poser l'erreur sur la bonne ligne plutôt qu'en haut de page.
            $champs = $refus->champs() ?? [];

            self::assertArrayHasKey('ops', $champs);
            self::assertStringContainsString('personne décédée', $champs['ops']);
            self::assertStringContainsString('retirez la date', $champs['ops'], 'Le message nomme les deux gestes possibles, pas un seul.');
        }
    }

    /**
     * Et **rien n'est écrit** : ni dans la table cible, ni dans la file.
     *
     * Un refus qui laisse derrière lui une demande à moitié construite reporte le défaut
     * sur l'administrateur, qui la trouvera dans sa file sans savoir quoi en faire.
     */
    public function testLeRefusNeLaisseAucuneDemandeDerriereLui(): void
    {
        $this->ficheVivante();

        try {
            $this->depot->deposer(
                $this->corpsDeModification([$this->opDeces(1991)]),
                $this->lecteur(self::OPALE),
            );
            self::fail('Le dépôt devait être refusé.');
        } catch (DonneesInvalides) {
            // attendu
        }

        self::assertSame(0, $this->arbre->ecritures(), 'Aucune écriture ne part vers Tree.');
        self::assertFalse($this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, 'deceased'));
    }

    /**
     * **Le cas courant reste accepté** : la date et la case, dans la même demande.
     *
     * C'est la raison pour laquelle le contrôle compose l'état *résultant* au lieu de
     * regarder chaque op isolément. Op par op, celui-ci refuserait la moitié des demandes
     * légitimes — et l'ordre des ops déciderait laquelle, ce qui serait pire.
     */
    public function testLaDateEtLaCaseDansLaMemeDemandePassent(): void
    {
        $this->ficheVivante();

        $demande = $this->depot->deposer(
            $this->corpsDeModification([
                $this->opDeces(1991, seq: 1),
                [
                    'seq' => 2,
                    'opKind' => 'champ',
                    'subjectKind' => 'personne',
                    'subjectId' => self::PERSONNE,
                    'field' => 'deceased',
                    'valueKind' => 'booleen',
                    'oldValue' => false,
                    'newValue' => true,
                    'baseVersion' => 7,
                ],
            ]),
            $this->lecteur(self::OPALE),
        );

        self::assertNotNull($demande->getId());
    }

    /**
     * L'ordre des ops ne décide de rien.
     *
     * La case d'abord, la date ensuite : même demande, même verdict. Un contrôle qui
     * lirait les ops dans l'ordre et se prononcerait au passage serait vert ici et rouge
     * au cas précédent, pour une raison que personne ne devinerait.
     */
    public function testLOrdreDesOpsNeChangeRien(): void
    {
        $this->ficheVivante();

        $demande = $this->depot->deposer(
            $this->corpsDeModification([
                [
                    'seq' => 1,
                    'opKind' => 'champ',
                    'subjectKind' => 'personne',
                    'subjectId' => self::PERSONNE,
                    'field' => 'deceased',
                    'valueKind' => 'booleen',
                    'oldValue' => false,
                    'newValue' => true,
                    'baseVersion' => 7,
                ],
                $this->opDeces(1991, seq: 2),
            ]),
            $this->lecteur(self::OPALE),
        );

        self::assertNotNull($demande->getId());
    }

    /**
     * Une fiche **déjà marquée décédée** reçoit une date sans rien cocher de plus.
     *
     * C'est le cas des vingt-cinq fiches du GEDCOM réel portant `DEAT Y` sans `DATE` : un
     * décès certain, dont on retrouve la date des années plus tard. Exiger un op
     * `deceased` qui ne changerait rien serait refuser une modification légitime — et
     * l'op serait de toute façon rejeté, « une modification qui ne modifie rien ».
     */
    public function testUneFicheDejaDecedeeAccepteUneDateSeule(): void
    {
        // La fiche de référence est déjà `deceased: true`, sans date de décès.
        $demande = $this->depot->deposer(
            $this->corpsDeModification([$this->opDeces(1991)]),
            $this->lecteur(self::OPALE),
        );

        self::assertNotNull($demande->getId());
    }

    /**
     * **Un lieu de décès seul passe**, et c'est voulu.
     *
     * La contrainte de base ne regarde que `raw`, `min` et `max` — le lieu n'en fait pas
     * partie. « Inhumé à Trochu » sans date est une information qu'on n'a pas à refuser,
     * et le contrôle recopie ce critère terme pour terme plutôt que d'en inventer un plus
     * large « par prudence ».
     */
    public function testUnLieuDeDecesSeulNEstPasUneDate(): void
    {
        $this->ficheVivante();

        $demande = $this->depot->deposer(
            $this->corpsDeModification([[
                'seq' => 1,
                'opKind' => 'champ',
                'subjectKind' => 'personne',
                'subjectId' => self::PERSONNE,
                'field' => 'death',
                'valueKind' => 'date_incertaine',
                'oldValue' => $this->blocDate(UncertainDate::inconnue()),
                'newValue' => $this->blocDate(UncertainDate::inconnue('Trochu')),
                'baseVersion' => 7,
            ]]),
            $this->lecteur(self::OPALE),
        );

        self::assertNotNull($demande->getId());
    }

    /** La fiche de référence, remise vivante et sans date de décès. */
    private function ficheVivante(): void
    {
        $this->arbre->poser(SubjectKind::Personne, self::PERSONNE, 'Marne Dolomite Sureau', 7, [
            'givenNames' => 'Marne Dolomite',
            'surname' => 'Sureau',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'feminin',
            'deceased' => false,
            'birth' => $this->annee(1987),
            'death' => UncertainDate::inconnue(),
            'forcePrivacy' => false,
            'privacyNote' => null,
        ]);
    }

    /**
     * Un op qui pose une date de décès.
     *
     * @return array<string, mixed>
     */
    private function opDeces(int $annee, int $seq = 1): array
    {
        return [
            'seq' => $seq,
            'opKind' => 'champ',
            'subjectKind' => 'personne',
            'subjectId' => self::PERSONNE,
            'field' => 'death',
            'valueKind' => 'date_incertaine',
            'oldValue' => $this->blocDate(UncertainDate::inconnue()),
            'newValue' => $this->blocDate($this->annee($annee)),
            'baseVersion' => 7,
        ];
    }
}

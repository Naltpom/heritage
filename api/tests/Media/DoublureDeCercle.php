<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Core\Enum\Regime;
use App\Module\Core\Security\CercleDeVisibiliteInterface;

/**
 * Le cercle de visibilité du noyau, joué par un double réglable.
 *
 * Il tient la place de l'implémentation de `Membership`. Ce qui est testé dans ce module
 * n'est **pas la formule** du cercle — elle appartient à `Membership` et sera testée
 * là-bas — mais le fait que `Media` obéisse à la réponse qu'on lui donne, sur les quatre
 * routes de lecture, jusque dans ses avertissements de doublon.
 *
 * Il applique néanmoins les trois règles du contrat, parce qu'un double qui les
 * ignorerait laisserait passer un `Media` qui les ignore aussi :
 *
 * 1. une personne **décédée** est visible de tout compte connecté, régime `Aucun`
 *    compris ;
 * 2. une personne **vivante** n'est visible que dans le cercle du lecteur, et jamais en
 *    régime `Aucun` ;
 * 3. une personne **non déclarée** est masquée — c'est « un décès inconnu vaut vivant ».
 *
 * ## Le lecteur courant
 *
 * Le contrat du noyau ne passe pas le lecteur en paramètre : l'implémentation le résout
 * elle-même. Ce double le tient donc dans un champ, que {@see CasDeTestMedia::membre()}
 * et ses voisines positionnent en construisant le `LecteurMedia`. C'est la transposition
 * fidèle du design réel — la session, ici, c'est le dernier lecteur construit.
 */
final class DoublureDeCercle implements CercleDeVisibiliteInterface
{
    private Regime $regime = Regime::Cercle;
    private int $lecteur = 0;

    /** @var list<int> visibles de tous : le fait historique */
    private array $decedees = [];

    /** @var array<int, list<int>> userId ⇒ personnes vivantes de son cercle */
    private array $cercles = [];

    /** @var list<int> comptes à cercle complet — c'est ainsi que `Membership` traitera un administrateur */
    private array $cerclesComplets = [];

    public function lecteurEst(int $userId): self
    {
        $this->lecteur = $userId;

        return $this;
    }

    public function regimeEst(Regime $regime): self
    {
        $this->regime = $regime;

        return $this;
    }

    public function decedee(int $personId): self
    {
        $this->decedees[] = $personId;

        return $this;
    }

    public function dansLeCercleDe(int $userId, int $personId): self
    {
        $this->cercles[$userId][] = $personId;

        return $this;
    }

    /**
     * Retire une personne du cercle d'un lecteur.
     *
     * En production, un cercle ne se « vide » pas : il se **recalcule** dès que
     * l'empreinte de l'arbre change — un décès saisi, une filiation validée, le réglage
     * `cercle.generations` abaissé. Cette méthode joue ce recalcul du point de vue qui
     * intéresse les tests : ce qu'un lecteur voyait hier et ne voit plus aujourd'hui.
     */
    public function horsDuCercleDe(int $userId, int $personId): self
    {
        $this->cercles[$userId] = array_values(array_filter(
            $this->cercles[$userId] ?? [],
            static fn (int $connu): bool => $connu !== $personId,
        ));

        return $this;
    }

    /**
     * L'administrateur n'est pas une exception au cercle : il en a un **complet**. C'est
     * la forme que prend « l'administrateur voit tout » une fois qu'on a renoncé au
     * `if ($estAdmin) { return true; }` que le contrat du noyau interdit.
     */
    public function cercleComplet(int $userId): self
    {
        $this->cerclesComplets[] = $userId;

        return $this;
    }

    public function regime(): Regime
    {
        return $this->regime;
    }

    /** Un seul arbre dans ce double : tout est dans le périmètre. */
    public function dansLePerimetre(int $personId): bool
    {
        return true;
    }

    public function voitPersonne(int $personId): bool
    {
        if (\in_array($this->lecteur, $this->cerclesComplets, true)) {
            return true;
        }

        if (\in_array($personId, $this->decedees, true)) {
            return true;
        }

        if (Regime::Aucun === $this->regime) {
            return false;
        }

        return \in_array($personId, $this->cercles[$this->lecteur] ?? [], true);
    }

    public function filtrerPersonnes(array $personIds): array
    {
        return array_values(array_filter(
            array_unique($personIds),
            fn (int $personId): bool => $this->voitPersonne($personId),
        ));
    }
}

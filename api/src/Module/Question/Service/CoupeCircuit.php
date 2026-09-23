<?php

declare(strict_types=1);

namespace App\Module\Question\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le coupe-circuit de l'appel sortant : au-delà de quelques échecs consécutifs, on
 * cesse d'appeler pendant un moment, et l'on se rouvre seul.
 *
 * ## Le défaut qu'il ferme, et pourquoi il n'est pas théorique
 *
 * Sans lui, un fournisseur en panne ou un quota épuisé coûte **un aller-retour raté par
 * lacune nouvelle, à chaque ouverture de page, par chaque membre**. Le délai d'attente
 * est de quelques secondes : vingt lacunes font une page qui met une minute à s'afficher
 * — pour finalement servir les phrases du repli, celles qu'on aurait eues tout de suite.
 *
 * Le pire n'est pas la lenteur, c'est qu'**elle ne ressemble pas à une panne du tiers**.
 * Elle ressemble à une application lente.
 *
 * ## Pas de table, pas de tâche de fond, pas de nettoyage
 *
 * Un compteur en cache, avec une durée de vie. Il se périme tout seul — il n'y a donc
 * ni ligne à supprimer, ni balayage à programmer, ni état à réparer si le cache est
 * vidé : un cache vide se lit « passant », ce qui est exactement le bon défaut. Perdre
 * la mémoire d'une panne coûte un aller-retour ; garder par erreur la mémoire d'une
 * panne réparée coûterait des heures de repli silencieux.
 *
 * ## Il ne lève jamais, et n'alerte jamais
 *
 * Ouvert ou fermé, la page sert des questions — c'est tout l'objet du repli. Le
 * coupe-circuit n'a donc aucune raison de faire du bruit : ce qui se lit, quand on veut
 * savoir, est `SELECT redacteur, count(*) FROM qst_question GROUP BY 1`.
 */
final class CoupeCircuit
{
    /**
     * Trois échecs d'affilée, et l'on coupe.
     *
     * Ni un — un délai d'attente isolé arrive sur un réseau sain, et couper dessus
     * ferait basculer au repli une installation qui fonctionne —, ni dix — à dix, on a
     * déjà fait attendre dix pages.
     */
    public const int ECHECS_AVANT_COUPURE = 3;

    /**
     * Cinq minutes de coupure.
     *
     * Assez pour qu'une panne passagère se calme sans qu'on la repique en boucle, assez
     * peu pour qu'une panne **réparée** ne laisse pas la page en repli tout l'après-midi.
     * Le chiffre est une intuition ; il vit dans une constante et non dans un réglage
     * d'administration parce qu'aucun administrateur n'a de raison de le connaître —
     * contrairement à `cercle.generations`, qui décide de ce qu'on voit.
     */
    public const int SECONDES_DE_COUPURE = 300;

    private const string CLE = 'questions.gemini.echecs';

    /**
     * Le pool est nommé par attribut et non en YAML : une définition concrète dans un
     * fichier de module **remplace** celle du prototype `App\:` au lieu de la compléter,
     * autowiring compris (voir l'en-tête de `RedactionParGemini`).
     *
     * On partage le pool de `Tree` — un cache de service sur disque. En poser un de plus
     * pour un unique compteur d'échecs coûterait un fichier de configuration pour rien.
     */
    public function __construct(
        #[Autowire(service: 'cache.arbre')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /** Peut-on appeler le tiers ? Un cache vide, ou illisible, répond **oui**. */
    public function passant(): bool
    {
        return $this->echecs() < self::ECHECS_AVANT_COUPURE;
    }

    /**
     * Un appel a réussi : on oublie les échecs.
     *
     * **On remet à zéro plutôt qu'on ne décrémente.** Deux échecs suivis d'un succès
     * suivi d'un échec ne sont pas trois échecs consécutifs, et c'est la consécutivité
     * qui distingue une panne d'un aléa. Un compteur qui ne se remet pas à zéro finit
     * par couper une installation parfaitement saine, au bout d'assez de semaines.
     */
    public function succes(): void
    {
        $this->cache->deleteItem(self::CLE);
    }

    public function echec(): void
    {
        $element = $this->cache->getItem(self::CLE);

        $element->set($this->echecs() + 1);
        // La durée est **réarmée à chaque échec** : la fenêtre glisse, elle ne court pas
        // depuis le premier. Sans cela, trois échecs espacés de quatre minutes
        // couperaient, puis la coupure expirerait une minute plus tard.
        $element->expiresAfter(self::SECONDES_DE_COUPURE);

        $this->cache->save($element);
    }

    private function echecs(): int
    {
        $valeur = $this->cache->getItem(self::CLE)->get();

        return \is_int($valeur) ? $valeur : 0;
    }
}

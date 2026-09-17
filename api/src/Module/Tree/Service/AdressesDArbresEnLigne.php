<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * LES ADRESSES D'ARBRES EN LIGNE — reconnues pour être **retirées de l'affichage**,
 * jamais de la base.
 *
 * ## Ce que le commanditaire a demandé, dans ses mots
 *
 * > « MyHeritage, retire tous les liens vers ces URL, ça sert à rien — ou du moins ne les
 * > affiche pas aux utilisateurs, garde-les en DB uniquement pour l'IA. »
 *
 * Deux exigences, et la seconde contraint la première : **on masque au rendu, on n'efface
 * rien**. Une URL retirée de `tree_citation.page` serait retirée pour tout le monde, y
 * compris pour la machine qui s'en sert de piste, et un `UPDATE` ne se défait pas.
 *
 * ## Pourquoi une liste fermée de domaines, et non « toute adresse »
 *
 * Parce que les deux familles d'URL de ce fonds ne se valent pas, et que la mesure le dit.
 * Les 109 adresses de l'arbre se répartissent ainsi :
 *
 * | domaine                           | nombre | ce que c'est                       |
 * |-----------------------------------|-------:|------------------------------------|
 * | `myheritage.fr`                   |     71 | arbre en ligne                     |
 * | `sites-cf.mhcache.com`            |     31 | **sites familiaux hébergés par MyHeritage** |
 * | `filae.com`                       |      1 | arbre en ligne                     |
 * | `archives.dordogne.fr`            |      3 | **visionneuse d'archives**         |
 * | `recherche.archives.finistere.fr` |      3 | **visionneuse d'archives**         |
 *
 * Masquer « toute adresse » emporterait les six dernières, qui sont exactement ce qu'on
 * veut montrer : le point d'entrée du registre numérisé où le chercheur suivant ira lire
 * l'acte lui-même. Et le sens du travail en cours est d'en **ajouter**, pas d'en cacher.
 *
 * `sites-cf.mhcache.com` mérite sa ligne : c'est le cache de sites de MyHeritage, et c'est
 * là que vivent les « sites web de famille » — ceux qui, dans `tree_source`, sont classés
 * `autre` faute de mieux. Une liste qui ne connaîtrait que `myheritage.fr` en laisserait
 * passer trente et un.
 *
 * ## Les trois domaines absents de ce fonds, et pourquoi ils sont là quand même
 *
 * `geneanet.org`, `ancestry` et `geni.com` n'apparaissent dans aucune URL de l'arbre
 * aujourd'hui — Geneanet y est cité 74 fois, mais par son titre, sans lien. Ils sont dans
 * la liste parce que le prochain contributeur qui collera une adresse collera l'une de
 * celles-là, et qu'une liste qui n'attrape que ce qu'on a déjà vu est une liste qu'il faut
 * rouvrir à chaque dépôt.
 *
 * ## FamilySearch n'y est pas, et c'est une décision
 *
 * Le site héberge **à la fois** un arbre collaboratif et des millions de registres
 * numérisés. Un lien vers une vue de registre y est une pièce de première main ; un lien
 * vers une fiche de l'arbre n'en est pas une. Les distinguer demanderait de lire le chemin
 * de l'URL et non son domaine, donc de faire reposer la règle sur une structure d'URL
 * qu'un site change sans prévenir. L'arbre en compte **une seule citation** : on la laisse
 * visible et on le dit, plutôt que de masquer à l'aveugle une adresse qui mène peut-être à
 * un acte.
 *
 * ## Deux colonnes portent des adresses et ne passent par aucun masquage
 *
 * `tree_source.note` (6 adresses) et `tree_source.text` (1) ne sont **pas sérialisés vers la
 * fiche** : `NormaliseurDePersonne::citations()` ne rend de la source que `id`, `title`,
 * `author` et `sourceKind`. Ces sept adresses n'atteignent donc aucun écran aujourd'hui, et
 * **c'est la seule raison pour laquelle elles ne sont pas masquées** — pas une décision.
 *
 * Le jour où une route exposera ces colonnes — un écran de sources, une fiche de fonds — elle
 * exposera sept adresses d'un coup, et **rien ne l'en avertira** : le champ « marche » déjà
 * partout ailleurs. Ce nettoyage devra alors être appliqué au même endroit que pour `page` et
 * `dataText`.
 *
 * Les six de `note` sont, aujourd'hui, des adresses de **visionneuses d'archives** — celles
 * qu'on veut voir. La liste fermée les laisse passer. Mais l'écran qui les exposera devra
 * quand même passer par ici, sans quoi la septième adresse déposée demain ne sera pas filtrée.
 */
final class AdressesDArbresEnLigne
{
    /**
     * Les domaines dont les adresses ne s'affichent pas.
     *
     * Comparés en **suffixe de nom d'hôte**, ce qui couvre les sous-domaines — `www.`,
     * `sites-cf.`, `fr.` — sans qu'aucun n'ait à être écrit. Un point est exigé devant le
     * suffixe pour qu'un domaine ne puisse pas être attrapé par un autre qui finit par les
     * mêmes lettres : `monheritage.fr` n'est pas `myheritage.fr`.
     *
     * @var list<string>
     */
    private const array DOMAINES = [
        'myheritage.fr',
        'myheritage.com',
        'mhcache.com',
        'filae.com',
        'geneanet.org',
        'geni.com',
        'ancestry.fr',
        'ancestry.com',
        'ancestry.co.uk',
        'gedbas.genealogy.net',
        'rodovid.org',
        'wikitree.com',
        'genealogieonline.nl',
        'heredis-online.com',
        'genealogy.com',
    ];

    /**
     * Les morceaux de chemin qui désignent un **relevé**, et non la fiche d'un arbre.
     *
     * Ils vivent sur les mêmes domaines : `myheritage.fr/research/collection-20396/…` est
     * le recensement français de 1931, `myheritage.fr/profile-…` est une personne dans
     * l'arbre d'un inconnu. Le domaine ne les sépare pas, le chemin si.
     *
     * `registres` couvre Geneanet, qui publie des images de registres sous ce préfixe —
     * aucune dans ce fonds aujourd'hui, mais la liste sert à ce qui arrivera.
     *
     * @var list<string>
     */
    private const array CHEMINS_DE_RELEVE = [
        '/research/',
        '/registres/',
        '/collection',
        '/record',
    ];

    /**
     * Le motif qui reconnaît une adresse dans du texte courant.
     *
     * Il s'arrête aux espaces et à la ponctuation fermante, parce qu'une URL est **collée
     * au milieu d'une phrase** dans ce fonds : « Point d'entrée : https://… — notice
     * ark:/… pour le registre ». Emporter le tiret ou le point final couperait la phrase.
     */
    private const string MOTIF_URL = '~https?://[^\s<>"\']+~i';

    /**
     * Le texte, débarrassé des seules adresses d'arbres en ligne.
     *
     * Rend `null` **quand il ne reste rien** : un `page` réduit à « , vue 1/6 » après
     * découpe serait pire que rien, et une chaîne vide s'affiche comme un champ présent et
     * muet. Le § 1.3 du contrat d'API distingue l'absent du vide, et c'est l'absent qu'on
     * veut ici.
     *
     * **Ce qui n'est pas une URL d'arbre reste intact, à la lettre près.** `page` porte les
     * cotes d'archives — « 5 E 341/7, vue 1/6 » — et elles sont la seule chose de ce champ
     * qui serve à retrouver l'acte.
     */
    public function nettoyer(?string $texte): ?string
    {
        if (null === $texte || '' === trim($texte)) {
            return null;
        }

        $sansUrl = preg_replace_callback(
            self::MOTIF_URL,
            fn (array $trouve): string => $this->estUnArbreEnLigne($trouve[0]) ? '' : $trouve[0],
            $texte,
        ) ?? $texte;

        if ($sansUrl === $texte) {
            return $texte;
        }

        $recousu = $this->recoudre($sansUrl);

        // Le champ ne portait que l'adresse : on rend `null` et non `''`. Une chaîne vide
        // s'affiche comme un champ présent et muet, là où l'absence retire la ligne —
        // c'est la distinction du § 1.3, et elle décide de ce que l'écran dessine.
        return '' === $recousu ? null : $recousu;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **CETTE ADRESSE DÉSIGNE-T-ELLE LA FICHE D'UN ARBRE, ET NON UN RELEVÉ ?**
     *
     * Plus stricte que {@see estUnArbreEnLigne()}, et les deux ne servent pas à la même
     * chose. Celle-ci ne répond `true` que pour une adresse dont le **chemin** montre
     * qu'elle mène à une personne dans l'arbre de quelqu'un.
     *
     * ## Pourquoi il faut deux questions, et non une
     *
     * Parce que MyHeritage héberge **deux choses qui n'ont rien à voir** sous le même
     * domaine, et que la mesure du fonds le dit sans ambiguïté :
     *
     * | chemin | nombre | ce que c'est |
     * |---|---:|---|
     * | `/research/collection-…` | **53** | un **relevé indexé** : recensement de 1921, de 1931, index des décès |
     * | `sites-cf.mhcache.com/site-…` | 31 | un site de famille — un arbre |
     * | `/profile-…` | 18 | une personne dans l'arbre de quelqu'un |
     *
     * **Le plus gros groupe n'est pas un arbre.** « Recensement français de 1931 » est un
     * relevé nominatif que MyHeritage publie ; il vaut ce que vaut un index — quelqu'un
     * avait le registre sous les yeux et a recopié — et certainement pas ce que vaut un
     * arbre recopié d'un autre arbre.
     *
     * Confondre les deux ferait **écrire une fausseté en base** : reclasser un recensement
     * en `arbre_tiers` le ferait afficher « tenu par un arbre en ligne », ce qu'il n'est
     * pas, et ferait tomber d'un cran la confiance de huit citations qui ne le méritent
     * pas. Une donnée fausse entrée par un outil ne se rattrape pas : c'est exactement ce
     * que ce lot répare, et on ne le rejoue pas dans l'autre sens.
     *
     * ## Ce que cette distinction ne change pas
     *
     * **Le masquage reste au domaine.** Le commanditaire a demandé de retirer les liens
     * MyHeritage, sans distinguer — « ça sert à rien, ou du moins ne les affiche pas aux
     * utilisateurs » —, et un lien vers une collection payante n'est pas plus utile à un
     * lecteur qu'un lien vers l'arbre d'un inconnu. Rien n'est perdu : la base garde
     * l'adresse, et `page` décrit toujours le relevé.
     *
     * Les deux règles divergent donc, et c'est délibéré : **on masque un lien sur ce qu'il
     * coûte à l'écran, on classe une source sur ce qu'elle est.**
     */
    public function designeUneFicheDArbre(string $url): bool
    {
        if (!$this->estUnArbreEnLigne($url)) {
            return false;
        }

        $chemin = strtolower((string) (parse_url(trim($url), \PHP_URL_PATH) ?? ''));

        foreach (self::CHEMINS_DE_RELEVE as $marqueur) {
            if (str_contains($chemin, $marqueur)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cette adresse mène-t-elle à l'un des domaines de la liste ?
     *
     * C'est la question du **masquage**, et elle s'arrête au nom d'hôte. Pour savoir si
     * une adresse prouve qu'une source est un arbre, voir {@see designeUneFicheDArbre()},
     * qui regarde le chemin.
     */
    public function estUnArbreEnLigne(string $url): bool
    {
        $hote = parse_url(trim($url), \PHP_URL_HOST);

        if (!\is_string($hote) || '' === $hote) {
            return false;
        }

        $hote = strtolower(rtrim($hote, '.'));

        foreach (self::DOMAINES as $domaine) {
            // Le domaine lui-même, ou n'importe lequel de ses sous-domaines. Le point
            // devant le suffixe est ce qui empêche `monheritage.fr` de passer pour
            // `myheritage.fr` — un suffixe nu attraperait les deux.
            if ($hote === $domaine || str_ends_with($hote, '.'.$domaine)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le texte recousu après découpe : ponctuation orpheline retirée, espaces resserrés.
     *
     * Retirer une URL au milieu d'une phrase laisse des cicatrices — « Voir : , vue 1/6 »,
     * un tiret cadratin suivi de rien, deux espaces. Elles se voient à l'écran et donnent
     * l'impression d'un texte tronqué par accident plutôt que d'un choix.
     */
    private function recoudre(string $texte): string
    {
        // Un séparateur laissé en fin de ligne, puis un séparateur laissé en tête.
        $propre = preg_replace('~[ \t]*[—–\-:;,]+[ \t]*(?=$|\R)~u', '', $texte) ?? $texte;

        // ── `^` avec `/m`, et surtout **pas** `(?<=^|\R)` ─────────────────────────────
        //
        // La forme évidente — une assertion arrière disant « début de chaîne **ou** après
        // un saut de ligne » — est **illégale en PCRE** : ses deux branches n'ont pas la
        // même longueur, `^` étant de largeur nulle et `\R` valant un ou deux caractères.
        // PCRE2 ne l'accepte que depuis la 10.43 ; en deçà, `preg_replace` refuse de
        // compiler, émet un avertissement et rend **`null`**.
        //
        // Et `null` ici ne serait pas une panne visible : `?? $propre` le rattraperait, le
        // texte ressortirait sans sa couture, et l'adresse aurait quand même été retirée.
        // Un défaut silencieux sur la ligne même qui masque les adresses.
        //
        // **Ce n'est pas une hypothèse.** La version écrite d'abord compilait ici — PCRE
        // 10.44 — et a été refusée par l'intégration continue, dont la bibliothèque est
        // antérieure. Même code, même analyseur, même niveau : `phpstan` était vert des
        // deux côtés. C'est la bibliothèque qui diffère, et elle ne se voit pas.
        //
        // `^` avec le modificateur `/m` dit exactement la même chose — début de chaîne ou
        // après un saut de ligne, sans rien consommer —, se compile partout, et a été
        // vérifié équivalent sur les dix formes de ce fonds, terminaisons `\r\n` comprises.
        $propre = preg_replace('~^[ \t]*[—–\-:;,]+[ \t]*~mu', '', $propre) ?? $propre;
        // Deux espaces là où l'adresse en séparait deux mots.
        $propre = preg_replace('~[ \t]{2,}~', ' ', $propre) ?? $propre;
        // Une parenthèse restée seule autour de ce qui était l'adresse.
        $propre = preg_replace('~\(\s*\)~', '', $propre) ?? $propre;

        $propre = trim($propre, " \t\n\r\0\x0B");

        return '' === $propre ? '' : $propre;
    }
}

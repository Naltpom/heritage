<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Service\CleDeRecherche;

/**
 * Les noms de pays **en français**, vers leur code ISO 3166-1 alpha-2 — ce qui permet à
 * {@see AppariementDesLieux} de reconnaître le pays cité au bout d'une chaîne `PLAC`,
 * quel qu'il soit, et non plus seulement parmi une cinquantaine.
 *
 * ── D'OÙ VIENT LA TABLE ─────────────────────────────────────────────────────────
 *
 * {@see self::CLDR} est la liste `fr` des territoires du **CLDR** d'Unicode (ICU 78.3),
 * telle que `symfony/intl` 8.1.5 l'embarque — 249 entrées, recopiées **verbatim** et
 * figées ici le 2026-09-24. Licence : Unicode License v3, qui autorise la copie avec la
 * mention de provenance ci-dessus.
 *
 * ── POURQUOI UNE TABLE RECOPIÉE ET NON `symfony/intl` EN DÉPENDANCE ────────────
 *
 * Essayé, et écarté sur une mesure : `Countries::getNames('fr')` de `symfony/intl` 8
 * **exige l'extension `intl`** dès le premier appel (`Symfony\Component\Intl\Locale`
 * lève « the "intl" extension is not installed »). L'image Docker l'a ; le PHP du poste
 * de développement, sur lequel tourne toute la suite de tests, ne l'a pas. Une
 * dépendance qui ferait tomber la suite en local pour une table de 249 lignes qui ne
 * bouge qu'au gré des traités n'est pas un bon marché.
 *
 * C'est la même règle que les fichiers de `ressources/gazetteer/` : une donnée publique
 * de référence, **versionnée**, lue sans réseau, avec sa provenance écrite à côté.
 *
 * ── RÉGÉNÉRER ───────────────────────────────────────────────────────────────────
 *
 * Dans le conteneur de l'API, qui porte l'extension `intl` :
 *
 *     docker compose run --rm --no-deps --entrypoint php api -r '
 *       foreach (ResourceBundle::create("fr", "ICUDATA-region")["Countries"] as $c => $n)
 *         if (preg_match("/^[A-Z]{2}$/", $c) && !in_array($c, ["EU","EZ","QO","UN","XA","XB","XK","ZZ"], true))
 *           echo var_export($c, true), " => ", var_export($n, true), ",\n";'
 *
 * puis remplacer le contenu de {@see self::CLDR} et mettre à jour la version d'ICU
 * ci-dessus. Le filtre retire ce que le CLDR nomme sans que ce soit un pays (l'Union
 * européenne, la zone euro, « régions éloignées de l'Océanie »…) — la liste que
 * `symfony/intl` écarte elle aussi.
 *
 * ── LES ALIAS HISTORIQUES : CE QUE LE CLDR NE DIRA JAMAIS ───────────────────────
 *
 * Un GEDCOM français écrit « Angleterre », « Écosse », « Hollande », « Zaïre » : des
 * noms que le CLDR ne connaît pas, parce qu'ils ne désignent pas — ou plus — un État. La
 * table {@see self::ALIAS} les porte, **et l'emporte sur le CLDR en cas de conflit**.
 * Elle reprend telles quelles les entrées de l'ancienne table `PAYS` de
 * {@see AppariementDesLieux}, dont `CONGO → CG` et `VIETNAM → VN` (le CLDR écrit
 * « Congo-Brazzaville » et « Viêt Nam », qui se replient autrement).
 *
 * **N'y ajouter que des noms sans ambiguïté.** « Tchécoslovaquie », « URSS »,
 * « Prusse », « Indochine » ne désignent aucun pays actuel à eux seuls : leur donner un
 * code serait choisir à la place du lecteur, exactement ce que la passe D refuse.
 */
final class NomsDesPays
{
    /**
     * Alias historiques et graphies courantes, **déjà repliés** par
     * {@see CleDeRecherche::replier()}.
     *
     * @var array<string, string> clé repliée → code ISO2
     */
    public const array ALIAS = [
        // L'ancienne table `PAYS` d'AppariementDesLieux, reprise à l'identique.
        'FRANCE' => 'FR', 'ALGERIE' => 'DZ', 'MAROC' => 'MA', 'TUNISIE' => 'TN',
        'BELGIQUE' => 'BE', 'SUISSE' => 'CH', 'LUXEMBOURG' => 'LU', 'ALLEMAGNE' => 'DE',
        'ITALIE' => 'IT', 'ESPAGNE' => 'ES', 'PORTUGAL' => 'PT', 'PAYS BAS' => 'NL',
        'ROYAUME UNI' => 'GB', 'ANGLETERRE' => 'GB', 'ECOSSE' => 'GB', 'IRLANDE' => 'IE',
        'AUTRICHE' => 'AT', 'POLOGNE' => 'PL', 'HONGRIE' => 'HU', 'ROUMANIE' => 'RO',
        'GRECE' => 'GR', 'TURQUIE' => 'TR', 'RUSSIE' => 'RU', 'UKRAINE' => 'UA',
        'SUEDE' => 'SE', 'NORVEGE' => 'NO', 'DANEMARK' => 'DK', 'FINLANDE' => 'FI',
        'TCHEQUIE' => 'CZ', 'SLOVAQUIE' => 'SK', 'CROATIE' => 'HR', 'SERBIE' => 'RS',
        'CANADA' => 'CA', 'ETATS UNIS' => 'US', 'MEXIQUE' => 'MX', 'BRESIL' => 'BR',
        'ARGENTINE' => 'AR', 'CHILI' => 'CL', 'SENEGAL' => 'SN', 'MALI' => 'ML',
        'COTE D IVOIRE' => 'CI', 'CAMEROUN' => 'CM', 'MADAGASCAR' => 'MG', 'CONGO' => 'CG',
        'LIBAN' => 'LB', 'SYRIE' => 'SY', 'ISRAEL' => 'IL', 'EGYPTE' => 'EG',
        'INDE' => 'IN', 'CHINE' => 'CN', 'JAPON' => 'JP', 'VIETNAM' => 'VN',
        'AUSTRALIE' => 'AU', 'NOUVELLE ZELANDE' => 'NZ',

        // Les nations du Royaume-Uni, et les noms d'usage qu'un acte ancien emploie.
        'PAYS DE GALLES' => 'GB', 'IRLANDE DU NORD' => 'GB', 'GRANDE BRETAGNE' => 'GB',
        'HOLLANDE' => 'NL', 'REPUBLIQUE TCHEQUE' => 'CZ', 'ETATS UNIS D AMERIQUE' => 'US',
        'USA' => 'US', 'BIRMANIE' => 'MM', 'ILE MAURICE' => 'MU', 'REUNION' => 'RE',
        'GUYANE' => 'GF',

        // Des noms d'avant les indépendances, qui ne désignent qu'un seul État actuel.
        'ZAIRE' => 'CD', 'DAHOMEY' => 'BJ', 'HAUTE VOLTA' => 'BF', 'CEYLAN' => 'LK',
        'SIAM' => 'TH', 'PERSE' => 'IR',
    ];

    /**
     * Les noms français du CLDR, tels quels — voir l'en-tête pour la provenance. Table
     * **générée** : ne pas la retoucher à la main, la régénérer.
     *
     * @var array<string, string> code ISO2 → nom
     */
    public const array CLDR = [
        'AD' => 'Andorre',
        'AE' => 'Émirats arabes unis',
        'AF' => 'Afghanistan',
        'AG' => 'Antigua-et-Barbuda',
        'AI' => 'Anguilla',
        'AL' => 'Albanie',
        'AM' => 'Arménie',
        'AO' => 'Angola',
        'AQ' => 'Antarctique',
        'AR' => 'Argentine',
        'AS' => 'Samoa américaines',
        'AT' => 'Autriche',
        'AU' => 'Australie',
        'AW' => 'Aruba',
        'AX' => 'Îles Åland',
        'AZ' => 'Azerbaïdjan',
        'BA' => 'Bosnie-Herzégovine',
        'BB' => 'Barbade',
        'BD' => 'Bangladesh',
        'BE' => 'Belgique',
        'BF' => 'Burkina Faso',
        'BG' => 'Bulgarie',
        'BH' => 'Bahreïn',
        'BI' => 'Burundi',
        'BJ' => 'Bénin',
        'BL' => 'Saint-Barthélemy',
        'BM' => 'Bermudes',
        'BN' => 'Brunei',
        'BO' => 'Bolivie',
        'BQ' => 'Pays-Bas caribéens',
        'BR' => 'Brésil',
        'BS' => 'Bahamas',
        'BT' => 'Bhoutan',
        'BV' => 'Île Bouvet',
        'BW' => 'Botswana',
        'BY' => 'Biélorussie',
        'BZ' => 'Belize',
        'CA' => 'Canada',
        'CC' => 'Îles Cocos',
        'CD' => 'Congo-Kinshasa',
        'CF' => 'République centrafricaine',
        'CG' => 'Congo-Brazzaville',
        'CH' => 'Suisse',
        'CI' => 'Côte d’Ivoire',
        'CK' => 'Îles Cook',
        'CL' => 'Chili',
        'CM' => 'Cameroun',
        'CN' => 'Chine',
        'CO' => 'Colombie',
        'CR' => 'Costa Rica',
        'CU' => 'Cuba',
        'CV' => 'Cap-Vert',
        'CW' => 'Curaçao',
        'CX' => 'Île Christmas',
        'CY' => 'Chypre',
        'CZ' => 'Tchéquie',
        'DE' => 'Allemagne',
        'DJ' => 'Djibouti',
        'DK' => 'Danemark',
        'DM' => 'Dominique',
        'DO' => 'République dominicaine',
        'DZ' => 'Algérie',
        'EC' => 'Équateur',
        'EE' => 'Estonie',
        'EG' => 'Égypte',
        'EH' => 'Sahara occidental',
        'ER' => 'Érythrée',
        'ES' => 'Espagne',
        'ET' => 'Éthiopie',
        'FI' => 'Finlande',
        'FJ' => 'Fidji',
        'FK' => 'Îles Malouines',
        'FM' => 'Micronésie',
        'FO' => 'Îles Féroé',
        'FR' => 'France',
        'GA' => 'Gabon',
        'GB' => 'Royaume-Uni',
        'GD' => 'Grenade',
        'GE' => 'Géorgie',
        'GF' => 'Guyane française',
        'GG' => 'Guernesey',
        'GH' => 'Ghana',
        'GI' => 'Gibraltar',
        'GL' => 'Groenland',
        'GM' => 'Gambie',
        'GN' => 'Guinée',
        'GP' => 'Guadeloupe',
        'GQ' => 'Guinée équatoriale',
        'GR' => 'Grèce',
        'GS' => 'Géorgie du Sud-et-les Îles Sandwich du Sud',
        'GT' => 'Guatemala',
        'GU' => 'Guam',
        'GW' => 'Guinée-Bissau',
        'GY' => 'Guyana',
        'HK' => 'R.A.S. chinoise de Hong Kong',
        'HM' => 'Îles Heard-et-MacDonald',
        'HN' => 'Honduras',
        'HR' => 'Croatie',
        'HT' => 'Haïti',
        'HU' => 'Hongrie',
        'ID' => 'Indonésie',
        'IE' => 'Irlande',
        'IL' => 'Israël',
        'IM' => 'Île de Man',
        'IN' => 'Inde',
        'IO' => 'Territoire britannique de l’océan Indien',
        'IQ' => 'Irak',
        'IR' => 'Iran',
        'IS' => 'Islande',
        'IT' => 'Italie',
        'JE' => 'Jersey',
        'JM' => 'Jamaïque',
        'JO' => 'Jordanie',
        'JP' => 'Japon',
        'KE' => 'Kenya',
        'KG' => 'Kirghizstan',
        'KH' => 'Cambodge',
        'KI' => 'Kiribati',
        'KM' => 'Comores',
        'KN' => 'Saint-Christophe-et-Niévès',
        'KP' => 'Corée du Nord',
        'KR' => 'Corée du Sud',
        'KW' => 'Koweït',
        'KY' => 'Îles Caïmans',
        'KZ' => 'Kazakhstan',
        'LA' => 'Laos',
        'LB' => 'Liban',
        'LC' => 'Sainte-Lucie',
        'LI' => 'Liechtenstein',
        'LK' => 'Sri Lanka',
        'LR' => 'Liberia',
        'LS' => 'Lesotho',
        'LT' => 'Lituanie',
        'LU' => 'Luxembourg',
        'LV' => 'Lettonie',
        'LY' => 'Libye',
        'MA' => 'Maroc',
        'MC' => 'Monaco',
        'MD' => 'Moldavie',
        'ME' => 'Monténégro',
        'MF' => 'Saint-Martin',
        'MG' => 'Madagascar',
        'MH' => 'Îles Marshall',
        'MK' => 'Macédoine du Nord',
        'ML' => 'Mali',
        'MM' => 'Myanmar (Birmanie)',
        'MN' => 'Mongolie',
        'MO' => 'R.A.S. chinoise de Macao',
        'MP' => 'Îles Mariannes du Nord',
        'MQ' => 'Martinique',
        'MR' => 'Mauritanie',
        'MS' => 'Montserrat',
        'MT' => 'Malte',
        'MU' => 'Maurice',
        'MV' => 'Maldives',
        'MW' => 'Malawi',
        'MX' => 'Mexique',
        'MY' => 'Malaisie',
        'MZ' => 'Mozambique',
        'NA' => 'Namibie',
        'NC' => 'Nouvelle-Calédonie',
        'NE' => 'Niger',
        'NF' => 'Île Norfolk',
        'NG' => 'Nigeria',
        'NI' => 'Nicaragua',
        'NL' => 'Pays-Bas',
        'NO' => 'Norvège',
        'NP' => 'Népal',
        'NR' => 'Nauru',
        'NU' => 'Niue',
        'NZ' => 'Nouvelle-Zélande',
        'OM' => 'Oman',
        'PA' => 'Panama',
        'PE' => 'Pérou',
        'PF' => 'Polynésie française',
        'PG' => 'Papouasie-Nouvelle-Guinée',
        'PH' => 'Philippines',
        'PK' => 'Pakistan',
        'PL' => 'Pologne',
        'PM' => 'Saint-Pierre-et-Miquelon',
        'PN' => 'Îles Pitcairn',
        'PR' => 'Porto Rico',
        'PS' => 'Territoires palestiniens',
        'PT' => 'Portugal',
        'PW' => 'Palaos',
        'PY' => 'Paraguay',
        'QA' => 'Qatar',
        'RE' => 'La Réunion',
        'RO' => 'Roumanie',
        'RS' => 'Serbie',
        'RU' => 'Russie',
        'RW' => 'Rwanda',
        'SA' => 'Arabie saoudite',
        'SB' => 'Îles Salomon',
        'SC' => 'Seychelles',
        'SD' => 'Soudan',
        'SE' => 'Suède',
        'SG' => 'Singapour',
        'SH' => 'Sainte-Hélène',
        'SI' => 'Slovénie',
        'SJ' => 'Svalbard et Jan Mayen',
        'SK' => 'Slovaquie',
        'SL' => 'Sierra Leone',
        'SM' => 'Saint-Marin',
        'SN' => 'Sénégal',
        'SO' => 'Somalie',
        'SR' => 'Suriname',
        'SS' => 'Soudan du Sud',
        'ST' => 'Sao Tomé-et-Principe',
        'SV' => 'Salvador',
        'SX' => 'Saint-Martin (partie néerlandaise)',
        'SY' => 'Syrie',
        'SZ' => 'Eswatini',
        'TC' => 'Îles Turques-et-Caïques',
        'TD' => 'Tchad',
        'TF' => 'Terres australes françaises',
        'TG' => 'Togo',
        'TH' => 'Thaïlande',
        'TJ' => 'Tadjikistan',
        'TK' => 'Tokelau',
        'TL' => 'Timor oriental',
        'TM' => 'Turkménistan',
        'TN' => 'Tunisie',
        'TO' => 'Tonga',
        'TR' => 'Turquie',
        'TT' => 'Trinité-et-Tobago',
        'TV' => 'Tuvalu',
        'TW' => 'Taïwan',
        'TZ' => 'Tanzanie',
        'UA' => 'Ukraine',
        'UG' => 'Ouganda',
        'UM' => 'Îles mineures éloignées des États-Unis',
        'US' => 'États-Unis',
        'UY' => 'Uruguay',
        'UZ' => 'Ouzbékistan',
        'VA' => 'État de la Cité du Vatican',
        'VC' => 'Saint-Vincent-et-les Grenadines',
        'VE' => 'Venezuela',
        'VG' => 'Îles Vierges britanniques',
        'VI' => 'Îles Vierges des États-Unis',
        'VN' => 'Viêt Nam',
        'VU' => 'Vanuatu',
        'WF' => 'Wallis-et-Futuna',
        'WS' => 'Samoa',
        'YE' => 'Yémen',
        'YT' => 'Mayotte',
        'ZA' => 'Afrique du Sud',
        'ZM' => 'Zambie',
        'ZW' => 'Zimbabwe',
    ];

    /**
     * L'index de lecture : nom **replié** → code ISO2, CLDR et alias réunis.
     *
     * Trois règles, dans cet ordre :
     *
     * 1. chaque nom du CLDR, replié ;
     * 2. pour un nom qui porte une précision entre parenthèses — « Myanmar (Birmanie) »,
     *    « Saint-Martin (partie néerlandaise) » —, la partie qui précède la parenthèse,
     *    **seulement si aucun autre pays ne la porte déjà**. C'est ce qui fait reconnaître
     *    « Myanmar » sans laisser « Saint-Martin » désigner la partie néerlandaise plutôt
     *    que la française, qui a son propre nom au CLDR ;
     * 3. les alias, qui **l'emportent** : ce sont des décisions humaines, écrites une par
     *    une, là où le CLDR n'est qu'une liste.
     *
     * @return array<string, string>
     */
    public static function index(CleDeRecherche $cles = new CleDeRecherche()): array
    {
        $index = [];

        foreach (self::CLDR as $code => $nom) {
            $index[$cles->replier($nom)] = $code;
        }

        foreach (self::CLDR as $code => $nom) {
            $avant = strstr($nom, '(', true);

            if (false === $avant) {
                continue;
            }

            $replie = $cles->replier($avant);

            if ('' !== $replie && !isset($index[$replie])) {
                $index[$replie] = $code;
            }
        }

        return [...$index, ...self::ALIAS];
    }
}

/**
 * La table des routes — séparée de l'instance du routeur, et pour une raison
 * concrète.
 *
 * `createRouter({ history: createWebHistory() })` lit `window.location` **au
 * chargement du module**. Tant que la table vivait dans `index.ts`, l'importer
 * depuis un test exécutait cette ligne et échouait sur `window is not defined` :
 * la table de routes — la liste des écrans, qui peut les ouvrir, et vers où
 * partent les refus — était la seule partie du socle qu'aucun test ne pouvait
 * regarder. Elle vit donc ici, en données pures.
 *
 * **Les noms de route sont un contrat.** Une notification porte `linkRoute` +
 * `linkParams` plutôt qu'une URL construite, « pour qu'une refonte du routage
 * front ne casse pas les notifications d'hier » (§ 8 du contrat). Renommer une
 * route ci-dessous rend muettes les notifications déjà écrites en base. On
 * ajoute, on ne renomme pas. Le contrat cite nommément `demande.detail`.
 */

import type { RouteRecordRaw } from 'vue-router'
import type { NomDeDisposition } from '@/components/arbre/dispositions'
import type { NiveauRequis } from './acces'

declare module 'vue-router' {
  interface RouteMeta {
    /** Niveau exigé. Obligatoire : une route sans `acces` déclaré serait une
     *  route dont personne ne sait qui peut l'ouvrir. */
    acces: NiveauRequis
    /** Suffixe du titre de page. « Heritage » est ajouté par la garde. */
    titre: string
    /** `false` masque l'en-tête applicatif (connexion, inscription). */
    cadre?: boolean
    /**
     * La **disposition** de l'arbre, pour les maquettes de comparaison.
     *
     * Absente sur `/arbre`, qui rend la mise en page du moteur telle quelle et
     * sert de référence. Voir `components/arbre/dispositions/`.
     */
    disposition?: NomDeDisposition
  }
}

/**
 * La disposition déclarée dans `meta`, servie à la vue en prop.
 *
 * Une fonction et non `props: true` : `meta` n'est pas un paramètre d'URL, et
 * c'est bien dans `meta` qu'elle doit vivre — la garde d'accès et le titre de
 * page s'y trouvent déjà, et une disposition en segment d'URL serait un
 * paramètre que n'importe qui pourrait inventer.
 */
function dispositionEnProp(route: {
  meta: { disposition?: NomDeDisposition }
  query: Record<string, unknown>
}): { disposition: NomDeDisposition | undefined; haut: unknown; bas: unknown } {
  // La profondeur voyage dans la **chaîne de requête** : une profondeur se juge
  // en la changeant, et un aller-retour par essai coûterait un déploiement. Les
  // valeurs sont bornées par `optionsDepuis()`, pas ici — une URL tapée à la main
  // ne doit pas rendre un écran vide.
  return { disposition: route.meta.disposition, haut: route.query['haut'], bas: route.query['bas'] }
}

/** Exportée pour que les tests montent la table sur un historique en mémoire :
 *  `createWebHistory()` a besoin d'un `window`, que la suite de tests n'a pas. */
export const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'racine',
    // Le composant n'est jamais rendu : la garde globale redirige selon le
    // régime, une fois la session connue. Une `redirect` de déclaration serait
    // évaluée **avant** la garde, donc avant que la session soit chargée.
    component: () => import('@/views/ArbreView.vue'),
    meta: { acces: 'public', titre: 'Accueil' },
  },

  /* --- Entrée : connexion et inscription (§ 2, § 4) --------------- */
  {
    path: '/connexion',
    name: 'connexion',
    component: () => import('@/views/ConnexionView.vue'),
    meta: { acces: 'anonyme', titre: 'Connexion', cadre: false },
  },
  {
    // Le lien d'invitation arrive sous **deux** formes, et les deux doivent
    // marcher. Le contrat bâtit le lien avec le jeton dans le **chemin** —
    // `https://heritage.local/inscription/kJ8s-2Qb…` (§ 3.2), et c'est cette
    // forme-là qui part par courriel — tandis que la forme `?jeton=…` est celle
    // qu'on écrit à la main. Le paramètre est donc optionnel : `/inscription`
    // seul reste valable, et la vue lit l'un ou l'autre.
    //
    // `/inscription/rattachement`, `/inscription/compte` et `/inscription/etat`
    // ne sont pas ambigus pour autant : `vue-router` classe un segment statique
    // **au-dessus** d'un paramètre, quel que soit l'ordre de déclaration. C'est
    // vérifié dans `views/entree-routage.test.ts`, parce que c'est le genre de
    // règle qui se casse en silence.
    //
    // Un jeton inconnu, mal formé, expiré, révoqué ou déjà consommé rend **404**
    // côté API, avec le même message dans les cinq cas (§ 4) : l'écran ne doit
    // pas les distinguer. Le jeton n'est pas filtré ici pour cette raison —
    // refuser une forme invalide côté routeur la rendrait distinguable d'un
    // jeton simplement inconnu.
    path: '/inscription/:jeton?',
    name: 'inscription.jeton',
    component: () => import('@/views/InscriptionJetonView.vue'),
    meta: { acces: 'anonyme', titre: 'Invitation', cadre: false },
  },
  {
    path: '/inscription/rattachement',
    name: 'inscription.rattachement',
    component: () => import('@/views/InscriptionRattachementView.vue'),
    meta: { acces: 'anonyme', titre: 'Rattachement', cadre: false },
  },
  {
    path: '/inscription/compte',
    name: 'inscription.compte',
    component: () => import('@/views/InscriptionCompteView.vue'),
    meta: { acces: 'anonyme', titre: 'Création du compte', cadre: false },
  },
  {
    // Étape 3 : elle exige une **session**, pas l'anonymat — le compte existe
    // déjà, il attend sa validation (§ 4.4). C'est la destination des refus de
    // niveau « membre » pour un compte en attente.
    path: '/inscription/etat',
    name: 'inscription.etat',
    component: () => import('@/views/InscriptionEtatView.vue'),
    meta: { acces: 'attente', titre: 'Votre inscription' },
  },

  /* --- Le compte ------------------------------------------------------ */
  {
    // Courriel et mot de passe modifiables ; nom et prénom en lecture seule —
    // ce sont ceux de la fiche rattachée, et les corriger passe par une demande.
    // `attente` et non `membre` : un compte non encore validé peut déjà corriger
    // son courriel ou son mot de passe, ce sont les siens, pas une donnée de
    // l'arbre.
    path: '/compte',
    name: 'compte',
    component: () => import('@/views/CompteView.vue'),
    meta: { acces: 'attente', titre: 'Mon compte' },
  },
  {
    // Le lien reçu à la nouvelle adresse (§ 2.5). `public` et non `anonyme` :
    // on y arrive depuis un courriel, tantôt sur un autre appareil sans session,
    // tantôt sur l'appareil où l'on vient de faire la demande — donc connecté.
    // `anonyme` renverrait ce second cas vers l'arbre sans rien confirmer, et
    // c'est le cas courant. Le jeton est passé en prop plutôt que lu par
    // `useRoute()` — la vue reste rendable hors routeur.
    path: '/compte/courriel/:jeton',
    name: 'compte.courriel',
    component: () => import('@/views/CompteCourrielView.vue'),
    props: true,
    meta: { acces: 'public', titre: 'Changement de courriel', cadre: false },
  },

  /* --- Consultation : ouverte au régime « en attente » ------------
   *
   * **`/arbre` SERT `ressorts`, et c'est la fin de la comparaison.** Les sept
   * maquettes `/arbre-2` à `/arbre-8` ont été retirées : elles existaient pour que
   * le commanditaire choisisse, il a choisi, et tant qu'elles vivaient **la route
   * que tout le monde atteint servait la sortie brute du moteur** — celle qui
   * n'avait été retenue par personne. `routeParDefaut()` renvoie `{ name: 'arbre' }`
   * pour tous les régimes : le travail fait sur les dispositions n'était donc servi
   * à aucun membre.
   *
   * La disposition reste posée en `meta` et voyage en **prop** plutôt que par
   * `useRoute()` : la vue reste montable seule dans un test de rendu, où il n'y a
   * pas de routeur. `?haut=` et `?bas=` continuent d'être lus et bornés par
   * `optionsDepuis()`.
   */
  {
    path: '/arbre',
    name: 'arbre',
    component: () => import('@/views/ArbreView.vue'),
    props: dispositionEnProp,
    meta: { acces: 'attente', titre: 'Arbre', disposition: 'ressorts' },
  },
  {
    // `{id}` est un entier : un identifiant provisoire (`#12/p1`) ne correspond
    // à aucune route côté API et n'a pas à en avoir une ici non plus. Le détail
    // d'un nœud en attente passe par la demande qui le porte (§ 6.6).
    path: '/personnes/:id(\\d+)',
    name: 'personne.detail',
    component: () => import('@/views/PersonneView.vue'),
    meta: { acces: 'attente', titre: 'Fiche' },
  },
  {
    path: '/chronologie',
    name: 'chronologie',
    component: () => import('@/views/ChronologieView.vue'),
    meta: { acces: 'attente', titre: 'Chronologie' },
  },
  {
    // `attente` pour une raison mécanique, et non par analogie avec ses
    // voisines : ces deux écrans sont bâtis sur des **événements datés**, et
    // une personne vivante hors cercle n'en fournit aucun. Un compte en attente
    // de validation y voit donc un écran plein — les 256 défunts de l'arbre,
    // leurs lieux et leurs dates. Exiger `membre` verrouillerait exactement le
    // public qui a le plus à voir pendant qu'il patiente.
    path: '/carte',
    name: 'carte',
    component: () => import('@/views/CarteView.vue'),
    meta: { acces: 'attente', titre: 'Carte' },
  },
  {
    path: '/anniversaires',
    name: 'anniversaires',
    component: () => import('@/views/AnniversairesView.vue'),
    meta: { acces: 'attente', titre: 'Anniversaires' },
  },

  /* --- Contribution : rattachement validé exigé (§ 12.1) ---------- */
  {
    // Nom cité par le contrat (§ 8, `linkRoute: "demande.detail"`). La route
    // existe dès maintenant pour que les notifications aient une cible ; l'écran
    // revient à l'agent des propositions.
    path: '/demandes/:id(\\d+)',
    name: 'demande.detail',
    component: () => import('@/views/DemandeView.vue'),
    meta: { acces: 'membre', titre: 'Demande' },
  },
  {
    // **La liste de ses propres demandes.**
    //
    // Elle manquait, et son absence rendait inutilisable une capacité entière :
    // le serveur refuse avec un motif obligatoire, le stocke, le sert — et
    // l'auteur n'avait aucun chemin pour l'atteindre. `demande.detail` existait
    // bien, mais on n'y arrive qu'en connaissant l'identifiant, c'est-à-dire en
    // tapant `/demandes/6` dans la barre d'adresse.
    //
    // Déclarée **avant** rien de particulier : `/mes-demandes` ne peut entrer en
    // collision avec `/demandes/:id(\d+)`, qui exige le préfixe `/demandes/` et
    // un entier. Un chemin propre plutôt qu'un `/demandes` nu, parce que
    // `/demandes` seul se lirait comme « toutes les demandes » — ce que voit un
    // administrateur, et qui est un autre écran.
    //
    // `membre` et non `attente` : un compte dont le rattachement n'est pas
    // validé n'a, par construction, aucune demande — l'API lui refuse le dépôt
    // (§ 12.2). Lui ouvrir une liste toujours vide serait lui promettre un
    // écran qui ne dira jamais rien.
    path: '/mes-demandes',
    name: 'demandes.miennes',
    component: () => import('@/views/MesDemandesView.vue'),
    meta: { acces: 'membre', titre: 'Mes demandes' },
  },
  {
    // Les deux formulaires de proposition. `membre` et non `attente` : « le
    // défaut fermé vaut aussi en écriture » — un compte dont le rattachement
    // n'est pas validé ne dépose pas, et l'API le lui redirait en
    // `403 regime_en_attente` (§ 12.2). La garde l'emmène vers l'écran qui
    // explique l'attente, pas vers la connexion : il est bien connecté.
    //
    // `?refaire=<id>` pré-remplit le formulaire depuis une demande caduque ou
    // refusée (§ 6.10) — « une caducité sans ce bouton est une contribution
    // perdue ». Le paramètre est une **requête** et non un segment de chemin :
    // l'écran reste le même, c'est son contenu initial qui change.
    path: '/personnes/:id(\\d+)/proposer',
    name: 'proposition.modification',
    component: () => import('@/views/PropositionModificationView.vue'),
    meta: { acces: 'membre', titre: 'Proposer une modification' },
  },
  {
    // **Demander la suppression d'une fiche** (§ 6.4 quater).
    //
    // Écran séparé et non panneau de la fiche, pour la même raison que
    // `proposition.modification` : il **relit la cible à son ouverture**, et ce
    // qu'il affiche — l'inventaire de ce que la suppression emporte — doit être
    // celui d'aujourd'hui, pas celui du chargement de la fiche, qui peut dater
    // de vingt minutes. Un panneau incrusté hériterait de cet état périmé et
    // annoncerait « 0 note » sur une fiche qui vient d'en recevoir une.
    //
    // `membre` comme les deux autres formulaires : un compte en attente de
    // rattachement ne dépose pas, et l'API le lui redirait en
    // `403 regime_en_attente`.
    path: '/personnes/:id(\\d+)/supprimer',
    name: 'proposition.suppression',
    component: () => import('@/views/PropositionSuppressionView.vue'),
    meta: { acces: 'membre', titre: 'Demander la suppression' },
  },
  {
    // **Proposer des liens de parenté sur une fiche existante** (§ 6.4 bis).
    //
    // Écran séparé de `proposition.modification`, et ce n'est pas un choix de
    // mise en page : une demande porte **une** nature, et `kind` « dit quelles
    // tables filles sont peuplées » (§ 5.7 du modèle). Un formulaire qui
    // déposerait à la fois des ops de champ et des liens déposerait **deux**
    // demandes sous un seul bouton — deux lignes dans la file, deux décisions,
    // et un administrateur libre de valider l'une et de refuser l'autre.
    //
    // `membre` comme les trois autres formulaires : un compte en attente de
    // rattachement ne dépose pas, et l'API le lui redirait en
    // `403 regime_en_attente`.
    path: '/personnes/:id(\\d+)/liens',
    name: 'proposition.liens',
    component: () => import('@/views/PropositionLiensView.vue'),
    meta: { acces: 'membre', titre: 'Proposer des liens' },
  },
  {
    // Pas de `:id` : la personne n'existe pas encore, et n'aura d'identifiant
    // qu'à la validation. Elle vit jusque-là sous une référence provisoire
    // propre à la demande (§ 6.4 du dossier, § 9.5).
    path: '/personnes/proposer',
    name: 'proposition.creation',
    component: () => import('@/views/PropositionCreationView.vue'),
    meta: { acces: 'membre', titre: 'Proposer une personne' },
  },

  /* --- Administration -------------------------------------------- */
  {
    // Les sous-écrans sont des **routes enfants**, chacune avec son `meta`
    // (`app/README.md`, « Ajouter une route »). Chacune a donc son chemin, donc
    // son lien profond : un administrateur qui met la file de validation en
    // favori doit retomber sur la file, pas sur un onglet par défaut.
    //
    // `redirect` est résolu **avant** la garde globale, qui verra donc le
    // `meta.acces` de l'enfant. Chaque enfant porte le sien explicitement plutôt
    // que de compter sur la fusion avec le parent : une route dont personne ne
    // sait qui peut l'ouvrir est exactement ce que `RouteMeta` interdit.
    path: '/administration',
    name: 'administration',
    component: () => import('@/views/AdministrationView.vue'),
    meta: { acces: 'administration', titre: 'Administration' },
    redirect: { name: 'administration.demandes' },
    children: [
      {
        path: 'demandes',
        name: 'administration.demandes',
        component: () => import('@/views/administration/FileDemandesView.vue'),
        meta: { acces: 'administration', titre: 'File de validation' },
      },
      {
        path: 'invitations',
        name: 'administration.invitations',
        component: () => import('@/views/administration/InvitationsView.vue'),
        meta: { acces: 'administration', titre: 'Invitations' },
      },
      {
        path: 'utilisateurs',
        name: 'administration.utilisateurs',
        component: () => import('@/views/administration/UtilisateursView.vue'),
        meta: { acces: 'administration', titre: 'Utilisateurs' },
      },
    ],
  },

  /* --- Sortie ------------------------------------------------------ */
  {
    /**
     * `/deconnexion` rendait « Page introuvable ».
     *
     * Le bouton « Se déconnecter » de l'en-tête, lui, a toujours fonctionné : il
     * appelle `deconnecter()` puis pousse vers `connexion`, sans jamais passer par
     * une URL. Le chemin `/deconnexion` n'existait donc nulle part — et c'est
     * pourtant celui qu'on tape, qu'on met en favori, et qu'on donne au téléphone
     * de quelqu'un pour lui faire fermer sa session. L'écran d'erreur générique
     * était la seule réponse, sur le geste le plus attendu d'une application
     * familiale partagée.
     *
     * ### Pourquoi un `beforeEnter` et pas un `redirect`
     *
     * Une `redirect` de déclaration est résolue **pendant la résolution de la
     * route**, avant toute garde : les `beforeEnter` d'un enregistrement qui
     * redirige ne sont jamais appelés. On atterrirait sur la connexion **sans
     * s'être déconnecté** — une route qui a l'air de marcher et qui laisse la
     * session ouverte, c'est-à-dire pire que la page introuvable qu'elle remplace.
     * La déconnexion doit avoir lieu, donc elle est dans une garde.
     *
     * ### Pourquoi `acces: 'public'` et surtout pas `'anonyme'`
     *
     * `'anonyme'` veut dire « réservé à qui n'a pas de session ». La garde globale
     * s'exécute **avant** ce `beforeEnter` et lit le régime d'**avant** la
     * déconnexion : un membre connecté serait donc renvoyé vers l'arbre par
     * `routeParDefaut()` sans jamais être déconnecté. C'est exactement la route
     * qu'un visiteur connecté doit pouvoir ouvrir ; `'public'` est le seul niveau
     * qui laisse passer les deux régimes.
     *
     * ### Le composant n'est pas rendu
     *
     * Les composants asynchrones sont résolus **après** les gardes d'entrée : ce
     * `import()` n'est jamais exécuté tant que la garde redirige. Il est déclaré
     * parce que `vue-router` exige un composant, et c'est `ConnexionView` plutôt
     * qu'un écran vide : si la garde cessait un jour de rediriger, on verrait la
     * connexion — la destination voulue — et non une page blanche.
     */
    path: '/deconnexion',
    name: 'deconnexion',
    component: () => import('@/views/ConnexionView.vue'),
    meta: { acces: 'public', titre: 'Déconnexion', cadre: false },
    beforeEnter: async () => {
      // Import différé : ce module doit rester des **données pures**, importables
      // par un test sans réveiller la chaîne qui mène au client d'API. Il est
      // chargé au premier passage sur cette route, pas au chargement de la table.
      const { deconnecter, useSession } = await import('@/composables/useSession')

      // Sans session, il n'y a rien à fermer. L'appel se solderait par une `401`,
      // que le rattrapage global traite comme une session perdue — donc une
      // seconde redirection vers la connexion, concurrente de celle-ci, qui
      // emporterait un `?suite=` parasite.
      if (useSession().connecte.value) {
        await deconnecter()
      }

      return { name: 'connexion' }
    },
  },

  /* --- Impasses --------------------------------------------------- */
  {
    path: '/acces-refuse',
    name: 'acces-refuse',
    component: () => import('@/views/AccesRefuseView.vue'),
    meta: { acces: 'public', titre: 'Accès refusé' },
  },
  {
    path: '/:chemin(.*)*',
    name: 'introuvable',
    component: () => import('@/views/IntrouvableView.vue'),
    meta: { acces: 'public', titre: 'Page introuvable' },
  },
]

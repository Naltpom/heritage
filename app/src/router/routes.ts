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
  }
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

  /* --- Consultation : ouverte au régime « en attente » ------------ */
  {
    path: '/arbre',
    name: 'arbre',
    component: () => import('@/views/ArbreView.vue'),
    meta: { acces: 'attente', titre: 'Arbre' },
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

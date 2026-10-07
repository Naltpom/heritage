/* ------------------------------------------------------------------------ */
/* Le vocabulaire de la famille fictive : prénoms par époque, noms, métiers, */
/* lieux (vraies communes, coordonnées approximatives). Aucune personne      */
/* réelle : tout est tiré au hasard, avec une graine fixe.                   */
/* Utilisable dans la page (window) comme dans Node (require).               */
/* ------------------------------------------------------------------------ */
;(function (racine) {
  const PRENOMS = {
    // [jusqu'à l'année de naissance, hommes, femmes]
    epoques: [
      [1700, ['Jean', 'Pierre', 'Jacques', 'François', 'Guillaume', 'Nicolas', 'Michel', 'Antoine', 'Julien', 'Mathurin'],
        ['Marie', 'Jeanne', 'Anne', 'Françoise', 'Catherine', 'Marguerite', 'Perrine', 'Julienne', 'Madeleine', 'Renée']],
      [1800, ['Jean', 'Pierre', 'Joseph', 'Louis', 'François', 'Jacques', 'Julien', 'René', 'Mathurin', 'Charles'],
        ['Marie', 'Jeanne', 'Anne', 'Françoise', 'Julienne', 'Louise', 'Marguerite', 'Perrine', 'Rose', 'Victoire']],
      [1880, ['Jean', 'Pierre', 'Joseph', 'Louis', 'François', 'Auguste', 'Jules', 'Eugène', 'Émile', 'Victor'],
        ['Marie', 'Louise', 'Joséphine', 'Eugénie', 'Augustine', 'Rose', 'Céline', 'Adèle', 'Clémentine', 'Victorine']],
      [1930, ['Louis', 'Henri', 'Marcel', 'André', 'René', 'Georges', 'Albert', 'Paul', 'Lucien', 'Raymond'],
        ['Marie', 'Louise', 'Jeanne', 'Marguerite', 'Germaine', 'Suzanne', 'Madeleine', 'Simone', 'Yvonne', 'Marthe']],
      [1970, ['Jean', 'Michel', 'Bernard', 'Alain', 'Jacques', 'Daniel', 'Pierre', 'Gérard', 'Claude', 'Philippe'],
        ['Monique', 'Françoise', 'Nicole', 'Jacqueline', 'Annie', 'Danielle', 'Christiane', 'Martine', 'Chantal', 'Sylvie']],
      [2000, ['Nicolas', 'Julien', 'Sébastien', 'Thomas', 'Mathieu', 'Guillaume', 'Romain', 'Antoine', 'Maxime', 'Nathan'],
        ['Julie', 'Camille', 'Céline', 'Aurélie', 'Émilie', 'Claire', 'Pauline', 'Laura', 'Marion', 'Élodie']],
      [3000, ['Lucas', 'Hugo', 'Louis', 'Gabriel', 'Léo', 'Jules', 'Arthur', 'Raphaël', 'Adam', 'Paul'],
        ['Emma', 'Jade', 'Louise', 'Alice', 'Chloé', 'Lina', 'Léa', 'Rose', 'Anna', 'Mila']],
    ],
  }
  // les quatre souches (les couleurs de branche de l'application) et les noms d'alliance
  const SOUCHES = ['Martin', 'Bernard', 'Dubois', 'Lefèvre']
  const NOMS = ['Morel', 'Girard', 'Roux', 'Faure', 'Blanc', 'Garnier', 'Leroy', 'Perrin', 'Robin', 'Mercier', 'Fontaine',
    'Chevalier', 'Lemoine', 'Guérin', 'Boucher', 'Gauthier', 'Lambert', 'Rousseau', 'Barbier', 'Collin', 'Renaud', 'Huet',
    'Le Gall', 'Jamet', 'Pichon', 'Tessier', 'Hamon', 'Riou', 'Gicquel', 'Lebreton', 'Daniel', 'Hervé', 'Thomas', 'Prigent']
  // [jusqu'à l'année, métiers d'hommes, métiers de femmes]
  const METIERS = [
    [1800, ['laboureur', 'journalier', 'tisserand', 'meunier', 'charpentier', 'maréchal-ferrant', 'marchand', 'tailleur'],
      ['fileuse', 'journalière', 'couturière', 'ménagère', 'domestique']],
    [1900, ['cultivateur', 'journalier', 'tisserand', 'forgeron', 'menuisier', 'marin', 'meunier', 'charron', 'boulanger'],
      ['cultivatrice', 'couturière', 'ménagère', 'lingère', 'domestique', 'fileuse']],
    [1950, ['cultivateur', 'cheminot', 'instituteur', 'menuisier', 'mécanicien', 'boulanger', 'facteur', 'marin'],
      ['ménagère', 'institutrice', 'couturière', 'commerçante', 'cultivatrice', 'infirmière']],
    [3000, ['ingénieur', 'technicien', 'enseignant', 'médecin', 'commercial', 'électricien', 'agriculteur', 'informaticien'],
      ['infirmière', 'enseignante', 'comptable', 'pharmacienne', 'architecte', 'secrétaire', 'avocate', 'chercheuse']],
  ]
  // des communes réelles (coordonnées approchées) : la famille part de Bretagne et du Maine, puis se disperse
  const LIEUX = [
    ['Josselin', 47.95, -2.55], ['Ploërmel', 47.93, -2.40], ['Pontivy', 48.07, -2.96], ['Malestroit', 47.81, -2.38],
    ['Guer', 47.91, -2.12], ['Mauron', 48.08, -2.28], ['Rohan', 48.07, -2.75], ['Questembert', 47.66, -2.45],
    ['Vitré', 48.12, -1.21], ['Fougères', 48.35, -1.20], ['Laval', 48.07, -0.77], ['Mayenne', 48.30, -0.61],
    ['Château-Gontier', 47.83, -0.70], ['Craon', 47.85, -0.95], ['Rennes', 48.11, -1.68], ['Vannes', 47.66, -2.76],
    ['Nantes', 47.22, -1.55], ['Saint-Malo', 48.65, -2.03], ['Angers', 47.47, -0.55], ['Le Mans', 48.00, 0.20],
    ['Brest', 48.39, -4.49], ['Lorient', 47.75, -3.37], ['Tours', 47.39, 0.69], ['Paris', 48.86, 2.35],
    ['Lyon', 45.76, 4.84], ['Bordeaux', 44.84, -0.58], ['Lille', 50.63, 3.06], ['Toulouse', 43.60, 1.44],
    ['Caen', 49.18, -0.37], ['Montréal', 45.50, -73.57], ['Alger', 36.75, 3.06], ['Marseille', 43.30, 5.37],
  ]
  const exp = { PRENOMS, SOUCHES, NOMS, METIERS, LIEUX }
  if (typeof module !== 'undefined') module.exports = exp
  else Object.assign(racine, { VOCABULAIRE: exp })
})(typeof window !== 'undefined' ? window : globalThis)

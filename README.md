# Revue de presse du Bassin — version 1.0.0

Collecteur Python et plugin WordPress pour une revue thématique et chronologique.
Le logiciel prépare des données publiques ; il ne modifie pas le contenu de la page
WordPress à chaque exécution. Le shortcode affiche les données actualisées.

## Installation sur GitHub

1. Créer un dépôt nommé `revue-presse-bassin`, avec la branche principale `main`.
2. Déposer **le contenu de cette archive à la racine du dépôt**. Conserver notamment
   `.github/workflows/collect.yml` et `.gitignore`. Pour ajouter le dossier caché,
   utiliser Git ou l'éditeur web GitHub en créant ce chemin explicitement.
3. Dans **Settings → Pages → Build and deployment**, choisir **GitHub Actions**.
4. Dans **Settings → Actions → General → Workflow permissions**, autoriser
   **Read and write permissions** afin de conserver `data/archive.json` dans Git.
   Une protection de branche interdisant les commits du bot nécessitera une
   adaptation du stockage. Le workflow doit pouvoir écrire sur `main`.
5. Dans **Actions**, ouvrir « Collecte et publication de la revue de presse » et
   cliquer **Run workflow**. La publication automatique est également déclenchée
   par les changements du collecteur, de la configuration ou des corrections.
6. Après réussite, ouvrir l'URL GitHub Pages indiquée par le déploiement. L'index est
   `https://VOTRE-COMPTE.github.io/revue-presse-bassin/index.json`.

GitHub Pages doit être disponible pour le type de dépôt et votre abonnement.
Avec un compte GitHub Free, choisir un dépôt public. Ne déposer aucun secret dans
le dépôt. Cette version n'utilise ni clé d'IA ni identifiant WordPress.

Le workflow tourne quatre fois par jour : **03:17, 09:17, 15:17 et 21:17 UTC**.
Les dates des articles et les limites des semaines utilisent **Europe/Paris**.
Les déclenchements GitHub peuvent être retardés ; la date réelle est affichée.
Sur un dépôt public, GitHub peut suspendre les tâches planifiées après une longue
période sans activité : vérifier périodiquement l'onglet Actions.

## Installation WordPress

1. Installer le ZIP distinct `revue-presse-bassin-wordpress-1.0.0.zip` via
   **Extensions → Ajouter → Téléverser une extension**, puis l'activer.
2. Dans **Réglages → Revue de presse**, coller l'URL HTTPS de `index.json`.
3. Créer une page « Revue de presse du Bassin d'Arcachon » et ajouter un bloc
   **Code court** contenant `[revue_presse_bassin]`.
4. Pour démarrer le test sur septembre, utiliser
   `[revue_presse_bassin periode="2026-09"]`.

Le lecteur choisit ensuite la période. Les thèmes, médias, lieux et recherche
filtrent immédiatement les fiches. La vue chronologique classe les jours du plus
récent au plus ancien. Les sujets communs sont regroupés dans chaque thème quand
au moins deux références du sujet sont présentes.

Le plugin revérifie les JSON après 30 minutes, lors d'une visite. En cas d'échec,
il conserve une copie valide et affiche un message. Il rend le contenu côté PHP :
les articles restent lisibles sans JavaScript. Un cache de page WordPress/CDN peut
retarder davantage l'affichage ; exclure cette page du cache ou lui donner une
durée raisonnable (30 minutes, par exemple). Le bouton « Revérifier les données »
ne purge pas le cache de page de l'hébergeur.

## Fichiers produits

- `data/archive.json` : références persistantes, enregistrées par le workflow.
- `data/curated.json` : sélection et corrections éditoriales prioritaires.
- `public/index.json` : liste des périodes, thèmes et état des sources.
- `public/derniere-semaine.json` : dernière semaine complète du lundi au dimanche.
- `public/semaine-courante.json` : semaine en cours.
- `public/mois/AAAA-MM.json` : un fichier pour chaque mois représenté.

L'archive complète ne disparaît pas quand un article sort d'un flux. Les exports
mensuels sont régénérés à partir de cette archive, sans collecte historique.
L'index utilise des URL relatives à sa propre adresse.

## Sources et périmètre

`config/sources.json` reprend les **25 flux fournis**, plus les flux proposés
d'InfoBassin, de TVCapFerret et de 20 Minutes, soit **28 flux pour 20 médias**.
Ces adresses sont configurées, mais certaines peuvent ne pas répondre ou ne pas
correspondre à un véritable flux : leur échec est visible dans l'index.

Sud Ouest et les médias nationaux passent un filtre territorial explicite.
Les médias exclusivement locaux (InfoBassin, TVCapFerret et La Dépêche du Bassin)
sont admis directement. Les pages d'événements TVCapFerret sont exclues ; les
articles d'agenda restent admis. Le périmètre comprend le Bassin, le Val de l'Eyre
et les incendies proches ayant un impact local (Saumos, Le Porge).

Les noms ambigus seuls, tels que « Salles » ou « Arès », ne suffisent pas à retenir
un article national. Ce choix réduit les faux positifs mais peut manquer des
articles locaux : les règles sont ajustables. La classification est une première
approche par mots-clés et peut être corrigée dans `data/curated.json`.

## Sélection initiale de septembre 2026

31 références de notre test sont fournies dans `data/curated.json` ; les deux
chroniques humoristiques ne sont pas incluses dans cette sélection initiale.
La collecte RSS peut ensuite ajouter d’autres publications, dont des chroniques locales. Il s'agit d'une **sélection partielle**,
pas d'une archive exhaustive du mois. Les titres sont des formulations abrégées.
La date de publication a été relevée, mais l'heure n'est pas toujours connue :
`publication_time_precision: "day"` le signale ; midi est utilisé uniquement
comme heure conventionnelle de classement. Le site affiche seulement la date.
Aucun résumé n'est fourni pour cette sélection initiale.

Pour supprimer une référence, la retirer de `curated.json` et ajouter son
identifiant à `excluded_article_ids` dans la configuration : sinon une ancienne
référence reste conservée dans l'archive. Pour corriger titre, thème, date ou groupe,
modifier sa référence dans `curated.json`. Une correction éditoriale est prioritaire
sur les prochaines notices RSS.

## Exécution locale et vérifications

Python 3.11 minimum ; Python 3.12 recommandé. Aucune dépendance Python externe.

```bash
python -m unittest discover -s tests -v
python collector.py
python collector.py --offline
python collector.py --source tvcapferret
```

`--offline` exporte uniquement les références conservées et indique que les flux
n'ont pas été contrôlés. Le filtre `--source` sert au diagnostic ; un déploiement
normal collecte tous les médias. Si tous les flux échouent, le programme termine
en erreur et conserve les exports précédents. Si quelques flux échouent, il
publie les données valides et précise les sources indisponibles.

Les dates absentes, ambiguës sans fuseau, ou dans le futur sont écartées. Une date
de mise à jour ne remplace pas une date de publication manquante. Les notices RSS
sont converties en texte et limitées à 450 caractères. Aucun texte intégral ni
image de presse n'est repris. La première version ne contourne aucun accès payant
et n'utilise pas de scraping de pages ni de génération de résumés par IA.

## Limites et suite

La couverture automatique dépend des flux disponibles : un flux national général
ne contient pas nécessairement toutes les publications locales. Une adresse XML
inaccessible n'est pas remplacée automatiquement par une page web. Ajouter des
connecteurs d'archives ou des API par média sera une deuxième étape, après mesure
du taux de couverture de cette version.

Le collecteur a des tests sur les dates, les doublons, les filtres et les archives.
Le déploiement réel GitHub Pages et l'intégration avec votre thème WordPress restent
à vérifier lors de l'installation. Aucun compte externe n'est modifié par cette
livraison.

## Documentation officielle

- GitHub Pages : https://docs.github.com/en/pages/getting-started-with-github-pages/using-custom-workflows-with-github-pages
- Planification GitHub Actions : https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#schedule
- Requêtes WordPress : https://developer.wordpress.org/reference/functions/wp_safe_remote_get/
- Shortcodes WordPress : https://developer.wordpress.org/apis/shortcode/

Code distribué sous GPL-2.0-or-later. Les contenus des médias restent attribués à
leurs éditeurs et consultables sur leurs sites.

## Résultat des vérifications de livraison

Le 7 octobre 2026, la collecte réelle a lu 13 flux sur 28 et constitué une archive
de 40 références (31 références éditoriales et 9 autres références RSS). Les échecs
sont enregistrés dans les exports. Les 11 tests Python passent. La syntaxe PHP,
le rendu du shortcode, la validation JSON, l’échappement des titres et la copie
de secours ont été vérifiés dans un banc de test CLI. Les filtres, la chronologie
et les regroupements ont été vérifiés dans un DOM JavaScript. Le rendu visuel
dans votre thème et la publication sur votre compte GitHub restent à vérifier.

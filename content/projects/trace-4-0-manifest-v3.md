---
title: "Trace 4.0, migration Manifest V3"
slug: "trace-4-0-manifest-v3"
type: "project"
date: 2026-10-26
subtitle: "L'extension de suivi de l'école cassée par la fin de Manifest V2, remise en marche de ma propre initiative"
seo_description: "Migration solo, en janvier 2026, de l'extension Chrome de suivi pédagogique de l'UHA 4.0 vers Manifest V3, après que Chrome a désactivé Manifest V2."
featured: false
status: "stable"
role: "Migration solo vers Manifest V3, initiative personnelle hors cursus"
period_start: 2026-01-01
period_end: 2026-01-20
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [javascript, chrome-extension, manifest-v3, service-worker, chrome-storage-api, rest-api, offscreencanvas, jquery]
skills: ["4.0.1/FR-08", "4.0.1/FR-15"]
---

Trace 4.0 est l'extension Chrome officielle de l'UHA 4.0 qui mesure le temps d'activité réelle des étudiants connectés sur les ressources de formation. Elle reposait sur Manifest V2, le format que Chrome a fini par désactiver. Début 2026, de ma propre initiative et en dehors de tout cadre noté, j'ai migré seul l'extension vers Manifest V3, livrée en une itération (v1.2.0) avec un changelog qui documente chaque fichier modifié.

## Le problème

L'extension suit, pour chaque étudiant connecté, le temps passé sur les ressources listées par la plateforme de formation : identifiants UHA, case CGU, bouton de bascule visible, icône qui change de couleur selon l'état (vert actif, rouge inactif, gris déconnecté). Les sessions mesurées remontent à une API interne de l'école, et l'étudiant retrouve ses formations, ses cours et son historique depuis la popup.

Le problème n'était pas fonctionnel mais d'infrastructure : Manifest V2, le format sur lequel elle reposait, a été progressivement désactivé par Google sur les navigateurs à jour. Sans intervention, l'outil de suivi refusait de se charger — plus personne dans la formation ne pouvait l'utiliser.

J'ai pris l'initiative de migrer l'extension sur mon temps personnel. La difficulté n'est pas de renommer quelques clés du manifeste : Manifest V3 change le modèle d'exécution. Le script d'arrière-plan n'est plus une page persistante dont l'état reste lisible à tout moment, mais un service worker éphémère que Chrome tue et relance à sa convenance. Tout ce qui supposait un état en mémoire disponible en permanence devait être repensé.

## Les décisions techniques

**Le service worker n'a plus d'état fiable, donc tout le stockage devient asynchrone.** `localStorage`, synchrone, est inaccessible à un service worker. Je l'ai remplacé partout par `chrome.storage.local`, asynchrone, derrière deux petits wrappers `getStorageData`/`setStorageData` construits sur des `Promise`. Le changement se propage en cascade : les trois fichiers qui lisaient l'état (`background.js`, `manager.js`, `gandalf.js`) passent intégralement en `async`/`await`, sans changer le comportement observable.

**Les tâches périodiques ne vivent pas dans le service worker.** Le piège classique d'une migration MV3 est de placer un `setInterval` dans le script d'arrière-plan : Chrome éteint un service worker inactif après une trentaine de secondes, ce qui l'interromprait. `background.js` n'en contient aucun. Le sondage du changement d'URL (`setInterval(checkUrlChange, 100)`) et l'envoi par lots toutes les deux minutes (`sendPeriod = 2 * 60 * 1000`) vivent entièrement dans le content script `activityWatcher.js`, un contexte de page classique que cette éviction ne concerne pas. Pas besoin de `chrome.alarms` : le bon contexte d'exécution suffit.

**Changer l'icône depuis un service worker demande de redessiner l'image à la main.** `setIcon` ne peut plus prendre un chemin de fichier direct. La fonction `updateIcon` de `background.js` récupère le PNG par `fetch`, le convertit en `ImageBitmap`, le dessine sur un `OffscreenCanvas` de 128 pixels, en extrait les `ImageData`, et c'est ce résultat que `chrome.action.setIcon` accepte. Une contrainte qui paraît mineure sur le papier devient le détail le plus retors de la migration.

**La configuration de l'API vient du manifeste, pas d'une constante recopiée.** Plutôt que de coder en dur l'adresse de l'API, le code la relit dynamiquement avec `chrome.runtime.getManifest().host_permissions[0]`. La source unique reste le manifeste. Le calcul est en revanche recopié à l'identique dans les trois fichiers qui en ont besoin plutôt que factorisé dans un module partagé — une dette que je détaille plus bas.

**Deux canaux de communication cohabitent plutôt qu'un seul.** Le tracking continu passe par un `Port` nommé `"Tracing"`, ouvert une fois par `activityWatcher.js` et conservé le temps de la page, pour que le service worker reçoive sans frais les événements `ACTIVITY`/`URL_CHANGE`. Les événements ponctuels — après une connexion, une déconnexion, un changement du bouton de tracking, ou un clic sur « Mes formations » — passent par un second canal, `chrome.runtime.sendMessage({type: 'REFRESH_TRACKING'})`, appelé depuis trois fichiers (`gandalf.js`, `manager.js` et `P_index.js`) et reçu par un `chrome.runtime.onMessage` dédié dans `background.js`. Je n'ai pas fusionné les deux parce qu'ils n'ont pas la même durée de vie : le premier dure la session de navigation sur une page, le second est un aller simple déclenché par une action de l'utilisateur.

## Ce que j'en ai appris

Que la plateforme des extensions Chrome a un modèle de sécurité et d'exécution précis, et que le connaître en détail — séparation `permissions`/`host_permissions`, format objet de la CSP, cycle de vie du service worker — change la façon de lire n'importe quelle extension, pas seulement celle-ci. Que reprendre un code existant sans en changer le comportement est un exercice différent d'écrire depuis zéro : il faut d'abord comprendre ce qui existe avant de décider ce qui doit changer. Et que les contraintes qui semblent anecdotiques sur le papier, comme changer une icône, sont parfois celles qui demandent le plus de détour technique.

Ce que je ferais autrement : les wrappers de stockage et la lecture de l'URL depuis le manifeste sont dupliqués à l'identique dans trois fichiers, faute d'un module de stockage commun. Sur un projet de cette taille, ça n'a rien cassé, mais c'est exactement le genre de duplication que je factoriserais dès le départ aujourd'hui.

## En chiffres

- ≈1 500 lignes au total : 1 030 lignes de JavaScript applicatif sur 8 fichiers, 104 lignes de HTML, 361 lignes de CSS maison
- bibliothèques tierces vendorées (jQuery slim, Bootstrap 4, Popper, Moment.js, kit Font Awesome) : ≈14 931 lignes, dont 14 876 pour Moment.js seul
- 1 version de migration livrée : v1.2.0, le 20 janvier 2026
- 1 changelog de 52 lignes qui documente chaque fichier modifié, 1 README de 115 lignes
- compatibilité visée : Chrome 88 et au-delà
- 0 test automatisé, 0 CI

## Liens

- Dépôt : espace personnel de Morgan sur le GitLab interne de l'UHA 4.0, non public.
- Démo : aucune — l'extension nécessite des identifiants UHA 4.0 valides.
- Documentation : README d'installation et changelog technique fichier par fichier, conservés dans le dépôt.

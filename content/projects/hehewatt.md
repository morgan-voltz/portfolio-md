---
title: "HEHEWATT"
slug: "hehewatt"
type: "project"
date: 2026-10-26
subtitle: "Une application de covoiturage scolaire reprise et prolongée par plusieurs promotions d'étudiants"
seo_description: "HEHEWATT, projet E-mersion UHA 4.0 pour le lycée Henner d'Altkirch : covoiturage scolaire en PHP/MySQL, géolocalisation OpenStreetMap, équipe inter-promotions."
featured: false
status: "en-cours"
role: "Développeur full-stack, parmi les quatre premiers contributeurs d'une équipe de 30 à 50 personnes selon les promotions"
period_start: 2026-01-05
period_end: 2026-02-13
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [php, mysql, javascript, leaflet, openstreetmap, osrm, docker, twig, api-rest]
skills: ["4.0.1/PM-01", "4.0.1/PM-07", "4.0.1/PM-08", "4.0.1/FR-07", "4.0.1/FR-09", "4.0.1/FR-13", "4.0.1/FR-14", "4.0.1/FR-15"]
---

# HEHEWATT

HEHEWATT est une application de covoiturage développée dans le cadre du projet E-mersion, qui associe le lycée Jean-Jacques Henner d'Altkirch et UHA 4.0, financé par Noria. Le dépôt existe depuis octobre 2024 et a été repris par plusieurs promotions successives : plus d'un millier de commits, entre trente et cinquante contributeurs. Je m'y suis greffé début 2026 comme développeur full-stack sur le cœur métier des trajets et sur le système de notifications, avec environ 93 commits, parmi les quatre premiers contributeurs.

Mon travail sur HEHEWATT s'arrête avec ma première année ; le projet continue d'être repris par les promotions suivantes, ce qui a pesé sur une partie de mes décisions — documentation en tête, pour quelqu'un qui ne me croisera jamais.

## Le problème

Le lycée Jean-Jacques Henner est situé dans le Sundgau, une zone rurale du Haut-Rhin mal desservie par les transports en commun : personnels, parents et élèves font chaque jour le même trajet domicile-lycée en voiture, souvent seuls. HEHEWATT met en relation ces usagers — les conducteurs publient des trajets ponctuels ou récurrents avec des arrêts intermédiaires, les passagers réservent une place sur le trajet qui passe près de chez eux, un administrateur côté lycée valide les comptes. Le lycée est partenaire du projet, pas client : pas de facturation, un besoin réel à servir pour sa communauté.

Le problème technique que j'ai rencontré n'est pas celui d'un projet qui démarre de zéro, mais d'un dépôt PHP sans gros framework, un fichier valant une page ou un point d'API, écrit à la main par des dizaines de personnes de niveaux très différents sur plus d'un an. Ajouter des fonctionnalités structurantes — arrêts multiples avec réservation par segment, trajets récurrents compatibles avec le calendrier scolaire — sans casser ce dont dépendent déjà des utilisateurs réels. Le programme E-mersion ambitionne qu'à terme des lycéens contribuent au code depuis leur navigateur (un service d'IDE web existe dans l'environnement Docker, pour l'instant commenté) : documenter et découper le travail n'est pas une option de confort.

## Les décisions techniques

**Une réservation consomme une place par segment de trajet, pas sur le trajet entier.** Un trajet à arrêts multiples perd son intérêt si un passager qui monte au deuxième arrêt et descend au troisième bloque une place sur tout le trajet. Le modèle associe donc à chaque réservation un arrêt de montée et un arrêt de descente, et la disponibilité se calcule tronçon par tronçon (`Stop::getAvailableSeats()`) plutôt que globalement. J'ai ajouté l'affichage de cette occupation par segment dans le composant de carte de trajet partagé par les cinq écrans concernés, pour qu'un conducteur voie en un coup d'œil quel tronçon est complet.

**La récurrence d'un trajet suit le calendrier scolaire réel, pas une règle hebdomadaire nue.** « Tous les lundis et mercredis » ne veut rien dire pendant les vacances. Le schéma sépare un trajet récurrent (`trajet_recurrent`) de ses exceptions (`trip_exceptions`), même logique côté réservation (`reservation_recurrente` / `reservation_exceptions`), et croise le tout avec deux tables de calendrier scolaire, `planning_annee_scolaire` et `planning_conges`, plutôt que de coder les vacances en dur. Côté réservation, j'ai levé une contrainte d'unicité pour qu'un passager puisse s'inscrire à l'année ou ponctuellement sur un même trajet récurrent, et écrit les requêtes à liste de jours qui en découlent.

**L'essentiel des emails transactionnels passe par un service unique qui couvre le cycle de vie complet d'un trajet.** Sur une quinzaine de fichiers capables de déclencher un email, `NotificationService` (`api/services/`) centralise une vingtaine de méthodes et interroge la base en requêtes préparées PDO, pas en chaînes concaténées. Deux aspérités non recousues : la réinitialisation de mot de passe appelle `EmailService` directement, sans passer par lui, et une seconde classe `NotificationService` sans rapport — notifications in-app, `api/notification/`, non signée — porte le même nom, dans le même espace de noms global. C'est le code dont je suis le plus sûr qu'il est de moi : les en-têtes de `NotificationService.php` et de la configuration des emails portent mon nom, contrairement à l'essentiel du reste du dépôt, non attribué nommément.

**La géolocalisation repose uniquement sur des services libres OpenStreetMap, sans API commerciale.** Faute de budget pour une clé payante : Nominatim géocode les adresses sur une zone bornée au Haut-Rhin et aux départements limitrophes (Bas-Rhin, Vosges, Territoire de Belfort, Suisse, Allemagne), OSRM calcule les temps de trajet entre arrêts, Leaflet affiche tout sur une carte. Le vrai travail a été de composer deux API aux contraintes différentes : Nominatim limite à une requête par seconde (debounce, cache dans `autocomplete.js`), et OSRM attend ses coordonnées en `longitude,latitude`, l'ordre inverse — une inversion documentée dans `route-calculator.js` après l'avoir trouvée en déboguant des heures d'arrivée fausses.

**Une API v1 versionnée s'ajoute aux endpoints existants plutôt que de les remplacer.** Sur un dépôt où des dizaines de personnes appellent déjà les anciens endpoints, les réécrire d'un coup aurait cassé le travail de tout le monde. J'ai créé les endpoints CRUD de cette API v1, groupés par ressource — trajets (4), réservations (6), arrêts (5) — capables de répondre en JSON pur ou en fragments HTML pré-rendus pour les pages déjà en AJAX, sans toucher à l'existant. Les deux API cohabitent encore aujourd'hui.

## Ce que j'en ai appris

Ce projet m'a surtout appris ce que « travailler en équipe » veut dire quand l'équipe change de visage toutes les quelques semaines. Une fonctionnalité bien faite mais livrée d'un bloc n'aide personne d'autre si personne ne peut s'y greffer : j'ai appris à découper mon travail en morceaux assez petits pour qu'un contributeur qui ne connaît pas l'historique du projet puisse en reprendre un sans tout relire.

J'ai aussi dû me corriger sur un réflexe : quand une partie de l'équipe était peu active, j'ai eu tendance à combler le vide par du travail personnel, avec une aide massive d'outils d'IA, plutôt qu'à adapter le périmètre ou relancer la répartition des tâches. Résultat : trop de changements en même temps rendent le travail difficile à suivre, et une personne qui absorbe tout en porte seule la responsabilité — ce qui n'est sain pour personne.

La leçon la plus concrète porte sur les changements structurants : modifier un fichier de configuration partagé par l'environnement de toute l'équipe et par les serveurs du lycée, sans le signaler ni le faire valider, peut casser le travail de dizaines de personnes qui n'ont rien demandé. Je communique maintenant ce genre de changement avant de le fusionner, pas seulement dans le message de commit.

Ce que je ferais autrement : préparer systématiquement les points d'étape, y compris pour des publics moins visibles qu'un client — les lycéens qui reprendront le projet — et viser des livraisons plus petites et plus régulières plutôt que des progrès impressionnants mais disruptifs pour l'équipe.

## En chiffres

- 1 058 commits sur l'ensemble du dépôt, dont environ 93 les miens, parmi les quatre premiers contributeurs
- Entre 30 et 50 contributeurs selon les promotions, sur 16 mois (octobre 2024 à février 2026)
- 262 fichiers hors dépendances : environ 26 000 lignes PHP (144 fichiers), 13 500 lignes JS, 4 700 lignes CSS, 1 000 lignes Twig
- 11 tables en base, 21 templates d'email Twig, une API v1 de 15 endpoints (4 trajets + 6 réservations + 5 arrêts)
- Environnement de développement Docker Compose : 4 services actifs (PHP/Apache avec Xdebug, MariaDB, phpMyAdmin, Mailpit), plus un service d'IDE web pour les lycéens, entièrement commenté

## Liens

- Dépôt : GitLab interne UHA 4.0, non public — projet scolaire sous convention avec un établissement partenaire, pas de copie de lecture publiée.
- Documentation technique : j'ai rédigé ou mis à jour la doc API, emails et géolocalisation, ainsi que le README, dans le dépôt (non public).
- Lycée Jean-Jacques Henner d'Altkirch, partenaire du projet E-mersion financé par Noria.

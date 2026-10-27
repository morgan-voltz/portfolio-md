---
title: "xvii"
slug: "xvii"
type: "project"
date: 2026-10-26
subtitle: "Un studio audio seize pistes en Rust dont le thread temps réel ne peut ni allouer ni attendre"
seo_description: "XVII est un prototype de studio audio seize pistes en Rust et Dioxus : thread temps réel sans allocation ni verrou, architecture actée avant le code."
featured: false
status: "prototype"
role: "Conception de l'architecture et prototypage, seul"
period_start: 2026-07-18
period_end: 2026-07-18
repo_url: "https://github.com/morgan-voltz/xvii"
demo_url: ""
client: ""
is_fil_rouge: false
tags: [rust, dioxus, cpal, symphonia, rubato, audio-temps-reel, dsp, mcp]
skills: []
---

XVII est un studio audio seize pistes en Rust et Dioxus, pensé pour annoter mes morceaux de metal : poser une structure (couplet, riff A, breakdown, solo), caler un tempo qui varie en cours de route, et synchroniser des paroles ligne par ligne puis mot à mot. Le nom vient du chiffre romain dix-sept : la dix-septième piste, celle des paroles, qui s'ajoute aux seize pistes audio.

Au 26 octobre 2026, seule la phase 1 sur 6 de la feuille de route est livrée : lire un fichier audio passé en argument dans une fenêtre minimale, avec un gain général réglable. L'architecture complète, elle, a été actée par écrit avant la première ligne de code.

## Le problème

Mes morceaux ne suivent pas une structure couplet-refrain sage : blast beats, demi-temps et double-temps, breaks qui ne reviennent jamais à l'identique. Les outils d'annotation automatique courants supposent une structure régulière et se trompent sur ce genre de musique — ou pire, imposent leur lecture sans le dire. Je voulais un outil qui propose une analyse quand il le peut, mais qui ne me retire jamais la main : poser une section, corriger un tempo, resynchroniser une ligne doit toujours rester possible, quelle que soit la qualité de la détection automatique.

Le deuxième problème est personnel. Je suis à l'aise en Rust mais débutant complet en traitement du signal. XVII est autant un outil que je veux utiliser qu'un projet pour apprendre la programmation audio temps réel et les bases du DSP — FFT, détection de transitoires, estimation de tempo — avant de m'attaquer à la partie qui m'intéresse le plus.

Le vrai problème d'architecture, du coup, est celui-ci : comment construire un moteur audio dont le temps réel ne tolère aucune pause, tout en laissant une interface humaine et, demain, un assistant IA piloter la même application sans jamais dupliquer les règles de validation ?

## Les décisions techniques

**Le modèle ne connaît ni l'audio ni l'interface.** Le crate `xvii-model` ne dépend que de `serde` : c'est Cargo, pas une convention, qui interdit d'y faire entrer un type audio ou UI. Au-dessus, `xvii-engine` et `xvii-decode` ne connaissent que le modèle ; `xvii-app` (Dioxus) et `xvii-mcp` (le futur serveur pour un assistant IA) sont les deux seuls points d'assemblage, et ils ne se connaissent pas entre eux. Les deux passeront par la même porte, `EngineHandle`, où un gain est borné à ×1,5 et un seek à 2^60 frames : la validation vit une seule fois, pas une par consommateur.

**Le thread audio obéit à six règles non négociables, sans exception.** Le callback `cpal` ne doit jamais allouer ni désallouer de mémoire, jamais prendre un verrou, jamais faire d'entrée-sortie, et doit s'exécuter en temps borné ; toute communication passe par une file lock-free (`rtrb`, un producteur, un consommateur) et des atomiques. La règle la plus subtile : ne jamais relâcher un `Arc` sur ce thread — un remplacement de piste renvoie l'ancien vers une file de retour que l'interface vide tranquillement, avec un recours ultime si elle sature : laisser fuiter la mémoire (`mem::forget`) plutôt que de violer la règle. `xvii-engine/src/rt.rs` (486 lignes) concentre ce code, couvert par 12 tests qui s'exécutent sans carte son — les machines de CI n'en ont pas.

**Un changement de volume ne doit jamais être brutal.** Appliquer un gain instantanément produit un craquement audible (le « zipper noise ») : la forme d'onde fait une marche d'escalier. Les gains de piste, de mute et de master glissent donc vers leur valeur cible par un filtre exponentiel à un pôle, avec une constante de temps d'environ 5 ms — assez court pour réagir, assez long pour qu'aucune oreille ne l'entende.

**Toute donnée d'analyse doit rester modifiable à la main, avec sa provenance et sa confiance.** C'est la décision D11 : rien produit par le futur module d'analyse ne sera définitif, une section détectée portera une confiance et pourra être écrasée par une section posée à la main, qui fait toujours foi. Pas une précaution abstraite : sur un passage de voix saturée (growl, scream), une reconnaissance vocale perd les phonèmes et sa confiance s'effondre — le brief d'interface prévoit déjà un état visuel de « renoncement visible » plutôt que d'inventer un résultat. Je préfère un outil qui dit qu'il ne sait pas à un outil qui ment poliment.

**Une relecture critique du code a trouvé cinq bugs réels avant qu'ils ne deviennent audibles.** Un seek extrême faisait paniquer l'arithmétique de la tête de lecture ; un périphérique annonçant jusqu'à 34 canaux (mode « pro audio ») pouvait être retenu par défaut alors que le mixeur n'en nourrit que deux ; un double appui rapide sur lecture/pause pouvait avaler la seconde pression ; la lecture ne se mettait pas en pause en fin de fichier ; une erreur de périphérique n'était jamais remontée à l'interface. Les cinq ont été corrigés dans le même commit, la borne de seek ramenée à un point de validation unique.

## Ce que j'en ai appris

Que documenter une architecture avant d'écrire du code force à trancher des questions — quelle unité de temps interne, qui a le droit de modifier quoi — qu'on remet sinon à plus tard, quand elles coûtent le plus cher à corriger. Que le temps réel audio est une discipline à part : la plupart des réflexes de programmation « normale » (allouer, verrouiller, faire une entrée-sortie) y sont interdits, et il faut un vocabulaire différent — files lock-free, atomiques, file de retour — pour s'en passer. Et que poser une règle comme D11 dès la conception évite de découvrir après coup que l'auto-détection échouera sur ma propre musique.

Ce que je ferais autrement : ne pas laisser le projet en pause pendant plusieurs mois juste après l'échafaudage, et viser une tranche verticale plus courte qui atteint la partie DSP dès les premières séances, plutôt que de livrer toute la plomberie — workspace, décodage, fenêtre minimale — avant de m'y attaquer. C'est justement la partie qui m'intéressait le plus en démarrant, et c'est celle que je n'ai pas encore commencée.

## En chiffres

- 6 crates dans un workspace Cargo (resolver 3, édition 2024)
- 2 284 lignes de Rust sur 21 fichiers, 37 fichiers au total dans le dépôt
- 23 tests, dont 12 sur le thread audio temps réel, exécutés sans carte son
- 994 lignes de documentation sur 4 fichiers : l'architecture (684 lignes, 16 sections, 12 décisions actées avant le code), le brief d'interface (162 lignes), le contrat de collaboration (100 lignes), le README (48 lignes)
- CI GitHub Actions sur 3 OS (Ubuntu, Windows, macOS) dès le premier commit
- 10 commits, le 18 juillet 2026, en deux séances
- 1 phase livrée sur les 6 de la feuille de route

## Liens

- Dépôt : [github.com/morgan-voltz/xvii](https://github.com/morgan-voltz/xvii), public.
- Pas de démonstration en ligne ni de version packagée : l'usage actuel est `cargo run -p xvii-app -- morceau.mp3`, en local.

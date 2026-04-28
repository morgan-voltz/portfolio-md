# 01 — Pourquoi ce projet existe

**Temps de lecture** : environ 8 minutes
**Dernière mise à jour** : 22 avril 2026

---

Ce projet est né d'une contrariété.

En tant qu'étudiant en Licence Informatique à l'UHA 4.0 de Mulhouse, j'ai reçu une consigne qui semblait simple au premier regard : produire un portfolio en ligne pour présenter mes projets. Le cadre imposé était WordPress, et la consigne n'était pas négociable.

Si tu connais un peu l'écosystème du développement web moderne, tu vois probablement déjà où se situe ma contrariété. WordPress n'est pas un mauvais CMS — c'est même la plateforme la plus utilisée au monde pour publier du contenu, et il y a d'excellentes raisons à cela. Mais quand tu es un étudiant qui passe ses soirées à écrire du Rust, à lire des articles sur la Clean Architecture, à t'extasier devant Avalonia et à co-fonder une entreprise qui construit du logiciel en .NET, produire un portfolio sous WordPress peut ressembler à un pas en arrière.

## La tension de départ

Voilà comment j'ai vécu la situation au début. D'un côté, une contrainte académique explicite : le portfolio doit être un vrai WordPress utilisable, avec son éditeur Gutenberg, ses plugins SEO comme Yoast, son thème qui répond aux exigences du jury. De l'autre, une envie légitime de profiter de cet exercice pour apprendre quelque chose d'utile pour ma carrière. Parce qu'un portfolio, ce n'est pas juste un livrable scolaire qu'on oublie après la soutenance. C'est aussi ce que montreront mes futurs recruteurs pendant des années, quand je chercherai un stage, une alternance, un premier poste. C'est une vitrine qui a besoin d'être crédible techniquement.

Le piège était simple. Soit je me soumettais à la contrainte académique et je produisais un WordPress sobre qui coche la case du diplôme. Dans ce cas, je perdais une occasion énorme de construire quelque chose qui raconte vraiment ce que je sais faire. Soit je contournais la contrainte en construisant un portfolio React moderne mais non-conforme aux attentes académiques, auquel cas je me mettais en porte-à-faux avec ma formation et je risquais de rater l'objectif premier qui est de valider mon année.

Les deux options me semblaient mauvaises. Et c'est précisément parce qu'elles me semblaient toutes les deux mauvaises que j'ai commencé à me méfier de la façon dont la question était posée.

## Le réflexe qui a tout débloqué

Un des réflexes les plus utiles que j'ai appris en ingénierie, c'est de **ne pas accepter la formulation initiale d'un problème**. Quand un choix binaire se présente et que les deux options semblent mauvaises, c'est souvent parce que la question est mal posée. Ce n'est pas que les options sont mauvaises — c'est que la question force une opposition qui n'existe peut-être pas vraiment.

Dans mon cas, la question apparente était « WordPress ou framework moderne ? ». Mais si on regarde de plus près, cette question mélange des choses qui ne sont pas à la même échelle. WordPress n'est pas un *framework* au sens où on l'entend habituellement. C'est un *CMS* : un système qui gère le cycle de vie du contenu — l'écrire, le stocker, le publier, le rendre. Un framework moderne comme React, à l'inverse, n'est pas un CMS : c'est une librairie de rendu qui a besoin de recevoir ses données de quelque part. Les deux outils n'existent pas à la même couche de l'architecture. Ils peuvent très bien coexister dans un même système.

Et dès qu'on commence à les regarder à leurs bonnes couches respectives, une nouvelle question émerge, beaucoup plus intéressante que la première. **Le CMS et le rendu public doivent-ils nécessairement être fournis par la même technologie ?** Et si un même contenu, écrit une seule fois, pouvait alimenter plusieurs rendus différents, chacun optimisé pour son public ?

Cette reformulation change tout. Les deux objectifs qui semblaient contradictoires deviennent parallèles. Je peux avoir un site WordPress authentique — avec Gutenberg fonctionnel, Yoast SEO opérationnel, un thème qui répond aux exigences académiques — *et en même temps* un portfolio technique moderne, avec une API C# et un front React qui démontrent mes compétences techniques. Il suffit que les deux consomment le même contenu sous une forme commune, portable, indépendante de la technologie de rendu.

## L'idée directrice du projet

C'est cette intuition qui a fait naître tout le projet que tu consultes. L'architecture qui en a émergé repose sur un principe simple à formuler : **écrire le contenu une seule fois, en format portable, et le faire consommer par plusieurs interfaces**.

Le format portable, c'est du Markdown avec des métadonnées en YAML. Tu verras dans le chapitre suivant pourquoi ce choix n'est pas anodin et ce qu'il apporte stratégiquement, bien au-delà du simple confort d'écriture. Les plusieurs interfaces, ce sont d'un côté un site WordPress classique qui satisfait la contrainte académique, et de l'autre un portfolio technique React alimenté par une API C# qui sert de vitrine à mes compétences. Tu verras dans les chapitres trois et quatre pourquoi cette décomposition en plusieurs couches fait sens au-delà de l'effet de style, et pourquoi elle est même préparée pour accueillir de futures interfaces additionnelles (desktop, mobile, outil en ligne de commande) sans rien dupliquer.

Au passage — et c'est le bénéfice non-prévu qui a fini par devenir la vraie raison du projet — cette architecture m'apprend des choses que je n'aurais jamais touchées dans un simple WordPress. Les principes de la Clean Architecture. Le découpage propre des responsabilités entre composants. Les patterns d'intégration entre systèmes hétérogènes. Les conventions Git professionnelles qu'on utilise dans les vraies équipes. La discipline de la documentation. Ce sont exactement les compétences que je voulais acquérir, et qu'un portfolio WordPress standard ne m'aurait jamais fait développer. La contrainte académique est devenue le déclencheur, pas l'obstacle.

## Ce que tu trouveras dans les chapitres suivants

Cette documentation pédagogique est organisée en chapitres indépendants qui approfondissent chacun un aspect du projet. Tu peux les lire dans l'ordre pour suivre la progression naturelle, ou sauter directement à ceux qui t'intéressent.

Le chapitre deux expliquera pourquoi le Markdown comme format pivot est une décision stratégique, et pas juste une préférence esthétique de développeur.

Le chapitre trois répondra à la question légitime « mais pourquoi ne pas se contenter de WordPress, qui fait déjà très bien son job ? », en exposant les bénéfices réels de l'architecture en couches qu'on a mise en place.

Le chapitre quatre décortiquera la Clean Architecture à partir d'exemples concrets du projet, pour que tu comprennes pourquoi trois dossiers peuvent changer radicalement la maintenabilité d'un code à long terme, même pour une application en apparence simple.

Les chapitres suivants aborderont des aspects plus spécifiques selon ton besoin : le pattern « un cœur métier, plusieurs interfaces » qui justifie la sophistication architecturale, WordPress vu de l'intérieur quand on le considère comme un système extensible plutôt qu'une boîte noire, les conventions de code et de commits qui rendent un projet lisible même des mois après son écriture, et une réflexion plus personnelle sur ce que signifie collaborer avec une intelligence artificielle dans un projet d'apprentissage sans se laisser remplacer par elle.

## Une dernière chose avant que tu continues

Ce projet est en développement actif. Au moment où tu lis ces lignes, une partie du code existe peut-être déjà, une autre est peut-être encore à écrire. Mais même incomplet, le projet reste intéressant à étudier pour ce qu'il représente : une tentative de transformer une contrainte en opportunité, en refusant les fausses dichotomies qu'on nous présente parfois comme les seules options possibles.

Si tu es toi-même étudiant en informatique et que tu reconnais un peu de ta propre situation dans ce que je raconte ici, j'espère que cette documentation te servira autant qu'elle m'a servi à moi pour clarifier ma pensée. Écrire pour expliquer est souvent le meilleur moyen de vraiment comprendre ce qu'on fait. Et cette doc n'a pas d'autre prétention que celle-là : partager un cheminement, en espérant qu'il te donnera des idées pour le tien.

Bonne lecture.

---

**Chapitre suivant** : [02 — Le Markdown comme format pivot](02-le-markdown-comme-format-pivot.md) *(à rédiger)*

**Retour à** : [documentation pédagogique](README.md) • [documentation d'architecture](../architecture/00-overview.md)

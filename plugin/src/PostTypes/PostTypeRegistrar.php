<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\PostTypes;

/**
 * Enregistre les custom post types du portfolio.
 *
 * Cette classe est invoquée par la composition root (Plugin) au moment
 * du hook WordPress 'init'. C'est le bon moment pour déclarer des post
 * types : WordPress a fini son initialisation interne mais n'a pas encore
 * commencé le routage des requêtes.
 *
 * Les choix d'arguments (slugs, supports, visibilité) sont documentés
 * dans docs/architecture/04-structure-wordpress.md sections 3.2 et 3.3.
 */
final class PostTypeRegistrar
{
    /**
     * Domaine de traduction du plugin.
     * Doit correspondre au "Text Domain" déclaré dans portfolio-md.php.
     */
    private const TEXT_DOMAIN = 'portfolio-md';

    /**
     * Méthode appelée par le hook WordPress 'init'.
     * Enregistre tous les post types du plugin dans l'ordre.
     */
    public function register(): void
    {
        $this->registerArticle();
        $this->registerProject();
    }

    /**
     * Enregistre le post type "article".
     *
     * Choix : on n'utilise PAS le post type natif 'post' de WordPress.
     * Voir docs/architecture/04-structure-wordpress.md section 3.1
     * pour le raisonnement complet.
     */
    private function registerArticle(): void
    {
        register_post_type('article', [
            'labels' => [
                'name'                  => _x('Articles', 'post type general name', self::TEXT_DOMAIN),
                'singular_name'         => _x('Article', 'post type singular name', self::TEXT_DOMAIN),
                'menu_name'             => _x('Articles', 'admin menu', self::TEXT_DOMAIN),
                'name_admin_bar'        => _x('Article', 'add new on admin bar', self::TEXT_DOMAIN),
                'add_new'               => __('Ajouter', self::TEXT_DOMAIN),
                'add_new_item'          => __('Ajouter un article', self::TEXT_DOMAIN),
                'new_item'              => __('Nouvel article', self::TEXT_DOMAIN),
                'edit_item'             => __('Modifier l\'article', self::TEXT_DOMAIN),
                'view_item'             => __('Voir l\'article', self::TEXT_DOMAIN),
                'all_items'             => __('Tous les articles', self::TEXT_DOMAIN),
                'search_items'          => __('Rechercher un article', self::TEXT_DOMAIN),
                'not_found'             => __('Aucun article trouvé.', self::TEXT_DOMAIN),
                'not_found_in_trash'    => __('Aucun article dans la corbeille.', self::TEXT_DOMAIN),
                'featured_image'        => __('Image à la une', self::TEXT_DOMAIN),
                'set_featured_image'    => __('Définir l\'image à la une', self::TEXT_DOMAIN),
                'remove_featured_image' => __('Retirer l\'image à la une', self::TEXT_DOMAIN),
                'use_featured_image'    => __('Utiliser comme image à la une', self::TEXT_DOMAIN),
                'archives'              => __('Archives des articles', self::TEXT_DOMAIN),
                'attributes'            => __('Attributs de l\'article', self::TEXT_DOMAIN),
            ],

            // Visibilité côté public et indexation moteurs.
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_rest'        => true, // Indispensable pour Gutenberg.
            'has_archive'         => true,

            // URL : /articles/<slug>/.
            'rewrite' => [
                'slug'       => 'articles',
                'with_front' => false,
            ],

            // Hiérarchie : non, les articles ne sont pas imbriqués.
            'hierarchical' => false,

            // Position dans le menu admin (5 = après "Articles" natif).
            'menu_position' => 5,
            'menu_icon'     => 'dashicons-edit-large',

            // Capacités : on hérite des capacités du type 'post' natif.
            // Cela évite de devoir créer des capacités custom à ce stade.
            'capability_type' => 'post',

            // Fonctionnalités prises en charge par l'éditeur :
            // - title    : champ titre
            // - editor   : éditeur Gutenberg
            // - excerpt  : champ extrait (résumé)
            // - revisions: historique des modifications
            // - author   : suivi de l'auteur
            // - custom-fields : champs personnalisés (post meta)
            'supports' => [
                'title',
                'editor',
                'excerpt',
                'revisions',
                'author',
                'custom-fields',
            ],
        ]);
    }

    /**
     * Enregistre le post type "project".
     *
     * Différences principales avec "article" :
     *   - URL en /projets/<slug>/
     *   - Supports incluent 'thumbnail' (image de couverture du projet)
     *   - Icône différente dans le menu admin
     */
    private function registerProject(): void
    {
        register_post_type('project', [
            'labels' => [
                'name'                  => _x('Projets', 'post type general name', self::TEXT_DOMAIN),
                'singular_name'         => _x('Projet', 'post type singular name', self::TEXT_DOMAIN),
                'menu_name'             => _x('Projets', 'admin menu', self::TEXT_DOMAIN),
                'name_admin_bar'        => _x('Projet', 'add new on admin bar', self::TEXT_DOMAIN),
                'add_new'               => __('Ajouter', self::TEXT_DOMAIN),
                'add_new_item'          => __('Ajouter un projet', self::TEXT_DOMAIN),
                'new_item'              => __('Nouveau projet', self::TEXT_DOMAIN),
                'edit_item'             => __('Modifier le projet', self::TEXT_DOMAIN),
                'view_item'             => __('Voir le projet', self::TEXT_DOMAIN),
                'all_items'             => __('Tous les projets', self::TEXT_DOMAIN),
                'search_items'          => __('Rechercher un projet', self::TEXT_DOMAIN),
                'not_found'             => __('Aucun projet trouvé.', self::TEXT_DOMAIN),
                'not_found_in_trash'    => __('Aucun projet dans la corbeille.', self::TEXT_DOMAIN),
                'featured_image'        => __('Image de couverture', self::TEXT_DOMAIN),
                'set_featured_image'    => __('Définir l\'image de couverture', self::TEXT_DOMAIN),
                'remove_featured_image' => __('Retirer l\'image de couverture', self::TEXT_DOMAIN),
                'use_featured_image'    => __('Utiliser comme image de couverture', self::TEXT_DOMAIN),
                'archives'              => __('Archives des projets', self::TEXT_DOMAIN),
                'attributes'            => __('Attributs du projet', self::TEXT_DOMAIN),
            ],

            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_rest'        => true,
            'has_archive'         => true,

            // URL : /projets/<slug>/.
            'rewrite' => [
                'slug'       => 'projets',
                'with_front' => false,
            ],

            'hierarchical' => false,

            'menu_position' => 6,
            'menu_icon'     => 'dashicons-portfolio',

            'capability_type' => 'post',

            // Différence notable : on inclut 'thumbnail' pour permettre
            // une image de couverture sur les projets (utile pour la home
            // et les listes de projets).
            'supports' => [
                'title',
                'editor',
                'excerpt',
                'revisions',
                'author',
                'custom-fields',
                'thumbnail',
            ],
        ]);
    }
}

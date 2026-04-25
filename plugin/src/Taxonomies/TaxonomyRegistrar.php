<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Taxonomies;

/**
 * Enregistre les taxonomies du portfolio.
 *
 * Cette classe est invoquée par la composition root (Plugin) au moment
 * du hook WordPress 'init', juste après PostTypeRegistrar. L'ordre
 * importe : on doit avoir déclaré les post types avant de pouvoir y
 * attacher des taxonomies.
 *
 * Les deux taxonomies du portfolio :
 *   - tech_tag       : technologies / concepts. Partagée article + project.
 *   - project_status : statut du projet. Réservée à project, valeurs fermées.
 *
 * Voir docs/architecture/04-structure-wordpress.md sections 4.1 et 4.2
 * pour le raisonnement complet.
 */
final class TaxonomyRegistrar
{
    /**
     * Domaine de traduction du plugin.
     */
    private const TEXT_DOMAIN = 'portfolio-md';

    /**
     * Slug de la taxonomie tech_tag (utilisé dans le code et les URLs).
     */
    public const TECH_TAG = 'tech_tag';

    /**
     * Slug de la taxonomie project_status.
     */
    public const PROJECT_STATUS = 'project_status';

    /**
     * Statuts fermés du post type project, sous forme [slug => libellé].
     *
     * Le slug est l'identifiant technique stocké en base.
     * Le libellé est ce qu'on affiche dans l'admin et le front.
     *
     * Cette liste est délibérément fermée : on ne s'attend pas à voir
     * de nouvelles valeurs ajoutées à la volée. Si un nouveau statut
     * devait apparaître un jour, ça mériterait une décision documentée.
     */
    public const PROJECT_STATUS_TERMS = [
        'en-cours'  => 'En cours',
        'stable'    => 'Stable',
        'archive'   => 'Archivé',
        'prototype' => 'Prototype',
    ];

    /**
     * Méthode appelée par le hook WordPress 'init'.
     * Enregistre toutes les taxonomies du plugin.
     */
    public function register(): void
    {
        $this->registerTechTag();
        $this->registerProjectStatus();
    }

    /**
     * Insère les termes fermés de project_status si absents.
     *
     * Cette méthode est appelée à l'activation du plugin (depuis Plugin::onActivation),
     * pas au hook 'init'. Elle est idempotente : si les termes existent déjà,
     * elle ne fait rien.
     *
     * Note : WordPress ne fournit pas de mécanisme natif pour interdire
     * la création de termes additionnels via l'admin. À l'étape 3 du
     * plan de plugin, on pourra durcir cela (capabilities custom ou
     * masquage du formulaire d'ajout). Pour l'instant, c'est documenté
     * dans 04-structure-wordpress.md comme une convention.
     */
    public function ensureProjectStatusTerms(): void
    {
        foreach (self::PROJECT_STATUS_TERMS as $slug => $label) {
            // term_exists retourne null si absent, ou un tableau/int si présent.
            if (term_exists($slug, self::PROJECT_STATUS) === null) {
                wp_insert_term(
                    $label,
                    self::PROJECT_STATUS,
                    ['slug' => $slug]
                );
            }
        }
    }

    /**
     * Taxonomie tech_tag : tags techniques partagés entre articles et projets.
     *
     * Pourquoi pas la taxonomie post_tag native :
     *   - séparation des concerns (post_tag est conçue pour les posts natifs) ;
     *   - slug d'URL propre (/tag/rust/ uniquement pour nos tags techniques) ;
     *   - expressivité du nom (tech_tag dit clairement de quoi on parle).
     */
    private function registerTechTag(): void
    {
        register_taxonomy(self::TECH_TAG, ['article', 'project'], [
            'labels' => [
                'name'                       => _x('Tags techniques', 'taxonomy general name', self::TEXT_DOMAIN),
                'singular_name'              => _x('Tag technique', 'taxonomy singular name', self::TEXT_DOMAIN),
                'menu_name'                  => __('Tags techniques', self::TEXT_DOMAIN),
                'all_items'                  => __('Tous les tags', self::TEXT_DOMAIN),
                'edit_item'                  => __('Modifier le tag', self::TEXT_DOMAIN),
                'view_item'                  => __('Voir le tag', self::TEXT_DOMAIN),
                'update_item'                => __('Mettre à jour le tag', self::TEXT_DOMAIN),
                'add_new_item'               => __('Ajouter un tag', self::TEXT_DOMAIN),
                'new_item_name'              => __('Nom du nouveau tag', self::TEXT_DOMAIN),
                'search_items'               => __('Rechercher un tag', self::TEXT_DOMAIN),
                'popular_items'              => __('Tags les plus utilisés', self::TEXT_DOMAIN),
                'separate_items_with_commas' => __('Séparer les tags par des virgules', self::TEXT_DOMAIN),
                'add_or_remove_items'        => __('Ajouter ou retirer des tags', self::TEXT_DOMAIN),
                'choose_from_most_used'      => __('Choisir parmi les tags les plus utilisés', self::TEXT_DOMAIN),
                'not_found'                  => __('Aucun tag trouvé.', self::TEXT_DOMAIN),
            ],

            // Visibilité.
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => true,
            'show_in_rest'       => true, // Indispensable pour Gutenberg.
            'show_tagcloud'      => true,
            'show_admin_column'  => true, // Affiche une colonne dans la liste des contenus.

            // Taxonomie plate (comme les tags natifs, pas comme les catégories).
            'hierarchical' => false,

            // URL : /tag/<slug>/.
            'rewrite' => [
                'slug'         => 'tag',
                'with_front'   => false,
                'hierarchical' => false,
            ],
        ]);
    }

    /**
     * Taxonomie project_status : statut du projet (En cours, Stable, etc.).
     *
     * Caractéristiques notables :
     *   - réservée au post type 'project' uniquement ;
     *   - valeurs fermées (voir PROJECT_STATUS_TERMS et ensureProjectStatusTerms) ;
     *   - URL en /statut/<slug>/.
     */
    private function registerProjectStatus(): void
    {
        register_taxonomy(self::PROJECT_STATUS, ['project'], [
            'labels' => [
                'name'                  => _x('Statuts de projet', 'taxonomy general name', self::TEXT_DOMAIN),
                'singular_name'         => _x('Statut de projet', 'taxonomy singular name', self::TEXT_DOMAIN),
                'menu_name'             => __('Statuts', self::TEXT_DOMAIN),
                'all_items'             => __('Tous les statuts', self::TEXT_DOMAIN),
                'edit_item'             => __('Modifier le statut', self::TEXT_DOMAIN),
                'view_item'             => __('Voir le statut', self::TEXT_DOMAIN),
                'update_item'           => __('Mettre à jour le statut', self::TEXT_DOMAIN),
                'add_new_item'          => __('Ajouter un statut', self::TEXT_DOMAIN),
                'new_item_name'         => __('Nom du nouveau statut', self::TEXT_DOMAIN),
                'search_items'          => __('Rechercher un statut', self::TEXT_DOMAIN),
                'parent_item'           => __('Statut parent', self::TEXT_DOMAIN),
                'parent_item_colon'     => __('Statut parent :', self::TEXT_DOMAIN),
                'not_found'             => __('Aucun statut trouvé.', self::TEXT_DOMAIN),
            ],

            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => true,
            'show_in_rest'       => true,
            'show_tagcloud'      => false, // Un statut n'est pas un tag.
            'show_admin_column'  => true,

            // Plate (comme tech_tag).
            'hierarchical' => false,

            // URL : /statut/<slug>/.
            'rewrite' => [
                'slug'         => 'statut',
                'with_front'   => false,
                'hierarchical' => false,
            ],
        ]);
    }
}

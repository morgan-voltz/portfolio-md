<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd;

/**
 * Composition root du plugin Portfolio MD.
 *
 * Cette classe est l'unique endroit où :
 *   - les services sont instanciés ;
 *   - les hooks WordPress sont accrochés.
 *
 * Cette centralisation évite l'anti-pattern fréquent dans les plugins
 * WordPress, où chaque fichier enregistre ses propres hooks globalement,
 * créant un graphe de dépendances invisible et difficile à tester.
 *
 * Quand de nouveaux services seront ajoutés (post types, taxonomies,
 * pipeline de conversion, endpoints REST, etc.), ils seront instanciés
 * dans le constructeur et leurs hooks accrochés dans boot().
 */
final class Plugin
{
    /**
     * Chemin absolu du fichier portfolio-md.php (point d'entrée).
     *
     * Utilisé par WordPress pour identifier le plugin (notamment pour
     * register_activation_hook et register_deactivation_hook).
     */
    private string $pluginFile;

    public function __construct(string $pluginFile)
    {
        $this->pluginFile = $pluginFile;
    }

    /**
     * Démarre le plugin : accroche les hooks WordPress vers les services.
     *
     * Cette méthode est appelée une seule fois, depuis portfolio-md.php.
     * Elle ne fait pas de travail directement — elle se contente de
     * dire à WordPress « quand tel événement arrive, appelle telle méthode ».
     */
    public function boot(): void
    {
        // Pour l'instant, le plugin ne fait rien d'observable.
        // Aux temps 2 et 3, on ajoutera ici l'enregistrement des
        // post types et des taxonomies via leurs registrars dédiés.

        // Hooks de cycle de vie du plugin (activation, désactivation).
        register_activation_hook($this->pluginFile, [$this, 'onActivation']);
        register_deactivation_hook($this->pluginFile, [$this, 'onDeactivation']);
    }

    /**
     * Appelé une fois quand l'admin clique sur "Activer" dans la liste
     * des extensions WordPress.
     *
     * À ce stade, on se contente de flush les rewrite rules pour que
     * les futures URLs des custom post types (à venir au temps 2) soient
     * prises en compte sans avoir à aller cliquer manuellement dans
     * Réglages > Permaliens.
     */
    public function onActivation(): void
    {
        flush_rewrite_rules();
    }

    /**
     * Appelé une fois quand l'admin clique sur "Désactiver".
     *
     * On flush également les rewrite rules pour nettoyer les URLs
     * spécifiques au plugin (custom post types) qui ne doivent plus
     * être routées par WordPress.
     */
    public function onDeactivation(): void
    {
        flush_rewrite_rules();
    }
}

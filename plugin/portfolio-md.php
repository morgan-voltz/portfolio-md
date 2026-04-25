<?php
/**
 * Plugin Name:       Portfolio MD
 * Plugin URI:        https://voltz.dev
 * Description:       Markdown comme format pivot pour les articles et projets du portfolio.
 * Version:           0.1.0
 * Requires PHP:      8.2
 * Requires at least: 6.4
 * Author:            Morgan Voltz
 * Author URI:        https://voltz.dev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       portfolio-md
 *
 * @package Voltz\PortfolioMd
 */

declare(strict_types=1);

// Empêche l'accès direct au fichier (sécurité standard WordPress).
// Si quelqu'un tape l'URL du fichier directement, on coupe court.
defined('ABSPATH') || exit;

// Charge l'autoloader Composer.
// À partir de cette ligne, toute classe sous le namespace Voltz\PortfolioMd
// est chargée automatiquement quand on l'utilise.
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    // Message clair si Composer n'a pas été lancé.
    // Sans cette vérification, on aurait une erreur fatale obscure.
    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>Portfolio MD :</strong> ';
        echo 'l\'autoloader Composer est manquant. Lance <code>composer install</code> ';
        echo 'dans le dossier du plugin.';
        echo '</p></div>';
    });
    return;
}
require_once $autoload;

// Instancie et démarre le plugin.
// La classe Plugin est la composition root : elle orchestre tout le reste.
(new \Voltz\PortfolioMd\Plugin(__FILE__))->boot();

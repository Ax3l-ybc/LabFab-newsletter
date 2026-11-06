<?php
/**
 * Plugin Name: Newsletter - LabFab automatique
 * Description: Addon pour The Newsletter Plugin. Prépare chaque mois la lettre du LabFab (articles du mois précédent et événements à venir), envoie un test, puis la programme pour tous les abonné·es.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: newsletter
 * Author: LabFab
 * License: GPLv2 or later
 * Text Domain: newsletter-labfab
 */

defined('ABSPATH') || exit;

define('LABFAB_NL_VERSION', '1.0.0');
define('LABFAB_NL_DIR', __DIR__);

// The Newsletter Plugin annonce qu'il est chargé : on démarre l'addon à ce moment-là.
add_action('newsletter_loaded', function ($version) {
    if (version_compare($version, '8.0.0', '<')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>Newsletter LabFab automatique nécessite The Newsletter Plugin 8.0 ou plus récent.</p></div>';
        });
        return;
    }
    require_once __DIR__ . '/includes/class-contenu.php';
    require_once __DIR__ . '/includes/class-source.php';
    require_once __DIR__ . '/includes/class-addon.php';
    new LabFab_Newsletter_Addon(__DIR__);
});

add_action('admin_notices', function () {
    if (!class_exists('Newsletter') && current_user_can('activate_plugins')) {
        echo '<div class="notice notice-error"><p>Newsletter LabFab automatique a besoin de l\'extension <strong>The Newsletter Plugin</strong>, qui n\'est pas active.</p></div>';
    }
});

// Arrête la tâche quotidienne si l'addon est désactivé alors que The Newsletter Plugin ne l'est plus.
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('labfab_newsletter_quotidien');
});

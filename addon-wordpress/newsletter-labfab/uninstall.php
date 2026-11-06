<?php
/**
 * Suppression de l'addon : on efface ses réglages et son historique.
 * Les lettres déjà créées restent dans The Newsletter Plugin.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('labfab_newsletter_reglages');
delete_option('labfab_newsletter_historique');
delete_option('newsletter_labfab_version');
wp_clear_scheduled_hook('labfab_newsletter_quotidien');

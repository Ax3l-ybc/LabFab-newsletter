<?php
/**
 * Page Newsletter → LabFab automatique.
 *
 * Variables : $this (LabFab_Newsletter_Addon), $avis, $apercu, $mois.
 */
defined('ABSPATH') || exit;

$r = $this->reglages();
$historique = $this->historique();
$prochaine_verif = wp_next_scheduled(self::TACHE);
$maintenant = current_datetime();
$prochain_mois = $maintenant->modify('first day of next month')->format('Y-m');
$jour_prepa = (int) $r['jour_preparation'];
$date_prepa = $maintenant->setDate((int) $maintenant->format('Y'), (int) $maintenant->format('n'), $jour_prepa);
$programmee = $this->lettre_programmee($prochain_mois);
$noms = LabFab_NL_Contenu::MOIS;
$nom_mois = function ($m) use ($noms) {
    return $noms[(int) substr($m, 5, 2) - 1] . ' ' . substr($m, 0, 4);
};
$libelles = [
    'programmee' => ['Programmée', '#1e7e34'],
    'brouillon' => ['Brouillon', '#1f4e5f'],
    'rien' => ['Pas de lettre', '#6c757d'],
    'erreur' => ['Erreur', '#b32d2e'],
];
?>
<div class="wrap">
    <h1>Newsletter LabFab automatique</h1>
    <p>Chaque mois, le <strong><?php echo esc_html($jour_prepa); ?></strong>, cet addon prépare la lettre du mois suivant
        à partir des articles et des événements du site et vous en envoie un exemplaire de test.
        <?php if ($r['envoi_auto']) : ?>
            Il la programme ensuite dans The Newsletter Plugin pour le <strong><?php echo esc_html(self::date_lisible($this->date_envoi($prochain_mois)->getTimestamp())); ?></strong>
            (puis à la même date les mois suivants). Entre les deux, vous pouvez la relire, la modifier ou l'annuler dans
        <?php else : ?>
            L'envoi automatique étant désactivé, la lettre reste en <strong>brouillon</strong> : vous la relisez et l'envoyez vous-même depuis
        <?php endif; ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=newsletter_emails_index')); ?>">Newsletter → Newsletters</a>.</p>

    <?php foreach ($avis as list($type, $texte)) : ?>
        <div class="notice notice-<?php echo esc_attr($type); ?>"><p><?php echo esc_html($texte); ?></p></div>
    <?php endforeach; ?>

    <h2>État</h2>
    <table class="widefat striped" style="max-width:820px">
        <tr>
            <th style="width:260px">Automatisation</th>
            <td><?php if (!$r['actif']) : ?>
                    <strong style="color:#b32d2e">Désactivée</strong> : aucune lettre ne sera préparée automatiquement. Cochez « Activer » dans les réglages ci-dessous.
                <?php elseif ($r['envoi_auto']) : ?>
                    <strong style="color:#1e7e34">Activée, avec envoi automatique</strong>
                <?php else : ?>
                    <strong style="color:#1f4e5f">Activée, sans envoi automatique</strong> : les lettres sont préparées en brouillon.
                <?php endif; ?></td>
        </tr>
        <tr>
            <th>Prochaine lettre</th>
            <td>
                <?php echo esc_html($nom_mois($prochain_mois)); ?> :
                <?php if ($programmee) : ?>
                    programmée. <a href="<?php echo esc_url(admin_url('admin.php?page=newsletter_emails_edit&id=' . $programmee)); ?>">Ouvrir la lettre</a>
                <?php elseif ($this->deja_traitee($prochain_mois)) : ?>
                    déjà traitée (voir l'historique).
                <?php elseif ((int) $maintenant->format('j') >= $jour_prepa) : ?>
                    préparation à la prochaine vérification.
                <?php else : ?>
                    préparation le <?php echo esc_html(wp_date('j F Y', $date_prepa->getTimestamp())); ?>,
                    <?php if ($r['envoi_auto']) : ?>
                        envoi le <?php echo esc_html(self::date_lisible($this->date_envoi($prochain_mois)->getTimestamp(), true)); ?>.
                    <?php else : ?>
                        en brouillon (envoi automatique désactivé).
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Prochaine vérification quotidienne</th>
            <td><?php echo $prochaine_verif ? esc_html(wp_date('j F Y à H:i', $prochaine_verif)) : 'non programmée'; ?>
                <br><span class="description">Les tâches programmées de WordPress se déclenchent lors des visites du site : l'heure peut glisser un peu.</span></td>
        </tr>
    </table>

    <h2>Aperçu et préparation à la main</h2>
    <form method="post" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap">
        <?php wp_nonce_field('labfab_newsletter'); ?>
        <label for="labfab-mois">Lettre du mois de</label>
        <input type="month" id="labfab-mois" name="mois" value="<?php echo esc_attr($mois); ?>" required>
        <button class="button" name="labfab_action" value="apercu">Voir l'aperçu</button>
        <button class="button" name="labfab_action" value="brouillon">Créer un brouillon</button>
        <button class="button button-primary" name="labfab_action" value="programmer"
                onclick="return confirm('Programmer cette lettre ? Elle sera envoyée automatiquement à tous les abonné·es à la date prévue.');">
            Préparer et programmer l'envoi</button>
    </form>
    <p class="description">L'aperçu n'envoie et n'enregistre rien. « Créer un brouillon » ajoute la lettre dans Newsletter → Newsletters sans l'envoyer.</p>

    <?php if ($apercu) : ?>
        <div style="max-width:820px; margin-top:12px">
            <?php if (!$apercu['a_envoyer']) : ?>
                <div class="notice notice-warning inline"><p>Rien ne s'est passé en <?php echo esc_html($apercu['mois_precedent']); ?>
                    et aucun événement n'est prévu en <?php echo esc_html($apercu['mois']); ?> : aucune lettre ne serait envoyée.</p></div>
            <?php endif; ?>
            <p><strong>Objet :</strong> <?php echo esc_html($apercu['sujet']); ?></p>
            <pre style="background:#fff; padding:10px; border:1px solid #dcdcde"><?php echo esc_html(self::resume($apercu)); ?></pre>
            <iframe title="Aperçu de la lettre" sandbox style="width:100%; height:900px; border:1px solid #dcdcde; background:#fff"
                    srcdoc="<?php echo esc_attr($this->rendre($apercu)); ?>"></iframe>
        </div>
    <?php endif; ?>

    <h2>Réglages</h2>
    <form method="post">
        <?php wp_nonce_field('labfab_newsletter'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Automatisation</th>
                <td><label><input type="checkbox" name="reglages[actif]" value="1" <?php checked($r['actif']); ?>>
                    Préparer automatiquement la lettre chaque mois</label></td>
            </tr>
            <tr>
                <th scope="row">Envoi</th>
                <td><label><input type="checkbox" name="reglages[envoi_auto]" value="1" <?php checked($r['envoi_auto']); ?>>
                    Envoyer automatiquement la lettre aux abonné·es à la date prévue</label>
                    <p class="description">Décoché : la lettre est créée en <strong>brouillon</strong>, et c'est vous qui l'envoyez depuis Newsletter → Newsletters.</p></td>
            </tr>
            <tr>
                <th scope="row"><label for="labfab-prepa">Jour de préparation</label></th>
                <td><input type="number" id="labfab-prepa" name="reglages[jour_preparation]" min="1" max="28" value="<?php echo esc_attr($r['jour_preparation']); ?>" class="small-text">
                    <p class="description">Jour du mois où la lettre du mois <strong>suivant</strong> est préparée et où vous recevez le test.</p></td>
            </tr>
            <tr>
                <th scope="row"><label for="labfab-envoi">Jour et heure d'envoi</label></th>
                <td>le <input type="number" id="labfab-envoi" name="reglages[jour_envoi]" min="1" max="28" value="<?php echo esc_attr($r['jour_envoi']); ?>" class="small-text">
                    à <input type="number" name="reglages[heure_envoi]" min="0" max="23" value="<?php echo esc_attr($r['heure_envoi']); ?>" class="small-text"> h
                    <p class="description">Si ce jour vient après le jour de préparation (ex. préparation le 25, envoi le 28), la lettre part à la <strong>fin du mois en cours</strong>, avant le début de son mois.
                        Sinon (ex. envoi le 1), elle part au début du mois de la lettre.</p></td>
            </tr>
            <tr>
                <th scope="row"><label for="labfab-test">Adresse de test</label></th>
                <td><input type="email" id="labfab-test" name="reglages[email_test]" value="<?php echo esc_attr($r['email_test']); ?>" class="regular-text">
                    <p class="description">Reçoit l'exemplaire de test et les messages de l'addon (lettre programmée, pas de lettre, erreur).</p></td>
            </tr>
            <tr>
                <th scope="row"><label for="labfab-sujet">Objet du mail</label></th>
                <td><input type="text" id="labfab-sujet" name="reglages[sujet]" value="<?php echo esc_attr($r['sujet']); ?>" class="regular-text">
                    <p class="description"><code>{mois}</code> et <code>{annee}</code> sont remplacés automatiquement.</p></td>
            </tr>
            <tr>
                <th scope="row"><label for="labfab-logo">Adresse du logo</label></th>
                <td><input type="url" id="labfab-logo" name="reglages[logo_url]" value="<?php echo esc_attr($r['logo_url']); ?>" class="large-text">
                    <p class="description">Image PNG publique (Gmail et Outlook n'affichent pas le SVG).</p></td>
            </tr>
            <tr>
                <th scope="row"><label for="labfab-edito">Édito de la prochaine lettre</label></th>
                <td><textarea id="labfab-edito" name="reglages[edito]" rows="5" class="large-text"><?php echo esc_textarea($r['edito']); ?></textarea>
                    <p class="description">Facultatif. Une ligne vide sépare deux paragraphes. Il est vidé une fois la lettre programmée.</p></td>
            </tr>
        </table>
        <p><button class="button button-primary" name="labfab_action" value="enregistrer">Enregistrer les réglages</button></p>
    </form>

    <h2>Historique</h2>
    <?php if (!$historique) : ?>
        <p>Aucune lettre préparée pour l'instant.</p>
    <?php else : ?>
        <table class="widefat striped" style="max-width:1000px">
            <thead><tr><th>Lettre</th><th>Résultat</th><th>Le</th><th>Détail</th></tr></thead>
            <tbody>
            <?php foreach ($historique as $h) :
                list($libelle, $couleur) = $libelles[$h['resultat']] ?? [$h['resultat'], '#000']; ?>
                <tr>
                    <td><?php echo esc_html($nom_mois($h['mois'])); ?></td>
                    <td><strong style="color:<?php echo esc_attr($couleur); ?>"><?php echo esc_html($libelle); ?></strong>
                        <?php if (($h['declencheur'] ?? '') === 'manuel') : ?><br><span class="description">à la main</span><?php endif; ?></td>
                    <td><?php echo esc_html($h['date'] ?? ''); ?></td>
                    <td>
                        <?php echo esc_html($h['message'] ?? ''); ?>
                        <?php if (!empty($h['email_id'])) : ?>
                            <br><a href="<?php echo esc_url(admin_url('admin.php?page=newsletter_emails_edit&id=' . (int) $h['email_id'])); ?>">Ouvrir la lettre</a>
                        <?php endif; ?>
                        <?php if (!empty($h['resume'])) : ?>
                            <details><summary>Contenu</summary><pre><?php echo esc_html($h['resume']); ?></pre></details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

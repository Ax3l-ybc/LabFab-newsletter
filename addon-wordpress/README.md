# Addon WordPress « Newsletter LabFab automatique »

Extension pour **The Newsletter Plugin**, à installer sur le WordPress de labfab.fr. Elle fait tout le travail **dans WordPress**, sans GitHub ni PC :

1. **Le 25 de chaque mois**, elle prépare la lettre du mois suivant à partir des articles et des événements du site. Elle applique les mêmes règles que le programme Python du dépôt.
2. Elle crée la lettre dans **Newsletter → Newsletters** et la **programme pour le 1er du mois** à 9 h.
3. Elle vous envoie **un exemplaire de test** et un mail récapitulatif, avec le lien pour relire ou annuler la lettre.
4. **Le 1er**, The Newsletter Plugin l'envoie à toutes les abonné·es confirmé·es, avec les liens de désinscription et les statistiques habituelles.

S'il ne s'est rien passé le mois précédent et que rien n'est prévu, aucune lettre n'est créée et vous recevez un mail « Pas de newsletter pour … ».

Les jours, l'heure, l'objet, le logo et l'adresse de test sont réglables.

## Installation

Il faut être **administrateur·rice** du WordPress et avoir **The Newsletter Plugin** (version 8 ou plus récente) installé et activé.

1. Téléchargez **`newsletter-labfab.zip`** (dans ce dossier, sur GitHub : cliquez sur le fichier, puis sur le bouton de téléchargement).
2. Dans WordPress : **Extensions → Ajouter une extension → Téléverser une extension**, choisissez le ZIP, puis **Installer maintenant** et **Activer**.
3. Ouvrez **Newsletter → LabFab automatique**.
4. Vérifiez les réglages, en particulier l'**adresse de test**. Cliquez sur **Voir l'aperçu** pour contrôler la lettre du mois prochain.
5. Quand tout vous convient, cochez **Activer la préparation et l'envoi automatiques**, puis enregistrez.

L'automatisation est **désactivée à l'installation** : rien ne part tant que vous ne l'avez pas activée.

### Premier essai conseillé

Avant d'activer l'automatisation :
1. Cliquez sur **Créer un brouillon** : la lettre apparaît dans Newsletter → Newsletters, sans être envoyée.
2. Ouvrez-la et envoyez-vous un test avec le bouton **Test** du plugin.
3. Vérifiez l'affichage, les liens et le lien de désinscription, puis supprimez ce brouillon.

## Au quotidien

| Je veux… | Comment |
|---|---|
| Relire la lettre programmée | Le mail récapitulatif contient le lien « Relire, modifier ou annuler » |
| **Annuler** l'envoi | Newsletter → Newsletters → ouvrir la lettre → **Pause**, ou la supprimer. Elle ne sera pas recréée automatiquement |
| Ajouter un **édito** | Newsletter → LabFab automatique → champ « Édito de la prochaine lettre », **avant le 25**. Il est vidé une fois la lettre programmée |
| Refaire la lettre après une annulation | Bouton **Préparer et programmer l'envoi** |
| Voir ce qui s'est passé les mois précédents | Tableau **Historique** en bas de la page |

## Sécurités

- **Pas de double envoi** : l'addon refuse de programmer une lettre si une lettre du même mois est déjà en attente dans The Newsletter Plugin.
- **En cas d'erreur** (par exemple le site qui ne répond pas), rien n'est créé, vous recevez un mail, et l'addon réessaie le lendemain.
- **Préparation en retard** : si la date d'envoi est déjà passée, la lettre est créée en brouillon au lieu de partir.
- Le formulaire de réglages est réservé aux administrateur·rices et protégé contre les envois de formulaire frauduleux.

## Bon à savoir

- **Les tâches programmées de WordPress se déclenchent lors des visites du site.** Si personne ne visite le site un jour donné, la préparation est décalée à la visite suivante. The Newsletter Plugin fonctionne de la même façon pour ses envois.
- **Les dates des événements** sont lues dans l'export calendrier du thème du site (`/wp-json/labfab/v1/ics`). Le site s'appelle donc lui-même. Si l'hébergeur bloque ces appels, l'aperçu affiche une erreur ; **Outils → Santé du site** l'indique aussi.
- **L'addon dépend de The Newsletter Plugin.** Il s'appuie sur la base prévue par l'éditeur pour les extensions, mais ce n'est pas une extension officielle. Après une **mise à jour majeure** du plugin, faites un aperçu et un brouillon de test pour vérifier que tout fonctionne.
- **L'addon contacte un seul serveur extérieur** : GitHub, pour le logo, et seulement si vous gardez l'adresse par défaut. Il ne contacte pas les serveurs de l'éditeur du plugin.
- **Si vous désinstallez l'addon**, ses réglages et son historique sont effacés. Les lettres déjà créées restent dans The Newsletter Plugin.

## Organisation du code

| Fichier | Rôle |
|---|---|
| `newsletter-labfab/labfab.php` | Point d'entrée : démarre l'addon quand The Newsletter Plugin est chargé |
| `newsletter-labfab/includes/class-contenu.php` | Règles de sélection et mise en forme (équivalent de `newsletter/contenu.py`) |
| `newsletter-labfab/includes/class-source.php` | Lecture des articles, des événements et de leurs dates dans WordPress |
| `newsletter-labfab/includes/class-addon.php` | Tâche quotidienne, création et programmation de la lettre, test, notifications |
| `newsletter-labfab/admin/page.php` | Page Newsletter → LabFab automatique |
| `newsletter-labfab/templates/lettre.php` | Modèle de la lettre (traduit de `templates/template.html`) |
| `tests/` | Tests (voir ci-dessous) |

## Tests

```bash
php addon-wordpress/tests/comparer.php
```
Compare l'addon au programme Python sur 9 mois de vraies données du site (contenu choisi et lettre produite) : tout doit être « IDENTIQUE ».

```bash
php addon-wordpress/tests/simulation.php
```
Simule WordPress et The Newsletter Plugin pour vérifier tout le fonctionnement : tâche quotidienne, programmation, test, absence de doublon, annulation, erreurs, page d'administration.

Pour rafraîchir l'instantané des données du site (`tests/donnees-labfab.json`) :

```bash
python3 addon-wordpress/tests/exporter_donnees.py addon-wordpress/tests
```

**Si vous modifiez `templates/template.html` ou les règles du programme Python**, reportez le changement dans l'addon, puis relancez `comparer.php`.

## Refaire le ZIP

```bash
cd addon-wordpress && rm -f newsletter-labfab.zip && zip -r newsletter-labfab.zip newsletter-labfab
```

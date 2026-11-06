# Newsletter du LabFab

Génère la lettre mensuelle du [LabFab](https://labfab.fr), le réseau des Fab Labs rennais, à partir de ce qui est publié sur le site.

La lettre du mois **M** contient :

- **« Ce qui s'est passé en M-1 »** : les articles publiés sur labfab.fr le mois précédent, ou, s'il n'y en a pas eu, les événements qui ont eu lieu le mois précédent ;
- **« À venir en M »** : les événements du site qui ont lieu pendant le mois M.

Le programme produit un fichier HTML. On le relit, puis on l'envoie avec le plugin WordPress **The Newsletter Plugin**, qui gère déjà la liste des abonné·es.

## Installation

Il faut Python 3.10 ou plus récent.

```bash
python -m venv .venv
```

```bash
.venv/bin/pip install -r requirements.txt
```

(Sous Windows : `.venv\Scripts\pip install -r requirements.txt`.)

## Utilisation

```bash
python -m newsletter
```

Cette commande génère la lettre du mois en cours dans `sortie/newsletter-AAAA-MM.html` et affiche la liste des articles et des événements retenus.

Options :

| Option | Rôle |
|---|---|
| `--mois 2026-12` | Génère la lettre d'un autre mois |
| `--edito edito.txt` | Ajoute un édito, écrit en texte simple ; une ligne vide sépare deux paragraphes |
| `--logo https://…/logo.png` | Adresse du logo. Utilisez un PNG : Gmail et Outlook n'affichent pas le SVG |
| `--sortie dossier/` | Dossier où écrire la lettre |

## Envoyer la lettre

1. Ouvrez le fichier généré dans un navigateur et relisez-le.
2. Dans WordPress : **Newsletter → Newsletters → Créer**, puis choisissez l'éditeur HTML brut, sans glisser-déposer.
3. Collez le contenu complet du fichier HTML, puis donnez un objet au mail.
4. Envoyez-vous un **test** et vérifiez l'affichage sur téléphone et sur ordinateur.
5. Envoyez la lettre aux abonné·es.

Le modèle contient les balises `{unsubscription_url}` (désinscription) et `{email_url}` (« Voir dans le navigateur »). Le plugin les remplace lui-même au moment de l'envoi : il ne faut pas les modifier.

## Règles de sélection

- **Articles** : tous les articles (« posts ») publiés entre le 1er et le dernier jour du mois précédent. Le badge affiche leur première catégorie, sauf « Non classé ».
- **Pas d'article le mois précédent** : la rubrique « Ce qui s'est passé » présente à la place les événements qui ont eu lieu ce mois-là. Leur badge indique la date et le lieu.
- **Rien le mois précédent et rien à venir** : si aucun article ni événement n'a eu lieu le mois précédent **et** qu'aucun événement n'est prévu dans le mois de la lettre, aucune lettre n'est générée. Le programme l'indique et s'arrête. S'il n'y a que des événements à venir, la lettre est bien générée.
- **Événements** : la vraie date est lue dans l'export calendrier du site (`/wp-json/labfab/v1/ics?event_id=…`), pas dans la date de publication.
  - Un événement est retenu s'il a lieu, au moins en partie, pendant le mois de la lettre.
  - Si la lettre est générée en cours de mois, les événements déjà terminés sont écartés.
  - Les événements **sans date** (par exemple « Les ateliers de novembre ») apparaissent en tête, avec la mention « Tout le mois », s'ils ont été publiés le mois précédent.

## Organisation du code

| Fichier | Rôle |
|---|---|
| `templates/template.html` | Le modèle d'email (HTML compatible avec les logiciels de mail, avec Jinja2) |
| `newsletter/wordpress.py` | Lecture des données publiques du site (API REST de WordPress) |
| `newsletter/contenu.py` | Choix et mise en forme des articles et des événements |
| `newsletter/__main__.py` | Commande `python -m newsletter` |
| `tests/` | Tests, qui fonctionnent sans connexion : `python -m pytest` |

Le programme ne lit que des données publiques : il n'a besoin d'aucun identifiant WordPress.

# Exemples de newsletter

Lettres générées avec les vraies données de labfab.fr, prêtes à être testées dans The Newsletter Plugin.

| Fichier | Contenu |
|---|---|
| `newsletter-2026-11.html` | Lettre de novembre 2026 : « Ce qui s'est passé en octobre » (Atelier Mesh, Itinérance) et « À venir en novembre » (Les ateliers de novembre au LabFab IMT Atlantique) |

## S'envoyer la lettre en test

1. **Copier le code.** Sur GitHub, ouvrez `newsletter-2026-11.html`, cliquez sur le bouton **Raw**, puis copiez tout le texte (Ctrl+A puis Ctrl+C).
2. **Créer la newsletter.** Dans l'administration WordPress : **Newsletter → Newsletters → Créer une newsletter**. Choisissez l'éditeur **HTML** (code brut) plutôt que l'éditeur par glisser-déposer.
3. **Coller le code.** Remplacez tout le contenu de l'éditeur par le code copié.
4. **Mettre un objet**, par exemple : « La lettre du LabFab – novembre 2026 ».
5. **S'envoyer un test.** Utilisez le bouton **Test** de l'éditeur et indiquez votre propre adresse email. **N'utilisez pas « Envoyer »** : la lettre partirait à toute la liste.

## Ce qu'il faut vérifier dans le mail reçu

- L'affichage sur ordinateur **et** sur téléphone.
- Les liens « En savoir plus » et « Voir tout l'agenda du réseau ».
- Le lien **Se désinscrire** : le plugin le remplace par un vrai lien à l'envoi. S'il reste écrit `{unsubscription_url}`, c'est que le code a été collé dans un éditeur qui ne gère pas ces balises.
- **Le logo** : il doit s'afficher en haut de la lettre. Dans Gmail, il faut parfois cliquer sur « Afficher les images ».

## Générer d'autres exemples

```bash
python -m newsletter --mois 2026-12 --sortie exemples
```

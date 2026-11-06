"""Génère la newsletter mensuelle du LabFab.

Exemples :
    python -m newsletter                      # lettre du mois en cours
    python -m newsletter --mois 2026-12       # lettre de décembre 2026
    python -m newsletter --edito edito.txt    # avec un édito
"""

from __future__ import annotations

import argparse
import sys
from datetime import date
from pathlib import Path

from jinja2 import Environment, FileSystemLoader, select_autoescape
from markupsafe import Markup, escape

from . import contenu
from .wordpress import SITE, ClientWordPress

RACINE = Path(__file__).resolve().parent.parent
LOGO_PAR_DEFAUT = f"{SITE}/wp-content/themes/labfab-theme-test2/img/logo/logo-long.svg"


def edito_en_html(texte: str) -> Markup:
    """Texte brut → paragraphes séparés par une ligne vide, retours à la ligne conservés."""
    paragraphes = [p.strip() for p in texte.strip().split("\n\n") if p.strip()]
    return Markup("<br><br>").join(Markup("<br>").join(escape(l) for l in p.splitlines())
                                   for p in paragraphes)


def construire_contexte(client: ClientWordPress, mois: date, aujourdhui: date,
                        edito: str = "", logo_url: str = LOGO_PAR_DEFAUT) -> dict:
    p = contenu.periode(mois)
    articles = contenu.preparer_articles(client.articles(p.debut_passe, p.fin_passe))
    bruts = client.evenements()
    calendriers = {e["id"]: client.calendrier(e["id"]) for e in bruts}
    evenements = contenu.preparer_evenements(bruts, calendriers, p, aujourdhui)
    nom_mois = contenu.MOIS[p.mois.month - 1]
    return {
        "mois": nom_mois,
        "annee": p.mois.year,
        "mois_precedent": contenu.MOIS[p.debut_passe.month - 1],
        "mois_suivant": nom_mois,
        "edito": edito_en_html(edito) if edito.strip() else "",
        "preheader": contenu.apercu_boite_de_reception(len(articles), len(evenements), nom_mois),
        "logo_url": logo_url,
        "articles": articles,
        "evenements": evenements,
    }


def rendre(contexte: dict, modele: Path = RACINE / "templates" / "template.html") -> str:
    env = Environment(loader=FileSystemLoader(modele.parent),
                      autoescape=select_autoescape(["html"]))
    return env.get_template(modele.name).render(**contexte)


def lire_mois(valeur: str) -> date:
    try:
        return date.fromisoformat(f"{valeur}-01")
    except ValueError:
        raise argparse.ArgumentTypeError("format attendu : AAAA-MM, par ex. 2026-12")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="python -m newsletter", description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--mois", type=lire_mois, default=date.today().replace(day=1),
                        help="mois de la lettre (AAAA-MM) ; par défaut le mois en cours")
    parser.add_argument("--edito", type=Path, help="fichier texte contenant l'édito")
    parser.add_argument("--logo", default=LOGO_PAR_DEFAUT, help="adresse du logo (PNG conseillé)")
    parser.add_argument("--sortie", type=Path, default=RACINE / "sortie",
                        help="dossier où écrire la lettre (par défaut : sortie/)")
    args = parser.parse_args(argv)

    edito = args.edito.read_text(encoding="utf-8") if args.edito else ""
    contexte = construire_contexte(ClientWordPress(), args.mois, date.today(), edito, args.logo)

    args.sortie.mkdir(parents=True, exist_ok=True)
    fichier = args.sortie / f"newsletter-{args.mois:%Y-%m}.html"
    fichier.write_text(rendre(contexte), encoding="utf-8")

    print(f"Lettre de {contexte['mois']} {contexte['annee']} : {fichier}")
    print(f"\nArticles publiés en {contexte['mois_precedent']} : {len(contexte['articles'])}")
    for a in contexte["articles"]:
        print(f"  - {a['titre']}")
    print(f"\nÉvénements en {contexte['mois_suivant']} : {len(contexte['evenements'])}")
    for e in contexte["evenements"]:
        print(f"  - {e['date_affichee']} : {e['titre']}")
    if args.logo.lower().endswith(".svg"):
        print("\nAttention : le logo est un SVG, que Gmail et Outlook n'affichent pas. "
              "Utilisez --logo avec l'adresse d'une version PNG.", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

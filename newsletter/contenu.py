"""Transforme les données brutes du site en contenu prêt pour le modèle d'email."""

from __future__ import annotations

import html
import re
from dataclasses import dataclass
from datetime import date, datetime, timedelta

from icalendar import Calendar

MOIS = ["janvier", "février", "mars", "avril", "mai", "juin", "juillet",
        "août", "septembre", "octobre", "novembre", "décembre"]
MOIS_COURTS = ["janv.", "févr.", "mars", "avr.", "mai", "juin", "juil.",
               "août", "sept.", "oct.", "nov.", "déc."]
JOURS_COURTS = ["lun.", "mar.", "mer.", "jeu.", "ven.", "sam.", "dim."]

# Catégories trop génériques pour être affichées en badge.
CATEGORIES_IGNOREES = {"Non classé"}
# Valeurs de lieu que le site renvoie quand rien n'est renseigné.
LIEUX_VIDES = {"", "Lieu non précisé"}

LONGUEUR_EXTRAIT = 220
TEXTE_SANS_DATE = "Tout le mois"


@dataclass
class Periode:
    """Une lettre du mois M parle des articles de M-1 et des événements de M."""
    mois: date            # 1er jour du mois de la lettre
    debut_passe: date     # 1er jour du mois précédent
    fin_passe: date       # dernier jour du mois précédent
    fin_mois: date        # dernier jour du mois de la lettre


def periode(mois: date) -> Periode:
    premier = mois.replace(day=1)
    fin_passe = premier - timedelta(days=1)
    suivant = (premier + timedelta(days=32)).replace(day=1)
    return Periode(premier, fin_passe.replace(day=1), fin_passe, suivant - timedelta(days=1))


def texte_brut(fragment_html: str) -> str:
    """Retire les balises et les entités HTML, et normalise les espaces."""
    sans_balises = re.sub(r"<[^>]+>", " ", fragment_html or "")
    return re.sub(r"\s+", " ", html.unescape(sans_balises)).strip()


def resumer(texte: str, longueur: int = LONGUEUR_EXTRAIT) -> str:
    """Coupe un texte à la fin d'un mot, sans dépasser `longueur` caractères."""
    texte = re.sub(r"\s*\[(…|&hellip;|\.\.\.)\]\s*$", "", texte)
    if len(texte) <= longueur:
        return texte
    coupe = texte[:longueur].rsplit(" ", 1)[0].rstrip(" ,;:.-–")
    return coupe + "…"


def image_a_la_une(contenu: dict) -> str | None:
    medias = contenu.get("_embedded", {}).get("wp:featuredmedia") or []
    if not medias or "source_url" not in medias[0]:
        return None
    tailles = medias[0].get("media_details", {}).get("sizes", {})
    for taille in ("large", "medium_large", "full"):
        if taille in tailles:
            return tailles[taille]["source_url"]
    return medias[0]["source_url"]


def categorie_principale(article: dict) -> str | None:
    for groupe in article.get("_embedded", {}).get("wp:term") or []:
        for terme in groupe:
            if terme.get("taxonomy") == "category" and terme.get("name") not in CATEGORIES_IGNOREES:
                return html.unescape(terme["name"])
    return None


def lire_calendrier(ics: str) -> tuple[datetime, datetime, str | None]:
    """Renvoie (début, fin, lieu) du premier événement d'un fichier .ics."""
    evenement = Calendar.from_ical(ics).walk("VEVENT")[0]
    debut = evenement.decoded("DTSTART")
    fin = evenement.decoded("DTEND") if "DTEND" in evenement else debut
    if not isinstance(debut, datetime):  # événement « journée entière »
        debut = datetime.combine(debut, datetime.min.time())
        fin = datetime.combine(fin, datetime.min.time())
    lieu = str(evenement.get("LOCATION", "")).strip()
    return debut, fin, (None if lieu in LIEUX_VIDES else lieu)


def date_courte(jour: date) -> str:
    """Ex. « mer. 9 déc. »"""
    return f"{JOURS_COURTS[jour.weekday()]} {jour.day} {MOIS_COURTS[jour.month - 1]}"


def heure(moment: datetime) -> str:
    """Ex. « 10h », « 12h30 »"""
    return f"{moment.hour}h{moment.minute:02d}" if moment.minute else f"{moment.hour}h"


def affichage_dates(debut: datetime, fin: datetime) -> tuple[str, str | None]:
    """Texte de la pastille date et des horaires."""
    if fin.date() > debut.date():
        return f"{date_courte(debut.date())} → {date_courte(fin.date())}", None
    if debut.hour == 0 and debut.minute == 0 and fin == debut:
        return date_courte(debut.date()), None
    if fin > debut:
        return date_courte(debut.date()), f"{heure(debut)} – {heure(fin)}"
    return date_courte(debut.date()), heure(debut)


def preparer_articles(articles: list[dict]) -> list[dict]:
    return [
        {
            "titre": texte_brut(a["title"]["rendered"]),
            "extrait": resumer(texte_brut(a["excerpt"]["rendered"])),
            "url": a["link"],
            "image_url": image_a_la_une(a),
            "categorie": categorie_principale(a),
        }
        for a in articles
    ]


def preparer_evenements(evenements: list[dict], calendriers: dict[int, str | None],
                        p: Periode, aujourdhui: date) -> list[dict]:
    """Garde les événements qui ont lieu pendant le mois de la lettre et ne sont pas terminés.

    Si la lettre est générée en cours de mois, les événements déjà terminés sont écartés.
    Les événements sans date (ex. « Les ateliers de novembre ») sont gardés, en tête de liste
    avec la mention « Tout le mois », s'ils ont été publiés pendant le mois précédent.
    """
    seuil = aujourdhui if p.mois <= aujourdhui <= p.fin_mois else p.mois
    dates, sans_date = [], []
    for e in evenements:
        fiche = {
            "titre": texte_brut(e["title"]["rendered"]),
            "url": e["link"],
            "image_url": image_a_la_une(e),
        }
        ics = calendriers.get(e["id"])
        if ics is None:
            publie = date.fromisoformat(e["date"][:10])
            if p.debut_passe <= publie <= p.fin_passe:
                sans_date.append({**fiche, "date_affichee": TEXTE_SANS_DATE,
                                  "horaires": None, "lieu": None})
            continue
        debut, fin, lieu = lire_calendrier(ics)
        if debut.date() > p.fin_mois or fin.date() < seuil:
            continue
        date_affichee, horaires = affichage_dates(debut, fin)
        dates.append((debut, {**fiche, "date_affichee": date_affichee,
                              "horaires": horaires, "lieu": lieu}))
    dates.sort(key=lambda paire: paire[0])
    return sans_date + [fiche for _, fiche in dates]


def preparer_evenements_passes(evenements: list[dict], calendriers: dict[int, str | None],
                               p: Periode) -> list[dict]:
    """Événements qui ont eu lieu pendant le mois précédent, présentés comme des articles.

    Sert de rubrique « Ce qui s'est passé » quand aucun article n'a été publié ce mois-là.
    """
    passes = []
    for e in evenements:
        ics = calendriers.get(e["id"])
        if ics is None:
            continue
        debut, fin, lieu = lire_calendrier(ics)
        if debut.date() > p.fin_passe or fin.date() < p.debut_passe:
            continue
        date_affichee, _ = affichage_dates(debut, fin)
        passes.append((debut, {
            "titre": texte_brut(e["title"]["rendered"]),
            "extrait": resumer(texte_brut(e.get("excerpt", {}).get("rendered", ""))),
            "url": e["link"],
            "image_url": image_a_la_une(e),
            "categorie": f"Événement · {date_affichee}" + (f" · {lieu}" if lieu else ""),
        }))
    passes.sort(key=lambda paire: paire[0])
    return [fiche for _, fiche in passes]


def apercu_boite_de_reception(nb_articles: int, nb_evenements: int, mois: str) -> str:
    def pluriel(n: int, mot: str) -> str:
        return f"{n} {mot}{'s' if n > 1 and not mot.endswith('s') else ''}"
    morceaux = []
    if nb_articles:
        morceaux.append(f"{pluriel(nb_articles, 'actualité')} du réseau")
    if nb_evenements:
        morceaux.append(f"{pluriel(nb_evenements, 'rendez-vous')} en {mois}")
    if not morceaux:
        return f"Les nouvelles du réseau des Fab Labs rennais – {mois}"
    return "Au programme : " + " et ".join(morceaux) + "."

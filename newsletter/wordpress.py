"""Lecture des données publiques du site labfab.fr via l'API REST de WordPress.

Aucun identifiant n'est nécessaire : on ne lit que ce que le site publie déjà.
"""

from __future__ import annotations

from datetime import date

import requests

SITE = "https://labfab.fr"


class ClientWordPress:
    def __init__(self, site: str = SITE, session: requests.Session | None = None, delai: int = 20):
        self.site = site.rstrip("/")
        self.session = session or requests.Session()
        # Le pare-feu du site refuse l'identifiant par défaut « python-requests ».
        self.session.headers["User-Agent"] = "labfab-newsletter/1.0 (+https://labfab.fr)"
        self.delai = delai

    def _liste(self, chemin: str, **params) -> list[dict]:
        """Récupère toutes les pages d'une liste WordPress (100 éléments par page)."""
        resultats: list[dict] = []
        page = 1
        while True:
            reponse = self.session.get(
                f"{self.site}/wp-json/{chemin}",
                params={**params, "per_page": 100, "page": page},
                timeout=self.delai,
            )
            reponse.raise_for_status()
            resultats.extend(reponse.json())
            if page >= int(reponse.headers.get("X-WP-TotalPages", 1)):
                return resultats
            page += 1

    def articles(self, debut: date, fin: date) -> list[dict]:
        """Articles publiés entre debut et fin (inclus), du plus ancien au plus récent."""
        return self._liste(
            "wp/v2/posts",
            after=f"{debut.isoformat()}T00:00:00",
            before=f"{fin.isoformat()}T23:59:59",
            orderby="date",
            order="asc",
            _embed="wp:featuredmedia,wp:term",
        )

    def evenements(self) -> list[dict]:
        """Tous les contenus du type « Événements » (sans leurs dates, voir calendrier())."""
        return self._liste("wp/v2/evenement", _embed="wp:featuredmedia")

    def calendrier(self, evenement_id: int) -> str | None:
        """Fichier .ics d'un événement (dates, horaires, lieu), ou None s'il n'a pas de date."""
        reponse = self.session.get(
            f"{self.site}/wp-json/labfab/v1/ics",
            params={"event_id": evenement_id},
            timeout=self.delai,
        )
        if reponse.status_code == 404:
            return None
        reponse.raise_for_status()
        return reponse.text

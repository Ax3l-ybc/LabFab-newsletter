"""Exporte les vraies données de labfab.fr et le contenu calculé par le programme Python."""
import json, sys
from datetime import date
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from newsletter import contenu
from newsletter.wordpress import ClientWordPress
from newsletter.__main__ import construire_contexte, rendre

sortie = Path(sys.argv[1])
client = ClientWordPress()
bruts = client.evenements()
calendriers = {e["id"]: client.calendrier(e["id"]) for e in bruts}
evenements = [{
    "titre": contenu.texte_brut(e["title"]["rendered"]),
    "extrait": contenu.resumer(contenu.texte_brut(e.get("excerpt", {}).get("rendered", ""))),
    "url": e["link"], "image_url": contenu.image_a_la_une(e),
    "publie": e["date"][:10], "ics": calendriers[e["id"]],
} for e in bruts]

class Cache:
    def articles(self, d, f): return client.articles(d, f)
    def evenements(self): return bruts
    def calendrier(self, i): return calendriers[i]

cas = []
for mois, aujourdhui in [("2026-06", "2026-05-25"), ("2026-07", "2026-06-25"), ("2026-09", "2026-08-25"),
                         ("2026-10", "2026-09-25"), ("2026-11", "2026-10-25"), ("2026-11", "2026-11-06"),
                         ("2026-12", "2026-11-25"), ("2027-01", "2026-12-25"), ("2027-02", "2027-01-25")]:
    y, m = map(int, mois.split("-"))
    p = contenu.periode(date(y, m, 1))
    articles = contenu.preparer_articles(client.articles(p.debut_passe, p.fin_passe))
    ctx = construire_contexte(Cache(), date(y, m, 1), date.fromisoformat(aujourdhui),
                              logo_url="https://exemple.org/logo.png")
    cas.append({"mois": mois, "aujourdhui": aujourdhui, "articles_entree": articles,
                "attendu": {k: ctx[k] for k in ("mois", "annee", "mois_precedent", "mois_suivant", "preheader",
                                               "articles", "evenements", "source_passe", "a_envoyer")},
                "html": rendre(ctx)})
(sortie / "donnees-labfab.json").write_text(json.dumps({"evenements": evenements, "cas": cas}, ensure_ascii=False, indent=1))
print(len(evenements), "événements,", len(cas), "cas")

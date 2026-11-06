"""Tests hors ligne : aucun appel au site labfab.fr."""

from datetime import date

from newsletter import contenu
from newsletter.__main__ import construire_contexte, edito_en_html, rendre


def ics(debut: str, fin: str, lieu: str = "Edulab Rennes 2") -> str:
    return (
        "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Labfab//Calendrier//FR\r\n"
        "BEGIN:VEVENT\r\nUID:test@labfab.fr\r\nDTSTAMP:20261106T140315Z\r\n"
        f"DTSTART;TZID=Europe/Paris:{debut}\r\nDTEND;TZID=Europe/Paris:{fin}\r\n"
        f"SUMMARY:Test\r\nLOCATION:{lieu}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
    )


def evenement(id_, titre, publie="2026-10-01T10:00:00"):
    return {"id": id_, "date": publie, "link": f"https://labfab.fr/evenements/{id_}/",
            "title": {"rendered": titre}, "excerpt": {"rendered": ""}}


ARTICLE = {
    "date": "2026-10-12T10:00:00",
    "link": "https://labfab.fr/velomix-2026/",
    "title": {"rendered": "Ombre &#8211; Atelier Cr&eacute;atif"},
    "excerpt": {"rendered": "<p>Un atelier <strong>créatif</strong> pour tous. [&hellip;]</p>"},
    "_embedded": {
        "wp:featuredmedia": [{"source_url": "https://labfab.fr/full.jpg",
                              "media_details": {"sizes": {"large": {"source_url": "https://labfab.fr/large.jpg"}}}}],
        "wp:term": [[{"taxonomy": "category", "name": "Non classé"},
                     {"taxonomy": "category", "name": "Actualité"}]],
    },
}


class FauxClient:
    def __init__(self, articles, evenements, calendriers):
        self._articles, self._evenements, self._calendriers = articles, evenements, calendriers

    def articles(self, debut, fin):
        self.periode_demandee = (debut, fin)
        return self._articles

    def evenements(self):
        return self._evenements

    def calendrier(self, id_):
        return self._calendriers.get(id_)


def test_periode_couvre_le_mois_precedent_et_le_mois_de_la_lettre():
    p = contenu.periode(date(2026, 1, 15))
    assert (p.mois, p.debut_passe, p.fin_passe, p.fin_mois) == (
        date(2026, 1, 1), date(2025, 12, 1), date(2025, 12, 31), date(2026, 1, 31))


def test_formats_de_date_en_francais():
    debut, fin, lieu = contenu.lire_calendrier(ics("20261209T100000", "20261209T123000"))
    assert contenu.affichage_dates(debut, fin) == ("mer. 9 déc.", "10h – 12h30")
    assert lieu == "Edulab Rennes 2"
    debut, fin, _ = contenu.lire_calendrier(ics("20261209T100000", "20261211T180000"))
    assert contenu.affichage_dates(debut, fin) == ("mer. 9 déc. → ven. 11 déc.", None)


def test_lieu_non_precise_est_masque():
    assert contenu.lire_calendrier(ics("20261209T100000", "20261209T120000", "Lieu non précisé"))[2] is None


def test_article_nettoye():
    (a,) = contenu.preparer_articles([ARTICLE])
    assert a["titre"] == "Ombre – Atelier Créatif"
    assert a["extrait"] == "Un atelier créatif pour tous."
    assert a["categorie"] == "Actualité"
    assert a["image_url"] == "https://labfab.fr/large.jpg"


def test_extrait_coupe_proprement():
    texte = "mot " * 100
    resume = contenu.resumer(texte.strip(), 50)
    assert len(resume) <= 51 and resume.endswith("mot…")


def test_selection_des_evenements():
    p = contenu.periode(date(2026, 11, 1))
    evenements = [
        evenement(1, "Plus tard"), evenement(2, "Avant"), evenement(3, "Déjà passé"),
        evenement(4, "Bientôt"), evenement(5, "Sans date, récent", "2026-10-20T11:00:00"),
        evenement(6, "Sans date, ancien", "2026-06-01T11:00:00"),
        evenement(7, "Hors mois"),
    ]
    calendriers = {
        1: ics("20261125T100000", "20261125T120000"),
        2: ics("20261028T100000", "20261102T120000"),   # commence en octobre, finit en novembre
        3: ics("20261103T100000", "20261103T120000"),   # terminé avant la génération (6 nov.)
        4: ics("20261110T140000", "20261110T170000"),
        7: ics("20261209T100000", "20261209T120000"),
    }
    titres = [e["titre"] for e in contenu.preparer_evenements(evenements, calendriers, p, date(2026, 11, 6))]
    assert titres == ["Sans date, récent", "Bientôt", "Plus tard"]
    # Générée avant le début du mois, la lettre garde aussi les événements du début de mois.
    titres = [e["titre"] for e in contenu.preparer_evenements(evenements, calendriers, p, date(2026, 10, 30))]
    assert titres == ["Sans date, récent", "Avant", "Déjà passé", "Bientôt", "Plus tard"]


def test_rendu_complet():
    client = FauxClient([ARTICLE], [evenement(1, "Atelier Presse DIY")],
                        {1: ics("20261109T100000", "20261109T123000")})
    contexte = construire_contexte(client, date(2026, 11, 1), date(2026, 11, 1),
                                   edito="Bonjour <à toutes>\n\nDeuxième paragraphe")
    assert client.periode_demandee == (date(2026, 10, 1), date(2026, 10, 31))
    html = rendre(contexte)
    assert "La lettre du LabFab – novembre 2026" in html
    assert "Ce qui s'est passé en octobre" in html and "À venir en novembre" in html
    assert "lun. 9 nov." in html and "Atelier Presse DIY" in html
    assert "Bonjour &lt;à toutes&gt;<br><br>Deuxième paragraphe" in html
    # Balises du plugin Newsletter conservées pour qu'il les remplace à l'envoi.
    assert "{unsubscription_url}" in html and "{email_url}" in html
    assert "Au programme : 1 actualité du réseau et 1 rendez-vous en novembre." in html
    assert "{{" not in html and "{%" not in html


def test_listes_vides():
    html = rendre(construire_contexte(FauxClient([], [], {}), date(2026, 11, 1), date(2026, 11, 1)))
    assert "Pas de nouvel article ce mois-ci" in html
    assert "Pas d'événement programmé" in html


def test_edito_retours_a_la_ligne():
    assert edito_en_html("Une ligne\nUne autre") == "Une ligne<br>Une autre"


def test_sans_article_on_parle_des_evenements_du_mois_precedent():
    evenements = [evenement(1, "Itinérance"), evenement(2, "Atelier Presse DIY"),
                  evenement(3, "Trop ancien")]
    calendriers = {
        1: ics("20261019T100000", "20261019T180000", "IMT Atlantique"),
        2: ics("20261209T100000", "20261209T123000"),
        3: ics("20260914T100000", "20260914T120000"),
    }
    contexte = construire_contexte(FauxClient([], evenements, calendriers),
                                   date(2026, 11, 1), date(2026, 11, 1))
    assert contexte["a_envoyer"] and contexte["source_passe"] == "evenements"
    (passe,) = contexte["articles"]
    assert passe["titre"] == "Itinérance"
    assert passe["categorie"] == "Événement · lun. 19 oct. · IMT Atlantique"


def test_rien_le_mois_precedent_pas_de_lettre(tmp_path, monkeypatch, capsys):
    from newsletter import __main__ as cli
    monkeypatch.setattr(cli, "ClientWordPress", lambda: FauxClient([], [], {}))
    assert cli.main(["--mois", "2026-11", "--sortie", str(tmp_path)]) == 0
    assert list(tmp_path.iterdir()) == []
    assert "Pas de lettre pour novembre 2026" in capsys.readouterr().out

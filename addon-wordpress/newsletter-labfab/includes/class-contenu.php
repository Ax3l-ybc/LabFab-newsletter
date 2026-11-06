<?php
/**
 * Choix et mise en forme du contenu de la lettre.
 *
 * Cette classe n'appelle pas WordPress : elle reçoit des tableaux simples et applique
 * les mêmes règles que le programme Python du dépôt (newsletter/contenu.py).
 */

defined('ABSPATH') || exit;

class LabFab_NL_Contenu {

    const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
        'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    const MOIS_COURTS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.',
        'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    const JOURS_COURTS = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
    const LIEUX_VIDES = ['', 'Lieu non précisé'];
    const LONGUEUR_EXTRAIT = 220;
    const TEXTE_SANS_DATE = 'Tout le mois';

    /**
     * Une lettre du mois M parle de ce qui s'est passé en M-1 et des événements de M.
     *
     * @param string $mois Mois de la lettre, au format AAAA-MM.
     * @return array{mois:string, debut_passe:string, fin_passe:string, fin_mois:string} Dates AAAA-MM-JJ.
     */
    static function periode($mois) {
        $premier = new DateTimeImmutable($mois . '-01', new DateTimeZone('UTC'));
        return [
            'mois' => $premier->format('Y-m-d'),
            'debut_passe' => $premier->modify('first day of previous month')->format('Y-m-d'),
            'fin_passe' => $premier->modify('-1 day')->format('Y-m-d'),
            'fin_mois' => $premier->modify('last day of this month')->format('Y-m-d'),
        ];
    }

    static function nom_mois($date) {
        return self::MOIS[(int) substr($date, 5, 2) - 1];
    }

    /** Retire les balises et les entités HTML, et normalise les espaces. */
    static function texte_brut($html) {
        $texte = preg_replace('/<[^>]+>/', ' ', (string) $html);
        $texte = html_entity_decode($texte, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $texte));
    }

    /** Coupe un texte à la fin d'un mot, sans dépasser $longueur caractères. */
    static function resumer($texte, $longueur = self::LONGUEUR_EXTRAIT) {
        $texte = preg_replace('/\s*\[(…|&hellip;|\.\.\.)\]\s*$/u', '', $texte);
        if (mb_strlen($texte, 'UTF-8') <= $longueur) {
            return $texte;
        }
        $coupe = mb_substr($texte, 0, $longueur, 'UTF-8');
        $espace = mb_strrpos($coupe, ' ', 0, 'UTF-8');
        if ($espace !== false) {
            $coupe = mb_substr($coupe, 0, $espace, 'UTF-8');
        }
        return preg_replace('/[ ,;:.\-–]+$/u', '', $coupe) . '…';
    }

    /**
     * Lit le premier événement d'un fichier .ics.
     *
     * @return array{debut:DateTimeImmutable, fin:DateTimeImmutable, journee:bool, lieu:?string}|null
     */
    static function lire_ics($ics, DateTimeZone $fuseau) {
        $ics = preg_replace("/\r?\n[ \t]/", '', (string) $ics); // lignes repliées
        if (!preg_match('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $bloc)) {
            return null;
        }
        $proprietes = [];
        foreach (preg_split("/\r?\n/", $bloc[1]) as $ligne) {
            if (preg_match('/^([A-Z-]+)((?:;[^:]*)?):(.*)$/', $ligne, $p) && !isset($proprietes[$p[1]])) {
                $proprietes[$p[1]] = [$p[2], $p[3]];
            }
        }
        if (empty($proprietes['DTSTART'])) {
            return null;
        }
        $debut = self::date_ics($proprietes['DTSTART'][0], $proprietes['DTSTART'][1], $fuseau);
        if (!$debut) {
            return null;
        }
        $journee = strlen(trim($proprietes['DTSTART'][1])) === 8;
        $fin = isset($proprietes['DTEND'])
            ? self::date_ics($proprietes['DTEND'][0], $proprietes['DTEND'][1], $fuseau)
            : null;
        if (!$fin) {
            $fin = $debut;
        } elseif ($journee && $fin > $debut) {
            $fin = $fin->modify('-1 day'); // pour une journée entière, DTEND est le lendemain
        }
        $lieu = isset($proprietes['LOCATION']) ? trim(str_replace(
            ['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $proprietes['LOCATION'][1])) : '';
        return [
            'debut' => $debut,
            'fin' => $fin,
            'journee' => $journee,
            'lieu' => in_array($lieu, self::LIEUX_VIDES, true) ? null : $lieu,
        ];
    }

    private static function date_ics($parametres, $valeur, DateTimeZone $fuseau) {
        $valeur = trim($valeur);
        if (strlen($valeur) === 8) {
            $date = DateTimeImmutable::createFromFormat('!Ymd', $valeur, $fuseau);
            return $date ?: null;
        }
        if (substr($valeur, -1) === 'Z') {
            $date = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $valeur, new DateTimeZone('UTC'));
            return $date ? $date->setTimezone($fuseau) : null;
        }
        $zone = $fuseau;
        if (preg_match('/TZID=([^;:]+)/', $parametres, $m)) {
            try {
                $zone = new DateTimeZone(trim($m[1], '"'));
            } catch (Exception $e) {
                $zone = $fuseau;
            }
        }
        $date = DateTimeImmutable::createFromFormat('Ymd\THis', $valeur, $zone);
        return $date ? $date->setTimezone($fuseau) : null;
    }

    /** Ex. « mer. 9 déc. » */
    static function date_courte(DateTimeInterface $jour) {
        return self::JOURS_COURTS[(int) $jour->format('N') - 1] . ' ' . $jour->format('j') . ' '
            . self::MOIS_COURTS[(int) $jour->format('n') - 1];
    }

    /** Ex. « 10h », « 12h30 » */
    static function heure(DateTimeInterface $moment) {
        $minutes = (int) $moment->format('i');
        return $moment->format('G') . 'h' . ($minutes ? sprintf('%02d', $minutes) : '');
    }

    /** @return array{0:string, 1:?string} Texte de la pastille date, et horaires. */
    static function affichage_dates(array $dates) {
        $debut = $dates['debut'];
        $fin = $dates['fin'];
        if ($fin->format('Y-m-d') > $debut->format('Y-m-d')) {
            return [self::date_courte($debut) . ' → ' . self::date_courte($fin), null];
        }
        if ($dates['journee']) {
            return [self::date_courte($debut), null];
        }
        if ($fin > $debut) {
            return [self::date_courte($debut), self::heure($debut) . ' – ' . self::heure($fin)];
        }
        return [self::date_courte($debut), self::heure($debut)];
    }

    /**
     * Événements à annoncer pour le mois de la lettre.
     *
     * Gardés : ceux qui ont lieu, au moins en partie, pendant le mois. Si la lettre est générée
     * en cours de mois, ceux déjà terminés sont écartés. Les événements sans date publiés le mois
     * précédent sont placés en tête avec la mention « Tout le mois ».
     *
     * @param array $evenements Éléments {titre, extrait, url, image_url, publie (AAAA-MM-JJ), ics}
     */
    static function evenements_a_venir(array $evenements, array $p, $aujourdhui, DateTimeZone $fuseau) {
        $seuil = ($aujourdhui >= $p['mois'] && $aujourdhui <= $p['fin_mois']) ? $aujourdhui : $p['mois'];
        $sans_date = [];
        $dates = [];
        foreach ($evenements as $e) {
            $fiche = ['titre' => $e['titre'], 'url' => $e['url'], 'image_url' => $e['image_url']];
            $lu = $e['ics'] ? self::lire_ics($e['ics'], $fuseau) : null;
            if (!$lu) {
                if ($e['publie'] >= $p['debut_passe'] && $e['publie'] <= $p['fin_passe']) {
                    $sans_date[] = $fiche + ['date_affichee' => self::TEXTE_SANS_DATE, 'horaires' => null, 'lieu' => null];
                }
                continue;
            }
            if ($lu['debut']->format('Y-m-d') > $p['fin_mois'] || $lu['fin']->format('Y-m-d') < $seuil) {
                continue;
            }
            list($date_affichee, $horaires) = self::affichage_dates($lu);
            $dates[] = [$lu['debut']->getTimestamp(), $fiche + [
                'date_affichee' => $date_affichee, 'horaires' => $horaires, 'lieu' => $lu['lieu']]];
        }
        usort($dates, function ($a, $b) {
            return $a[0] <=> $b[0];
        });
        return array_merge($sans_date, array_column($dates, 1));
    }

    /** Événements qui ont eu lieu le mois précédent, présentés comme des articles. */
    static function evenements_passes(array $evenements, array $p, DateTimeZone $fuseau) {
        $passes = [];
        foreach ($evenements as $e) {
            $lu = $e['ics'] ? self::lire_ics($e['ics'], $fuseau) : null;
            if (!$lu || $lu['debut']->format('Y-m-d') > $p['fin_passe'] || $lu['fin']->format('Y-m-d') < $p['debut_passe']) {
                continue;
            }
            list($date_affichee) = self::affichage_dates($lu);
            $passes[] = [$lu['debut']->getTimestamp(), [
                'titre' => $e['titre'],
                'extrait' => $e['extrait'],
                'url' => $e['url'],
                'image_url' => $e['image_url'],
                'categorie' => 'Événement · ' . $date_affichee . ($lu['lieu'] ? ' · ' . $lu['lieu'] : ''),
            ]];
        }
        usort($passes, function ($a, $b) {
            return $a[0] <=> $b[0];
        });
        return array_column($passes, 1);
    }

    static function apercu_boite_de_reception($nb_articles, $nb_evenements, $mois) {
        $pluriel = function ($n, $mot) {
            return $n . ' ' . $mot . ($n > 1 && substr($mot, -1) !== 's' ? 's' : '');
        };
        $morceaux = [];
        if ($nb_articles) {
            $morceaux[] = $pluriel($nb_articles, 'actualité') . ' du réseau';
        }
        if ($nb_evenements) {
            $morceaux[] = $pluriel($nb_evenements, 'rendez-vous') . ' en ' . $mois;
        }
        if (!$morceaux) {
            return 'Les nouvelles du réseau des Fab Labs rennais – ' . $mois;
        }
        return 'Au programme : ' . implode(' et ', $morceaux) . '.';
    }

    /**
     * Assemble tout le contenu de la lettre du mois $mois.
     *
     * @param array  $articles   Articles du mois précédent {titre, extrait, url, image_url, categorie}
     * @param array  $evenements Tous les événements {titre, extrait, url, image_url, publie, ics}
     * @param string $mois       AAAA-MM
     * @param string $aujourdhui AAAA-MM-JJ
     */
    static function construire(array $articles, array $evenements, $mois, $aujourdhui, DateTimeZone $fuseau) {
        $p = self::periode($mois);
        $a_venir = self::evenements_a_venir($evenements, $p, $aujourdhui, $fuseau);
        $source_passe = 'articles';
        if (!$articles) {
            // Sans article publié le mois précédent, on raconte les événements qui ont eu lieu.
            $articles = self::evenements_passes($evenements, $p, $fuseau);
            $source_passe = 'evenements';
        }
        $nom_mois = self::nom_mois($p['mois']);
        return [
            'mois' => $nom_mois,
            'annee' => (int) substr($p['mois'], 0, 4),
            'mois_precedent' => self::nom_mois($p['debut_passe']),
            'mois_suivant' => $nom_mois,
            'preheader' => self::apercu_boite_de_reception(count($articles), count($a_venir), $nom_mois),
            'articles' => $articles,
            'evenements' => $a_venir,
            'source_passe' => $source_passe,
            // Rien le mois précédent ET rien d'annoncé ce mois-ci → pas de lettre.
            'a_envoyer' => (bool) ($articles || $a_venir),
        ];
    }

    /** Texte brut → paragraphes séparés par une ligne vide, retours à la ligne conservés. */
    static function edito_en_html($texte) {
        $paragraphes = array_filter(array_map('trim', preg_split("/\r?\n\s*\r?\n/", trim((string) $texte))));
        $html = [];
        foreach ($paragraphes as $paragraphe) {
            $lignes = array_map(function ($l) {
                return htmlspecialchars(trim($l), ENT_QUOTES, 'UTF-8');
            }, preg_split("/\r?\n/", $paragraphe));
            $html[] = implode('<br>', $lignes);
        }
        return implode('<br><br>', $html);
    }
}

# Scientias YouTube Carrousel

WordPress-plugin voor responsieve YouTube-carrousels met automatische Shorts-feeds, extra playlistcarrousels, handmatige fallbackvideo's, link overrides en redactionele conceptberichten.

## Vereisten

- WordPress 6.0 of nieuwer.
- PHP 7.4 of nieuwer.
- Een YouTube Data API v3-key voor automatische feeds en playlists.
- Een werkende WP-Cron-configuratie voor automatische verversing en conceptberichten.

## Installatie

1. Upload de pluginmap of distributie-zip via **Plugins > Nieuwe plugin**.
2. Activeer **Scientias YouTube Carrousel**.
3. Open **YouTube carrousel > Feed instellingen**.
4. Vul de YouTube Data API-key en het YouTube-kanaal-ID in.
5. Kies het maximale aantal feeditems en schakel eventueel automatische concepten in.
6. Klik op **Feeds direct verversen** om de eerste feed onmiddellijk op te halen.

De API-key wordt na opslag niet opnieuw leesbaar in het formulier getoond. Laat het veld leeg om een bestaande key te behouden.

## Werking en bronprioriteit

De standaardshortcode gebruikt de volgende volgorde:

1. De gecachete Shorts-feed van het ingestelde YouTube-kanaal.
2. Losse, gepubliceerde video-items uit WordPress wanneer de feed niet beschikbaar of leeg is.

Een benoemde extra carrousel gebruikt deze volgorde:

1. De gecachete inhoud van de ingestelde YouTube-playlist.
2. De handmatige videorijen van die carrousel wanneer de playlist niet beschikbaar of leeg is.

Bezoekers laden nooit rechtstreeks gegevens uit de YouTube API. De plugin haalt feeds op de achtergrond op en toont de laatst succesvol ontvangen gegevens als een nieuwe aanvraag mislukt.

## Shortcodes

Plaats de standaardcarrousel met:

```text
[scientias_youtube_carrousel]
```

Gebruik een eigen titel en itemlimiet met:

```text
[scientias_youtube_carrousel title="Video" limit="8"]
```

Plaats een extra themacarrousel met:

```text
[scientias_youtube_carrousel name="ruimtevaart"]
```

Beschikbare attributen:

| Attribuut | Betekenis |
| --- | --- |
| `name` | Slug van een extra carrousel. Zonder `name` wordt de standaardfeed getoond. |
| `title` | Overschrijft de zichtbare carrouseltitel. |
| `limit` | Begrenst het aantal losse WordPress-fallbackitems. API-feeds gebruiken het maximum uit de feedinstellingen. |

## Weergave op de site

- Toont een responsieve, horizontale videocarrousel op desktop en mobiel.
- Toont thumbnails met lazy loading en een automatische YouTube-thumbnailfallback.
- Opent video's in een fullscreen modal met autoplay.
- Ondersteunt knoppen, toetsenbordnavigatie, slepen en swipen.
- Een videotitel kan optioneel naar een eigen artikel of pagina linken.
- Zonder paginalink blijft de titel gewone tekst en opent de thumbnail de videospeler.

## Videobronnen

- Haalt automatisch Shorts op uit een ingesteld YouTube-kanaal.
- Ondersteunt maximaal 50 feeditems.
- Ondersteunt extra themacarrousels met een eigen shortcodenaam.
- Een extra carrousel kan een YouTube-playlist gebruiken.
- Per extra carrousel kunnen maximaal 100 handmatige video's worden ingesteld.
- Er kunnen maximaal 100 extra carrousels worden opgeslagen.
- Handmatige video's dienen als fallback wanneer een API-feed of playlist niet beschikbaar of leeg is.
- Losse fallbackvideo's kunnen als eigen WordPress-posttype worden beheerd.

## Link Overrides

- Koppelt een YouTube-video-ID aan een eigen artikel- of pagina-URL.
- Ondersteunt toevoegen, wijzigen en verwijderen.
- Toont maximaal 50 overrides per beheerpagina.
- Ondersteunt maximaal 5.000 opgeslagen koppelingen.
- Toont huidige feedvideo's met hun video-ID en koppelingsstatus.
- Handmatig ingestelde overrides hebben voorrang op automatisch aangemaakte koppelingen.

Link overrides worden gebruikt voor video's uit de automatische hoofdfeed. Handmatige video's en handmatige rijen in extra carrousels hebben ieder een eigen optioneel pagina-URL-veld.

## CSV-import

- Importeert video-ID/URL-koppelingen uit CSV.
- Ondersteunt komma en puntkomma als scheidingsteken.
- Herkent optionele kolomkoppen.
- Heeft een samenvoegmodus en een volledige vervangmodus.
- Accepteert maximaal 5.000 gegevensregels en bestanden tot 2 MB.
- Controleert bestandstype, uploadstatus, regels, dubbele video-ID's en ongeldige waarden.
- Slaat niets op wanneer een CSV is afgekapt of een limiet overschrijdt.

## Automatische concepten

- Kan voor iedere nieuwe Short automatisch een WordPress-concept maken.
- Gebruikt de YouTube-titel als berichttitel.
- Plaatst de YouTube-URL en een redactionele instructie in het bericht.
- Kent automatisch de categorieën `Video` en `Shorts` toe wanneer die bestaan.
- Stelt het berichtformaat in op `video`.
- Wijst het concept toe aan de geconfigureerde standaardauteur, momenteel `Diederik Jekel`.
- Maakt iedere video-ID slechts eenmaal aan.
- Een verwijderd concept wordt bewust niet opnieuw aangemaakt.
- Bij publicatie wordt automatisch een link override naar het gepubliceerde artikel toegevoegd.
- Een bestaande handmatige override wordt nooit overschreven.

De standaardauteur wordt in de plugin bepaald met `SYC_DEFAULT_DRAFT_AUTHOR_NAME`. Wanneer de ingestelde gebruiker niet bestaat, maakt WordPress het concept zonder die expliciete auteurstoewijzing aan.

## Caching en verversing

- Bezoekersrequests doen nooit rechtstreeks een YouTube API-aanvraag.
- WP-Cron controleert de feeds iedere vijf minuten.
- Playlists worden in roterende batches verwerkt om time-outs en overmatig quotagebruik te voorkomen.
- De actieve cache heeft een looptijd van vijftien minuten.
- De laatst succesvol ontvangen feed blijft als fallback beschikbaar bij API-fouten.
- De hoofdfeedcache is gekoppeld aan het ingestelde kanaal en itemaantal.
- Een beheerder kan de feeds handmatig verversen.
- De handmatige refresh toont een echte succes-, waarschuwing- of foutmelding.
- Overlappende cron-, refresh-, concept- en instellingenprocessen worden met owner-aware locks voorkomen.

## Beheer en veiligheid

- De opgeslagen API-key wordt niet leesbaar teruggetoond.
- Een leeg API-keyveld behoudt de bestaande sleutel.
- Video-ID's, URL's, titels, thumbnails en slugs worden gevalideerd en opgeschoond.
- Beheeracties gebruiken WordPress-capabilities en nonces.
- Gelijktijdige instellingenupdates kunnen elkaar niet stil overschrijven.
- Afgekapt formulierverkeer door `max_input_vars` wordt herkend en geweigerd.
- Bestaande configuraties boven nieuwe limieten worden niet stilzwijgend afgekapt.
- Pagina-caches van onder andere SiteGround, WP Rocket, W3 Total Cache en WP Super Cache worden waar mogelijk gepurged.
- Vertalingen via het plugin-textdomain worden ondersteund.

## Beheeronderdelen

- **Feed instellingen:** API-key, kanaal-ID, maximaal aantal items, automatische concepten, handmatige refresh en feedstatus.
- **Link overrides:** nieuwe koppelingen toevoegen, bestaande koppelingen pagineren en bewerken, CSV importeren en huidige feed-ID's bekijken.
- **Extra carrousels:** playlistcarrousels en handmatige themacarrousels maken of wijzigen.
- **Losse video-items:** gepubliceerde WordPress-video's beheren die als fallback voor de hoofdfeed dienen.

## Levenscyclus

- Plant het cron-event automatisch bij activatie.
- Herstelt een ontbrekend cron-event automatisch.
- Verwijdert cron-events bij deactivatie.
- Verwijdert instellingen, locks, feedcaches, playlistcaches en administratieve status bij het volledig verwijderen van de plugin.

## Ontwikkelcontrole

De repository bevat configuratie voor PHP_CodeSniffer met WordPress Coding Standards en PHPCompatibilityWP.

```bash
composer install
composer exec -- phpcs
php -l scientias-youtube-carrousel.php
php -l uninstall.php
```

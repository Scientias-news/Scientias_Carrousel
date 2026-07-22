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

## Onboarding

Na een nieuwe activatie opent voor administrators automatisch eenmalig **YouTube carrousel > Aan de slag** wanneer de API-configuratie nog ontbreekt.

1. De plugin controleert of de activatie en WP-Cronplanning zijn voltooid.
2. De beheerder vult de YouTube Data API-key, het kanaal-ID en het maximale aantal Shorts in.
3. Na opslaan haalt de plugin direct de eerste feed op en toont hij het resultaat.

Zolang de API-key of het kanaal-ID ontbreekt, blijft in WordPress een beheerwaarschuwing met een link naar de onboarding zichtbaar. Bestaande installaties met geldige instellingen worden bij een update niet omgeleid.

### Plugin bijwerken

Verwijder of deactiveer de bestaande plugin niet voor een update. Upload de nieuwe zip via **Plugins > Nieuwe plugin > Plugin uploaden** en kies **Huidige vervangen door geüploade versie**. Verwijderen voert `uninstall.php` uit en wist de instellingen, API-key, carrousels en link overrides.

## Dashboard

Het menu **YouTube carrousel > Dashboard** geeft de redactie direct inzicht in wat bezoekers zien.

- Toont de gezondheid van de hoofdfeed en iedere ingestelde playlistcarrousel.
- Toont de actieve bron: actuele API-cache, laatst bekende feed of handmatige fallback.
- Toont het aantal zichtbare items.
- Toont de laatste verversingspoging en de laatste succesvolle verversing afzonderlijk.
- Toont wanneer WP-Cron de feeds opnieuw controleert.
- Waarschuwt wanneer API-instellingen ontbreken, WP-Cron niet beschikbaar is of fallbackcontent zichtbaar is.
- Bevat een knop om de YouTube-verbinding te testen zonder caches of conceptberichten te wijzigen.
- Bevat een knop om de hoofdfeed en een begrensde playlistbatch direct te verversen.

## Redactioneel video-overzicht

Het menu **YouTube carrousel > Video-overzicht** bundelt alle bekende video's uit de hoofdfeed en ingestelde playlistcarrousels.

- Toont per video de redactionele status: nieuw, concept aangemaakt, artikel gekoppeld, gepubliceerd of genegeerd.
- Toont afzonderlijk of de video nog in een actuele actieve feedset staat.
- **API-video verdwenen** betekent dat de video niet meer in de laatst succesvol opgehaalde actieve feedset staat; dit betekent niet noodzakelijk dat de video van YouTube is verwijderd.
- **Concept maken** maakt direct een gekoppeld WordPress-concept.
- **Artikel koppelen** zoekt een bestaand bewerkbaar WordPress-artikel op titel. Publicatie vult automatisch de link override.
- **Negeren** voorkomt dat voor een nog niet verwerkte video later automatisch een concept wordt gemaakt.

Bij een upgrade wordt het overzicht eenmalig gevuld vanuit de bestaande geldige feedcaches. Alleen succesvolle API-verversingen wijzigen de bronstatus; een API-fout markeert daarom geen video's als verdwenen.

## Automatische berichten

Onder **Feed instellingen** kan een administrator automatische berichten voor nieuwe Shorts in- of uitschakelen. De standaardauteur, categorieën, het berichtformaat, de initiële status en de tekst na de YouTube-URL zijn afzonderlijk instelbaar. Handmatig via het video-overzicht aangemaakte concepten blijven altijd gewone concepten.

## Werking en bronprioriteit

De standaardshortcode gebruikt de volgende volgorde:

1. De gecachete Shorts-feed van het ingestelde YouTube-kanaal.
2. Losse, gepubliceerde video-items uit WordPress wanneer de feed niet beschikbaar of leeg is.

Een benoemde extra carrousel gebruikt deze volgorde:

1. De gecachete inhoud van de ingestelde YouTube-playlist.
2. De handmatige videorijen van die carrousel wanneer de playlist niet beschikbaar of leeg is.

Bezoekers laden nooit rechtstreeks gegevens uit de YouTube API. De plugin haalt feeds op de achtergrond op en toont de laatst succesvol ontvangen gegevens als een nieuwe aanvraag mislukt.

Elke extra carrousel wordt afzonderlijk opgeslagen. Daardoor blijven andere carrousels onaangeraakt en overschrijft een verouderd browsertabblad geen nieuwere wijziging. Verwijderen gebeurt uitsluitend via de expliciete verwijderknop.

Bestaande carrousels staan in inklapbare kaarten. Ingeklapt blijven naam, shortcode, bron en aantal handmatige video's zichtbaar; open de kaart om de inhoud te bewerken.

De volgorde van extra carrousels en van handmatige video's binnen een carrousel kan via slepen worden aangepast. Voor de carrouselvolgorde zijn ook toetsenbordvriendelijke omhoog/omlaag-knoppen beschikbaar; wijzigingen worden pas na expliciet opslaan actief.

Iedere opgeslagen carrousel kan worden gedupliceerd, de shortcode kan direct worden gekopieerd en een voorbeeld van de laatst opgeslagen versie kan op de beheerpagina worden bekeken. Het voorbeeld gebruikt uitsluitend bestaande caches en doet geen extra YouTube API-aanvraag.

Playlistcarrousels tonen in hun kaart de gezondheid, actieve bron, het aantal zichtbare items, laatste poging, laatste succesvolle refresh en de meest recente foutmelding. Deze status hoort altijd bij de opgeslagen playlist.

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

Link overrides worden gebruikt voor API-video's uit de automatische hoofdfeed en playlistcarrousels. Handmatige video's en handmatige rijen in extra carrousels hebben ieder een eigen optioneel pagina-URL-veld.

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

- **Dashboard:** actieve videobronnen, feedgezondheid, itemaantallen, cronplanning en directe beheeracties.
- **Aan de slag:** eerste configuratie, herstel na ontbrekende instellingen en directe eerste feedrefresh.
- **Video-overzicht:** alle feedvideo's, redactionele statussen en acties voor concepten, artikelkoppelingen en negeren.
- **Feed instellingen:** API-key, kanaal-ID, maximaal aantal items, automatische concepten, handmatige refresh en feedstatus.
- **Link overrides:** nieuwe koppelingen toevoegen, bestaande koppelingen pagineren en bewerken, CSV importeren en huidige feed-ID's bekijken.
- **Extra carrousels:** playlistcarrousels en handmatige themacarrousels maken of wijzigen.
- **Losse video-items:** gepubliceerde WordPress-video's beheren die als fallback voor de hoofdfeed dienen.

Administrators en editors kunnen de redactionele onderdelen beheren. Alleen administrators hebben toegang tot API-instellingen, onboarding, verbindingstests, handmatige feedrefreshes en technische hulpmiddelen.

Onder **Gereedschap** kunnen administrators de portable configuratie als JSON exporteren of importeren. De API-key, caches, locks, cronstatus, conceptmapping en videohistorie staan nooit in het exportbestand. Een import behoudt de bestaande API-key en wordt volledig geweigerd zodra één veld of rij ongeldig is.

Op dezelfde pagina kan een read-only diagnostisch JSON-rapport worden gedownload. Het bevat versies, configuratievlaggen en aantallen, cache- en feedstatus, cronplanning en lockvervalmomenten, maar nooit API-keys, ruwe API-antwoorden, artikelgegevens, gebruikersgegevens, nonces of locktokens.

YouTube-fouten worden gericht uitgelegd, onder andere voor een ongeldige of geblokkeerde API-key, opgebruikt quotum, rate limiting, een ongeldig kanaal, een niet-beschikbare Shorts-playlist en een privé/verwijderde playlist. Crongezondheid is gebaseerd op werkelijk gemeten uitvoering en herkent ook een goed werkende externe servercron.

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

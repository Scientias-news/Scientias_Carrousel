# Changelog

## 1.1.1 - 2026-07-20

- Added a shortcode hint (`[scientias_youtube_carrousel]`) to the feed settings screen.
- Auto-created draft posts are now assigned the "Shorts" category (under "Video") by default; other categories are still set editorially based on topic.

## 1.1.0 - 2026-07-16

- Added automatic draft posts for new shorts in the YouTube feed, containing the video title, an embed, and an editorial note.
- Added automatic link override linking: when an auto-created draft is published, the video's link override is filled with the article permalink.
- Manually set link overrides always take precedence over automatic ones.
- Deleted drafts are intentionally not recreated; each video ID is processed once.
- Added an on/off setting for automatic drafts on the feed settings screen (off by default).

## 1.0.9 - 2026-06-17

- Added optional YouTube playlist support to extra carrousels.
- Added per-playlist API caching with manual video rows as fallback when a playlist cannot be loaded.

## 1.0.8 - 2026-06-17

- Added manually managed extra carrousels for topic-specific video selections.
- Added `[scientias_youtube_carrousel name="slug"]` support for rendering an extra carrousel without using the YouTube feed.

## 1.0.7 - 2026-06-15

- Added CSV import for Link overrides with merge and overwrite modes, supporting comma and semicolon delimiters.
- Added a clear warning that overwrite mode replaces all existing link overrides.
- Renamed manual fallback entries to `Losse video-items` throughout the admin UI.
- Added explanatory help text to clarify that loose video items are only used as manual source or fallback when the YouTube API feed is unavailable.
- Added an admin notice on the loose video items list explaining the relationship between API feed, link overrides, and fallback items.
- Reordered the YouTube carrousel submenu so `Feed instellingen` and `Link overrides` appear before `Losse video-items`.
- Renamed the shortcode to `[scientias_youtube_carrousel]` and removed the old test-phase spelling.

## 1.0.6.1 - 2026-06-15

- Fixed the submit button label on the feed settings screen after the Link overrides UX update.
- Confirmed PHP syntax and whitespace checks for the patch release.

## 1.0.6 - 2026-06-15

- Improved the Link overrides admin screen for larger lists.
- Moved the new override input row to the top of the page with a dedicated save button directly below it.
- Added pagination for existing link overrides, showing 50 mappings per page.
- Kept non-visible override pages preserved while editing the current paginated page.
- Removed the duplicate read-only saved mappings table to reduce clutter.

## 1.0.5 - 2026-06-15

- Moved automatic feed link overrides to a separate `Link overrides` submenu item.
- Masked the stored YouTube API key in the admin form so it is no longer displayed in plaintext.
- Kept existing API keys when the API key field is left empty while saving settings.
- Preserved existing link overrides when saving feed settings from the main settings page.

## 1.0.4 - 2026-06-15

- Fixed feed link override persistence by separating settings normalization from form sanitization.
- Added an overview of saved video-ID to page URL mappings in the feed settings screen.
- Added a current feed overview showing video IDs, titles, and whether an override is active.
- Improved compatibility with older stored override formats.

## 1.0.3 - 2026-06-15

- Added link overrides for automatic YouTube feed items.
- Added a settings table where YouTube video IDs can be mapped to custom page URLs.
- Kept default feed behavior unchanged when no override is set: the thumbnail opens the fullscreen player and the title remains plain text.
- Applied custom link behavior consistently to automatic feed items and manually managed video items.

## 1.0.2 - 2026-06-15

- Added an optional custom link URL field for manually managed video items.
- Kept the default behavior unchanged: without a custom link, clicking the video opens the fullscreen player.
- When a custom link is set, the title below the video links to that URL while the thumbnail still opens the fullscreen player.
- Updated carrousel markup to avoid nesting links inside buttons.

## 1.0.1 - 2026-04-29

- Added editorial horizontal video carrousel styling for desktop and mobile.
- Added fullscreen video modal with mobile navigation.
- Preserved YouTube controls by avoiding overlays on top of the player.
- Added mobile vertical edge navigation in fullscreen mode.
- Added thumbnail fallbacks for YouTube videos.
- Improved shortcode rendering by preventing paragraph wrappers around the carrousel.
- Reduced YouTube API feed cache lifetime from 30 minutes to 5 minutes.
- Added cache clearing hooks for feed setting changes and manual video item updates.
- Added best-effort purge support for SiteGround Optimizer and common WordPress cache plugins.

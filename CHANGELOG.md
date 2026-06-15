# Changelog

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
- Updated carousel markup to avoid nesting links inside buttons.

## 1.0.1 - 2026-04-29

- Added editorial horizontal video carousel styling for desktop and mobile.
- Added fullscreen video modal with mobile navigation.
- Preserved YouTube controls by avoiding overlays on top of the player.
- Added mobile vertical edge navigation in fullscreen mode.
- Added thumbnail fallbacks for YouTube videos.
- Improved shortcode rendering by preventing paragraph wrappers around the carousel.
- Reduced YouTube API feed cache lifetime from 30 minutes to 5 minutes.
- Added cache clearing hooks for feed setting changes and manual video item updates.
- Added best-effort purge support for SiteGround Optimizer and common WordPress cache plugins.

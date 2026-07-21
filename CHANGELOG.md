# Changelog

## 1.5.1 - 2026-07-21

- Added an editorial dashboard for the main feed and every configured playlist carousel.
- Shows the active source, visible item count, last refresh attempt, last successful refresh, and next cron check.
- Warns when API settings are missing, WP-Cron is unavailable, or visitors are seeing fallback content.
- Added a safe YouTube connection test that does not change caches or create draft posts.
- Preserves the previous successful status when a later API refresh fails.
- Tracks and cleans up per-playlist refresh metadata.

## 1.1.4.1 - 2026-07-21

- Hardened feed, settings, and auto-draft locks with atomic owner tokens and owner-aware cleanup.
- Added global limits for saved link overrides, custom carrousels, and manual items per carrousel.
- Made the CSV row limit apply to all rows read, including invalid and duplicate rows.
- Rejects truncated CSV and carrousel form submissions instead of saving partial data.
- Preserves existing over-limit settings and serializes all settings writers to prevent lost updates.
- Keeps last-known-good feed data available on API errors and scopes the main cache to its channel settings.
- Refreshes playlists in bounded rotating batches to limit request time and YouTube API quota usage.
- Manual refresh now reports its actual result and uses Post/Redirect/Get to prevent accidental replays.
- Cleaned up refresh lock state during uninstall.
- Auto-created draft posts are now assigned to a configurable default author (Diederik Jekel) instead of no author, since WP-Cron has no logged-in user context.
- Added a short retrying lock around the link-override write on post publish, preventing a rare lost-update race when two posts publish at nearly the same time.

## 1.1.4 - 2026-07-21

- Shortcode rendering no longer calls the YouTube API directly: both the main feed and extra-carrousel playlists are now read-only from the transient cache during a visitor request. All API fetching (main feed and every configured playlist) happens in the background via `syc_refresh_all_feeds()`, run by WP-Cron and by the "Cache legen" button in the admin.
- Decoupled the cache TTL (15 minutes) from the cron refresh interval (5 minutes) so a slightly late cron run no longer leaves a cold-cache gap.
- Added a lock around the background feed/playlist refresh to prevent overlapping fetches.
- Clamped `max_items` to 50 at save time, not only when calling the API.
- Sanitized video ID, title, and thumbnail URL from the YouTube API response before caching.
- Added file size, MIME-type, and row-count limits to the link overrides CSV import.

## 1.1.3 - 2026-07-21

- Moved feed refresh and auto-draft synchronization to a WP-Cron event (every 5 minutes) instead of running during a visitor's page load. The shortcode still reads from the transient cache as a fallback, so behavior is unchanged if the cron doesn't run.
- Added self-healing cron scheduling on `init` so the event is restored if it goes missing outside of the activation hook (e.g. after an automated deploy).
- Added a deactivation hook to unschedule the cron event, and cron cleanup in `uninstall.php`.

## 1.1.2 - 2026-07-21

- Auto-created draft posts are now assigned both the "Video" and "Shorts" categories, and get the "video" post format set.
- Fixed a shortcode bug where an explicit `title="Video"` on an extra carrousel was silently replaced by the carrousel name.
- Added `load_plugin_textdomain()` so translation files in `languages/` are actually loaded.
- Added `uninstall.php` to remove plugin options and transients when the plugin is deleted.

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

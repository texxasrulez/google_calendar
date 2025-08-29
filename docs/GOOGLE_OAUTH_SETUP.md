# Google OAuth for Roundcube Calendar (Read-only)

This adds a **Google** driver and a Settings block to the Calendar plugin so each user can connect their Google account and overlay Google calendars in Roundcube.

## What you get

- New backend driver: `google` (read-only).  
- Preferences → Calendar: a **Google** section with a Connect/Disconnect button and per-calendar checkboxes.
- Token storage in the Roundcube DB (`google_oauth_tokens` table). Tokens are stored as JSON; refresh tokens are used when possible.
- Uses the official `google/apiclient` library.

> Write support is intentionally disabled for safety in this first cut. Events from Google are displayed read-only. The driver marks calendars as non‑editable so the UI won’t allow creating/editing/deleting.
>
> You can extend `google_driver::new_event()/edit_event()/remove_event()` to enable writes (respecting `accessRole === writer/owner`).

## Install

1. **Copy** the `calendar` plugin directory into your Roundcube `plugins/` (replace your existing copy or install as a new plugin path).
2. In this plugin directory, install the Google API client:
   ```bash
   cd plugins/calendar
   composer install
   ```
   If you deploy vendors elsewhere, ensure Composer’s autoload is reachable by Roundcube (either global `vendor/` or plugin’s `vendor/`).

3. In `plugins/calendar/config.inc.php`, set your Google OAuth credentials:
   ```php
   $config['calendar_google_client_id']     = 'YOUR_CLIENT_ID.apps.googleusercontent.com';
   $config['calendar_google_client_secret'] = 'YOUR_CLIENT_SECRET';
   // Redirect URI is computed, but you must authorize it in Google Cloud console:
   // https://YOURDOMAIN.TLD/mail/?_task=calendar&_action=plugin.google-oauth-callback
   ```

4. **Google Cloud Console** → APIs & Services → Credentials
   - Create **OAuth client ID** (type: Web application).
   - Authorized redirect URI: `https://YOURDOMAIN.TLD/mail/?_task=calendar&_action=plugin.google-oauth-callback`
   - Enable **Google Calendar API**.

5. **Database**
   - On first use, the plugin creates table `google_oauth_tokens`. Ensure your DB user can `CREATE TABLE`.
   - Table (generic):  
     ```sql
     CREATE TABLE google_oauth_tokens (
       user_id INT NOT NULL,
       account VARCHAR(255) NOT NULL,
       access_token TEXT NOT NULL,
       refresh_token TEXT NULL,
       expires_at INT NULL,
       PRIMARY KEY (user_id, account)
     );
     ```

6. In Roundcube `config/config.inc.php`, enable the plugin:
   ```php
   $config['plugins'][] = 'calendar';
   ```

7. **Choose the driver** (optional):
   - To use **Google only**, set in `plugins/calendar/config.inc.php`:
     ```php
     $config['calendar_driver'] = 'google';
     ```
   - To keep your existing driver (e.g. `caldav`) and only use Google as an overlay source in preferences, leave your driver as-is. The Google block still allows connecting and selecting calendars; the calendar view will include them when the Google driver is active. (Advanced multi‑driver overlay is planned; for now choose the driver you need.)

## Usage

- Go to **Settings → Preferences → Calendar**. In the **Google** block click **Connect Google** and complete OAuth.
- Select which calendars to show.
- Go to the Calendar view; Google events appear read‑only, colored using Google’s calendar color.

## Notes / Limitations

- Read‑only by default. Write methods are stubbed out.
- No background sync. Events are fetched live for the visible range.
- Recurrence is handled by Google’s `singleEvents=true` expansion.
- Alarms are not exposed; pending alarms list is empty.
- If you see “google/apiclient missing”, run `composer install` inside the plugin.
- If your Roundcube is not under `/mail/`, adjust the redirect URI path accordingly.

## Dev hooks

- Driver: `plugins/calendar/drivers/google/google_driver.php`
- UI glue and endpoints: `plugins/calendar/calendar.php` (actions: `google-oauth-start`, `google-oauth-callback`, `google-oauth-disconnect`)

## Security

- Tokens are stored per-**Roundcube user id**.
- Consider restricting who can enable the plugin and ensure HTTPS is enforced (HSTS).

---

© You. AGPLv3 compatible changes applied. This readme is part of the distributed source.

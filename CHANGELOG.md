# Changelog ##

## 1.6.2

- Fix fatal `RedisException: NOAUTH Authentication required` on `session_start()` when PHP is configured with Redis sessions but `session.save_path` does not include Redis credentials: force file-based sessions for this script only (keeps form credentials in server-side storage). See [#36](https://github.com/BeAPI/phpwpinfo/issues/36).
- Remove third-party CDN assets (Bootstrap CSS/JS, jQuery); ship minimal inline CSS and vanilla JS for navigation dropdowns
- Resolve public IP via in-page `fetch()` to api.ipify.org (replaces JSONP); document CSP / `connect-src` limitations in the UI when the call fails
- Fix dropdown menus clipped by the header (`overflow` / clearfix on the navbar container)

## 1.6.1

- Better handle error management for Redis test connexion

## 1.6.0

- Better handle error management for MySQL test connexion
- Improve check with -1 value on PHP conf
- Improve form for mail checker (allow to customize mailfrom and fix a return-path)

## 1.5.0

- ADD: Favicon
- ADD: WordPress Handbook link
- UPDATE: Required version and modules
- UPDATE: PHP requirement change from 5.6 to 7.4

## 1.4.0
- Refactor readme
- Branding
- Add readme's installation section and usage

## 1.1
* Implement recommended/required for each config/module
* Improve test config value

## 1.0
* Initial release
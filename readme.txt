=== Festival ID Tracker ===
Contributors: vernissaria
Tags: redirect, url tracking, campaign
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track unique festival ID URLs, view stats in dashboard widgets, and enable optional redirects while preserving IDs.

== Description ==

The Festival ID Tracker plugin provides secure, in-dashboard analytics for websites using unique identifiers in their URLs (e.g., NFC tags, QR codes, or campaign links). It logs and analyzes visits with a `?id=XXXXXX` pattern and offers optional automatic redirection while maintaining comprehensive statistics.

**Version 1.5.0** introduces enhanced security features including rate limiting, bot detection, and WordPress compliance improvements for a more robust and secure tracking experience.

= Key Features =

**Tracking & Analytics:**
* Tracks `?id=XXXXXX` query parameters (6-character alphanumeric)
* Secure database logging with proper indexing
* Privacy-focused with daily-rotating user hashes
* Real-time statistics display

**Security Features (New in 1.5.0):**
* Rate limiting (10 requests/minute per IP)
* Automatic bot detection and filtering
* Nonce verification for all admin operations
* SQL injection protection with prepared statements
* XSS prevention with proper output escaping

**Dashboard Widgets:**
* **Daily Statistics:** 7-day rolling view with navigation
  - Total calls per day
  - Unique festival IDs per day
  - Historical data browsing
* **Global Statistics:** All-time performance metrics
  - Total accesses per ID
  - Active days per ID
  - Top 5/Show All toggle

**Redirect Functionality:**
* Optional automatic redirection
* ID parameter preservation in redirects
* Works with any internal or external URL
* Simple enable/disable toggle

**Administration:**
* Comprehensive settings page under Settings > Festival ID Tracker
* Quick statistics overview
* Testing tools and instructions
* Direct settings access from plugins page

= Perfect For =

* **Events & Festivals:** Track NFC wristbands, badges, or tags
* **QR Code Campaigns:** Monitor scan rates and engagement
* **Marketing Campaigns:** Track campaign-specific URLs
* **Multi-Venue Events:** Analyze venue popularity
* **Tourism & Hospitality:** Monitor information point usage
* **Retail Promotions:** Track in-store engagement

= Privacy & Compliance =

* No personally identifiable information stored
* Daily-rotating hashes for user identification
* GDPR-ready design
* Compliant with WordPress coding standards

== Installation ==

= Automatic Installation (Recommended) =

1. Go to Plugins > Add New in your WordPress admin
2. Search for "Festival ID Tracker"
3. Click "Install Now" and then "Activate"
4. Configure settings under Settings > Festival ID Tracker

= Manual Installation =

1. Download the plugin ZIP file
2. Upload the `festival-id-tracker` folder to `/wp-content/plugins/`
3. Activate through the 'Plugins' menu in WordPress
4. Configure under Settings > Festival ID Tracker

= Configuration =

1. Navigate to **Settings > Festival ID Tracker**
2. (Optional) Enable redirect functionality
3. (Optional) Enter destination URL for redirects
4. Save Settings
5. Test with `yoursite.com?id=TEST01`

== Frequently Asked Questions ==

= How do I view statistics? =

Statistics are displayed in three locations:
1. **Dashboard Widgets:** Two widgets on your main dashboard
2. **Settings Page:** Current statistics section
3. **Daily/Global Views:** Detailed breakdowns in widgets

= What does each statistic mean? =

* **Total Calls Tracked:** All-time visits with any festival ID
* **Unique Festival IDs:** Count of different IDs used
* **Calls Today:** Today's visits with any ID
* **Total Accesses:** Times a specific ID was used
* **Unique Days Used:** Different days an ID was active

= How does the redirect work? =

When enabled, visitors accessing `yoursite.com?id=ABC123` are automatically redirected to your configured URL with the ID preserved: `destination.com?id=ABC123`

= Can I disable tracking for bots? =

Yes! Version 1.5.0 automatically detects and filters out bot traffic from your statistics.

= Is there a rate limit? =

Yes, the plugin limits each IP address to 10 requests per minute to prevent abuse.

= Can I export the data? =

Currently, data export must be done via database tools. A future version may include built-in export functionality.

= Is this plugin GDPR compliant? =

The plugin is designed with privacy in mind:
- Uses daily-rotating hashes instead of storing raw user data
- No personally identifiable information is stored long-term
- You should still mention tracking in your privacy policy

= What happens to my data if I deactivate the plugin? =

Data is preserved when you deactivate the plugin. To completely remove data, you must manually delete the `wp_festidtrack_log` table from your database.

= Can I customize the ID format? =

Currently, the plugin tracks exactly 6-character alphanumeric IDs. Custom formats may be added in future versions.

== Screenshots ==

1. Festival ID Daily Statistics widget showing 7-day view
2. Festival ID Global Statistics widget with top performers
3. Settings page with redirect configuration
4. Current statistics display in settings
5. Testing instructions and examples

== Changelog ==

= 1.5.0 (2024) =
* **Security:** Added comprehensive security improvements
  - Rate limiting (10 requests/minute per IP)
  - Bot detection and filtering
  - Nonce verification for all admin operations
  - Enhanced input sanitization
* **Compliance:** Changed prefix to `festidtrack_` for WordPress standards
* **Performance:** Improved SQL queries and caching
* **Fixes:** Resolved statistics display issues
* **Code:** Complete refactoring following WordPress coding standards

= 1.4.0 =
* Added redirect functionality with optional URL configuration
* New comprehensive settings page under Settings > Festival ID Tracker
* Enhanced settings with testing instructions
* Added enable/disable toggle for redirects
* Settings link in plugins page for quick access

= 1.3.0 =
* Dashboard widgets for daily and global statistics
* 7-day rolling navigation for historical data
* Top 5 / Show All toggle for global statistics
* Enhanced database logging with improved indexing

= 1.2.0 =
* ID preservation in redirect URLs
* Improved redirect handling

= 1.1.0 =
* Basic tracking functionality
* Database logging implementation

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.5.0 =
Major security update with rate limiting, bot detection, and WordPress compliance improvements. Recommended for all users.

= 1.4.0 =
Adds powerful redirect functionality and comprehensive settings page. Update to enable automatic redirects with ID preservation.

== Additional Information ==

= Requirements =

* WordPress 5.0 or higher
* PHP 7.4 or higher
* MySQL 5.6 or higher

= Support =

For support, feature requests, or bug reports, please visit:
[GitHub Issues](https://github.com/Clustmart/festival-id-tracker-wp-plugin/issues)

= Contributing =

We welcome contributions! Visit our [GitHub repository](https://github.com/Clustmart/festival-id-tracker-wp-plugin) to contribute.


== Privacy Policy ==

This plugin:
* Stores hashed visitor data (IP + User Agent + Daily Salt)
* Does not store personally identifiable information
* Does not make external API calls
* Does not set cookies
* All data is stored locally in your WordPress database

For GDPR compliance, please mention the tracking functionality in your site's privacy policy.
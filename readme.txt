=== FediBoost ===
Contributors: flavor
Tags: activitypub, mastodon, fediverse, boost, social
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically boost WordPress posts on connected Mastodon accounts when published via ActivityPub.

== Description ==

FediBoost extends the ActivityPub plugin by automatically boosting your WordPress posts on your connected Mastodon accounts when they are published.

When you publish a new post on your WordPress site, FediBoost will automatically boost (reblog) that post on your linked Mastodon account, helping increase visibility across the fediverse.

**Features:**

* Automatic boosting of new posts to connected Mastodon accounts
* Seamless integration with the ActivityPub plugin
* No additional configuration required after setup

**Requirements:**

* The [ActivityPub plugin](https://wordpress.org/plugins/activitypub/) must be installed and configured
* A connected Mastodon account via ActivityPub

== Installation ==

1. Ensure the ActivityPub plugin is installed and activated.
2. Upload the `fediboost` folder to `/wp-content/plugins/` or install via the WordPress plugin installer.
3. Activate FediBoost through the Plugins menu.
4. Go to Settings > FediBoost.
5. Enter your Mastodon instance URL (e.g., mastodon.social) and authorize the connection.
6. New posts will automatically be boosted to your connected Mastodon account when published.

== Frequently Asked Questions ==

= Does this plugin work without the ActivityPub plugin? =

No, FediBoost requires the ActivityPub plugin to be installed and properly configured. FediBoost extends ActivityPub's functionality to add automatic boosting capabilities.

= Can I choose which posts get boosted? =

Currently, FediBoost will boost all newly published posts. Future versions may include options to selectively boost posts based on categories, tags, or other criteria.

== External Services ==

FediBoost connects to external Mastodon instances that you configure (e.g., mastodon.social). This communication is essential for the plugin to function and is initiated only with the instance you explicitly provide.

**During setup:**

* FediBoost registers an OAuth application on your Mastodon instance and performs an authorization flow so it can act on your behalf.

**When a post is published:**

* FediBoost searches for the post on your Mastodon instance and performs a reblog (boost) via the Mastodon API.

**Data sent to your Mastodon instance:**

* Your instance URL
* OAuth authorization codes
* Search queries to locate your posts
* Reblog (boost) requests

**Data stored locally on your WordPress site:**

* Encrypted OAuth tokens
* Your Mastodon username
* Your Mastodon instance URL

Each Mastodon instance has its own privacy policy and terms of service. You can find a list of instances and their policies at [joinmastodon.org/servers](https://joinmastodon.org/servers).

This plugin does not send data to any third-party service other than the Mastodon instance(s) you explicitly configure.

== Changelog ==

= 1.0.0 =
* Initial release
* Automatic boosting of published posts to connected Mastodon accounts

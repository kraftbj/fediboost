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

1. Ensure the ActivityPub plugin is installed and activated on your WordPress site.
2. Configure your Mastodon account connection through the ActivityPub plugin settings.
3. Upload the `fediboost` folder to the `/wp-content/plugins/` directory.
4. Activate the FediBoost plugin through the 'Plugins' menu in WordPress.
5. New posts will automatically be boosted to your connected Mastodon account when published.

== Frequently Asked Questions ==

= Does this plugin work without the ActivityPub plugin? =

No, FediBoost requires the ActivityPub plugin to be installed and properly configured. FediBoost extends ActivityPub's functionality to add automatic boosting capabilities.

= Can I choose which posts get boosted? =

Currently, FediBoost will boost all newly published posts. Future versions may include options to selectively boost posts based on categories, tags, or other criteria.

== Changelog ==

= 1.0.0 =
* Initial release
* Automatic boosting of published posts to connected Mastodon accounts

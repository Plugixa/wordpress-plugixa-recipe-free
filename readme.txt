=== Plugixa Recipe ===
Contributors: zakaria04, freemius
Tags: recipe, recipes, nutrition, food, schema
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modern recipe plugin with a React admin, multiple layouts, nutrition facts, reviews, collections, meal plans, and Recipe structured data for SEO.

== Description ==

🍳 Build beautiful, search-friendly recipe pages with **Plugixa Recipe** - a complete recipe manager with a fast React admin, four ready layouts, an FDA-style nutrition label, reviews, collections and meal plans.

📚 Read the [**Documentation**](https://www.plugixa.com/documentation/plugixa-recipe)
🌐 Visit the official website [**Plugixa**](https://www.plugixa.com/)
✉️ Questions or support requests: [**Contact us**](https://www.plugixa.com/contact)

[__Documentation__](https://www.plugixa.com/documentation/plugixa-recipe) | [__Contact__](https://www.plugixa.com/contact) | [__Support Forum__](https://wordpress.org/support/plugin/plugixa-recipe/) | [__Upgrade to Pro!__](https://www.plugixa.com/plugins/plugixa-recipe#pricing)

Publish a proper recipe in **3 simple steps**:

1. Add a recipe - title, photo, grouped ingredients and numbered instructions
2. Pick a layout and save it as a reusable **View**
3. Drop in the `[plugixa_recipe]` shortcode or the Recipe block, and publish

Every recipe is marked up with **schema.org Recipe** structured data - ingredients, times, servings, ratings and nutrition - so Google can read it for rich results.

### Four Ready Recipe Layouts

Pick a layout, save it as a View, and reuse it anywhere - no design skills needed.

✅ **Grid** - a clean, responsive card grid
✅ **Slider** - a touch-friendly carousel with autoplay and navigation
✅ **Masonry** - a Pinterest-style wall that fits cards of any height
✅ **List** - a roomy, full-width layout with larger excerpts

Every layout ships with a polished **dark mode**, switchable per shortcode or following the site.

= Key Features of Plugixa Recipe =

* Fast, Material UI-based admin for recipes, taxonomies and settings
* Unlimited recipes, categories, cuisines and courses
* Grouped ingredients and instructions, prep/cook times, servings and 11 diet flags
* 17-field FDA-style nutrition facts label
* Four layouts - grid, slider, masonry and list - with built-in dark mode
* Unlimited saved Views and saved shortcodes, with a visual shortcode builder
* Visual view editor - layout, colours, typography, spacing, images and categories
* Recipe block for Gutenberg, plus 8 widget shortcodes (categories, cuisines, latest, popular, related, search, recipe of the day, count)
* 5-star reviews with photos and admin replies
* Favourites, shopping lists, collections/cookbooks and meal plans
* Built-in servings scaler on every recipe page
* Frontend recipe submission with `[plugixa_recipe_form]`
* Optional Cloudflare Turnstile captcha on the submission and review forms
* Automatic, filterable category and cuisine archive pages
* Clean print view for every recipe
* schema.org Recipe microdata, plus Open Graph and Twitter Card meta
* Font picker with locally-served fonts - no font CDN
* Clean URLs: `/recipe/`, `/recipe-category/`, `/recipe-cuisine/`
* WPML and Polylang support, including translatable categories and cuisines
* Elementor widget
* REST API (`plugixa-recipe/v1`), action/filter hooks and template overrides

= Plugixa Recipe Pro Features =

* AI Recipe Generator - draft full recipes from a topic, a URL or a document
* Custom fields - add your own fields to the recipe form and templates
* Import and export - move recipes as CSV or JSON, and import from WP Recipe Maker
* SEO analyzer - per-recipe scoring with a site-wide dashboard
* JSON-LD Recipe schema output alongside the microdata
* One-click demo content to explore the plugin quickly
* WooCommerce integration
* Priority support

[Upgrade to Plugixa Recipe Pro](https://www.plugixa.com/plugins/plugixa-recipe#pricing)

= 🥗 FDA-Style Nutrition Facts =

Add up to 17 nutrition fields and the recipe page renders a proper FDA-style label - calories, fats, carbohydrates, protein, vitamins and minerals - with percent daily values. The same data feeds the Recipe structured data, so search engines read it too.

= ⭐ Reviews, Favourites and Collections =

Visitors can rate a recipe out of five, leave a review with photos, and mark recipes as favourites. Reviews are moderated from the admin and can be answered with an admin reply. Logged-in visitors can build collections (cookbooks), shopping lists and meal plans.

= 🔍 Recipe Schema for Google Rich Results =

Every recipe outputs schema.org Recipe microdata - ingredients, instructions, prep and cook time, servings, cuisine, category, aggregate rating and nutrition - so it is eligible for recipe rich results. Open Graph and Twitter Card tags are included for social sharing.

= 🎨 Customize Visually with Saved Views =

A View is a reusable display design: layout, columns per breakpoint, colours, typography, spacing, image handling and category filters. Save as many as you like, then reference one from any shortcode or from the block - change the View and every placement updates.

= 📝 Let Visitors Submit Their Own Recipes =

Drop `[plugixa_recipe_form]` on a page and visitors can submit recipes with photos, straight into moderation. Protect it with your own Cloudflare Turnstile keys if you want a captcha.

= 🌍 Multilingual and Translation Ready =

Plugixa Recipe works with WPML and Polylang, including translatable categories and cuisines, and ships translation-ready strings throughout.

= 🧩 Works With Gutenberg, Elementor and Page Builders =

Use the Recipe block in Gutenberg, the bundled Elementor widget, or the shortcodes in any builder, page, post or widget area.

### 👨‍💻 Author

[**Plugixa Recipe**](https://www.plugixa.com/plugins/plugixa-recipe) is developed by [**Plugixa**](https://www.plugixa.com/), building focused, well-documented WordPress plugins with an emphasis on performance, clean code and structured data.

### 🛟 Documentation & Support

Plugixa Recipe is built to be usable without a manual, but it is documented in full.

👉 Read the [Documentation](https://www.plugixa.com/documentation/plugixa-recipe) - installation, shortcode reference, layouts and Views, nutrition facts, multilingual setup and developer hooks
👉 Ask on the [WordPress.org support forum](https://wordpress.org/support/plugin/plugixa-recipe/)
👉 Or [contact us directly](https://www.plugixa.com/contact)
👉 Browse more plugins at [plugixa.com](https://www.plugixa.com/)

= Shortcode Usage =

Display recipes:
`[plugixa_recipe]`

With options (view and layout are configured via saved Views in the admin):
`[plugixa_recipe view="12" layout="grid"]`

Frontend submission form:
`[plugixa_recipe_form]`

== Installation ==

= MINIMUM REQUIREMENTS =

* WordPress 6.5 or greater
* PHP version 8.0 or greater
* MySQL version 5.7 or greater (or MariaDB 10.3 or greater)

= AUTOMATIC INSTALLATION (EASIEST WAY) =

1. In your WordPress admin, go to Plugins > Add New
2. Search for "Plugixa Recipe"
3. Click Install Now, then Activate
4. Open "Plugixa Recipe" in the admin menu and add your first recipe

= MANUAL INSTALLATION =

1. Download the plugin ZIP
2. Go to Plugins > Add New > Upload Plugin, choose the ZIP and click Install Now
   (or upload the `plugixa-recipe` folder to `/wp-content/plugins/` over FTP)
3. Activate the plugin through the Plugins menu
4. Open "Plugixa Recipe" in the admin menu to add your recipes
5. Use the `[plugixa_recipe]` shortcode or the Recipe block to display them

== Frequently Asked Questions ==

= Q. How do I add a recipe? =

After activating the plugin, open Plugixa Recipe in the WordPress admin menu and click "Add Recipe". You get grouped ingredients, numbered instructions, times, servings, diet flags and a nutrition panel.

= Q. How do I display recipes on my site? =

Use the `[plugixa_recipe]` shortcode, the Recipe block, or one of the 8 widget shortcodes on any page, post or widget area.

= Q. What is a View? =

A View is a saved, reusable display design - layout, colours, typography, spacing and filters. Reference it from a shortcode or the block, and editing the View updates every placement at once.

= Q. Does it output Recipe structured data for SEO? =

Yes. Every recipe is marked up with schema.org Recipe microdata, including aggregate ratings and nutrition information, so search engines can read it for rich results. Open Graph and Twitter Card meta tags are included too.

= Q. Is it compatible with page builders? =

Yes. The shortcodes and block work with Gutenberg and major page builders, and an Elementor widget is included.

= Q. Can visitors submit their own recipes? =

Yes. Add the `[plugixa_recipe_form]` shortcode to any page. Submissions arrive for moderation, and you can protect the form with your own Cloudflare Turnstile keys.

= Q. Does it support translations? =

Yes. Plugixa Recipe is compatible with WPML and Polylang, including translatable categories and cuisines.

= Q. Does it slow down my site? =

No. Recipe queries are served through the WordPress object cache, and the fonts used by the font picker are bundled with the plugin and served from your own server rather than a font CDN.

= Q. What is the difference between the free and Pro versions? =

The free plugin is a complete recipe manager - all four layouts, Views, nutrition facts, reviews, collections, meal plans, frontend submission and Recipe structured data. Pro adds the AI Recipe Generator, custom fields, import and export, the SEO analyzer, JSON-LD schema output, demo content and WooCommerce integration. See the [Pro features](https://www.plugixa.com/plugins/plugixa-recipe#pricing).

= Q. Where can I find the documentation? =

Full documentation - installation, shortcode reference, layouts and Views, nutrition facts, multilingual setup and developer hooks - is at https://www.plugixa.com/documentation/plugixa-recipe

== External services ==

This plugin connects to the third-party services listed below. Apart from Freemius, every connection is optional: it is made only when a site administrator turns the relevant feature on.

The only services this plugin contacts are Freemius, Cloudflare Turnstile (optional, and only with your own keys) and - if you add a video URL to a recipe - the video host's oEmbed endpoint.

= Freemius =

We use Freemius to handle licensing, software updates, and - only if you opt in - anonymous usage diagnostics. On activation you are shown an opt-in screen; if you skip it, no identifiable data is sent. When you opt in, or when you manage a license or purchase, data such as your site URL, WordPress and PHP versions, active theme/plugins, and the email address used at opt-in may be transmitted. This occurs on activation, plugin updates, and license actions.
Provided by Freemius, Inc. - Terms: https://freemius.com/terms/ - Privacy: https://freemius.com/privacy/

= Cloudflare Turnstile (optional) =

If you enable the captcha for the frontend recipe submission form and/or the review form and enter your own Turnstile site and secret keys, the plugin loads the Turnstile widget on those forms and verifies each submission with Cloudflare. The visitor's browser loads the widget script from Cloudflare, and when the visitor submits a protected form your server sends the Turnstile token and the visitor's IP address to Cloudflare to be checked. It is disabled by default and requires your own Turnstile keys; with the captcha switched off, no request is made and the script is not loaded.
Provided by Cloudflare, Inc. - Service: https://www.cloudflare.com/application-services/products/turnstile/ - Terms: https://www.cloudflare.com/website-terms/ - Privacy: https://www.cloudflare.com/privacypolicy/

= Video hosts via oEmbed (optional) =

If you add a video URL to a recipe, that recipe's page embeds the video using WordPress's built-in oEmbed. Your server requests the embed code from the host of the URL you entered, and the visitor's browser then loads the player from that host, which may set cookies. No video is embedded unless you add a video URL to a recipe, and no request is made to any video host otherwise.

The host contacted is whichever one your URL points to: the plugin passes the URL to WordPress's own oEmbed handler, so any provider on WordPress's built-in provider list can be used. In practice this is YouTube or Vimeo, whose terms and privacy policies are linked below; if you embed from another provider, that provider's own terms and privacy policy apply instead.
YouTube - Terms: https://www.youtube.com/t/terms - Privacy: https://policies.google.com/privacy
Vimeo - Terms: https://vimeo.com/terms - Privacy: https://vimeo.com/privacy
WordPress oEmbed provider list: https://developer.wordpress.org/advanced-administration/wordpress/oembed/

== Screenshots ==

1. Plugixa Recipe - beautiful recipe cards and Recipe schema for WordPress
2. Responsive grid layout - a clean card grid that turns your recipes into a scannable collection
3. Masonry layout - a Pinterest-style wall that fits recipe cards of any height into a tight flow
4. Slider carousel - touch-friendly, with autoplay and navigation for featuring your best recipes
5. Full-width list - a roomy layout with larger excerpts, for recipe blogs and long category pages
6. Built-in dark mode - every layout ships with a dark theme, per shortcode or following the site
7. Rich single-recipe pages - hero image, an at-a-glance prep and cook panel, ratings, and Print, Save and Share
8. Ingredients and instructions - grouped ingredients with a servings scaler, then numbered cooking steps
9. Photo gallery and a full FDA-style nutrition facts panel for every recipe
10. Aggregate star ratings with a per-star breakdown, plus a You May Also Like suggestions row
11. Category and cuisine archives - automatic, filterable archive pages styled to match your views
12. Recipe Manager - every recipe with its category, cuisine, difficulty, total time, rating and status
13. Design Views - reusable display designs you can reference from any shortcode
14. Visual view editor - layout, colours, typography, spacing, images and categories, per view
15. AI Recipe Generator (Pro) - draft recipes from a topic, a URL or a document
16. Import and Export (Pro) - move recipes as CSV or JSON, and import from WP Recipe Maker
17. Fully responsive on mobile, in light and dark, with big tap-friendly recipe cards
18. Single-recipe pages and swipeable sliders stay smooth all the way down to mobile

== Changelog ==

= 1.0.0 =
* Initial release
* React admin panel for recipes, categories, cuisines, and courses
* Grid, slider, masonry, and list layouts with saved views and shortcodes
* FDA-style nutrition facts label
* Reviews, favorites, collections, shopping lists, and meal plans
* Frontend recipe submission form
* schema.org Recipe microdata, Open Graph and Twitter Card meta
* WPML and Polylang multilingual support
* REST API and developer hooks

== Upgrade Notice ==

= 1.0.0 =
Initial release of Plugixa Recipe.

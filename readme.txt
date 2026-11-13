=== Plugixa Recipe – Recipe Cards, Nutrition Facts, Reviews & Schema ===
Contributors: zakaria04, freemius
Tags: recipe, recipes, recipe card, nutrition, schema
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modern recipe plugin with a React admin, multiple layouts, nutrition facts, reviews, favourites, and Recipe structured data for SEO.

== Description ==

🍳 Build beautiful, search-friendly recipe pages with **Plugixa Recipe** - a complete recipe manager with a fast React admin, four ready layouts, an FDA-style nutrition label, reviews and favourites.

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
* FDA-style nutrition facts label - 15 nutrients and a serving size
* Four layouts - grid, slider, masonry and list - with built-in dark mode
* Unlimited saved Views and saved shortcodes, with a visual shortcode builder
* Visual view editor - layout, colours, typography, spacing, images and categories
* Two Gutenberg blocks (a recipe listing and a single recipe), plus 8 widget shortcodes (categories, cuisines, latest, popular, related, search, recipe of the day, count)
* Put a whole recipe inside any post or page, with "Jump to recipe" and "Print recipe" buttons, or list several as a numbered round-up
* 5-star reviews with photos and admin replies
* Favourites, and a page for your visitors with the recipes they saved
* A servings adjuster on every recipe page that scales the ingredient amounts
* Optional Cloudflare Turnstile captcha on the review form
* Automatic, filterable category and cuisine archive pages, and a search form that filters by course, difficulty, time and diet
* Clean print view for every recipe, with a choice of what to print
* Recipe structured data (JSON-LD) for rich results, recipes in WordPress's own sitemap, plus Open Graph and Twitter Card meta
* Font picker with locally-served fonts - no font CDN
* Clean URLs: `/recipe/`, `/recipe-category/`, `/recipe-cuisine/`
* WPML and Polylang support, including translatable categories and cuisines
* Elementor widget
* REST API (`plugixa-recipe/v1`), action/filter hooks and template overrides

= Plugixa Recipe Pro Features =

* AI Recipe Generator - draft full recipes from a topic, a URL or a document
* Custom fields - add your own fields to the recipe form and templates
* Import and export - move recipes as CSV or JSON, and import from WP Recipe Maker, Tasty Recipes, Create by Mediavine, WP Delicious, Cooked, Zip Recipes and Recipe Card Blocks
* SEO analyzer - per-recipe scoring with a site-wide dashboard
* One-click demo content to explore the plugin quickly
* WooCommerce integration
* Priority support

[Upgrade to Plugixa Recipe Pro](https://www.plugixa.com/plugins/plugixa-recipe#pricing)

= 🥗 FDA-Style Nutrition Facts =

Add up to 15 nutrients and a serving size, and the recipe page renders a proper FDA-style label - calories, fats, carbohydrates, protein, vitamins and minerals - with percent daily values. The same data feeds the Recipe structured data, so search engines read it too.

= ⭐ Reviews and Favourites =

Visitors can rate a recipe out of five, leave a review with photos, and mark recipes as favourites. Reviews are moderated from the admin and can be answered with an admin reply. Logged-in visitors keep their saved recipes on their own account page.

= 🔍 Recipe Schema for Google Rich Results =

Every recipe page outputs schema.org Recipe structured data (JSON-LD) - ingredients, instructions in their sections, equipment, prep and cook time, servings, cuisine, category, diets, aggregate rating, nutrition and video - so it is eligible for recipe rich results. Listing and archive pages output a list of links to the recipes they show. Open Graph and Twitter Card tags are included for social sharing.

= 🎨 Customize Visually with Saved Views =

A View is a reusable display design: layout, columns per breakpoint, colours, typography, spacing, image handling and category filters. Save as many as you like, then reference one from any shortcode or from the block - change the View and every placement updates.

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

With options (the layout, colours and spacing come from a saved View):
`[plugixa_recipe view="12" categories="desserts" limit="6"]`

A listing saved in the shortcode builder:
`[plugixa_recipe id="3"]`

One whole recipe inside a post:
`[plugixa_single_recipe id="12"]`

A search form that filters the listing beside it:
`[plugixa_recipe_search style="expanded"]`

Your visitors' saved recipes:
`[plugixa_recipe_account]`

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

Yes. Every recipe page carries schema.org Recipe structured data (JSON-LD), including aggregate ratings and nutrition information, so search engines can read it for rich results. It can be switched off for the whole site or for a single recipe. Open Graph and Twitter Card meta tags are included too.

= Q. Is it compatible with page builders? =

Yes. The shortcodes and block work with Gutenberg and major page builders, and an Elementor widget is included.

= Q. Does it support translations? =

Yes. Plugixa Recipe is compatible with WPML and Polylang, including translatable categories and cuisines.

= Q. Does it slow down my site? =

No. Recipe queries are served through the WordPress object cache, and the fonts used by the font picker are bundled with the plugin and served from your own server rather than a font CDN.

= Q. What is the difference between the free and Pro versions? =

The free plugin is a complete recipe manager - all four layouts, Views, nutrition facts, reviews, favourites, a servings adjuster and Recipe structured data. Pro adds the AI Recipe Generator, custom fields, import and export (including from seven other recipe plugins), the SEO analyzer, collections and meal plans, shopping lists, recipe submissions from visitors, nutrition calculated from the ingredients, reader tools (unit conversion, step timers and cook mode), demo content and WooCommerce integration. See the [Pro features](https://www.plugixa.com/plugins/plugixa-recipe#pricing).

= Q. Where can I find the documentation? =

Full documentation - installation, shortcode reference, layouts and Views, nutrition facts, multilingual setup and developer hooks - is at https://www.plugixa.com/documentation/plugixa-recipe

== External services ==

This plugin connects to the third-party services listed below. Apart from Freemius, every connection is optional: it is made only when a site administrator turns the relevant feature on.

The only services this plugin contacts are Freemius, Cloudflare Turnstile (optional, and only with your own keys) and - if you add a video URL to a recipe - the video host's oEmbed endpoint.

= Freemius =

We use Freemius to handle licensing, software updates, and - only if you opt in - anonymous usage diagnostics. On activation you are shown an opt-in screen; if you skip it, no identifiable data is sent. When you opt in, or when you manage a license or purchase, data such as your site URL, WordPress and PHP versions, active theme/plugins, and the email address used at opt-in may be transmitted. This occurs on activation, plugin updates, and license actions.
Provided by Freemius, Inc. - Terms: https://freemius.com/terms/ - Privacy: https://freemius.com/privacy/

= Cloudflare Turnstile (optional) =

If you enable the captcha for the review form and enter your own Turnstile site and secret keys, the plugin loads the Turnstile widget on that form and verifies each review with Cloudflare. The visitor's browser loads the widget script from Cloudflare, and when the visitor submits a protected form your server sends the Turnstile token and the visitor's IP address to Cloudflare to be checked. It is disabled by default and requires your own Turnstile keys; with the captcha switched off, no request is made and the script is not loaded.
Provided by Cloudflare, Inc. - Service: https://www.cloudflare.com/application-services/products/turnstile/ - Terms: https://www.cloudflare.com/website-terms/ - Privacy: https://www.cloudflare.com/privacypolicy/

= Video hosts via oEmbed (optional) =

If you add a video URL to a recipe, that recipe's page embeds the video using WordPress's built-in oEmbed. Your server requests the embed code from the host of the URL you entered, and the visitor's browser then loads the player from that host, which may set cookies. No video is embedded unless you add a video URL to a recipe, and no request is made to any video host otherwise. In the recipe editor, the player is loaded from YouTube or Vimeo only when you press Preview beside the video URL.

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

= 1.4.0 =
* Changed: collections and meal plans, shopping lists, recipe submissions (the `[plugixa_recipe_form]` form and "My recipes"), the nutrition calculator and the reader tools (unit conversion, step timers, cook mode, notes) are now part of Plugixa Recipe Pro. Nothing is deleted: your collections, lists, submitted recipes and settings stay in your database and come back as they were when Pro is active. Pages that hold one of these shortcodes show nothing in the meantime.
* The servings adjuster on recipe pages, reviews, favourites, the nutrition label and the print view stay free.
* New: Settings > Notifications & uploads holds the moderation email and the largest photo a visitor may upload.
* Fixed: a category or cuisine page number past the last page answers "not found" (404) instead of an empty page that search engines could index.
* Fixed: the rating summary on a recipe page sits beside its bars on wide screens, recipe cards in listings lift on hover, and review photos respond to the pointer - these styles were written in a form the stylesheet never compiled.
* Developers: the recipe page template is built from parts in `views/single/` and the archive from `views/archive/`, so a child theme or add-on can reuse one part instead of copying the whole page.

= 1.2.0 =
* New: Recipe structured data (JSON-LD) is part of this plugin. Recipe pages describe their steps in sections, with equipment, diets and breadcrumbs; listings and archives describe a list of the recipes they show.
* New: the recipe editor is one page with a section bar. Drag ingredients and steps to reorder them, group them under headings, paste a whole list, pick units and ingredients from suggestions, add a photo, timer and tip to a step, and preview the recipe while writing it.
* New: the recipe editor keeps a draft in your browser, warns before you leave with unsaved changes, and saves with Ctrl+S (Cmd+S on a Mac).
* New: categories, cuisines and courses are searched and created right from the recipe editor; gallery photos can be reordered by dragging; a video preview loads only when you click it.
* New: the units list includes count units - piece, pinch, clove, slice, dash, can - used by the editor's suggestions and the public form.
* New: the recipe submission form has the same lists - reorder, group, paste - with units from your own list, a timer and a photo per step, and a draft kept in the visitor's browser.
* New: a recipe page shows what the editor stores - equipment, notes, tips, source, tags, course, rest time, yield, all eleven diet labels, a step's title, time and video, and an ingredient's link, "optional" mark and substitute.
* New: Reader Tools on every recipe page - scale the amounts (half, double, triple), switch between metric and US units, copy the ingredients, start a timer from a step, and follow the method in cook mode with the screen kept on. Everything runs in the visitor's browser.
* New: put a whole recipe inside a post or page - the Recipe block, the `[plugixa_single_recipe id="12"]` shortcode or the Plugixa Recipe widget for Elementor. "Jump to recipe" and "Print recipe" buttons are added above the post, and the recipe's own address then names the post as its canonical.
* New: `[plugixa_recipe_roundup ids="3,8,12"]` lists recipes as a numbered round-up, each linking to its page.
* New: pages for your visitors - `[plugixa_saved_recipes]`, `[plugixa_shopping_list]`, `[plugixa_my_submissions]`, or all three as tabs with `[plugixa_recipe_account]`. The shopping list works without logging in (it is kept in the visitor's browser) and is saved to the account of a visitor who is logged in. "Add to shopping list" on a recipe adds the ingredients as shown, minus the ones the visitor ticked.
* New: Nutrition Calculator (an app, off by default) - work out a recipe's nutrition facts from its ingredients with the USDA FoodData Central database and your own free API key. You confirm every match and every weight; the result is labelled as calculated on the recipe page.
* New: the expanded search form (`[plugixa_recipe_search style="expanded"]`) also filters by course, difficulty, total time and diet.
* New: `[plugixa_surprise_recipe]` - a link that opens a recipe picked at random.
* New: a Pin button on the recipe's photo (a plain link; nothing is loaded from Pinterest), and "My notes" on every recipe - a visitor's own notes, kept in their browser.
* New: the print page shows everything the recipe page shows (equipment, notes, tips, source, rest time, yield, course, a step's title, time and photo), prints the amounts and units the reader chose on the page, and lets the reader leave out images, notes or nutrition.
* New: App Manager. Switch the optional parts of the plugin (reviews, collections, favorites, shopping lists, nutrition, recipe submissions) on and off from one screen.
* New: the recipe search shortcode filters the listing it sits beside, and "load more" and pagination keep the search.
* New: recipes, recipe categories and cuisines are listed in WordPress's own sitemap (published recipes only, and only the archives that have one).
* New: a Courses screen (Recipes > Courses) for the course the recipe editor has always asked for.
* New: the "Success message" you write in Settings is what a visitor sees after sending a recipe.
* Security: publishing a recipe, taking a published recipe down and moderating reviews now need the publish capability (Authors and above by default). Contributors can still write drafts and submit them for review.
* Security: the colours, sizes, fonts and shadow of a View are validated when the View is saved and again when it is printed.
* Security: a shopping list can only be read or changed by the user it belongs to.
* Fixed: a recipe sent through the public form with a photo that is not an image, or one over the upload limit, is refused with the reason instead of being stored without its photo. The form states the limit your server really applies.
* Fixed: the "Helpful" button under a review works on recipe pages.
* Fixed: a recipe page no longer shows an internal word ("form") as the recipe's source.
* Fixed: with Polylang active, recipes are listed in the sitemap index again.
* Fixed: uninstalling with "delete all data" also removes the plugin's upload folder.
* Fixed: a recipe page names its own address as canonical. It named the newest blog post's.
* Fixed: the total time of a recipe counts its rest time.
* Fixed: saving a recipe is one request, and a save that would empty its ingredients or steps is refused.
* Fixed: "load more", page numbers and infinite scroll keep working on a page served from a page cache, however old the cached copy is.
* Fixed: on cached pages, loading more reviews, voting a review helpful and sending a review or a recipe no longer fail with an expired security token.
* Fixed: the "load more" button now says so when a request fails, instead of doing nothing.
* Fixed: an address for a recipe or a recipe archive that does not exist answers 404 instead of showing the blog.
* Fixed: recipe listings are styled in the Elementor editor preview.
* Fixed: "load more" is no longer shown under a listing that already fits on one page.
* Fixed: a review rating outside 1 to 5 is refused, and mistyping a form no longer counts against the hourly submission limit.
* Fixed: a saved shortcode limited to a category, cuisine or course lists only those recipes, and keeps the sort order chosen in the builder.
* Fixed: carbohydrates entered in the recipe editor are saved, and vitamins A and C are entered and shown as % daily value.
* Fixed: the "noindex drafts" switch in Settings > SEO stays on after saving.
* Fixed: the units list no longer repeats its last unit, and the author's name shows on the recipe page.
* Fixed: the favourite button on a recipe page saves the recipe; it sent the request to an address only administrators can use.
* Fixed: the rating and share bar under a recipe fits a phone screen, with the share icons in two rows of three.
* Changed: ten settings that had no effect were removed from the Settings screen.
* Changed: the plugin is now built from independent modules, each owning its own database tables.
* Changed: the plugin has one menu entry, "Plugixa Recipe"; every screen opens inside it, and links to the old screens still work.
* Changed: the Settings screen is one list of sections; a section whose app is switched off stays visible and says where to switch it on.
* Changed: "Random" is no longer offered as a sort in the shortcode builder - no listing ever applied it.
* Changed: a recipe page describes the recipe once, as JSON-LD; the microdata the templates also carried, which could disagree with it, is gone.

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

= 1.4.0 =
Collections, meal plans, shopping lists, recipe submissions, the nutrition calculator and the reader tools move to Pro. Your data is kept and returns when Pro is active. Also fixes an empty page served past the last page of an archive, and recipe page and listing styles that were never applied.

= 1.2.0 =
A large release: recipe structured data, a new recipe editor, reader tools, recipes inside posts and visitor pages. Publishing recipes and moderating reviews now need the publish capability. Recommended for every site.

= 1.0.0 =
Initial release of Plugixa Recipe.

# Hub Client

Install on each consumer site (e.g. ybc.hr). It does two things:

1. **Page sections from the hub** with shortcodes and Bricks dynamic tags — heroes, info boxes, sponsor lists, latest news — rendered on the server, cached, no query manager needed.
2. **Local copies of hub articles**, so they have their own URLs, Bricks single/archive templates, SEO plugin output and sitemap entries, and keep working if the hub is down.

## Hub content in page elements

Set **Hub URL** and **This site on the hub** under Settings → Hub client (webhook secret only needed for article copies and instant refresh).

### In Bricks: which tool where

| Where | Use |
|---|---|
| Heading, Basic Text, Button text, Image source, Link URL, any attribute | **Dynamic tags** `{hub:…}` — Bricks only resolves its own tags in these fields, not shortcodes |
| A section of HTML, a list of a variable number of items, the content with its markup | **Shortcode element** with `[hub_loop]`, `[hub]`, `[hub_image]`, `[hub_link]` |
| Rich Text | either |

When something renders empty, log in as an administrator and open the page (or the builder): a small red note in its place says why — hub unreachable, wrong slug, nothing for that language, settings missing. Visitors never see it.

### Bricks dynamic tags

In the dynamic data picker, each of your hub's placements and content types has its own group — **Hub · home-hero**, **Hub · news (content type)** … — with a ready tag per field (Title, Excerpt, Content, Image, Button text, Button link; for content types the newest item's Title, Image, Article page link, Date). The list comes from the hub, is refreshed at most hourly while you're in the builder (or with *Refresh sections now*), and is never fetched for visitors. Add `:2`, `:3` for later items and `:1:en` for a fixed language — see **Hub · tips**.

The tag format:

```
{hub:<placement>:<field>}                 {hub:home-hero:title}
{hub:<placement>:<field>:<index>}         {hub:home-blocks:title:2}
{hub:<placement>:<field>:<index>:<lang>}  {hub:home-hero:title:1:en}
{hub:type.<content type>:<field>:<index>} {hub:type.news:title:1}   newest news item
```

- **Heading** → `{hub:home-hero:title}`
- **Image** → source *Dynamic data* → `{hub:home-hero:image}`
- **Button** → text `{hub:home-hero:cta_label}`, link type *Dynamic data* → `{hub:home-hero:cta_url}`
- **Link to the local article page** → `{hub:type.news:link:1}`
- For a fixed set of cards (e.g. 3 sponsor boxes): style one card with index `:1`, duplicate it, change the index to `:2`, `:3`. For a variable number of items use `[hub_loop]` in a Shortcode element.

### Shortcodes

| Shortcode | Output |
|---|---|
| `[hub placement="home-hero" field="title"]` | one field of the first item in a placement |
| `[hub placement="home-blocks" index="2" field="excerpt"]` | the 2nd item (hub *Order*) |
| `[hub type="news" field="title"]` | newest item of a content type |
| `[hub placement="home-hero" field="title" lang="en"]` | a specific language (default: see *Which language* below) |
| `[hub … default="—"]` | text when there's nothing |
| `[hub_image placement="home-hero" size="large" class="hero-img" loading="eager"]` | a complete `<img>` with srcset and alt; sizes `thumbnail`, `medium`, `medium_large`, `large`, `full` |
| `[hub_link placement="home-hero" class="btn"]` | a complete button link: `cta_url` with `cta_label` as text |
| `[hub_link to="article" text="Pročitaj više" class="more"]` | link to this site's copy of the article; `text` is literal text or a field name (`text="title"`) |
| `[hub_loop placement="home-blocks" limit="6" wrap="div" class="grid"] … [/hub_loop]` | repeats its content for each item; inside, use the shortcodes without a placement |
| `[hub_loop type="news" limit="3" empty="Nema novosti"] … [/hub_loop]` | latest news; `empty` = text when there are none |

**Fields:** `data.<name>` (see Data from the content), `title`, `subtitle`, `badge`, `excerpt`, `html` (the content, with its markup), `image` (URL), `image_full`, `image_alt`, `image_focus`, `cta_url`, `cta_label`, `cta2_url`, `cta2_label`, `date` (add `format="j.n.Y."`), `slug`, `id`, `lang`, `content_type`, and `link` — the URL of this site's copy of the article (empty if it isn't kept as an article).

**Event fields:** `event_start`, `event_end` (date + time; `format="…"` works), `event_date`, `event_time`, `location`, `map_url` (directions link), `map_lat`, `map_lng`, `stream_url`. Also `seo_title`, `seo_description`.

### Data from the content

On the hub, write lines like `@weight: 84` or `@price-month: 30 €` in a block's content. Here:

| | |
|---|---|
| `[hub placement="players" field="data.weight"]` | `84` (`html.weight` works too) |
| `{hub:players:data.weight}` | the same in Bricks |
| `[hub_link text="data.price_month"]` | as link text |
| `{cf_hub_data_weight}` | on article copies (custom field `hub_data_<name>`) |

`-` in a name becomes `_` (`@price-month` → `data.price_month`). The lines themselves don't show in the content.

#### All (or some) data points at once: `[hub_data]`

Shows a block's data as a list — every value, or the ones you name, in your order:

| | |
|---|---|
| `[hub_data]` | all values, as `<dl>` (label + value), in the order they're written on the hub |
| `[hub_data keys="position,height,weight"]` | only these, in this order (`exclude="note"` for the opposite) |
| `[hub_data labels="height:Visina,weight:Težina"]` | own labels (default: the name, `price_month` → "Price month") |
| `[hub_data wrap="table"]` | `dl` (default), `ul`, `table`, `div` (spans), `none` |
| `[hub_data key="height"]` | just one value |
| `[hub_data keys="number,weight"]<b>{label}</b> {value}[/hub_data]` | your own markup per value (`{key}` too) |

Empty values are skipped (`show_empty="1"` keeps them), `empty="…"` is shown when there's nothing. Every item has a class `hub-data-<name>` for styling.

**Which block?** Inside a post loop (Bricks query loop, a theme loop, a single post template) it's the current post — a player, an article copy. Inside `[hub_loop]` it's the current item. Otherwise give a source like `[hub]`: `placement=`, `type=`, `tag=`, `index=`.

**A list of players, each with a row of data points:** create a *Players* post type fed by the hub type `player` (Settings → Hub client → Post types), then in Bricks:

```
Section
└─ Block  ← Query loop: Post type "Players", posts per page -1, order by title
   ├─ Image      {featured_image}
   ├─ Heading    {post_title}  (link {post_url})
   └─ Shortcode  [hub_data keys="position,number,height,weight" wrap="div"]
```

The **Element creator → Post type list** pattern builds exactly this (rows, cards or table-like rows). Without Bricks: `[hub_loop type="player"]<h3>[hub field="title"]</h3>[hub_data][/hub_loop]`. A single value in Bricks: `{cf_hub_data_weight}`.

### Tags

| Shortcode / tag | Output |
|---|---|
| `[hub_loop tag="summer-camp"] … [/hub_loop]` | items with that tag, newest first |
| `[hub_loop type="news" tag="summer-camp" limit="3"]` | a content type, only with that tag |
| `[hub_loop placement="home-cards" tag="kids"]` | a placement, only blocks with that tag (in their Order) |
| `tag="a,b"` / `tag="a+b"` | any of the tags / all of them |
| `[hub tag="kids" field="title" index="2"]` | works on every shortcode |
| `{hub:tag.summer-camp:title}` | Bricks: newest item with that tag |
| `[hub field="tags"]` | the item's tags, comma-separated |

Article copies get the hub tags as post tags (or *Article tags* with the own post type), so Bricks tag archives and query loops by tag work.

### Events, maps, second button

| Shortcode | Output |
|---|---|
| `[hub_loop type="event" upcoming="1" limit="5"] … [/hub_loop]` | upcoming events, soonest first |
| `[hub type="event" upcoming="1" field="title"]` | the next event — `upcoming="1"` works on every shortcode |
| `[hub_link to="cta2" class="btn-2"]` | second button |
| `[hub_link to="map" text="Upute"]`, `[hub_link to="stream" text="Uživo"]` | directions / live stream (open in a new tab) |
| `[hub_map type="event" upcoming="1" height="320"]` | embedded map with the pin and a directions link; OpenStreetMap (no cookies), `provider="google"` for Google, `link=""` hides the link |

Bricks: `{hub:next.event:title}`, `{hub:next.event:event_date}`, `{hub:next.event:location}`, … — `next.<type>` is the upcoming items of that type, soonest first. For the **Map** element use `{hub:next.event:map_lat}` and `{hub:next.event:map_lng}`. For focal points put `{hub:home-hero:image_focus}` into the Image element's object-position (`[hub_image]` adds it by itself).

Events that have ended drop out of `upcoming` lists by themselves, without a call to the hub: the stored list is filtered on every page view, and a local timer purges the page cache when the next one ends.

**Don't put a shortcode inside an HTML attribute with double quotes** — `href="[hub field="cta_url"]"` breaks the attribute and WordPress leaves it unprocessed. Use `[hub_link]` (or `[hub_image]`) instead, which outputs the whole tag. If you must, use single quotes inside: `href="[hub field='cta_url']"`.

Example card list (Bricks Shortcode element):

```
[hub_loop placement="home-blocks" wrap="div" class="cards"]
  <article class="card">
    [hub_image size="medium_large" class="card-img"]
    <h3>[hub field="title"]</h3>
    <p>[hub field="excerpt"]</p>
    [hub_link class="btn"]
  </article>
[/hub_loop]
```

Latest news linking to the local article pages:

```
[hub_loop type="news" limit="3" wrap="ul" class="news"]
  <li>[hub_link to="article" text="title"] <time>[hub field="date"]</time></li>
[/hub_loop]
```

### Which language

Content is written and translated **only on the hub**; pages here hold the layout. The language of hub sections:

1. `lang="…"` on the shortcode or `:1:en` on the tag — always wins;
2. a page **translated** with Polylang/WPML (it has language versions) shows its own language;
3. any other page — Settings → Hub client → **Section language**:
   - **Follow the visitor's selected language** (default): one page, hub content in the language the visitor picked. The choice comes in as `?hub_lang=en` and is remembered in the `hub_lang` cookie. Links: `[hub_lang_switch]` (`labels="hr:HR,en:EN"`), or the Polylang switcher — on untranslated pages its links are changed to stay on the same page. (Polylang's own `pll_language` cookie isn't used: Polylang resets it to the page's language on every view.)
   - **Always this site's language**.
4. the site's language: the first of **Languages to keep**, else the Polylang/WPML default.

Caching and SEO with "visitor's language": views in another language than the site's send `Cache-Control: private, no-cache` and `DONOTCACHEPAGE` (WP Rocket keeps a separate cache per `hub_lang` value; for LiteSpeed/others add the `hub_lang` cookie to their vary / no-cache cookie list). Search engines see the site language; for the other language to be indexed it needs its own URLs (translated pages in Polylang).

Polylang isn't required for this. You need it only for: text that isn't from the hub (menus, footer, texts typed in Bricks) in several languages; separate URLs per language for SEO; or keeping article copies in several languages apart (archives, sitemaps, `/en/` URLs).

### Element creator (paste into Bricks)

**Settings → Hub shortcodes → Element creator**: pick a pattern — Hero, Cards (fixed number), Cards (any number), Text block, Next event, Upcoming events, Logo row, FAQ, Post type list — and where the content comes from (placement, content type, tag or upcoming events, chosen with the pickers at the top). Set a section title, number of items, columns, light/dark and an accent colour, then **Copy for Bricks** and paste in the builder (Ctrl/Cmd+V or right-click → Paste).

The section arrives as native Bricks elements (section, container, blocks, heading, text, image, buttons) using `{hub:…}` tags, so you style and edit it in Bricks as usual. Lists of any length, maps and FAQs use a Shortcode element with `[hub_loop]` / `[hub_map]`. **Post type list** is a native Bricks query loop over one of this site's post types (e.g. Players) with each post's data points from `[hub_data]`. **Copy as shortcode** gives the same thing for other builders.

### Cheat sheet

**Settings → Hub shortcodes** (also linked from the Plugins screen): every shortcode and Bricks tag with your hub's real placements, content types and languages filled in, click-to-copy codes, ready-made hero / cards / news recipes, and the list of sections in use on this site with when each was last refreshed.

### How content stays fresh (and why it's fast)

Hub content is **stored on this site** and refreshed **only when the hub reports a change**:

- **Visitors never cause a call to the hub.** Every page view reads the local store — all sections together in one database read.
- **A webhook from the hub** refreshes only the sections that change can affect: those showing the changed item, and those whose placement or content type it belongs to. Example: editing the hero costs 3 calls (the item + its section in each language); news lists and other blocks aren't touched. Changes to other sites' content cost nothing here.
- These calls carry a unique `_v` parameter, so a Cloudflare cache in front of the hub can't return an old copy.
- **The page cache is purged** after a change (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround, Breeze, Cache Enabler, Nginx Helper; others via the `hub_client_purge_cache` action), so visitors get fully cached pages that are still current.
- **If the hub is down** during a refresh, the previous content stays and the settings screen shows how many sections failed. **Refresh sections now** (or `wp hub-client refresh`) re-fetches everything in use.
- **A brand-new section** (just added in Bricks) is loaded the first time an editor views it — this can be switched off; then it arrives with the next hub change or a manual refresh.
- **Section images are copied to this site's media library** when a section is refreshed (never during a page view), once per image. `[hub_image]` and the Bricks Image element then use this site's own sizes and srcset from its own domain, and any image optimiser or CDN on this site applies. Turn off under *Calls to the hub* to load images from the hub instead.
- Sections no page has used for 60 days are dropped from the store.
- **No timed calls by default.** The nightly article check (repairs missed webhooks) is opt-in under *Calls to the hub*.

## Local copies of hub articles

Requires Hub Content 0.4.0+ on the hub.

## Setup

1. **On this site:** install and activate Hub Client. Open **Settings → Hub client** and copy the **webhook URL** shown at the top (`https://ybc.hr/wp-json/hub-client/v1/webhook`).
2. **On the hub:** Content blocks → Target sites → this site → paste it into *Webhook URL* and save. Copy the *Webhook secret* (and the *API key* if the hub requires keys).
3. **Back here**, preferably in `wp-config.php`:
   ```php
   define('HUB_CLIENT_SECRET', '…webhook secret from the hub…');
   define('HUB_CLIENT_KEY', '…API key, only if the hub requires one…');
   ```
   (or paste them into the settings fields).
4. Fill in **Hub URL** (`https://hub.yourdomain.hr`), **This site on the hub** (`ybc`), **Content types to keep** (default `news`), **Languages**, and **Store as**. Save.
5. Click **Test connection**, then **Sync everything now** for the first import.

## What happens

| On the hub | On this site |
|---|---|
| Article published for this site | Webhook → item fetched from the hub → local post created and published |
| Article edited | Same post updated in place |
| Translation published | Its own local post; with Polylang here, linked as a translation |
| Unpublished, trashed, deleted, or this site unticked | Local post moved to the trash |
| Published again / site ticked again | The trashed post is restored, same ID and URL |
| *Show until* passed / *Show from* not yet | Local post is a draft; an hourly job publishes it when its window opens |
| Content type not in "Content types to keep" (e.g. hero blocks) | Not copied |

An optional **nightly check** (Settings → Hub client → *Calls to the hub*) pulls anything changed since the last run and trashes copies the hub no longer serves, so a missed webhook fixes itself within a day. Run it any time with **Sync articles now** or `wp hub-client sync`.

## What a local copy contains

| Field | From the hub |
|---|---|
| Title, content (rendered HTML), excerpt, slug, publish date | as on the hub |
| Featured image | downloaded once into this site's media library, with alt text |
| Category (or *Article type* with the own post type) | the hub content type, e.g. `news` |
| Custom fields `hub_cta_url`, `hub_cta_label`, `hub_canonical_site`, `hub_subtitle`, `hub_badge`, `hub_cta2_url`, `hub_cta2_label`, `hub_image_focus`, `hub_event_start`, `hub_event_end`, `hub_location`, `hub_map_lat`, `hub_map_lng`, `hub_map_url`, `hub_stream_url` | for Bricks dynamic data: `{cf_hub_subtitle}`, `{cf_hub_event_start}` … Event dates are in this site's time as `Y-m-d H:i:s`, so a Bricks query loop can sort by `hub_event_start` |
| SEO title, meta description, social image | written to Yoast / Rank Math / SEOPress fields on each sync; AIOSEO via its filters; without an SEO plugin Hub Client prints `<title>`, meta description and Open Graph tags itself. The social image is copied into this site's media library |
| Hidden `_hub_*` fields | link back to the hub item (id, language, group, modified, window) |

**Store as**
- *Posts*: articles are normal posts, content types become categories. Use your existing Bricks post templates.
- *Own post type*: "Hub articles" at `/novosti/<slug>/` (base configurable), archive at `/novosti/`, types under *Article types*. Create a Bricks single template with the condition *Post type → Hub articles*. Changing this setting after the first sync leaves the earlier copies in the other type; delete those by hand.

Edit articles **on the hub**. Local edits are overwritten on the next update; the editor shows a notice with a link to the hub item.

## Post types for this site

**Settings → Hub client → Post types**: give hub content its own post type on this site — e.g. **Turniri** at `/turniri/` fed by the hub's `event` type, **Usluge** at `/usluge/` fed by `listing`.

Per post type: name (plural/singular), URL base, archive page on/off, menu icon, and the hub content types that feed it (each hub type feeds at most one post type). Hub items of those types are kept here as that post type — with their hub content type and tags (*Article types* / *Article tags*), all hub fields as custom fields, Polylang translation links, SEO, sitemaps. Types not in any post type go where *Store as* says. Types in a post type are kept automatically, even if they aren't in *Content types to keep*.

Content is still written and translated on the hub; there is no *Add new* here. In Bricks, make Single and Archive templates with the condition *Post type → …* and use the post type in query loops.

Adding, changing or removing a post type moves existing copies (a minute later, in the background) — removing one moves its articles back to Posts / Hub articles. URLs change accordingly.

## SEO for shared articles

If the hub sets an article's **Canonical site** to another site:

- with that site's Article URL pattern set on the hub → this copy's `<link rel="canonical">` points to the original (core WordPress, Yoast, Rank Math, AIOSEO, SEOPress);
- without a pattern → this copy gets `noindex, follow`;
- either way it's left out of this site's sitemap (core sitemaps and Yoast).

Articles whose canonical site is this site, or that have none, are untouched.

## Revisions

Settings → Hub client → **Revisions**:

- **Hub article copies keep** — default **0**. Their history lives on the hub, and every hub edit rewrites the copy, so revisions here would only fill the database. `-1` = unlimited.
- **All other posts and pages keep** — empty = WordPress default (`WP_POST_REVISIONS`, usually unlimited). A value like `10` keeps the database small and still lets you go back. This setting wins over `WP_POST_REVISIONS` in `wp-config.php`.

Lowering a limit also removes existing extra revisions (oldest first) in the background a minute after saving; or click **Clean up revisions now**, or run `wp hub-client revisions [--dry-run]`. Autosaves are never deleted (they count toward the limit, as in WordPress).

## Security

- Webhooks must carry a valid `X-Hub-Signature` (HMAC-SHA256 over timestamp + body with the shared secret), a timestamp within 5 minutes, and this site's slug. Each signature is accepted once.
- The payload is only a notification: content is always fetched from the configured hub URL, never taken from the webhook body or a URL inside it.
- Content goes through WordPress's normal HTML filtering on save, so even a compromised hub can't inject scripts here.
- Hub URL must be HTTPS.
- If this site is behind Cloudflare: Bot Fight Mode off, and no rule that challenges POSTs to `/wp-json/hub-client/v1/webhook`.

## WP-CLI

```
wp hub-client test              # check connection and that this site exists on the hub
wp hub-client sync [--full]     # pull article changes (or re-check everything) and trash removed items
wp hub-client refresh           # re-fetch every page section in use
wp hub-client windows           # apply show-from / show-until now
wp hub-client revisions [--dry-run]  # remove revisions beyond the limits
```

## Notes

- SEO tested end to end: hub robots.txt, local section images (srcset, alt, one download per image, refresh on image change, fallback when off), canonical per language, noindex without a pattern, sitemap exclusion.
- Shortcodes and Bricks tags tested against a live hub: every field, index, language, loops, images, escaping, caching (one request per placement), last-good fallback with the hub down, webhook refresh, and the Bricks filters as Bricks calls them (Bricks itself was not available in the test environment).
- Tested end to end against Hub Content on WordPress 6.8: webhooks, signature/replay checks, create/update/translation/move/unpublish/restore, visibility windows, images, nightly reconcile, API keys, both storage modes, front-end URLs, Polylang linking.
- Comments and pings are closed on copies.

## Updates (Git Updater)

Hub Client updates from this GitHub repo through [Git Updater](https://git-updater.com/):

1. On each site install Git Updater (latest release from github.com/afragen/git-updater) and keep this plugin in `wp-content/plugins/hub-client`.
2. New versions then appear under **Plugins → Updates**; turn on *Enable auto-updates* for Hub Client if you like.
3. Instant updates (optional): Settings → Git Updater → **Remote Management** → copy the update URL, and add it in GitHub → Settings → Webhooks for **Releases** events (one webhook per site). Keep that key secret.

**Releasing** (from a clone of this repo):

```
bin/release.sh 0.4.1 "Short description of the change"
```

It bumps the version in `hub-client.php`, commits, tags `0.4.1` and pushes. The tag must always be higher than the last one; Git Updater compares it to the installed version.

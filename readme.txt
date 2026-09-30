=== SC Events Manager ===
Contributors: screencandy
Tags: events, calendar
Requires at least: 6.6
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.8.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A site-agnostic events calendar. Guides you through naming your own event post types before anything is registered.

== Description ==

SC Events Manager is built to be dropped into any WordPress site as-is — no build step, no Composer install. Rather
than hard-coding a single "Event" post type, it's managed from one "Events Manager" settings screen where you create
your own event types with their own singular/plural labels (e.g. "Show" / "Shows", "Gig" / "Gigs", "Class" /
"Classes") — up to 4 by default. That cap is filterable, e.g.:

    add_filter('scem_max_event_types', fn () => 8);

For each type, the underlying post type key and URL slug are locked in the moment it's created — renaming its labels
afterwards never orphans existing events or breaks their links. Types can be deleted from the screen too, but only
after typing the type's plural label to confirm — doing so moves every one of its posts to Trash (a normal 30-day
recovery window, nothing is hard-deleted) and removes any custom taxonomies attached to it.

Each event type can also have its own custom taxonomy (e.g. "Genre" for Gigs), managed from a "Taxonomies" screen
under Events Manager — capped at 1 per event type by default, also filterable:

    add_filter('scem_max_taxonomies_per_event_type', fn () => 4);

Unlike event types, taxonomies can be freely deleted — removing one just stops classifying posts, it doesn't touch
them.

Every event post type gets a hand-written set of meta boxes. The main "Event Details" box, above the content
editor, covers location (with a Leaflet + OpenStreetMap map and address search, vendored locally so there's no
runtime CDN dependency, plus indoor/outdoor and disabled access flags), date/time (single-day or date-range,
optional per-date time overrides, and simple recurrence for single-day events — daily/weekly/monthly with an end
date). Tickets/Price, Event Status, Organiser, and Restrictions (age range with a conditional "responsible adult
required" flag, dogs allowed) each get their own compact box in the sidebar. Event status is deliberately kept as its
own field rather than a custom post_status — a cancelled event is usually still a published page with a notice on
it, not an unpublished one, and post_status can't easily express both at once.

Venues are their own global post type (Events Manager → Venues), shared across every event type rather than tied to
one — a room can host a "Show" one week and a "Gig" the next. Picking a saved venue from an event's Location section
stores a live reference: editing the venue's address later updates every event using it, so venue owners never
re-enter the same details twice. An event's own manual venue fields also have a "save these details as a venue"
checkbox, so a venue can be created from an event without a separate trip to Events Manager → Venues first. Deleting
a saved venue isn't blocked — the moment before it's actually removed, its current details are copied into every
event still referencing it and the reference is cleared, so an event never ends up pointing at nothing.

"All Events Calendar" (Events Manager → Calendar) is a month-grid view across every configured event type, with
Prev/Next navigation capped to 6 months either side of today. Recurring events are expanded into individual
occurrences on the fly for whichever month is being viewed — daily, weekly (specific weekdays), and monthly (same
date, or same "nth weekday" e.g. third Thursday) are all supported, each capped at their recurrence_until date.
Clicking a date's event opens a modal with its details (venue, price, status, ticket link, edit/view links).
Navigating months re-fetches just that month's data via admin-ajax and swaps the grid client-side — no full page
reload, no Swiper or other carousel dependency, just a small JS renderer working off the same JSON shape the initial
page load already used.

The same recurrence-aware query also powers two front-end pieces. [sc_events] is a listing shortcode (also callable
directly from a theme template via scem_events_listing()/scem_the_events_listing()) merging every configured event
type by default — narrow it with type="gig,show" (comma-separated). Other attributes: range="future|past|month|week"
(default future), limit="10", venue="123" (a saved venue's post ID), taxonomy="genre" term="jazz", and
show_cancelled="true" (cancelled events are hidden by default). Which details appear on each listed event — featured
image, date/time, venue, price, status, event type, excerpt, ticket link — is controlled from Events Manager →
Listing Display, a checkbox screen storing an *ordered* list of enabled fields rather than independent booleans, so
a future drag-and-drop reorder tool can reuse the exact same data with no migration.

== Versioning ==

Every working session bumps the version at least a patch level, so the number in the plugin header always reflects
exactly what's on disk. Patch (1.1.x) covers bug fixes and small tweaks; minor (1.x.0) covers new capabilities;
major is reserved for a genuinely breaking rewrite.

== Changelog ==

= 1.8.4 =
* Sites running this plugin now get the normal "Update available" notice in wp-admin, served from this plugin's GitHub Releases.

= 1.8.3 =
* The venue map has its own on-brand green teardrop pin (`.scem-marker-pin`) instead of Leaflet's default blue one.
  `--scem-marker-colour` lets a theme rebrand it.

= 1.8.2 =
* Events have their own venue map (`scem_render_venue_map()`), drawn with this plugin's bundled Leaflet, so it no longer
  depends on SC Maps being active.

= 1.8.1 =
* Added `scem_get_event()` for single-event templates: one event's whole schedule (every date of a multi-day range,
  every future hit of a recurring rule) plus full venue detail — address, town, postcode, coordinates, indoor/outdoor
  and disabled access.

= 1.8.0 =
* Added arbitrary-month listing support to `scem_get_events()`; fixed multi-day events reverting to one-day.

= 1.7.1 =
* The "SC Events Manager" settings menu now reads "SC Events Manager" in the admin sidebar (not just the page title)
  and has moved down near the bottom of the menu, alongside SC Room Bookings and SC Maps's own settings screens —
  keeps the admin sidebar's top area for actual content (event types, posts, pages) rather than plugin settings. The
  "Event Types" menu that lists event/venue post types is unaffected — it stays at the top with other content.

= 1.7.0 =
* Ticket prices are now structured rows rather than one free-text field: each row is a ticket type (Adult, Teen,
  Child, Infant, Concession, Senior, Student, Carer, Family, Group, Member), an optional age qualifier, and either
  an amount or "Free". A free-text string couldn't be sorted, filtered or shortened, so listing cards had to print it whole —
  "£10 adults, £5 unaccompanied children, under 5 free" doesn't fit in a card.
* Ages use the from/under pair Restrictions already uses, so "under 5", "ages 5-15" and "60+" are the same two
  fields instead of an entry per boundary in the type list. The age tiers are names, not bands: a venue pricing
  two child bands adds two Child rows with different age qualifiers. Age tiers run oldest-first (Adult, Teen,
  Child, Infant) so the headline price leads and the table reads down to the free rows.
* Added a "Ticket conditions" note for the wording no dropdown can hold (e.g. "... free if accompanied by an
  adult"). Shown under the price table on the event's own page, never on a listing card.
* scem_get_events()/REST occurrences gained price_rows, price_from, price_note and ticket_notes. price is now a
  short derived string ("", "Free", "£20", "From £5") that's safe to print in a fixed-width card; anything already
  reading it keeps working.
* Added scem_get_ticket_prices() and scem_the_ticket_prices() for rendering the full breakdown on an event's page.
* Added a currency symbol setting (Events Manager -> Listing Display) rather than assuming "£".
* The Tickets box moved from the sidebar to below the editor — a price row is four controls wide and the sidebar
  column can't lay that out.
* Events saved before this release keep their price: a bare amount or "Free" migrates to a single Adult row, and
  anything wordier surfaces in the conditions note rather than being guessed at. _scem_price is still written on
  every save, holding the derived summary.

= 1.6.0 =
* Event types now nest under a single new "Event Types" top-level admin menu instead of each type getting its own —
  keeps the admin sidebar tidy as more types are added. Each type still gets exactly one link there (its own list);
  "Add New" stays one click away, from inside a type's own list. Venues are unaffected — they already nested under
  Events Manager's own settings menu.

= 1.5.0 =
* Added a stable data API for pulling events into other code: scem_get_events() (PHP) and a public, versioned REST
  endpoint (GET /wp-json/scem/v1/events — same query params as the [sc_events] shortcode). Both call the same
  underlying query as the shortcode, so results always match; the REST response omits edit_url.
* Added Events Manager -> Documentation: a reference page for all three integration points (the PHP function, the
  REST endpoint, the shortcode) plus a full occurrence-field reference pulled live from
  EventOccurrences::FIELD_DESCRIPTIONS.
* EventListingShortcode gained a query() method (raw occurrence data, no HTML) — what the two additions above call;
  render()/the shortcode itself are unchanged.

= 1.4.0 =
* Added a "Delete" action for event types on the Events Manager settings screen — previously the only way to remove
  one was directly via wp-cli/the database. Requires typing the type's plural label to confirm (like GitHub's
  delete-repo confirmation); moves every one of its posts to Trash (wp_trash_post(), not a hard delete) and removes
  any custom taxonomies scoped to it (Settings::taxonomiesForEventType() + the existing deleteTaxonomy()).
* Settings gained deleteEventType() — previously there was no method to remove an event type from settings at all.

= 1.3.0 =
* Added the [sc_events] front-end listing shortcode (+ scem_events_listing()/scem_the_events_listing() template
  functions): filter by type (comma-separated), a future/past/month/week range, venue, taxonomy/term, and a limit;
  cancelled events hidden unless show_cancelled="true" is passed.
* Added Events Manager -> Listing Display: a checkbox screen controlling which fields a listed event shows, stored
  as an ordered list so a future drag-and-drop reorder UI needs no data migration.
* EventOccurrences (the shared recurrence-expansion query behind both the calendar and the new shortcode) gained
  post_types/post_status/venue/taxonomy/status filters, all backward compatible with its existing callers.

= 1.2.0 =
* Added "All Events Calendar": a month-grid admin page across every event type, with recurrence expansion (daily/
  weekly/monthly, including "nth weekday of month"), a click-through detail modal, and AJAX-driven month navigation
  capped to 6 months either side of today.

= 1.1.0 =
* Fixed: Tickets/Price, Event Status, Organiser, and Restrictions side boxes were registering themselves on every
  post type (including Pages and Posts) whenever zero event types were configured — add_meta_box() treats an empty
  $screen array as "use the current screen" rather than "no screens".
* Fixed: the "Events Manager" settings page had no menu entry at all and was only reachable by typing its URL
  directly — the Venues post type's own "all items" link was claiming the shared parent slug first.

= 0.1.0 =
* Initial scaffold: unified settings screen for creating/renaming multiple event types (capped, filterable), dynamic post type registration per type.
* Per-event-type custom taxonomies (create/delete, capped, filterable).
* Hand-written "Event Details" meta box: location + Leaflet map search, date/time with recurrence, tickets/price, status, organiser.
* Added venue indoor/outdoor + disabled access flags, and a Restrictions section (age range, responsible adult, dogs allowed).
* Added a global Venues post type: events can link a saved venue by reference (edits propagate everywhere it's used) with a snapshot-on-delete safeguard, or keep entering one-off details manually.
* Added a "save as venue" checkbox to an event's manual venue fields.
* Split Tickets/Price, Event Status, Organiser, and Restrictions out of the main Event Details box into their own sidebar meta boxes.

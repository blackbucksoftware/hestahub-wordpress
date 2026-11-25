# The plugin ships one optional script, for the calendar

Until now the plugin shipped no JavaScript, and the contract says the calendar "renders three months server-side and pages by query parameter. No JavaScript is required." Paging that way reloads the whole page, which is slow, and the visitor loses their place.

The plugin now ships `assets/hestahub.js`. It catches clicks on the calendar's month links, fetches the same URL, and swaps every calendar on the page for the one at the same position in the response. It updates the address bar, so Back, Forward and bookmarks keep working. It's loaded wherever the stylesheet is (owned routes, posts with the shortcode), deferred in the footer, with no dependencies and no build step.

The script is **optional by design**. Without it, or when anything goes wrong (a network error, a non-2xx response such as the 503 of an outage, a response without the calendars, a theme's calendar without the two classes it needs), the link loads the page exactly as before. So the contract's "No JavaScript is required" still holds: it's a requirement on the links, not a ban on scripts.

## Considered options

- **An endpoint that returns only the calendar** (REST route or fragment URL): rejected. It would send less data, but it adds a public surface to keep compatible, and a second URL per calendar state for page caches and canonicals to deal with. Fetching the real page reuses what exists, from cached data.
- **Leaving it to the theme:** rejected. Every Tenant with a calendar has the same problem.
- **jQuery or a bundled framework:** rejected. Most sites load jQuery anyway, but the script needs nothing from it, and a build step would be new tooling for 150 lines.

## Consequences

- All calendars on a page move together, because the `hestahub_month` parameter moves all of them on a reload too.
- **A theme that overrides `calendar.php` must keep two classes** for in-place paging: `hestahub-calendar` on the outer element and `hestahub-calendar-nav` around the links. They're documented in the template and the readme. Markup is still not contract: without them, paging just reloads.
- **No automated browser tests.** The repo has no JS test runner. PHPUnit covers where the script is enqueued; the browser behaviour was checked by hand against a local page.
- The fetch sends a whole page over the network to show one part of it. At the size of these pages, that is cheaper than a second public surface.

## Status

accepted

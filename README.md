# Skemik PHP

The class `Skemik` reads the feed of a [Skemik](https://github.com/plue-gmbh/skemik)
install from a site on ordinary webspace, and writes to it, with nothing else:
no library and no build step. It is one file, `Skemik.php`, general to any
install. Copy it into the site. It needs PHP 8.0 or newer with curl, years
behind what Skemik itself asks, so a site on the host's older PHP reads the
feed as well.

Every release of Skemik is run against the class before it leaves, so no
release breaks it unnoticed.

[Start it](#start-it) · [The calls](#the-calls) ·
[How it keeps its copies](#how-it-keeps-its-copies) ·
[How to build a website](#how-to-build-a-website) ·
[Contributing](#contributing) ·
[Licence and trademark](#licence-and-trademark)

## Start it

Build it with the feed's address on one language's route, then read. A
blog is these lines, the articles newest first with their texts rendered. Read
with `render=html`, every text is HTML, a `headline` as much as a `text`, so
it is printed as read and never escaped again. Read without, a text is as
stored and is escaped where it is printed.

```php
require __DIR__ . '/Skemik.php';
$skemik = new Skemik('https://example.com/feed/de-DE');
foreach ($skemik->members($skemik->read('/articles?sort=-datePublished&render=html')) as $article) {
  echo '<article><h2>', $article['headline'], '</h2>', $article['text'] ?? '';
  echo '<p>', $skemik->word('Article', 'datePublished'), ': ', $article['datePublished'] ?? '';
  echo '</p>';
  echo '</article>';
}
```

## The calls

Each on `$skemik`:

- `read('/articles/opening')` is one document, or `null` where the address
  holds none. An address is its path after the feed's, or a full one, as a
  record's `@id` is.

- `reads(['/articles', '/events'])` is every document a page needs in one call,
  each under the address asked.

- `every('/articles?sort=-datePublished')` is a list's records to its end,
  where `read()` gives its first page: two hundred a page, the pages after the
  first asked in one call.

- `post('/messages', $document)` writes a new record with the key the class was
  built with, as Skemik's README says under
  [*Writing through the feed*](https://github.com/plue-gmbh/skemik#writing-through-the-feed),
  and it lands as a draft.
  `patch('/events/open-day', ['name' => 'Open day'])` changes the fields named.
  Each answers `['status' => 201, 'document' => …]`, the record as written or
  the refusal that names what was missing. A `post()` keeps no copy of what it
  wrote, so a contact form leaves nothing of an enquiry on the site's host. A
  `patch()` echoes the `ETag` the class last read or wrote the record under.
  Where somebody wrote in between the answer is `412`, the class drops its
  copy, and the next `read()` is the record as it stands. An asset beside its
  file is not among these.

- `new Skemik('https://example.com/feed/de-DE', $key)` reads with a key what a
  stranger cannot. A bookkeeping view is `read('/invoices')` under the blog's
  loop, on a `Skemik` built with the bookkeeping's key. Its copies are kept
  apart from the stranger's.

- `new Skemik('https://example.com/feed/de-DE', '', '/tmp/copies')` keeps its
  copies in another folder than `cache/` beside the class, which PHP must be
  able to write.

- `members($list)` is a list's records, none where the list was not read, and
  `target($edge, 'memberOf')` what an edge points at. They are the two lines of
  consumer contract from Skemik's
  [*Reading the feed*](https://github.com/plue-gmbh/skemik#reading-the-feed),
  as code. A member is its `item`.
  A `Role` or a `ProgramMembership` is opened onto its target, one inside another,
  with the edge's own words first.

- `word('Event', 'startDate')` is a field's name in the route's language, as
  `/feed/{locale}` gives it, so a page prints *Starts* without typing it.
  `words('Event')` is every field's name of the type, by its property. A field
  the catalogue names has the catalogue's name, and every other field of the
  type the profile's. So a contact form labels its fields with
  `words('Message')`, a type never public, and its email field with
  `word('Person', 'email')`, a field the install may not publish.

- `file($media)` is the address a page links a picture or a font at: its own
  copy under `media/` beside the class once one stands, and the feed's
  `contentUrl` until then. The copy is made after the page that first shows
  the file. A fourth argument to `new Skemik()` names another folder, served
  under its own name at the site's root. The class copies every picture,
  video, sound and font an install holds, a PDF and a zip. A fifth argument
  names the types copied in their place, each with the extension its copy
  gets: `['image/png' => 'png', 'font/woff2' => 'woff2']`.

- `staff($organization)` is who acts for an organisation, as an imprint names
  them: the people whose `worksFor` edge to it says a `roleName`, grouped under
  that role, and nobody whose edge says none.

- `named($address)` cuts an address to its collection and slug, since a filter
  names an edge as a document names it: `'/people?worksFor=' . named($id)`.

- `head($site, $page)` is a page's title, description, canonical address and
  other languages, as the lines of its `<head>`. It escapes what it prints, so
  it takes the site and the page as stored, read without `render=html`.
  `head($site, $article, $places)` is the same for a record's own page.
  `languages($site, $page, $places)` is the page's address in each of its
  languages, its own among them, as a language switcher lists them. A switcher
  that offers every language of the site puts each site's own address beneath:
  `$languages + array_column($skemik->versions($site), 'url', 'inLanguage')`.

- `address($site, $page)` is a page's address, `address($site, $article,
  $places)` that of a record's own page, `site($site, $page)` the site a page
  stands on, and `versions($record)` a record with its translations, by their
  language.

- `at($site, $address, $places)` is the record whose page stands at an
  address, a page or a record at its own page, as stored, or `null` where
  none stands there. It is `address()` asked the other way round.

- `sitemap($site, $places)` is the site's `sitemap.xml`: every page in every
  language at its address, and every record of the collections `$places` names
  at its own page.

- `local($document, $site, $places)` turns every link in a rendered document
  onto the site, and `destinations($addresses, $site, $places)` says where each
  address goes. A page is then
  `local(read('/web-pages/about?render=html'), $site)`.

The calls that take a site are for a site whose pages are records. What a
site is, where a page stands and where a link goes is *How to build a
website*'s. A site that only lists its articles needs none of them.

## How it keeps its copies

A copy is kept while the install's `dateModified`
stands. `changed()` asks `/feed` for the date at most once a minute, so an
install nobody writes to is read from its copies for days. Once the date has
moved, a copy is revalidated by its `ETag`. It counts as current only when
answered more than a minute after the move was seen, since a cache in between
may hold what came before for that minute, and a write in the same second moves
no date. A copy is stamped before it is fetched again, so two pages asked in
the same second refresh it once, and a feed that fails is asked again a minute
later. The last copy stands while the feed fails, an answer cut off by the
timeout counting as a failure. Where the feed answers `404`, the class keeps
that the address holds nothing, for ten minutes at most and until the date
moves, so a miss is asked once in ten minutes and a `404` sent by mistake
mends itself. Only the feed's own `404` counts, which says so in JSON. One
from a server in between is a failure, and the last copy stands. A copy not
fetched for thirty days is deleted, and read anew when next asked.
`reads()` fetches the addresses not kept, four at once. So the page waits for a
quarter of its answers in turn and not for each. Against an install on another
host the round trip is what costs, a `304` as much as a `200`. On the same host
the site and its install share one pool of PHP workers, and four at once keep a
page to five of them, however many addresses it asks. The files a page shows
are copied four at once too.

## How to build a website

This is a way to build a website on an install, so that changing the site is
changing records and not code. The class carries it out. Each thing the site
shows has one home in the feed, found by a rule a person can state. The feed
enforces none of it, and a frontend takes the rules it needs. An imprint may as
well be a page whose `text` is typed whole.

- **The site** is a `WebSite`, the one whose `url` is the address the frontend
  serves. On each route that is the address it serves that language under, so
  `web-sites?url=https://www.example.com/en` on the English route, where the
  English site states that address. An install that holds one site needs no
  `WebSite`: the home page stands in its place, the `WebPage` whose `url` is
  the address the frontend serves, `web-pages?url=…`, one per language, each a
  translation of the other. Where the class takes a site, a site is any record
  that states the site's `name` and `url`, so it takes either, as
  `head($home, $page)` and `local($document, $home)`, and reads nothing else
  of it. The second way ends where a second site begins, since a page that
  names no site belongs to whichever site reads it. A preview names the site
  it shows in its own configuration, never in the holdings, since which host
  previews what is the deployment's business and not a fact about the site.

- **A page's address** is the one it states, or else its slug after the address
  of the site it stands on. Only a home page states one: it is the site's
  `WebPage` that states the site's `url` as its own,
  `web-pages?isPartOf=web-sites/…&url=…`. Every other page's path is its slug,
  `/imprint` being `web-pages/imprint`, so two sites of one install cannot
  both have an `/imprint`. A page stands on the site it names as `isPartOf`,
  or else on the version of the site in the page's own language, so where no
  page names a site, `/en/imprint` is `web-pages/imprint` on the English
  route. A record's versions are itself and its `translationOfWork` and
  `workTranslation`, by their language. `address()`, `site()` and `versions()`
  say each.

- **The page at an address** is `at()`'s, so a frontend answers a request
  without listing its pages. It takes the site's version whose address the
  asked one begins with, the longest where two do, and reads there what the
  rest may name: the home page where nothing is left, a page by its slug, and
  a record of each collection whose place is that path or a function. What it
  read counts only where `address()` says the address asked. Most of what it
  asks holds nothing, on a page that stands as much as on an address nothing
  holds, and the class keeps that for ten minutes. A query string and
  a fragment are cut off first, and a last part that could be no slug, by the
  shape Skemik's *Writing through the feed* gives one, is never asked of the
  feed.

- **A page's head** is `head()`'s. Its title stands alone on the home page, the
  one that states the site's address, and before the site's name elsewhere.
  Where a page has other versions, it lists every one in a language the site
  stands in or that names a site of its own, itself among them,
  since a search engine trusts an alternate only where each version names
  every other. The English one is listed again as `x-default`, the version
  shown to a reader of none of these languages.

- **A page's sections** are its `hasPart`, lists in their order, each member
  drawn by its type. How a type is drawn is the frontend's: a list of terms as
  chips, an article as a card, a picture after a text inside that text's card.

- **The menu, the footer and the buttons** are the site's own. Its `hasPart`
  holds the lists: the first is the menu, and each further list is a column of
  the footer. A section in them links to its place on the home page, and a
  page links to its path. The order means something, and a reordering shows at
  once. Buttons are the site's `potentialAction`, which stand on every page,
  and a page's own, which stand on that page. A button is a label and a link,
  so `potentialAction` is written inline on any record as rows of `name` and
  `target`. It is not a record of the `Action` collection, which holds what
  somebody did. Where no `WebSite` stands, these move to records that are
  there anyway: the menu and the footer are lists the home page holds, and the
  buttons are the `potentialAction` of the `Organization` whose `url` is the
  site's address.

- **The look** is the `Brand` the site is `about`. Its colours and typefaces
  are rows of its `additionalProperty`, a `name` and a `value` each. Four names
  are our convention, `color-primary`, `color-ink`, `font-body` and
  `font-display`, so a frontend built for one organisation finds the same
  tokens at the next. None is required and none is closed. A font's value is
  the typeface's name. Its files are the woff2 `MediaObject`s that are `about`
  the brand, `media?encodingFormat=font/woff2&about=brands/…`. The look may as
  well stay in the frontend's own styles, with no `Brand` in the holdings.

- **Pictures, fonts and other files** are served from the frontend's own
  copies, which `file()` links, and from the feed's address only until a copy
  stands. A file at its `contentUrl` comes through the install's PHP, one
  worker per file per visitor. A copy is made once and never asked again, since a replaced
  file gets a new address and none changes under its old one. Where PHP runs
  as FPM it is made once the page has left. Elsewhere the visitor whose page
  first shows the file waits for it, ten seconds at most. The copy is swapped
  in whole and named by the type the feed sent the file with. Only a file of
  the install the class reads is copied, of a type the frontend lists and of
  20 MB at most. Any other stays at the feed's address. One past that size, or
  sent as a type not listed, is left alone for thirty days, and one whose
  fetch failed is tried again after fifteen minutes. A copy is served by the site's own host
  and not under the install's policy, so the site sends its SVG copies as the
  install does, `Content-Security-Policy: default-src 'none'; style-src
  'unsafe-inline'` on `media/*.svg`, or leaves `image/svg+xml` out of its
  types. A copy no page has linked for thirty days is deleted, and copied
  again should a page show its file later. So a file taken offline, which
  leaves the feed's documents, leaves the site's host within that time. A
  site that keeps its own rendered pages for longer links copies that are
  gone.

- **A page about a record**, an imprint, shows its `mainEntity`'s fields under
  the catalogue's words in the page's language, never retyped. Words such as
  *Licence* are the catalogue's names of the fields, `word()`'s, and the site
  has none of its own. For an organisation the page shows who may act for it,
  under the `roleName` their `worksFor` edge to it says, and nobody whose edge
  says none: `staff()`, which asks `people?worksFor=organizations/…`. Its
  `text` holds only what is typed nowhere else, the liability and copyright
  wording. A register entry is a row of `identifier`, a `name` and a `value`
  each, as the brand's tokens are. So the page prints *Handelsregister* above
  *HRB 784950* and never the field's word, and `?identifier=` and `?q=` find
  the value. A bare string is refused. An invoice's `identifier` stays its
  number, one string.

- **A record's own page**, an article's or a service's, is the frontend's to
  say, since the feed knows no route. It says so in `$places`, a collection's
  path after the site's address: `['articles' => 'blog/', 'services' => '']`
  puts `articles/opening` at `/blog/opening` and `services/website` at
  `/website`. Any collection may be named. `head()` and `address()` take
  `$places` for such a page. A record that is one in every language, a service
  or a person, has its page under each language's site, and `head()` lists
  them as it lists a page's versions.

  A place may be a function of the record in the path's stead, so a record
  stands under the page of another. This one puts a client's site under the
  first service it is about, and at the top where it is about none:

  ```php
  $places = ['web-sites' => function (array $site): string {
    foreach ($site['about'] ?? [] as $about) {
      if (str_contains($about['@id'], '/services/')) {
        return basename($about['@id']) . '/';
      }
    }
    return '';
  }];
  ```

  The class runs on PHP 8.0, where a constant cannot hold a function, so such
  places stand in a variable or are what a function returns.

- **A link in a text** to a record leaves the feed as the record's `@id`, so
  `local()` turns every link in a rendered document before a visitor follows
  it. A link becomes the page it names, or the site's page whose `mainEntity`
  is the record it names, `web-pages?mainEntity=…`. The pages about a record
  are asked without their site. The first that names this site is taken, or
  else the first that names none. Where no page is about the record, the link
  becomes the record's own page, and is its words alone where the frontend
  names no place for its collection. So articles and services link to each
  other as pages do, on a site with no page record at all. Every link is asked
  in one call. A page of this site becomes its path, so a preview keeps its
  visitor on the host previewed. A page of another site becomes its address. A
  visitor never lands in the feed.

- **The sitemap** is `sitemap()`'s. It lists every page of the site in every
  language the site stands in, at the address `address()` says. A page that
  states an address other than its site's, as a page shown for an address
  nothing holds may, is left out. A record of a collection `$places` names is
  listed at its own page under each language's site, a service as much as an
  article, unless a page is about it, which then stands in its place.

Two rules keep it so. **Placement lives in lists and only there.** An article a
section holds names no page, since two homes for one fact drift apart, and
`isPartOf` says which site a page belongs to and nothing else. **`about` is
what a record is about**, true wherever it stands, and a site reads it only for
its own look.

## Contributing

Pull requests are welcome, and so is an issue to report or to discuss. A
contribution is under `0BSD` like everything here.

## Licence and trademark

Everything here is under the Zero-Clause BSD licence, `0BSD`: use, change and
pass it on without restriction and without naming us. A read with a cache
pasted into a client's site is that site's. Skemik itself is under the licence
its own README names.

The name is not part of the licence. Skemik™ and its mark are plue GmbH's. Say
truthfully what something works with, for example *a site built on Skemik*.
Nothing calls itself Skemik or reads as coming from us.

---

Copyright © 2026 [plue GmbH](https://plue.tech) · Skemik™ is a trademark of plue GmbH.

# Words, by Cat

Small local tool plus static compiler for a gallery of stupid words illustrated by cats.

## What Lives Where

- `index.php`: local intake form
- `generate_caption.php`: AI caption endpoint for the form
- `includes/settings.local.php`: local private settings, not committed
- `data/letters/`: source of truth, one JSON file per letter
- `data/originals/`: downloaded source images
- `data/derived/`: main `1200x896` images
- `data/thumbs/`: square `240x240` thumbs
- `data/site/front.txt`: homepage text below the banner
- `data/site/about.html`: about page body
- `data/site/banner.jpg`: homepage header image
- `bin/compile_site.py`: static site generator
- `bin/compile`: shell wrapper for the generator
- `public/`: generated static site output

## Local Setup

1. Copy `includes/settings.local.php.example` to `includes/settings.local.php`
2. Put your OpenAI key, OpenAI project id, and prompt in that file
3. Adjust model or image sizes there if needed

The prompt and anything private belong in `includes/settings.local.php`, not in committed files.

## Running The Intake Form

From the repo root:

```bash
php -S localhost:8000
```

Open:

```text
http://localhost:8000
```

The form does this:

- word
- Flickr URL
- editable AI prompt
- editable caption
- save

On save it:

- fetches Flickr provenance
- downloads the source image
- writes the original image to `data/originals/`
- generates a `1200x896` main image into `data/derived/`
- generates a `240x240` thumb into `data/thumbs/`
- writes or overwrites the entry in `data/letters/{letter}.json`

Entries overwrite by slug within the same letter. This is deliberate.

## Editing Site Copy

Edit these files:

- homepage text below banner: `data/site/front.txt`
- about page body: `data/site/about.html`
- homepage image: `data/site/banner.jpg`

The old Jekyll `about.md` is no longer the main place to edit public copy.

## Building The Static Site

Run:

```bash
./bin/compile
```

If that ever seems stale, run the Python script directly:

```bash
python3 bin/compile_site.py
```

This writes the static site into `public/`.

## Viewing The Generated Site

Serve `public/` locally:

```bash
python3 -m http.server 8001 -d public
```

Open:

```text
http://localhost:8001
```

## Current Site Shape

- homepage: title, alphabet nav, banner, short text, random button
- letter pages: thumb grid
- entry pages: caption, main image, provenance, mini thumb strip, prev/next carets
- footer: Armadillo Sandwich link plus About link

## Notes

- This is intentionally simple.
- There is no database.
- There is no paging yet.
- The compiler is stdlib Python plus normal shell use.
- ImageMagick is required for image derivatives.

## Remaining Work

- add more real content and test crowded letter pages plus longer mini thumb strips
- set up deployment scripts
- move live hosting to a proper virtual host instead of GitHub Pages
- point the live vhost at generated `public/`
- clean out the old Jekyll-era files once they are no longer needed

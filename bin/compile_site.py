#!/usr/bin/env python3
from __future__ import annotations

import html
import json
import shutil
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
import re


ROOT = Path(__file__).resolve().parent.parent
DATA_DIR = ROOT / "data"
LETTERS_DIR = DATA_DIR / "letters"
PUBLIC_DIR = ROOT / "public"
ABOUT_SOURCE = ROOT / "about.md"
ABOUT_SITE_SOURCE = DATA_DIR / "site" / "about.html"
FRONT_TEXT_SOURCE = DATA_DIR / "site" / "front.txt"
FAVICON_SOURCE = ROOT / "favicon.ico"
SITE_TITLE = "Words, by Cat"
SITE_TAGLINE = "An alphabetical collection of stupid words illustrated by cats."
ORANGE_LETTERS = {"c", "g", "k", "m", "q", "t", "x", "z"}
LETTER_ORDER = [chr(code) for code in range(ord("a"), ord("z") + 1)]
BANNER_CANDIDATES = [
    DATA_DIR / "site" / "banner.jpg",
    DATA_DIR / "site" / "banner.jpeg",
    DATA_DIR / "site" / "banner.png",
    DATA_DIR / "site" / "banner.webp",
]


@dataclass
class Entry:
    word: str
    slug: str
    letter: str
    caption: str
    created_at: str
    updated_at: str
    source_type: str
    source: dict
    images: dict

    @property
    def page_path(self) -> str:
        return f"/{self.letter}/{self.slug}/"

    @property
    def letter_path(self) -> str:
        return f"/{self.letter}/"

    @property
    def main_public_path(self) -> str:
        return f"/images/main/{self.letter}/{self.slug}.jpg"

    @property
    def thumb_public_path(self) -> str:
        return f"/images/thumbs/{self.letter}/{self.slug}.jpg"


def main() -> None:
    letters = load_entries()
    build_public_tree(letters)


def load_entries() -> dict[str, list[Entry]]:
    letters: dict[str, list[Entry]] = {letter: [] for letter in LETTER_ORDER}

    for json_path in sorted(LETTERS_DIR.glob("*.json")):
        letter = json_path.stem.lower()
        if letter not in letters:
            continue

        rows = json.loads(json_path.read_text(encoding="utf-8"))
        if not isinstance(rows, list):
            continue

        entries: list[Entry] = []
        for row in rows:
            if not isinstance(row, dict):
                continue
            entries.append(
                Entry(
                    word=str(row.get("word", "")),
                    slug=str(row.get("slug", "")),
                    letter=str(row.get("letter", letter)).lower(),
                    caption=str(row.get("caption", "")),
                    created_at=str(row.get("created_at", "")),
                    updated_at=str(row.get("updated_at", "")),
                    source_type=str(row.get("source_type", "")),
                    source=dict(row.get("source", {})),
                    images=dict(row.get("images", {})),
                )
            )

        entries.sort(key=lambda entry: entry.word.lower())
        letters[letter] = entries

    return letters


def build_public_tree(letters: dict[str, list[Entry]]) -> None:
    if PUBLIC_DIR.exists():
        shutil.rmtree(PUBLIC_DIR)

    (PUBLIC_DIR / "assets").mkdir(parents=True, exist_ok=True)
    (PUBLIC_DIR / "images" / "main").mkdir(parents=True, exist_ok=True)
    (PUBLIC_DIR / "images" / "thumbs").mkdir(parents=True, exist_ok=True)

    write_text(PUBLIC_DIR / "assets" / "styles.css", styles_css())

    banner_public_path = copy_banner_if_present()
    copy_entry_images(letters)
    copy_favicon_if_present()
    write_text(PUBLIC_DIR / "index.html", render_home_page(letters, banner_public_path))
    write_random_page(all_entries(letters))
    write_text(PUBLIC_DIR / "about" / "index.html", render_about_page(load_about_html()))

    for letter, entries in letters.items():
        write_text(PUBLIC_DIR / letter / "index.html", render_letter_page(letter, entries))
        for index, entry in enumerate(entries):
            prev_entry = entries[index - 1] if index > 0 else None
            next_entry = entries[index + 1] if index < len(entries) - 1 else None
            write_text(
                PUBLIC_DIR / letter / entry.slug / "index.html",
                render_entry_page(entry, entries, prev_entry, next_entry),
            )


def copy_banner_if_present() -> str:
    for source in BANNER_CANDIDATES:
        if source.is_file():
            dest = PUBLIC_DIR / "assets" / source.name
            shutil.copy2(source, dest)
            return f"/assets/{source.name}"
    return ""


def copy_entry_images(letters: dict[str, list[Entry]]) -> None:
    for entry in all_entries(letters):
        main_src = ROOT / str(entry.images.get("main", {}).get("path", ""))
        thumb_src = ROOT / str(entry.images.get("thumb", {}).get("path", ""))

        main_dest = PUBLIC_DIR / "images" / "main" / entry.letter / f"{entry.slug}.jpg"
        thumb_dest = PUBLIC_DIR / "images" / "thumbs" / entry.letter / f"{entry.slug}.jpg"
        main_dest.parent.mkdir(parents=True, exist_ok=True)
        thumb_dest.parent.mkdir(parents=True, exist_ok=True)

        if main_src.is_file():
            shutil.copy2(main_src, main_dest)
        if thumb_src.is_file():
            shutil.copy2(thumb_src, thumb_dest)


def copy_favicon_if_present() -> None:
    if FAVICON_SOURCE.is_file():
        shutil.copy2(FAVICON_SOURCE, PUBLIC_DIR / "favicon.ico")


def write_random_page(entries: list[Entry]) -> None:
    targets = [entry.page_path for entry in entries]
    target_json = json.dumps(targets)
    body = f"""<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Random | {SITE_TITLE}</title>
  <meta name="robots" content="noindex">
  <script>
    const targets = {target_json};
    const target = targets.length ? targets[Math.floor(Math.random() * targets.length)] : "/";
    window.location.replace(target);
  </script>
</head>
<body></body>
</html>
"""
    write_text(PUBLIC_DIR / "random" / "index.html", body)


def render_home_page(letters: dict[str, list[Entry]], banner_public_path: str) -> str:
    latest = sorted(
        all_entries(letters),
        key=lambda entry: (entry.updated_at or entry.created_at),
        reverse=True,
    )[:8]

    banner_markup = (
        f'<figure class="hero-figure"><img class="hero-image" src="{banner_public_path}" alt="Words, by Cat banner"></figure>'
        if banner_public_path
        else '<div class="hero-fallback"><p>Stupid words, judged by cats.</p></div>'
    )

    latest_markup = "".join(render_thumb_card(entry, show_caption=False) for entry in latest)

    body = f"""
    <section class="hero panel">
      {banner_markup}
      <div class="hero-copy">
        {load_front_text()}
        <p class="home-random"><a href="/random/">Random Cat</a></p>
      </div>
    </section>
    """
    return render_layout("Words, by Cat", body, page_title=None, current_letter=None, is_home=True)


def render_about_page(about_html: str) -> str:
    body = f"""
    <section class="panel about-panel">
      <div class="section-heading">
        <h2>About</h2>
      </div>
      <div class="about-copy">
        {about_html}
      </div>
    </section>
    """
    return render_layout("About", body, page_title=None, current_letter=None, is_home=False)


def render_letter_page(letter: str, entries: list[Entry]) -> str:
    title = letter.upper()
    if entries:
        first_entry = entries[0]
        grid = "".join(render_thumb_card(entry, show_caption=True) for entry in entries)
        body = f"""
        <section class="panel">
          <div class="section-heading letter-heading">
            <h2>{escape(title)}</h2>
            <p class="meta">{len(entries)} word{'s' if len(entries) != 1 else ''}. <a href="{first_entry.page_path}">Start gallery view</a></p>
          </div>
          <div class="thumb-grid">
            {grid}
          </div>
        </section>
        """
    else:
        body = f"""
        <section class="panel">
          <div class="section-heading">
            <h2>{escape(title)}</h2>
          </div>
          <p class="meta">No cats here yet.</p>
        </section>
        """
    return render_layout(title, body, page_title=title, current_letter=letter, is_home=False)


def render_entry_page(entry: Entry, siblings: list[Entry], prev_entry: Entry | None, next_entry: Entry | None) -> str:
    mini_strip = "".join(render_mini_thumb(item, current=item.slug == entry.slug) for item in siblings)
    provenance = render_provenance(entry)
    nav_left = render_caret_link(prev_entry, direction="prev")
    nav_right = render_caret_link(next_entry, direction="next")
    prev_path = prev_entry.page_path if prev_entry else ""
    next_path = next_entry.page_path if next_entry else ""

    body = f"""
    <article class="panel entry-panel">
      <div class="entry-shell">
        <div class="entry-nav entry-nav-left">{nav_left}</div>
        <div class="entry-main" data-prev="{prev_path}" data-next="{next_path}">
          <div class="entry-caption">
            <h3 class="entry-word">{escape(entry.word)}</h3>
            <p>{escape(entry.caption)}</p>
          </div>
          <figure class="entry-figure">
            <img class="entry-image" src="{entry.main_public_path}" alt="{escape(entry.word)}">
          </figure>
          {provenance}
          <div class="mini-strip-wrap">
            <div class="mini-strip">
              {mini_strip}
            </div>
          </div>
        </div>
        <div class="entry-nav entry-nav-right">{nav_right}</div>
      </div>
    </article>
    """
    return render_layout(entry.word, body, page_title=entry.word, current_letter=entry.letter, is_home=False)


def render_layout(title: str, body: str, page_title: str | None, current_letter: str | None, is_home: bool) -> str:
    full_title = SITE_TITLE if title == SITE_TITLE else f"{title} | {SITE_TITLE}"
    nav = render_letter_nav(current_letter)
    header_class = "site-header home-header" if is_home else "site-header"
    page_header = ""
    footer = render_footer()
    behavior_script = """
<script>
(() => {
  const currentThumb = document.querySelector('.mini-thumb.is-current');
  const stripWrap = currentThumb ? currentThumb.closest('.mini-strip-wrap') : null;
  if (currentThumb && stripWrap) {
    const targetLeft = currentThumb.offsetLeft - ((stripWrap.clientWidth - currentThumb.offsetWidth) / 2);
    const maxLeft = Math.max(0, stripWrap.scrollWidth - stripWrap.clientWidth);
    stripWrap.scrollLeft = Math.max(0, Math.min(targetLeft, maxLeft));
  }

  const entryMain = document.querySelector('.entry-main[data-prev], .entry-main[data-next]');
  if (!entryMain) return;

  let startX = 0;
  let startY = 0;

  entryMain.addEventListener('touchstart', (event) => {
    const touch = event.changedTouches[0];
    if (!touch) return;
    startX = touch.clientX;
    startY = touch.clientY;
  }, { passive: true });

  entryMain.addEventListener('touchend', (event) => {
    const touch = event.changedTouches[0];
    if (!touch) return;

    const deltaX = touch.clientX - startX;
    const deltaY = touch.clientY - startY;
    const absX = Math.abs(deltaX);
    const absY = Math.abs(deltaY);

    if (absX < 60 || absX <= absY * 1.2) {
      return;
    }

    const target = deltaX < 0 ? entryMain.dataset.next : entryMain.dataset.prev;
    if (target) {
      window.location.href = target;
    }
  }, { passive: true });
})();
</script>"""
    return f"""<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{escape(full_title)}</title>
  <meta name="description" content="{escape(SITE_TAGLINE)}">
  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Comfortaa:wght@400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
  <div class="site-shell">
    <div class="site-frame panel">
    <header class="{header_class}">
      <div class="masthead">
        <h1 class="site-title"><a href="/">{escape(SITE_TITLE)}</a></h1>
      </div>
      <nav class="letter-nav" aria-label="Browse by letter">
        {nav}
      </nav>
    </header>
    <main class="site-main">
      {page_header}
      {body}
    </main>
    {footer}
    </div>
  </div>
  {behavior_script}
</body>
</html>
"""


def render_letter_nav(current_letter: str | None) -> str:
    items = []
    for letter in LETTER_ORDER:
        classes = ["letter-pill", "letter-pill-orange" if letter in ORANGE_LETTERS else "letter-pill-green"]
        if current_letter == letter:
            classes.append("is-current")
        items.append(
            f'<a class="{" ".join(classes)}" href="/{letter}/">{escape(letter.upper())}</a>'
        )
    return "".join(items)


def render_thumb_card(entry: Entry, show_caption: bool) -> str:
    caption_markup = f'<p class="thumb-caption">{escape(entry.caption)}</p>' if show_caption and entry.caption else ""
    return f"""
    <a class="thumb-card" href="{entry.page_path}">
      <img class="thumb-card-image" src="{entry.thumb_public_path}" alt="{escape(entry.word)}">
      <span class="thumb-card-title">{escape(entry.word)}</span>
      {caption_markup}
    </a>
    """


def render_mini_thumb(entry: Entry, current: bool) -> str:
    class_name = "mini-thumb is-current" if current else "mini-thumb"
    return (
        f'<a class="{class_name}" href="{entry.page_path}" aria-label="{escape(entry.word)}">'
        f'<img src="{entry.thumb_public_path}" alt="{escape(entry.word)}"></a>'
    )


def render_caret_link(entry: Entry | None, direction: str) -> str:
    if entry is None:
        return '<span class="caret-link is-empty" aria-hidden="true"></span>'
    glyph = "&lsaquo;" if direction == "prev" else "&rsaquo;"
    label = f"{'Previous' if direction == 'prev' else 'Next'}: {entry.word}"
    direction_class = f"caret-link-{direction}"
    return f'<a class="caret-link {direction_class}" href="{entry.page_path}" aria-label="{escape(label)}">{glyph}</a>'


def render_provenance(entry: Entry) -> str:
    page_url = str(entry.source.get("page_url", "")).strip()
    author = str(entry.source.get("author", "")).strip()
    license_name = str(entry.source.get("license", "")).strip()
    license_url = str(entry.source.get("license_url", "")).strip()

    pieces: list[str] = []
    if author:
        if page_url:
            pieces.append(f'Photo by <a href="{escape(page_url)}">{escape(author)}</a>')
        else:
            pieces.append(f"Photo by {escape(author)}")
    elif page_url:
        pieces.append(f'<a href="{escape(page_url)}">Source photo</a>')

    if license_name:
        license_label = display_license_label(license_name)
        license_href = license_url or (license_name if license_name.startswith("http://") or license_name.startswith("https://") else "")
        if license_url:
            pieces.append(f'<a href="{escape(license_href)}">{escape(license_label)}</a>')
        else:
            pieces.append(escape(license_label))

    if not pieces:
        return ""

    return f'<div class="provenance meta">{" / ".join(pieces)}</div>'


def render_footer() -> str:
    year = datetime.now().year
    return (
        '<footer class="site-footer">'
        f'<p class="meta">&copy; <a href="https://armadillosandwich.com">Armadillo Sandwich</a> 2009-{year} / '
        '<a href="/about/">About</a></p>'
        '</footer>'
    )


def load_about_html() -> str:
    if ABOUT_SITE_SOURCE.is_file():
        return ABOUT_SITE_SOURCE.read_text(encoding="utf-8").strip()

    if not ABOUT_SOURCE.is_file():
        return "<p>About page missing.</p>"

    raw = ABOUT_SOURCE.read_text(encoding="utf-8")
    body = strip_front_matter(raw).strip()
    if not body:
        return "<p>About page missing.</p>"

    paragraphs = [chunk.strip() for chunk in re.split(r"\n\s*\n", body) if chunk.strip()]
    html_blocks = []
    for paragraph in paragraphs:
        html_blocks.append(f"<p>{paragraph}</p>")
    return "\n".join(html_blocks)


def load_front_text() -> str:
    if FRONT_TEXT_SOURCE.is_file():
        text = FRONT_TEXT_SOURCE.read_text(encoding="utf-8").strip()
        if text:
            return text
    return SITE_TAGLINE


def strip_front_matter(raw: str) -> str:
    if raw.startswith("---"):
        parts = raw.split("---", 2)
        if len(parts) == 3:
            return parts[2]
    return raw


def display_license_label(value: str) -> str:
    text = value.strip()
    lower = text.lower()
    if "creativecommons.org/licenses/" not in lower:
        return text

    parts = [part for part in text.rstrip("/").split("/") if part]
    if len(parts) < 2:
        return text

    code = parts[-2].upper().replace("-", " ")
    version = parts[-1]
    if code == "BY":
        code = "CC BY"
    elif code == "BY SA":
        code = "CC BY-SA"
    elif code == "BY NC":
        code = "CC BY-NC"
    elif code == "BY NC SA":
        code = "CC BY-NC-SA"
    elif code == "BY ND":
        code = "CC BY-ND"
    elif code == "BY NC ND":
        code = "CC BY-NC-ND"

    return f"{code} {version}".strip()


def all_entries(letters: dict[str, list[Entry]]) -> list[Entry]:
    items: list[Entry] = []
    for entries in letters.values():
        items.extend(entries)
    return items


def write_text(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content.strip() + "\n", encoding="utf-8")


def escape(value: str) -> str:
    return html.escape(value, quote=True)


def styles_css() -> str:
    return """
:root {
  --bg: #dd9341;
  --panel: #ffffff;
  --ink: #111111;
  --line: #000000;
  --soft-line: #cfcfcf;
  --image-mat: #ffffff;
  --orange: #dd9341;
  --orange-dark: #ad6c1c;
  --orange-light: #ebb066;
  --green: #6ea931;
  --green-dark: #588b24;
  --green-light: #88be4a;
  --shadow: rgba(0, 0, 0, 0.14);
}

* {
  box-sizing: border-box;
}

html, body {
  margin: 0;
  padding: 0;
}

body {
  background: var(--bg);
  color: var(--ink);
  font: 16px/1.5 "Comfortaa", sans-serif;
}

a {
  color: var(--orange);
  text-decoration: none;
}

a:visited,
a:hover {
  color: var(--orange-dark);
}

img {
  display: block;
  max-width: 100%;
}

.site-shell {
  width: min(1260px, calc(100vw - 32px));
  margin: 24px auto 48px;
}

.panel {
  background: var(--panel);
  border-radius: 18px;
}

.site-frame {
  border: 2px solid var(--line);
  box-shadow: 6px 6px 0 var(--shadow);
}

.site-frame {
  padding: 18px 20px 20px;
}

.site-header {
  padding: 0 0 16px;
  margin-bottom: 18px;
}

.home-header {
  border-bottom: 0;
  margin-bottom: 12px;
}

.masthead {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.site-title,
.page-title,
.section-heading h2,
.thumb-card-title {
  font-weight: 700;
}

.site-title {
  margin: 0;
  font-size: clamp(1.7rem, 3vw, 2.4rem);
}

.site-title a {
  color: var(--ink);
}

.letter-nav {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 16px;
}

.letter-pill,
.random-link {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 40px;
  min-height: 40px;
  padding: 0 10px;
  border-radius: 999px;
  color: #ffffff;
  font-size: 1.18rem;
  font-weight: 700;
  box-shadow: 0 1px 4px rgba(0,0,0,0.18);
  text-shadow: 0 -1px 0 rgba(0,0,0,0.22), 0 1px 0 rgba(255,255,255,0.18);
  transition: background 120ms ease, color 120ms ease, text-shadow 120ms ease;
}

.letter-pill-green,
.random-link {
  background: linear-gradient(180deg, var(--green-light) 0%, var(--green) 55%, var(--green-dark) 100%);
}

.letter-pill-orange {
  background: linear-gradient(180deg, var(--orange-light) 0%, var(--orange) 55%, var(--orange-dark) 100%);
}

.letter-pill:visited,
.letter-pill:active,
.random-link:visited,
.random-link:active {
  color: #ffffff;
}

.letter-pill:hover,
.random-link:hover,
.letter-pill:visited:hover,
.random-link:visited:hover {
  color: #cfcfcf;
  text-shadow: none;
}

.site-main {
  margin-top: 0;
}

.page-header {
  padding: 0 4px 14px;
}

.page-title {
  margin: 0;
  color: var(--ink);
  font-size: clamp(1.35rem, 2.3vw, 1.9rem);
}

.hero {
  overflow: hidden;
}

.hero-figure {
  margin: 0;
  padding: 20px;
}

.hero-image {
  width: 100%;
  aspect-ratio: 1200 / 450;
  object-fit: cover;
  padding: 6px;
  border: 2px solid var(--line);
  border-radius: 18px;
  background: var(--image-mat);
}

.hero-fallback {
  display: grid;
  place-items: center;
  min-height: 220px;
  padding: 24px;
  background:
    radial-gradient(circle at top left, rgba(110, 169, 49, 0.24), transparent 35%),
    radial-gradient(circle at bottom right, rgba(221, 147, 65, 0.28), transparent 40%),
    #fffdf8;
  border-bottom: 1px solid var(--soft-line);
}

.hero-fallback p {
  margin: 0;
  font-size: clamp(1.6rem, 4vw, 2.5rem);
  text-align: center;
}

.hero-copy,
.section-heading,
.entry-caption,
.provenance {
  padding: 18px 20px;
}

.home-random {
  margin: 18px 0 0;
}

.home-random a,
.home-random a:visited {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 48px;
  padding: 0 18px;
  border-radius: 999px;
  color: #ffffff;
  font-weight: 700;
  background: linear-gradient(180deg, var(--green-light) 0%, var(--green) 55%, var(--green-dark) 100%);
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.4), 0 2px 6px rgba(0,0,0,0.18);
  text-shadow: 0 1px 0 rgba(0,0,0,0.18);
}

.section-heading {
  border-bottom: 1px solid var(--soft-line);
}

.letter-heading {
  padding-top: 10px;
  padding-bottom: 10px;
}

.letter-heading .meta {
  margin: 0;
}

.section-heading h2 {
  margin: 0 0 6px;
  font-size: 1.5rem;
}

.meta {
  color: #3d3d3d;
  font-size: 0.9rem;
}

.thumb-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 240px));
  justify-content: space-between;
  gap: 24px 20px;
  padding: 20px;
}

.thumb-grid-home {
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
}

.thumb-card {
  display: flex;
  flex-direction: column;
  gap: 10px;
  color: var(--ink);
}

.thumb-card:hover,
.thumb-card:visited {
  color: var(--ink);
}

.thumb-card-image {
  width: 100%;
  padding: 6px;
  border: 2px solid var(--line);
  border-radius: 14px;
  aspect-ratio: 1;
  object-fit: cover;
  background: var(--image-mat);
}

.thumb-card-title {
  font-size: 1.1rem;
}

.thumb-caption {
  margin: 0;
  color: #333333;
  font-size: 0.95rem;
}

.about-copy {
  padding: 18px 20px;
}

.about-copy p {
  margin: 0 0 16px;
}

.about-copy p:last-child {
  margin-bottom: 0;
}

.entry-panel {
  padding: 8px 0 0;
  border: 0;
  box-shadow: none;
  background: transparent;
}

.entry-shell {
  display: grid;
  grid-template-columns: minmax(32px, 48px) minmax(0, 1080px) minmax(32px, 48px);
  gap: 16px;
  align-items: start;
  justify-content: center;
}

.entry-main {
  min-width: 0;
}

.entry-figure {
  margin: 0;
}

.entry-image {
  width: 100%;
  aspect-ratio: 1200 / 896;
  object-fit: cover;
  padding: 6px;
  border: 2px solid var(--line);
  border-radius: 18px;
  background: var(--image-mat);
}

.entry-word {
  margin: 0 0 10px;
  font-size: 1.5rem;
}

.entry-caption p {
  margin: 0;
  font-size: 1.05rem;
}

.entry-caption {
  padding: 0 0 14px;
}

.provenance {
  padding: 10px 0 10px;
  text-align: right;
}

.entry-nav {
  display: flex;
  align-self: stretch;
}

.caret-link,
.caret-link:visited {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  min-height: 100%;
  padding: 12px 0;
  color: #000000;
  font-size: 4rem;
  line-height: 1;
}

.caret-link.is-empty {
  visibility: hidden;
}

.mini-strip-wrap {
  overflow-x: auto;
  padding-bottom: 2px;
}

.mini-strip {
  display: flex;
  gap: 10px;
  min-width: max-content;
}

.mini-thumb {
  display: block;
  border-radius: 12px;
}

.mini-thumb img {
  width: 96px;
  height: 96px;
  padding: 6px;
  border-radius: 12px;
  border: 2px solid var(--line);
  object-fit: cover;
  background: var(--image-mat);
}

.mini-thumb.is-current img {
  border-color: var(--green);
}

.site-footer {
  border-top: 1px solid var(--soft-line);
  margin-top: 18px;
  padding: 14px 4px 0;
}

.site-footer .meta {
  margin: 0;
}

@media (max-width: 980px) {
  .site-shell {
    width: min(100vw - 24px, 1320px);
  }

  .entry-shell {
    grid-template-columns: 1fr;
    gap: 0;
  }

  .entry-main {
    order: 1;
  }

  .entry-nav {
    display: none;
  }
}

@media (max-width: 640px) {
  .site-shell {
    width: calc(100vw - 18px);
    margin-top: 12px;
  }

  .site-frame {
    padding: 14px;
  }

  .thumb-grid,
  .hero-figure,
  .hero-copy,
  .section-heading {
    padding: 14px;
  }

  .site-header {
    padding-bottom: 14px;
    margin-bottom: 14px;
  }

  .thumb-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px 14px;
  }

  .thumb-caption {
    display: none;
  }

  .hero-image {
    aspect-ratio: 4 / 3;
    object-position: center center;
  }
}
"""


if __name__ == "__main__":
    main()

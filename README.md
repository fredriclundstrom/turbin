# Turbin

One-page website for Turbin, a creative production agency at Götgatsbacken, Stockholm.

## What's here

- `index.html` – the whole site: markup, styles and scripts in one file
- `images/web/` – case and studio photos
- `film/web/` – the case film, its poster, and the logo spin film (fallback when WebGL is unavailable)
- `assets/clients/` – client logos

The 3D logo uses three.js r147, loaded from jsDelivr. Fonts (Anton, Inter, JetBrains Mono) load from Google Fonts.

## Run locally

Open `index.html` in a browser, or serve the folder:

```
python3 -m http.server
```

then visit http://localhost:8000.

The header's black/white switch reads the pixels of the photos behind it, which only works when the
page is served (http/https), not when opened as a `file://`.

## Publish with GitHub Pages

Repository → Settings → Pages → Source: *Deploy from a branch*, branch `main`, folder `/ (root)`.

## Map data

Street map © OpenStreetMap contributors.

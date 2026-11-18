# Plugin Store promo images

Marketing images for the Bloodhound listing on the Craft Plugin Store, in the same theme as
[justinholt.com/plugins/craft-bloodhound](https://justinholt.com/plugins/craft-bloodhound).

```bash
./build.sh          # all slides
./build.sh "2 5"    # just slides 2 and 5
```

Output: `out/bloodhound-promo-N.jpg`, 1920×1080 (rendered at 2× in headless Chrome, downsampled).
**JPEG, never PNG.** `fonts.css` is generated and gitignored.

The cover and the closing slide carry the Craft 6 beta caution, and every footer says
"Craft CMS 6 (beta)" — keep it there until Craft 6 and Bloodhound leave beta.

The watermark is the hound's **outline** (`assets/watermark.svg`, the stroked `src/icon-mask.svg`
paths), not the filled head: filled, it is one large flat mass and reads as a grey blob.

| # | Slide |
| --- | --- |
| 1 | Cover — Lite free, Pro $49, Craft 6 beta caution |
| 2 | Twig or Blade, same call |
| 3 | Only live content, ever |
| 4 | Meilisearch and Typesense, set up for you |
| 5 | Index health — status and repair |
| 6 | Pro — Craft search, synonyms, zero downtime |
| 7 | Closing — Lite/Pro summary, beta caution |

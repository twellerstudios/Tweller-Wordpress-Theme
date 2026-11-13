# Christmas 2026 — ready-to-launch ad pack

Budget: about $10 USD/day → run **2 ads** in one ad set (Ad 1 + Ad 2). Use Ad 3 as a backup or swap-in.

**Website (all ads):** https://www.twellerstudios.com/book-us/christmas-photos/
**URL parameters** (Ads Manager → Ad → Tracking → URL parameters, paste as is):
```
utm_source=facebook&utm_medium=paid_social&utm_campaign=xmas-2026&utm_content={{ad.name}}
```
**Call to action button:** Book Now
**Optimise for:** Leads (the pixel's `Lead` event fires on bookings, WhatsApp and Call taps).
**Placements:** Advantage+ placements.
**Audience:** Trinidad & Tobago, **women only** for now (Ad set → Audience → Gender: Women), mobile.

---

## Ad 1 — Scene Tour (video)

| Placement | File |
|---|---|
| Reels, Stories (9:16) | `video/xmas_scene_tour_9x16.mp4` |
| Feed (4:5) | `video/xmas_scene_tour_4x5.mp4` |
| Cover image | `video/xmas_scene_tour_thumb.jpg` |

In Ads Manager, add both videos to the same ad and let Meta use the right one per placement ("Select media → customise for placements"). The music is already in the videos. `*_silent.mp4` versions have no music, in case you'd rather pick a track from Meta's Sound Collection.

**Primary text**
> Thinking about Christmas photos of you and your family? 🎄
> Choose from 4 Christmas scenes, or keep it home with your own tree. In studio or at your home.
> Christmas Minis from TTD 550. Limited spaces available. Book now!

**Headline:** Christmas Minis from TTD 550
**Description:** Limited spaces · Last shoot date Dec 24

---

## Ad 2 — Pick Your Scene (carousel)

Upload the 6 cards in order, each with its own headline:

| Card | File | Headline |
|---|---|---|
| 1 | `carousel/xmas_carousel_01.jpg` | 4 scenes to choose from |
| 2 | `carousel/xmas_carousel_02.jpg` | Fireside Library |
| 3 | `carousel/xmas_carousel_03.jpg` | Classic Red Hearth |
| 4 | `carousel/xmas_carousel_04.jpg` | Snowy Window |
| 5 | `carousel/xmas_carousel_05.jpg` | Champagne Gold |
| 6 | `carousel/xmas_carousel_06.jpg` | Christmas Minis from TTD 550 |

Card description (all cards): Book online · Limited spaces
Turn **off** "Automatically show the best performing cards first" so the hook card stays first.

**Primary text**
> Thinking about Christmas photos of you and your family? 🎄 Swipe to pick your scene.
> In studio or at your home. Christmas Minis from TTD 550. Limited spaces available.

---

## Ad 3 — Single image (backup / swap-in)

| Placement | File |
|---|---|
| Feed (4:5) | `image/xmas_image_ad_4x5_HQ.jpg` |
| Square | `image/xmas_image_ad_1x1_HQ.jpg` |
| Stories (9:16) | `image/xmas_image_ad_9x16_HQ.jpg` |
| Carousel fallback | `image/xmas_pick_scene_4x5.jpg` |

`_HQ` files are double resolution (2160 px wide); the 1080 versions are in `image/1080/`.

**Primary text**
> Thinking about Christmas photos of you and your family? Book us now! 🎄
> 4 Christmas scenes to choose from, in studio or at your home. Limited spaces available.

**Headline:** Book us now! Christmas Minis from TTD 550
**Description:** Last shoot date Dec 24

---

### Before you publish
- Preview each placement in Ads Manager (Feed, Reels, Stories) and check nothing important is covered.
- Events Manager → Test events: open the website link from the ad preview and tap WhatsApp once to see `Lead` arrive.
- Early-bird ("Book by Nov 30, extra scene free") ends Nov 30. After that, the image ad (Ad 3) still mentions it, so pause it or ask for a refreshed version.

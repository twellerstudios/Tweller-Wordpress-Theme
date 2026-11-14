# Connect Facebook & Instagram to the giveaway (one-time setup, about 20 minutes)

**What this does:** your site reads the giveaway posts directly from Meta every 10 minutes: every comment and its likes, the post's likes, comments, shares, reach, views, and your page followers. The draw, the live board and the numbers fill themselves.

**What you'll end up with:** one long-lived access token, your Facebook Page ID and your Instagram account ID, pasted into **Tweller Bookings → Giveaway: Meta**.

> Never paste the token into a chat or an email. Paste it only into the plugin's "Access token" box. It's stored on your site and never shown again.

## 1. Make a Meta app (5 min)
1. Go to **developers.facebook.com → My Apps → Create App**.
2. Pick **"Other"**, then **"Business"**. Name it **Tweller Giveaway**.
3. Connect it to your **Business portfolio** (the one that owns the Tweller Studios page and ad account).
4. Leave the app in **Development mode**. You are its admin, so it can read your own page and account without a review.

## 2. Make a system user (so the token never expires) (5 min)
1. **business.facebook.com → Business settings → Users → System users → Add.** Name it `tweller-giveaway`, role **Admin**.
2. **Add assets** to that system user:
   - **Pages:** Tweller Studios → **Full control** (or at least "Manage page content" and "View insights").
   - **Instagram accounts:** twellerstudios → Full control.
   - **Apps:** Tweller Giveaway → **Manage app**.
3. Click **Generate new token**, pick the Tweller Giveaway app, set the expiry to **Never**, and tick these permissions:
   - `pages_show_list`, `pages_read_engagement`, `pages_read_user_content`
   - `read_insights`
   - `instagram_basic`, `instagram_manage_comments`, `instagram_manage_insights`
4. Copy the token.

## 3. Your IDs
- **Facebook Page ID:** `1480134645625711` (Tweller Studios).
- **Instagram account ID:** `17841402344062882` (the twellerstudios Instagram Business account).

## 4. Plug it in
1. **Tweller Bookings → Giveaway: Meta.** Paste the token, the Page ID and the Instagram ID. **Save.**
2. Click **Test connection & list my posts**. You should see green ticks with your page name and follower counts.
   - A red ✗ shows Meta's own message. The usual ones:
     - *"(#10) … does not have permission"* → a permission wasn't ticked in step 2.3, or the system user doesn't have that asset assigned.
     - *"(#200) … requires pages_read_user_content"* → see "If Meta asks for a review" below.
3. Each week, after you post the giveaway on Facebook and Instagram, come back, click **Test connection & list my posts**, **copy the post's ID** into that week's row, and **Save**. Then click **Sync now**.

## 5. What fills in by itself
- Likes, comments, shares, people reached and video views for each post (Facebook and Instagram), added together on the dashboard.
- Every comment with its like count, so the entrants list, tags, the most-loved memory and the draw use real numbers.
- Your Facebook and Instagram follower counts, and how many you've gained since tracking began.
- The live board on the giveaway page, with a "Like it on Facebook / Instagram" link for each memory.

## If Meta asks for a review
Some comment permissions (`pages_read_user_content`, `instagram_manage_comments`) can need Meta's **Advanced access** for people who are not the app's admin. For your own page and account, with you as the app admin, they normally work in Development mode. If **Test connection** says otherwise:
- Meta's App Dashboard → **App Review → Permissions and features** → request those permissions. Meta usually asks for a short screen recording showing the giveaway dashboard.
- Until it's approved, you can keep using **Import comments** (CSV) on the Giveaway page; the numbers on the post still sync.

## TikTok
TikTok doesn't let a website read comments. Type its likes, comments, shares and views under **TikTok (typed in)** on the same page, and import its comments from a CSV on the Giveaway page.

## Good to know
- **Not truly real-time.** Meta limits how often an app can ask, so the site refreshes every 10 minutes (or on **Sync now**). The public page checks for new numbers every 30 seconds.
- **Names:** Facebook comments show first name + initial on the public board; Instagram shows the @username. You can hide any comment from the public board on the Giveaway page.
- **Votes on the website** need an email, are one per memory, three per person per week, and count as likes toward the most-loved winner (adjustable in Giveaway → Settings).

/**
 * Extracts Cloudflare Radar's bot directory into the JSON that
 * bin/import-crawlers.php expects.
 *
 * Radar has no public JSON endpoint for the directory, so this runs in the
 * browser. Paste it into the DevTools console on:
 *
 *     https://radar.cloudflare.com/bots/directory
 *
 * It loads every page, extracts each card, copies the result to the clipboard
 * and also downloads radar-crawlers.json. Then:
 *
 *     php bin/import-crawlers.php --input=radar-crawlers.json --dry-run
 *     php bin/import-crawlers.php --input=radar-crawlers.json
 *
 * Radar's utility classes are hashed and change between deploys, so every
 * selector here keys off something stable instead: the semantic `radar-*`
 * class names, and the query parameters in the operator and category links.
 */
(async () => {
  const SETTLE_MS = 600;
  const MAX_ROUNDS = 200;

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  /**
   * Cards live inside the results grid. Scoping to it keeps unrelated cards
   * elsewhere on the page (promos, related bots) out of the export; if the
   * container is ever renamed, fall back to searching the whole document.
   */
  const grids = () => document.querySelectorAll(".radar-grid");
  const cards = () => {
    const scoped = [...grids()].flatMap((grid) => [
      ...grid.querySelectorAll("article.radar-card-article"),
    ]);

    return scoped.length > 0
      ? scoped
      : [...document.querySelectorAll("article.radar-card-article")];
  };

  /** Click "load more" / "next" until the card count stops growing. */
  async function loadEverything() {
    let previous = -1;
    let rounds = 0;

    while (cards().length !== previous && rounds < MAX_ROUNDS) {
      previous = cards().length;
      rounds += 1;

      const more = [...document.querySelectorAll("button, a")].find((el) => {
        const label = (el.textContent || "").trim().toLowerCase();
        return (
          !el.hasAttribute("disabled") &&
          (label === "load more" || label === "show more" || label === "next")
        );
      });

      if (more) {
        more.click();
      } else {
        // No control found: fall back to scrolling, in case it is infinite.
        window.scrollTo(0, document.body.scrollHeight);
      }

      await sleep(SETTLE_MS);
      console.log(`… ${cards().length} cards`);
    }
  }

  const text = (el) => (el ? el.textContent.trim().replace(/\s+/g, " ") : "");

  /** Read a value out of a link's query string, e.g. ?category=AI_CRAWLER. */
  function paramFrom(card, param) {
    const link = card.querySelector(`a[href*="${param}="]`);
    if (!link) return "";
    try {
      return new URL(link.getAttribute("href"), location.origin).searchParams.get(param) || "";
    } catch {
      return "";
    }
  }

  function labelFor(card, param) {
    const link = card.querySelector(`a[href*="${param}="]`);
    return text(link && (link.querySelector(".label") || link));
  }

  function extract(card) {
    // The heading link points at the bot's own page; its slug is the stable id.
    const heading = card.querySelector('.radar-heading a[href*="/bots/directory/"]');
    const agent = text(heading && (heading.querySelector("span") || heading));
    if (!agent) return null;

    const tags = [...card.querySelectorAll(".radar-tag-list li")].map((li) => text(li));

    return {
      agent,
      slug: (heading.getAttribute("href") || "").split("/").pop(),
      operator: labelFor(card, "operator"),
      // "Training", "Search", … — the human label the importer maps to a group.
      purpose: labelFor(card, "category"),
      // "AI_CRAWLER", … — the machine code, as a fallback for the same mapping.
      category: paramFrom(card, "category"),
      description: text(card.querySelector("p.description")),
      verified: tags.some((t) => /verified/i.test(t)),
      tags,
    };
  }

  await loadEverything();

  const seen = new Set();
  const crawlers = [];

  for (const card of cards()) {
    const row = extract(card);
    if (!row) continue;

    const key = row.agent.toLowerCase();
    if (seen.has(key)) continue;

    seen.add(key);
    crawlers.push(row);
  }

  const payload = {
    source: {
      name: "Cloudflare Radar",
      url: location.href,
      retrieved_at: new Date().toISOString().slice(0, 10),
    },
    crawlers,
  };

  const json = JSON.stringify(payload, null, 2);

  const byPurpose = crawlers.reduce((acc, c) => {
    const key = c.purpose || "(none)";
    acc[key] = (acc[key] || 0) + 1;
    return acc;
  }, {});

  console.log(`Extracted ${crawlers.length} crawlers`);
  console.table(byPurpose);

  try {
    await navigator.clipboard.writeText(json);
    console.log("Copied to clipboard.");
  } catch {
    console.log("Clipboard blocked — use the downloaded file.");
  }

  const url = URL.createObjectURL(new Blob([json], { type: "application/json" }));
  const link = document.createElement("a");
  link.href = url;
  link.download = "radar-crawlers.json";
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);

  return payload;
})();

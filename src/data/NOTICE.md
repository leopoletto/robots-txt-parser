# Data notice

The files in this directory are **not covered by the MIT License** that applies
to the rest of this package.

| File | Derived from |
| --- | --- |
| `agents.source.json`, `agents/*.json` | The agent roster and categories published by [Known Agents](https://knownagents.com/agents) |
| `radar-crawlers.json` | The bot directory published by [Cloudflare Radar](https://radar.cloudflare.com/bots/directory) |
| `crawlers.json` | Crawler selection and purpose classification from Cloudflare Radar; the group prose and crawler labels are this package's own |

They hold factual identifiers only — agent names, operators, categories and
paths. The descriptive text published by those sources is not included.

These files are bundled so the package works out of the box. The author claims
no ownership of the third-party material and grants no licence to it: rights in
it remain with its sources, and reuse outside this package is subject to their
terms. To run without the bundled data, pass a `NullAgentRepository`.

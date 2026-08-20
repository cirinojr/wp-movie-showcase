# Cache Architecture

## Why this architecture exists

External API calls add latency, processing cost, rate-limit pressure, and an external availability dependency. WP Movie Showcase therefore keeps reusable data close to WordPress and only contacts OMDb when the cached representation can no longer satisfy the request.

The design combines three complementary policies:

- **Adaptive caching** determines how long frequently requested movies remain fresh.
- **Stale-while-revalidate (SWR)** returns usable data immediately and schedules eventual background revalidation.
- **Stampede protection** coordinates concurrent stale requests before they can refresh the same key.

## Cache hierarchy

```mermaid
flowchart TD
    A[Browser] --> B[Bounded in-memory suggestion cache]
    B --> C[WordPress REST API]
    C --> D[L1 request memory]
    D --> E[L2 persistent backend]
    E --> F{Entry state}
    F -->|Fresh| G[Return immediately]
    F -->|Stale| H[Return immediately]
    H --> I[Acquire scheduling lock]
    I --> J[Schedule WP-Cron refresh]
    F -->|Miss or expired| K[Fetch OMDb synchronously]
    J --> L[Re-read persistent entry]
    L -->|Fresh, missing, or expired| N[Stop]
    L -->|Stale| O[Acquire execution lock]
    O --> P[Fetch OMDb in background]
    K --> M[Validate and cache]
    P --> M
```

L2 uses the persistent WordPress object cache when one is configured. Otherwise it uses Transients. These are alternative persistent backends, not two sequential cache layers.

## Entry lifecycle

Each persistent entry is a versioned envelope:

```php
[
    'schema'      => 4,
    'type'        => 'movie',
    'value'       => $movie,
    'cached_at'   => 1700000000,
    'fresh_until' => 1700043200,
    'stale_until' => 1700129600,
]
```

- **Fresh:** return immediately and make no upstream request.
- **Stale:** return immediately and attempt to schedule one eventual background revalidation.
- **Expired:** delete the unusable entry and fetch synchronously.
- **Miss:** fetch synchronously.

Physical storage lasts until `stale_until`; this does not change the original fresh TTL.

## Preserved cache policy

| Data | Fresh window | Stale grace | Notes |
|---|---:|---:|---|
| Movie detail | 12 hours | 24 hours | Existing fresh TTL preserved |
| Hot movie detail | 7 days | 24 hours | Existing promotion behavior preserved |
| Suggestions | 6 hours | 1 hour | Existing fresh TTL preserved |
| Empty suggestions | 15 minutes | None | Existing negative TTL preserved |
| Movie not found | 15 minutes | None | Existing negative TTL preserved |

Movies are promoted after five persistent-cache hits. The hit count remains capped at 100. Promotion and hit updates keep the legacy sliding fresh-window behavior.

## Why negative entries do not use SWR

A negative response is useful briefly because it prevents repeated identical misses. Serving it stale could hide a title that OMDb adds or corrects later. Negative entries therefore remain fresh for 15 minutes and expire without a stale grace period.

## Stampede protection

The refresh lock is scoped to the hashed cache key and has a two-minute lease. With a persistent Object Cache whose backend honors atomic add-if-absent semantics, acquisition coordinates through `wp_cache_add()`. Without one, acquisition uses the uniqueness of `add_option()`, which is shared through the WordPress database across PHP processes.

The lock stores a random ownership token. With the database fallback, expired takeover is an atomic compare-and-swap: the update succeeds only when both the option name and serialized value still match the value that was read. Release is an atomic compare-and-delete using the same ownership snapshot. This prevents a delayed worker from deleting or replacing a newer owner's lock.

The portable WordPress Object Cache API can express add-if-absent but does not expose atomic compare-and-delete. Object Cache locks therefore use lease-only release: successful workers do not explicitly delete them, and the short TTL removes them safely. This avoids a check-then-delete race in which an old worker could remove a newer lease. A failed refresh deliberately leaves either backend's lease in place as a two-minute retry backoff.

When the selected backend provides the acquisition semantics described above, one refresh is scheduled per scheduling-lock lease. WordPress Cron is intentionally used because it has no required external dependency and is compatible with standard WordPress and WordPress VIP.

## Scheduling vs execution deduplication

Scheduling and execution are separate concurrency boundaries:

```text
stale request
→ scheduling lock
→ WP-Cron job

worker execution
→ re-check persistent freshness
→ execution lock
→ re-check persistent freshness
→ upstream only if still necessary
```

The scheduling lock prevents concurrent stale requests from creating the same job during one two-minute lease. It cannot prove that only one job exists forever: default WP-Cron is traffic-triggered, and execution may occur after the requested timestamp and after that lease expires.

Workers are therefore idempotent. Each job verifies that its scheduled cache key still matches the service's active API-key hash, namespace generation, and schema version. It then reads the persistent backend directly, without trusting request-local state. Fresh entries stop immediately. Missing or expired entries also stop, so an obsolete job does not recreate data removed by invalidation or normal expiry.

Only a still-stale entry proceeds to an execution lock. After acquiring ownership, the worker re-reads persistent state to close the race where another worker refreshed between the first read and lock acquisition. The OMDb request timeout is five seconds and the execution lease is two minutes. Within that lease and the atomic acquisition guarantees of the selected backend, only the owner contacts OMDb for that stale representation.

This use tolerates eventual WP-Cron execution because stale data has already been returned to the visitor. Installations requiring predictable timing may connect WordPress cron processing to a system scheduler; that is an operational option, not a plugin requirement, and WP-Cron is not treated as a durable queue.

## Failure handling

Timeouts, DNS failures, non-200 responses, rate limits, malformed JSON, and invalid payloads do not overwrite a valid stale entry. Users continue receiving stale data until `stale_until`. After that boundary, the entry is not served indefinitely: the request receives the normalized upstream error if a new fetch fails.

## Invalidation

Cache keys include the operation, normalized title or IMDb ID, cache namespace, schema version, and a truncated hash of the API key. The API key itself is never stored in plaintext in the key.

Passive invalidation is time-based: entries move from fresh to stale and then expired according to their envelope timestamps. Explicit or logical invalidation changes which entries remain usable through targeted deletion or cache-key identity changes.

Explicit and logical invalidation primitives are available at four levels:

- `invalidate_movie()` resolves a movie through any supplied title or IMDb alias, derives the canonical title and IMDb ID from the cached payload, and removes every known alias plus request-local hot metadata.
- `invalidate_search()` removes one normalized suggestion query.
- `invalidate_namespace()` increments the namespace generation for a logical full purge.
- `CACHE_SCHEMA_VERSION` makes incompatible envelopes unreachable after a schema change.

Changing the API key automatically selects a different hashed namespace.

These primitives can be invoked by consuming application code or connected to domain events, but the plugin does not implement a generic domain-event system.

## Browser cache

The frontend retains its existing bounded, navigation-local `Map` for suggestions. Its 50-query limit caps one navigation at no more than roughly 250 normalized suggestion records (five per query), while avoiding repeat REST calls without serialization or main-thread storage work. `sessionStorage` and client-side SWR were deliberately not added: the server already returns fresh or stale values quickly, and another persistence layer would add invalidation and privacy complexity for a small response-time benefit.

The browser may revalidate against WordPress, but only the server decides whether OMDb must be contacted. This prevents browser and server SWR from producing duplicate upstream requests.

Intent-based detail prefetch was also left out. Pointer and keyboard movement through a five-item list is too weak a signal to justify extra REST traffic, and selection already benefits from the server's ID cache.

## Why not localStorage or a Service Worker?

`localStorage` is synchronous, duplicates stale/invalidation policy in the browser, and persists data beyond the navigation where it is useful. A Service Worker would add lifecycle, versioning, and HTTP-cache coordination for a small autocomplete payload. The bounded `Map` gives immediate repeat-query reuse without serialization, persistent stale state, or another cache authority.

## HTTP caching

ETag and shared `Cache-Control` headers are deliberately not emitted. WordPress REST responses can pass through hosts and CDNs with different cache policies, while the authoritative fresh/stale decision depends on server-side envelope timestamps and refresh locks. Adding proxy caching here could keep responses beyond the plugin's invalidation boundary. The development-only cache status header provides observability without changing HTTP cache semantics.

## Observability

Set `WP_MOVIE_SHOWCASE_CACHE_DEBUG` to `true` (or enable `WP_DEBUG`) to log cache events and add the `X-WP-Movie-Cache` REST response header. Possible events include `MEMORY_HIT`, `CACHE_FRESH`, `CACHE_STALE`, `CACHE_MISS`, `UPSTREAM_FETCH`, `SWR_SCHEDULED`, `SWR_REFRESH`, `SWR_LOCKED`, `SWR_EXECUTION_LOCKED`, and `NEGATIVE_HIT`. No keys, API credentials, queries, or movie payloads are logged.

## Trade-offs

- Stale data may be returned for a bounded period in exchange for lower latency and resilience.
- WP-Cron is portable but does not guarantee immediate execution on sites without traffic.
- Cache envelopes consume more storage than raw values.
- Namespace invalidation leaves unreachable entries until their physical TTL expires; this avoids backend-specific wildcard deletion.
- Database option locks add a small write cost on installations without persistent object caching.

## Why not cache everything forever?

OMDb metadata can change, negative results can become valid, and permanent entries create unbounded storage and invalidation pressure. Fresh and stale windows bound how old a response may be while still allowing temporary upstream failures to be hidden. Namespace generations make broad invalidation predictable, and physical expiry eventually reclaims unreachable entries.

## Rejected alternatives

- **Action Scheduler:** stronger queue semantics are unnecessary because refresh is opportunistic and stale data has already been returned.
- **Custom lock table:** a new schema and maintenance lifecycle are disproportionate to one short mutex.
- **Redis-specific CAS commands:** they would make Redis mandatory and break the portable object-cache contract.
- **Browser persistent storage:** rejected for the synchronization and invalidation costs described above.
- **HTTP proxy caching:** rejected because it could outlive server-side namespace and stale boundaries.

## When SWR should NOT be used

SWR is inappropriate when stale information can cause an unsafe or irreversible decision, including:

- financial transaction state;
- inventory where immediate consistency is mandatory;
- security, authorization, or revocation data;
- medical or emergency information that cannot tolerate staleness;
- any workflow where the latest write must be read immediately.

Movie metadata tolerates a bounded stale window, which makes SWR an appropriate trade-off here.

## Reproducible benchmark

Run:

```bash
npm run benchmark
```

The harness applies a deterministic 50 ms delay to the mocked upstream and reports perceived request time plus OMDb call count for cold, fresh, stale, and failed-refresh scenarios. It does not claim production latency; use the same command and REST debug header in the target environment for deployment-specific measurements.

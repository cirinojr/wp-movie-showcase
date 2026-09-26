# Cache concurrency review

Scope: ownership-safe lock release and prevention of stale publication across invalidation or competing refreshes. Review includes the PHP changes, test doubles, regression tests and deployment assumptions.

## Findings corrected

| Severity | Finding | Correction and regression coverage |
| --- | --- | --- |
| High | Invalidation between the worker's final stale check and snapshot acquisition could be adopted as the new baseline, allowing the deleted entry to be recreated. | Compare the original stale envelope again inside snapshot acquisition. Both backends reproduce the failure before the fix and pass afterward; replacement by a fresh entry is covered too. |
| High | A failed SQL revision read was cast to an empty/default revision, indistinguishable from an absent option. | Inspect the database error and reject the operation. Tests verify that neither aliases nor request memory bypass this failure. |
| Medium | Coordination exceptions escaped search/refresh and bypassed the existing REST error handling. | Convert coordination failures to the existing service-unavailable `WP_Error`; explicit invalidation still reports failure through an exception. Tests cover title, IMDb and suggestion searches. |
| Medium | Successful publication discarded request-local data, introducing an extra persistent hit and changing hot-cache promotion timing. | Restore request memory only after successful guarded publication. A regression test failed before the fix and now confirms memory reuse and unchanged hit count. |

## Validation

- PHP 7.4 and PHP 8.2: 50 tests, 241 assertions, passing.
- WordPress VIP PHPCS rules: passing.
- `git diff --check`: passing.
- Deterministic benchmark: fresh/stale hits and stale after failed refresh make zero upstream calls. This uses mocked storage and does not measure database contention.
- The earlier real MySQL test verified mutex exclusion and release ownership using two independent database connections. The mutex SQL and release mechanism were not changed during this review.

## Remaining deployment validation

The site-wide publication mutex requires a single primary and a stable database connection during each critical section. Cache hits also enter the mutex because they can update hit counters. Production contention, proxies, failover/reconnection and multi-primary configurations are not certified by the unit suite. Persistent object-cache adapters must honor atomic add and forced shared reads; full WordPress integration with the deployment's actual adapter remains to be exercised. Passing PHPCS alone does not certify those runtime capabilities or WordPress VIP hosting compatibility.

The current tests establish the covered interleavings; they are not a proof of all production schedules or an unconditional guarantee of reviewer approval.

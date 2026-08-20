# Security Policy

## Reporting a vulnerability

Please report suspected vulnerabilities through GitHub's private vulnerability reporting or a private draft security advisory for this repository. Do not disclose unpatched vulnerabilities in a public issue.

Include the affected version, reproduction steps, impact, and any suggested mitigation. Avoid including real API keys, user data, or credentials in the report.

## Scope

Security-sensitive areas include OMDb credential handling, REST input validation, cache-key isolation, lock ownership, upstream response validation, and administrative settings permissions.

The public search endpoints are intentionally unauthenticated because visitors use them on the frontend. Site owners should apply host-, CDN-, or WAF-level rate controls when abuse or API-quota exhaustion is a concern; WordPress nonces are not an authorization or rate-limiting mechanism for public endpoints.

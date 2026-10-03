# 2.1 verification — 2026-10-04

Release: 2.1.0 — 2026-10-04.

Scope: `.idea/Refactor_01.md`, progress for the explicit updater. No validation
shortcuts, second record-counting pass, update decision changes, real MaxMind
requests, consuming application edits or release publication were introduced.

## RED / GREEN evidence

- Initial three progress tests failed because the observer/monotonic clock layer
  did not exist. They then passed with 20 assertions: unknown range total,
  throttling, ETA warmup/smoothing/stalls, real SDK range traversal and hash bytes.
- Seven command tests failed on the absent `--progress` option, then passed:
  early notices, Country/ASN phases, check-only phases, JSON/STDERR separation,
  quiet/no-progress precedence, sanitized failures and cancellation.
- Two download tests failed with zero reported bytes instead of six. Streaming
  sink instrumentation made them pass with known and absent Content-Length.
- A human `--no-progress` test failed on the missing total duration, then passed
  after the final summary was separated from phase reporting.
- A short non-TTY validation test failed because its final count was throttled
  away. An explicit completion snapshot now flushes the actual final count.

Additional regressions cover actual SIGINT with handler restoration, cancellation
cleanup and retained old lookup, interruption immediately after atomic rename,
no clock sampling with disabled progress, zero-size/zero-speed ETA and repeated
command sessions. The existing human-output assertion was adapted to the new
human format; existing JSON assertions remain unchanged.

## Design checks

The SDK traversal visits disjoint CIDR ranges; its counter does not count internal
trie nodes. Therefore metadata nodeCount is never a percentage denominator.
Snapshots carry enums and numeric measurements only, with no URL, credentials,
headers, response bodies or arbitrary upstream error text. Console output is
isolated from validator/transport/updater classes by a typed observer.

Time measurements use an injectable monotonic `hrtime` clock. ETA uses smoothed
sampled speed, requires at least one second and two samples, and becomes unknown
when a sample has no progress. Known completed work has zero remaining time.
The session samples at most every 250 ms; ordinary non-TTY lines are further
limited to five seconds. Phase transitions and final counts bypass throttling.
The null observer path avoids timing/snapshot allocation while retaining a cheap
cancellation check. Hashing streams chunks instead of buffering the file.

SIGINT sets a flag rather than throwing from an asynchronous signal callback.
Controlled checkpoints unwind existing reader/stream/staging/lock finally blocks.
There is no checkpoint between installation's pending marker, atomic rename and
metadata completion. A signal during that section is observed afterward; installed
data remains intact and is not reported as an overall successful command.
The command restores the previous signal handler and async setting.
PCNTL is optional; SIGKILL cleanup is not promised. Blocking calls can delay a
checkpoint until callback, return or the existing timeout. No new PHP extension
or Composer dependency was added.

## Actual test results

Every row passed **253 tests / 601 assertions**, including the actual SIGINT test,
with common PHP HTTP/DNS/socket functions disabled:

| PHP | Laravel | Pest | Guzzle / PSR-7 |
| --- | --- | --- | --- |
| 8.4.26 | 12.69.3 | 4.7.8 | 7.15.5 / 2.13.1 |
| 8.5.11 | 12.69.3 | 4.7.8 | 7.15.5 / 2.13.1 |
| 8.4.26 | 13.34.0 | 5.3.0 | 8.2.0 / 3.1.0 |
| 8.5.11 | 13.34.0 | 5.3.0 | 8.2.0 / 3.1.0 |

Laravel 12 used an isolated /tmp dependency environment with the updated source
and tests. The package working tree retained its locked Laravel 13 dependencies.
The test runner was allowed local stream descriptors outside the restrictive
sandbox; the PHP network restrictions below remained enabled.

```sh
php8.5 -d allow_url_fopen=0 \
  -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,gethostbyname,gethostbynamel,gethostbyaddr,dns_get_record \
  vendor/bin/pest --compact --colors=never
```

Focused progress plus updater tests: **48 tests / 187 assertions**. A separate
real PTY check verified cursor-based output, a newline before the interruption
summary, and absence of ANSI on a redirected output target even when STDOUT was
itself a TTY. Windows terminals and a real multi-minute production MMDB/download
were not exercised. Fake clocks and small original synthetic fixtures were used.

## Quality and distribution checks

- `composer validate --strict`: valid.
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: no errors,
  existing level 6 src scope; local worker socket permitted.
- PHP lint for src/config/tests/examples: no syntax errors.
- `git diff --check`: clean.
- Composer review archive includes new runtime progress classes and 2.1 docs,
  excludes fixtures, state, credentials, IDE prompts, vendor and development caches.
- README, updater guide and Hungarian quickstart document all output modes,
  phase-only ETA, optional interruption behavior and limits. JSON schema unchanged.

No tag, push, commit or publication was performed for this task.

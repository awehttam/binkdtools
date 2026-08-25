# binkdtools

PHP CLI tools for working with [binkd](https://github.com/pgul/binkd), the FidoNet mailer.

## Tools

### node-report.php

Bird's eye view of a specific node's session activity — successes, failures, bytes transferred, and stats.

```
php node-report.php [options] <node>

  node        FidoNet address, e.g. 1:17/227
  -f file     Path to binkd log (searches common locations if omitted)
  -n count    Limit to the most recent N sessions
  -v          Verbose: show individual file transfers per session
```

**Example output:**
```
========================================================
  Node Activity Report: 1:17/227
  Log:  /var/log/binkd/binkd.log
  From: 2024-06-01 03:12
  To:   2024-06-28 14:23
========================================================

SUMMARY
----------------------------------------
  Sessions : 42 total  (38 OK / 4 failed)
  Sent     : 12.3 MB in 85 file(s)
  Received : 8.7 MB in 62 file(s)
  Streak   : 12 consecutive OK

SESSIONS
------------------------------------------------------------------------
  Date/Time        Dir Status Sent      Received  ↑Files ↓Files
  --------------------------------------------------------------------
  06/28 14:23:01   OUT OK     1.2 MB    345.0 KB  3      2       [1m23s]
  06/27 22:15:33   IN  OK     0 B       2.1 MB    0      7       [2m01s]
  06/27 10:05:12   OUT FAIL   0 B       0 B        0      0      [5s]
```

---

### node-grep.php

Extracts and stitches together all raw log lines for a node's sessions. Identifies matching PIDs in a first pass, then collects all lines for those PIDs grouped by session.

```
php node-grep.php [options] <node>

  node        FidoNet address, e.g. 1:17/227
  -f file     Path to binkd log (searches common locations if omitted)
  -n count    Show only the most recent N sessions
  -r          Raw mode: no session headers, just the log lines
```

**Example output:**
```
=== [1/3] PID 12345   | OUT | 1:17/227     | 2024-06-28 14:23:01 | OK ===
+ 28 Jun 14:23:01 [12345] call to 1:17/227
+ 28 Jun 14:23:05 [12345] outgoing session with bbs.example.com [1.2.3.4]
+ 28 Jun 14:23:05 [12345] addr: 1:17/227@fidonet
+ 28 Jun 14:23:06 [12345] sent: netmail.su0 (1234, 456.78 CPS, 1:17/227)
+ 28 Jun 14:23:07 [12345] done (to 1:17/227, OK, S/R: 1/0 (1234/0 bytes))
```

Raw mode (`-r`) is useful for piping: `php node-grep.php -r -f binkd.log 1:17/227 | grep sent`

---

### node-lastseen.php

Reports the last-seen date/time for every node across binkd.log and its rotated/gzipped predecessors. Since binkd's log lines omit the year, rotated logs are stitched together oldest-first and the year is reconstructed by watching the month counter wrap backwards (Dec -> Jan).

"Seen" requires confirmed contact (an `addr:` or `done (...)` line). A bare `call to <addr>` is logged before the connection attempt even completes, so a down node binkd keeps dialing won't look freshly "seen" on every failed retry — those dial-only attempts are tracked separately and shown in the **Last Attempt** column instead.

```
php node-lastseen.php [options]

  -f file     Log file to include (repeatable), newest-first. Overrides default search.
  -d dir      Directory to search for default log files in (default: .)
  -n count    Limit output to N nodes
  -s          Sort alphabetically by node address (default: most recent first)
```

By default, searches for `binkd.log` and rotated predecessors `binkd.log.0` through `binkd.log.60` (and `.gz` variants of each) in the standard binkd log locations.

**Example output:**
```
============================================================
  Node Last-Seen Report
  Logs: binkd.log, binkd.log.0, binkd.log.1, binkd.log.2.gz
============================================================

  Node            Last Seen           Dir Status Sessions Last Attempt
  ------------------------------------------------------------------------------
  1:17/227        2024-06-28 14:23:01 OUT OK     42       -
  1:18/100        2024-06-20 09:12:44 IN  FAIL   3        -
  1:19/50         never               -   -      0        2024-06-27 03:00:01

  3 node(s)
```

## Log format

binkd writes log lines in this format (see `tools.c:vLog`):

```
<mark> <DD> <Mon> <HH:MM:SS> [<PID>] <message>
```

| Mark | Level | Meaning |
|------|-------|---------|
| `!`  | 0     | Fatal   |
| `?`  | 1     | Error   |
| `+`  | 2     | Info    |
| `-`  | 3     | Verbose |
| ` `  | 4+    | Debug   |

Sessions are identified by PID. All lines belonging to the same session share a PID.

## Requirements

- PHP 8.0+ (CLI)
- PHP zlib extension (for reading `.gz` rotated logs, used by `node-lastseen.php`)
- A binkd log file

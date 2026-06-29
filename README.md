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
- A binkd log file

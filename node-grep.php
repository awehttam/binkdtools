#!/usr/bin/env php
<?php
/**
 * node-grep.php — Extract and stitch together all binkd log lines for a node's sessions.
 *
 * Identifies every PID that had a session with the target node, then outputs
 * all log lines for those PIDs grouped by session.
 *
 * Usage: php node-grep.php [-f logfile] [-n count] [-r] <node>
 */

declare(strict_types=1);

function usage(string $prog): never {
    echo <<<USAGE
Usage: php $prog [options] <node>

  node        FidoNet address, e.g. 1:17/227
  -f file     Path to binkd log (searches common locations if omitted)
  -n count    Show only the most recent N sessions
  -r          Raw mode: no session headers, just the log lines

USAGE;
    exit(1);
}

function normalizeAddr(string $s): string {
    $s = preg_replace('/@\S+/', '', trim($s));
    $s = preg_replace('/\.0$/', '', $s);
    return strtolower($s);
}

function addrMatches(string $logAddr, string $targetNorm): bool {
    return normalizeAddr(rtrim($logAddr, ',')) === $targetNorm;
}

/** Parse a binkd log line. Returns null if it doesn't match the format. */
function parseLine(string $line): ?array {
    // Format (tools.c:336): <mark> <DD> <Mon> <HH:MM:SS> [<PID>] <message>
    if (!preg_match(
        '/^([!?+\- ]) (\d{2}) ([A-Za-z]{3}) (\d{2}:\d{2}:\d{2}) \[(\d+)\] (.*)$/',
        $line, $m
    )) {
        return null;
    }
    return [
        'mark' => $m[1],
        'day'  => (int)$m[2],
        'mon'  => $m[3],
        'time' => $m[4],
        'pid'  => (int)$m[5],
        'msg'  => $m[6],
        'raw'  => rtrim($line),
    ];
}

function toTimestamp(array $p, int $year): int {
    static $months = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,
                      'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
    [$h, $m, $s] = explode(':', $p['time']);
    $mon = $months[strtolower($p['mon'])] ?? 1;
    return mktime((int)$h, (int)$m, (int)$s, $mon, $p['day'], $year);
}

/** Check whether a parsed line's message involves the target node. */
function lineInvolvesNode(array $p, string $targetNorm): bool {
    $msg = $p['msg'];
    // "call to <addr>"  (client.c:362)
    if (preg_match('/^call to (\S+)$/', $msg, $m))
        return addrMatches($m[1], $targetNorm);
    // "addr: <addr>"  (protocol.c:1351)
    if (preg_match('/^addr: (\S+)/', $msg, $m))
        return addrMatches($m[1], $targetNorm);
    // "done (to|from <addr>, ...)"  (protocol.c:3047)
    if (preg_match('/^done \((?:(?:to|from) )?([^\s,]+),/', $msg, $m))
        return addrMatches($m[1], $targetNorm);
    return false;
}

// ---- Argument parsing -------------------------------------------------------

$opts = getopt('f:n:rh', ['help'], $restIdx);
if (isset($opts['h']) || isset($opts['help'])) usage($argv[0]);

$positional = array_slice($argv, $restIdx);
if (empty($positional)) usage($argv[0]);

$targetRaw  = $positional[0];
$targetNorm = normalizeAddr($targetRaw);
$limit      = isset($opts['n']) ? max(1, (int)$opts['n']) : 0;
$rawMode    = isset($opts['r']);

$logFile = $opts['f'] ?? null;
if ($logFile === null) {
    foreach (['/var/log/binkd/binkd.log', '/var/log/binkd.log', 'binkd.log'] as $c) {
        if (file_exists($c)) { $logFile = $c; break; }
    }
}
if (!$logFile || !is_readable($logFile)) {
    fwrite(STDERR, "Error: cannot find or read binkd log. Use -f <logfile>\n");
    exit(1);
}

$year = (int)date('Y');

// ---- Pass 1: identify matching PIDs -----------------------------------------
// Also collect lightweight session metadata (start time, direction, status, addr).

$pidMeta = [];   // pid => [start, direction, status, node_addr]

$fh = fopen($logFile, 'r');
if (!$fh) { fwrite(STDERR, "Error: cannot open $logFile\n"); exit(1); }

while (($line = fgets($fh)) !== false) {
    $p = parseLine($line);
    if ($p === null) continue;

    $pid = $p['pid'];
    $msg = $p['msg'];
    $ts  = toTimestamp($p, $year);

    if (!isset($pidMeta[$pid])) {
        $pidMeta[$pid] = ['start' => $ts, 'direction' => null, 'status' => null, 'node_addr' => null];
    }

    $meta = &$pidMeta[$pid];

    if (lineInvolvesNode($p, $targetNorm)) {
        $meta['involves'] = true;
    }

    // Capture direction and status for session headers regardless
    if (preg_match('/^call to (\S+)$/', $msg, $m)) {
        $meta['direction'] = 'OUT';
        if (isset($meta['involves'])) $meta['node_addr'] = $meta['node_addr'] ?? $m[1];
    } elseif (preg_match('/^(outgoing|incoming) session with /', $msg, $m)) {
        $meta['direction'] = strtoupper($m[1] === 'outgoing' ? 'OUT' : 'IN');
    } elseif (preg_match('/^addr: (\S+)/', $msg, $m) && addrMatches($m[1], $targetNorm)) {
        $meta['node_addr'] = $meta['node_addr'] ?? rtrim($m[1], ',');
    } elseif (preg_match('/^done \((?:(to|from) )?([^\s,]+), (OK|failed),/', $msg, $m)) {
        if (addrMatches($m[2], $targetNorm)) {
            $meta['node_addr'] = $meta['node_addr'] ?? $m[2];
            if ($m[1]) $meta['direction'] = strtoupper($m[1] === 'to' ? 'OUT' : 'IN');
        }
        if (!empty($meta['involves'])) {
            $meta['status'] = strtoupper($m[3]);
        }
    }

    unset($meta);
}

fclose($fh);

// Filter to PIDs that involve the target node
$matchedPids = array_keys(array_filter($pidMeta, fn($m) => !empty($m['involves'])));

if (empty($matchedPids)) {
    fwrite(STDERR, "No sessions found for $targetRaw\n");
    exit(0);
}

// Sort by session start time, apply limit
usort($matchedPids, fn($a, $b) => $pidMeta[$a]['start'] <=> $pidMeta[$b]['start']);
if ($limit > 0) {
    $matchedPids = array_slice($matchedPids, -$limit);
}
$matchedSet = array_flip($matchedPids);

// Ordered list of sessions for output (preserves sort order)
$sessionOrder = $matchedPids;  // sorted by start time

// ---- Pass 2: collect lines for matching PIDs --------------------------------

$sessionLines = array_fill_keys($matchedPids, []);

$fh = fopen($logFile, 'r');
if (!$fh) { fwrite(STDERR, "Error: cannot re-open $logFile\n"); exit(1); }

while (($line = fgets($fh)) !== false) {
    $p = parseLine($line);
    if ($p === null) continue;
    if (isset($matchedSet[$p['pid']])) {
        $sessionLines[$p['pid']][] = $p['raw'];
    }
}

fclose($fh);

// ---- Output -----------------------------------------------------------------

$total = count($sessionOrder);
$shown = 0;

foreach ($sessionOrder as $pid) {
    $meta  = $pidMeta[$pid];
    $lines = $sessionLines[$pid];
    if (empty($lines)) continue;

    $shown++;
    $startDt  = date('Y-m-d H:i:s', $meta['start']);
    $dir      = $meta['direction'] ?? '?';
    $status   = $meta['status']    ?? '?';
    $nodeAddr = $meta['node_addr'] ?? $targetRaw;

    if (!$rawMode) {
        $header = sprintf(
            '=== [%d/%d] PID %-7d | %-3s | %-12s | %s | %s ===',
            $shown, $total, $pid, $dir, $nodeAddr, $startDt, $status
        );
        echo "\n$header\n";
    }

    foreach ($lines as $l) {
        echo $l . "\n";
    }
}

if (!$rawMode) echo "\n";

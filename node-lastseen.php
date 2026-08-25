#!/usr/bin/env php
<?php
/**
 * node-lastseen.php — Report the last-seen date/time for every node in binkd's logs.
 *
 * Scans binkd.log and its rotated/gzipped predecessors (binkd.log.0, binkd.log.1,
 * binkd.log.2.gz, ...) and reports, per node, when it was last (and first) in
 * contact, its last direction/status, and how many sessions were seen.
 *
 * binkd log lines omit the year (tools.c:vLog), so rotated logs are stitched
 * together oldest-first and the year is reconstructed by watching for the
 * month counter wrapping backwards (Dec -> Jan).
 *
 * Usage: php node-lastseen.php [-f logfile]... [-d dir] [-n count] [-s]
 */

declare(strict_types=1);

// ---- Helpers ----------------------------------------------------------------

function usage(string $prog): never {
    echo <<<USAGE
Usage: php $prog [options]

  -f file     Log file to include (repeatable), newest-first. Overrides default search.
  -d dir      Directory to search for default log files in (default: .)
  -n count    Limit output to N nodes
  -s          Sort alphabetically by node address (default: most recent first)

By default, searches for binkd.log, binkd.log.0, binkd.log.1, binkd.log.2.gz
(and .gz variants of .0/.1) in the standard binkd log locations.

USAGE;
    exit(1);
}

function normalizeAddr(string $s): string {
    $s = trim($s);
    $s = preg_replace('/@\S+/', '', $s);   // strip @domain
    $s = preg_replace('/\.0$/', '', $s);   // strip trailing .0 point
    return strtolower($s);
}

/** Parse a binkd log line into its components, or return null. */
function parseLine(string $line): ?array {
    // Format (tools.c:vLog): <mark> <DD> <Mon> <HH:MM:SS> [<PID>] <message>
    if (!preg_match(
        '/^([!?+\- ]) (\d{2}) ([A-Za-z]{3}) (\d{2}:\d{2}:\d{2}) \[(\d+)\] (.*)$/',
        $line, $m
    )) {
        return null;
    }
    return [
        'day'  => (int)$m[2],
        'mon'  => strtolower($m[3]),
        'time' => $m[4],
        'pid'  => (int)$m[5],
        'msg'  => $m[6],
    ];
}

const MONTHS = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,
                'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];

function toTimestamp(array $p, int $year): int {
    [$h, $m, $s] = explode(':', $p['time']);
    $mon = MONTHS[$p['mon']] ?? 1;
    return mktime((int)$h, (int)$m, (int)$s, $mon, $p['day'], $year);
}

function fmtDt(int $ts): string {
    return date('Y-m-d H:i:s', $ts);
}

function col(string $s, int $width): string {
    return str_pad(substr($s, 0, $width), $width);
}

// Transparent gzip-aware line reader.
function logOpen(string $path) {
    return str_ends_with(strtolower($path), '.gz') ? gzopen($path, 'rb') : fopen($path, 'r');
}
function logGets($fh): string|false {
    return is_resource($fh) ? fgets($fh) : gzgets($fh);
}
function logClose($fh): void {
    is_resource($fh) ? fclose($fh) : gzclose($fh);
}

// ---- Argument parsing -------------------------------------------------------

$opts = getopt('f:d:n:sh', ['help'], $restIdx);
if (isset($opts['h']) || isset($opts['help'])) usage($argv[0]);

$limit    = isset($opts['n']) ? max(1, (int)$opts['n']) : 0;
$sortAlpha = isset($opts['s']);

$explicitFiles = [];
if (isset($opts['f'])) {
    $explicitFiles = is_array($opts['f']) ? $opts['f'] : [$opts['f']];
}

$files = [];
if ($explicitFiles) {
    // -f is given newest-first (same convention as the default search order);
    // reverse to oldest-first for chronological year reconstruction below.
    $files = array_reverse($explicitFiles);
} else {
    $dirs = isset($opts['d']) ? [$opts['d']] : ['.', '/var/log/binkd', '/var/log'];
    // Search order requested: binkd.log, .0, .1, .2.gz — but for year
    // reconstruction we need oldest-first, so build the chronological list
    // from whichever directory actually has the logs.
    $chronological = ['binkd.log.2.gz', 'binkd.log.1', 'binkd.log.1.gz', 'binkd.log.0', 'binkd.log.0.gz', 'binkd.log'];
    foreach ($dirs as $dir) {
        $found = [];
        foreach ($chronological as $name) {
            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($path) && is_readable($path)) $found[] = $path;
        }
        if ($found) { $files = $found; break; }
    }
}

if (!$files) {
    fwrite(STDERR, "Error: no binkd log files found. Use -f <logfile> or -d <dir>\n");
    exit(1);
}

// ---- Pass 1: read all files oldest-first, reconstruct the year -------------

$records = [];   // ['ts_year_rel' => int, 'day','mon','time','pid','msg']
$prevMon = null;
$relYear = 0;

foreach ($files as $path) {
    $fh = logOpen($path);
    if (!$fh) {
        fwrite(STDERR, "Warning: cannot open $path, skipping\n");
        continue;
    }
    while (($line = logGets($fh)) !== false) {
        $p = parseLine(rtrim($line, "\r\n"));
        if ($p === null) continue;

        $monNum = MONTHS[$p['mon']] ?? 1;
        if ($prevMon !== null && $monNum < $prevMon) {
            $relYear++;
        }
        $prevMon = $monNum;

        $p['rel_year'] = $relYear;
        $records[] = $p;
    }
    logClose($fh);
}

if (!$records) {
    fwrite(STDERR, "No parsable log lines found.\n");
    exit(0);
}

$maxRelYear = $relYear;
$currentYear = (int)date('Y');

// ---- Pass 2: rebuild per-PID sessions, tracking node address/status --------

$sessions = [];

foreach ($records as $p) {
    $year = $currentYear - ($maxRelYear - $p['rel_year']);
    $ts   = toTimestamp($p, $year);
    $pid  = $p['pid'];
    $msg  = $p['msg'];

    if (!isset($sessions[$pid])) {
        $sessions[$pid] = [
            'start' => $ts, 'end' => $ts,
            'direction' => null, 'status' => null,
            'node_addr' => null,
        ];
    }
    $sess = &$sessions[$pid];
    if ($ts > $sess['end']) $sess['end'] = $ts;

    if (preg_match('/^call to (\S+)$/', $msg, $m)) {
        $sess['direction'] = 'out';
        $sess['node_addr'] = $sess['node_addr'] ?? rtrim($m[1], ',');

    } elseif (preg_match('/^(outgoing|incoming) session with /', $msg, $m)) {
        $sess['direction'] = $m[1] === 'outgoing' ? 'out' : 'in';

    } elseif (preg_match('/^addr: (\S+)/', $msg, $m)) {
        $sess['node_addr'] = $sess['node_addr'] ?? rtrim($m[1], ',');

    } elseif (preg_match(
        '/^done \((?:(to|from) )?([^\s,]+), (OK|failed), S\/R: (\d+)\/(\d+) \((\d+)\/(\d+) bytes\)\)/',
        $msg, $m
    )) {
        $sess['node_addr'] = $sess['node_addr'] ?? $m[2];
        if ($m[1]) $sess['direction'] = ($m[1] === 'to') ? 'out' : 'in';
        $sess['status'] = strtolower($m[3]);
    }

    unset($sess);
}

// ---- Aggregate sessions into per-node last-seen stats -----------------------

$nodes = [];

foreach ($sessions as $sess) {
    if ($sess['node_addr'] === null) continue;
    $norm = normalizeAddr($sess['node_addr']);

    if (!isset($nodes[$norm])) {
        $nodes[$norm] = [
            'raw' => $sess['node_addr'],
            'first_ts' => $sess['start'],
            'last_ts' => $sess['start'],
            'direction' => $sess['direction'],
            'status' => $sess['status'],
            'sessions' => 0,
        ];
    }
    $n = &$nodes[$norm];
    $n['sessions']++;
    if ($sess['start'] < $n['first_ts']) $n['first_ts'] = $sess['start'];
    if ($sess['start'] >= $n['last_ts']) {
        $n['last_ts']    = $sess['start'];
        $n['raw']        = $sess['node_addr'];
        $n['direction']  = $sess['direction'];
        $n['status']     = $sess['status'];
    }
    unset($n);
}

if ($sortAlpha) {
    ksort($nodes);
} else {
    uasort($nodes, fn($a, $b) => $b['last_ts'] <=> $a['last_ts']);
}

if ($limit > 0) {
    $nodes = array_slice($nodes, 0, $limit, true);
}

// ---- Output -----------------------------------------------------------------

$sep = str_repeat('=', 60);
echo "\n$sep\n";
echo "  Node Last-Seen Report\n";
echo "  Logs: " . implode(', ', array_reverse($files)) . "\n";
echo "$sep\n\n";

printf("  %s %s %s %s %s\n",
    col('Node', 15), col('Last Seen', 19), col('Dir', 3), col('Status', 6), col('Sessions', 8));
echo '  ' . str_repeat('-', 58) . "\n";

foreach ($nodes as $n) {
    $dir = match($n['direction']) { 'out' => 'OUT', 'in' => 'IN', default => '?' };
    $st  = match($n['status'])    { 'ok' => 'OK', 'failed' => 'FAIL', default => '?' };
    printf("  %s %s %s %s %s\n",
        col($n['raw'], 15),
        col(fmtDt($n['last_ts']), 19),
        col($dir, 3),
        col($st, 6),
        col((string)$n['sessions'], 8)
    );
}

echo "\n  " . count($nodes) . " node(s)\n\n";

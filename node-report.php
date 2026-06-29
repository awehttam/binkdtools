#!/usr/bin/env php
<?php
/**
 * node-report.php — Bird's eye view of a specific FidoNet node's binkd activity.
 *
 * Usage: php node-report.php [-f logfile] [-n count] [-v] <node>
 *
 * Log format (from binkd tools.c:vLog):
 *   <mark> <DD> <Mon> <HH:MM:SS> [<PID>] <message>
 */

declare(strict_types=1);

// ---- Helpers ----------------------------------------------------------------

function usage(string $prog): never {
    echo <<<USAGE
Usage: php $prog [options] <node>

  node        FidoNet address, e.g. 1:17/227
  -f file     Path to binkd log (searches common locations if omitted)
  -n count    Limit to the most recent N sessions
  -v          Verbose: show individual file transfers per session

USAGE;
    exit(1);
}

function normalizeAddr(string $s): string {
    $s = trim($s);
    $s = preg_replace('/@\S+/', '', $s);   // strip @domain
    $s = preg_replace('/\.0$/', '', $s);   // strip trailing .0 point
    return strtolower($s);
}

function addrMatches(string $logAddr, string $targetNorm): bool {
    return normalizeAddr(rtrim($logAddr, ',')) === $targetNorm;
}

/** Parse a binkd log line into its components, or return null. */
function parseLine(string $line): ?array {
    // Format: <mark> <DD> <Mon> <HH:MM:SS> [<PID>] <message>
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
    ];
}

function toTimestamp(array $p, int $year): int {
    static $months = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,
                      'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
    [$h, $m, $s] = explode(':', $p['time']);
    $mon = $months[strtolower($p['mon'])] ?? 1;
    return mktime((int)$h, (int)$m, (int)$s, $mon, $p['day'], $year);
}

function fmtBytes(int $b): string {
    if ($b >= 1_048_576) return sprintf('%.1f MB', $b / 1_048_576);
    if ($b >= 1_024)     return sprintf('%.1f KB', $b / 1_024);
    return "{$b} B";
}

function fmtDuration(int $secs): string {
    if ($secs <= 0)    return '';
    if ($secs < 60)    return "{$secs}s";
    if ($secs < 3600)  return sprintf('%dm%02ds', intdiv($secs, 60), $secs % 60);
    return sprintf('%dh%02dm', intdiv($secs, 3600), intdiv($secs % 3600, 60));
}

function col(string $s, int $width): string {
    return str_pad(substr($s, 0, $width), $width);
}

// ---- Argument parsing -------------------------------------------------------

$opts = getopt('f:n:vh', ['help'], $restIdx);
if (isset($opts['h']) || isset($opts['help'])) usage($argv[0]);

$positional = array_slice($argv, $restIdx);
if (empty($positional)) usage($argv[0]);

$targetRaw  = $positional[0];
$targetNorm = normalizeAddr($targetRaw);
$limit      = isset($opts['n']) ? max(1, (int)$opts['n']) : 0;
$verbose    = isset($opts['v']);

$logFile = $opts['f'] ?? null;
if ($logFile === null) {
    foreach (['/var/log/binkd/binkd.log', '/var/log/binkd.log', 'binkd.log'] as $candidate) {
        if (file_exists($candidate)) { $logFile = $candidate; break; }
    }
}
if (!$logFile || !is_readable($logFile)) {
    fwrite(STDERR, "Error: cannot find or read binkd log. Use -f <logfile>\n");
    exit(1);
}

// ---- Log parsing ------------------------------------------------------------
// Single pass: accumulate per-PID session data, track which PIDs involve our node.

$sessions = [];
$year     = (int)date('Y');  // binkd logs omit the year

$fh = fopen($logFile, 'r');
if (!$fh) { fwrite(STDERR, "Error: cannot open $logFile\n"); exit(1); }

while (($line = fgets($fh)) !== false) {
    $p = parseLine(rtrim($line));
    if ($p === null) continue;

    $pid = $p['pid'];
    $msg = $p['msg'];
    $ts  = toTimestamp($p, $year);

    if (!isset($sessions[$pid])) {
        $sessions[$pid] = [
            'pid'        => $pid,
            'start'      => $ts,
            'end'        => $ts,
            'direction'  => null,    // 'out' | 'in'
            'status'     => null,    // 'ok'  | 'failed'
            'node_addr'  => null,
            'involves'   => false,
            'files_sent' => 0,
            'files_rcvd' => 0,
            'bytes_sent' => 0,
            'bytes_rcvd' => 0,
            'transfers'  => [],      // [{dir, name, bytes, cps}]
            'errors'     => [],
        ];
    }

    $sess = &$sessions[$pid];
    if ($ts > $sess['end']) $sess['end'] = $ts;

    // "call to <addr>"  (outgoing, client.c:362)
    if (preg_match('/^call to (\S+)$/', $msg, $m)) {
        $sess['direction'] = 'out';
        if (addrMatches($m[1], $targetNorm)) {
            $sess['involves']  = true;
            $sess['node_addr'] = $sess['node_addr'] ?? $m[1];
        }

    // "outgoing/incoming session with ..."  (protocol.c:3168)
    } elseif (preg_match('/^(outgoing|incoming) session with /', $msg, $m)) {
        $sess['direction'] = $m[1] === 'outgoing' ? 'out' : 'in';

    // "addr: <addr>"  (protocol.c:1351 — remote AKAs)
    } elseif (preg_match('/^addr: (\S+)/', $msg, $m)) {
        if (addrMatches($m[1], $targetNorm)) {
            $sess['involves']  = true;
            $sess['node_addr'] = $sess['node_addr'] ?? rtrim($m[1], ',');
        }

    // "done (to|from <addr>, OK|failed, S/R: N/N (B/B bytes))"  (protocol.c:3047)
    } elseif (preg_match(
        '/^done \((?:(to|from) )?([^\s,]+), (OK|failed), S\/R: (\d+)\/(\d+) \((\d+)\/(\d+) bytes\)\)/',
        $msg, $m
    )) {
        if (addrMatches($m[2], $targetNorm)) {
            $sess['involves']  = true;
            $sess['node_addr'] = $sess['node_addr'] ?? $m[2];
            if ($m[1]) $sess['direction'] = ($m[1] === 'to') ? 'out' : 'in';
        }
        if ($sess['involves']) {
            $sess['status']      = strtolower($m[3]);
            $sess['files_sent']  = (int)$m[4];
            $sess['files_rcvd']  = (int)$m[5];
            $sess['bytes_sent']  = (int)$m[6];
            $sess['bytes_rcvd']  = (int)$m[7];
        }

    // "sent: <path> (<bytes>, <cps> CPS, <addr>)"  (protocol.c:2458)
    } elseif ($sess['involves'] && preg_match(
        '/^sent: (\S+) \((\d+), ([\d.]+) CPS/', $msg, $m
    )) {
        $sess['transfers'][] = ['dir'=>'↑', 'name'=>basename($m[1]), 'bytes'=>(int)$m[2], 'cps'=>(float)$m[3]];

    // "rcvd: <name> (<bytes>, <cps> CPS, <addr>)"  (inbound.c:576)
    } elseif ($sess['involves'] && preg_match(
        '/^rcvd: (\S+) \((\d+), ([\d.]+) CPS/', $msg, $m
    )) {
        $sess['transfers'][] = ['dir'=>'↓', 'name'=>$m[1], 'bytes'=>(int)$m[2], 'cps'=>(float)$m[3]];

    // Error/warning lines
    } elseif ($p['mark'] === '?' && $sess['involves']) {
        $sess['errors'][] = $msg;
    }

    unset($sess);
}
fclose($fh);

// ---- Filter and sort --------------------------------------------------------

$matched = array_values(array_filter($sessions, fn($s) => $s['involves']));
usort($matched, fn($a, $b) => $a['start'] <=> $b['start']);

if ($limit > 0) {
    $matched = array_slice($matched, -$limit);
}

// ---- Stats ------------------------------------------------------------------

$total      = count($matched);
$successes  = count(array_filter($matched, fn($s) => $s['status'] === 'ok'));
$failures   = count(array_filter($matched, fn($s) => $s['status'] === 'failed'));
$unknown    = $total - $successes - $failures;
$bytesSent  = (int)array_sum(array_column($matched, 'bytes_sent'));
$bytesRcvd  = (int)array_sum(array_column($matched, 'bytes_rcvd'));
$filesSent  = (int)array_sum(array_column($matched, 'files_sent'));
$filesRcvd  = (int)array_sum(array_column($matched, 'files_rcvd'));

$firstSeen = $matched ? date('Y-m-d H:i', reset($matched)['start']) : 'n/a';
$lastSeen  = $matched ? date('Y-m-d H:i', end($matched)['start'])   : 'n/a';

// ---- Output -----------------------------------------------------------------

$sep = str_repeat('=', 56);
echo "\n$sep\n";
echo "  Node Activity Report: $targetRaw\n";
echo "  Log:  $logFile\n";
if ($total) {
    echo "  From: $firstSeen\n";
    echo "  To:   $lastSeen\n";
}
echo "$sep\n\n";

echo "SUMMARY\n";
echo str_repeat('-', 40) . "\n";
printf("  Sessions : %d total  (%d OK / %d failed%s)\n",
    $total, $successes, $failures,
    $unknown ? " / $unknown unknown" : '');
printf("  Sent     : %s in %d file(s)\n", fmtBytes($bytesSent), $filesSent);
printf("  Received : %s in %d file(s)\n", fmtBytes($bytesRcvd), $filesRcvd);

if ($total === 0) {
    echo "\nNo sessions found for node $targetRaw.\n\n";
    exit(0);
}

// Recent activity streak
$streak = 0;
foreach (array_reverse($matched) as $s) {
    if ($s['status'] !== 'ok') break;
    $streak++;
}
if ($streak > 1) printf("  Streak   : %d consecutive OK\n", $streak);

echo "\n";
echo "SESSIONS\n";
echo str_repeat('-', 72) . "\n";
printf("  %s %s %s %s %s %s %s\n",
    col('Date/Time',    16),
    col('Dir', 3),
    col('Status', 6),
    col('Sent',    9),
    col('Received', 9),
    col('↑Files', 6),
    col('↓Files', 6)
);
echo '  ' . str_repeat('-', 68) . "\n";

foreach ($matched as $sess) {
    $dt  = date('m/d H:i:s', $sess['start']);
    $dir = match($sess['direction']) { 'out' => 'OUT', 'in' => 'IN', default => '?' };
    $st  = match($sess['status'])    { 'ok' => 'OK', 'failed' => 'FAIL', default => '?' };
    $dur = fmtDuration($sess['end'] - $sess['start']);
    $durStr = $dur ? "[$dur]" : '';

    printf("  %s %s %s %s %s %s %s  %s\n",
        col($dt, 16),
        col($dir, 3),
        col($st,  6),
        col(fmtBytes($sess['bytes_sent']), 9),
        col(fmtBytes($sess['bytes_rcvd']), 9),
        col((string)$sess['files_sent'], 6),
        col((string)$sess['files_rcvd'], 6),
        $durStr
    );

    foreach ($sess['errors'] as $err) {
        echo "    ! $err\n";
    }

    if ($verbose && $sess['transfers']) {
        foreach ($sess['transfers'] as $t) {
            printf("      %s %-32s %s @ %.0f CPS\n",
                $t['dir'], $t['name'], fmtBytes($t['bytes']), $t['cps']);
        }
    }
}

echo "\n";

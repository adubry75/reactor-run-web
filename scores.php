<?php
// Reactor Run top 10s. GET = both boards. POST {board: "total"|"station", name, score, station, crew} = submit.
header('Content-Type: application/json');
header('Cache-Control: no-store');
$files = ['station' => __DIR__ . '/scores.json', 'total' => __DIR__ . '/scores-total.json'];
$max = 10;

function out($data, $code = 200) { http_response_code($code); echo json_encode($data); exit; }
function byScore($a, $b) { return $b['score'] <=> $a['score']; }
function load($f) { $l = is_file($f) ? json_decode((string)@file_get_contents($f), true) : []; return is_array($l) ? $l : []; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['station' => load($files['station']), 'total' => load($files['total'])]);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) out(['error' => 'bad request'], 400);
$board = ($in['board'] ?? '') === 'total' ? 'total' : 'station';
$name = substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($in['name'] ?? ''))), 0, 3);
$score = (int)($in['score'] ?? 0);
$cap = $board === 'total' ? 2000000 : 200000;
if ($name === '' || $score <= 0 || $score > $cap) out(['error' => 'invalid score'], 400);

// one submission per IP per board every 15 seconds
$ipFile = sys_get_temp_dir() . '/reactorrun_' . $board . '_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
if (is_file($ipFile) && time() - filemtime($ipFile) < 15) out(['error' => 'slow down'], 429);
@touch($ipFile);

$fp = fopen($files[$board], 'c+');
if (!$fp) out(['error' => 'storage unavailable'], 500);
flock($fp, LOCK_EX);
$list = json_decode((string)stream_get_contents($fp), true);
if (!is_array($list)) $list = [];
$id = bin2hex(random_bytes(6));
$list[] = ['id' => $id, 'name' => $name, 'score' => $score, 'station' => (int)($in['station'] ?? 0),
  'crew' => (int)($in['crew'] ?? 0), 'date' => gmdate('Y-m-d')];
usort($list, 'byScore');
$list = array_slice($list, 0, $max);
ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($list)); fflush($fp);
flock($fp, LOCK_UN); fclose($fp);
$rank = 0;
foreach ($list as $i => $e) if ($e['id'] === $id) { $rank = $i + 1; break; }
out(['board' => $board, 'scores' => $list, 'rank' => $rank, 'id' => $id]);

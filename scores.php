<?php
// Reactor Run global top 10. GET = list, POST {name, score, station, crew, final} = submit.
header('Content-Type: application/json');
header('Cache-Control: no-store');
$file = __DIR__ . '/scores.json';
$max = 10;

function out($data, $code = 200) { http_response_code($code); echo json_encode($data); exit; }
function byScore($a, $b) { return $b['score'] <=> $a['score']; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  $list = is_file($file) ? json_decode((string)@file_get_contents($file), true) : [];
  out(['scores' => is_array($list) ? $list : []]);
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) out(['error' => 'bad request'], 400);
$name = substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($in['name'] ?? ''))), 0, 3);
$score = (int)($in['score'] ?? 0);
if ($name === '' || $score <= 0 || $score > 200000) out(['error' => 'invalid score'], 400);

// one submission per IP every 15 seconds
$ipFile = sys_get_temp_dir() . '/reactorrun_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
if (is_file($ipFile) && time() - filemtime($ipFile) < 15) out(['error' => 'slow down'], 429);
@touch($ipFile);

$fp = fopen($file, 'c+');
if (!$fp) out(['error' => 'storage unavailable'], 500);
flock($fp, LOCK_EX);
$list = json_decode((string)stream_get_contents($fp), true);
if (!is_array($list)) $list = [];
$id = bin2hex(random_bytes(6));
$list[] = ['id' => $id, 'name' => $name, 'score' => $score, 'station' => (int)($in['station'] ?? 0),
  'crew' => (int)($in['crew'] ?? 0), 'final' => !empty($in['final']), 'date' => gmdate('Y-m-d')];
usort($list, 'byScore');
$list = array_slice($list, 0, $max);
ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($list)); fflush($fp);
flock($fp, LOCK_UN); fclose($fp);
$rank = 0;
foreach ($list as $i => $e) if ($e['id'] === $id) { $rank = $i + 1; break; }
out(['scores' => $list, 'rank' => $rank, 'id' => $id]);

<?php
declare(strict_types=1);
function h(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function require_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Invalid CSRF token.'); } }
function current_user(PDO $pdo): ?array { if (empty($_SESSION['user_id'])) return null; $s=$pdo->prepare('SELECT id, public_id, username, display_name, email, role, live_presence_mode FROM users WHERE id=? AND status="active"'); $s->execute([$_SESSION['user_id']]); return $s->fetch() ?: null; }
function require_user(PDO $pdo): array { $u=current_user($pdo); if(!$u){ header('Location: /login.php'); exit; } return $u; }
function users_exist(PDO $pdo): bool { return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0; }
function ulid_like(): string { return bin2hex(random_bytes(13)); }
function json_response(array $data, int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }
function canonicalize_url(string $url): string { $parts=parse_url(trim($url)); if(!$parts || empty($parts['host'])) return trim($url); $scheme=strtolower($parts['scheme'] ?? 'https'); $host=strtolower($parts['host']); $path=$parts['path'] ?? '/'; $query=[]; if (!empty($parts['query'])) { parse_str($parts['query'],$query); foreach(array_keys($query) as $k){ if(str_starts_with(strtolower($k),'utm_')||in_array(strtolower($k),['fbclid','gclid'],true)) unset($query[$k]); } } return $scheme.'://'.$host.$path.($query?'?'.http_build_query($query):''); }

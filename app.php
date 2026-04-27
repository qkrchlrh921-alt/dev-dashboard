<?php
/**
 * TRUST FOOTBALL - 단일 파일 풀스택 앱
 * v3: 로그인 버그수정 + 다크 네온 디자인 + CSRF 보안
 */

// 세션 수명을 2시간으로 연장 (AJAX 호출 도중 만료되어 401 나오는 현상 완화)
// 전용 세션 디렉토리 사용 — 시스템 phpsessionclean이 php.ini의 1440초를 읽어 세션을 삭제하는 문제 회피
$customSessionPath = '/tmp/tf_sessions';
if (!is_dir($customSessionPath)) @mkdir($customSessionPath, 0700, true);
ini_set('session.save_path', $customSessionPath);
ini_set('session.gc_maxlifetime', 30 * 24 * 3600); // 30일 (로그인 유지 지원)
ini_set('session.cookie_lifetime', 7200);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
session_set_cookie_params([
    'lifetime' => 7200,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// ─────────────────────────────────────────────
// DB 연결
// ─────────────────────────────────────────────
$pdo = new PDO(
    'mysql:host=localhost;dbname=trust_football;charset=utf8mb4',
    'football_user',
    'Tf_Secure2026!',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// [시간대] 서버는 UTC지만 앱은 KST로 표시
date_default_timezone_set('Asia/Seoul');
$pdo->exec("SET time_zone = '+09:00'");

// 만료된 임시회원 자동 정리 (7일 경과)
try {
    $pdo->exec("DELETE ma FROM match_attendance ma JOIN users u ON ma.user_id=u.id WHERE u.is_temp=1 AND u.temp_expires < NOW()");
    $pdo->exec("DELETE mq FROM match_quarters mq JOIN users u ON mq.user_id=u.id WHERE u.is_temp=1 AND u.temp_expires < NOW()");
    $pdo->exec("DELETE tm FROM team_members tm JOIN users u ON tm.user_id=u.id WHERE u.is_temp=1 AND u.temp_expires < NOW()");
    $pdo->exec("DELETE FROM users WHERE is_temp=1 AND temp_expires < NOW()");
} catch (Exception $e) { /* 정리 실패 무시 */ }

// ─────────────────────────────────────────────
// 카카오 OAuth 설정 (실제 키 발급 후 교체)
// ─────────────────────────────────────────────
const KAKAO_REST_KEY   = '__KAKAO_REST_API_KEY__';     // Kakao Developers → REST API 키
const KAKAO_JS_KEY     = '__KAKAO_JS_KEY__';           // Kakao Developers → JavaScript 키
const KAKAO_REDIRECT   = '__YOUR_DOMAIN__/app.php?page=oauth_kakao_callback';  // https://도메인/app.php?page=oauth_kakao_callback

// SMS 발송 설정 (게이트웨이 가입 후 교체)
const SMS_API_KEY      = '__SMS_API_KEY__';
const SMS_API_SECRET   = '__SMS_API_SECRET__';
const SMS_SENDER       = '010-0000-0000';              // 발신번호 (사전 등록 필요)

// ─────────────────────────────────────────────
// CSRF 토큰 생성/검증
// ─────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrfToken(): string {
    return $_SESSION['csrf_token'];
}
function csrfInput(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">';
}
function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        // die() 대신 flash + redirect (하얀 화면 방지)
        $_SESSION['flash'] = ['msg'=>'보안 토큰이 만료되었습니다. 페이지를 새로고침 후 다시 시도해주세요.','type'=>'error'];
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '?page=home'));
        exit;
    }
}

// ─────────────────────────────────────────────
// 헬퍼 함수
// ─────────────────────────────────────────────
function me(): ?array { return $_SESSION['user'] ?? null; }
// 공개 표시명(닉네임 우선, 없으면 실명). 회비/신고/인증 등 실명 필수 화면에서는 사용 금지.
function displayName(array $u): string {
    $nick = trim((string)($u['nickname'] ?? ''));
    return $nick !== '' ? $nick : (string)($u['name'] ?? '-');
}
function teamDisplayName(string $name): string {
    $name = trim($name);
    if (!$name) return '-';
    if (preg_match('/(FC|fc|Fc|SC|sc|AFC|afc|유나이티드|시티)$/u', $name)) return $name;
    return $name . ' FC';
}
// [유니폼] 매치 유니폼 색상 맵 — key 8종 + hex
function uniformColorMap(): array {
    return [
        ''       => ['#888',    '미정'],
        'RED'    => ['#ff4d6d', '빨강'],
        'BLUE'   => ['#3a9ef5', '파랑'],
        'NAVY'   => ['#1e3a5f', '남색'],
        'YELLOW' => ['#ffd60a', '노랑'],
        'GREEN'  => ['#00ff88', '초록'],
        'BLACK'  => ['#111',    '검정'],
        'WHITE'  => ['#f0f0f0', '흰색'],
        'ORANGE' => ['#ff9500', '주황'],
        'PURPLE' => ['#c084fc', '보라'],
    ];
}
// 유니폼 색상 원형 배지 SVG 반환
// [매치 시간] "19:00" 또는 "19:00~21:00" 포맷
function matchTimeStr(array $m): string {
    $s = substr($m['match_time'] ?? '', 0, 5);
    $e = !empty($m['match_end_time']) ? substr($m['match_end_time'], 0, 5) : '';
    return $e ? "{$s}~{$e}" : $s;
}
function uniformDot(?string $key, int $size = 14): string {
    $map = uniformColorMap();
    $k = in_array((string)$key, array_keys($map), true) ? (string)$key : '';
    [$hex, $label] = $map[$k];
    $sz = (int)$size;
    if ($k === '') {
        return ''; // 유니폼 미설정이면 아무것도 표시 안 함 (빈 점선 대신 깔끔하게)
    }
    $border = $k === 'WHITE' ? 'border:1px solid rgba(0,0,0,0.25);' : '';
    return "<span title=\"유니폼: {$label}\" style=\"display:inline-block;width:{$sz}px;height:{$sz}px;border-radius:50%;background:{$hex};{$border};vertical-align:middle\"></span>";
}
// [B1] 아바타 렌더 헬퍼 — 프로필 사진 있으면 IMG, 없으면 이니셜 원형
// $size: 픽셀 크기 (기본 40). $extraStyle: 추가 CSS (optional)
function renderAvatar(array $u, int $size = 40, string $extraStyle = ''): string {
    $dn = displayName($u);
    $initial = htmlspecialchars(mb_substr($dn, 0, 1, 'UTF-8'), ENT_QUOTES, 'UTF-8');
    $img = trim((string)($u['profile_image_url'] ?? ''));
    $commonStyle = "width:{$size}px;height:{$size}px;border-radius:50%;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;overflow:hidden;{$extraStyle}";
    if ($img !== '') {
        $imgEsc = htmlspecialchars($img, ENT_QUOTES, 'UTF-8');
        return "<span style=\"{$commonStyle};background:#000\"><img src=\"{$imgEsc}\" alt=\"\" style=\"width:100%;height:100%;object-fit:cover\"></span>";
    }
    $font = max(12, (int)($size * 0.42));
    return "<span style=\"{$commonStyle};background:var(--primary-glow);font-size:{$font}px;font-weight:700;color:var(--primary)\">{$initial}</span>";
}
// 내가 속한 팀이 ACTIVE 상태인지(3명 이상 모여 활성화) — 매치 개설/SOS 등 주요 액션 게이트
function myTeamActivated(PDO $pdo): bool {
    global $__teamStatusCache;
    $tid = myTeamId();
    if (!$tid) return false;
    if (isset($__teamStatusCache[$tid])) return $__teamStatusCache[$tid] === 'ACTIVE';
    $s = $pdo->prepare("SELECT status FROM teams WHERE id=?");
    $s->execute([$tid]);
    $st = $s->fetchColumn();
    $__teamStatusCache[$tid] = $st;
    return $st === 'ACTIVE';
}
function myTeamMemberCount(PDO $pdo): int {
    $tid = myTeamId();
    if (!$tid) return 0;
    $s = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE team_id=? AND status='active' AND role != 'mercenary'");
    $s->execute([$tid]);
    return (int)$s->fetchColumn();
}
function requireLogin(): void { if (!me()) redirect('?page=login'); }
function isSuperAdmin(): bool { return me() && (me()['global_role'] ?? '') === 'SUPER_ADMIN'; }
// isAdmin은 기존 system_role='admin' 또는 global_role IN (ADMIN, SUPER_ADMIN) — 호환성 유지
function isAdmin(): bool {
    $m = me(); if (!$m) return false;
    if (!empty($m['is_admin'])) return true; // TF-07
    if (($m['system_role'] ?? '') === 'admin') return true;
    $g = $m['global_role'] ?? '';
    return $g === 'ADMIN' || $g === 'SUPER_ADMIN';
}
// [어드민 MVP] isAdmin과 동일하지만 시맨틱 명확화 — 어드민 페이지 전용 체크에 사용
function isAnyAdmin(): bool {
    $m = me(); if (!$m) return false;
    $g = $m['global_role'] ?? '';
    return $g === 'ADMIN' || $g === 'SUPER_ADMIN';
}
// [어드민 MVP] 어드민 액션 감사 로그 기록
// $type 예: 'approve_venue', 'reject_venue', 'restrict_user', 'adjust_manner', 'force_match' 등
// $targetType 예: 'venue_verification', 'user', 'team', 'match', 'report'
function logAdminAction(PDO $pdo, string $type, string $targetType, int $targetId, string $note = ''): void {
    if (!me()) return;
    try {
        $pdo->prepare(
            "INSERT INTO admin_logs (admin_id, action_type, target_type, target_id, note)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([(int)me()['id'], $type, $targetType, $targetId, $note]);
    } catch (PDOException $e) {
        // 로그 실패는 원래 액션을 막지 않음 — 에러 로그만 남김
        error_log("logAdminAction failed: " . $e->getMessage());
    }
}
function isCaptain(): bool { return me() && (isAdmin() || (me()['is_captain'] ?? false)); }

// [팀 실력] 팀 한 건 데이터를 기반으로 실력 계산 (0~100 점수 + 4단계 색상/라벨 반환)
// 공식: 승률*40 + 선출비율*30 + 승점(min 30) = max 100
// 경기 3번 미만이면 '평가중'으로 표기
function calcTeamStrength(array $team, int $proCount = 0, int $memberCount = 0): array {
    $w = (int)($team['win']  ?? 0);
    $d = (int)($team['draw'] ?? 0);
    $l = (int)($team['loss'] ?? 0);
    $totalGames = $w + $d + $l;
    $score = 0.0;
    // 경기 3회 미만: 평가 불가
    if ($totalGames < 3) {
        return [
            'score'        => 0,
            'level'        => 0,
            'color_key'    => 'GRAY',
            'color_hex'    => '#888',
            'bg_hex'       => 'rgba(255,255,255,0.06)',
            'label'        => '평가중',
            'subtitle'     => "경기 {$totalGames}/3회",
            'icon'         => '⚪',
            'is_rated'     => false,
        ];
    }
    $winRate = $w / $totalGames;                      // 0~1
    $score  += $winRate * 40;
    $proRatio = $memberCount > 0 ? min(1.0, $proCount / $memberCount) : 0;
    $score  += $proRatio * 30;
    $score  += min(30.0, (float)($w * 3 + $d));       // 승점 (최대 30)
    $score   = max(0, min(100, $score));
    // 4단계 분류
    if     ($score < 25) $out = ['level'=>1,'color_key'=>'GREEN', 'color_hex'=>'#00ff88','bg_hex'=>'rgba(0,255,136,0.12)', 'label'=>'입문','icon'=>'🟢'];
    elseif ($score < 50) $out = ['level'=>2,'color_key'=>'BLUE',  'color_hex'=>'#3a9ef5','bg_hex'=>'rgba(58,158,245,0.15)','label'=>'밸런스','icon'=>'🔵'];
    elseif ($score < 75) $out = ['level'=>3,'color_key'=>'ORANGE','color_hex'=>'#ff9500','bg_hex'=>'rgba(255,149,0,0.15)', 'label'=>'경쟁','icon'=>'🟠'];
    else                 $out = ['level'=>4,'color_key'=>'RED',   'color_hex'=>'#ff4d6d','bg_hex'=>'rgba(255,77,109,0.15)','label'=>'강팀','icon'=>'🔴'];
    $out['score']    = (int)round($score);
    $out['subtitle'] = "{$w}승{$d}무{$l}패";
    $out['is_rated'] = true;
    return $out;
}
// [팀 실력] strength_color 캐시 컬럼 업데이트 (필요 시 호출)
function persistTeamStrength(PDO $pdo, int $teamId, string $colorKey): void {
    // GRAY는 저장하지 않음 (평가중 = 기본 GREEN 유지)
    if (!in_array($colorKey, ['GREEN','BLUE','ORANGE','RED'], true)) return;
    $pdo->prepare("UPDATE teams SET strength_color=? WHERE id=?")->execute([$colorKey, $teamId]);
}
function myTeamId(): int { return (int)(me()['team_id'] ?? 0); }
function redirect(string $url): void { header("Location: $url"); exit; }
function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function flash(string $msg, string $type = 'success'): void { $_SESSION['flash'] = ['msg' => $msg, 'type' => $type]; }
function getFlash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }

// ── SMS OTP 발송 헬퍼 (게이트웨이 연동 후 활성화) ──
function sendSmsOtp(string $phone, string $otp): bool {
    if (SMS_API_KEY === '__SMS_API_KEY__') return false; // 미연동 시 false
    // TODO: 실제 SMS 게이트웨이 연동 (예: NHN Cloud, 네이버 클라우드, CoolSMS 등)
    // 아래는 CoolSMS 예시 — 게이트웨이에 맞춰 교체
    /*
    $ch = curl_init('https://api.coolsms.co.kr/messages/v4/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: HMAC-SHA256 apiKey=' . SMS_API_KEY . ', date=' . gmdate('Y-m-d\TH:i:s\Z') . ', salt=..., signature=...',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'message' => [
                'to' => str_replace('-', '', $phone),
                'from' => str_replace('-', '', SMS_SENDER),
                'text' => '[TRUST FOOTBALL] 인증번호: ' . $otp . ' (5분 내 입력)',
            ],
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return !empty($res);
    */
    return false;
}

// ── 매너점수 패널티 시스템 ──
// 매너점수 변경 + 30점 이하면 7일 활동 제한 자동 부여
function applyMannerDelta(PDO $pdo, int $userId, float $delta, ?int $matchId = null, string $reason = ''): void {
    $pdo->prepare("UPDATE users SET manner_score = GREATEST(0, LEAST(100, manner_score + ?)) WHERE id=?")
        ->execute([$delta, $userId]);
    $pdo->prepare("INSERT INTO manner_score_logs (user_id, match_id, score_change, reason) VALUES (?,?,?,?)")
        ->execute([$userId, $matchId, $delta, $reason]);
    $sc = $pdo->prepare("SELECT manner_score FROM users WHERE id=?"); $sc->execute([$userId]);
    $cur = (float)$sc->fetchColumn();
    if ($cur <= 30) {
        $pdo->prepare("UPDATE users SET restricted_until = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id=? AND (restricted_until IS NULL OR restricted_until < NOW())")
            ->execute([$userId]);
    }
}
// 활동 제한 체크 — true 반환 시 액션 차단
function isRestricted(PDO $pdo, int $userId): array {
    $row = $pdo->prepare("SELECT manner_score, restricted_until FROM users WHERE id=?");
    $row->execute([$userId]); $r = $row->fetch();
    if (!$r) return [false, null];
    if ($r['restricted_until'] && strtotime($r['restricted_until']) > time()) {
        return [true, $r['restricted_until']];
    }
    return [false, null];
}
// 알림 INSERT 헬퍼
function notify(PDO $pdo, int $userId, string $type, string $title, string $body = '', string $link = '', ?array $extra = null): void {
    $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link, extra_data) VALUES (?,?,?,?,?,?)")
        ->execute([$userId, $type, $title, $body, $link, $extra ? json_encode($extra) : null]);
}
// [TF-24] 안읽은 메시지 수 공통 함수
function getUnreadCount(PDO $pdo, int $userId): int {
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    try {
        $s = $pdo->prepare("
            SELECT COUNT(*) FROM messages m
            JOIN conversation_participants cp ON cp.conversation_id=m.conversation_id AND cp.user_id=?
            WHERE m.created_at > COALESCE(cp.last_read_at,'2000-01-01') AND m.sender_id != ?
        ");
        $s->execute([$userId, $userId]);
        $cache[$userId] = (int)$s->fetchColumn();
    } catch (PDOException $e) {
        $cache[$userId] = 0;
    }
    return $cache[$userId];
}
// 약관 재동의 필요 여부 체크
function checkReagreement(PDO $pdo, int $userId): bool {
    $actives = $pdo->query("SELECT agreement_type,version FROM agreement_versions WHERE is_active=1 AND is_required=1")->fetchAll();
    if (!$actives) return false;
    $agreed = $pdo->prepare("SELECT agreement_type,version FROM user_agreements WHERE user_id=?");
    $agreed->execute([$userId]);
    $agreedMap = [];
    foreach ($agreed->fetchAll() as $a) $agreedMap[$a['agreement_type']] = $a['version'];
    foreach ($actives as $av) {
        if (($agreedMap[$av['agreement_type']] ?? '') !== $av['version']) return true;
    }
    return false;
}

// team_season_stats 자동 집계 함수
function updateSeasonStats(PDO $pdo, int $teamId, int $goalsFor, int $goalsAgainst): void {
    if (!$teamId) return;
    $win  = $goalsFor  > $goalsAgainst ? 1 : 0;
    $draw = $goalsFor === $goalsAgainst  ? 1 : 0;
    $loss = $goalsFor  < $goalsAgainst ? 1 : 0;
    $pts  = $win * 3 + $draw;
    $pdo->prepare("
        INSERT INTO team_season_stats (team_id, matches_played, wins, draws, losses, goals_for, goals_against, goal_difference, points)
        VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            matches_played   = matches_played + 1,
            wins             = wins + VALUES(wins),
            draws            = draws + VALUES(draws),
            losses           = losses + VALUES(losses),
            goals_for        = goals_for + VALUES(goals_for),
            goals_against    = goals_against + VALUES(goals_against),
            goal_difference  = goals_for + VALUES(goals_for) - (goals_against + VALUES(goals_against)),
            points           = points + VALUES(points)
    ")->execute([$teamId, $win, $draw, $loss, $goalsFor, $goalsAgainst, $goalsFor-$goalsAgainst, $pts]);
}

function formatPhone(string $p): string {
    $c = preg_replace('/\D/', '', $p);
    if (strlen($c) === 11) return substr($c,0,3).'-'.substr($c,3,4).'-'.substr($c,7,4);
    return $p;
}

// ── 포인트 시스템 ──
function addPoints(PDO $pdo, int $userId, string $action, int $points, string $desc = '', ?int $refId = null): void {
    // 중복 방지 (같은 action + ref_id 조합)
    if ($refId) {
        $dup = $pdo->prepare("SELECT id FROM user_points WHERE user_id=? AND action=? AND ref_id=?");
        $dup->execute([$userId, $action, $refId]);
        if ($dup->fetch()) return;
    }
    $pdo->prepare("INSERT INTO user_points (user_id, action, points, description, ref_id) VALUES (?,?,?,?,?)")
        ->execute([$userId, $action, $points, $desc, $refId]);
}

function getUserPoints(PDO $pdo, int $userId): int {
    $s = $pdo->prepare("SELECT COALESCE(SUM(points),0) FROM user_points WHERE user_id=?");
    $s->execute([$userId]);
    return (int)$s->fetchColumn();
}

// 포인트 기준
define('PT_ATTENDANCE', 10);
define('PT_CHECKIN', 5);
define('PT_MOM_VOTE', 5);
define('PT_MANNER_REVIEW', 5);
define('PT_BUG_LOW', 50);
define('PT_BUG_MEDIUM', 100);
define('PT_BUG_HIGH', 150);
define('PT_BUG_CRITICAL', 200);
define('PT_INVITE', 30);
define('PT_FIRST_MATCH', 20);

// ─────────────────────────────────────────────
// 세션 유저에 team_id / is_captain 동적 주입
// ─────────────────────────────────────────────
if (me()) {
    $__s = $pdo->prepare(
        "SELECT tm.team_id, tm.role, tm.has_manage_perm, t.leader_id
         FROM team_members tm JOIN teams t ON t.id=tm.team_id
         WHERE tm.user_id=? AND tm.status='active' AND tm.role != 'mercenary' LIMIT 1"
    );
    $__s->execute([me()['id']]);
    $__tm = $__s->fetch();
    $_SESSION['user']['team_id']   = $__tm ? (int)$__tm['team_id'] : 0;
    // [권한] has_manage_perm=1 OR 팀 리더면 관리 권한 (직책과 독립적으로 부여 가능)
    $_SESSION['user']['is_captain'] = $__tm && (
        !empty($__tm['has_manage_perm']) ||
        $__tm['leader_id'] == me()['id']
    );
}

// ─────────────────────────────────────────────
// 라우터
// ─────────────────────────────────────────────
$page   = preg_replace('/[^a-z_]/', '', $_GET['page'] ?? 'home'); // 화이트리스트 방어
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action) {
    // page=api에서 처리할 AJAX 액션은 여기서 스킵
    $apiActions = ['toggle_bench','set_bench','batch_proxy_attendance','toggle_dues_payment'];
    if ($page === 'api' && in_array($action, $apiActions, true)) {
        // page=api 섹션에서 처리
    } else {
        $csrfExempt = ['logout','login','register','admin_login','toggle_bench','set_bench','batch_proxy_attendance','toggle_dues_payment'];
        if (!in_array($action, $csrfExempt, true)) verifyCsrf();
        handleAction($pdo, $action);
    }
}

// ── AJAX JSON 엔드포인트 (?page=api&fn=...) ──
if ($page === 'api') {
    header('Content-Type: application/json; charset=utf-8');
    if (!me()) { http_response_code(401); echo json_encode(['ok'=>false,'err'=>'unauthorized']); exit; }

    // POST API (AJAX용 - 선발/후보, 출석 일괄)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $apiAction = $_POST['action'] ?? '';
        if ($apiAction === 'toggle_bench') {
            if (!isCaptain()) { echo json_encode(['ok'=>false,'msg'=>'캡틴만 가능']); exit; }
            $matchId = (int)($_POST['match_id'] ?? 0);
            $targetUid = (int)($_POST['user_id'] ?? 0);
            if (!$matchId || !$targetUid) { echo json_encode(['ok'=>false,'msg'=>'잘못된 요청']); exit; }
            $cur = $pdo->prepare("SELECT is_bench FROM match_attendance WHERE match_id=? AND user_id=?");
            $cur->execute([$matchId, $targetUid]);
            $curRow = $cur->fetch();
            if (!$curRow) {
                $tid = (int)myTeamId();
                $pdo->prepare("INSERT INTO match_attendance (match_id, user_id, team_id, status, is_bench) VALUES (?,?,?,?,1)")
                    ->execute([$matchId, $targetUid, $tid, 'PENDING']);
                echo json_encode(['ok'=>true,'is_bench'=>1,'msg'=>'후보로 등록']);
                exit;
            }
            $newBench = $curRow['is_bench'] ? 0 : 1;
            $pdo->prepare("UPDATE match_attendance SET is_bench=? WHERE match_id=? AND user_id=?")
                ->execute([$newBench, $matchId, $targetUid]);
            echo json_encode(['ok'=>true,'is_bench'=>$newBench,'msg'=>$newBench ? '후보로 변경' : '선발로 변경']);
            exit;
        }
        if ($apiAction === 'set_bench') {
            if (!isCaptain()) { echo json_encode(['ok'=>false,'msg'=>'캡틴만 가능']); exit; }
            $matchId = (int)($_POST['match_id'] ?? 0);
            $targetUid = (int)($_POST['user_id'] ?? 0);
            $setBench = (int)($_POST['is_bench'] ?? 0);
            if (!$matchId || !$targetUid) { echo json_encode(['ok'=>false,'msg'=>'잘못된 요청']); exit; }
            $cur = $pdo->prepare("SELECT id FROM match_attendance WHERE match_id=? AND user_id=?");
            $cur->execute([$matchId, $targetUid]);
            if ($cur->fetch()) {
                $pdo->prepare("UPDATE match_attendance SET is_bench=? WHERE match_id=? AND user_id=?")->execute([$setBench, $matchId, $targetUid]);
            } else {
                $tid = (int)myTeamId();
                $pdo->prepare("INSERT INTO match_attendance (match_id, user_id, team_id, status, is_bench) VALUES (?,?,?,?,?)")->execute([$matchId, $targetUid, $tid, 'PENDING', $setBench]);
            }
            echo json_encode(['ok'=>true,'is_bench'=>$setBench,'msg'=>$setBench ? '후보 지정' : '선발 지정']);
            exit;
        }
        if ($apiAction === 'batch_proxy_attendance') {
            if (!isCaptain()) { echo json_encode(['ok'=>false,'msg'=>'캡틴만 가능']); exit; }
            $matchId = (int)($_POST['match_id'] ?? 0);
            $votes = json_decode($_POST['votes'] ?? '{}', true);
            if (!$matchId || !$votes) { echo json_encode(['ok'=>false,'msg'=>'잘못된 요청']); exit; }
            $tid = (int)myTeamId();
            $updated = 0;
            foreach ($votes as $uid => $vote) {
                $uid = (int)$uid;
                $vote = in_array($vote, ['ATTEND','ABSENT','PENDING']) ? $vote : 'PENDING';
                $exists = $pdo->prepare("SELECT id FROM match_attendance WHERE match_id=? AND user_id=?");
                $exists->execute([$matchId, $uid]);
                if ($exists->fetch()) {
                    $pdo->prepare("UPDATE match_attendance SET status=? WHERE match_id=? AND user_id=?")->execute([$vote, $matchId, $uid]);
                } else {
                    $pdo->prepare("INSERT INTO match_attendance (match_id, user_id, team_id, status) VALUES (?,?,?,?)")->execute([$matchId, $uid, $tid, $vote]);
                }
                $updated++;
            }
            echo json_encode(['ok'=>true,'msg'=>$updated.'명 출석 저장 완료','count'=>$updated]);
            exit;
        }

        // ── 회비 납부 토글 (AJAX) ──
        if ($apiAction === 'toggle_dues_payment') {
            if (!isCaptain()) { echo json_encode(['ok'=>false,'msg'=>'캡틴만 가능']); exit; }
            $tid = (int)myTeamId();
            $uid = (int)($_POST['user_id'] ?? 0);
            $ym = preg_replace('/[^0-9\-]/', '', $_POST['year_month'] ?? '');
            $newStatus = in_array($_POST['new_status'] ?? '', ['paid','unpaid','partial','exempt']) ? $_POST['new_status'] : 'paid';
            $note = trim($_POST['note'] ?? '');
            if (!$tid || !$uid || !$ym) { echo json_encode(['ok'=>false,'msg'=>'잘못된 요청']); exit; }

            // Get fee amount from settings or team default
            $feeStmt = $pdo->prepare("SELECT monthly_fee FROM team_dues_settings WHERE team_id=?");
            $feeStmt->execute([$tid]);
            $feeAmt = (int)($feeStmt->fetchColumn() ?: 0);
            if ($feeAmt <= 0) {
                $feeStmt2 = $pdo->prepare("SELECT membership_fee FROM teams WHERE id=?");
                $feeStmt2->execute([$tid]);
                $feeAmt = (int)($feeStmt2->fetchColumn() ?: 30000);
            }

            $exists = $pdo->prepare("SELECT id, status FROM team_dues_payments WHERE team_id=? AND user_id=? AND year_month=?");
            $exists->execute([$tid, $uid, $ym]);
            $row = $exists->fetch();

            if ($row) {
                $upd = $pdo->prepare("UPDATE team_dues_payments SET status=?, paid_at=?, note=?, recorded_by=? WHERE id=?");
                $upd->execute([
                    $newStatus,
                    $newStatus === 'paid' ? date('Y-m-d H:i:s') : null,
                    $note ?: null,
                    (int)me()['id'],
                    $row['id']
                ]);
            } else {
                $ins = $pdo->prepare("INSERT INTO team_dues_payments (team_id, user_id, year_month, amount, status, paid_at, note, recorded_by) VALUES (?,?,?,?,?,?,?,?)");
                $ins->execute([
                    $tid, $uid, $ym, $feeAmt, $newStatus,
                    $newStatus === 'paid' ? date('Y-m-d H:i:s') : null,
                    $note ?: null,
                    (int)me()['id']
                ]);
            }

            $statusLabels = ['paid'=>'납부','unpaid'=>'미납','partial'=>'일부','exempt'=>'면제'];
            echo json_encode(['ok'=>true,'status'=>$newStatus,'label'=>$statusLabels[$newStatus]??$newStatus,'msg'=>'처리 완료']);
            exit;
        }
    }

    $fn = preg_replace('/[^a-z_]/', '', $_GET['fn'] ?? '');
    if ($fn === 'match_share_data') {
        $mid = (int)($_GET['id'] ?? 0);
        $m = $pdo->prepare("SELECT m.*, ht.name AS home_name, at.name AS away_name, mr.score_home, mr.score_away, mr.mom_user_id, u_mom.name AS mom_name FROM matches m LEFT JOIN teams ht ON ht.id=m.home_team_id LEFT JOIN teams at ON at.id=m.away_team_id LEFT JOIN match_results mr ON mr.match_id=m.id LEFT JOIN users u_mom ON u_mom.id=mr.mom_user_id WHERE m.id=?");
        $m->execute([$mid]); $md = $m->fetch();
        if (!$md) { echo json_encode(['ok'=>false,'err'=>'not_found']); exit; }
        $sc = $pdo->prepare("SELECT u.name FROM match_player_records mpr JOIN users u ON u.id=mpr.user_id WHERE mpr.match_id=? AND mpr.goals>0");
        $sc->execute([$mid]); $scorers = array_column($sc->fetchAll(), 'name');
        $ac = $pdo->prepare("SELECT COUNT(*) FROM match_attendance WHERE match_id=? AND status='ATTEND'");
        $ac->execute([$mid]);
        $lq = $pdo->prepare("SELECT mq.user_id, mq.position, u.name FROM match_quarters mq JOIN users u ON u.id=mq.user_id WHERE mq.match_id=? AND mq.quarter=1");
        $lq->execute([$mid]);
        $mg=0;$ma=0;if($md['mom_user_id']){$ms=$pdo->prepare("SELECT goals,assists FROM match_player_records WHERE match_id=? AND user_id=?");$ms->execute([$mid,$md['mom_user_id']]);$mst=$ms->fetch();$mg=(int)($mst['goals']??0);$ma=(int)($mst['assists']??0);}
        echo json_encode(['ok'=>true,'match'=>['match_date'=>$md['match_date'],'match_time'=>$md['match_time']??'','home_name'=>$md['home_name'],'away_name'=>$md['away_name'],'score_home'=>(int)($md['score_home']??0),'score_away'=>(int)($md['score_away']??0),'location'=>$md['location']??'','mom_name'=>$md['mom_name'],'mom_goals'=>$mg,'mom_assists'=>$ma,'scorers'=>$scorers,'attend_count'=>(int)$ac->fetchColumn(),'lineup'=>$lq->fetchAll()]], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($fn === 'user_profile') {
        $uid = (int)($_GET['id'] ?? 0);
        $q = $pdo->prepare("SELECT u.id, u.name, u.nickname, u.position, u.manner_score, u.height, u.weight, u.preferred_foot, u.is_player_background, u.mom_count,
            u.stat_pace, u.stat_shooting, u.stat_passing, u.stat_dribbling, u.stat_defending, u.stat_physical, u.region, u.district, u.profile_image_url, u.jersey_number,
            t.name AS team_name
            FROM users u
            LEFT JOIN team_members tm ON tm.user_id=u.id AND tm.status='active' AND tm.role != 'mercenary'
            LEFT JOIN teams t ON t.id=tm.team_id
            WHERE u.id=? LIMIT 1");
        $q->execute([$uid]);
        $row = $q->fetch();
        if (!$row) { http_response_code(404); echo json_encode(['ok'=>false,'err'=>'not_found']); exit; }
        // 팀/용병 분리 집계
        $agg = $pdo->prepare("SELECT
            SUM(CASE WHEN is_mercenary=0 THEN 1 ELSE 0 END) AS team_played,
            SUM(CASE WHEN is_mercenary=1 THEN 1 ELSE 0 END) AS merc_played,
            SUM(CASE WHEN is_mercenary=0 THEN is_checked_in ELSE 0 END) AS team_attended,
            SUM(CASE WHEN is_mercenary=1 THEN is_checked_in ELSE 0 END) AS merc_attended,
            SUM(CASE WHEN is_mercenary=0 THEN goals ELSE 0 END) AS team_goals,
            SUM(CASE WHEN is_mercenary=1 THEN goals ELSE 0 END) AS merc_goals,
            SUM(CASE WHEN is_mercenary=0 THEN assists ELSE 0 END) AS team_assists,
            SUM(CASE WHEN is_mercenary=1 THEN assists ELSE 0 END) AS merc_assists,
            COUNT(DISTINCT match_id) AS total_played,
            SUM(is_checked_in) AS total_attended,
            SUM(goals) AS total_goals,
            SUM(assists) AS total_assists
            FROM match_player_records WHERE user_id=?");
        $agg->execute([$uid]);
        $a = $agg->fetch() ?: [];
        $tp = (int)($a['total_played']??0);
        $row['matches_played'] = $tp;
        $row['goals_total']    = (int)($a['total_goals']??0);
        $row['assists_total']  = (int)($a['total_assists']??0);
        // 출석률: match_attendance에서 ATTEND한 경기 수 / 참여 가능했던 전체 경기 수
        $attRateQ = $pdo->prepare("SELECT
            (SELECT COUNT(DISTINCT match_id) FROM match_attendance WHERE user_id=? AND status='ATTEND') AS att,
            (SELECT COUNT(DISTINCT match_id) FROM match_attendance WHERE user_id=?) AS total
        ");
        $attRateQ->execute([$uid, $uid]); $attR = $attRateQ->fetch();
        $row['attendance_rate'] = ((int)($attR['total']??0)) > 0 ? round((int)$attR['att'] / (int)$attR['total'] * 100) : 0;
        $row['split'] = [
            'team' => [
                'played'   => (int)($a['team_played']??0),
                'attended' => (int)($a['team_attended']??0),
                'goals'    => (int)($a['team_goals']??0),
                'assists'  => (int)($a['team_assists']??0),
            ],
            'merc' => [
                'played'   => (int)($a['merc_played']??0),
                'attended' => (int)($a['merc_attended']??0),
                'goals'    => (int)($a['merc_goals']??0),
                'assists'  => (int)($a['merc_assists']??0),
            ],
        ];
        // 최근 경기 이력 (최대 10건)
        $hist = $pdo->prepare("
            SELECT mpr.match_id, mpr.is_mercenary, mpr.goals, mpr.assists, mpr.is_checked_in,
                   m.title, m.match_date, m.match_time, m.location, m.status AS match_status,
                   ht.name AS home_name, at.name AS away_name,
                   t.name AS played_team_name,
                   res.score_home, res.score_away
            FROM match_player_records mpr
            JOIN matches m ON m.id=mpr.match_id
            LEFT JOIN teams ht ON ht.id=m.home_team_id
            LEFT JOIN teams at ON at.id=m.away_team_id
            LEFT JOIN teams t  ON t.id=mpr.team_id
            LEFT JOIN match_results res ON res.match_id=mpr.match_id
            WHERE mpr.user_id=?
            ORDER BY m.match_date DESC, m.match_time DESC
            LIMIT 10
        ");
        $hist->execute([$uid]);
        $row['recent_matches'] = $hist->fetchAll();
        // 내가 작성한 이 유저에 대한 비공개 노트 (본인 것만 조회)
        $meId = (int)me()['id'];
        $nt = $pdo->prepare("SELECT note, updated_at FROM user_notes WHERE author_id=? AND target_user_id=? LIMIT 1");
        $nt->execute([$meId, $uid]);
        $row['my_note']    = $nt->fetch() ?: null;
        $row['can_edit_note'] = ($meId !== $uid); // 자기 자신에 대한 메모는 숨김
        // [친구 상태] 친구 요청 버튼 렌더용
        $row['friend_state'] = 'none'; // none | incoming | outgoing | accepted | blocked | self
        if ($meId === $uid) {
            $row['friend_state'] = 'self';
        } else {
            $fq = $pdo->prepare("SELECT status, requester_id FROM friendships WHERE (requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?) LIMIT 1");
            $fq->execute([$meId, $uid, $uid, $meId]);
            if ($frow = $fq->fetch()) {
                if ($frow['status'] === 'ACCEPTED') $row['friend_state'] = 'accepted';
                elseif ($frow['status'] === 'BLOCKED') $row['friend_state'] = 'blocked';
                elseif ((int)$frow['requester_id'] === $meId) $row['friend_state'] = 'outgoing';
                else $row['friend_state'] = 'incoming';
            }
        }
        // 강퇴 권한: 내가 해당 팀의 캡틴이고 대상이 같은 팀의 non-captain
        $row['can_kick'] = false;
        $row['kick_team_id'] = null;
        if ($meId !== $uid) {
            $myTid = (int)(myTeamId() ?? 0);
            if ($myTid && isCaptain()) {
                $kk = $pdo->prepare("SELECT role FROM team_members WHERE team_id=? AND user_id=? AND status='active' AND role != 'mercenary'");
                $kk->execute([$myTid, $uid]);
                $trole = $kk->fetchColumn();
                if ($trole && $trole !== 'captain') {
                    $row['can_kick'] = true;
                    $row['kick_team_id'] = $myTid;
                }
            }
        }
        echo json_encode(['ok'=>true,'user'=>$row], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // [채팅 폴링] 새 메시지 조회 (?page=api&fn=chat_poll&conv_id=N&after_id=N)
    if ($fn === 'chat_poll') {
        $convId  = (int)($_GET['conv_id'] ?? 0);
        $afterId = (int)($_GET['after_id'] ?? 0);
        if (!$convId) { echo json_encode(['ok'=>false,'err'=>'no_conv_id']); exit; }
        // 참여자 확인
        $mem = $pdo->prepare("SELECT id FROM conversation_participants WHERE conversation_id=? AND user_id=?");
        $mem->execute([$convId, (int)me()['id']]);
        if (!$mem->fetch()) { echo json_encode(['ok'=>false,'err'=>'not_member']); exit; }
        // after_id 이후 새 메시지만 조회
        $q = $pdo->prepare("
            SELECT m.id, m.sender_id, COALESCE(NULLIF(TRIM(u.nickname),''), u.name) AS sender_name,
                   u.profile_image_url, m.message, m.msg_type, m.extra_data, m.created_at
            FROM messages m LEFT JOIN users u ON u.id=m.sender_id
            WHERE m.conversation_id=? AND m.id > ?
            ORDER BY m.id ASC LIMIT 50
        ");
        $q->execute([$convId, $afterId]);
        $msgs = $q->fetchAll();
        // 읽음 처리
        if ($msgs) {
            $pdo->prepare("UPDATE conversation_participants SET last_read_at=NOW() WHERE conversation_id=? AND user_id=?")
                ->execute([$convId, (int)me()['id']]);
        }
        echo json_encode(['ok'=>true,'messages'=>$msgs,'me_id'=>(int)me()['id']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(404); echo json_encode(['ok'=>false,'err'=>'unknown_fn']); exit;
}

renderPage($pdo, $page);

// ═══════════════════════════════════════════════════════════════
// ACTION HANDLER
// ═══════════════════════════════════════════════════════════════
function handleAction(PDO $pdo, string $action): void {
    switch ($action) {

        case 'register':
            $fromKakao = isset($_SESSION['pending_kakao']);
            $kakaoData = $fromKakao ? $_SESSION['pending_kakao'] : null;

            $name     = trim($_POST['name'] ?? '');
            $phone    = formatPhone(trim($_POST['phone'] ?? ''));
            $pw       = $_POST['password'] ?? '';
            $pw2      = $_POST['password_confirm'] ?? '';
            $position = $_POST['position'] ?? '';
            $region   = trim($_POST['region']   ?? '');
            $district = trim($_POST['district'] ?? '');
            $level    = $_POST['level'] ?? '';
            $isPlayer = !empty($_POST['is_player_background']) ? 1 : 0;
            $teamCode = strtoupper(trim($_POST['team_code'] ?? ''));
            // 필수 약관 체크
            $tos     = !empty($_POST['agree_TOS']);
            $privacy = !empty($_POST['agree_PRIVACY']);
            $location= !empty($_POST['agree_LOCATION']);
            if (!$tos || !$privacy || !$location) {
                flash('필수 약관에 모두 동의해야 합니다.', 'error');
                redirect('?page=register' . ($fromKakao ? '&source=kakao' : ''));
            }
            if (!$name || !$phone || strlen($pw) < 6) {
                flash('이름, 전화번호, 비밀번호(6자↑)를 입력하세요.', 'error');
                redirect('?page=register' . ($fromKakao ? '&source=kakao' : ''));
            }
            if ($pw !== $pw2) {
                flash('비밀번호가 일치하지 않습니다. 다시 확인해주세요.', 'error');
                redirect('?page=register' . ($fromKakao ? '&source=kakao' : ''));
            }
            if (!preg_match('/^01[0-9]-\d{3,4}-\d{4}$/', $phone)) {
                flash('전화번호 형식이 올바르지 않습니다. (010-1234-5678)', 'error');
                redirect('?page=register' . ($fromKakao ? '&source=kakao' : ''));
            }
            // 팀코드 사전 확인
            $joinTeam = null;
            if ($teamCode) {
                $tc = $pdo->prepare("SELECT * FROM teams WHERE invite_code=? AND status != 'BANNED'");
                $tc->execute([$teamCode]); $joinTeam = $tc->fetch();
                if (!$joinTeam) { flash('유효하지 않은 팀 초대코드입니다.', 'error'); redirect('?page=register'); }
            }
            try {
                if ($fromKakao) {
                    $nickname = $kakaoData['nickname'] ?? $name;
                    $profileImg = $kakaoData['profile_image_url'] ?? null;
                    $pdo->prepare("INSERT INTO users (name,nickname,phone,password_hash,position,region,district,is_player_background,kakao_id,auth_provider,kakao_email,profile_image_url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$name, $nickname, $phone, password_hash($pw, PASSWORD_DEFAULT), $position, $region, $district, $isPlayer, $kakaoData['kakao_id'], 'KAKAO', $kakaoData['email'], $profileImg]);
                    unset($_SESSION['pending_kakao']);
                } else {
                    $pdo->prepare("INSERT INTO users (name,phone,password_hash,position,region,district,is_player_background) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$name, $phone, password_hash($pw, PASSWORD_DEFAULT), $position, $region, $district, $isPlayer]);
                }
                $newUserId = (int)$pdo->lastInsertId();
                // [팀 가입 승인] 팀코드로 가입 시도 시 PENDING 상태로 INSERT — 캡틴 수락 필요
                if ($joinTeam) {
                    $pdo->prepare("INSERT IGNORE INTO team_members (team_id,user_id,role,status) VALUES (?,?,'player','pending')")
                        ->execute([$joinTeam['id'], $newUserId]);
                    // 캡틴에게 알림
                    $capQ = $pdo->prepare("SELECT user_id FROM team_members WHERE team_id=? AND role='captain' AND status='active' LIMIT 1");
                    $capQ->execute([$joinTeam['id']]);
                    if ($cid = (int)$capQ->fetchColumn()) {
                        notify($pdo, $cid, 'TEAM_JOIN', '🚪 팀 가입 신청', h($name).'님이 팀 가입을 신청했습니다.', '?page=team');
                    }
                }
                // 약관 동의 저장
                $agreeTypes = ['TOS'=>true,'PRIVACY'=>true,'LOCATION'=>true,'MARKETING'=>!empty($_POST['agree_MARKETING'])];
                $actives    = $pdo->query("SELECT agreement_type,version FROM agreement_versions WHERE is_active=1")->fetchAll();
                foreach ($actives as $av) {
                    if (!empty($agreeTypes[$av['agreement_type']])) {
                        $pdo->prepare("INSERT IGNORE INTO user_agreements (user_id,agreement_type,version) VALUES (?,?,?)")
                            ->execute([$newUserId, $av['agreement_type'], $av['version']]);
                    }
                }
                // 동명의 임시회원이 있으면 데이터 이관
                $tempUser = $pdo->prepare("SELECT id FROM users WHERE name=? AND is_temp=1 LIMIT 1");
                $tempUser->execute([$name]);
                $tempMatch = $tempUser->fetch();
                if ($tempMatch) {
                    $pdo->prepare("UPDATE team_members SET user_id=? WHERE user_id=?")->execute([$newUserId, $tempMatch['id']]);
                    $pdo->prepare("UPDATE match_attendance SET user_id=? WHERE user_id=?")->execute([$newUserId, $tempMatch['id']]);
                    $pdo->prepare("UPDATE match_quarters SET user_id=? WHERE user_id=?")->execute([$newUserId, $tempMatch['id']]);
                    $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$tempMatch['id']]);
                    flash('가입 완료! 이전 경기 기록이 연결되었습니다.');
                } else {
                    flash('가입 완료! 로그인하세요.');
                }
                redirect('?page=login');
            } catch (PDOException) {
                flash('이미 사용 중인 전화번호입니다.', 'error');
                redirect('?page=register');
            }
            break;

        case 'login':
            $phone = formatPhone(trim($_POST['phone'] ?? ''));
            $pw    = $_POST['password'] ?? '';
            $stmt  = $pdo->prepare("SELECT * FROM users WHERE phone=?");
            $stmt->execute([$phone]);
            $user  = $stmt->fetch();
            if ($user && password_verify($pw, $user['password_hash'])) {
                // 영구정지/banned 계정 차단
                if (($user['system_role'] ?? '') === 'banned') {
                    flash('이용이 영구 정지된 계정입니다. 운영팀에 문의하세요.', 'error');
                    redirect('?page=login');
                }
                // 활성 제재 확인 (RESTRICT/BLACKLIST)
                $pen = $pdo->prepare("
                    SELECT penalty_type, reason, expires_at
                    FROM user_penalties
                    WHERE user_id=? AND penalty_type IN ('RESTRICT','BLACKLIST')
                      AND (expires_at IS NULL OR expires_at > NOW())
                    ORDER BY created_at DESC LIMIT 1
                ");
                $pen->execute([$user['id']]); $pen = $pen->fetch();
                if ($pen) {
                    $until = $pen['expires_at'] ? ' ('.$pen['expires_at'].'까지)' : '';
                    // [TF-11] 제재 유저도 로그인 허용 (이의제기용)
                    session_regenerate_id(true);
                    $_SESSION['user'] = $user;
                    $_SESSION['user_restricted'] = true;
                    $_SESSION['restriction_reason'] = $pen['reason'] ?? '';
                    $_SESSION['restriction_until'] = $pen['expires_at'] ?? '';
                    $_SESSION['penalty_info'] = [
                        'user_id' => $user['id'],
                        'user_name' => $user['name'],
                        'penalty_type' => $pen['penalty_type'],
                        'reason' => $pen['reason'],
                        'expires_at' => $pen['expires_at'],
                    ];
                    $pdo->prepare("UPDATE users SET last_login_at=NOW() WHERE id=?")->execute([$user['id']]);
                    flash('이용이 제한된 계정입니다'.$until.'. 이의제기를 제출할 수 있습니다.', 'error');
                    redirect('?page=home');
                }
                // 세션 고정 공격 방지
                session_regenerate_id(true);
                $_SESSION['user'] = $user;
                // 로그인 유지 체크 시 세션 쿠키 30일 연장
                if (!empty($_POST['remember_me'])) {
                    $lifetime = 30 * 24 * 3600;
                    // session_regenerate_id 후이므로 session_set_cookie_params는 무효
                    // setcookie로 직접 쿠키 수명 30일 설정
                    setcookie(session_name(), session_id(), [
                        'expires'  => time() + $lifetime,
                        'path'     => '/',
                        'httponly'  => true,
                        'samesite' => 'Lax',
                    ]);
                    $_SESSION['remember_until'] = time() + $lifetime;
                }
                $pdo->prepare("UPDATE users SET last_login_at=NOW() WHERE id=?")->execute([$user['id']]);
                // 약관 재동의 필요 여부 체크
                $reagree = checkReagreement($pdo, $user['id']);
                if ($reagree) {
                    $_SESSION['requires_reagreement'] = true;
                    flash('약관이 업데이트되었습니다. 동의 후 이용해주세요.');
                    redirect('?page=agreements');
                }
                flash('환영합니다, ' . h($user['name']) . '님!');
                // 딥링크(팀 초대 등) 대기 중이면 해당 URL로 복귀
                if (!empty($_SESSION['post_login_url'])) {
                    $postUrl = $_SESSION['post_login_url'];
                    unset($_SESSION['post_login_url']);
                    redirect($postUrl);
                }
                redirect('?page=home');
            } else {
                flash('전화번호 또는 비밀번호가 올바르지 않습니다.', 'error');
                redirect('?page=login');
            }
            break;

        // [TF-07] 관리자 전용 로그인
        case 'admin_login':
            $phone = formatPhone(trim($_POST['phone'] ?? ''));
            $pw    = $_POST['password'] ?? '';
            $stmt  = $pdo->prepare("SELECT * FROM users WHERE phone=?");
            $stmt->execute([$phone]);
            $user  = $stmt->fetch();
            if ($user && password_verify($pw, $user['password_hash'])) {
                $isAdm = !empty($user['is_admin']) || in_array($user['global_role'] ?? '', ['ADMIN','SUPER_ADMIN']) || ($user['system_role'] ?? '') === 'admin';
                if (!$isAdm) {
                    flash('관리자 권한이 없는 계정입니다.', 'error');
                    redirect('?page=admin_login');
                }
                session_regenerate_id(true);
                $_SESSION['user'] = $user;
                $_SESSION['admin_authenticated'] = true;
                $pdo->prepare("UPDATE users SET last_login_at=NOW() WHERE id=?")->execute([$user['id']]);
                logAdminAction($pdo, 'admin_login', 'user', (int)$user['id'], '관리자 로그인');
                flash('관리자로 로그인되었습니다.');
                redirect('?page=admin_dashboard');
            } else {
                flash('전화번호 또는 비밀번호가 올바르지 않습니다.', 'error');
                redirect('?page=admin_login');
            }
            break;

        case 'logout':
            session_destroy();
            redirect('?page=login');
            break;

        case 'create_match':
            requireLogin();
            if (!isCaptain()) { flash('캡틴만 매치를 개설할 수 있습니다.', 'error'); redirect('?page=home'); }
            // [팀 활성화 게이트] PENDING 팀(3명 미만) 매치 개설 차단
            if (!myTeamActivated($pdo)) {
                $cnt = myTeamMemberCount($pdo);
                flash("팀원이 3명 이상 모여야 매치를 개설할 수 있습니다. (현재 {$cnt}명)", 'error');
                redirect('?page=team');
            }
            // [매너 패널티] 활동 제한 체크
            [$blocked, $until] = isRestricted($pdo, me()['id']);
            if ($blocked) { flash('매너점수 부족으로 '.date('m/d H:i', strtotime($until)).'까지 활동 제한 중입니다.', 'error'); redirect('?page=home'); }
            // [매너필터] min_manner_score 추가 — DECIMAL(4,1)
            $minManner = max(0.0, min(50.0, (float)($_POST['min_manner_score'] ?? 0)));
            // [매치 타입 3-way]
            //   VENUE        — 🏟️ 상대팀 구함 (구장 확보됨) — 기본
            //   VENUE_WANTED — 🔍 경기장 구함 (상대팀 있음 또는 자체)
            //   REQUEST      — 🆘 상대+경기장 모두 구함 (홈팀 미정)
            $allowedMT = ['VENUE','VENUE_WANTED','REQUEST','MERC_ONLY'];
            $matchType = in_array($_POST['match_type'] ?? 'VENUE', $allowedMT, true) ? $_POST['match_type'] : 'VENUE';
            // home_team_id: REQUEST만 NULL, 나머지는 내 팀
            $homeTeamForType = $matchType === 'REQUEST' ? null : myTeamId();
            // [유니폼] 화이트리스트 체크
            $uniMap = uniformColorMap();
            $uniformColor = in_array($_POST['uniform_color'] ?? '', array_keys($uniMap), true) ? $_POST['uniform_color'] : (array_key_exists('uniform_color', $_POST) ? '' : '');
            $matchEndTime = !empty($_POST['match_end_time']) ? $_POST['match_end_time'] : null;
            $awayTeamName = trim($_POST['away_team_name'] ?? '');
            $isPrivate = !empty($_POST['is_private']) ? 1 : 0;
            $sportType = in_array($_POST['sport_type']??'', ['축구','풋살'], true) ? $_POST['sport_type'] : '풋살';
            $venueAddr = trim($_POST['venue_address'] ?? '');
            $venueSubway = trim($_POST['venue_subway'] ?? '');
            $venueParking = trim($_POST['venue_parking'] ?? '');
            $venueNote = trim($_POST['venue_note'] ?? '');
            $pdo->prepare(
                "INSERT INTO matches (title,match_type,sport_type,creator_id,location,venue_address,venue_subway,venue_parking,venue_note,match_date,match_time,match_end_time,max_players,level,home_team_id,away_team_name,region,district,format_type,match_style,uniform_color,allow_mercenary,is_private,fee_type,fee_amount,note,min_manner_score,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                trim($_POST['title'] ?? ''),
                $matchType,
                $sportType,
                (int)me()['id'],
                trim($_POST['location'] ?? ''),
                $venueAddr ?: null,
                $venueSubway ?: null,
                $venueParking ?: null,
                $venueNote ?: null,
                $_POST['match_date'],
                $_POST['match_time'],
                $matchEndTime,
                (int)($_POST['max_players'] ?? 12),
                $_POST['level'] ?? '모든실력',
                $homeTeamForType,
                $awayTeamName !== '' ? $awayTeamName : null,
                trim($_POST['region'] ?? ''),
                trim($_POST['district'] ?? ''),
                $_POST['format_type'] ?? '풋살',
                $_POST['match_style'] ?? '친선',
                $uniformColor,
                isset($_POST['allow_mercenary']) ? 1 : 0,
                $isPrivate,
                $_POST['fee_type'] ?? '없음',
                (int)($_POST['fee_amount'] ?? 0),
                trim($_POST['note'] ?? ''),
                $minManner,
                $matchType === 'REQUEST' ? 'request_pending' : 'open',
            ]);
            flash('매치가 개설되었습니다.');
            redirect('?page=matches');
            break;

        // [매치 수정] 작성자가 경기 시작 전 매치 정보 수정
        case 'update_match':
            requireLogin();
            $mid = (int)($_POST['match_id'] ?? 0);
            $mChk = $pdo->prepare("SELECT creator_id, status FROM matches WHERE id=?");
            $mChk->execute([$mid]); $mRow = $mChk->fetch();
            if (!$mRow) { flash('매치를 찾을 수 없습니다.','error'); redirect('?page=matches'); }
            $canEdit = isAdmin() || ((int)$mRow['creator_id'] === (int)me()['id']);
            $editableStatus = in_array($mRow['status'], ['open','request_pending','confirmed','checkin_open']);
            if (!$canEdit || !$editableStatus) {
                flash('수정 권한이 없거나 진행 중인 매치는 수정할 수 없습니다.','error');
                redirect('?page=match&id='.$mid);
            }
            $allowedMT2 = ['VENUE','VENUE_WANTED','REQUEST','MERC_ONLY'];
            $uniMap2 = uniformColorMap();
            $awayName2 = trim($_POST['away_team_name'] ?? '');
            $sportType2 = in_array($_POST['sport_type']??'', ['축구','풋살'], true) ? $_POST['sport_type'] : '풋살';
            // match_date / match_time 필수값 검증
            $matchDate2 = trim($_POST['match_date'] ?? '');
            $matchTime2 = trim($_POST['match_time'] ?? '');
            if (!$matchDate2 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $matchDate2)) {
                flash('올바른 경기 날짜를 입력해주세요.', 'error'); redirect('?page=match&id='.$mid);
            }
            if (!$matchTime2 || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $matchTime2)) {
                flash('올바른 경기 시간을 입력해주세요.', 'error'); redirect('?page=match&id='.$mid);
            }
            $pdo->prepare("UPDATE matches SET title=?, match_type=?, sport_type=?, location=?, venue_address=?, venue_subway=?, venue_parking=?, match_date=?, match_time=?, match_end_time=?,
                max_players=?, level=?, away_team_name=?, region=?, district=?, format_type=?, match_style=?, uniform_color=?,
                allow_mercenary=?, fee_type=?, fee_amount=?, note=?
                WHERE id=?")
                ->execute([
                    trim($_POST['title'] ?? ''),
                    in_array($_POST['match_type'] ?? 'VENUE', $allowedMT2, true) ? $_POST['match_type'] : 'VENUE',
                    $sportType2,
                    trim($_POST['location'] ?? ''),
                    trim($_POST['venue_address'] ?? '') ?: null,
                    trim($_POST['venue_subway'] ?? '') ?: null,
                    trim($_POST['venue_parking'] ?? '') ?: null,
                    $matchDate2,
                    $matchTime2,
                    !empty($_POST['match_end_time']) ? $_POST['match_end_time'] : null,
                    (int)($_POST['max_players'] ?? 12),
                    $_POST['level'] ?? '모든실력',
                    $awayName2 !== '' ? $awayName2 : null,
                    trim($_POST['region'] ?? ''),
                    trim($_POST['district'] ?? ''),
                    $_POST['format_type'] ?? '풋살',
                    $_POST['match_style'] ?? '친선',
                    in_array($_POST['uniform_color'] ?? '', array_keys($uniMap2), true) ? $_POST['uniform_color'] : '',
                    isset($_POST['allow_mercenary']) ? 1 : 0,
                    $_POST['fee_type'] ?? '없음',
                    (int)($_POST['fee_amount'] ?? 0),
                    trim($_POST['note'] ?? ''),
                    $mid,
                ]);
            flash('매치 정보가 수정되었습니다.');
            redirect('?page=match&id='.$mid);
            break;

        case 'apply_match':
            requireLogin();
            $matchId = (int)($_POST['match_id'] ?? 0);
            $teamId  = myTeamId();
            if (!$teamId) { flash('팀에 소속된 후 신청하세요.', 'error'); redirect('?page=matches'); }
            // [매너 패널티] 활동 제한 체크
            [$blocked, $until] = isRestricted($pdo, me()['id']);
            if ($blocked) { flash('매너점수 부족으로 '.date('m/d H:i', strtotime($until)).'까지 활동 제한 중입니다.', 'error'); redirect('?page=match&id='.$matchId); }
            // [매너필터] 매치의 min_manner_score 체크 — 신청 캡틴 본인 매너점수
            $matchInfo = $pdo->prepare("SELECT min_manner_score FROM matches WHERE id=?");
            $matchInfo->execute([$matchId]); $matchInfo = $matchInfo->fetch();
            if ($matchInfo && (float)$matchInfo['min_manner_score'] > 0) {
                $myManner = (float)$pdo->query("SELECT manner_score FROM users WHERE id=".(int)me()['id'])->fetchColumn();
                if ($myManner < (float)$matchInfo['min_manner_score']) {
                    flash('팀 캡틴의 매너점수가 기준에 미달합니다. (필요: '.$matchInfo['min_manner_score'].'°, 본인: '.$myManner.'°)', 'error');
                    redirect('?page=match&id='.$matchId);
                }
            }
            $dup = $pdo->prepare("SELECT id FROM match_requests WHERE match_id=? AND team_id=? AND status NOT IN ('rejected','cancelled')");
            $dup->execute([$matchId, $teamId]);
            if ($dup->fetch()) { flash('이미 신청한 매치입니다.', 'error'); redirect('?page=match&id='.$matchId); }
            $pdo->prepare("INSERT INTO match_requests (match_id,team_id,requested_by) VALUES (?,?,?)")
                ->execute([$matchId, $teamId, me()['id']]);
            $pdo->prepare("UPDATE matches SET status='request_pending' WHERE id=? AND status='open'")->execute([$matchId]);
            flash('신청 완료!');
            redirect('?page=match&id='.$matchId);
            break;

        case 'accept_request':
            requireLogin();
            $reqId = (int)($_POST['request_id'] ?? 0);
            $req   = $pdo->prepare("SELECT mr.*,m.home_team_id FROM match_requests mr JOIN matches m ON m.id=mr.match_id WHERE mr.id=?");
            $req->execute([$reqId]); $req = $req->fetch();
            if (!$req || $req['home_team_id'] != myTeamId()) { flash('권한이 없습니다.', 'error'); redirect('?page=home'); }
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE match_requests SET status='accepted',responded_by=?,responded_at=NOW() WHERE id=?")->execute([me()['id'],$reqId]);
            $pdo->prepare("UPDATE matches SET away_team_id=?,status='confirmed' WHERE id=?")->execute([$req['team_id'],$req['match_id']]);
            $pdo->commit();
            flash('신청을 수락했습니다.');
            redirect('?page=match&id='.$req['match_id']);
            break;

        case 'reject_request':
            requireLogin();
            $reqId = (int)($_POST['request_id'] ?? 0);
            $pdo->prepare("UPDATE match_requests SET status='rejected',responded_by=?,responded_at=NOW() WHERE id=?")->execute([me()['id'],$reqId]);
            $mid = $pdo->prepare("SELECT match_id FROM match_requests WHERE id=?");
            $mid->execute([$reqId]); $mid = $mid->fetchColumn();
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM match_requests WHERE match_id=? AND status='pending'");
            $cnt->execute([$mid]);
            if (!$cnt->fetchColumn()) $pdo->prepare("UPDATE matches SET status='open' WHERE id=?")->execute([$mid]);
            flash('신청을 거절했습니다.');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=home');
            break;

        case 'checkin':
            requireLogin();
            $matchId = (int)($_POST['match_id'] ?? 0);
            $match   = $pdo->prepare("SELECT * FROM matches WHERE id=?");
            $match->execute([$matchId]); $match = $match->fetch();
            if (!$match || $match['match_date'] !== date('Y-m-d')) {
                flash('경기 당일에만 체크인 가능합니다.', 'error');
                redirect('?page=match&id='.$matchId);
            }
            if (!myTeamId()) { flash('팀 소속 후 체크인 가능합니다.', 'error'); redirect('?page=match&id='.$matchId); }
            try {
                $pdo->prepare("INSERT INTO checkins (match_id,user_id,team_id) VALUES (?,?,?)")
                    ->execute([$matchId, me()['id'], myTeamId()]);
                flash('체크인 완료!');
            } catch (PDOException) { flash('이미 체크인했습니다.', 'error'); }
            redirect('?page=match&id='.$matchId);
            break;

        case 'cancel_checkin':
            requireLogin();
            $matchId = (int)($_POST['match_id'] ?? 0);
            $match   = $pdo->prepare("SELECT match_date FROM matches WHERE id=?");
            $match->execute([$matchId]); $match = $match->fetch();
            if (!$match || $match['match_date'] !== date('Y-m-d')) {
                flash('당일만 체크인 취소 가능합니다.', 'error');
                redirect('?page=match&id='.$matchId);
            }
            $pdo->prepare("DELETE FROM checkins WHERE match_id=? AND user_id=?")
                ->execute([$matchId, (int)me()['id']]);
            flash('체크인이 취소되었습니다.');
            redirect('?page=match&id='.$matchId);
            break;

        case 'submit_result':
            requireLogin();
            $matchId = (int)($_POST['match_id'] ?? 0);
            $hs = max(0,(int)($_POST['home_score']??0));
            $as = max(0,(int)($_POST['away_score']??0));
            $match = $pdo->prepare("SELECT * FROM matches WHERE id=?");
            $match->execute([$matchId]); $match = $match->fetch();
            if (!$match) { flash('매치를 찾을 수 없습니다.','error'); redirect('?page=matches'); }
            if (!in_array(myTeamId(),[$match['home_team_id'],$match['away_team_id']])) {
                flash('해당 매치 팀만 결과를 입력할 수 있습니다.', 'error');
                redirect('?page=match&id='.$matchId);
            }
            $ex = $pdo->prepare("SELECT id FROM match_results WHERE match_id=?");
            $ex->execute([$matchId]);
            if ($ex->fetch()) {
                $pdo->prepare("UPDATE match_results SET score_home=?,score_away=?,reporter_id=?,is_approved=0 WHERE match_id=?")
                    ->execute([$hs,$as,me()['id'],$matchId]);
            } else {
                $pdo->prepare("INSERT INTO match_results (match_id,score_home,score_away,reporter_id) VALUES (?,?,?,?)")
                    ->execute([$matchId,$hs,$as,me()['id']]);
            }
            $pdo->prepare("UPDATE matches SET status='result_pending' WHERE id=?")->execute([$matchId]);
            // [자동] 참석자 전원 match_player_records 생성 (없는 경우만 INSERT IGNORE)
            $attAll = $pdo->prepare("
                SELECT ma.user_id, ma.team_id,
                       CASE WHEN tm.role='mercenary' THEN 1 ELSE 0 END AS is_merc
                FROM match_attendance ma
                LEFT JOIN team_members tm ON tm.team_id=ma.team_id AND tm.user_id=ma.user_id AND tm.status='active'
                WHERE ma.match_id=? AND ma.status='ATTEND'
            ");
            $attAll->execute([$matchId]);
            foreach ($attAll->fetchAll() as $aa) {
                $pdo->prepare("INSERT IGNORE INTO match_player_records (match_id, user_id, team_id, is_mercenary, goals, assists, is_checked_in, match_date) VALUES (?,?,?,?,0,0,1,?)")
                    ->execute([$matchId, (int)$aa['user_id'], (int)$aa['team_id'], (int)$aa['is_merc'], $match['match_date']]);
            }
            flash('결과 입력 완료! 아래에서 개인 기록(골/어시)도 등록하세요.');
            redirect('?page=match&id='.$matchId);
            break;

        case 'approve_result':
            requireLogin();
            $matchId = (int)($_POST['match_id'] ?? 0);
            $r = $pdo->prepare("SELECT mr.*,m.home_team_id,m.away_team_id,m.match_date FROM match_results mr JOIN matches m ON m.id=mr.match_id WHERE mr.match_id=?");
            $r->execute([$matchId]); $r = $r->fetch();
            if (!$r || (int)$r['reporter_id']==(int)me()['id']) { flash('승인 권한이 없습니다.', 'error'); redirect('?page=match&id='.$matchId); }
            // 해당 매치의 홈/어웨이 팀 소속이어야 승인 가능
            if (!in_array(myTeamId(), [(int)$r['home_team_id'], (int)$r['away_team_id']])) {
                flash('해당 매치 팀 소속만 결과를 승인할 수 있습니다.', 'error'); redirect('?page=match&id='.$matchId);
            }
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE match_results SET is_approved=1 WHERE match_id=?")->execute([$matchId]);
            // [1-2] 결과 승인 시 status='result_pending' (양팀 평가 완료 후 'completed'로 전환)
            $pdo->prepare("UPDATE matches SET status='result_pending', evaluation_step=0 WHERE id=?")->execute([$matchId]);
            // [TF-17] 결과 승인 시 pending 요청 자동 만료
            $pdo->prepare("UPDATE mercenary_requests SET status='expired' WHERE match_id=? AND status='pending'")->execute([$matchId]);
            $pdo->prepare("UPDATE match_requests SET status='expired' WHERE match_id=? AND status='pending'")->execute([$matchId]);
            // 체크인 기반 match_player_records 생성 (평가 무관 — 즉시)
            $cks = $pdo->prepare("SELECT user_id,team_id FROM checkins WHERE match_id=? AND status='confirmed'");
            $cks->execute([$matchId]); $cks = $cks->fetchAll();
            foreach ($cks as $ck) {
                $isMerc = 0;
                $tm = $pdo->prepare("SELECT role FROM team_members WHERE user_id=? AND team_id=? AND status='active' LIMIT 1");
                $tm->execute([$ck['user_id'],$ck['team_id']]); $tmRole = $tm->fetchColumn();
                if ($tmRole === 'mercenary') $isMerc = 1;
                try {
                    $pdo->prepare("INSERT IGNORE INTO match_player_records (match_id,user_id,team_id,is_mercenary,is_checked_in,match_date) VALUES (?,?,?,?,1,?)")
                        ->execute([$matchId,$ck['user_id'],$ck['team_id'],$isMerc,$r['match_date']]);
                } catch(PDOException) {}
            }
            // [팀 결과 자동 반영] 승/무/패 + 득점/실점 — 트랜잭션 내에서 수행
            $sh = (int)$r['score_home']; $sa = (int)$r['score_away'];
            $htid = (int)$r['home_team_id']; $atid = (int)$r['away_team_id'];
            if ($htid) {
                if ($sh > $sa) { $pdo->prepare("UPDATE teams SET win=win+1, goals_for=goals_for+?, goals_against=goals_against+? WHERE id=?")->execute([$sh,$sa,$htid]); }
                elseif ($sh < $sa) { $pdo->prepare("UPDATE teams SET loss=loss+1, goals_for=goals_for+?, goals_against=goals_against+? WHERE id=?")->execute([$sh,$sa,$htid]); }
                else { $pdo->prepare("UPDATE teams SET draw=draw+1, goals_for=goals_for+?, goals_against=goals_against+? WHERE id=?")->execute([$sh,$sa,$htid]); }
            }
            if ($atid) {
                if ($sa > $sh) { $pdo->prepare("UPDATE teams SET win=win+1, goals_for=goals_for+?, goals_against=goals_against+? WHERE id=?")->execute([$sa,$sh,$atid]); }
                elseif ($sa < $sh) { $pdo->prepare("UPDATE teams SET loss=loss+1, goals_for=goals_for+?, goals_against=goals_against+? WHERE id=?")->execute([$sa,$sh,$atid]); }
                else { $pdo->prepare("UPDATE teams SET draw=draw+1, goals_for=goals_for+?, goals_against=goals_against+? WHERE id=?")->execute([$sa,$sh,$atid]); }
            }
            $pdo->commit();
            // 양팀 캡틴에게 평가 알림 (트랜잭션 커밋 후)
            foreach ([$r['home_team_id'], $r['away_team_id']] as $tid) {
                if (!$tid) continue;
                $cap = $pdo->prepare("SELECT user_id FROM team_members WHERE team_id=? AND role='captain' AND status='active' LIMIT 1");
                $cap->execute([$tid]);
                if ($cid = $cap->fetchColumn()) {
                    notify($pdo, (int)$cid, 'EVAL', '오늘 경기 어떠셨나요?', '상대팀을 평가해주셔야 결과가 공식 반영됩니다.', '?page=team_eval&match_id='.$matchId);
                }
            }
            flash('결과가 승인되었습니다! 팀 승/무/패와 득실이 반영되었습니다.');
                // 양 팀에 포인트 풀 100P 지급
                foreach ([$homeTeamId ?? 0, $awayTeamId ?? 0] as $_poolTid) {
                    if ($_poolTid > 0) {
                        $dupPool = $pdo->prepare("SELECT id FROM team_point_pool WHERE team_id=? AND match_id=?");
                        $dupPool->execute([$_poolTid, $matchId]);
                        if (!$dupPool->fetch()) {
                            $pdo->prepare("INSERT INTO team_point_pool (team_id, match_id, total_points, remaining) VALUES (?,?,100,100)")
                                ->execute([$_poolTid, $matchId]);
                        }
                    }
                }
            redirect('?page=match&id='.$matchId);
            break;

        case 'dispute_result':
            requireLogin();
            $matchId = (int)($_POST['match_id'] ?? 0);
            $reason  = trim($_POST['reason'] ?? '');
            if (!$reason) { flash('분쟁 사유를 입력하세요.', 'error'); redirect('?page=match&id='.$matchId); }
            // 해당 매치의 홈/어웨이 팀 소속이어야 분쟁 제기 가능
            $dMatch = $pdo->prepare("SELECT home_team_id, away_team_id FROM matches WHERE id=?");
            $dMatch->execute([$matchId]); $dMatch = $dMatch->fetch();
            if (!$dMatch) { flash('매치를 찾을 수 없습니다.','error'); redirect('?page=matches'); }
            if (!in_array(myTeamId(), [(int)$dMatch['home_team_id'], (int)$dMatch['away_team_id']])) {
                flash('해당 매치 팀 소속만 분쟁을 제기할 수 있습니다.', 'error'); redirect('?page=match&id='.$matchId);
            }
            $pdo->prepare("UPDATE matches SET status='disputed' WHERE id=?")->execute([$matchId]);
            $pdo->prepare("UPDATE match_results SET report_content=? WHERE match_id=?")->execute([$reason,$matchId]);
            $pdo->prepare("INSERT INTO reports (reporter_id,target_type,target_id,reason) VALUES (?,?,?,?)")
                ->execute([me()['id'],'match',$matchId,'[결과분쟁] '.$reason]);
            flash('분쟁이 접수되었습니다.');
            redirect('?page=match&id='.$matchId);
            break;

        case 'submit_report':
            requireLogin();
            $tt  = in_array($_POST['target_type']??'',['user','team','match']) ? $_POST['target_type'] : 'user';
            $tid = (int)($_POST['target_id'] ?? 0);
            $rsn = trim($_POST['reason'] ?? '');
            if (!$rsn) { flash('신고 사유를 입력하세요.', 'error'); redirect($_SERVER['HTTP_REFERER']??'?page=home'); }
            $pdo->prepare("INSERT INTO reports (reporter_id,target_type,target_id,reason) VALUES (?,?,?,?)")
                ->execute([me()['id'],$tt,$tid,$rsn]);
            flash('신고가 접수되었습니다.');
            redirect($_SERVER['HTTP_REFERER']??'?page=home');
            break;

        case 'resolve_report':
            requireLogin(); if(!isAdmin()){flash('권한 없음','error');redirect('?page=home');}
            $st = in_array($_POST['status']??'',['resolved','dismissed'])?$_POST['status']:'resolved';
            $pdo->prepare("UPDATE reports SET status=?,admin_note=? WHERE id=?")
                ->execute([$st, trim($_POST['admin_note']??''), (int)($_POST['report_id']??0)]);
            flash('처리 완료.'); redirect('?page=admin_reports');
            break;

        case 'add_fee':
            requireLogin(); if(!isCaptain()){flash('캡틴만 가능합니다.','error');redirect('?page=fees');}
            $type = in_array($_POST['type']??'',['회비','참가비','보증금','환불','후원금','연회비','벌금','기타'])?$_POST['type']:'기타';
            $pdo->prepare("INSERT INTO fees (team_id,user_id,amount,type,status,memo) VALUES (?,?,?,?,?,?)")
                ->execute([myTeamId(),(int)$_POST['user_id'],(int)$_POST['amount'],$type,'미납',trim($_POST['memo']??'')]);
            flash('항목이 추가되었습니다.'); redirect('?page=fees&tab=special');
            break;

        case 'add_monthly_fee':
            requireLogin(); if(!isCaptain()){flash('캡틴만 가능합니다.','error');redirect('?page=fees');}
            $tid = myTeamId();
            $month = $_POST['month'] ?? date('Y-m');
            $team = $pdo->prepare("SELECT membership_fee FROM teams WHERE id=?");
            $team->execute([$tid]); $feeAmt = (int)($team->fetchColumn() ?: 0);
            if ($feeAmt <= 0) { flash('팀 설정에서 월 회비를 먼저 설정해주세요.', 'error'); redirect('?page=fees&tab=fees'); }
            $members = $pdo->prepare("SELECT user_id FROM team_members WHERE team_id=? AND status='active' AND role != 'mercenary'");
            $members->execute([$tid]); $memberIds = $members->fetchAll(PDO::FETCH_COLUMN);
            $added = 0;
            foreach ($memberIds as $uid) {
                $exists = $pdo->prepare("SELECT id FROM fees WHERE team_id=? AND user_id=? AND type='회비' AND created_at >= ? AND created_at < ?");
                $exists->execute([$tid, $uid, $month.'-01', date('Y-m-01', strtotime($month.'-01 +1 month'))]);
                if (!$exists->fetch()) {
                    $pdo->prepare("INSERT INTO fees (team_id,user_id,amount,type,status,memo) VALUES (?,?,?,?,?,?)")
                        ->execute([$tid, $uid, $feeAmt, '회비', '미납', date('n월', strtotime($month.'-01')).' 회비']);
                    $added++;
                }
            }
            flash($added.'명에게 '.number_format($feeAmt).'원 회비가 부과되었습니다.');
            redirect('?page=fees&tab=fees&month='.$month);
            break;

        case 'toggle_fee':
            requireLogin(); if(!isCaptain()){flash('권한 없음','error');redirect('?page=fees');}
            $uid = (int)($_POST['user_id'] ?? 0);
            $month = $_POST['month'] ?? date('Y-m');
            $cur = $_POST['current'] ?? 'unpaid';
            $tid = myTeamId();
            if ($cur === 'paid') {
                $pdo->prepare("UPDATE fees SET status='미납' WHERE team_id=? AND user_id=? AND type='회비' AND status='납부' AND created_at >= ? AND created_at < ?")
                    ->execute([$tid, $uid, $month.'-01', date('Y-m-01', strtotime($month.'-01 +1 month'))]);
            } else {
                $exists = $pdo->prepare("SELECT id FROM fees WHERE team_id=? AND user_id=? AND type='회비' AND created_at >= ? AND created_at < ?");
                $exists->execute([$tid, $uid, $month.'-01', date('Y-m-01', strtotime($month.'-01 +1 month'))]);
                if ($exists->fetch()) {
                    $pdo->prepare("UPDATE fees SET status='납부' WHERE team_id=? AND user_id=? AND type='회비' AND created_at >= ? AND created_at < ?")
                        ->execute([$tid, $uid, $month.'-01', date('Y-m-01', strtotime($month.'-01 +1 month'))]);
                } else {
                    $team = $pdo->prepare("SELECT membership_fee FROM teams WHERE id=?");
                    $team->execute([$tid]); $feeAmt = (int)($team->fetchColumn() ?: 0);
                    if ($feeAmt <= 0) $feeAmt = 0;
                    $pdo->prepare("INSERT INTO fees (team_id,user_id,amount,type,status,memo) VALUES (?,?,?,?,?,?)")
                        ->execute([$tid, $uid, $feeAmt, '회비', '납부', date('n월', strtotime($month.'-01')).' 회비']);
                }
            }
            $yr = $_POST['year'] ?? date('Y');
            redirect('?page=fees&tab=fees&year='.$yr);
            break;

        case 'pay_fee_by_user':
            requireLogin(); if(!isCaptain()){flash('캡틴만 가능합니다.','error');redirect('?page=fees');}
            $uid = (int)($_POST['user_id'] ?? 0);
            $month = $_POST['month'] ?? date('Y-m');
            $pdo->prepare("UPDATE fees SET status='납부' WHERE team_id=? AND user_id=? AND type='회비' AND status='미납' AND created_at >= ? AND created_at < ?")
                ->execute([myTeamId(), $uid, $month.'-01', date('Y-m-01', strtotime($month.'-01 +1 month'))]);
            flash('납부 처리되었습니다.');
            redirect('?page=fees&tab=fees&month='.$month);
            break;

        // ── 어드민 보증금 추가 (팀 소속 없어도 가능) ──
        case 'admin_deposit_add':
            requireLogin(); if(!isAdmin()){flash('권한 없음','error');redirect('?page=home');}
            $uid    = (int)($_POST['user_id'] ?? 0);
            $amount = (int)($_POST['amount']  ?? 0);
            $memo   = trim($_POST['memo'] ?? '보증금 납부');
            if (!$uid || $amount < 1) { flash('유저와 금액을 확인하세요.','error'); redirect('?page=admin_deposit'); }
            // 해당 유저의 팀 조회 (없으면 team_id=0 허용하도록 NULL 처리)
            $utid = $pdo->prepare("SELECT team_id FROM team_members WHERE user_id=? AND status='active' LIMIT 1");
            $utid->execute([$uid]); $utid = $utid->fetchColumn() ?: null;
            $pdo->prepare("INSERT INTO fees (team_id,user_id,amount,type,status,memo) VALUES (?,?,?,?,?,?)")
                ->execute([$utid, $uid, $amount, '보증금', '납부', $memo]);
            flash('보증금이 추가되었습니다.'); redirect('?page=admin_deposit');
            break;

        case 'pay_fee':
            requireLogin(); if(!isCaptain()){flash('권한 없음','error');redirect('?page=fees');}
            $pdo->prepare("UPDATE fees SET status='납부' WHERE id=?")->execute([(int)($_POST['fee_id']??0)]);
            flash('납부 처리 완료.'); redirect('?page=fees');
            break;

        case 'create_league':
            requireLogin(); if(!isAdmin()){flash('권한 없음','error');redirect('?page=home');}
            $pdo->prepare("INSERT INTO leagues (name,region,district,season) VALUES (?,?,?,?)")
                ->execute([trim($_POST['name']),trim($_POST['region']??''),trim($_POST['district']??''),trim($_POST['season']??'')]);
            flash('리그가 생성되었습니다.'); redirect('?page=leagues');
            break;

        case 'join_league':
            requireLogin(); if(!isCaptain()){flash('캡틴만 가능합니다.','error');redirect('?page=leagues');}
            try {
                $pdo->prepare("INSERT INTO league_teams (league_id,team_id) VALUES (?,?)")->execute([(int)$_POST['league_id'],myTeamId()]);
                flash('리그에 등록되었습니다.');
            } catch(PDOException){flash('이미 등록된 리그입니다.','error');}
            redirect('?page=league&id='.(int)$_POST['league_id']);
            break;

        // ── 약관 동의 ──
        case 'save_agreements':
            requireLogin();
            $actives = $pdo->query("SELECT agreement_type,version,is_required FROM agreement_versions WHERE is_active=1")->fetchAll();
            foreach ($actives as $av) {
                $checked = !empty($_POST['agree_'.$av['agreement_type']]);
                if (!$checked && $av['is_required']) {
                    flash('필수 약관에 모두 동의해야 합니다.', 'error'); redirect('?page=agreements');
                }
                if ($checked) {
                    $pdo->prepare("INSERT INTO user_agreements (user_id,agreement_type,version) VALUES (?,?,?)
                        ON DUPLICATE KEY UPDATE version=VALUES(version),agreed_at=NOW()")
                        ->execute([me()['id'], $av['agreement_type'], $av['version']]);
                }
            }
            unset($_SESSION['requires_reagreement']);
            flash('약관 동의가 완료되었습니다.');
            redirect('?page=home');
            break;

        // [TF-11] 제재 이의제기 제출
        case 'submit_appeal':
            requireLogin();
            $reason = trim($_POST['appeal_reason'] ?? '');
            if (!$reason || mb_strlen($reason) < 10) {
                flash('이의제기 사유를 10자 이상 입력해주세요.', 'error');
                redirect('?page=home');
            }
            $userId = (int)me()['id'];
            $existing = $pdo->prepare("SELECT id FROM user_appeals WHERE user_id=? AND status='pending' LIMIT 1");
            $existing->execute([$userId]);
            if ($existing->fetch()) {
                flash('이미 처리 대기 중인 이의제기가 있습니다.', 'error');
                redirect('?page=home');
            }
            $pdo->prepare("INSERT INTO user_appeals (user_id, reason) VALUES (?, ?)")
                ->execute([$userId, $reason]);
            flash('이의제기가 접수되었습니다. 관리자 검토 후 결과를 알려드립니다.');
            redirect('?page=home');
            break;

        // [TF-11] 관리자: 이의제기 처리
        case 'review_appeal':
            requireLogin();
            if (!isAdmin()) { flash('권한 없음', 'error'); redirect('?page=home'); }
            $appealId = (int)($_POST['appeal_id'] ?? 0);
            $decision = $_POST['decision'] ?? '';
            $adminNote = trim($_POST['admin_note'] ?? '');
            if (!$appealId || !in_array($decision, ['approved','rejected'])) {
                flash('잘못된 요청', 'error'); redirect('?page=admin_dashboard&tab=appeals');
            }
            $appeal = $pdo->prepare("SELECT * FROM user_appeals WHERE id=? AND status='pending'");
            $appeal->execute([$appealId]); $appeal = $appeal->fetch();
            if (!$appeal) {
                flash('이의제기를 찾을 수 없습니다.', 'error'); redirect('?page=admin_dashboard&tab=appeals');
            }
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE user_appeals SET status=?, admin_note=?, reviewed_at=NOW() WHERE id=?")
                ->execute([$decision, $adminNote, $appealId]);
            if ($decision === 'approved') {
                $pdo->prepare("UPDATE users SET restricted_until=NULL, ban_reason=NULL WHERE id=?")->execute([$appeal['user_id']]);
                $pdo->prepare("UPDATE user_penalties SET expires_at=NOW() WHERE user_id=? AND (expires_at IS NULL OR expires_at > NOW())")->execute([$appeal['user_id']]);
                notify($pdo, (int)$appeal['user_id'], 'SYSTEM', '이의제기 승인', '이의제기가 승인되어 제재가 해제되었습니다.', '?page=home');
            } else {
                notify($pdo, (int)$appeal['user_id'], 'SYSTEM', '이의제기 기각', '이의제기가 기각되었습니다.' . ($adminNote ? ' 사유: '.$adminNote : ''), '?page=home');
            }
            $pdo->commit();
            logAdminAction($pdo, 'review_appeal', 'user', (int)$appeal['user_id'], $decision.': '.$adminNote);
            flash('이의제기를 '.($decision==='approved'?'승인':'기각').'했습니다.');
            redirect('?page=admin_dashboard&tab=appeals');
            break;

        // ── 유저 차단 ──
        case 'block_user':
            requireLogin();
            $blockedId = (int)($_POST['blocked_id'] ?? 0);
            if ($blockedId && $blockedId !== me()['id']) {
                try {
                    $pdo->prepare("INSERT INTO user_blocks (blocker_id,blocked_id) VALUES (?,?)")
                        ->execute([me()['id'],$blockedId]);
                    // TF-22: 차단 시 친구 관계 정리
                    $pdo->prepare("UPDATE friendships SET status='BLOCKED' WHERE (requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?)")
                        ->execute([me()['id'], $blockedId, $blockedId, me()['id']]);
                    flash('해당 유저를 차단했습니다.');
                } catch(PDOException) { flash('이미 차단한 유저입니다.','error'); }
            }
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=home');
            break;

        // ── 유저 차단 해제 ──
        case 'unblock_user':
            requireLogin();
            $blockedId = (int)($_POST['blocked_id'] ?? 0);
            $pdo->prepare("DELETE FROM user_blocks WHERE blocker_id=? AND blocked_id=?")
                ->execute([me()['id'],$blockedId]);
            flash('차단이 해제되었습니다.');
            redirect('?page=mypage');
            break;

        // ── 참석 투표 ──
        case 'vote_attendance':
            requireLogin();
            $matchId = (int)($_POST['match_id'] ?? 0);
            $vote    = $_POST['vote'] ?? '';
            if (!in_array($vote, ['ATTEND','ABSENT','PENDING'])) { flash('잘못된 요청','error'); redirect('?page=home'); }
            $match = $pdo->prepare("SELECT status FROM matches WHERE id=?");
            $match->execute([$matchId]); $match = $match->fetch();
            if (!$match || in_array($match['status'], ['finished','cancelled'])) {
                flash('투표할 수 없는 경기입니다.','error'); redirect('?page=home');
            }
            $teamId = myTeamId();

            // ── [TF-20] 참석→불참 변경 시 시간대별 노쇼/페널티 판정 ──
            if ($vote === 'ABSENT') {
                // 기존 참석 여부 확인 (ATTEND→ABSENT 전환인지 체크)
                $prevAtt = $pdo->prepare("SELECT status FROM match_attendance WHERE match_id=? AND user_id=?");
                $prevAtt->execute([$matchId, me()['id']]); $prevAtt = $prevAtt->fetch();
                $wasAttending = ($prevAtt && $prevAtt['status'] === 'ATTEND');

                if ($wasAttending) {
                    // 매치 시작까지 남은 시간 계산
                    $mInfo = $pdo->prepare("SELECT match_date, match_time FROM matches WHERE id=?");
                    $mInfo->execute([$matchId]); $mInfo = $mInfo->fetch();
                    $matchDateTime = $mInfo['match_date'] . ' ' . ($mInfo['match_time'] ?? '00:00:00');
                    $hoursUntilMatch = (strtotime($matchDateTime) - time()) / 3600;

                    if ($hoursUntilMatch < 1) {
                        // 1시간 미만: 노쇼 처리 — 100% 페널티 + 매너점수 감점 + 경고
                        applyMannerDelta($pdo, me()['id'], -5.0, $matchId, '노쇼 (경기 1시간 전 이내 취소)');
                        notify($pdo, me()['id'], 'NO_SHOW', '노쇼 경고',
                            '경기 1시간 전 이내 취소로 노쇼 처리되었습니다. 매너점수 -5점이 차감됩니다.',
                            '?page=match&id='.$matchId);
                        flash('경기 1시간 전 이내 취소로 노쇼 처리됩니다. 매너점수 -5점 차감.', 'error');
                    } elseif ($hoursUntilMatch < 24) {
                        // 1~24시간: 50% 페널티 — 매너점수 소폭 감점
                        applyMannerDelta($pdo, me()['id'], -2.0, $matchId, '늦은 취소 (경기 24시간 전 이내)');
                        notify($pdo, me()['id'], 'LATE_CANCEL', '늦은 취소 알림',
                            '경기 24시간 전 이내 취소로 매너점수 -2점이 차감됩니다.',
                            '?page=match&id='.$matchId);
                        flash('경기 24시간 전 이내 취소입니다. 매너점수 -2점 차감.', 'error');
                    }
                    // 24시간 이상: 페널티 없음 (정상 취소)
                }
            }

            $pdo->prepare("
                INSERT INTO match_attendance (match_id,team_id,user_id,status) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE status=VALUES(status),team_id=VALUES(team_id)
            ")->execute([$matchId,$teamId,me()['id'],$vote]);
            // 참석 시 → 아직 쿼터 배정 안 된 쿼터에 본인 포지션으로 자동 배정
            if ($vote === 'ATTEND' && $teamId) {
                $posMap = ['DF'=>'CB','MF'=>'CM','FW'=>'ST'];
                $rawPos = me()['position'] ?? 'MF';
                $autoPos = $posMap[$rawPos] ?? ($rawPos ?: 'CM');
                for ($aq = 1; $aq <= 4; $aq++) {
                    $exists = $pdo->prepare("SELECT id FROM match_quarters WHERE match_id=? AND team_id=? AND quarter=? AND user_id=?");
                    $exists->execute([$matchId, $teamId, $aq, me()['id']]);
                    if (!$exists->fetch()) {
                        $pdo->prepare("INSERT IGNORE INTO match_quarters (match_id,team_id,quarter,user_id,position,assigned_by) VALUES (?,?,?,?,?,?)")
                            ->execute([$matchId, $teamId, $aq, me()['id'], $autoPos, me()['id']]);
                    }
                }
            }
            // 불참 시 → 쿼터 배정 제거
            if ($vote === 'ABSENT' && $teamId) {
                $pdo->prepare("DELETE FROM match_quarters WHERE match_id=? AND team_id=? AND user_id=?")
                    ->execute([$matchId, $teamId, me()['id']]);
            }
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=home');
            break;

        // ── 용병 프로필 등록/수정 ──
        case 'save_mercenary':
            requireLogin();
            $positions  = trim($_POST['positions'] ?? 'CM');
            $level      = $_POST['level'] ?? '아마';
            $region     = trim($_POST['region'] ?? '서울');
            $district   = trim($_POST['district'] ?? '');
            $avail_time = trim($_POST['available_time'] ?? '');
            $formats    = trim($_POST['format_types'] ?? '');
            $intro      = trim($_POST['intro'] ?? '');
            $fee_pref   = $_POST['fee_preference'] ?? '협의';
            $foot       = in_array($_POST['preferred_foot']??'',['LEFT','RIGHT','BOTH']) ? $_POST['preferred_foot'] : null;
            $hVal       = (int)($_POST['height'] ?? 0);
            $height     = ($hVal >= 140 && $hVal <= 220) ? $hVal : null;
            $wVal       = (int)($_POST['weight'] ?? 0);
            $weight     = ($wVal >= 30 && $wVal <= 200) ? $wVal : null;
            $isPlayer   = !empty($_POST['is_player_background']) ? 1 : 0;
            $lookingTeam = !empty($_POST['looking_for_team']) ? 1 : 0;
            $pdo->prepare("
                INSERT INTO mercenaries (user_id,positions,level,region,district,available_time,format_types,intro,fee_preference,preferred_foot,height,weight,is_player_background,looking_for_team,is_active)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)
                ON DUPLICATE KEY UPDATE positions=VALUES(positions),level=VALUES(level),region=VALUES(region),
                district=VALUES(district),available_time=VALUES(available_time),format_types=VALUES(format_types),
                intro=VALUES(intro),fee_preference=VALUES(fee_preference),preferred_foot=VALUES(preferred_foot),
                height=VALUES(height),weight=VALUES(weight),is_player_background=VALUES(is_player_background),looking_for_team=VALUES(looking_for_team),is_active=1
            ")->execute([me()['id'],$positions,$level,$region,$district,$avail_time,$formats,$intro,$fee_pref,$foot,$height,$weight,$isPlayer,$lookingTeam]);
            flash('용병 프로필이 저장되었습니다.');
            redirect('?page=mercenaries');
            break;

        // ── 용병 신청 ──
        case 'mercenary_apply':
            requireLogin();
            $matchId = (int)($_POST['match_id'] ?? 0);
            $teamId  = (int)($_POST['team_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');
            // [매너 패널티] 활동 제한 체크
            [$blocked, $until] = isRestricted($pdo, me()['id']);
            if ($blocked) { flash('매너점수 부족으로 '.date('m/d H:i', strtotime($until)).'까지 활동 제한 중입니다.', 'error'); redirect('?page=match&id='.$matchId); }
            try {
                $pdo->prepare("INSERT INTO mercenary_requests (match_id,team_id,user_id,message) VALUES (?,?,?,?)")
                    ->execute([$matchId,$teamId,me()['id'],$message]);
                flash('용병 신청 완료! 팀 캡틴의 승인을 기다리세요.');
            } catch(PDOException) { flash('이미 신청한 매치입니다.','error'); }
            redirect('?page=match&id='.$matchId);
            break;

        // ── 용병 신청 수락/거절 ──
        case 'mercenary_respond':
            requireLogin(); if(!isCaptain()){flash('캡틴만 가능합니다.','error');redirect('?page=mercenaries');}
            $reqId  = (int)($_POST['req_id'] ?? 0);
            $rawStatus = (string)($_POST['status'] ?? '');
            $allowed = ['accepted','rejected','cancelled'];
            if (!in_array($rawStatus, $allowed, true)) { flash('잘못된 요청','error'); redirect('?page=mercenaries'); }
            // 내 팀 요청만 처리 가능 (보안)
            $req = $pdo->prepare("SELECT * FROM mercenary_requests WHERE id=? AND team_id=?");
            $req->execute([$reqId, myTeamId()]); $req = $req->fetch();
            if (!$req) { flash('요청을 찾을 수 없습니다.','error'); redirect('?page=mercenaries'); }
            $isOffer = ($req['offer_type'] ?? 'apply') === 'offer';
            // [제안/지원 권한 분리]
            //   apply (선수가 지원한 것): 캡틴이 수락/거절 가능
            //   offer (캡틴이 제안한 것): 캡틴은 취소(cancelled)만 가능, 수락/거절은 선수가 따로 액션
            if ($isOffer && $rawStatus !== 'cancelled') {
                flash('우리가 제안한 용병은 선수가 응답해야 합니다. 취소만 가능해요.', 'error');
                redirect('?page=mercenaries');
            }
            if (!$isOffer && $rawStatus === 'cancelled') {
                flash('선수 지원은 수락/거절로만 처리 가능합니다.', 'error');
                redirect('?page=mercenaries');
            }
            $pdo->prepare("UPDATE mercenary_requests SET status=?,responded_at=NOW() WHERE id=?")->execute([$rawStatus, $reqId]);
            if ($rawStatus === 'accepted') {
                try {
                    $pdo->prepare("INSERT INTO team_members (team_id,user_id,role,status) VALUES (?,?,'mercenary','active')")
                        ->execute([$req['team_id'],$req['user_id']]);
                } catch(PDOException) {}
                // 선수에게 알림
                notify($pdo, (int)$req['user_id'], 'MERCENARY', '✓ 용병 지원 수락', '지원하신 경기에 용병으로 확정되었습니다.', '?page=match&id='.(int)$req['match_id']);
            } elseif ($rawStatus === 'rejected') {
                notify($pdo, (int)$req['user_id'], 'MERCENARY', '용병 지원 결과', '아쉽게도 이번 지원은 거절되었습니다.', '?page=mercenaries');
            }
            // cancelled는 선수에게 굳이 알림 안 보냄 (조용히 처리)
            $msgMap = ['accepted'=>'용병을 수락했습니다!','rejected'=>'용병 신청을 거절했습니다.','cancelled'=>'제안을 취소했습니다.'];
            flash($msgMap[$rawStatus] ?? '처리 완료');
            redirect('?page=mercenaries');
            break;

        // ── 팀원 모집 게시글 작성 ──
        case 'create_recruit':
            requireLogin(); if(!isCaptain()){flash('캡틴만 가능합니다.','error');redirect('?page=recruits');}
            $pdo->prepare("
                INSERT INTO recruit_posts (team_id,positions_needed,recruit_count,level_required,region,district,play_time,team_style,age_range,membership_fee,intro)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                myTeamId(),
                trim($_POST['positions_needed'] ?? '무관'),
                (int)($_POST['recruit_count'] ?? 1),
                $_POST['level_required'] ?? '무관',
                trim($_POST['region'] ?? '서울'),
                trim($_POST['district'] ?? ''),
                trim($_POST['play_time'] ?? ''),
                $_POST['team_style'] ?? '친선',
                $_POST['age_range'] ?? '무관',
                $_POST['membership_fee'] ?? '협의',
                trim($_POST['intro'] ?? ''),
            ]);
            flash('모집 게시글이 등록되었습니다.');
            redirect('?page=recruits');
            break;

        // ── 팀원 모집 지원 ──
        case 'recruit_apply':
            requireLogin();
            $postId  = (int)($_POST['post_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');
            // 자기소개 없어도 지원 가능 (선택사항)
            try {
                $pdo->prepare("INSERT INTO recruit_applications (post_id,user_id,message) VALUES (?,?,?)")
                    ->execute([$postId,me()['id'],$message]);
                flash('지원 완료! 팀 캡틴의 확인을 기다리세요.');
            } catch(PDOException) { flash('이미 지원한 게시글입니다.','error'); }
            redirect('?page=recruits');
            break;

        case 'update_recruit_post':
            requireLogin();
            $postId = (int)($_POST['post_id'] ?? 0);
            $post = $pdo->prepare("SELECT team_id FROM recruit_posts WHERE id=?");
            $post->execute([$postId]); $post = $post->fetch();
            if (!$post) { flash('게시글을 찾을 수 없습니다.','error'); redirect('?page=recruits'); }
            if (!isAnyAdmin() && (!isCaptain() || (int)$post['team_id'] !== myTeamId())) {
                flash('수정 권한이 없습니다.','error'); redirect('?page=recruits');
            }
            $pdo->prepare("UPDATE recruit_posts SET positions_needed=?, recruit_count=?, intro=?, level_required=?, status=? WHERE id=?")
                ->execute([
                    $_POST['positions_needed'] ?? '무관',
                    (int)($_POST['recruit_count'] ?? 1),
                    trim($_POST['intro'] ?? ''),
                    $_POST['level_required'] ?? '무관',
                    in_array($_POST['status']??'',['open','closed']) ? $_POST['status'] : 'open',
                    $postId,
                ]);
            flash('모집글이 수정되었습니다.');
            redirect('?page=recruits');
            break;

        // ── 팀원 모집 지원 수락/거절 ──
        case 'recruit_respond':
            requireLogin(); if(!isCaptain()){flash('캡틴만 가능합니다.','error');redirect('?page=recruits');}
            $appId  = (int)($_POST['app_id'] ?? 0);
            $status = $_POST['status'] === 'accepted' ? 'accepted' : 'rejected';
            // 내 팀 게시글의 지원만 처리 가능 (보안)
            $app = $pdo->prepare("SELECT ra.*,rp.team_id FROM recruit_applications ra JOIN recruit_posts rp ON rp.id=ra.post_id WHERE ra.id=? AND rp.team_id=?");
            $app->execute([$appId, myTeamId()]); $app = $app->fetch();
            if (!$app) { flash('지원서를 찾을 수 없습니다.','error'); redirect('?page=recruits'); }
            $pdo->prepare("UPDATE recruit_applications SET status=?,responded_at=NOW() WHERE id=?")->execute([$status,$appId]);
            if ($status === 'accepted') {
                // 바로 active로 추가 (pending 없이 즉시 팀원 활성화)
                try {
                    $pdo->prepare("INSERT INTO team_members (team_id,user_id,role,status) VALUES (?,?,'player','active')")
                        ->execute([$app['team_id'],$app['user_id']]);
                    // 팀원 3명 이상 되면 PENDING→ACTIVE
                    $cnt = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE team_id=? AND status='active'");
                    $cnt->execute([$app['team_id']]);
                    if ((int)$cnt->fetchColumn() >= 3) {
                        $pdo->prepare("UPDATE teams SET status='ACTIVE' WHERE id=? AND status='PENDING'")->execute([$app['team_id']]);
                    }
                } catch(PDOException) {}
                flash('지원자를 수락했습니다! 팀원으로 추가되었습니다.');
            } else {
                flash('지원을 거절했습니다.');
            }
            redirect('?page=recruits');
            break;

        // ── 매너 평가 등록 ──
        case 'submit_review':
            requireLogin();
            $matchId     = (int)($_POST['match_id'] ?? 0);
            $targetType  = $_POST['target_type'] === 'mercenary' ? 'mercenary' : 'team';
            $targetId    = (int)($_POST['target_id'] ?? 0);
            $manner      = max(1,min(5,(int)($_POST['manner_score'] ?? 3)));
            $attend      = max(1,min(5,(int)($_POST['attendance_score'] ?? 3)));
            $skill       = max(1,min(5,(int)($_POST['skill_score'] ?? 3)));
            $inviteAgain = (int)(($_POST['invite_again'] ?? '0') === '1');
            $comment     = trim($_POST['comment'] ?? '');
            try {
                $pdo->prepare("
                    INSERT INTO reviews (match_id,reviewer_id,target_type,target_id,manner_score,attendance_score,skill_score,invite_again,comment)
                    VALUES (?,?,?,?,?,?,?,?,?)
                ")->execute([$matchId,me()['id'],$targetType,$targetId,$manner,$attend,$skill,$inviteAgain,$comment]);
                // 유저 manner_score 갱신 (대상이 용병일 때)
                if ($targetType === 'mercenary') {
                    $avg = $pdo->prepare("SELECT AVG(manner_score) FROM reviews WHERE target_type='mercenary' AND target_id=?");
                    $avg->execute([$targetId]);
                    $pdo->prepare("UPDATE users SET manner_score=? WHERE id=?")->execute([$avg->fetchColumn(),$targetId]);
                } else {
                    $avg = $pdo->prepare("SELECT AVG(manner_score) FROM reviews WHERE target_type='team' AND target_id=?");
                    $avg->execute([$targetId]);
                    $pdo->prepare("UPDATE teams SET manner_score=? WHERE id=?")->execute([$avg->fetchColumn(),$targetId]);
                }
                flash('평가가 등록되었습니다.');
            } catch(PDOException) { flash('평가 등록 중 오류가 발생했습니다.','error'); }
            redirect('?page=match&id='.$matchId);
            break;

        // ── [1단계] 캡틴이 상대팀 평가 (간단 매너굿/보통/거칠어요 + 시간 + 추천) ──
        // 양쪽 캡틴 모두 평가 완료 시에만 결과가 team_season_stats에 공식 반영됨
        case 'submit_team_eval':
            requireLogin();
            if (!isCaptain()) { flash('캡틴만 평가 가능합니다.', 'error'); redirect('?page=home'); }
            $matchId  = (int)($_POST['match_id'] ?? 0);
            $targetTeamId = (int)($_POST['target_team_id'] ?? 0);
            $manner   = in_array($_POST['manner'] ?? '', ['good','normal','rough']) ? $_POST['manner'] : 'normal';
            $time     = in_array($_POST['time'] ?? '', ['ontime','late']) ? $_POST['time'] : 'ontime';
            $overall  = in_array($_POST['overall'] ?? '', ['recommend','not']) ? $_POST['overall'] : 'recommend';
            // 점수 계산
            $deltaMap = ['good'=>0.5,'normal'=>0,'rough'=>-1.0,'ontime'=>0,'late'=>-0.5,'recommend'=>0.3,'not'=>-0.5];
            $totalDelta = $deltaMap[$manner] + $deltaMap[$time] + $deltaMap[$overall];
            // 상대팀 캡틴에게 점수 적용
            $oppCap = $pdo->prepare("SELECT user_id FROM team_members WHERE team_id=? AND role='captain' AND status='active' LIMIT 1");
            $oppCap->execute([$targetTeamId]); $oppCapId = (int)$oppCap->fetchColumn();
            if ($oppCapId) {
                applyMannerDelta($pdo, $oppCapId, $totalDelta, $matchId, "팀 평가: $manner/$time/$overall");
            }
            // reviews 테이블에도 기록 (호환성)
            $mannerInt = $manner==='good'?5:($manner==='normal'?3:1);
            $attendInt = $time==='ontime'?5:2;
            $skillInt  = $overall==='recommend'?4:2;
            try {
                $pdo->prepare("INSERT INTO reviews (match_id,reviewer_id,target_type,target_id,manner_score,attendance_score,skill_score,invite_again,comment) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$matchId, me()['id'], 'team', $targetTeamId, $mannerInt, $attendInt, $skillInt, $overall==='recommend'?1:0, '']);
            } catch(PDOException) {}
            // [평가 완료 → evaluation_step 1 + 양팀 완료 시 공식 반영] 양쪽 캡틴 모두 평가했는지 체크
            $m = $pdo->prepare("SELECT home_team_id, away_team_id, evaluation_step, status FROM matches WHERE id=?");
            $m->execute([$matchId]); $m = $m->fetch();
            if ($m) {
                $bothDone = $pdo->prepare("
                    SELECT
                        SUM(CASE WHEN target_id=? THEN 1 ELSE 0 END) AS a,
                        SUM(CASE WHEN target_id=? THEN 1 ELSE 0 END) AS b
                    FROM reviews WHERE match_id=? AND target_type='team'
                ");
                $bothDone->execute([(int)$m['home_team_id'], (int)$m['away_team_id'], $matchId]);
                $r = $bothDone->fetch();
                if ((int)$r['a'] > 0 && (int)$r['b'] > 0 && (int)$m['evaluation_step'] < 1) {
                    // 양팀 평가 완료 → 결과 공식 반영
                    $pdo->prepare("UPDATE matches SET evaluation_step=1, status='completed' WHERE id=?")->execute([$matchId]);
                    // [TF-17] 경기 완료 시 pending 요청 자동 만료
                    $pdo->prepare("UPDATE mercenary_requests SET status='expired' WHERE match_id=? AND status='pending'")->execute([$matchId]);
                    $pdo->prepare("UPDATE match_requests SET status='expired' WHERE match_id=? AND status='pending'")->execute([$matchId]);
                    $res = $pdo->prepare("SELECT score_home, score_away FROM match_results WHERE match_id=?");
                    $res->execute([$matchId]); $res = $res->fetch();
                    if ($res) {
                        $hs = (int)$res['score_home']; $as = (int)$res['score_away'];
                        $ht = (int)$m['home_team_id']; $at = (int)$m['away_team_id'];
                        if ($hs > $as) {
                            $pdo->prepare("UPDATE teams SET win=win+1, goals_for=goals_for+?, goals_against=goals_against+? WHERE id=?")->execute([$hs,$as,$ht]);
                            $pdo->prepare("UPDATE teams SET loss=loss+1, goals_for=goals_for+?, goals_against=goals_against+? WHERE id=?")->execute([$as,$hs,$at]);
                        } elseif ($hs < $as) {
                            $pdo->prepare("UPDATE teams SET loss=loss+1, goals_for=goals_for+?, goals_against=goals_against+? WHERE id=?")->execute([$hs,$as,$ht]);
                            $pdo->prepare("UPDATE teams SET win=win+1, goals_for=goals_for+?, goals_against=goals_against+? WHERE id=?")->execute([$as,$hs,$at]);
                        } else {
                            $pdo->prepare("UPDATE teams SET draw=draw+1, goals_for=goals_for+?, goals_against=goals_against+? WHERE id=?")->execute([$hs,$as,$ht]);
                            $pdo->prepare("UPDATE teams SET draw=draw+1, goals_for=goals_for+?, goals_against=goals_against+? WHERE id=?")->execute([$as,$hs,$at]);
                        }
                        updateSeasonStats($pdo, $ht, $hs, $as);
                        updateSeasonStats($pdo, $at, $as, $hs);
                    }
                    // 참석자 전원에게 MOM 투표 알림
                    $atts = $pdo->prepare("SELECT user_id FROM match_attendance WHERE match_id=? AND status='ATTEND'");
                    $atts->execute([$matchId]);
                    foreach ($atts->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                        notify($pdo, (int)$uid, 'MOM', 'MOM 투표가 열렸습니다!', '오늘 우리 팀 MOM(Man of the Match)을 투표해주세요.', '?page=mom_vote&match_id='.$matchId);
                    }
                }
            }
            flash('평가가 등록되었습니다. 양 팀 평가 완료 시 결과가 공식 반영됩니다.');
            redirect('?page=match&id='.$matchId);
            break;

        // ── [1-1] 노쇼/비매너 신고 ──
        case 'report_no_show':
            requireLogin();
            if (!isCaptain()) { flash('캡틴만 신고 가능합니다.', 'error'); redirect('?page=home'); }
            $matchId   = (int)($_POST['match_id'] ?? 0);
            $targetUid = (int)($_POST['target_user_id'] ?? 0);
            $reason    = trim($_POST['reason'] ?? '');
            // 이미 신고했는지 (UNIQUE 제약)
            try {
                $pdo->prepare("INSERT INTO no_show_reports (match_id, reported_user_id, reporter_id, reason) VALUES (?,?,?,?)")
                    ->execute([$matchId, $targetUid, me()['id'], $reason]);
                // 매너점수 -5 즉시 차감
                applyMannerDelta($pdo, $targetUid, -5.0, $matchId, '노쇼/비매너 신고');
                notify($pdo, $targetUid, 'NO_SHOW',
                    '노쇼/비매너 신고 접수',
                    '매너점수 -5점이 차감되었습니다. 30점 이하가 되면 7일간 활동이 제한됩니다.',
                    '?page=mypage');
                flash('노쇼/비매너 신고가 접수되어 매너점수 -5점 차감되었습니다.');
            } catch(PDOException) { flash('이미 신고된 선수입니다.', 'error'); }
            redirect('?page=match&id='.$matchId);
            break;

        // ── [1-4] MOM 투표 ──
        case 'vote_mom':
            requireLogin();
            $matchId  = (int)($_POST['match_id'] ?? 0);
            $votedUid = (int)($_POST['voted_user_id'] ?? 0);
            // 본인 매치 참석자인지 검증
            $att = $pdo->prepare("SELECT id FROM match_attendance WHERE match_id=? AND user_id=? AND status='ATTEND'");
            $att->execute([$matchId, me()['id']]);
            if (!$att->fetch() && !isAdmin()) { flash('참석한 경기에서만 투표 가능합니다.', 'error'); redirect('?page=match&id='.$matchId); }
            try {
                $pdo->prepare("INSERT INTO mom_votes (match_id, voter_id, voted_user_id) VALUES (?,?,?)")
                    ->execute([$matchId, me()['id'], $votedUid]);
                // 받은 표 수 갱신
                $pdo->prepare("UPDATE users SET mom_count = (SELECT COUNT(*) FROM mom_votes WHERE voted_user_id=?) WHERE id=?")
                    ->execute([$votedUid, $votedUid]);
                flash('MOM 투표 완료!');
            } catch(PDOException) { flash('이미 투표하셨습니다.', 'error'); }
            // 모든 참석자 투표 완료 시 evaluation_step 2로 전환
            $cnt = $pdo->prepare("SELECT
                (SELECT COUNT(*) FROM match_attendance WHERE match_id=? AND status='ATTEND') AS att,
                (SELECT COUNT(*) FROM mom_votes WHERE match_id=?) AS voted");
            $cnt->execute([$matchId, $matchId]); $c = $cnt->fetch();
            if ((int)$c['att'] > 0 && (int)$c['voted'] >= (int)$c['att']) {
                $pdo->prepare("UPDATE matches SET evaluation_step=GREATEST(evaluation_step, 2) WHERE id=?")->execute([$matchId]);
            }
            redirect('?page=mom_vote&match_id='.$matchId);
            break;

        // ── [7단계] 회비 독촉 (개별) ──
        case 'remind_fee':
            requireLogin();
            if (!isCaptain()) { flash('캡틴만 가능합니다.', 'error'); redirect('?page=fees'); }
            $uid = (int)($_POST['user_id'] ?? 0);
            $tid = myTeamId();
            $sum = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fees WHERE team_id=? AND user_id=? AND status='미납'");
            $sum->execute([$tid, $uid]); $owe = (int)$sum->fetchColumn();
            $tn = $pdo->prepare("SELECT name FROM teams WHERE id=?"); $tn->execute([$tid]); $tname = $tn->fetchColumn();
            notify($pdo, $uid, 'FEE',
                '회비 미납 안내',
                h($tname).' 미납 회비 '.number_format($owe).'원이 있습니다. 빠른 납부 부탁드려요.',
                '?page=fees');
            flash('독촉 알림을 보냈습니다.');
            redirect('?page=fees');
            break;

        // ── [7단계] 회비 독촉 (전체 일괄) ──
        case 'remind_fee_all':
            requireLogin();
            if (!isCaptain()) { flash('캡틴만 가능합니다.', 'error'); redirect('?page=fees'); }
            $tid = myTeamId();
            $tn = $pdo->prepare("SELECT name FROM teams WHERE id=?"); $tn->execute([$tid]); $tname = $tn->fetchColumn();
            $unpaid = $pdo->prepare("SELECT user_id, SUM(amount) AS owe FROM fees WHERE team_id=? AND status='미납' GROUP BY user_id");
            $unpaid->execute([$tid]);
            $sent = 0;
            foreach ($unpaid->fetchAll() as $u) {
                notify($pdo, (int)$u['user_id'], 'FEE',
                    '[일괄] 회비 미납 안내',
                    h($tname).' 미납 회비 '.number_format($u['owe']).'원이 있습니다.',
                    '?page=fees');
                $sent++;
            }
            flash("일괄 독촉 발송 완료 ($sent명)");
            redirect('?page=fees');
            break;

        // ── [4단계] 용병 SOS 생성 ──
        case 'create_sos':
            requireLogin();
            if (!isCaptain()) { flash('캡틴만 가능합니다.', 'error'); redirect('?page=home'); }
            $matchId = (int)($_POST['match_id'] ?? 0);
            $cnt     = max(1, min(5, (int)($_POST['needed_count'] ?? 1)));
            $pos     = in_array($_POST['position_needed'] ?? '', ['GK','DF','MF','FW']) ? $_POST['position_needed'] : null;
            $msg     = trim($_POST['message'] ?? '');
            if (!$matchId || !$msg) { flash('필수 입력', 'error'); redirect('?page=match&id='.$matchId); }
            $pdo->prepare("INSERT INTO sos_alerts (match_id, team_id, needed_count, position_needed, message, expires_at) VALUES (?,?,?,?,?, DATE_ADD(NOW(), INTERVAL 48 HOUR))")
                ->execute([$matchId, myTeamId(), $cnt, $pos, $msg]);
            // 같은 지역의 활동 용병들에게 알림
            $matchRow = $pdo->prepare("SELECT region, location, match_date, match_time FROM matches WHERE id=?");
            $matchRow->execute([$matchId]); $mr = $matchRow->fetch();
            $region = $mr['region'] ?? '';
            $cands = $pdo->prepare("
                SELECT DISTINCT u.id FROM users u
                LEFT JOIN team_members tm ON tm.user_id=u.id AND tm.status='active'
                WHERE u.region=? AND u.manner_score >= 35
                  AND (u.restricted_until IS NULL OR u.restricted_until < NOW())
                  AND u.id != ?
                ORDER BY u.manner_score DESC LIMIT 20
            ");
            $cands->execute([$region, me()['id']]);
            foreach ($cands->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                notify($pdo, (int)$uid, 'SOS',
                    '🚨 긴급 용병 호출!',
                    "[$region] {$mr['match_date']} ".substr($mr['match_time'],0,5)." · ".h($mr['location'])." · ".$cnt."명 부족",
                    '?page=match&id='.$matchId);
            }
            flash('SOS 발송 완료! '.$cnt.'명 부족 알림이 지역 용병들에게 전송되었습니다.');
            redirect('?page=match&id='.$matchId);
            break;

        // ── [2단계] 골든타임 루프 단계 종료 ──
        case 'finish_loop':
            requireLogin();
            $matchId = (int)($_POST['match_id'] ?? 0);
            // 권한 체크: 해당 매치의 홈/어웨이 팀 캡틴만 가능
            $flMatch = $pdo->prepare("SELECT home_team_id, away_team_id FROM matches WHERE id=?");
            $flMatch->execute([$matchId]); $flMatch = $flMatch->fetch();
            if (!$flMatch) { flash('매치를 찾을 수 없습니다.','error'); redirect('?page=matches'); }
            if (!isCaptain() || !in_array(myTeamId(), [(int)$flMatch['home_team_id'], (int)$flMatch['away_team_id']])) {
                flash('해당 매치 캡틴만 완료 처리할 수 있습니다.', 'error'); redirect('?page=match&id='.$matchId);
            }
            $pdo->prepare("UPDATE matches SET evaluation_step=3 WHERE id=?")->execute([$matchId]);
            flash('루프 완료! 다음 매치를 잡아보세요.');
            redirect('?page=matches');
            break;

        // ── 팀 생성 ──
        case 'create_team':
            requireLogin();
            $name     = trim($_POST['name'] ?? '');
            $region   = trim($_POST['region'] ?? '');
            $district = trim($_POST['district'] ?? '');
            $intro    = trim($_POST['intro'] ?? '');
            if (!$name || !$region) { flash('팀 이름과 지역은 필수입니다.','error'); redirect('?page=team_create'); }
            // 이미 팀 있으면 차단
            $ex = $pdo->prepare("SELECT id FROM team_members WHERE user_id=? AND status='active' LIMIT 1");
            $ex->execute([me()['id']]);
            if ($ex->fetchColumn()) { flash('이미 소속된 팀이 있습니다.','error'); redirect('?page=team'); }
            // 초대코드 생성
            do {
                $code = strtoupper(substr(md5(uniqid()), 0, 6));
                $dup  = $pdo->prepare("SELECT id FROM teams WHERE invite_code=?");
                $dup->execute([$code]);
            } while ($dup->fetch());
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO teams (name,region,district,intro,leader_id,invite_code,status) VALUES (?,?,?,?,?,?,'PENDING')")
                    ->execute([$name,$region,$district,$intro,me()['id'],$code]);
                $tid = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO team_members (team_id,user_id,role,status) VALUES (?,?,'captain','active')")
                    ->execute([$tid,me()['id']]);
                $pdo->prepare("INSERT INTO team_season_stats (team_id,region,district) VALUES (?,?,?)")
                    ->execute([$tid,$region,$district]);
                $pdo->commit();
                flash("팀 '{$name}'이 생성되었습니다! 초대코드: {$code}");
                redirect('?page=team');
            } catch(PDOException $e) { $pdo->rollBack(); flash('팀 생성 실패: '.$e->getMessage(),'error'); redirect('?page=team_create'); }
            break;

        // ── 팀 초대코드 가입 ──
        case 'join_team_code':
            requireLogin();
            $code = strtoupper(trim($_POST['invite_code'] ?? ''));
            if (!$code) { flash('초대코드를 입력하세요.','error'); redirect('?page=team_join'); }
            $team = $pdo->prepare("SELECT * FROM teams WHERE invite_code=?");
            $team->execute([$code]); $team = $team->fetch();
            if (!$team) { flash('유효하지 않은 초대코드입니다.','error'); redirect('?page=team_join'); }
            if ($team['status'] === 'BANNED') { flash('가입이 제한된 팀입니다.','error'); redirect('?page=team_join'); }
            $ex = $pdo->prepare("SELECT id FROM team_members WHERE user_id=? AND status='active' LIMIT 1");
            $ex->execute([me()['id']]);
            if ($ex->fetchColumn()) { flash('이미 소속된 팀이 있습니다.','error'); redirect('?page=team'); }
            try {
                // [팀 가입 승인] 즉시 active가 아닌 pending으로 INSERT (캡틴 수락 필요)
                $pdo->prepare("INSERT INTO team_members (team_id,user_id,role,status) VALUES (?,?,'player','pending')")
                    ->execute([$team['id'],me()['id']]);
                // 캡틴에게 알림
                $capQ = $pdo->prepare("SELECT user_id FROM team_members WHERE team_id=? AND role='captain' AND status='active' LIMIT 1");
                $capQ->execute([$team['id']]);
                if ($cid = (int)$capQ->fetchColumn()) {
                    notify($pdo, $cid, 'TEAM_JOIN', '🚪 팀 가입 신청', h(displayName(me())).'님이 팀 가입을 신청했습니다.', '?page=team');
                }
                flash("팀 '{$team['name']}'에 가입 신청을 보냈습니다. 캡틴 수락 후 팀원이 됩니다 ⏳");
                redirect('?page=team_join');
            } catch(PDOException) { flash('이미 신청했거나 가입한 팀입니다.','error'); redirect('?page=team_join'); }
            break;

        // ── 경기장 인증 제출 (이미지 업로드) ──
        case 'submit_venue':
            requireLogin();
            $matchId = (int)($_POST['match_id'] ?? 0);
            if (!$matchId) { flash('매치 정보가 없습니다.','error'); redirect('?page=venue_verify'); }
            $file = $_FILES['receipt_image'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                flash('이미지 파일을 선택해주세요.','error'); redirect('?page=venue_verify&match_id='.$matchId);
            }
            if ($file['size'] > 5 * 1024 * 1024) {
                flash('5MB 이하의 이미지만 업로드 가능합니다.','error'); redirect('?page=venue_verify&match_id='.$matchId);
            }
            $imgInfo = @getimagesize($file['tmp_name']);
            if (!$imgInfo) {
                flash('올바른 이미지 파일이 아닙니다.','error'); redirect('?page=venue_verify&match_id='.$matchId);
            }
            $allowedMimes = ['image/jpeg'=>'jpg','image/jpg'=>'jpg','image/png'=>'png'];
            $mime = $imgInfo['mime'] ?? '';
            if (!isset($allowedMimes[$mime])) {
                flash('JPG, PNG 형식만 업로드 가능합니다.','error'); redirect('?page=venue_verify&match_id='.$matchId);
            }
            $ext      = $allowedMimes[$mime];
            $filename = 'venue_' . me()['id'] . '_' . uniqid() . '.' . $ext;
            $savePath = '/var/www/html/uploads/venues/' . $filename;
            $webPath  = 'uploads/venues/' . $filename;
            if (!move_uploaded_file($file['tmp_name'], $savePath)) {
                flash('파일 저장 실패. 잠시 후 다시 시도해주세요.','error'); redirect('?page=venue_verify&match_id='.$matchId);
            }
            $pdo->prepare("INSERT INTO venue_verifications (match_id,submitted_by,receipt_image_url) VALUES (?,?,?)")
                ->execute([$matchId, me()['id'], $webPath]);
            flash('인증 요청이 접수되었습니다. 관리자 검토 후 승인됩니다.');
            redirect('?page=match&id='.$matchId);
            break;

        // ── 팀 탈퇴 ──
        case 'leave_team':
            requireLogin();
            $tid = myTeamId();
            if (!$tid) { flash('소속 팀이 없습니다.','error'); redirect('?page=home'); }
            // 실제 역할 DB에서 조회 (세션 is_captain 대신)
            $myRoleQ = $pdo->prepare("SELECT role FROM team_members WHERE team_id=? AND user_id=? AND status='active'");
            $myRoleQ->execute([$tid, me()['id']]); $myActualRole = $myRoleQ->fetchColumn() ?: 'player';
            $isLeader = in_array($myActualRole, ['president','director','captain'], true);
            // 정식 팀원만 카운트 (용병 제외)
            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE team_id=? AND status='active' AND role != 'mercenary'");
            $cntStmt->execute([$tid]); $memberCount = (int)$cntStmt->fetchColumn();
            if ($isLeader && $memberCount > 1) {
                flash('다른 팀원이 있습니다. 먼저 팀원을 강퇴하거나 직책을 위임해주세요.','error');
                redirect('?page=team');
            }
            $pdo->beginTransaction();
            try {
                // 본인 탈퇴
                $pdo->prepare("DELETE FROM team_members WHERE team_id=? AND user_id=?")->execute([$tid, me()['id']]);
                // 남은 정식 멤버 확인
                $remainQ = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE team_id=? AND status='active' AND role != 'mercenary'");
                $remainQ->execute([$tid]); $remain = (int)$remainQ->fetchColumn();
                if ($remain === 0) {
                    // 용병 기록도 정리 + 팀 삭제
                    $pdo->prepare("DELETE FROM team_members WHERE team_id=?")->execute([$tid]);
                    $pdo->prepare("DELETE FROM teams WHERE id=?")->execute([$tid]);
                    flash('팀이 해체되었습니다.');
                } else {
                    flash('팀에서 나갔습니다.');
                }
                $pdo->commit();
            } catch(PDOException $e) { $pdo->rollBack(); flash('오류가 발생했습니다: '.$e->getMessage(),'error'); }
            redirect('?page=home');
            break;

        // ── 팀원 강퇴 ──
        case 'kick_member':
            requireLogin();
            if (!isCaptain()) { flash('캡틴만 강퇴할 수 있습니다.','error'); redirect('?page=team'); }
            $targetId = (int)($_POST['target_user_id'] ?? 0);
            if (!$targetId || $targetId === me()['id']) { flash('잘못된 요청입니다.','error'); redirect('?page=team'); }
            $tid = myTeamId();
            $pdo->prepare("DELETE FROM team_members WHERE team_id=? AND user_id=? AND role != 'captain'")
                ->execute([$tid, $targetId]);
            flash('팀원을 강퇴했습니다.');
            redirect('?page=team');
            break;

        // ── 비밀번호 찾기: Step 1 — 본인 확인 + OTP 발송 ──
        case 'verify_reset':
            $name  = trim($_POST['name']  ?? '');
            $phone = formatPhone(trim($_POST['phone'] ?? ''));
            $stmt  = $pdo->prepare("SELECT id FROM users WHERE name=? AND phone=?");
            $stmt->execute([$name, $phone]); $found = $stmt->fetchColumn();
            if (!$found) {
                flash('등록된 정보와 일치하지 않습니다.','error');
                redirect('?page=forgot_password');
            }
            $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE users SET reset_token=?, reset_token_expires=DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id=?")
                ->execute([$otp, $found]);
            $_SESSION['reset_user_id'] = (int)$found;
            $_SESSION['reset_phone']   = $phone;
            // SMS 발송 (게이트웨이 연동 후 활성화)
            $smsSent = sendSmsOtp($phone, $otp);
            if ($smsSent) {
                flash('인증번호가 발송되었습니다. 5분 내 입력해주세요.');
            } else {
                flash('인증번호: ' . $otp . ' (SMS 게이트웨이 연동 전 임시 표시)', 'info');
            }
            redirect('?page=forgot_password&step=2');
            break;

        // ── 비밀번호 찾기: Step 2 — OTP 검증 ──
        case 'verify_otp':
            $resetId = (int)($_SESSION['reset_user_id'] ?? 0);
            if (!$resetId) { flash('세션이 만료되었습니다. 다시 시도해주세요.','error'); redirect('?page=forgot_password'); }
            $inputOtp = trim($_POST['otp'] ?? '');
            $check = $pdo->prepare("SELECT reset_token, reset_token_expires FROM users WHERE id=?");
            $check->execute([$resetId]); $row = $check->fetch();
            if (!$row || $row['reset_token'] !== $inputOtp) {
                flash('인증번호가 올바르지 않습니다.','error');
                redirect('?page=forgot_password&step=2');
            }
            if (strtotime($row['reset_token_expires']) < time()) {
                flash('인증번호가 만료되었습니다. 처음부터 다시 시도해주세요.','error');
                $pdo->prepare("UPDATE users SET reset_token=NULL, reset_token_expires=NULL WHERE id=?")->execute([$resetId]);
                unset($_SESSION['reset_user_id'], $_SESSION['reset_phone']);
                redirect('?page=forgot_password');
            }
            $_SESSION['otp_verified'] = true;
            $pdo->prepare("UPDATE users SET reset_token=NULL, reset_token_expires=NULL WHERE id=?")->execute([$resetId]);
            redirect('?page=forgot_password&step=3');
            break;

        // ── 비밀번호 찾기: Step 3 — 새 비밀번호 설정 ──
        case 'reset_password':
            $resetId = (int)($_SESSION['reset_user_id'] ?? 0);
            if (!$resetId || empty($_SESSION['otp_verified'])) { flash('세션이 만료되었습니다. 다시 시도해주세요.','error'); redirect('?page=forgot_password'); }
            $pw1 = $_POST['password']  ?? '';
            $pw2 = $_POST['password2'] ?? '';
            if (strlen($pw1) < 6) { flash('비밀번호는 6자 이상이어야 합니다.','error'); redirect('?page=forgot_password&step=3'); }
            if ($pw1 !== $pw2)    { flash('비밀번호가 일치하지 않습니다.','error'); redirect('?page=forgot_password&step=3'); }
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")
                ->execute([password_hash($pw1, PASSWORD_DEFAULT), $resetId]);
            unset($_SESSION['reset_user_id'], $_SESSION['reset_phone'], $_SESSION['otp_verified']);
            flash('비밀번호가 변경되었습니다. 다시 로그인해주세요.');
            redirect('?page=login');
            break;

        // ── 팀 포인트 배분 ──
        case 'distribute_team_points':
            requireLogin();
            if (!isCaptain()) { flash('관리자만 가능합니다.', 'error'); redirect('?page=team_points'); }
            $poolId = (int)($_POST['pool_id'] ?? 0);
            $toUserId = (int)($_POST['to_user_id'] ?? 0);
            $pts = (int)($_POST['points'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            if (!$poolId || !$toUserId || $pts <= 0) { flash('입력값을 확인하세요.', 'error'); redirect('?page=team_points'); }
            if (!$reason) $reason = '팀 포인트';
            // 풀 잔여 확인
            $pool = $pdo->prepare("SELECT * FROM team_point_pool WHERE id=? AND team_id=?");
            $pool->execute([$poolId, myTeamId()]); $pool = $pool->fetch();
            if (!$pool) { flash('포인트 풀을 찾을 수 없습니다.', 'error'); redirect('?page=team_points'); }
            if ($pts > $pool['remaining']) { flash('잔여 포인트가 부족합니다. (남은: '.$pool['remaining'].'P)', 'error'); redirect('?page=team_points'); }
            // 배분 기록
            $pdo->prepare("INSERT INTO team_point_distribute (pool_id, team_id, from_user_id, to_user_id, points, reason) VALUES (?,?,?,?,?,?)")
                ->execute([$poolId, myTeamId(), me()['id'], $toUserId, $pts, $reason]);
            // 풀 차감
            $pdo->prepare("UPDATE team_point_pool SET distributed=distributed+?, remaining=remaining-? WHERE id=?")
                ->execute([$pts, $pts, $poolId]);
            // 받는 사람 개인 포인트 적립
            addPoints($pdo, $toUserId, 'team_reward', $pts, $reason, $poolId);
            // 받는 사람에게 알림
            $fromName = me()['name'] ?? '관리자';
            notify($pdo, $toUserId, 'POINT', '🎁 포인트 지급', $fromName.'님이 '.$pts.'P를 지급했습니다: '.$reason, '?page=point_history');
            flash($pts.'P를 지급했습니다!');
            redirect('?page=team_points');
            break;

        // ── 버그 신고 ──
        case 'submit_bug_report':
            requireLogin();
            $bugTitle = trim($_POST['bug_title'] ?? '');
            $bugDesc = trim($_POST['bug_description'] ?? '');
            $bugCategory = in_array($_POST['bug_category'] ?? '', ['bug','ui','feature','other']) ? $_POST['bug_category'] : 'bug';
            $bugPage = trim($_POST['bug_page'] ?? '');
            if (!$bugTitle || mb_strlen($bugTitle) < 5) { flash('제목을 5자 이상 입력해주세요.', 'error'); redirect('?page=bug_report'); }
            $pdo->prepare("INSERT INTO bug_reports (user_id, category, title, description, page_url) VALUES (?,?,?,?,?)")
                ->execute([me()['id'], $bugCategory, $bugTitle, $bugDesc, $bugPage]);
            $reportId = (int)$pdo->lastInsertId();
            // 신고 접수 포인트 (50P 기본)
            addPoints($pdo, me()['id'], 'bug_report', PT_BUG_MEDIUM, '버그 신고: '.mb_substr($bugTitle,0,30,'UTF-8'), $reportId);
            flash('버그 신고가 접수되었습니다! 100P가 적립되었습니다.');
            redirect('?page=bug_report');
            break;

        // ── 비밀번호 변경 (마이페이지) ──
        case 'change_password':
            requireLogin();
            $curPw = $_POST['current_password'] ?? '';
            $newPw = $_POST['new_password'] ?? '';
            $newPw2 = $_POST['new_password2'] ?? '';
            $user = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
            $user->execute([me()['id']]); $user = $user->fetch();
            if (!$user || !password_verify($curPw, $user['password_hash'])) {
                flash('현재 비밀번호가 일치하지 않습니다.', 'error'); redirect('?page=mypage');
            }
            if (strlen($newPw) < 6) { flash('새 비밀번호는 6자 이상이어야 합니다.', 'error'); redirect('?page=mypage'); }
            if ($newPw !== $newPw2) { flash('새 비밀번호가 일치하지 않습니다.', 'error'); redirect('?page=mypage'); }
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")
                ->execute([password_hash($newPw, PASSWORD_DEFAULT), me()['id']]);
            flash('비밀번호가 변경되었습니다.');
            redirect('?page=mypage');
            break;

        // ── 프로필 편집 ──
        case 'update_profile':
            requireLogin();
            $region   = trim($_POST['region']   ?? '');
            $district = trim($_POST['district'] ?? '');
            $allPositions = ['GK','LB','CB','RB','CDM','CM','LM','RM','CAM','LW','ST','RW'];
            $posJson = json_decode($_POST['positions_json'] ?? '[]', true);
            if (!is_array($posJson)) $posJson = [];
            $posJson = array_values(array_filter($posJson, fn($p) => in_array($p, $allPositions)));
            $posJson = array_slice($posJson, 0, 3);
            $position = $posJson[0] ?? ($_POST['position'] ?? null);
            $subPositions = count($posJson) > 1 ? implode(',', array_slice($posJson, 1)) : null;
            $isPlayer = !empty($_POST['is_player_background']) ? 1 : 0;
            $foot = in_array($_POST['preferred_foot']??'',['LEFT','RIGHT','BOTH']) ? $_POST['preferred_foot'] : null;
            $hVal = (int)($_POST['height'] ?? 0);
            $height = ($hVal >= 140 && $hVal <= 220) ? $hVal : null;
            $wVal = (int)($_POST['weight'] ?? 0);
            $weight = ($wVal >= 30 && $wVal <= 200) ? $wVal : null;
            // 스탯 (1~99 범위 클램프)
            $clamp = fn($v) => min(99, max(1, (int)$v));
            $sPace  = $clamp($_POST['stat_pace']      ?? 50);
            $sShoot = $clamp($_POST['stat_shooting']  ?? 50);
            $sPass  = $clamp($_POST['stat_passing']   ?? 50);
            $sDrib  = $clamp($_POST['stat_dribbling'] ?? 50);
            $sDef   = $clamp($_POST['stat_defending'] ?? 50);
            $sPhys  = $clamp($_POST['stat_physical']  ?? 50);

            // ── 닉네임 처리 (실명은 수정 불가, 닉네임만 변경 가능) ──
            $meId = (int)me()['id'];
            $nickname = trim($_POST['nickname'] ?? '');
            if ($nickname === '') {
                flash('닉네임을 입력해주세요.', 'error');
                redirect('?page=mypage');
            }
            if (mb_strlen($nickname) < 2 || mb_strlen($nickname) > 20) {
                flash('닉네임은 2~20자 이내여야 합니다.', 'error');
                redirect('?page=mypage');
            }
            // 본인이 아닌 다른 사용자가 이미 같은 닉네임을 사용 중인지 확인
            $dup = $pdo->prepare("SELECT id FROM users WHERE nickname=? AND id<>? LIMIT 1");
            $dup->execute([$nickname, $meId]);
            if ($dup->fetch()) {
                flash('이미 사용 중인 닉네임입니다.', 'error');
                redirect('?page=mypage');
            }

            $jerseyNum = (int)($_POST['jersey_number'] ?? 0);
            $jerseyNum = ($jerseyNum >= 1 && $jerseyNum <= 99) ? $jerseyNum : null;
            $pdo->prepare("UPDATE users SET nickname=?, jersey_number=?, region=?, district=?, position=?, sub_positions=?, is_player_background=?,
                height=?, weight=?, preferred_foot=?,
                stat_pace=?, stat_shooting=?, stat_passing=?, stat_dribbling=?, stat_defending=?, stat_physical=?
                WHERE id=?")
                ->execute([$nickname, $jerseyNum, $region, $district, $position, $subPositions, $isPlayer,
                           $height, $weight, $foot,
                           $sPace, $sShoot, $sPass, $sDrib, $sDef, $sPhys, $meId]);
            $_SESSION['user']['nickname'] = $nickname;
            $_SESSION['user']['region']   = $region;
            $_SESSION['user']['district'] = $district;
            flash('프로필이 저장되었습니다.');
            redirect('?page=mypage');
            break;

        // ── 선호 포지션 저장 ──
        case 'save_position_prefs':
            requireLogin();
            $allPositions = ['GK','LB','CB','RB','CDM','CM','LM','RM','CAM','LW','ST','RW'];
            $prefsRaw = json_decode($_POST['position_prefs'] ?? '[]', true);
            if (!is_array($prefsRaw)) $prefsRaw = [];
            $prefsClean = array_values(array_filter($prefsRaw, fn($p) => in_array($p, $allPositions)));
            $prefsClean = array_slice($prefsClean, 0, 12);
            $pdo->prepare("UPDATE users SET position_prefs=? WHERE id=?")->execute([json_encode($prefsClean), me()['id']]);
            flash('선호 포지션이 저장되었습니다.');
            redirect('?page=mypage');
            break;

        // ── 캡틴 → 용병 선수에게 용병 제안 ──
        case 'offer_mercenary':
            requireLogin();
            if (!isCaptain()) { flash('캡틴만 제안할 수 있습니다.', 'error'); redirect('?page=mercenaries'); }
            if (!myTeamActivated($pdo)) { flash('팀원 3명이 모여 팀이 활성화된 후에 용병 제안이 가능합니다.', 'error'); redirect('?page=mercenaries'); }
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            $matchId      = (int)($_POST['match_id'] ?? 0);
            $message      = trim($_POST['message'] ?? '');
            if (!$targetUserId || !$matchId) {
                flash('경기와 대상 선수를 선택해주세요.', 'error'); redirect('?page=mercenaries');
            }
            // 내 팀 경기인지 확인
            $myMatch = $pdo->prepare("SELECT id FROM matches WHERE id=? AND (home_team_id=? OR away_team_id=?) AND status NOT IN ('completed','cancelled')");
            $myMatch->execute([$matchId, myTeamId(), myTeamId()]);
            if (!$myMatch->fetch()) { flash('유효하지 않은 경기입니다.', 'error'); redirect('?page=mercenaries'); }
            // 이미 제안/신청 있는지 확인
            $dup = $pdo->prepare("SELECT id FROM mercenary_requests WHERE match_id=? AND team_id=? AND user_id=? AND status NOT IN ('rejected','cancelled')");
            $dup->execute([$matchId, myTeamId(), $targetUserId]);
            if ($dup->fetch()) { flash('이미 제안하거나 신청된 선수입니다.', 'error'); redirect('?page=mercenaries'); }
            try {
                $pdo->prepare("INSERT INTO mercenary_requests (match_id, team_id, user_id, message, offer_type, initiated_by) VALUES (?,?,?,?,'offer',?)")
                    ->execute([$matchId, myTeamId(), $targetUserId, $message, me()['id']]);
                flash('용병 제안을 보냈습니다! 선수의 수락을 기다려주세요.');
            } catch (PDOException) { flash('제안 중 오류가 발생했습니다.', 'error'); }
            redirect('?page=mercenaries');
            break;

        // ── 선수: 받은 용병 제안 응답 ──
        case 'respond_mercenary_offer':
            requireLogin();
            $reqId  = (int)($_POST['req_id'] ?? 0);
            $answer = $_POST['answer'] === 'accept' ? 'accepted' : 'rejected';
            // 내가 받은 제안인지 확인
            $req = $pdo->prepare("SELECT * FROM mercenary_requests WHERE id=? AND user_id=? AND offer_type='offer' AND status='pending'");
            $req->execute([$reqId, me()['id']]); $req = $req->fetch();
            if (!$req) { flash('제안을 찾을 수 없습니다.', 'error'); redirect('?page=mercenaries'); }
            $pdo->prepare("UPDATE mercenary_requests SET status=?, responded_at=NOW() WHERE id=?")->execute([$answer, $reqId]);
            if ($answer === 'accepted') {
                try {
                    $pdo->prepare("INSERT INTO team_members (team_id,user_id,role,status) VALUES (?,?,'mercenary','active')")
                        ->execute([$req['team_id'], me()['id']]);
                } catch (PDOException) {}
                flash('제안을 수락했습니다! 해당 경기에 용병으로 참여하게 됩니다.');
            } else {
                flash('제안을 거절했습니다.');
            }
            redirect('?page=mercenaries');
            break;

        // ── 캡틴 → 용병 선수에게 팀 가입 제안 ──
        case 'offer_team_join':
            requireLogin();
            if (!isCaptain()) { flash('캡틴만 제안할 수 있습니다.', 'error'); redirect('?page=mercenaries'); }
            if (!myTeamActivated($pdo)) { flash('팀원 3명이 모여 팀이 활성화된 후에 가입 제안이 가능합니다.', 'error'); redirect('?page=mercenaries'); }
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            $message      = trim($_POST['message'] ?? '');
            if (!$targetUserId) { flash('대상 선수를 선택해주세요.', 'error'); redirect('?page=mercenaries'); }
            // 이미 팀 있는 유저 체크
            $hasTeam = $pdo->prepare("SELECT id FROM team_members WHERE user_id=? AND status='active' LIMIT 1");
            $hasTeam->execute([$targetUserId]);
            if ($hasTeam->fetch()) { flash('이미 팀이 있는 선수입니다.', 'error'); redirect('?page=mercenaries'); }
            try {
                $pdo->prepare("INSERT INTO team_join_offers (team_id, user_id, offered_by, message, expires_at) VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL 7 DAY))
                    ON DUPLICATE KEY UPDATE status='pending', message=VALUES(message), offered_by=VALUES(offered_by), responded_at=NULL, expires_at=DATE_ADD(NOW(), INTERVAL 7 DAY)")
                    ->execute([myTeamId(), $targetUserId, me()['id'], $message]);
                flash('팀 가입 제안을 보냈습니다!');
            } catch (PDOException $e) { flash('제안 중 오류가 발생했습니다.', 'error'); }
            redirect('?page=mercenaries');
            break;

        // ── 선수: 받은 팀 가입 제안 응답 ──
        case 'respond_team_join_offer':
            requireLogin();
            $offerId = (int)($_POST['offer_id'] ?? 0);
            $answer  = $_POST['answer'] === 'accept' ? 'accepted' : 'rejected';
            $offer = $pdo->prepare("SELECT * FROM team_join_offers WHERE id=? AND user_id=? AND status='pending' AND (expires_at IS NULL OR expires_at > NOW())");
            $offer->execute([$offerId, me()['id']]); $offer = $offer->fetch();
            if (!$offer) { flash('제안을 찾을 수 없거나 만료되었습니다.', 'error'); redirect('?page=mercenaries'); }
            $pdo->prepare("UPDATE team_join_offers SET status=?, responded_at=NOW() WHERE id=?")->execute([$answer, $offerId]);
            if ($answer === 'accepted') {
                // 이미 팀 있으면 차단
                $hasTeam = $pdo->prepare("SELECT id FROM team_members WHERE user_id=? AND status='active' LIMIT 1");
                $hasTeam->execute([me()['id']]);
                if ($hasTeam->fetch()) { flash('이미 소속 팀이 있습니다.', 'error'); redirect('?page=mercenaries'); }
                try {
                    $pdo->prepare("INSERT INTO team_members (team_id, user_id, role, status) VALUES (?,?,'player','active')")
                        ->execute([$offer['team_id'], me()['id']]);
                    // 팀 ACTIVE 여부 체크
                    $cnt = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE team_id=? AND status='active'");
                    $cnt->execute([$offer['team_id']]);
                    if ((int)$cnt->fetchColumn() >= 3) {
                        $pdo->prepare("UPDATE teams SET status='ACTIVE' WHERE id=? AND status='PENDING'")->execute([$offer['team_id']]);
                    }
                } catch (PDOException) {}
                flash('팀 가입 제안을 수락했습니다! 팀원이 되었습니다.');
            } else {
                flash('팀 가입 제안을 거절했습니다.');
            }
            redirect('?page=mercenaries');
            break;

        // ── 쿼터 선수 배정 (save_quarter) ──────────────────────
        case 'save_quarter':
            requireLogin();
            if (!isCaptain()) { flash('캡틴만 배정할 수 있습니다.', 'error'); redirect('?page=home'); }
            $matchId  = (int)($_POST['match_id'] ?? 0);
            $quarter  = (int)($_POST['quarter']  ?? 0);
            $players  = $_POST['players'] ?? []; // [{user_id, position}]
            if (!$matchId || $quarter < 1 || $quarter > 4) { flash('잘못된 요청','error'); redirect('?page=match&id='.$matchId); }
            // 내 팀이 이 경기에 참여하는지 확인
            $chk = $pdo->prepare("SELECT id FROM matches WHERE id=? AND (home_team_id=? OR away_team_id=?)");
            $chk->execute([$matchId, myTeamId(), myTeamId()]);
            if (!$chk->fetch()) { flash('권한 없습니다.','error'); redirect('?page=home'); }
            // 해당 쿼터 기존 배정 삭제 후 재삽입
            $tid = myTeamId();
            $pdo->prepare("DELETE FROM match_quarters WHERE match_id=? AND team_id=? AND quarter=?")
                ->execute([$matchId, $tid, $quarter]);
            $savedCount = 0;
            $savedList = [];
            foreach ($players as $uid => $pos) {
                $uid = (int)$uid; $pos = in_array($pos,['GK','LB','CB','RB','CDM','CM','LM','RM','CAM','LW','ST','RW']) ? $pos : 'CM';
                if (!$uid) continue;
                $pdo->prepare("INSERT IGNORE INTO match_quarters (match_id,team_id,quarter,user_id,position,assigned_by) VALUES (?,?,?,?,?,?)")
                    ->execute([$matchId, $tid, $quarter, $uid, $pos, me()['id']]);
                $savedCount++;
                $savedList[] = $pos;
            }
            flash($quarter.'쿼터 '.$savedCount.'명 저장 ('.implode('/',$savedList).')');
            redirect('?page=match&id='.$matchId.'#lineup');
            break;

        // ── 후보(벤치) 토글 (toggle_bench) ──────────────────────
        // ── 후보 직접 지정 (set_bench) ──────────────────────
        case 'set_bench':
            header('Content-Type: application/json');
            if (!me()) { echo json_encode(['ok'=>false,'msg'=>'로그인 필요']); exit; }
            if (!isCaptain()) { echo json_encode(['ok'=>false,'msg'=>'캡틴만 가능합니다']); exit; }
            $matchId = (int)($_POST['match_id'] ?? 0);
            $targetUid = (int)($_POST['user_id'] ?? 0);
            $setBench = (int)($_POST['is_bench'] ?? 0);
            if (!$matchId || !$targetUid) { echo json_encode(['ok'=>false,'msg'=>'잘못된 요청']); exit; }
            $cur = $pdo->prepare("SELECT id FROM match_attendance WHERE match_id=? AND user_id=?");
            $cur->execute([$matchId, $targetUid]);
            if ($cur->fetch()) {
                $pdo->prepare("UPDATE match_attendance SET is_bench=? WHERE match_id=? AND user_id=?")
                    ->execute([$setBench, $matchId, $targetUid]);
            } else {
                $pdo->prepare("INSERT INTO match_attendance (match_id, user_id, status, is_bench) VALUES (?, ?, 'PENDING', ?)")
                    ->execute([$matchId, $targetUid, $setBench]);
            }
            echo json_encode(['ok'=>true,'is_bench'=>$setBench,'msg'=>$setBench?'후보로 지정':'선발로 지정']);
            exit;

        case 'toggle_bench':
            header('Content-Type: application/json');
            if (!me()) { echo json_encode(['ok'=>false,'msg'=>'로그인 필요']); exit; }
            if (!isCaptain()) { echo json_encode(['ok'=>false,'msg'=>'캡틴만 가능합니다']); exit; }
            $matchId = (int)($_POST['match_id'] ?? 0);
            $targetUid = (int)($_POST['user_id'] ?? 0);
            if (!$matchId || !$targetUid) { echo json_encode(['ok'=>false,'msg'=>'잘못된 요청']); exit; }
            // 현재 is_bench 값 조회
            $cur = $pdo->prepare("SELECT is_bench, status FROM match_attendance WHERE match_id=? AND user_id=?");
            $cur->execute([$matchId, $targetUid]);
            $curRow = $cur->fetch();
            if (!$curRow) {
                // 출석 기록 없으면 PENDING + bench로 생성
                $pdo->prepare("INSERT INTO match_attendance (match_id, user_id, status, is_bench) VALUES (?, ?, 'PENDING', 1)")
                    ->execute([$matchId, $targetUid]);
                echo json_encode(['ok'=>true,'is_bench'=>1,'msg'=>'후보로 등록']);
                exit;
            }
            $newBench = $curRow['is_bench'] ? 0 : 1;
            $pdo->prepare("UPDATE match_attendance SET is_bench=? WHERE match_id=? AND user_id=?")
                ->execute([$newBench, $matchId, $targetUid]);
            echo json_encode(['ok'=>true,'is_bench'=>$newBench,'msg'=>$newBench?'후보로 변경':'선발로 변경']);
            exit;

                // [쿼터 동일 저장] 1쿼터 배정을 2~4쿼터에 복사
        case 'copy_quarter_all':
            requireLogin();
            if (!isCaptain()) { flash('캡틴만 가능합니다.', 'error'); redirect('?page=home'); }
            $matchId = (int)($_POST['match_id'] ?? 0);
            $srcQ    = (int)($_POST['source_quarter'] ?? 1);
            $tid     = myTeamId();
            // 원본 쿼터 조회
            $src = $pdo->prepare("SELECT user_id, position FROM match_quarters WHERE match_id=? AND team_id=? AND quarter=?");
            $src->execute([$matchId, $tid, $srcQ]);
            $srcPlayers = $src->fetchAll();
            if (!$srcPlayers) { flash('복사할 '.$srcQ.'쿼터 배정이 없습니다.','error'); redirect('?page=match&id='.$matchId.'#lineup'); }
            // 2~4쿼터에 복사 (기존 덮어쓰기)
            $copied = 0;
            for ($q = 1; $q <= 4; $q++) {
                if ($q === $srcQ) continue;
                $pdo->prepare("DELETE FROM match_quarters WHERE match_id=? AND team_id=? AND quarter=?")->execute([$matchId, $tid, $q]);
                foreach ($srcPlayers as $sp) {
                    $pdo->prepare("INSERT IGNORE INTO match_quarters (match_id,team_id,quarter,user_id,position,assigned_by) VALUES (?,?,?,?,?,?)")
                        ->execute([$matchId, $tid, $q, (int)$sp['user_id'], $sp['position'], me()['id']]);
                }
                $copied++;
            }
            flash($srcQ.'쿼터 배정을 나머지 '.$copied.'쿼터에 복사했습니다.');
            redirect('?page=match&id='.$matchId.'#lineup');
            break;

        // ── 캡틴: 팀원 대신 출석 신청 (대리 신청) ──
        // ── 캡틴: 일괄 대리 출석 ──
        case 'batch_proxy_attendance':
            header('Content-Type: application/json');
            if (!me()) { echo json_encode(['ok'=>false,'msg'=>'로그인 필요']); exit; }
            if (!isCaptain()) { echo json_encode(['ok'=>false,'msg'=>'캡틴만 가능']); exit; }
            $matchId = (int)($_POST['match_id'] ?? 0);
            $votes = json_decode($_POST['votes'] ?? '{}', true);
            if (!$matchId || !$votes) { echo json_encode(['ok'=>false,'msg'=>'잘못된 요청']); exit; }
            $tid = (int)myTeamId();
            $updated = 0;
            foreach ($votes as $uid => $vote) {
                $uid = (int)$uid;
                $vote = in_array($vote, ['ATTEND','ABSENT','PENDING']) ? $vote : 'PENDING';
                $exists = $pdo->prepare("SELECT id FROM match_attendance WHERE match_id=? AND user_id=?");
                $exists->execute([$matchId, $uid]);
                if ($exists->fetch()) {
                    $pdo->prepare("UPDATE match_attendance SET status=? WHERE match_id=? AND user_id=?")->execute([$vote, $matchId, $uid]);
                } else {
                    $pdo->prepare("INSERT INTO match_attendance (match_id, user_id, team_id, status) VALUES (?,?,?,?)")->execute([$matchId, $uid, $tid, $vote]);
                }
                $updated++;
            }
            echo json_encode(['ok'=>true,'msg'=>$updated.'명 출석 저장 완료','count'=>$updated]);
            exit;

        case 'proxy_attendance':
            requireLogin();
            if (!isCaptain()) { flash('권한이 없습니다.', 'error'); redirect('?page=home'); }
            $matchId   = (int)($_POST['match_id'] ?? 0);
            $targetUid = (int)($_POST['target_user_id'] ?? 0);
            $voteStatus = in_array($_POST['vote']??'', ['ATTEND','ABSENT','PENDING']) ? $_POST['vote'] : 'ATTEND';
            // 같은 팀원인지 확인
            $isMember = $pdo->prepare("SELECT id FROM team_members WHERE team_id=? AND user_id=? AND status='active'");
            $isMember->execute([myTeamId(), $targetUid]);
            if (!$isMember->fetch()) { flash('해당 팀원이 아닙니다.', 'error'); redirect('?page=match&id='.$matchId); }
            $pdo->prepare("
                INSERT INTO match_attendance (match_id, team_id, user_id, status) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE status=VALUES(status)
            ")->execute([$matchId, myTeamId(), $targetUid, $voteStatus]);
            // 참석 시 → 쿼터 자동 배정
            if ($voteStatus === 'ATTEND') {
                $tUser = $pdo->prepare("SELECT position FROM users WHERE id=?");
                $tUser->execute([$targetUid]); $tPos = $tUser->fetchColumn() ?: 'MF';
                $posMap = ['DF'=>'CB','MF'=>'CM','FW'=>'ST'];
                $autoPos = $posMap[$tPos] ?? ($tPos ?: 'CM');
                for ($aq = 1; $aq <= 4; $aq++) {
                    $exists = $pdo->prepare("SELECT id FROM match_quarters WHERE match_id=? AND team_id=? AND quarter=? AND user_id=?");
                    $exists->execute([$matchId, myTeamId(), $aq, $targetUid]);
                    if (!$exists->fetch()) {
                        $pdo->prepare("INSERT IGNORE INTO match_quarters (match_id,team_id,quarter,user_id,position,assigned_by) VALUES (?,?,?,?,?,?)")
                            ->execute([$matchId, myTeamId(), $aq, $targetUid, $autoPos, me()['id']]);
                    }
                }
            }
            if ($voteStatus === 'ABSENT') {
                $pdo->prepare("DELETE FROM match_quarters WHERE match_id=? AND team_id=? AND user_id=?")
                    ->execute([$matchId, myTeamId(), $targetUid]);
            }
            // 이력 기록
            $pdo->prepare("INSERT INTO attendance_override_logs (match_id, target_user_id, changed_by, old_status, new_status) VALUES (?,?,?,?,?)")
                ->execute([$matchId, $targetUid, me()['id'], 'PENDING', $voteStatus]);
            flash(h($voteStatus==='ATTEND'?'참석':'불참').' 처리 완료.');
            redirect('?page=match&id='.$matchId);
            break;

        // ── 친구 요청 보내기 ──
        case 'send_friend_request':
            requireLogin();
            $targetId = (int)($_POST['target_user_id'] ?? 0);
            if (!$targetId || $targetId === me()['id']) {
                flash('잘못된 요청입니다.', 'error'); redirect($_SERVER['HTTP_REFERER'] ?? '?page=home');
            }
            // [TF-22] 차단 관계 확인
            $blockChk = $pdo->prepare("SELECT id FROM user_blocks WHERE (blocker_id=? AND blocked_id=?) OR (blocker_id=? AND blocked_id=?) LIMIT 1");
            $blockChk->execute([me()['id'], $targetId, $targetId, me()['id']]);
            if ($blockChk->fetch()) {
                flash('차단 관계로 인해 친구 요청을 보낼 수 없습니다.', 'error');
                redirect($_SERVER['HTTP_REFERER'] ?? '?page=home');
            }
            $ex = $pdo->prepare("SELECT id,status FROM friendships WHERE (requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?)");
            $ex->execute([me()['id'], $targetId, $targetId, me()['id']]); $ex = $ex->fetch();
            if ($ex) {
                flash(match($ex['status']){'ACCEPTED'=>'이미 친구입니다.','PENDING'=>'이미 요청이 있습니다.',default=>'차단된 유저입니다.'}, 'error');
            } else {
                try {
                    $pdo->prepare("INSERT INTO friendships (requester_id, addressee_id) VALUES (?,?)")->execute([me()['id'], $targetId]);
                    flash('친구 요청을 보냈습니다!');
                } catch (PDOException) { flash('오류가 발생했습니다.', 'error'); }
            }
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=home');
            break;

        // ── 친구 요청 응답 (수락/거절) ──
        case 'respond_friend':
            requireLogin();
            $fid    = (int)($_POST['friendship_id'] ?? 0);
            $answer = $_POST['answer'] ?? '';
            if (!$fid) { flash('잘못된 요청', 'error'); redirect('?page=friends'); }
            $f = $pdo->prepare("SELECT * FROM friendships WHERE id=? AND addressee_id=? AND status='PENDING'");
            $f->execute([$fid, me()['id']]); $f = $f->fetch();
            if (!$f) { flash('요청을 찾을 수 없습니다.', 'error'); redirect('?page=friends'); }
            if ($answer === 'accept') {
                $pdo->beginTransaction();
                $existing = $pdo->prepare("SELECT c.id FROM conversations c JOIN conversation_participants cp1 ON cp1.conversation_id=c.id AND cp1.user_id=? JOIN conversation_participants cp2 ON cp2.conversation_id=c.id AND cp2.user_id=? WHERE c.type='DIRECT' LIMIT 1");
                $existing->execute([me()['id'], $f['requester_id']]);
                $convRow = $existing->fetch();
                if ($convRow) {
                    $convId = (int)$convRow['id'];
                } else {
                    $pdo->prepare("INSERT INTO conversations (type, created_by) VALUES ('DIRECT', ?)")->execute([me()['id']]);
                    $convId = (int)$pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO conversation_participants (conversation_id, user_id) VALUES (?,?),(?,?)")->execute([$convId, me()['id'], $convId, $f['requester_id']]);
                    $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, message, msg_type) VALUES (?,?,?,'SYSTEM')")->execute([$convId, me()['id'], '친구가 되었습니다! 대화를 시작해보세요.']);
                }
                $pdo->prepare("UPDATE friendships SET status='ACCEPTED', conv_id=? WHERE id=?")->execute([$convId, $fid]);
                $pdo->commit();
                flash('친구 요청을 수락했습니다!');
            } else {
                $pdo->prepare("DELETE FROM friendships WHERE id=?")->execute([$fid]);
                flash('친구 요청을 거절했습니다.');
            }
            redirect('?page=friends');
            break;

        // ── 채팅 대화방 시작 ──
        case 'start_chat':
            requireLogin();
            $targetId = (int)($_POST['target_user_id'] ?? 0);
            $matchId  = (int)($_POST['match_id'] ?? 0);
            if ($matchId) {
                $existing = $pdo->prepare("SELECT id FROM conversations WHERE type='MATCH' AND match_id=? LIMIT 1");
                $existing->execute([$matchId]); $row = $existing->fetch();
                if ($row) {
                    try { $pdo->prepare("INSERT IGNORE INTO conversation_participants (conversation_id, user_id) VALUES (?,?)")->execute([(int)$row['id'], me()['id']]); } catch (PDOException) {}
                    redirect('?page=chat&conv_id='.(int)$row['id']);
                }
                $match = $pdo->prepare("SELECT id FROM matches WHERE id=?"); $match->execute([$matchId]); $match = $match->fetch();
                if (!$match) { flash('매치를 찾을 수 없습니다.', 'error'); redirect('?page=matches'); }
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO conversations (type, match_id, created_by) VALUES ('MATCH', ?, ?)")->execute([$matchId, me()['id']]);
                $convId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO conversation_participants (conversation_id, user_id) VALUES (?,?)")->execute([$convId, me()['id']]);
                if ($targetId && $targetId !== me()['id']) {
                    try { $pdo->prepare("INSERT IGNORE INTO conversation_participants (conversation_id, user_id) VALUES (?,?)")->execute([$convId, $targetId]); } catch (PDOException) {}
                }
                $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, message, msg_type) VALUES (?,?,?,'SYSTEM')")->execute([$convId, me()['id'], '매치 대화방이 열렸습니다. 자유롭게 소통하세요!']);
                $pdo->commit();
                redirect('?page=chat&conv_id='.$convId);
            } elseif ($targetId) {
                $existing = $pdo->prepare("SELECT c.id FROM conversations c JOIN conversation_participants cp1 ON cp1.conversation_id=c.id AND cp1.user_id=? JOIN conversation_participants cp2 ON cp2.conversation_id=c.id AND cp2.user_id=? WHERE c.type='DIRECT' LIMIT 1");
                $existing->execute([me()['id'], $targetId]);
                if ($row = $existing->fetch()) { redirect('?page=chat&conv_id='.(int)$row['id']); }
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO conversations (type, created_by) VALUES ('DIRECT', ?)")->execute([me()['id']]);
                $convId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO conversation_participants (conversation_id, user_id) VALUES (?,?),(?,?)")->execute([$convId, me()['id'], $convId, $targetId]);
                $pdo->commit();
                redirect('?page=chat&conv_id='.$convId);
            }
            flash('잘못된 요청입니다.', 'error'); redirect('?page=messages');
            break;

        // ── 메시지 전송 ──
        case 'send_message':
            requireLogin();
            $convId  = (int)($_POST['conv_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');
            if (!$convId || !$message) { flash('메시지를 입력하세요.', 'error'); redirect('?page=chat&conv_id='.$convId); }
            $mem = $pdo->prepare("SELECT id FROM conversation_participants WHERE conversation_id=? AND user_id=?");
            $mem->execute([$convId, me()['id']]);
            if (!$mem->fetch()) { flash('권한이 없습니다.', 'error'); redirect('?page=messages'); }
            // [TF-22] DM 차단 확인
            $otherUser = $pdo->prepare("SELECT user_id FROM conversation_participants WHERE conversation_id=? AND user_id!=? LIMIT 1");
            $otherUser->execute([$convId, me()['id']]); $otherRow = $otherUser->fetch();
            if ($otherRow) {
                $dmBlock = $pdo->prepare("SELECT id FROM user_blocks WHERE (blocker_id=? AND blocked_id=?) OR (blocker_id=? AND blocked_id=?) LIMIT 1");
                $dmBlock->execute([me()['id'], $otherRow['user_id'], $otherRow['user_id'], me()['id']]);
                if ($dmBlock->fetch()) {
                    flash('차단 관계로 인해 메시지를 보낼 수 없습니다.', 'error');
                    redirect('?page=messages');
                }
            }
            $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, message, msg_type) VALUES (?,?,?,'TEXT')")->execute([$convId, me()['id'], $message]);
            $pdo->prepare("UPDATE conversation_participants SET last_read_at=NOW() WHERE conversation_id=? AND user_id=?")->execute([$convId, me()['id']]);
            redirect('?page=chat&conv_id='.$convId);
            break;

        // ── 대표자 출석 강제 변경 ──
        case 'override_attendance':
            requireLogin();
            if (!isCaptain()) { flash('권한이 없습니다.', 'error'); redirect('?page=home'); }
            $matchId   = (int)($_POST['match_id'] ?? 0);
            $targetUid = (int)($_POST['target_user_id'] ?? 0);
            $newStatus = in_array($_POST['new_status'] ?? '', ['ATTEND','ABSENT','PENDING']) ? $_POST['new_status'] : 'ATTEND';
            $cur = $pdo->prepare("SELECT status FROM match_attendance WHERE match_id=? AND user_id=?");
            $cur->execute([$matchId, $targetUid]); $old = $cur->fetchColumn() ?: 'PENDING';
            $pdo->prepare("INSERT INTO match_attendance (match_id, team_id, user_id, status) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)")
                ->execute([$matchId, myTeamId(), $targetUid, $newStatus]);
            $pdo->prepare("INSERT INTO attendance_override_logs (match_id, target_user_id, changed_by, old_status, new_status) VALUES (?,?,?,?,?)")
                ->execute([$matchId, $targetUid, me()['id'], $old, $newStatus]);
            flash('출석 상태가 변경되었습니다.');
            redirect('?page=match&id='.$matchId);
            break;

        // ── 팀 설정 저장 ──
        case 'save_team_settings':
            requireLogin();
            if (!isCaptain()) { flash('캡틴만 수정할 수 있습니다.', 'error'); redirect('?page=team'); }
            $emblemUrl = trim($_POST['emblem_url'] ?? '');
            if ($emblemUrl && !filter_var($emblemUrl, FILTER_VALIDATE_URL)) { $emblemUrl = ''; }
            $pdo->prepare("UPDATE teams SET intro=?, style=?, activity_day=?, avg_age_range=?, toss_link=?, kakao_pay_link=?, emblem_url=? WHERE id=?")
                ->execute([
                    trim($_POST['intro'] ?? ''),
                    in_array($_POST['style']??'',['친선','빡겜','초보환영','매너중시'])?$_POST['style']:'친선',
                    in_array($_POST['activity_day']??'',['평일','주말','상관없음'])?$_POST['activity_day']:'상관없음',
                    in_array($_POST['avg_age_range']??'',['20대','30대','40대','무관'])?$_POST['avg_age_range']:'무관',
                    trim($_POST['toss_link'] ?? ''),
                    trim($_POST['kakao_pay_link'] ?? ''),
                    $emblemUrl,
                    myTeamId(),
                ]);
            // Update membership fee if provided
            if (isset($_POST['membership_fee_amount'])) {
                $newFee = max(0, (int)$_POST['membership_fee_amount']);
                $pdo->prepare("UPDATE teams SET membership_fee=? WHERE id=".myTeamId())->execute([$newFee]);
                $pdo->prepare("INSERT INTO team_dues_settings (team_id, monthly_fee, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE monthly_fee=VALUES(monthly_fee), updated_by=VALUES(updated_by)")
                    ->execute([myTeamId(), $newFee, (int)me()['id']]);
            }
            flash('팀 프로필이 수정되었습니다.');
            redirect('?page=team_settings');
            break;

        case 'manual_add_member':
            requireLogin();
            if (!isCaptain()) { flash('권한이 없습니다.','error'); redirect('?page=team'); }
            $mName = trim($_POST['member_name'] ?? '');
            $mPhone = formatPhone(trim($_POST['member_phone'] ?? ''));
            $mPos = in_array($_POST['member_position']??'',['GK','DF','MF','FW']) ? $_POST['member_position'] : null;
            if (!$mName || mb_strlen($mName) < 2) { flash('이름을 입력하세요.','error'); redirect('?page=team'); }
            $tid = myTeamId();
            // 이미 가입된 유저인지 확인
            $existing = null;
            if ($mPhone) {
                $ex = $pdo->prepare("SELECT id FROM users WHERE phone=?");
                $ex->execute([$mPhone]); $existing = $ex->fetchColumn();
            }
            if ($existing) {
                // 이미 가입된 유저 → 팀에 추가
                $alreadyMember = $pdo->prepare("SELECT id FROM team_members WHERE team_id=? AND user_id=?");
                $alreadyMember->execute([$tid, $existing]);
                if ($alreadyMember->fetch()) { flash('이미 팀원입니다.','error'); redirect('?page=team'); }
                $pdo->prepare("INSERT INTO team_members (team_id,user_id,role,status,joined_at) VALUES (?,?,'player','active',NOW())")
                    ->execute([$tid, $existing]);
                flash(h($mName).'님을 팀에 추가했습니다.');
            } else {
                // 미가입 유저 → 계정 자동 생성 (임시 비밀번호)
                $tempPw = password_hash('123456', PASSWORD_DEFAULT);
                $phone = $mPhone ?: '미등록-'.time().'-'.random_int(100,999);
                try {
                    $pdo->prepare("INSERT INTO users (name,nickname,phone,password_hash,position) VALUES (?,?,?,?,?)")
                        ->execute([$mName, $mName, $phone, $tempPw, $mPos]);
                    $newId = (int)$pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO team_members (team_id,user_id,role,status,joined_at) VALUES (?,?,'player','active',NOW())")
                        ->execute([$tid, $newId]);
                    flash(h($mName).'님 계정이 생성되고 팀에 추가되었습니다. (임시 비밀번호: 123456)');
                } catch (PDOException) {
                    flash('추가 실패 — 이미 등록된 전화번호입니다.','error');
                }
            }
            redirect('?page=team');
            break;

        case 'add_guest_player':
            requireLogin();
            if (!isCaptain()) { flash('권한이 없습니다.','error'); redirect('?page=home'); }
            $matchId = (int)($_POST['match_id'] ?? 0);
            $guestName = trim($_POST['guest_name'] ?? '');
            $guestPos = $_POST['guest_position'] ?? '';
            if (!$guestName || mb_strlen($guestName) < 2) { flash('이름을 입력하세요.','error'); redirect('?page=match&id='.$matchId); }
            $tid = myTeamId();
            // 임시 계정 생성
            $tempPhone = 'guest-'.time().'-'.random_int(100,999);
            $tempPw = password_hash('123456', PASSWORD_DEFAULT);
            try {
                $pdo->prepare("INSERT INTO users (name,nickname,phone,password_hash,position,is_temp,temp_expires) VALUES (?,?,?,?,?,1,DATE_ADD(NOW(),INTERVAL 7 DAY))")
                    ->execute([$guestName, $guestName, $tempPhone, $tempPw, $guestPos ?: null]);
                $guestId = (int)$pdo->lastInsertId();
                // 팀에 추가
                $pdo->prepare("INSERT IGNORE INTO team_members (team_id,user_id,role,status,joined_at) VALUES (?,?,'player','active',NOW())")
                    ->execute([$tid, $guestId]);
                // 참석 등록
                $pdo->prepare("INSERT INTO match_attendance (match_id,team_id,user_id,status) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE status='ATTEND'")
                    ->execute([$matchId, $tid, $guestId, 'ATTEND']);
                // 쿼터 자동 배정
                $posMap = ['DF'=>'CB','MF'=>'CM','FW'=>'ST'];
                $autoPos = $posMap[$guestPos] ?? ($guestPos ?: 'CM');
                for ($aq = 1; $aq <= 4; $aq++) {
                    $pdo->prepare("INSERT IGNORE INTO match_quarters (match_id,team_id,quarter,user_id,position,assigned_by) VALUES (?,?,?,?,?,?)")
                        ->execute([$matchId, $tid, $aq, $guestId, $autoPos, me()['id']]);
                }
                flash(h($guestName).'님이 참석자로 추가되었습니다.');
            } catch (PDOException $e) {
                flash('추가 실패: '.$e->getMessage(),'error');
            }
            redirect('?page=match&id='.$matchId);
            break;

        case 'request_team_name_change':
            requireLogin();
            if (!isCaptain()) { flash('캡틴만 가능합니다.','error'); redirect('?page=team_settings'); }
            $newName = trim($_POST['new_team_name'] ?? '');
            if (mb_strlen($newName) < 2 || mb_strlen($newName) > 20) { flash('팀명은 2~20자여야 합니다.','error'); redirect('?page=team_settings'); }
            $tid = myTeamId();
            $team = $pdo->prepare("SELECT last_name_changed_at, name_change_requested FROM teams WHERE id=?");
            $team->execute([$tid]); $t = $team->fetch();
            if ($t['name_change_requested']) { flash('이미 변경 요청이 대기 중입니다.','error'); redirect('?page=team_settings'); }
            if ($t['last_name_changed_at'] && strtotime($t['last_name_changed_at']) > strtotime('-30 days')) {
                $nextDate = date('Y-m-d', strtotime($t['last_name_changed_at'] . ' +30 days'));
                flash("팀명은 월 1회만 변경 가능합니다. 다음 변경 가능일: {$nextDate}",'error');
                redirect('?page=team_settings');
            }
            $pdo->prepare("UPDATE teams SET name_change_requested=?, name_change_requested_at=NOW() WHERE id=?")
                ->execute([$newName, $tid]);
            flash("팀명 변경 요청이 접수되었습니다. 관리자 승인 후 반영됩니다. (요청: {$newName})");
            redirect('?page=team_settings');
            break;

        case 'admin_approve_team_name':
            requireLogin();
            if (!isAnyAdmin()) { flash('권한 없음','error'); redirect('?page=admin'); }
            $tid = (int)($_POST['team_id'] ?? 0);
            $approve = $_POST['approve'] ?? '';
            if ($approve === 'yes') {
                $t = $pdo->prepare("SELECT name_change_requested FROM teams WHERE id=?");
                $t->execute([$tid]); $newN = $t->fetchColumn();
                if ($newN) {
                    $pdo->prepare("UPDATE teams SET name=?, name_change_requested=NULL, name_change_requested_at=NULL, last_name_changed_at=NOW() WHERE id=?")
                        ->execute([$newN, $tid]);
                    flash("팀명이 '{$newN}'으로 변경 승인되었습니다.");
                }
            } else {
                $pdo->prepare("UPDATE teams SET name_change_requested=NULL, name_change_requested_at=NULL WHERE id=?")
                    ->execute([$tid]);
                flash('팀명 변경 요청을 거절했습니다.');
            }
            redirect('?page=admin&tab=teams');
            break;

        // ── 관리자: 팀 상태 변경 ──
        case 'admin_team_status':
            requireLogin();
            if (!isAdmin()) { flash('권한 없음', 'error'); redirect('?page=home'); }
            $tid    = (int)($_POST['team_id'] ?? 0);
            $status = in_array($_POST['status']??'',['PENDING','ACTIVE','BANNED'])?$_POST['status']:'ACTIVE';
            $pdo->prepare("UPDATE teams SET status=? WHERE id=?")->execute([$status, $tid]);
            flash("팀 상태가 {$status}로 변경되었습니다.");
            redirect('?page=admin_dashboard&tab=teams');
            break;

        case 'admin_user_role':
            requireLogin();
            if (!isAdmin()) { flash('권한 없음', 'error'); redirect('?page=home'); }
            $uid  = (int)($_POST['user_id'] ?? 0);
            $role = in_array($_POST['system_role']??'',['user','admin']) ? $_POST['system_role'] : 'user';
            $pdo->prepare("UPDATE users SET system_role=? WHERE id=?")->execute([$role, $uid]);
            flash('역할이 변경되었습니다.');
            redirect('?page=admin_dashboard&tab=users');
            break;

        case 'admin_user_team_remove':
            requireLogin();
            if (!isAdmin()) { flash('권한 없음', 'error'); redirect('?page=home'); }
            $uid = (int)($_POST['user_id'] ?? 0);
            $tid = (int)($_POST['team_id'] ?? 0);
            $pdo->prepare("UPDATE team_members SET status='left' WHERE user_id=? AND team_id=?")->execute([$uid, $tid]);
            flash('팀에서 제거했습니다.');
            redirect('?page=admin_dashboard&tab=users');
            break;

        case 'admin_match_status':
            requireLogin();
            if (!isAdmin()) { flash('권한 없음', 'error'); redirect('?page=home'); }
            $mid    = (int)($_POST['match_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            $allowed = ['open','confirmed','cancelled','in_progress','result_pending','completed'];
            if (!in_array($status, $allowed)) { flash('잘못된 상태', 'error'); redirect('?page=admin_dashboard&tab=matches'); }
            $pdo->prepare("UPDATE matches SET status=? WHERE id=?")->execute([$status, $mid]);
            flash('경기 상태가 변경되었습니다.');
            redirect('?page=admin_dashboard&tab=matches');
            break;

        // ── 매치 개설자 또는 어드민: 매치 삭제 ──
        case 'delete_match':
            requireLogin();
            $mid = (int)($_POST['match_id'] ?? 0);
            $m = $pdo->prepare("SELECT creator_id, home_team_id, status FROM matches WHERE id=?");
            $m->execute([$mid]); $m = $m->fetch();
            if (!$m) { flash('매치를 찾을 수 없습니다.', 'error'); redirect('?page=matches'); }
            $uid = (int)me()['id'];
            $isCreator = ((int)$m['creator_id'] === $uid);
            // 작성자는 경기 시작 전까지 삭제 가능 (open/request_pending/confirmed/checkin_open)
            $creatorCanDelete = in_array($m['status'], ['open','request_pending','confirmed','checkin_open']);
            $canDelete = isAdmin() || ($isCreator && $creatorCanDelete);
            if (!$canDelete) {
                flash($creatorCanDelete ? '삭제 권한이 없습니다.' : '진행 중인 매치는 관리자만 삭제할 수 있습니다.', 'error');
                redirect('?page=match&id='.$mid);
            }
            if (in_array($m['status'], ['in_progress','result_pending','completed'])) {
                flash('진행 중이거나 결과가 기록된 매치는 삭제할 수 없습니다.', 'error');
                redirect('?page=match&id='.$mid);
            }
            $pdo->prepare("UPDATE matches SET status='cancelled' WHERE id=?")->execute([$mid]);
            // [TF-17] 매치 취소 시 pending 자동 만료
            $pdo->prepare("UPDATE mercenary_requests SET status='expired' WHERE match_id=? AND status='pending'")->execute([$mid]);
            $pdo->prepare("UPDATE match_requests SET status='expired' WHERE match_id=? AND status='pending'")->execute([$mid]);
            flash('매치가 삭제(취소)되었습니다.');
            redirect(isAdmin() ? '?page=admin_dashboard&tab=matches' : '?page=matches');
            break;

        // ── 유저 비공개 메모 저장 (본인만 조회 가능) ──
        case 'save_user_note':
            requireLogin();
            $target = (int)($_POST['target_user_id'] ?? 0);
            $note   = trim((string)($_POST['note'] ?? ''));
            $meId   = (int)me()['id'];
            if (!$target || $target === $meId) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok'=>false,'err'=>'invalid_target']); exit;
            }
            // 대상 유저가 실재하는지만 확인
            $chk = $pdo->prepare("SELECT id FROM users WHERE id=?");
            $chk->execute([$target]);
            if (!$chk->fetch()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok'=>false,'err'=>'user_not_found']); exit;
            }
            if ($note === '') {
                $pdo->prepare("DELETE FROM user_notes WHERE author_id=? AND target_user_id=?")
                    ->execute([$meId, $target]);
            } else {
                if (mb_strlen($note) > 2000) $note = mb_substr($note, 0, 2000);
                $pdo->prepare("INSERT INTO user_notes (author_id, target_user_id, note) VALUES (?,?,?)
                    ON DUPLICATE KEY UPDATE note=VALUES(note)")
                    ->execute([$meId, $target, $note]);
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true,'saved'=>$note==='' ? null : $note], JSON_UNESCAPED_UNICODE);
            exit;

        // [개인 기록] 골/어시스트 저장
        case 'save_player_records':
            requireLogin();
            if (!isCaptain()) { flash('캡틴/매니저만 가능','error'); redirect('?page=home'); }
            $matchId = (int)($_POST['match_id'] ?? 0);
            $goals   = $_POST['goals']   ?? [];
            $assists = $_POST['assists'] ?? [];
            $yellows = $_POST['yellows'] ?? [];
            $reds    = $_POST['reds']    ?? [];
            $match   = $pdo->prepare("SELECT match_date, status, home_team_id, away_team_id FROM matches WHERE id=?");
            $match->execute([$matchId]); $mInfo = $match->fetch();
            if (!$mInfo) { flash('매치 없음','error'); redirect('?page=matches'); }
            if (in_array($mInfo['status'], ['open','confirmed','checkin_open','cancelled'])) { flash('아직 경기가 진행되지 않았습니다.','error'); redirect('?page=match&id='.$matchId); }
            $tid = myTeamId();
            $saved = 0;
            foreach ($goals as $uid => $g) {
                $uid = (int)$uid;
                $g   = max(0, min(20, (int)$g));
                $a   = max(0, min(20, (int)($assists[$uid] ?? 0)));
                $isMerc = 0;
                // 용병 여부 체크
                $mChk = $pdo->prepare("SELECT role FROM team_members WHERE team_id=? AND user_id=? AND status='active'");
                $mChk->execute([$tid, $uid]);
                $role = $mChk->fetchColumn();
                if ($role === 'mercenary') $isMerc = 1;
                // UPSERT
                $yc = max(0, min(5, (int)($yellows[$uid] ?? 0)));
                $rc = max(0, min(2, (int)($reds[$uid] ?? 0)));
                $pdo->prepare("INSERT INTO match_player_records (match_id, user_id, team_id, is_mercenary, goals, assists, yellow_cards, red_cards, is_checked_in, match_date)
                    VALUES (?,?,?,?,?,?,?,?,1,?)
                    ON DUPLICATE KEY UPDATE goals=VALUES(goals), assists=VALUES(assists), yellow_cards=VALUES(yellow_cards), red_cards=VALUES(red_cards)")
                    ->execute([$matchId, $uid, $tid, $isMerc, $g, $a, $yc, $rc, $mInfo['match_date']]);
                $saved++;
            }
            // 유저 누적 골/어시 업데이트
            foreach ($goals as $uid => $g) {
                $uid = (int)$uid;
                $totals = $pdo->prepare("SELECT COALESCE(SUM(goals),0) AS tg, COALESCE(SUM(assists),0) AS ta FROM match_player_records WHERE user_id=?");
                $totals->execute([$uid]); $tt = $totals->fetch();
                $pdo->prepare("UPDATE users SET goals=?, assists=? WHERE id=?")->execute([(int)$tt['tg'], (int)$tt['ta'], $uid]);
            }
            flash("{$saved}명 개인 기록이 저장되었습니다.");
            redirect('?page=match&id='.$matchId);
            break;

        // [상대팀 빠른 수정] VS 카드에서 바로
        case 'quick_set_away':
            requireLogin();
            $mid = (int)($_POST['match_id'] ?? 0);
            $m = $pdo->prepare("SELECT creator_id FROM matches WHERE id=?");
            $m->execute([$mid]); $mr = $m->fetch();
            if (!$mr || ((int)$mr['creator_id'] !== (int)me()['id'] && !isAdmin())) {
                flash('수정 권한이 없습니다.','error');
                redirect('?page=match&id='.$mid);
            }
            $name = trim($_POST['away_team_name'] ?? '');
            $pdo->prepare("UPDATE matches SET away_team_name=? WHERE id=?")
                ->execute([$name !== '' ? $name : null, $mid]);
            flash($name ? "상대팀을 '{$name}'으로 설정했습니다." : '상대팀을 미정으로 변경했습니다.');
            redirect('?page=match&id='.$mid);
            break;

        // [투표 요청] 미응답 팀원에게 일괄 알림
        case 'request_vote':
            requireLogin();
            if (!isCaptain() || !myTeamId()) { flash('캡틴만 가능','error'); redirect('?page=home'); }
            $matchId = (int)($_POST['match_id'] ?? 0);
            $tid = myTeamId();
            // 미응답 팀원 조회
            $pending = $pdo->prepare("
                SELECT tm.user_id FROM team_members tm
                WHERE tm.team_id=? AND tm.status='active' AND tm.role != 'mercenary'
                AND tm.user_id NOT IN (
                    SELECT user_id FROM match_attendance WHERE match_id=? AND team_id=? AND status != 'PENDING'
                )
            ");
            $pending->execute([$tid, $matchId, $tid]);
            $sent = 0;
            while ($row = $pending->fetch()) {
                notify($pdo, (int)$row['user_id'], 'MATCH', '📢 출석 투표 요청',
                    '캡틴이 출석 투표를 요청했습니다. 참석/불참을 알려주세요!',
                    '?page=match&id='.$matchId);
                $sent++;
            }
            flash("미응답 {$sent}명에게 투표 요청 알림을 보냈습니다.");
            redirect('?page=match&id='.$matchId);
            break;

        // [용병 전환] 인원 부족 시 용병 모집 활성화
        case 'enable_mercenary':
            requireLogin();
            if (!isCaptain()) { flash('캡틴만 가능','error'); redirect('?page=home'); }
            $matchId = (int)($_POST['match_id'] ?? 0);
            $pdo->prepare("UPDATE matches SET allow_mercenary=1 WHERE id=? AND (home_team_id=? OR away_team_id=?)")
                ->execute([$matchId, myTeamId(), myTeamId()]);
            flash('용병 모집이 활성화되었습니다. FA시장에서 용병이 지원할 수 있습니다.');
            redirect('?page=match&id='.$matchId);
            break;

        // [관리 권한 토글] 직책과 독립적으로 권한 부여/해제
        case 'toggle_manage_perm':
            requireLogin();
            if (!isCaptain() || !myTeamId()) { flash('캡틴만 가능','error'); redirect('?page=team'); }
            $targetId = (int)($_POST['target_user_id'] ?? 0);
            if ($targetId === (int)me()['id']) { flash('본인 권한은 변경 불가','error'); redirect('?page=team'); }
            // 현재 상태 반전
            $cur = $pdo->prepare("SELECT has_manage_perm FROM team_members WHERE team_id=? AND user_id=? AND status='active'");
            $cur->execute([myTeamId(), $targetId]);
            $curVal = (int)$cur->fetchColumn();
            $newVal = $curVal ? 0 : 1;
            $pdo->prepare("UPDATE team_members SET has_manage_perm=? WHERE team_id=? AND user_id=? AND status='active'")
                ->execute([$newVal, myTeamId(), $targetId]);
            flash($newVal ? '관리 권한을 부여했습니다.' : '관리 권한을 해제했습니다.');
            redirect('?page=team');
            break;

        // [직책 임명] 캡틴이 팀원 역할 변경
        case 'change_member_role':
            requireLogin();
            if (!isCaptain() || !myTeamId()) { flash('캡틴만 가능합니다.','error'); redirect('?page=team'); }
            $targetId = (int)($_POST['target_user_id'] ?? 0);
            $newRole  = $_POST['new_role'] ?? 'player';
            $allowed  = ['player','owner','president','director','captain','vice_captain','manager','coach','treasurer','analyst','doctor'];
            if (!in_array($newRole, $allowed, true)) { flash('잘못된 직책','error'); redirect('?page=team'); }
            // 본인 직책도 변경 가능 (구단주 등 자기 임명)
            $pdo->prepare("UPDATE team_members SET role=? WHERE team_id=? AND user_id=? AND status='active'")
                ->execute([$newRole, myTeamId(), $targetId]);
            $roleLabels = ['player'=>'선수','owner'=>'구단주','president'=>'회장','director'=>'감독','captain'=>'주장','vice_captain'=>'부주장','manager'=>'매니저','coach'=>'코치','treasurer'=>'총무','analyst'=>'전력분석','doctor'=>'팀닥터'];
            flash(($roleLabels[$newRole] ?? $newRole).'(으)로 직책을 변경했습니다.');
            redirect('?page=team');
            break;

        // [팀 가입 승인] 캡틴이 팀원 가입 신청 수락
        case 'approve_team_join':
            requireLogin();
            if (!isCaptain() || !myTeamId()) { flash('권한 없음','error'); redirect('?page=team'); }
            $applicantId = (int)($_POST['user_id'] ?? 0);
            $tid = myTeamId();
            // 대상이 내 팀에 pending 상태인지 확인
            $chk = $pdo->prepare("SELECT id FROM team_members WHERE team_id=? AND user_id=? AND status='pending' LIMIT 1");
            $chk->execute([$tid, $applicantId]);
            if (!$chk->fetchColumn()) { flash('대기 중 신청이 없습니다.','error'); redirect('?page=team'); }
            // 신청자가 다른 팀에 active로 소속되어 있으면 차단 (단일 소속 룰)
            $other = $pdo->prepare("SELECT team_id FROM team_members WHERE user_id=? AND status='active' AND role != 'mercenary' LIMIT 1");
            $other->execute([$applicantId]);
            if ($other->fetchColumn()) {
                flash('해당 유저는 이미 다른 팀에 소속되어 있어 수락할 수 없습니다.', 'error');
                redirect('?page=team');
            }
            $pdo->prepare("UPDATE team_members SET status='active', joined_at=NOW() WHERE team_id=? AND user_id=?")
                ->execute([$tid, $applicantId]);
            // 3명 이상 시 팀 자동 활성화
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE team_id=? AND status='active' AND role != 'mercenary'");
            $cnt->execute([$tid]);
            if ((int)$cnt->fetchColumn() >= 3) {
                $pdo->prepare("UPDATE teams SET status='ACTIVE' WHERE id=? AND status='PENDING'")->execute([$tid]);
            }
            // 신청자에게 알림
            notify($pdo, $applicantId, 'TEAM_JOIN', '🎉 팀 가입 승인', '팀 가입이 수락되었습니다!', '?page=team');
            flash('팀원 가입을 수락했습니다.');
            redirect('?page=team');
            break;

        case 'reject_team_join':
            requireLogin();
            if (!isCaptain() || !myTeamId()) { flash('권한 없음','error'); redirect('?page=team'); }
            $applicantId = (int)($_POST['user_id'] ?? 0);
            $tid = myTeamId();
            // pending 레코드 삭제 (재신청 가능하도록)
            $pdo->prepare("DELETE FROM team_members WHERE team_id=? AND user_id=? AND status='pending'")
                ->execute([$tid, $applicantId]);
            notify($pdo, $applicantId, 'TEAM_JOIN', '팀 가입 거절', '아쉽게도 가입 요청이 거절되었습니다.', '?page=team_join');
            flash('팀원 가입을 거절했습니다.');
            redirect('?page=team');
            break;

        // [B2 온보딩] 완료/건너뛰기 표시 — 재노출 방지
        case 'mark_onboarded':
            requireLogin();
            $pdo->prepare("UPDATE users SET onboarded_at=NOW() WHERE id=? AND onboarded_at IS NULL")
                ->execute([(int)me()['id']]);
            // 건너뛰기면 알림 없이 홈, 완료면 매치로 유도
            $skipped = !empty($_POST['skipped']);
            redirect($skipped ? '?page=home' : '?page=matches');
            break;

        // [B1] 프로필 사진 업로드/삭제
        case 'upload_profile_image':
            requireLogin();
            if (empty($_FILES['avatar']['tmp_name']) || !is_uploaded_file($_FILES['avatar']['tmp_name'])) {
                flash('파일을 선택해주세요.', 'error');
                redirect('?page=mypage');
            }
            $file = $_FILES['avatar'];
            if ($file['size'] > 3 * 1024 * 1024) {
                flash('이미지는 3MB 이하만 가능합니다.', 'error');
                redirect('?page=mypage');
            }
            // MIME 검증 (실제 이미지인지 확인)
            $info = @getimagesize($file['tmp_name']);
            if (!$info || !in_array($info['mime'], ['image/jpeg','image/png','image/webp'], true)) {
                flash('JPG/PNG/WEBP 이미지만 업로드 가능합니다.', 'error');
                redirect('?page=mypage');
            }
            $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$info['mime']];
            $uid = (int)me()['id'];
            $filename = "u{$uid}_".time().".{$ext}";
            $destDir  = '/var/www/html/uploads/avatars';
            $destPath = "$destDir/$filename";
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            if (!@move_uploaded_file($file['tmp_name'], $destPath)) {
                flash('업로드 실패 — 권한/용량을 확인해주세요.', 'error');
                redirect('?page=mypage');
            }
            @chmod($destPath, 0644);
            $publicUrl = "/uploads/avatars/$filename";
            // 기존 이미지가 있으면 서버에서 삭제 (저장소 낭비 방지)
            $old = $pdo->prepare("SELECT profile_image_url FROM users WHERE id=?");
            $old->execute([$uid]);
            $oldUrl = $old->fetchColumn();
            if ($oldUrl && strpos($oldUrl, '/uploads/avatars/') === 0) {
                $oldPath = '/var/www/html' . $oldUrl;
                if (file_exists($oldPath)) @unlink($oldPath);
            }
            $pdo->prepare("UPDATE users SET profile_image_url=? WHERE id=?")->execute([$publicUrl, $uid]);
            $_SESSION['user']['profile_image_url'] = $publicUrl;
            flash('프로필 사진이 업로드되었습니다.');
            redirect('?page=mypage');
            break;

        case 'delete_profile_image':
            requireLogin();
            $uid = (int)me()['id'];
            $old = $pdo->prepare("SELECT profile_image_url FROM users WHERE id=?");
            $old->execute([$uid]);
            $oldUrl = $old->fetchColumn();
            if ($oldUrl && strpos($oldUrl, '/uploads/avatars/') === 0) {
                $oldPath = '/var/www/html' . $oldUrl;
                if (file_exists($oldPath)) @unlink($oldPath);
            }
            $pdo->prepare("UPDATE users SET profile_image_url=NULL WHERE id=?")->execute([$uid]);
            $_SESSION['user']['profile_image_url'] = null;
            flash('프로필 사진을 삭제했습니다.');
            redirect('?page=mypage');
            break;

        // [B4] 피드백 제출 — 로그인 유저만
        case 'submit_feedback':
            requireLogin();
            $type    = in_array($_POST['type'] ?? 'OTHER', ['BUG','FEATURE','COMPLIMENT','OTHER'], true) ? $_POST['type'] : 'OTHER';
            $msg     = trim((string)($_POST['message'] ?? ''));
            $pageUrl = mb_substr(trim((string)($_POST['page_url'] ?? '')), 0, 500);
            $ua      = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
            if (mb_strlen($msg) < 5) {
                flash('피드백 내용을 5자 이상 적어주세요.', 'error');
                redirect($_SERVER['HTTP_REFERER'] ?? '?page=home');
            }
            if (mb_strlen($msg) > 1000) $msg = mb_substr($msg, 0, 1000);
            $pdo->prepare("INSERT INTO feedback (user_id, type, message, page_url, user_agent) VALUES (?,?,?,?,?)")
                ->execute([(int)me()['id'], $type, $msg, $pageUrl ?: null, $ua ?: null]);
            flash('감사합니다! 피드백이 접수되었습니다 🙏');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=home');
            break;

        // ═══════════════ 어드민 전용 액션들 ═══════════════
        // [어드민 MVP] 권한은 isAnyAdmin (ADMIN + SUPER_ADMIN 모두 가능).
        // 일부 강제 액션만 isSuperAdmin 제한 (매치 강제, 영구 정지 등).
        // 리다이렉트 URL은 내부 refer 기준으로 admin 또는 admin_master로 분기.

        case 'admin_approve_venue':
            requireLogin();
            if (!isAnyAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $vid = (int)($_POST['verify_id'] ?? 0);
            $v = $pdo->prepare("SELECT match_id FROM venue_verifications WHERE id=?");
            $v->execute([$vid]); $row = $v->fetch();
            if (!$row) { flash('인증 요청을 찾을 수 없습니다.','error'); redirect('?page=admin&tab=verify'); }
            $pdo->prepare("UPDATE venue_verifications SET status='VERIFIED' WHERE id=?")->execute([$vid]);
            $pdo->prepare("UPDATE matches SET venue_verified=1, venue_auth=1 WHERE id=?")->execute([(int)$row['match_id']]);
            logAdminAction($pdo, 'approve_venue', 'venue_verification', $vid, 'match_id='.$row['match_id']);
            flash('경기장 인증을 승인했습니다.');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=verify');
            break;

        case 'admin_hold_venue':
            requireLogin();
            if (!isAnyAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $vid = (int)($_POST['verify_id'] ?? 0);
            $pdo->prepare("UPDATE venue_verifications SET status='HOLD' WHERE id=?")->execute([$vid]);
            logAdminAction($pdo, 'hold_venue', 'venue_verification', $vid);
            flash('경기장 인증을 보류 처리했습니다.');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=verify');
            break;

        case 'admin_reject_venue':
            requireLogin();
            if (!isAnyAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $vid = (int)($_POST['verify_id'] ?? 0);
            $pdo->prepare("UPDATE venue_verifications SET status='REJECTED' WHERE id=?")->execute([$vid]);
            logAdminAction($pdo, 'reject_venue', 'venue_verification', $vid);
            flash('경기장 인증을 거절했습니다.');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=verify');
            break;

        case 'admin_force_delete_match':
            requireLogin();
            if (!isSuperAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $mid = (int)($_POST['match_id'] ?? 0);
            $pdo->prepare("UPDATE matches SET status='cancelled' WHERE id=?")->execute([$mid]);
            // [TF-17] 매치 강제취소 시 pending 자동 만료
            $pdo->prepare("UPDATE mercenary_requests SET status='expired' WHERE match_id=? AND status='pending'")->execute([$mid]);
            $pdo->prepare("UPDATE match_requests SET status='expired' WHERE match_id=? AND status='pending'")->execute([$mid]);
            logAdminAction($pdo, 'cancel_match', 'match', $mid);
            flash('매치를 강제 취소했습니다.');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=matches');
            break;

        // [관리자] 매치 완전 삭제 (DB에서 제거)
        case 'admin_purge_match':
            requireLogin();
            if (!isSuperAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $mid = (int)($_POST['match_id'] ?? 0);
            logAdminAction($pdo, 'purge_match', 'match', $mid, 'DB 완전 삭제');
            // FK CASCADE 대응 — 관련 데이터 먼저 삭제
            foreach (['match_player_records','match_attendance','match_quarters','match_requests',
                       'match_results','checkins','mercenary_requests','mom_votes','sos_alerts'] as $tbl) {
                $pdo->prepare("DELETE FROM {$tbl} WHERE match_id=?")->execute([$mid]);
            }
            $pdo->prepare("DELETE FROM matches WHERE id=?")->execute([$mid]);
            flash('매치 #'.$mid.'를 완전히 삭제했습니다.');
            redirect('?page=admin&tab=matches');
            break;

        case 'admin_force_close_match':
            requireLogin();
            if (!isSuperAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $mid = (int)($_POST['match_id'] ?? 0);
            $pdo->prepare("UPDATE matches SET status='force_closed' WHERE id=?")->execute([$mid]);
            logAdminAction($pdo, 'force_close_match', 'match', $mid);
            flash('매치를 강제 종료했습니다.');
            // TF-17: 용병 pending 자동 만료 (강제종료 시)
            $pdo->prepare("UPDATE mercenary_requests SET status='expired' WHERE match_id=? AND status='pending'")->execute([$mid]);
            $pdo->prepare("UPDATE match_requests SET status='expired' WHERE match_id=? AND status='pending'")->execute([$mid]);
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=matches');
            break;

        case 'admin_dispute_match':
            requireLogin();
            if (!isSuperAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $mid = (int)($_POST['match_id'] ?? 0);
            $pdo->prepare("UPDATE matches SET status='disputed' WHERE id=?")->execute([$mid]);
            logAdminAction($pdo, 'dispute_match', 'match', $mid);
            flash('매치를 분쟁 상태로 변경했습니다.');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=matches');
            break;

        case 'admin_force_result':
            requireLogin();
            if (!isSuperAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $mid = (int)($_POST['match_id'] ?? 0);
            $sh  = max(0, min(99, (int)($_POST['score_home'] ?? 0)));
            $sa  = max(0, min(99, (int)($_POST['score_away'] ?? 0)));
            // 기존 match_results 있으면 UPDATE, 없으면 INSERT
            $chk = $pdo->prepare("SELECT id FROM match_results WHERE match_id=?");
            $chk->execute([$mid]);
            if ($chk->fetchColumn()) {
                $pdo->prepare("UPDATE match_results SET score_home=?, score_away=?, is_approved=1, reporter_id=? WHERE match_id=?")
                    ->execute([$sh, $sa, (int)me()['id'], $mid]);
            } else {
                $pdo->prepare("INSERT INTO match_results (match_id, score_home, score_away, is_approved, reporter_id) VALUES (?,?,?,1,?)")
                    ->execute([$mid, $sh, $sa, (int)me()['id']]);
            }
            // 매치 상태를 completed로 (분쟁/result_pending 해소)
            $pdo->prepare("UPDATE matches SET status='completed' WHERE id=? AND status IN ('result_pending','disputed','in_progress','checkin_open','confirmed')")
                ->execute([$mid]);
            logAdminAction($pdo, 'force_result', 'match', $mid, "{$sh}:{$sa}");
            flash("결과 강제 입력 완료: {$sh} : {$sa}");
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=matches');
            break;

        // [관리자] 매치 팀 배정 변경 (홈/어웨이 팀 수동 지정)
        case 'admin_assign_team':
            requireLogin();
            if (!isSuperAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $mid = (int)($_POST['match_id'] ?? 0);
            $side = $_POST['side'] ?? 'home'; // home | away
            $teamId = (int)($_POST['team_id'] ?? 0);
            $col = $side === 'away' ? 'away_team_id' : 'home_team_id';
            $pdo->prepare("UPDATE matches SET {$col} = ? WHERE id=?")
                ->execute([$teamId ?: null, $mid]);
            logAdminAction($pdo, 'assign_team', 'match', $mid, "{$side}={$teamId}");
            flash("{$side} 팀을 변경했습니다.");
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=match&id='.$mid);
            break;

        case 'admin_activate_team':
            requireLogin();
            if (!isSuperAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $tid = (int)($_POST['team_id'] ?? 0);
            $pdo->prepare("UPDATE teams SET status='ACTIVE' WHERE id=?")->execute([$tid]);
            logAdminAction($pdo, 'activate_team', 'team', $tid);
            flash('팀을 활성화했습니다.');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=dashboard');
            break;

        case 'admin_ban_team':
            requireLogin();
            if (!isSuperAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $tid = (int)($_POST['team_id'] ?? 0);
            $pdo->prepare("UPDATE teams SET status='BANNED' WHERE id=?")->execute([$tid]);
            logAdminAction($pdo, 'ban_team', 'team', $tid);
            flash('팀을 비활성화(BANNED)했습니다.');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=dashboard');
            break;

        case 'admin_restrict_user':
            requireLogin();
            if (!isAnyAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $uid    = (int)($_POST['user_id'] ?? 0);
            $days   = max(1, min(36500, (int)($_POST['days'] ?? 7)));
            $reason = trim($_POST['reason'] ?? '매너 위반');
            $fromReport = (int)($_POST['from_report_id'] ?? 0);
            // [권한 분리] 30일 초과(영구 포함)는 SUPER_ADMIN만 가능
            if ($days > 30 && !isSuperAdmin()) {
                flash('30일 초과 제재는 SUPER_ADMIN만 가능합니다.', 'error');
                redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=users');
            }
            if ($uid === (int)me()['id']) { flash('본인 제재 불가','error'); redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=users'); }
            $pdo->prepare("UPDATE users SET restricted_until = DATE_ADD(NOW(), INTERVAL ? DAY), ban_reason = ? WHERE id=?")
                ->execute([$days, $reason, $uid]);
            $pdo->prepare("INSERT INTO user_penalties (user_id, penalty_type, reason, expires_at, created_by) VALUES (?, 'RESTRICT', ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?)")
                ->execute([$uid, $reason, $days, me()['id']]);
            logAdminAction($pdo, 'restrict_user', 'user', $uid, "days={$days} reason={$reason}".($fromReport?" from_report=#{$fromReport}":''));
            // 신고 창에서 제재했으면 해당 신고를 RESOLVED로 자동 전환
            if ($fromReport) {
                $upd = $pdo->prepare("UPDATE reports SET status='RESOLVED', admin_note=CONCAT(COALESCE(admin_note,''), '\n[자동] ', ? ,'일 제재'), resolved_at=NOW() WHERE id=?");
                $upd->execute([$days, $fromReport]);
            }
            flash("유저 {$days}일 제한 완료".($fromReport?' (신고 자동 처리)':''));
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=users');
            break;

        case 'admin_blacklist_user':
            requireLogin();
            if (!isSuperAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $uid    = (int)($_POST['user_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '블랙리스트');
            if ($uid === (int)me()['id']) { flash('본인은 블랙리스트 불가','error'); redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=users'); }
            $pdo->prepare("INSERT INTO user_penalties (user_id, penalty_type, reason, created_by) VALUES (?, 'BLACKLIST', ?, ?)")
                ->execute([$uid, $reason, me()['id']]);
            logAdminAction($pdo, 'blacklist_user', 'user', $uid, $reason);
            flash('유저를 블랙리스트에 등록했습니다.');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=users');
            break;

        // [TF-25] 제재 이의제기 제출
        case 'submit_appeal':
            $penInfo = $_SESSION['penalty_info'] ?? null;
            if (!$penInfo) { flash('세션이 만료되었습니다. 다시 로그인 시도해주세요.', 'error'); redirect('?page=login'); }
            verifyCsrf();
            $appealReason = trim($_POST['appeal_reason'] ?? '');
            if (mb_strlen($appealReason) < 10) { flash('이의제기 사유를 10자 이상 작성해주세요.', 'error'); redirect('?page=appeal'); }
            $pdo->exec("CREATE TABLE IF NOT EXISTS penalty_appeals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                penalty_type VARCHAR(20) NOT NULL,
                appeal_reason TEXT NOT NULL,
                status ENUM('pending','approved','rejected') DEFAULT 'pending',
                admin_response TEXT,
                reviewed_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TIMESTAMP NULL
            )");
            $existing = $pdo->prepare("SELECT id FROM penalty_appeals WHERE user_id=? AND status='pending'");
            $existing->execute([$penInfo['user_id']]);
            if ($existing->fetch()) { flash('이미 접수된 이의제기가 있습니다. 관리자 검토를 기다려주세요.', 'warning'); redirect('?page=appeal'); }
            $pdo->prepare("INSERT INTO penalty_appeals (user_id, penalty_type, appeal_reason) VALUES (?,?,?)")
                ->execute([$penInfo['user_id'], $penInfo['penalty_type'], $appealReason]);
            flash('이의제기가 접수되었습니다. 관리자 검토 후 결과를 알려드리겠습니다.', 'success');
            unset($_SESSION['penalty_info']);
            redirect('?page=login');

        // [TF-25] 관리자: 이의제기 처리
        case 'admin_appeal_action':
            if (!isAnyAdmin()) { flash('권한이 없습니다.', 'error'); redirect('?page=home'); }
            verifyCsrf();
            $appealId = (int)($_POST['appeal_id'] ?? 0);
            $decision = $_POST['decision'] ?? '';
            $adminResp = trim($_POST['admin_response'] ?? '');
            if (!in_array($decision, ['approved','rejected'])) { flash('잘못된 요청입니다.', 'error'); redirect('?page=admin&tab=appeals'); }
            $appeal = $pdo->prepare("SELECT * FROM penalty_appeals WHERE id=? AND status='pending'");
            $appeal->execute([$appealId]); $appeal = $appeal->fetch();
            if (!$appeal) { flash('이의제기를 찾을 수 없습니다.', 'error'); redirect('?page=admin&tab=appeals'); }
            $pdo->prepare("UPDATE penalty_appeals SET status=?, admin_response=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                ->execute([$decision, $adminResp, me()['id'], $appealId]);
            if ($decision === 'approved') {
                $pdo->prepare("UPDATE users SET restricted_until=NULL, ban_reason=NULL WHERE id=?")->execute([$appeal['user_id']]);
                $pdo->prepare("DELETE FROM user_penalties WHERE user_id=? AND (expires_at IS NULL OR expires_at > NOW())")->execute([$appeal['user_id']]);
                notify($pdo, $appeal['user_id'], 'SYSTEM', '이의제기 승인', '이의제기가 승인되어 제재가 해제되었습니다.', '?page=home');
                logAdminAction($pdo, 'appeal_approved', 'user', $appeal['user_id'], "appeal_id={$appealId}");
            } else {
                notify($pdo, $appeal['user_id'], 'SYSTEM', '이의제기 반려', '이의제기가 반려되었습니다: '.$adminResp, '?page=login');
                logAdminAction($pdo, 'appeal_rejected', 'user', $appeal['user_id'], "appeal_id={$appealId}");
            }
            flash($decision === 'approved' ? '이의제기를 승인하고 제재를 해제했습니다.' : '이의제기를 반려했습니다.', 'success');
            redirect('?page=admin&tab=appeals');

        case 'admin_unrestrict_user':
            requireLogin();
            if (!isAnyAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $uid = (int)($_POST['user_id'] ?? 0);
            $pdo->prepare("UPDATE users SET restricted_until=NULL, ban_reason=NULL WHERE id=?")->execute([$uid]);
            logAdminAction($pdo, 'unrestrict_user', 'user', $uid);
            flash('유저 제한을 해제했습니다.');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=users');
            break;

        case 'admin_adjust_manner':
            requireLogin();
            if (!isAnyAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $uid   = (int)($_POST['user_id'] ?? 0);
            $delta = (float)($_POST['delta'] ?? 0);
            if ($delta === 0.0) { flash('변경값 없음','error'); redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=users'); }
            // [권한 분리] ADMIN은 ±5 이내만 가능, SUPER_ADMIN은 ±50까지
            if (!isSuperAdmin() && abs($delta) > 5) {
                flash('ADMIN은 ±5점 이내만 조정 가능합니다. 큰 조정은 SUPER_ADMIN에게 요청하세요.', 'error');
                redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=users');
            }
            $delta = max(-50.0, min(50.0, $delta));
            $pdo->prepare("UPDATE users SET manner_score = GREATEST(0, LEAST(100, manner_score + ?)) WHERE id=?")
                ->execute([$delta, $uid]);
            $pdo->prepare("INSERT INTO manner_score_logs (user_id, score_change, reason) VALUES (?, ?, '관리자 조정')")
                ->execute([$uid, $delta]);
            logAdminAction($pdo, 'adjust_manner', 'user', $uid, "delta={$delta}");
            flash("매너점수 ".($delta>=0?'+':'')."{$delta}점 조정 완료");
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=users');
            break;

        case 'admin_promote_super':
            requireLogin();
            if (!isSuperAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid === (int)me()['id']) { flash('본인 승급 불가','error'); redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=users'); }
            $pdo->prepare("UPDATE users SET global_role='SUPER_ADMIN' WHERE id=?")->execute([$uid]);
            logAdminAction($pdo, 'promote_super', 'user', $uid);
            flash('SUPER_ADMIN으로 승급했습니다.');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=users');
            break;

        case 'admin_mark_reviewing':
            requireLogin();
            if (!isAnyAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $rid  = (int)($_POST['report_id'] ?? 0);
            $note = trim((string)($_POST['admin_note'] ?? ''));
            $pdo->prepare("UPDATE reports SET status='REVIEWING', admin_note=? WHERE id=?")
                ->execute([$note !== '' ? $note : null, $rid]);
            logAdminAction($pdo, 'mark_reviewing', 'report', $rid, $note);
            flash('신고를 검토중으로 변경했습니다.');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=reports');
            break;

        case 'admin_dismiss_report':
            requireLogin();
            if (!isAnyAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $rid  = (int)($_POST['report_id'] ?? 0);
            $note = trim((string)($_POST['admin_note'] ?? ''));
            $pdo->prepare("UPDATE reports SET status='DISMISSED', admin_note=?, resolved_at=NOW() WHERE id=?")
                ->execute([$note !== '' ? $note : null, $rid]);
            logAdminAction($pdo, 'dismiss_report', 'report', $rid, $note);
            flash('신고를 기각 처리했습니다.');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=reports');
            break;

        case 'admin_resolve_report':
            requireLogin();
            if (!isAnyAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $rid  = (int)($_POST['report_id'] ?? 0);
            $note = trim((string)($_POST['admin_note'] ?? ''));
            $pdo->prepare("UPDATE reports SET status='RESOLVED', admin_note=?, resolved_at=NOW() WHERE id=?")
                ->execute([$note !== '' ? $note : null, $rid]);
            logAdminAction($pdo, 'resolve_report', 'report', $rid, $note);
            flash('신고를 처리 완료로 변경했습니다.');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=reports');
            break;

        // [B4] 피드백 상태 변경 (어드민)
        case 'admin_feedback_reviewing':
            requireLogin();
            if (!isAnyAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $fid  = (int)($_POST['feedback_id'] ?? 0);
            $note = trim((string)($_POST['admin_note'] ?? ''));
            $pdo->prepare("UPDATE feedback SET status='REVIEWING', admin_note=? WHERE id=?")
                ->execute([$note !== '' ? $note : null, $fid]);
            logAdminAction($pdo, 'feedback_reviewing', 'feedback', $fid, $note);
            flash('피드백을 검토중으로 변경했습니다.');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=feedback');
            break;

        case 'admin_feedback_resolved':
            requireLogin();
            if (!isAnyAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $fid  = (int)($_POST['feedback_id'] ?? 0);
            $note = trim((string)($_POST['admin_note'] ?? ''));
            $pdo->prepare("UPDATE feedback SET status='RESOLVED', admin_note=?, resolved_at=NOW() WHERE id=?")
                ->execute([$note !== '' ? $note : null, $fid]);
            logAdminAction($pdo, 'feedback_resolved', 'feedback', $fid, $note);
            flash('피드백을 해결 처리했습니다.');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=feedback');
            break;

        case 'admin_feedback_archive':
            requireLogin();
            if (!isAnyAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $fid  = (int)($_POST['feedback_id'] ?? 0);
            $note = trim((string)($_POST['admin_note'] ?? ''));
            $pdo->prepare("UPDATE feedback SET status='ARCHIVED', admin_note=? WHERE id=?")
                ->execute([$note !== '' ? $note : null, $fid]);
            logAdminAction($pdo, 'feedback_archive', 'feedback', $fid, $note);
            flash('피드백을 보관 처리했습니다.');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=feedback');
            break;

        // ── [TF-BUG] 버그 리포트 상태 변경 (어드민) ──
        case 'update_bug_status':
            requireLogin();
            if (!isAnyAdmin()) { flash('권한 없음','error'); redirect('?page=home'); }
            $bugId     = (int)($_POST['bug_id'] ?? 0);
            $bugStatus = $_POST['bug_status'] ?? '';
            $bugNote   = trim((string)($_POST['admin_note'] ?? ''));
            $allowedBugStatuses = ['pending','reviewing','fixed','wontfix','duplicate'];
            if (!in_array($bugStatus, $allowedBugStatuses, true)) {
                flash('잘못된 상태입니다.', 'error');
                redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=bugs');
            }
            $bugUpdateFields = "status=?, admin_note=?";
            $bugParams = [$bugStatus, $bugNote !== '' ? $bugNote : null];
            if (in_array($bugStatus, ['fixed','wontfix','duplicate'])) {
                $bugUpdateFields .= ", resolved_at=NOW()";
            }
            $pdo->prepare("UPDATE bug_reports SET $bugUpdateFields WHERE id=?")
                ->execute([...$bugParams, $bugId]);
            // 해결(fixed) 시 심각도에 따라 추가 포인트 지급
            if ($bugStatus === 'fixed') {
                $bugRow = $pdo->prepare("SELECT user_id, severity, points_awarded FROM bug_reports WHERE id=?");
                $bugRow->execute([$bugId]); $bugRow = $bugRow->fetch();
                if ($bugRow) {
                    $bonusMap = ['low'=>0,'medium'=>0,'high'=>50,'critical'=>100];
                    $bonus = $bonusMap[$bugRow['severity']] ?? 0;
                    if ($bonus > 0 && (int)$bugRow['points_awarded'] <= PT_BUG_MEDIUM) {
                        addPoints($pdo, (int)$bugRow['user_id'], 'bug_report', $bonus, '버그 해결 보너스 (심각도: '.$bugRow['severity'].')', $bugId);
                        $pdo->prepare("UPDATE bug_reports SET points_awarded = points_awarded + ? WHERE id=?")
                            ->execute([$bonus, $bugId]);
                    }
                }
            }
            $statusLabelsMap = ['pending'=>'접수','reviewing'=>'검토중','fixed'=>'해결','wontfix'=>'보류','duplicate'=>'중복'];
            logAdminAction($pdo, 'update_bug_status', 'bug_report', $bugId, ($statusLabelsMap[$bugStatus] ?? $bugStatus).($bugNote ? ": $bugNote" : ''));
            flash('버그 리포트를 ['.($statusLabelsMap[$bugStatus] ?? $bugStatus).'] 상태로 변경했습니다.');
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=bugs');
            break;

        // ── 회비 설정 저장 ──
        case 'save_dues_setting':
            requireLogin();
            if (!isCaptain()) { flash('캡틴만 가능합니다.', 'error'); redirect('?page=fees'); }
            $tid = myTeamId();
            $monthlyFee = max(0, (int)($_POST['monthly_fee'] ?? 30000));
            $desc = trim($_POST['dues_description'] ?? '');
            $pdo->prepare("INSERT INTO team_dues_settings (team_id, monthly_fee, description, updated_by)
                           VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE monthly_fee=VALUES(monthly_fee), description=VALUES(description), updated_by=VALUES(updated_by)")
                ->execute([$tid, $monthlyFee, $desc, (int)me()['id']]);
            // Also update teams.membership_fee for backward compat
            $pdo->prepare("UPDATE teams SET membership_fee=? WHERE id=?")->execute([$monthlyFee, $tid]);
            flash('회비 설정이 저장되었습니다. ('.number_format($monthlyFee).'원/월)');
            redirect('?page=dues');
            break;

        // ── 월별 일괄 납부 처리 ──
        case 'bulk_dues_paid':
            requireLogin();
            if (!isCaptain()) { flash('캡틴만 가능합니다.', 'error'); redirect('?page=dues'); }
            $tid = myTeamId();
            $ym = preg_replace('/[^0-9\-]/', '', $_POST['year_month'] ?? '');
            if (!$ym) { flash('잘못된 요청입니다.', 'error'); redirect('?page=dues'); }
            // Get fee
            $fs = $pdo->prepare("SELECT monthly_fee FROM team_dues_settings WHERE team_id=?");
            $fs->execute([$tid]);
            $feeAmt = (int)($fs->fetchColumn() ?: 0);
            if ($feeAmt <= 0) {
                $fs2 = $pdo->prepare("SELECT membership_fee FROM teams WHERE id=?");
                $fs2->execute([$tid]);
                $feeAmt = (int)($fs2->fetchColumn() ?: 30000);
            }
            // Get all active members
            $mems = $pdo->prepare("SELECT user_id FROM team_members WHERE team_id=? AND status='active' AND role != 'mercenary'");
            $mems->execute([$tid]);
            $cnt = 0;
            foreach ($mems->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $ex = $pdo->prepare("SELECT id FROM team_dues_payments WHERE team_id=? AND user_id=? AND year_month=?");
                $ex->execute([$tid, $uid, $ym]);
                if ($ex->fetch()) {
                    $pdo->prepare("UPDATE team_dues_payments SET status='paid', paid_at=NOW(), recorded_by=? WHERE team_id=? AND user_id=? AND year_month=? AND status != 'exempt'")
                        ->execute([(int)me()['id'], $tid, $uid, $ym]);
                } else {
                    $pdo->prepare("INSERT INTO team_dues_payments (team_id, user_id, year_month, amount, status, paid_at, recorded_by) VALUES (?,?,?,?,?,NOW(),?)")
                        ->execute([$tid, $uid, $ym, $feeAmt, 'paid', (int)me()['id']]);
                }
                $cnt++;
            }
            flash($ym.' 전체 납부 처리 완료 ('.$cnt.'명)');
            redirect('?page=dues&year='.substr($ym,0,4));
            break;
    }
}

// ═══════════════════════════════════════════════════════════════
// PAGE RENDERER
// ═══════════════════════════════════════════════════════════════
function renderPage(PDO $pdo, string $page): void {
    $auth = ['home','matches','match','team','mypage','fees','reports','admin_reports','admin_deposit','ranking','leagues','league','mercenaries','recruits','team_create','team_join','join_team','venue_verify','agreements','messages','chat','friends','team_settings','admin_dashboard','admin_master','admin','mom_vote','team_eval','sos_create','notifications','history','guide','bug_report','point_history','team_points','dues'];
    // [TF-25] 이의제기 페이지는 비로그인(제재유저)도 접근 가능
    if ($page === 'appeal') { pagAppeal($pdo); return; }
    if (in_array($page,$auth) && !me()) {
        // 팀 초대 딥링크면 로그인 후 복귀할 URL 보존
        if (in_array($page, ['join_team','team_join']) && !empty($_GET['code'])) {
            $_SESSION['post_login_url'] = '?page=team_join&code=' . urlencode(strtoupper(trim($_GET['code'])));
        }
        redirect('?page=login');
    }
    // [가드] 어드민 전용 페이지 — renderHeader() 전에 체크 (headers already sent 방지)
    $adminPages = ['admin','admin_dashboard','admin_reports','admin_deposit'];
    if (in_array($page, $adminPages) && !isAnyAdmin()) {
        flash('관리자만 접근 가능합니다.', 'error');
        redirect('?page=home');
    }
    if ($page === 'admin_master' && !isSuperAdmin()) {
        flash('SUPER_ADMIN만 접근 가능합니다.', 'error');
        redirect('?page=home');
    }
    // [가드] �� 설정 — 캡틴/매니저만 접근 가능
    if ($page === 'team_settings' && !isCaptain()) {
        flash('캡틴만 접근 가능합니다.', 'error');
        redirect('?page=team');
    }
    // [가드] SOS/팀평가 — 캡틴 전용 페이지
    if (in_array($page, ['sos_create','team_eval']) && !isCaptain()) {
        redirect('?page=matches');
    }
    // [가드] MOM투표/팀평가/SOS — match_id 필수 파라미터
    if (in_array($page, ['mom_vote','team_eval','sos_create']) && empty($_GET['match_id'])) {
        redirect('?page=matches');
    }
    renderHeader($pdo);
    $flash = getFlash();
    if ($flash): ?>
<div class="tf-alert tf-alert-<?= $flash['type']==='error'?'error':'ok' ?>">
  <?= h($flash['msg']) ?>
  <button onclick="this.parentElement.remove()" class="tf-alert-close">×</button>
</div>
    <?php endif;
    match($page) {
        'login'         => pagLogin($pdo),
        'admin_login'   => pagAdminLogin($pdo),
        'register'      => pagRegister($pdo),
        'home'          => pagHome($pdo),
        'matches'       => pagMatches($pdo),
        'match'         => pagMatchDetail($pdo),
        'team'          => pagTeam($pdo),
        'mypage'        => pagMypage($pdo),
        'team_points'   => pagTeamPoints($pdo),
        'bug_report'    => pagBugReport($pdo),
        'point_history' => pagPointHistory($pdo),
        'ranking'       => pagRanking($pdo),
        'fees'          => pagFees($pdo),
        'dues'          => pagDues($pdo),
        'leagues'       => pagLeagues($pdo),
        'league'        => pagLeagueDetail($pdo),
        'reports'       => pagReports($pdo),
        'admin_reports' => pagAdminReports($pdo),
        'admin_deposit' => pagAdminDeposit($pdo),
        'mercenaries'   => pagMercenaries($pdo),
        'recruits'      => pagRecruits($pdo),
        'team_create'   => pagTeamCreate($pdo),
        'team_join'     => pagTeamJoin($pdo),
        'join_team'     => pagTeamJoin($pdo),    // 딥링크 별칭 (카톡/문자 공유용)
        'venue_verify'  => pagVenueVerify($pdo),
        'agreements'      => pagAgreements($pdo),
        'forgot_password' => pagForgotPassword(),
        'messages'        => pagMessages($pdo),
        'chat'            => pagChat($pdo),
        'friends'         => pagFriends($pdo),
        'team_profile'    => pagTeamProfile($pdo),
        'team_settings'   => pagTeamSettings($pdo),
        'admin_dashboard' => pagAdminDashboard($pdo),
        'admin_master'    => pagAdminMaster($pdo),
        'admin'           => pagAdmin($pdo),    // [어드민 MVP] 서브탭 구조 통합 어드민
        'history'         => pagHistory($pdo),  // [지난 경기 히스토리] 월별 그룹핑
        'guide'           => pagGuide($pdo),    // [앱 기능 소개] 신규 유저/시연용
        'mom_vote'        => pagMomVote($pdo),       // [1-4] MOM 투표 페이지
        'team_eval'       => pagTeamEval($pdo),      // [1-2] 캡틴이 상대팀 평가
        'sos_create'      => pagSosCreate($pdo),     // [4단계] 용병 SOS 생성
        'notifications'   => pagNotifications($pdo), // 알림 목록
        'oauth_kakao_callback' => pagKakaoCallback($pdo), // 카카오 OAuth 콜백
        'manual'          => pagManual(), // 사용설명서 (비로그인 접근 가능)
        'state_diagram'   => pagStateDiagram($pdo), // 상태전이 시각화
        'terms'           => pagTerms(), // 이용약관/개인정보/위치기반 (비로그인 접근 가능)
        default           => print('<div class="container" style="text-align:center;padding:60px 20px"><div style="font-size:60px;margin-bottom:12px">🤔</div><div style="font-size:18px;font-weight:700;margin-bottom:8px">페이지를 찾을 수 없습니다</div><div style="font-size:13px;color:var(--text-sub);margin-bottom:20px">요청하신 페이지가 없거나 접근 권한이 없습니다.</div><a href="?page=home" class="btn btn-primary">홈으로 돌아가기</a></div>'),
    };
    renderFooter($page);
}

// ─────────────────────────────────────────────
// HTML 헤더 + 다크 네온 CSS
// ─────────────────────────────────────────────
function renderHeader(PDO $pdo = null): void {
    // 로그인한 경우 팀명 + 직책 조회
    $myTeamInfo = null;
    if (me() && $pdo) {
        $ti = $pdo->prepare("
            SELECT t.name AS team_name, tm.role
            FROM team_members tm JOIN teams t ON t.id=tm.team_id
            WHERE tm.user_id=? AND tm.status='active' LIMIT 1
        ");
        $ti->execute([me()['id']]); $myTeamInfo = $ti->fetch();
    }
    $roleLabel = [
        'captain'=>'캡틴','vice_captain'=>'부캡틴','manager'=>'매니저',
        'coach'=>'코치','treasurer'=>'총무','player'=>'선수','mercenary'=>'용병',
    ]; ?>

<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>TRUST FOOTBALL</title>
<!-- [6단계] PWA -->
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#00FF88">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TRUST FC">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;900&family=Noto+Sans+KR:wght@400;500;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
/* ── CSS 변수 ── */
:root {
  --bg-main:       #0F1117;
  --bg-surface:    #1A1D24;
  --bg-surface-alt:#242830;
  --primary:       #00FF88;
  --primary-dim:   #00CC6A;
  --primary-glow:  rgba(0,255,136,.25);
  --danger:        #FF4D6D;
  --warning:       #FFB800;
  --info:          #38BDF8;
  --text-main:     #FFFFFF;
  --text-sub:      #9BA1B0;
  --border:        #2D323E;
  --radius-sm:     10px;
  --radius-md:     16px;
  --radius-lg:     20px;
}

/* ── 기본 ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
  background: var(--bg-main);
  color: var(--text-main);
  font-family: 'Noto Sans KR', 'Space Grotesk', sans-serif;
  padding-bottom: 80px;
  min-height: 100vh;
}
/* 숫자/영문에 Space Grotesk 적용 */
.num, h1, h2, h3, .score-box { font-family: 'Space Grotesk', 'Noto Sans KR', sans-serif; }

/* ── 탑바 ── */
.topbar {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 16px;
  background: var(--bg-surface);
  border-bottom: 1px solid var(--border);
  position: sticky; top: 0; z-index: 100;
}
.topbar-brand { font-family:'Space Grotesk',sans-serif; font-weight:900; font-size:18px; color:var(--primary); letter-spacing:1px; cursor:pointer; transition:opacity 0.15s; }
.topbar-brand:hover, .topbar-brand:active { opacity:0.75; }
.topbar-right { display:flex; align-items:center; gap:10px; }
.topbar-user { font-size:12px; color:var(--text-sub); }

/* ── 버튼 ── */
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  min-height: 52px; padding: 0 20px;
  border-radius: 14px; border: none; cursor: pointer;
  font-size: 15px; font-weight: 600;
  transition: transform .15s, box-shadow .15s, background .15s;
  text-decoration: none; line-height: 1;
}
.btn:active { transform: scale(0.96); }
.btn-primary {
  background: var(--primary); color: #0F1117;
  box-shadow: 0 0 16px var(--primary-glow);
}
.btn-primary:hover { background: var(--primary-dim); box-shadow: 0 0 24px var(--primary-glow); }
.btn-outline {
  background: transparent; color: var(--text-main);
  border: 1.5px solid var(--border);
}
.btn-outline:hover { border-color: var(--primary); color: var(--primary); }
.btn-danger  { background: var(--danger);  color: #fff; }
.btn-warning { background: var(--warning); color: #0F1117; }
.btn-ghost   { background: var(--bg-surface-alt); color: var(--text-sub); min-height:40px; padding:0 14px; font-size:13px; border-radius:10px; }
.btn-ghost:hover { color: var(--text-main); }
.btn-sm { min-height: 38px; padding: 0 14px; font-size: 13px; border-radius: 10px; }
.btn-w { width: 100%; }

/* ── 카드 ── */
.card {
  background: linear-gradient(145deg, var(--bg-surface) 0%, var(--bg-surface-alt) 100%);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  box-shadow: 0 4px 20px rgba(0,0,0,.3);
  overflow: hidden;
}
.card-body { padding: 16px; }
.card-link { text-decoration: none; color: var(--text-main); display: block; transition: transform .15s, box-shadow .15s; }
.card-link:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(0,0,0,.4); }

/* ── 폼 ── */
.form-group { margin-bottom: 16px; }
.form-label { display:block; font-size:13px; color:var(--text-sub); margin-bottom:6px; font-weight:500; }
.form-control, .form-select {
  width: 100%; min-height: 52px; padding: 12px 16px;
  background: var(--bg-surface-alt); color: var(--text-main);
  border: 1.5px solid var(--border); border-radius: var(--radius-sm);
  font-size: 15px; font-family: inherit;
  transition: border-color .2s, box-shadow .2s;
  outline: none; appearance: none;
}
.form-control::placeholder { color: var(--text-sub); }
.form-control:focus, .form-select:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 3px var(--primary-glow);
}
.form-select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%239BA1B0' d='M6 8L0 0h12z'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 14px center; padding-right:40px; }
textarea.form-control { min-height: 100px; resize: vertical; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

/* ── 알림 플래시 ── */
.tf-alert {
  display:flex; align-items:center; justify-content:space-between;
  margin:12px 16px; padding:14px 16px; border-radius:var(--radius-sm);
  font-size:14px; font-weight:500;
}
.tf-alert-ok    { background:rgba(0,255,136,.12); color:var(--primary); border:1px solid rgba(0,255,136,.3); }
.tf-alert-error { background:rgba(255,77,109,.12); color:var(--danger);  border:1px solid rgba(255,77,109,.3); }
.tf-alert-close { background:none; border:none; color:inherit; font-size:18px; cursor:pointer; line-height:1; padding:0 4px; }

/* ── 배지 ── */
.badge {
  display:inline-flex; align-items:center;
  padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; letter-spacing:.3px;
}
.badge-green   { background:rgba(0,255,136,.15); color:var(--primary); }
.badge-yellow  { background:rgba(255,184,0,.15);  color:var(--warning); }
.badge-red     { background:rgba(255,77,109,.15); color:var(--danger); }
.badge-blue    { background:rgba(56,189,248,.15);  color:var(--info); }
.badge-gray    { background:var(--bg-surface-alt); color:var(--text-sub); }

/* ── 구분선 ── */
.divider { border:none; border-top:1px solid var(--border); margin:20px 0; }

/* ── 하단 네비 ── */
.nav-bottom {
  position:fixed; bottom:0; left:0; right:0;
  background:var(--bg-surface); border-top:1px solid var(--border);
  display:flex; z-index:200;
  padding-bottom: env(safe-area-inset-bottom);
}
.nav-bottom a {
  flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center;
  padding:10px 4px 8px; color:var(--text-sub); text-decoration:none; font-size:10px; gap:3px;
  transition:color .15s;
}
.nav-bottom a i { font-size:22px; }
.nav-bottom a.active, .nav-bottom a:hover { color:var(--primary); }
.nav-dot { position:relative; }
.nav-dot::after { content:''; position:absolute; top:-2px; right:-4px; width:8px; height:8px; background:var(--danger); border-radius:50%; border:2px solid var(--bg-surface); }

/* ── 체크인 바 ── */
.ci-bar { height:6px; background:var(--bg-surface-alt); border-radius:3px; overflow:hidden; margin:6px 0; }
.ci-fill { height:100%; background:var(--primary); border-radius:3px; transition:width .4s; }

/* ── 스코어 ── */
.score-box { font-size:3rem; font-weight:900; letter-spacing:6px; color:var(--primary); text-align:center; }

/* ── 섹션 제목 ── */
.section-title { font-size:16px; font-weight:700; color:var(--text-main); margin-bottom:12px; }

/* ── 컨테이너 ── */
.container { max-width:600px; margin:0 auto; padding:16px; }

/* ── 테이블 ── */
.tf-table { width:100%; border-collapse:collapse; }
.tf-table th { font-size:12px; color:var(--text-sub); font-weight:600; padding:8px 10px; text-align:left; border-bottom:1px solid var(--border); }
.tf-table td { padding:12px 10px; border-bottom:1px solid var(--border); font-size:14px; }
.tf-table tr:last-child td { border-bottom:none; }
.tf-table .rank-1 { background:rgba(255,184,0,.07); }
.tf-table .rank-2 { background:rgba(255,255,255,.03); }

/* ── 필터 칩 ── */
.chip-row { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
.chip { display:inline-flex; align-items:center; height:34px; padding:0 14px; border-radius:20px; font-size:13px; font-weight:500; border:1.5px solid var(--border); color:var(--text-sub); background:transparent; cursor:pointer; text-decoration:none; transition:.15s; }
.chip.active, .chip:hover { border-color:var(--primary); color:var(--primary); background:rgba(0,255,136,.08); }

/* ── 로그인 전용 ── */
.login-wrap { min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:24px; }
.login-logo { font-family:'Space Grotesk',sans-serif; font-size:32px; font-weight:900; color:var(--primary); text-shadow:0 0 30px var(--primary-glow); margin-bottom:4px; }
.login-slogan { font-size:13px; color:var(--text-sub); margin-bottom:32px; }
.login-card { width:100%; max-width:400px; }

/* ── 통계 박스 ── */
.stat-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:16px; }
.stat-box { background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--radius-md); padding:14px; text-align:center; }
.stat-val { font-family:'Space Grotesk',sans-serif; font-size:22px; font-weight:700; }
.stat-lbl { font-size:11px; color:var(--text-sub); margin-top:2px; }

/* ── 리스트 아이템 ── */
.list-item { display:flex; justify-content:space-between; align-items:center; padding:14px 0; border-bottom:1px solid var(--border); }
.list-item:last-child { border-bottom:none; }

/* ── Collapse (순수 CSS) ── */
.tf-collapse { display:none; }
.tf-collapse.open { display:block; }

/* ── 포지션 칩 (전역) ── */
.pos-chip { border:1px solid var(--border); border-radius:8px; text-align:center; padding:8px 4px; font-size:13px; font-weight:600; transition:.2s; color:var(--text-sub); }
.pos-chip.active { background:color-mix(in srgb, var(--pos-color,var(--primary)) 15%, transparent); color:var(--pos-color,var(--primary)); border-color:var(--pos-color,var(--primary)); font-weight:800; }
.pos-radio:checked + .pos-chip,
.pos-radio2:checked + .pos-chip { background:color-mix(in srgb, var(--pos-color,var(--primary)) 15%, transparent); color:var(--pos-color,var(--primary)); border-color:var(--pos-color,var(--primary)); font-weight:800; }
</style>
<?php if(me()): ?><meta name="user-id" content="<?=(int)me()['id']?>"><?php endif; ?>
</head>
<body>
<?php if (me()):
  // 미읽음 알림 카운트
  $_unreadN = 0;
  if ($pdo) {
    try {
      $u = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
      $u->execute([me()['id']]); $_unreadN = (int)$u->fetchColumn();
    } catch(PDOException) {}
  }
?>
<header class="topbar">
  <a href="?page=home" class="topbar-brand" style="text-decoration:none">⚽ TRUST FOOTBALL</a>
  <div class="topbar-right">
    <?php $__topMsgCnt = getUnreadCount($pdo, me()['id']); ?>
    <a href="?page=messages" style="position:relative;color:var(--text-main);text-decoration:none;margin-right:10px">
      <i class="bi bi-chat-dots-fill" style="font-size:20px"></i>
      <?php if($__topMsgCnt > 0): ?>
      <span style="position:absolute;top:-4px;right:-6px;background:#ff3b30;color:#fff;border-radius:999px;font-size:8px;min-width:14px;height:14px;display:flex;align-items:center;justify-content:center;font-weight:800;padding:0 2px;border:1.5px solid var(--bg-surface)"><?=$__topMsgCnt > 99 ? '99+' : $__topMsgCnt?></span>
      <?php endif; ?>
    </a>
    <a href="?page=notifications" style="position:relative;color:var(--text-main);text-decoration:none;margin-right:6px">
      <i class="bi bi-bell-fill" style="font-size:18px"></i>
      <?php if($_unreadN>0): ?>
      <span style="position:absolute;top:-4px;right:-4px;background:var(--danger);color:#fff;border-radius:999px;font-size:9px;font-weight:700;min-width:14px;height:14px;display:inline-flex;align-items:center;justify-content:center;padding:0 3px"><?=$_unreadN>99?'99+':$_unreadN?></span>
      <?php endif; ?>
    </a>
    <div style="text-align:right;line-height:1.3">
      <div style="font-size:13px;font-weight:700;color:var(--text-main)"><?= h(displayName(me())) ?></div>
      <?php if($myTeamInfo): ?>
      <div style="font-size:10px;color:var(--text-sub)">
        <span style="color:var(--primary);font-weight:600"><?=h(teamDisplayName($myTeamInfo['team_name']))?></span>
        <span style="margin-left:4px;background:rgba(255,255,255,0.08);padding:1px 5px;border-radius:4px;font-size:9px;color:var(--text-sub)">
          <?=$roleLabel[$myTeamInfo['role']] ?? h($myTeamInfo['role'])?>
        </span>
      </div>
      <?php else: ?>
      <div style="font-size:10px;color:var(--text-sub)">팀 없음</div>
      <?php endif; ?>
    </div>
    <form method="POST" style="margin:0">
      <?= csrfInput() ?>
      <input type="hidden" name="action" value="logout">
      <button type="submit" class="btn btn-ghost btn-sm" style="font-size:11px;padding:4px 8px">로그아웃</button>
    </form>
  </div>
</header>
<?php else: ?>
<header class="topbar">
  <a href="?page=home" class="topbar-brand" style="text-decoration:none">⚽ TRUST FOOTBALL</a>
</header>
<?php endif; ?>
<?php }

// ─────────────────────────────────────────────
// 하단 네비 + 닫는 태그
// ─────────────────────────────────────────────
function renderFooter(string $page): void {
    if (!me()) { echo '</body></html>'; return; }
    global $pdo;
    $dot = 0; $msgDot = 0;
    if (myTeamId()) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM match_requests mr JOIN matches m ON m.id=mr.match_id WHERE m.home_team_id=? AND mr.status='pending'");
        $s->execute([myTeamId()]); $dot = (int)$s->fetchColumn();
    }
    // 안읽은 메시지 수 (TF-24 공통 함수 사용)
    $msgDot = getUnreadCount($pdo, me()['id']); ?>
<nav class="nav-bottom">
  <a href="?page=home"        class="<?= $page==='home'        ?'active':'' ?>"><i class="bi bi-house-fill"></i>홈</a>
  <a href="?page=matches"     class="<?= in_array($page,['matches','match'])?'active':'' ?>"><i class="bi bi-calendar2-week-fill"></i>경기</a>
  <a href="?page=mercenaries" class="<?= in_array($page,['mercenaries','recruits'])?'active':'' ?>">
    <i class="bi bi-megaphone-fill"></i>FA</a>
  <a href="?page=messages" class="<?= in_array($page,['messages','chat','friends'])?'active':'' ?>"
     style="position:relative;<?=$msgDot>0?'color:var(--primary)!important':''?>">
    <div style="position:relative;display:inline-block">
      <i class="bi bi-chat-dots-fill"></i>
      <?php if($msgDot>0): ?>
      <span style="position:absolute;top:-4px;right:-8px;background:#ff3b30;color:#fff;border-radius:999px;font-size:9px;min-width:16px;height:16px;display:flex;align-items:center;justify-content:center;font-weight:800;padding:0 3px;border:1.5px solid var(--bg-surface)"><?=$msgDot?></span>
      <?php endif; ?>
    </div>
    톡</a>
  <?php if(myTeamId()): ?><a href="?page=dues" class="<?= in_array($page,['dues','fees'])?'active':'' ?>"><i class="bi bi-cash-coin"></i>회비</a><?php endif; ?>
  <a href="?page=mypage" class="<?= in_array($page,['mypage','team','team_settings'])?'active':'' ?>" style="position:relative">
    <?php $totalDot = $dot;
    if(isCaptain()&&myTeamId()){
      $rd=$pdo->prepare("SELECT COUNT(*) FROM mercenary_requests mr JOIN matches m ON m.id=mr.match_id WHERE mr.team_id=? AND mr.status='pending'");
      $rd->execute([myTeamId()]); $totalDot+=(int)$rd->fetchColumn();
    } ?>
    <i class="bi bi-person-fill"></i>
    <?php if($totalDot>0): ?><span style="position:absolute;top:6px;right:calc(50% - 18px);background:var(--danger);color:#fff;border-radius:999px;font-size:9px;min-width:16px;height:16px;display:inline-flex;align-items:center;justify-content:center;font-weight:700;padding:0 3px"><?=$totalDot?></span><?php endif; ?>
    MY</a>
  <?php if (isAnyAdmin()): ?>
  <!-- [어드민 MVP] 관리 탭 (ADMIN + SUPER_ADMIN 노출). 기존 admin_master도 레거시 호환 유지 -->
  <a href="?page=admin" class="<?= in_array($page,['admin','admin_master']) ?'active':'' ?>" style="color:#ff4d6d">
    <i class="bi bi-shield-lock-fill" style="color:#ff4d6d"></i>관리
  </a>
  <?php endif; ?>
</nav>
<script>
// JS Collapse 토글
document.querySelectorAll('[data-tf-toggle]').forEach(btn=>{
  btn.addEventListener('click',()=>{
    const t=document.getElementById(btn.dataset.tfToggle);
    if(t) t.classList.toggle('open');
  });
});
// 전화번호 자동 하이픈
function phoneInput(el){
  el.addEventListener('input',function(){
    let v=this.value.replace(/\D/g,'');
    if(v.length>11)v=v.slice(0,11);
    if(v.length<=3)this.value=v;
    else if(v.length<=7)this.value=v.slice(0,3)+'-'+v.slice(3);
    else this.value=v.slice(0,3)+'-'+v.slice(3,7)+'-'+v.slice(7);
  });
}
document.querySelectorAll('.phone-input').forEach(phoneInput);
// [6단계] PWA Service Worker 등록
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(e => console.warn('SW reg fail', e));
  });
}
// [PWA] 설치 유도 배너 — 아직 설치 안 된 경우에만 (세션 1회)
var _deferredPrompt = null;
window.addEventListener('beforeinstallprompt', function(e) {
  e.preventDefault();
  _deferredPrompt = e;
  if (sessionStorage.getItem('pwa_dismiss')) return;
  var bar = document.createElement('div');
  bar.id = 'pwaInstallBar';
  bar.style.cssText = 'position:fixed;bottom:60px;left:0;right:0;z-index:1100;background:linear-gradient(135deg,rgba(0,255,136,0.15),rgba(0,0,0,0.95));backdrop-filter:blur(12px);border-top:1px solid rgba(0,255,136,0.3);padding:12px 16px;display:flex;align-items:center;gap:10px';
  bar.innerHTML =
    '<div style="font-size:24px">⚽</div>' +
    '<div style="flex:1"><div style="font-size:13px;font-weight:700;color:#00ff88">TRUST FOOTBALL 앱 설치</div><div style="font-size:11px;color:#aaa">홈 화면에 추가하면 더 빠르게!</div></div>' +
    '<button id="pwaInstallBtn" style="background:#00ff88;color:#000;border:none;border-radius:20px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer">설치</button>' +
    '<button onclick="document.getElementById(\'pwaInstallBar\').remove();sessionStorage.setItem(\'pwa_dismiss\',1)" style="background:none;border:none;color:#888;font-size:16px;cursor:pointer;padding:4px">✕</button>';
  document.body.appendChild(bar);
  document.getElementById('pwaInstallBtn').onclick = function() {
    if (_deferredPrompt) {
      _deferredPrompt.prompt();
      _deferredPrompt.userChoice.then(function() { _deferredPrompt = null; bar.remove(); });
    }
  };
});
// 클립보드 복사 (Toast UX) — 모던 API + execCommand fallback
window.showCopyToast = function(msg){
  var t = document.createElement('div');
  t.textContent = msg;
  t.style.cssText = 'position:fixed;bottom:90px;left:50%;transform:translateX(-50%);background:#00FF88;color:#0F1117;padding:10px 20px;border-radius:20px;font-size:13px;font-weight:700;z-index:10000;box-shadow:0 4px 20px rgba(0,255,136,0.35)';
  document.body.appendChild(t);
  setTimeout(function(){ if(t.parentNode) t.parentNode.removeChild(t); }, 2000);
};
window.copyToClipboard = function(text, _btn){
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(
      function(){ showCopyToast('복사되었습니다!'); },
      function(){ _fallbackCopy(text); }
    );
  } else {
    _fallbackCopy(text);
  }
};
function _fallbackCopy(text){
  try {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly','');
    ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
    document.body.appendChild(ta);
    ta.focus(); ta.select(); ta.setSelectionRange(0, text.length);
    var ok = document.execCommand('copy');
    document.body.removeChild(ta);
    showCopyToast(ok ? '복사되었습니다!' : ('직접 복사: ' + text));
  } catch(e){ showCopyToast('직접 복사: ' + text); }
}
// 공통 모달 열기/닫기
window.tfOpenModal = function(id){ var m = document.getElementById(id); if(m){ m.style.display='flex'; } };
window.tfViewPhoto = function(url){
  var overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.92);z-index:99999;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:20px';
  overlay.onclick = function(){ document.body.removeChild(overlay); };
  var img = document.createElement('img');
  img.src = url;
  img.style.cssText = 'max-width:90vw;max-height:85vh;border-radius:12px;object-fit:contain;box-shadow:0 8px 40px rgba(0,0,0,0.5)';
  var close = document.createElement('div');
  close.textContent = '✕';
  close.style.cssText = 'position:absolute;top:16px;right:16px;width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,0.15);color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;cursor:pointer';
  overlay.appendChild(img);
  overlay.appendChild(close);
  document.body.appendChild(overlay);
};
window.tfCloseModal = function(id){ var m = document.getElementById(id); if(m){ m.style.display='none'; } };

// ───────── 유저 프로필 모달 (공통) ─────────
// 사용법: <a onclick="openUserProfile(USER_ID)">닉네임</a>
window._lastProfileData = null;
window._lastProfileData = null;
window.openUserProfile = function(userId){
  if (!userId) return;
  var modal = document.getElementById('userProfileModal');
  if (!modal) { console.warn('userProfileModal not found'); return; }
  // DM 타겟 세팅 + 본인이면 DM 버튼 숨김
  var dmTarget = document.getElementById('upmDmTargetId');
  var dmBtn = document.getElementById('upmDmBtn');
  if (dmTarget) dmTarget.value = userId;
  // 본인 프로필이면 메시지 버튼 비활성
  var meId = <?= (int)(me()['id'] ?? 0) ?>;
  if (dmBtn) {
    if (meId && meId === +userId) { dmBtn.disabled = true; dmBtn.title = '본인에게는 DM 불가'; }
    else { dmBtn.disabled = false; dmBtn.title = ''; }
  }
  // 로딩 상태 표시
  var body = document.getElementById('upmBody');
  body.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text-sub)">불러오는 중...</div>';
  tfOpenModal('userProfileModal');
  fetch('?page=api&fn=user_profile&id=' + encodeURIComponent(userId), { credentials:'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (!j.ok) {
        if (j.err === 'unauthorized') {
          body.innerHTML = '<div style="text-align:center;padding:30px"><div style="color:var(--warning);margin-bottom:10px">🔒 로그인이 만료되었습니다</div>' +
            '<a href="?page=login" class="btn btn-primary btn-sm">다시 로그인</a>' +
            '<button type="button" onclick="location.reload()" class="btn btn-outline btn-sm" style="margin-left:6px">새로고침</button></div>';
        } else {
          body.innerHTML = '<div style="text-align:center;padding:30px;color:var(--danger)">' + (j.err || '로드 실패') + '</div>';
        }
        return;
      }
      var u = j.user;
      var pos = u.position || '-';
      var posColor = {GK:'#ff9500',DF:'#3a9ef5',MF:'#00ff88',FW:'#ff6b6b'}[pos] || '#888';
      var stats = [u.stat_pace, u.stat_shooting, u.stat_passing, u.stat_dribbling, u.stat_defending, u.stat_physical].map(function(v){return +v||50;});
      var labels = ['속도','슈팅','패스','드리블','수비','피지컬'];
      var avg = Math.round(stats.reduce(function(a,b){return a+b;},0) / 6);
      // 레이더 차트 (SVG)
      var cx = 70, cy = 70, R = 54;
      var polygon = stats.map(function(v, i){
        var ang = -Math.PI/2 + (i * 2*Math.PI/6);
        var r = R * (v/99);
        return (cx + r*Math.cos(ang)) + ',' + (cy + r*Math.sin(ang));
      }).join(' ');
      var axisLines = '';
      var labelTxts = '';
      for (var i=0;i<6;i++){
        var ang = -Math.PI/2 + (i * 2*Math.PI/6);
        var x2 = cx + R*Math.cos(ang), y2 = cy + R*Math.sin(ang);
        axisLines += '<line x1="'+cx+'" y1="'+cy+'" x2="'+x2+'" y2="'+y2+'" stroke="rgba(255,255,255,0.1)" />';
        var lx = cx + (R+12)*Math.cos(ang), ly = cy + (R+12)*Math.sin(ang) + 3;
        labelTxts += '<text x="'+lx+'" y="'+ly+'" text-anchor="middle" font-size="9" fill="#888">'+labels[i]+'</text>';
      }
      var rings = '';
      [0.25,0.5,0.75,1].forEach(function(k){
        var pts = [];
        for (var j=0;j<6;j++){
          var a = -Math.PI/2 + (j * 2*Math.PI/6);
          pts.push((cx + R*k*Math.cos(a)) + ',' + (cy + R*k*Math.sin(a)));
        }
        rings += '<polygon points="'+pts.join(' ')+'" fill="none" stroke="rgba(255,255,255,0.06)"/>';
      });
      var footLabel = {LEFT:'왼발',RIGHT:'오른발',BOTH:'양발'}[u.preferred_foot] || '-';
      // 분리 집계 (팀 vs 용병)
      var sp = u.split || {team:{played:0,attended:0,goals:0,assists:0}, merc:{played:0,attended:0,goals:0,assists:0}};
      var teamAttRate = sp.team.played > 0 ? Math.round(sp.team.attended/sp.team.played*100) : 0;
      var mercAttRate = sp.merc.played > 0 ? Math.round(sp.merc.attended/sp.merc.played*100) : 0;
      var splitTable =
        '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:6px">' +
          '<thead><tr style="color:var(--text-sub);font-size:10px;text-align:center"><th style="padding:4px;text-align:left">구분</th><th style="padding:4px">경기</th><th style="padding:4px">출석률</th><th style="padding:4px">골</th><th style="padding:4px">어시</th></tr></thead>' +
          '<tbody>' +
            '<tr style="border-top:1px solid rgba(255,255,255,0.06)"><td style="padding:6px 4px;color:var(--primary)">🛡️ 팀</td>' +
              '<td style="text-align:center">'+sp.team.played+'</td><td style="text-align:center">'+teamAttRate+'%</td>' +
              '<td style="text-align:center;color:#ffb400">'+sp.team.goals+'</td><td style="text-align:center;color:#3a9ef5">'+sp.team.assists+'</td></tr>' +
            '<tr style="border-top:1px solid rgba(255,255,255,0.04)"><td style="padding:6px 4px;color:#ffb400">⚡ 용병</td>' +
              '<td style="text-align:center">'+sp.merc.played+'</td><td style="text-align:center">'+mercAttRate+'%</td>' +
              '<td style="text-align:center;color:#ffb400">'+sp.merc.goals+'</td><td style="text-align:center;color:#3a9ef5">'+sp.merc.assists+'</td></tr>' +
          '</tbody>' +
        '</table>';
      // 최근 경기 이력 렌더 (탭 필터 — 전체/팀/용병)
      var recent = u.recent_matches || [];
      var histHtml = recent.length === 0
        ? '<div style="text-align:center;padding:20px;color:var(--text-sub);font-size:12px">경기 이력이 없습니다</div>'
        : recent.map(function(m){
            var isMerc = +m.is_mercenary === 1;
            var scoreStr = (m.score_home != null && m.score_away != null) ? (m.score_home+' : '+m.score_away) : '-';
            var badge = isMerc
              ? '<span class="badge" style="background:rgba(255,180,0,0.15);color:#ffb400;font-size:9px">⚡ 용병</span>'
              : '<span class="badge" style="background:rgba(0,255,136,0.12);color:var(--primary);font-size:9px">🛡️ 팀</span>';
            var title = m.title || ((m.home_name||'?')+' vs '+(m.away_name||'?'));
            return '<div class="tf-hist-row" data-kind="'+(isMerc?'merc':'team')+'" style="display:flex;align-items:center;gap:8px;padding:8px 4px;border-top:1px solid rgba(255,255,255,0.05)">' +
              '<div style="flex:1;min-width:0">' +
                '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">' + badge +
                  '<span style="font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+(title||'-')+'</span>' +
                '</div>' +
                '<div style="font-size:10px;color:var(--text-sub);margin-top:2px">'+(m.match_date||'')+(m.played_team_name ? ' · '+m.played_team_name : '')+'</div>' +
              '</div>' +
              '<div style="text-align:right;flex-shrink:0">' +
                '<div style="font-size:11px;font-family:\'Space Grotesk\',sans-serif">'+scoreStr+'</div>' +
                '<div style="font-size:10px;color:var(--text-sub)">⚽'+(+m.goals||0)+' 🎯'+(+m.assists||0)+'</div>' +
              '</div>' +
            '</div>';
          }).join('');
      // 메모 영역 (본인에 대해서는 숨김)
      var noteHtml = '';
      if (u.can_edit_note) {
        var noteText = (u.my_note && u.my_note.note) ? u.my_note.note : '';
        var noteTime = (u.my_note && u.my_note.updated_at) ? ' · ' + u.my_note.updated_at.replace('T',' ').substring(0,16) : '';
        noteHtml =
          '<div style="margin-top:12px;padding:10px;background:rgba(255,214,10,0.06);border:1px dashed rgba(255,214,10,0.25);border-radius:10px">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">' +
              '<div style="font-size:11px;color:#ffd60a;font-weight:700">📝 내 메모 (본인만 보임)' + noteTime + '</div>' +
              '<span id="upmNoteStatus" style="font-size:10px;color:var(--text-sub)"></span>' +
            '</div>' +
            '<textarea id="upmNoteText" maxlength="2000" placeholder="이 선수에 대한 기억/주의사항을 적어두세요 (나만 볼 수 있음)" style="width:100%;background:rgba(0,0,0,0.3);color:var(--text-main);border:1px solid rgba(255,255,255,0.08);border-radius:6px;padding:8px;font-size:12px;resize:vertical;min-height:60px">'+ (noteText.replace(/</g,'&lt;').replace(/>/g,'&gt;')) +'</textarea>' +
            '<button type="button" onclick="upmSaveNote('+u.id+')" class="btn btn-outline btn-sm" style="margin-top:6px;width:100%">메모 저장</button>' +
          '</div>';
      }
      // 강퇴 버튼 토글
      var kickBtn = document.getElementById('upmKickBtn');
      var kickTarget = document.getElementById('upmKickTargetId');
      var kickTeam = document.getElementById('upmKickTeamId');
      if (kickBtn) {
        if (u.can_kick && u.kick_team_id) {
          kickBtn.style.display = '';
          if (kickTarget) kickTarget.value = u.id;
          if (kickTeam) kickTeam.value = u.kick_team_id;
        } else {
          kickBtn.style.display = 'none';
        }
      }
      // [친구] 상태에 따라 버튼 변경
      var friendBtn = document.getElementById('upmFriendBtn');
      var friendTarget = document.getElementById('upmFriendTargetId');
      if (friendBtn && friendTarget) {
        friendTarget.value = u.id;
        var fs = u.friend_state || 'none';
        friendBtn.disabled = false;
        friendBtn.style.display = '';
        if (fs === 'self') {
          friendBtn.style.display = 'none';
        } else if (fs === 'accepted') {
          friendBtn.innerHTML = '<i class="bi bi-check-circle-fill"></i> 친구';
          friendBtn.disabled = true;
          friendBtn.style.color = 'var(--primary)';
        } else if (fs === 'outgoing') {
          friendBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> 요청 보냄';
          friendBtn.disabled = true;
          friendBtn.style.color = 'var(--text-sub)';
        } else if (fs === 'incoming') {
          friendBtn.innerHTML = '<i class="bi bi-inbox-fill"></i> 친구 페이지에서 수락';
          friendBtn.disabled = false;
          friendBtn.onclick = function(){ location.href='?page=friends'; };
        } else if (fs === 'blocked') {
          friendBtn.style.display = 'none';
        } else {
          friendBtn.innerHTML = '<i class="bi bi-person-plus"></i> 친구 요청';
          friendBtn.onclick = window.upmFriendAction;
        }
      }
      // SUPER_ADMIN 패널 타겟 세팅 (본인 제외)
      var superPanel = document.getElementById('upmSuperPanel');
      if (superPanel) {
        var selfId = <?= (int)(me()['id'] ?? 0) ?>;
        if (selfId && +u.id !== selfId) {
          superPanel.style.display = '';
          ['upmSaTargetA','upmSaTargetB','upmSaTargetC'].forEach(function(idName){
            var el = document.getElementById(idName); if (el) el.value = u.id;
          });
        } else {
          superPanel.style.display = 'none';
        }
      }
      body.innerHTML =
        '<div style="display:flex;align-items:center;gap:14px;margin-bottom:14px">' +
          (u.profile_image_url
            ? '<div onclick="tfViewPhoto(\''+u.profile_image_url+'\')" style="width:56px;height:56px;border-radius:50%;overflow:hidden;border:2px solid '+posColor+';background:#000;flex-shrink:0;cursor:pointer"><img src="'+u.profile_image_url+'" style="width:100%;height:100%;object-fit:cover"></div>'
            : '<div style="width:56px;height:56px;border-radius:50%;background:'+posColor+'22;border:2px solid '+posColor+';display:flex;align-items:center;justify-content:center;font-weight:800;color:'+posColor+'">'+pos+'</div>') +
          '<div style="flex:1;min-width:0">' +
            '<div style="font-size:17px;font-weight:700">'+(u.nickname||u.name||'-')+'</div>' +
            '<div style="font-size:12px;color:var(--text-sub);margin-top:2px">'+(u.team_name ? u.team_name+' · ' : '')+(u.region||'')+' '+(u.district||'')+'</div>' +
            (u.jersey_number ? '<span class="badge" style="background:rgba(255,255,255,0.1);color:#fff;font-size:12px;font-weight:800;margin-top:4px;display:inline-block;font-family:\'Space Grotesk\',sans-serif">#'+u.jersey_number+'</span> ' : '') +
            (u.is_player_background ? '<span class="badge" style="background:rgba(0,255,136,0.15);color:var(--primary);font-size:10px;margin-top:4px;display:inline-block">⚽ 선수 출신</span>' : '') +
          '</div>' +
        '</div>' +
        '<div style="display:flex;justify-content:center;margin-bottom:10px">' +
          '<svg width="140" height="140" viewBox="0 0 140 140">' + rings + axisLines +
            '<polygon points="'+polygon+'" fill="rgba(0,255,136,0.25)" stroke="#00ff88" stroke-width="2"/>' + labelTxts +
            '<text x="70" y="74" text-anchor="middle" font-size="14" font-weight="800" fill="#00ff88">'+avg+'</text>' +
          '</svg>' +
        '</div>' +
        (function(){var gpm = u.matches_played > 0 ? (u.goals_total / u.matches_played * 100).toFixed(0) : '0';
          var apm = u.matches_played > 0 ? (u.assists_total / u.matches_played * 100).toFixed(0) : '0';
          return '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;text-align:center;margin-bottom:6px">' +
          '<div><div style="font-size:18px;font-weight:800;color:var(--primary)">'+u.matches_played+'</div><div style="font-size:10px;color:var(--text-sub)">경기</div></div>' +
          '<div><div style="font-size:18px;font-weight:800;color:#ffb400">'+u.goals_total+'</div><div style="font-size:10px;color:var(--text-sub)">득점</div></div>' +
          '<div><div style="font-size:18px;font-weight:800;color:#3a9ef5">'+u.assists_total+'</div><div style="font-size:10px;color:var(--text-sub)">도움</div></div>' +
        '</div>' +
        '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;text-align:center;margin-bottom:10px">' +
          '<div><div style="font-size:14px;font-weight:700">'+u.attendance_rate+'%</div><div style="font-size:9px;color:var(--text-sub)">출석률</div></div>' +
          '<div><div style="font-size:14px;font-weight:700;color:#ffb400">'+gpm+'%</div><div style="font-size:9px;color:var(--text-sub)">득점률</div></div>' +
          '<div><div style="font-size:14px;font-weight:700;color:#3a9ef5">'+apm+'%</div><div style="font-size:9px;color:var(--text-sub)">어시율</div></div>'; })() +
        '</div>' +
        '<div style="font-size:12px;color:var(--text-sub);display:flex;justify-content:space-between;padding:8px 4px;border-top:1px solid rgba(255,255,255,0.06)">' +
          '<span>매너 '+u.manner_score+'</span><span>MOM '+u.mom_count+'</span>' +
          '<span>'+(u.height?u.height+'cm':'-')+' · '+(u.weight?u.weight+'kg':'-')+'</span><span>'+footLabel+'</span>' +
        '</div>' +
        '<div style="margin-top:12px;padding-top:8px;border-top:1px solid rgba(255,255,255,0.06)">' +
          '<div style="font-size:11px;font-weight:700;color:var(--text-sub);margin-bottom:4px">팀 vs 용병 활동 분리</div>' +
          splitTable +
        '</div>' +
        '<div style="margin-top:14px">' +
          '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">' +
            '<div style="font-size:12px;font-weight:700;color:var(--text-sub)">최근 경기 '+recent.length+'건</div>' +
            '<div class="chip-row" style="gap:4px;flex-wrap:nowrap">' +
              '<button type="button" onclick="upmFilterHist(\'all\',this)" class="chip active" style="font-size:10px;padding:2px 8px">전체</button>' +
              '<button type="button" onclick="upmFilterHist(\'team\',this)" class="chip" style="font-size:10px;padding:2px 8px">팀</button>' +
              '<button type="button" onclick="upmFilterHist(\'merc\',this)" class="chip" style="font-size:10px;padding:2px 8px">용병</button>' +
            '</div>' +
          '</div>' +
          '<div id="upmHistList">' + histHtml + '</div>' +
        '</div>' +
        noteHtml;
    })
    .catch(function(e){ body.innerHTML = '<div style="text-align:center;padding:30px;color:var(--danger)">네트워크 오류</div>'; });
};
</script>

<!-- [B4] 플로팅 피드백 버튼 + 모달 (로그인 유저 전역 노출)
     ⚠️ 채팅(?page=chat)에서는 메시지 전송 버튼과 겹쳐서 숨김 처리 -->
<?php if ($page !== 'chat'): ?>
<button type="button" onclick="tfOpenModal('feedbackModal')" aria-label="피드백 보내기"
  style="position:fixed;bottom:100px;right:12px;z-index:1200;width:44px;height:44px;border-radius:50%;border:none;background:linear-gradient(135deg,#00ff88,#00c869);color:#0F1117;font-size:18px;box-shadow:0 4px 20px rgba(0,255,136,0.4);cursor:pointer;display:flex;align-items:center;justify-content:center;opacity:0.85;transition:opacity 0.15s"
  onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.85'">
  📣
</button>
<?php endif; ?>

<div id="feedbackModal" class="tf-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;align-items:center;justify-content:center;padding:16px" onclick="if(event.target===this) tfCloseModal('feedbackModal')">
  <div class="card" style="width:100%;max-width:400px;margin:0" onclick="event.stopPropagation()">
    <div class="card-body">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <h3 style="font-size:15px;font-weight:700;margin:0">📣 피드백 보내기</h3>
        <button type="button" onclick="tfCloseModal('feedbackModal')" class="btn btn-ghost btn-sm" style="padding:4px 10px">✕</button>
      </div>
      <div style="font-size:11px;color:var(--text-sub);margin-bottom:12px">버그·건의·불편한 점을 알려주세요. 베타 기간 동안 형이 직접 봅니다 🙏</div>
      <form method="POST" action="?page=home">
        <?=csrfInput()?>
        <input type="hidden" name="action" value="submit_feedback">
        <input type="hidden" name="page_url" id="fbPageUrl">
        <!-- 타입 선택 4카드 -->
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:4px;margin-bottom:10px">
          <label style="cursor:pointer">
            <input type="radio" name="type" value="BUG" checked style="display:none" class="fb-radio">
            <div class="fb-chip" style="border:2px solid rgba(255,255,255,0.1);border-radius:8px;padding:8px 4px;text-align:center;background:rgba(255,255,255,0.02)">
              <div style="font-size:18px">🐛</div><div style="font-size:10px;font-weight:700;margin-top:2px">버그</div>
            </div>
          </label>
          <label style="cursor:pointer">
            <input type="radio" name="type" value="FEATURE" style="display:none" class="fb-radio">
            <div class="fb-chip" style="border:2px solid rgba(255,255,255,0.1);border-radius:8px;padding:8px 4px;text-align:center;background:rgba(255,255,255,0.02)">
              <div style="font-size:18px">💡</div><div style="font-size:10px;font-weight:700;margin-top:2px">건의</div>
            </div>
          </label>
          <label style="cursor:pointer">
            <input type="radio" name="type" value="COMPLIMENT" style="display:none" class="fb-radio">
            <div class="fb-chip" style="border:2px solid rgba(255,255,255,0.1);border-radius:8px;padding:8px 4px;text-align:center;background:rgba(255,255,255,0.02)">
              <div style="font-size:18px">💖</div><div style="font-size:10px;font-weight:700;margin-top:2px">칭찬</div>
            </div>
          </label>
          <label style="cursor:pointer">
            <input type="radio" name="type" value="OTHER" style="display:none" class="fb-radio">
            <div class="fb-chip" style="border:2px solid rgba(255,255,255,0.1);border-radius:8px;padding:8px 4px;text-align:center;background:rgba(255,255,255,0.02)">
              <div style="font-size:18px">📝</div><div style="font-size:10px;font-weight:700;margin-top:2px">기타</div>
            </div>
          </label>
        </div>
        <style>
          .fb-radio:checked + .fb-chip { border-color: var(--primary); background: rgba(0,255,136,0.12); }
          .fb-radio:checked + .fb-chip > :nth-child(2) { color: var(--primary); }
        </style>
        <textarea name="message" required minlength="5" maxlength="1000" rows="5" placeholder="어떤 부분이 불편하셨나요? 구체적으로 적어주실수록 빨리 반영됩니다."
          style="width:100%;background:rgba(0,0,0,0.3);color:var(--text-main);border:1px solid rgba(255,255,255,0.08);border-radius:6px;padding:10px;font-size:13px;resize:vertical;margin-bottom:10px"></textarea>
        <button type="submit" class="btn btn-primary btn-w">제출</button>
      </form>
    </div>
  </div>
</div>
<script>
// 피드백 모달 열릴 때 현재 URL 자동 채우기
(function(){
  var btn = document.querySelector('[onclick*="feedbackModal"]');
  if (btn) {
    btn.addEventListener('click', function(){
      var f = document.getElementById('fbPageUrl');
      if (f) f.value = window.location.pathname + window.location.search;
    });
  }
})();
</script>

<!-- 유저 프로필 모달 (공통, 모든 페이지에서 openUserProfile(id) 호출 가능) -->
<div id="userProfileModal" class="tf-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;align-items:center;justify-content:center;padding:12px" onclick="if(event.target===this) tfCloseModal('userProfileModal')">
  <div class="card" style="width:100%;max-width:400px;max-height:85vh;margin:0;display:flex;flex-direction:column" onclick="event.stopPropagation()">
    <!-- 헤더 (고정) -->
    <div style="padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.06);display:flex;justify-content:space-between;align-items:center;flex-shrink:0">
      <h3 style="font-size:15px;font-weight:700;margin:0">선수 프로필</h3>
      <button type="button" onclick="tfCloseModal('userProfileModal')" class="btn btn-ghost btn-sm" aria-label="닫기" style="padding:4px 10px">✕</button>
    </div>
    <!-- 스크롤 영역 -->
    <div style="overflow-y:auto;flex:1;padding:14px 16px">
      <div id="upmBody"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:14px">
        <button type="button" id="upmFriendBtn" class="btn btn-outline btn-sm" onclick="upmFriendAction()">
          <i class="bi bi-person-plus"></i> 친구 요청
        </button>
        <button type="button" id="upmDmBtn" class="btn btn-primary btn-sm" onclick="upmSendDm()">
          <i class="bi bi-chat-dots"></i> 메시지 보내기
        </button>
      </div>
      <form id="upmFriendForm" method="POST" style="display:none">
        <?=csrfInput()?>
        <input type="hidden" name="action" value="send_friend_request">
        <input type="hidden" name="target_user_id" id="upmFriendTargetId">
      </form>
      <!-- 캡틴 전용: 팀원 강퇴 (can_kick=true 일 때만 노출) -->
      <button type="button" id="upmKickBtn" onclick="upmKick()" class="btn btn-w btn-sm" style="display:none;margin-top:8px;color:var(--danger);border:1px solid rgba(255,77,109,0.3);background:rgba(255,77,109,0.08)">
        <i class="bi bi-person-x"></i> 팀원 강퇴
      </button>
      <?php if (isSuperAdmin()): ?>
      <!-- SUPER_ADMIN 전용 도구 -->
      <div id="upmSuperPanel" style="display:none;margin-top:10px;padding:10px;border:1px dashed rgba(255,77,109,0.3);background:rgba(255,77,109,0.05);border-radius:8px">
        <div style="font-size:10px;color:#ff4d6d;font-weight:700;margin-bottom:6px">🔐 SUPER_ADMIN</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:6px">
          <form method="POST" onsubmit="return confirm('7일 제한하시겠습니까?')" style="margin:0"><?=csrfInput()?><input type="hidden" name="action" value="admin_restrict_user"><input type="hidden" name="user_id" id="upmSaTargetA"><input type="hidden" name="days" value="7"><button type="submit" class="btn btn-sm btn-w" style="font-size:11px;background:#ff9500;color:#fff;border:none">7일 제한</button></form>
          <form method="POST" onsubmit="return confirm('블랙리스트 등록? (로그인 차단)')" style="margin:0"><?=csrfInput()?><input type="hidden" name="action" value="admin_blacklist_user"><input type="hidden" name="user_id" id="upmSaTargetB"><button type="submit" class="btn btn-sm btn-w" style="font-size:11px;color:#ff4d6d;border:1px solid rgba(255,77,109,0.3);background:transparent">블랙리스트</button></form>
        </div>
        <form method="POST" style="display:flex;gap:4px;margin:0">
          <?=csrfInput()?>
          <input type="hidden" name="action" value="admin_adjust_manner">
          <input type="hidden" name="user_id" id="upmSaTargetC">
          <input type="number" name="delta" step="0.5" min="-50" max="50" placeholder="±점수" style="flex:1;background:rgba(0,0,0,0.3);color:var(--text-main);border:1px solid rgba(255,255,255,0.08);border-radius:6px;padding:6px;font-size:12px" required>
          <button type="submit" class="btn btn-sm" style="font-size:11px">매너 조정</button>
        </form>
      </div>
      <?php endif; ?>
      <form id="upmDmForm" method="POST" style="display:none">
        <?=csrfInput()?>
        <input type="hidden" name="action" value="start_chat">
        <input type="hidden" name="target_user_id" id="upmDmTargetId">
      </form>
      <form id="upmKickForm" method="POST" style="display:none">
        <?=csrfInput()?>
        <input type="hidden" name="action" value="kick_member">
        <input type="hidden" name="target_user_id" id="upmKickTargetId">
        <input type="hidden" name="team_id" id="upmKickTeamId">
      </form>
    </div>
  </div>
</div>
<script>
window.upmSendDm = function(){
  var uid = document.getElementById('upmDmTargetId').value;
  if (!uid) return;
  document.getElementById('upmDmForm').submit();
};
// [친구] 친구 요청 POST
window.upmFriendAction = function(){
  var uid = document.getElementById('upmFriendTargetId').value;
  if (!uid) return;
  document.getElementById('upmFriendForm').submit();
};
// 강퇴 (캡틴 전용)
window.upmKick = function(){
  if (!confirm('정말 이 팀원을 강퇴하시겠습니까?')) return;
  document.getElementById('upmKickForm').submit();
};
// 최근 경기 필터 (전체/팀/용병)
window.upmFilterHist = function(kind, btn){
  var rows = document.querySelectorAll('#upmHistList .tf-hist-row');
  rows.forEach(function(r){
    if (kind === 'all' || r.dataset.kind === kind) r.style.display='';
    else r.style.display='none';
  });
  if (btn && btn.parentElement) {
    btn.parentElement.querySelectorAll('.chip').forEach(function(b){ b.classList.remove('active'); });
    btn.classList.add('active');
  }
};
// 비공개 메모 저장 (fetch POST + CSRF)
window.upmSaveNote = function(targetUid){
  var ta = document.getElementById('upmNoteText');
  var st = document.getElementById('upmNoteStatus');
  if (!ta) return;
  var fd = new FormData();
  fd.append('action', 'save_user_note');
  fd.append('csrf_token', '<?=csrfToken()?>');
  fd.append('target_user_id', targetUid);
  fd.append('note', ta.value);
  if (st) st.textContent = '저장 중...';
  fetch('?page=api', { method:'POST', body: fd, credentials:'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (j.ok) { if (st) { st.textContent = '저장됨 ✓'; setTimeout(function(){ st.textContent=''; }, 2000); } }
      else { if (st) st.textContent = '실패: ' + (j.err||''); }
    })
    .catch(function(){ if (st) st.textContent = '네트워크 오류'; });
};
// AJAX 참석 투표 - 매치 상세용
window.ajaxVoteDetail = function(matchId, vote, btn) {
  var fd = new FormData();
  fd.append('action', 'vote_attendance');
  fd.append('match_id', matchId);
  fd.append('vote', vote);
  fd.append('csrf_token', '<?=csrfToken()?>');
  var wrap = btn.closest('.vote-wrap');
  var btns = wrap ? wrap.querySelectorAll('button') : [];
  btns.forEach(function(b){ b.disabled=true; });
  fetch(window.location.pathname, { method:'POST', body:fd, credentials:'same-origin' })
    .then(function(){
      var colors = {ATTEND:['var(--primary)','rgba(0,255,136,0.12)'],PENDING:['var(--text-sub)','rgba(255,255,255,0.06)'],ABSENT:['#ff4d4d','rgba(255,59,48,0.1)']};
      btns.forEach(function(b){
        var bv = b.dataset ? b.getAttribute('onclick').match(/'(\w+)'/)[1] : '';
        if (!bv) return;
        var active = bv === vote;
        var c = colors[bv] || ['var(--text-sub)','transparent'];
        b.style.fontWeight = active ? '700' : '500';
        b.style.background = active ? c[1] : 'transparent';
        b.style.color = active ? c[0] : 'var(--text-sub)';
        b.style.borderColor = active ? c[0] : 'var(--border)';
        b.disabled = false;
      });
    })
    .catch(function(){ btns.forEach(function(b){b.disabled=false;}); alert('네트워크 오류'); });
};
// AJAX 후보(벤치) 토글
// 대리출��� 일괄 저장
  function selectVote(btn, vote) {
    var row = btn.closest('.batch-row');
    row.querySelectorAll('.att-btn').forEach(function(b){ b.className='btn btn-sm att-btn btn-outline'; b.style.background=''; b.style.color=''; b.style.borderColor=''; });
    btn.classList.remove('btn-outline');
    if(vote==='ATTEND') btn.classList.add('btn-primary');
    else if(vote==='ABSENT') { btn.style.background='rgba(255,59,48,0.15)'; btn.style.color='#ff4d4d'; btn.style.borderColor='#ff4d4d'; }
    else { btn.style.background='rgba(255,255,255,0.06)'; btn.style.color='var(--text-sub)'; }
    row.setAttribute('data-vote', vote);
  }
  function selectBench(btn, bench) {
    var row = btn.closest('.batch-row');
    row.querySelectorAll('.bench-btn').forEach(function(b){ b.className='btn btn-sm bench-btn btn-outline'; b.style.background=''; b.style.color=''; b.style.borderColor=''; });
    btn.classList.remove('btn-outline');
    if(bench===0) btn.classList.add('btn-primary');
    else { btn.style.background='rgba(255,180,0,0.15)'; btn.style.color='#ffb400'; btn.style.borderColor='#ffb400'; }
    row.setAttribute('data-bench', bench);
  }
  function saveBatchAttendance(matchId) {
    var rows = document.querySelectorAll('.batch-row');
    var votes = {}; var benchMap = {};
    rows.forEach(function(row) {
      var uid = row.getAttribute('data-uid');
      var sv = row.querySelector('.att-btn:not(.btn-outline)');
      var sb = row.querySelector('.bench-btn:not(.btn-outline)');
      if(sv) votes[uid] = sv.getAttribute('data-vote');
      if(sb) benchMap[uid] = sb.getAttribute('data-bench');
    });
    var msg = document.getElementById('batchSaveMsg');
    msg.style.display='block'; msg.textContent='저장 중...'; msg.style.color='var(--text-sub)';
    var fd = new FormData();
    fd.append('action','batch_proxy_attendance');
    fd.append('match_id', matchId);
    fd.append('votes', JSON.stringify(votes));
    fetch('?page=api', {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){return r.json()})
      .then(function(d){
        var bp = [];
        Object.keys(benchMap).forEach(function(uid){
          var f2 = new FormData();
          f2.append('action','set_bench'); f2.append('match_id',matchId); f2.append('user_id',uid); f2.append('is_bench',benchMap[uid]);
          bp.push(fetch('?page=api',{method:'POST',body:f2,credentials:'same-origin'}).then(function(r){return r.json()}));
        });
        return Promise.all(bp).then(function(){return d});
      })
      .then(function(d){
        msg.textContent='✅ '+(d.msg||'저장 완료!'); msg.style.color='var(--primary)';
        setTimeout(function(){location.reload()},800);
      })
      .catch(function(e){ msg.textContent='❌ 실패: '+e.message; msg.style.color='#ff4d4d'; });
  }

  window.toggleBenchTo = function(matchId, userId, toBench, el) {
    var fd = new FormData();
    fd.append('action','set_bench');
    fd.append('match_id', matchId);
    fd.append('user_id', userId);
    fd.append('is_bench', toBench);
    fetch('?page=api', {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){return r.json()})
      .then(function(d){ if(d.ok) location.reload(); else alert(d.msg||'실패'); })
      .catch(function(e){ alert(e.message); });
  };

  window.toggleBench = function(matchId, userId, el) {
  var fd = new FormData();
  fd.append('action','toggle_bench');
  fd.append('match_id', matchId);
  fd.append('user_id', userId);
  fd.append('csrf_token', '<?=csrfToken()?>');
  fetch(window.location.pathname, {method:'POST', body:fd, credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.ok) location.reload();
      else alert(d.msg||'오류');
    })
    .catch(function(){ alert('네트워크 오류'); });
};
// AJAX 참석 투표 - 홈 카드용
window.ajaxVote = function(matchId, vote, btn) {
  var fd = new FormData();
  fd.append('action', 'vote_attendance');
  fd.append('match_id', matchId);
  fd.append('vote', vote);
  fd.append('csrf_token', '<?=csrfToken()?>');
  btn.disabled = true;
  btn.textContent = '...';
  fetch(window.location.pathname, { method:'POST', body:fd, credentials:'same-origin' })
    .then(function(r){ return r.text(); })
    .then(function(){
      var wrap = btn.closest('.vote-wrap');
      if (wrap) {
        if (vote === 'ATTEND') {
          wrap.innerHTML = '<span style="color:var(--primary);font-weight:700;font-size:13px">✓ 참석 완료</span> <button class="btn btn-ghost btn-sm" style="font-size:11px;margin-left:6px" onclick="ajaxVote('+matchId+',\'ABSENT\',this)">취소</button>';
        } else {
          wrap.innerHTML = '<span style="color:var(--danger);font-size:13px">✕ 불참</span> <button class="btn btn-primary btn-sm" style="font-size:11px;margin-left:6px" onclick="ajaxVote('+matchId+',\'ATTEND\',this)">참석으로 변경</button>';
        }
      }
    })
    .catch(function(){ btn.disabled=false; btn.textContent=vote==='ATTEND'?'✓ 참석':'✕ 불참'; alert('네트워크 오류'); });
};
</script>
</body></html>
<?php }

// ═══════════════════════════════════════════════════════════════
// 로그인
// ═══════════════════════════════════════════════════════════════

// [TF-07] 관리자 전용 로그인 페이지
function pagAdminLogin(PDO $pdo): void { ?>
<div class="login-wrap">
  <div style="text-align:center;margin-bottom:24px">
    <div style="width:72px;height:72px;border-radius:20px;background:linear-gradient(135deg,#ff4d6d 0%,#ff758c 100%);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:36px;box-shadow:0 8px 24px rgba(255,77,109,0.25)">&#x1F6E1;</div>
    <div style="font-size:24px;font-weight:900;letter-spacing:1px">ADMIN LOGIN</div>
    <div style="font-size:13px;color:var(--text-sub);margin-top:4px">관리자 전용 로그인</div>
  </div>
  <div class="card login-card">
    <div class="card-body">
      <form method="POST" action="?page=admin_login">
        <?= csrfInput() ?>
        <input type="hidden" name="action" value="admin_login">
        <div class="form-group">
          <div style="position:relative">
            <i class="bi bi-phone" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-sub)"></i>
            <input type="tel" name="phone" class="form-control phone-input" style="padding-left:36px;height:48px;font-size:15px" placeholder="관리자 전화번호" maxlength="13" required autofocus>
          </div>
        </div>
        <div class="form-group">
          <div style="position:relative">
            <i class="bi bi-lock" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-sub)"></i>
            <input type="password" name="password" class="form-control" style="padding-left:36px;height:48px;font-size:15px" placeholder="비밀번호" required>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-w" style="font-size:15px;padding:13px;border-radius:12px;font-weight:800;background:linear-gradient(135deg,#ff4d6d,#ff758c)">관리자 로그인</button>
      </form>
      <hr class="divider" style="margin:16px 0">
      <a href="?page=login" class="btn btn-outline btn-w" style="font-size:14px;padding:12px;border-radius:10px">
        <i class="bi bi-arrow-left"></i> 일반 로그인으로
      </a>
    </div>
  </div>
  <div style="text-align:center;margin-top:8px;font-size:10px;color:var(--text-sub)">&copy; 2026 TRUST FOOTBALL ADMIN</div>
</div>
<?php }

function pagLogin(PDO $pdo): void { ?>
<div class="login-wrap">
  <!-- 로고 영역 -->
  <div style="text-align:center;margin-bottom:24px">
    <div style="width:72px;height:72px;border-radius:20px;background:linear-gradient(135deg,var(--primary) 0%,#00cc6a 100%);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:36px;box-shadow:0 8px 24px rgba(0,255,136,0.25)">⚽</div>
    <div style="font-size:24px;font-weight:900;letter-spacing:1px">TRUST FOOTBALL</div>
    <div style="font-size:13px;color:var(--text-sub);margin-top:4px">매너가 만드는 완벽한 매치</div>
  </div>

  <!-- 로그인 카드 -->
  <div class="card login-card">
    <div class="card-body">
      <form method="POST" action="?page=login">
        <?= csrfInput() ?>
        <input type="hidden" name="action" value="login">
        <div class="form-group">
          <div style="position:relative">
            <i class="bi bi-phone" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-sub)"></i>
            <input type="tel" name="phone" class="form-control phone-input" style="padding-left:36px;height:48px;font-size:15px"
                   placeholder="전화번호 (010-0000-0000)" maxlength="13" required autofocus>
          </div>
        </div>
        <div class="form-group">
          <div style="position:relative">
            <i class="bi bi-lock" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-sub)"></i>
            <input type="password" name="password" class="form-control" style="padding-left:36px;height:48px;font-size:15px"
                   placeholder="비밀번호" required>
          </div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:var(--text-sub)">
            <input type="checkbox" name="remember_me" value="1" style="width:15px;height:15px;accent-color:var(--primary)">
            로그인 유지
          </label>
          <a href="?page=forgot_password" style="font-size:12px;color:var(--text-sub)">비밀번호 찾기</a>
        </div>
        <button type="submit" class="btn btn-primary btn-w" style="font-size:15px;padding:13px;border-radius:12px;font-weight:800">로그인</button>
      </form>

      <!-- 간편 로그인 -->
      <div style="display:flex;align-items:center;gap:10px;margin:18px 0">
        <div style="flex:1;height:1px;background:var(--border)"></div>
        <span style="font-size:11px;color:var(--text-sub);white-space:nowrap">간편 로그인</span>
        <div style="flex:1;height:1px;background:var(--border)"></div>
      </div>

      <div style="display:flex;flex-direction:column;gap:8px">
        <!-- 카카오 -->
        <?php if (KAKAO_REST_KEY !== '__KAKAO_REST_API_KEY__'): ?>
        <a href="https://kauth.kakao.com/oauth/authorize?client_id=<?=KAKAO_REST_KEY?>&redirect_uri=<?=urlencode(KAKAO_REDIRECT)?>&response_type=code"
           class="btn btn-w" style="background:#FEE500;color:#3C1E1E;font-size:14px;padding:12px;font-weight:700;border:none;border-radius:10px;display:flex;align-items:center;justify-content:center;gap:8px">
          <svg width="18" height="18" viewBox="0 0 18 18"><path fill="#3C1E1E" d="M9 1C4.58 1 1 3.79 1 7.21c0 2.17 1.45 4.08 3.64 5.18-.16.56-.58 2.03-.66 2.35-.1.39.14.39.3.28.12-.08 1.95-1.32 2.74-1.86.63.09 1.28.14 1.98.14 4.42 0 8-2.79 8-6.21S13.42 1 9 1"/></svg>
          카카오로 시작
        </a>
        <?php else: ?>
        <button disabled class="btn btn-w" style="background:#FEE500;color:#3C1E1E;font-size:14px;padding:12px;font-weight:700;border:none;border-radius:10px;opacity:0.5;display:flex;align-items:center;justify-content:center;gap:8px">
          <svg width="18" height="18" viewBox="0 0 18 18"><path fill="#3C1E1E" d="M9 1C4.58 1 1 3.79 1 7.21c0 2.17 1.45 4.08 3.64 5.18-.16.56-.58 2.03-.66 2.35-.1.39.14.39.3.28.12-.08 1.95-1.32 2.74-1.86.63.09 1.28.14 1.98.14 4.42 0 8-2.79 8-6.21S13.42 1 9 1"/></svg>
          카카오 로그인 (준비중)
        </button>
        <?php endif; ?>
        <!-- 네이버 -->
        <button disabled class="btn btn-w" style="background:#03C75A;color:#fff;font-size:14px;padding:12px;font-weight:700;border:none;border-radius:10px;opacity:0.5;display:flex;align-items:center;justify-content:center;gap:8px">
          <svg width="16" height="16" viewBox="0 0 16 16"><path fill="#fff" d="M10.87 8.56L5.01 0H0v16h5.13V7.44L10.99 16H16V0h-5.13z"/></svg>
          네이버 로그인 (준비중)
        </button>
      </div>

      <hr class="divider" style="margin:16px 0">

      <!-- 회원가입 -->
      <a href="?page=register" class="btn btn-outline btn-w" style="font-size:14px;padding:12px;border-radius:10px">
        <i class="bi bi-person-plus"></i> 회원가입
      </a>
    </div>
  </div>

  <!-- 하단 링크 -->
  <div style="text-align:center;margin-top:20px;display:flex;justify-content:center;gap:12px;flex-wrap:wrap">
    <a href="?page=manual" style="font-size:11px;color:var(--primary)">📖 사용설명서</a>
    <a href="?page=terms" style="font-size:11px;color:var(--primary)">📄 이용약관</a>
    <a href="?page=terms&tab=privacy" style="font-size:11px;color:var(--text-sub)">개인정보처리방침</a>
  </div>
  <div style="text-align:center;margin-top:8px;font-size:10px;color:var(--text-sub)">© 2026 TRUST FOOTBALL</div>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 회원가입
// ═══════════════════════════════════════════════════════════════
function pagRegister(PDO $pdo): void {
    $agreements = $pdo->query("SELECT agreement_type,version,is_required,content_url FROM agreement_versions WHERE is_active=1 ORDER BY FIELD(agreement_type,'TOS','PRIVACY','LOCATION','MARKETING')")->fetchAll();
    $typeLabels = ['TOS'=>'이용약관','PRIVACY'=>'개인정보처리방침','LOCATION'=>'위치기반서비스','MARKETING'=>'마케팅 정보 수신(선택)'];
    $fromKakao = isset($_SESSION['pending_kakao']);
    $kakaoData = $fromKakao ? $_SESSION['pending_kakao'] : null;
    ?>
<div class="login-wrap">
  <div class="login-logo"><?= $fromKakao ? '💬 카카오 회원가입' : '회원가입' ?></div>
  <div class="login-slogan"><?= $fromKakao ? '카카오 계정이 확인되었습니다! 추가 정보를 입력해주세요.' : 'TRUST FOOTBALL과 함께하세요' ?></div>
  <?php if ($fromKakao && !empty($kakaoData['profile_image_url'])): ?>
  <div style="text-align:center;margin-bottom:12px">
    <img src="<?=h($kakaoData['profile_image_url'])?>" style="width:60px;height:60px;border-radius:50%;border:2px solid var(--primary)">
    <div style="font-size:13px;color:var(--primary);margin-top:4px"><?=h($kakaoData['nickname'] ?? '')?></div>
  </div>
  <?php endif; ?>
  <div class="card login-card">
    <div class="card-body">
      <form method="POST" action="?page=register">
        <?= csrfInput() ?>
        <input type="hidden" name="action" value="register">

        <!-- 기본 정보 -->
        <div class="form-group"><label class="form-label">이름</label>
          <input type="text" name="name" class="form-control" required></div>
        <div class="form-group"><label class="form-label">전화번호 <span style="font-size:12px;color:var(--text-sub)">(로그인 ID)</span></label>
          <input type="tel" name="phone" class="form-control phone-input" placeholder="010-0000-0000" maxlength="13" required></div>
        <div class="form-group"><label class="form-label">비밀번호 <span style="font-size:12px;color:var(--text-sub)">(6자 이상)</span></label>
          <input type="password" name="password" id="regPw1" class="form-control" required minlength="6"></div>
        <!-- [신규] 비밀번호 재입력 — 브라우저 단 실시간 일치 체크 + 서버에서도 재검증 -->
        <div class="form-group"><label class="form-label">비밀번호 확인</label>
          <input type="password" name="password_confirm" id="regPw2" class="form-control" required minlength="6" placeholder="한 번 더 입력하세요">
          <div id="regPwMatch" style="font-size:11px;margin-top:4px;min-height:14px"></div>
        </div>
        <script>
        (function(){
          var p1 = document.getElementById('regPw1'), p2 = document.getElementById('regPw2'),
              msg = document.getElementById('regPwMatch');
          function check() {
            if (!p2.value) { msg.textContent = ''; return; }
            if (p1.value === p2.value) {
              msg.textContent = '✓ 일치';
              msg.style.color = '#00ff88';
            } else {
              msg.textContent = '✗ 비밀번호가 다릅니다';
              msg.style.color = '#ff4d6d';
            }
          }
          p1.addEventListener('input', check);
          p2.addEventListener('input', check);
        })();
        </script>

        <!-- 프로필 (선택) -->
        <hr class="divider">
        <p style="font-size:13px;color:var(--text-sub);margin-bottom:12px">프로필 설정 <span style="font-size:11px">(나중에 마이페이지에서 변경 가능)</span></p>
        <div class="form-group">
          <label class="form-label">주포지션 <span style="font-size:11px;color:var(--text-sub)">(선택 시 강조 표시됩니다)</span></label>
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px">
            <?php
            $posInfo = [
              'GK'=>['color'=>'#ff9500','desc'=>'골키퍼'],
              'DF'=>['color'=>'#3a9ef5','desc'=>'수비수'],
              'MF'=>['color'=>'#00ff88','desc'=>'미드필더'],
              'FW'=>['color'=>'#ff6b6b','desc'=>'공격수'],
            ];
            foreach($posInfo as $p => $pi): ?>
            <label style="cursor:pointer">
              <input type="radio" name="position" value="<?=$p?>" style="display:none" class="pos-radio">
              <div class="pos-chip" style="--pos-color:<?=$pi['color']?>">
                <div style="font-size:13px;font-weight:700"><?=$p?></div>
                <div style="font-size:9px;opacity:0.7;margin-top:1px"><?=$pi['desc']?></div>
              </div>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">활동 지역</label>
            <input type="text" name="region" class="form-control" placeholder="서울"></div>
          <div class="form-group"><label class="form-label">구/군</label>
            <input type="text" name="district" class="form-control" placeholder="강남구"></div>
        </div>

        <!-- 선수 출신 -->
        <label style="display:flex;align-items:center;gap:10px;margin-bottom:14px;cursor:pointer;padding:12px;background:var(--bg-surface-alt);border-radius:10px;border:1px solid var(--border)">
          <input type="checkbox" name="is_player_background" value="1" style="width:18px;height:18px;accent-color:var(--primary)">
          <div>
            <div style="font-weight:600;font-size:14px">⚽ 선수 출신</div>
            <div style="font-size:12px;color:var(--text-sub)">아마추어 클럽팀, 학교 선수단, 리그 경험자</div>
          </div>
        </label>

        <!-- 팀 초대코드 (선택) -->
        <div class="form-group">
          <label class="form-label">팀 초대코드 <span style="font-size:12px;color:var(--text-sub)">(있는 경우 입력)</span></label>
          <input type="text" name="team_code" class="form-control" placeholder="ABCD1234" maxlength="10" style="text-transform:uppercase;letter-spacing:2px">
          <div style="font-size:12px;color:var(--text-sub);margin-top:4px">가입 즉시 해당 팀 소속으로 시작됩니다.</div>
        </div>

        <!-- 약관 동의 -->
        <hr class="divider">
        <div style="background:var(--bg-surface-alt);border-radius:12px;padding:14px;margin-bottom:14px">
          <label style="display:flex;align-items:center;gap:10px;margin-bottom:10px;cursor:pointer;font-weight:700">
            <input type="checkbox" id="agreeAll" style="width:18px;height:18px">
            <span>전체 동의</span>
          </label>
          <div style="height:1px;background:var(--border);margin-bottom:10px"></div>
          <?php foreach ($agreements as $ag):
            $label = $typeLabels[$ag['agreement_type']] ?? $ag['agreement_type'];
            $isReq = $ag['is_required']; ?>
          <label style="display:flex;align-items:center;gap:10px;margin-bottom:8px;cursor:pointer">
            <input type="checkbox" name="agree_<?=h($ag['agreement_type'])?>" value="1"
                   class="agree-check" style="width:16px;height:16px"
                   <?=$isReq ? 'required' : ''?>>
            <span style="font-size:14px;flex:1"><?=h($label)?> <?=$isReq?'<span style="color:var(--danger);font-size:12px">(필수)</span>':'<span style="color:var(--text-sub);font-size:12px">(선택)</span>'?></span>
            <?php
              $tabMap = ['TOS'=>'tos','PRIVACY'=>'privacy','LOCATION'=>'location'];
              $termTab = $tabMap[$ag['agreement_type']] ?? '';
            ?>
            <a href="?page=terms<?=$termTab ? '&tab='.$termTab : ''?>" target="_blank" style="font-size:12px;color:var(--text-sub);white-space:nowrap">보기</a>
          </label>
          <?php endforeach; ?>
        </div>

        <button type="submit" class="btn btn-primary btn-w">가입하기</button>
      </form>
      <hr class="divider">
      <a href="?page=login" class="btn btn-outline btn-w">이미 계정이 있어요</a>
    </div>
  </div>
</div>
<style>
/* pos-chip은 전역 CSS에 정의됨 */
</style>
<script>
const agreeAll=document.getElementById('agreeAll');
const checks=document.querySelectorAll('.agree-check');
agreeAll.addEventListener('change',()=>checks.forEach(c=>c.checked=agreeAll.checked));
checks.forEach(c=>c.addEventListener('change',()=>{agreeAll.checked=[...checks].every(x=>x.checked);}));
</script>
<?php }

// ═══════════════════════════════════════════════════════════════
// 홈
// ═══════════════════════════════════════════════════════════════
function getWeatherData(): ?array {
    $cacheFile = '/tmp/tf_weather_cache.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 1800) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if ($data) return $data;
    }
    $region = me()['region'] ?? '서울';
    if (empty(trim($region))) $region = '서울';
    $regionEncoded = urlencode($region);
    $ctx = stream_context_create(['http'=>['timeout'=>5,'header'=>'User-Agent: TrustFootball/1.0']]);
    $json = @file_get_contents("https://wttr.in/{$regionEncoded}?format=j1", false, $ctx);
    if (!$json) return null;
    $data = json_decode($json, true);
    if (!$data || empty($data['current_condition'])) return null;
    @file_put_contents($cacheFile, $json);
    return $data;
}
function weatherIcon(string $code): string {
    $map = ['113'=>'☀️','116'=>'⛅','119'=>'☁️','122'=>'☁️','143'=>'🌫️','176'=>'🌦️','179'=>'🌨️','182'=>'🌨️',
        '185'=>'🌨️','200'=>'⛈️','227'=>'❄️','230'=>'❄️','248'=>'🌫️','260'=>'🌫️','263'=>'🌧️','266'=>'🌧️',
        '281'=>'🌨️','284'=>'🌨️','293'=>'🌧️','296'=>'🌧️','299'=>'🌧️','302'=>'🌧️','305'=>'🌧️','308'=>'🌧️',
        '311'=>'🌨️','314'=>'🌨️','317'=>'🌨️','320'=>'❄️','323'=>'❄️','326'=>'❄️','329'=>'❄️','332'=>'❄️',
        '335'=>'❄️','338'=>'❄️','350'=>'🌨️','353'=>'🌧️','356'=>'🌧️','359'=>'🌧️','362'=>'🌨️','365'=>'🌨️',
        '368'=>'❄️','371'=>'❄️','374'=>'🌨️','377'=>'🌨️','386'=>'⛈️','389'=>'⛈️','392'=>'⛈️','395'=>'❄️'];
    return $map[$code] ?? '🌤️';
}

function pagHome(PDO $pdo): void {
    $myTeamId = myTeamId();
    $weatherData = getWeatherData();
    // [B2 온보딩] 아직 온보딩 안 본 유저면 플래그 세팅
    $needOnboarding = false;
    if (me()) {
        $ob = $pdo->prepare("SELECT onboarded_at FROM users WHERE id=?");
        $ob->execute([(int)me()['id']]);
        $needOnboarding = ($ob->fetchColumn() === null);
    }
    // 탭: week(이번주) | month(이번달) — 기본값 이번주
    $period   = $_GET['period'] ?? 'week';

    $dateWhere = match($period) {
        'month' => 'AND m.match_date BETWEEN CURDATE() AND LAST_DAY(CURDATE())',
        default => 'AND m.match_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)',
    };
    $tabLabels = ['week' => '이번주', 'month' => '이번달'];

    if ($myTeamId) {
        // 서브쿼리로 집계 — GROUP BY 오염 없이 정확한 카운트
        $stmt = $pdo->prepare("
            SELECT m.*,
                   COALESCE(ht.name,'(팀 미지정)') AS home_name, at.name AS away_name,
                   (SELECT COUNT(*) FROM match_attendance WHERE match_id=m.id AND team_id=? AND status='ATTEND')  AS attend_count,
                   (SELECT COUNT(*) FROM match_attendance WHERE match_id=m.id AND team_id=? AND status='ABSENT')  AS absent_count,
                   (SELECT COUNT(*) FROM match_attendance WHERE match_id=m.id AND team_id=? AND status='PENDING') AS pending_count,
                   (SELECT status   FROM match_attendance WHERE match_id=m.id AND user_id=? LIMIT 1) AS my_att_status
            FROM matches m
            LEFT JOIN teams ht ON ht.id=m.home_team_id
            LEFT JOIN teams at ON at.id=m.away_team_id
            WHERE (m.home_team_id=? OR m.away_team_id=?)
              AND m.status NOT IN ('cancelled','finished','completed','force_closed')
              AND m.match_date >= CURDATE() $dateWhere
            ORDER BY m.match_date, m.match_time
        ");
        $stmt->execute([$myTeamId, $myTeamId, $myTeamId, me()['id'], $myTeamId, $myTeamId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT m.*, COALESCE(ht.name,'(팀 미지정)') AS home_name, at.name AS away_name,
                   0 AS attend_count, 0 AS absent_count, 0 AS pending_count, NULL AS my_att_status
            FROM matches m LEFT JOIN teams ht ON ht.id=m.home_team_id LEFT JOIN teams at ON at.id=m.away_team_id
            WHERE m.status IN ('open','request_pending') AND m.match_date >= CURDATE() $dateWhere
            ORDER BY m.match_date, m.match_time LIMIT 15
        ");
        $stmt->execute();
    }
    $matches = $stmt->fetchAll();

    // ── 참석자 이름 목록 (팀원별, 경기별 한번에 조회) ───────────
    $attendanceByMatch = [];
    if ($myTeamId && $matches) {
        $mids = implode(',', array_map(fn($m)=>(int)$m['id'], $matches));
        $as = $pdo->query("
            SELECT ma.match_id, ma.status, u.name, u.nickname, ma.user_id
            FROM match_attendance ma JOIN users u ON u.id=ma.user_id
            WHERE ma.match_id IN ($mids) AND ma.team_id=$myTeamId
            ORDER BY FIELD(ma.status,'ATTEND','PENDING','ABSENT'), u.name
        ");
        foreach ($as->fetchAll() as $a) {
            $attendanceByMatch[$a['match_id']][] = $a;
        }
    }

    // ── 알림 데이터 ─────────────────────────────────────────────
    $pendingMercReq = 0; $pendingRecruitApp = 0; $myMercPending = 0; $myRecruitPending = 0;
    if (me() && $myTeamId && isCaptain()) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM mercenary_requests mr JOIN matches m ON m.id=mr.match_id WHERE mr.team_id=? AND mr.status='pending'");
        $s->execute([$myTeamId]); $pendingMercReq = (int)$s->fetchColumn();
        $s = $pdo->prepare("SELECT COUNT(*) FROM recruit_applications ra JOIN recruit_posts rp ON rp.id=ra.post_id WHERE rp.team_id=? AND ra.status='pending'");
        $s->execute([$myTeamId]); $pendingRecruitApp = (int)$s->fetchColumn();
    }
    if (me() && !$myTeamId) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM mercenary_requests WHERE user_id=? AND status='pending'");
        $s->execute([me()['id']]); $myMercPending = (int)$s->fetchColumn();
        $s = $pdo->prepare("SELECT COUNT(*) FROM recruit_applications WHERE user_id=? AND status='pending'");
        $s->execute([me()['id']]); $myRecruitPending = (int)$s->fetchColumn();
    }

    // ── 랭킹 TOP 3 (홈 미니 섹션용) ────────────────────────────
    $topTeams = $pdo->query("
        SELECT name, win, draw, loss, (win*3+draw) AS pts, region
        FROM teams ORDER BY pts DESC, win DESC LIMIT 3
    ")->fetchAll();
    ?>
<?php
// [TF-11] 제재 유저 이의제기 폼
if (!empty($_SESSION['user_restricted'])):
    $appealPending = false;
    try {
        $apCheck = $pdo->prepare("SELECT id FROM user_appeals WHERE user_id=? AND status='pending' LIMIT 1");
        $apCheck->execute([(int)me()['id']]);
        $appealPending = (bool)$apCheck->fetch();
    } catch(PDOException $e) {}
?>
<div class="card" style="margin:12px auto;max-width:600px;border:2px solid var(--danger)">
  <div class="card-body" style="text-align:center">
    <div style="font-size:32px;margin-bottom:8px">&#x1F6AB;</div>
    <div style="font-size:16px;font-weight:700;color:var(--danger);margin-bottom:6px">이용 제한 중</div>
    <?php if($_SESSION['restriction_until'] ?? ''): ?>
    <div style="font-size:13px;color:var(--text-sub);margin-bottom:4px">제한 기간: ~<?=h($_SESSION['restriction_until'])?></div>
    <?php endif; ?>
    <?php if($_SESSION['restriction_reason'] ?? ''): ?>
    <div style="font-size:13px;color:var(--text-sub);margin-bottom:12px">사유: <?=h($_SESSION['restriction_reason'])?></div>
    <?php endif; ?>
    <?php if($appealPending): ?>
    <div style="padding:12px;background:var(--card-bg);border-radius:8px;font-size:13px;color:var(--warning)">
      <i class="bi bi-hourglass-split"></i> 이의제기가 접수되어 관리자 검토 중입니다.
    </div>
    <?php else: ?>
    <div style="font-size:13px;color:var(--text-sub);margin-bottom:12px">이의가 있으시면 아래 양식으로 제출해주세요.</div>
    <form method="POST" style="text-align:left">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="submit_appeal">
      <textarea name="appeal_reason" class="form-control" rows="4" placeholder="이의제기 사유를 상세히 작성해주세요 (최소 10자)" style="font-size:13px;margin-bottom:10px" required minlength="10"></textarea>
      <button type="submit" class="btn btn-primary btn-w" style="font-size:14px" onclick="return confirm('이의제기를 제출하시겠습니까?')">이의제기 제출</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>


<?php if ($needOnboarding): ?>
<!-- [B2] 가입 직후 온보딩 오버레이 (3슬라이드) -->
<div id="tfOnboard" style="position:fixed;inset:0;background:rgba(0,0,0,0.9);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(8px)">
  <div style="width:100%;max-width:380px;background:var(--bg-surface);border:1px solid rgba(0,255,136,0.25);border-radius:18px;overflow:hidden">
    <!-- 진행 바 -->
    <div style="height:4px;background:rgba(255,255,255,0.06);display:flex">
      <div id="obBar1" style="flex:1;background:var(--primary);transition:0.3s"></div>
      <div id="obBar2" style="flex:1;background:rgba(255,255,255,0.06);transition:0.3s"></div>
      <div id="obBar3" style="flex:1;background:rgba(255,255,255,0.06);transition:0.3s"></div>
    </div>
    <div style="padding:24px 22px">
      <!-- 슬라이드 1: 팀 찾기/만들기 -->
      <div id="obSlide1" class="ob-slide">
        <div style="text-align:center;margin-bottom:18px">
          <div style="font-size:56px;line-height:1;margin-bottom:8px">🛡️</div>
          <div style="font-size:18px;font-weight:800;margin-bottom:6px;color:var(--primary)">환영합니다!</div>
          <div style="font-size:13px;color:var(--text-sub);line-height:1.5">TRUST FOOTBALL은 동호회 축구 매칭 앱입니다.<br>첫 번째로 팀을 설정해볼까요?</div>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px">
          <a href="?page=team_join" class="btn btn-primary btn-w" style="font-size:13px"><i class="bi bi-key"></i> 초대코드가 있어요</a>
          <a href="?page=team_create" class="btn btn-outline btn-w" style="font-size:13px"><i class="bi bi-plus-square"></i> 새 팀 만들기</a>
          <button type="button" onclick="obNext()" class="btn btn-ghost btn-w" style="font-size:12px;color:var(--text-sub)">나중에 할게요 →</button>
        </div>
      </div>
      <!-- 슬라이드 2: 용병 활동 -->
      <div id="obSlide2" class="ob-slide" style="display:none">
        <div style="text-align:center;margin-bottom:18px">
          <div style="font-size:56px;line-height:1;margin-bottom:8px">⚡</div>
          <div style="font-size:18px;font-weight:800;margin-bottom:6px;color:#ffb400">용병으로도 뛸 수 있어요</div>
          <div style="font-size:13px;color:var(--text-sub);line-height:1.5">팀이 없거나, 팀원이 부족한 날에도<br>다른 팀의 <strong style="color:#ffb400">용병</strong>으로 경기에 참여할 수 있습니다.</div>
        </div>
        <div style="padding:12px;background:rgba(255,184,0,0.06);border:1px dashed rgba(255,184,0,0.3);border-radius:10px;margin-bottom:14px;font-size:11px;color:var(--text-sub);line-height:1.6">
          💡 FA시장에 프로필을 올리면 주변 팀에서 <strong>용병 제안</strong>이 들어옵니다.<br>
          🔥 실력 있으면 <strong>팀 가입 제안</strong>도 받을 수 있어요.
        </div>
        <div style="display:flex;flex-direction:column;gap:8px">
          <a href="?page=mercenaries" class="btn btn-primary btn-w" style="font-size:13px"><i class="bi bi-lightning-charge"></i> 용병 등록하러 가기</a>
          <button type="button" onclick="obNext()" class="btn btn-ghost btn-w" style="font-size:12px;color:var(--text-sub)">다음 →</button>
        </div>
      </div>
      <!-- 슬라이드 3: 매치 둘러보기 -->
      <div id="obSlide3" class="ob-slide" style="display:none">
        <div style="text-align:center;margin-bottom:18px">
          <div style="font-size:56px;line-height:1;margin-bottom:8px">⚽</div>
          <div style="font-size:18px;font-weight:800;margin-bottom:6px">준비 완료!</div>
          <div style="font-size:13px;color:var(--text-sub);line-height:1.5">내 지역의 매치를 둘러보세요.<br>매너점수·레이더차트로 신뢰할 수 있는 팀/선수를 찾을 수 있어요.</div>
        </div>
        <div style="padding:12px;background:rgba(0,255,136,0.06);border:1px solid rgba(0,255,136,0.2);border-radius:10px;margin-bottom:14px;font-size:11px;color:var(--text-sub);line-height:1.6">
          🎯 <strong>문제 있으면 📣 버튼으로 피드백!</strong><br>
          🚫 노쇼/매너 위반은 자동 제재됩니다 (앱 안내 따라주세요).
        </div>
        <form method="POST" id="obDoneForm">
          <?=csrfInput()?>
          <input type="hidden" name="action" value="mark_onboarded">
          <button type="submit" class="btn btn-primary btn-w" style="font-size:13px">시작하기 🚀</button>
        </form>
      </div>
      <!-- 건너뛰기 (1, 2 슬라이드에서만) -->
      <div id="obSkipArea" style="text-align:center;margin-top:12px">
        <button type="button" onclick="obSkipAll()" style="background:none;border:none;color:var(--text-sub);font-size:11px;cursor:pointer;text-decoration:underline;text-underline-offset:2px">전체 건너뛰기</button>
      </div>
    </div>
  </div>
</div>
<form id="obSkipForm" method="POST" style="display:none">
  <?=csrfInput()?>
  <input type="hidden" name="action" value="mark_onboarded">
  <input type="hidden" name="skipped" value="1">
</form>
<script>
(function(){
  var step = 1;
  window.obNext = function(){
    if (step >= 3) return;
    document.getElementById('obSlide'+step).style.display = 'none';
    step++;
    document.getElementById('obSlide'+step).style.display = '';
    document.getElementById('obBar'+step).style.background = 'var(--primary)';
    if (step === 3) document.getElementById('obSkipArea').style.display = 'none';
  };
  window.obSkipAll = function(){
    if (confirm('온보딩을 건너뛰시겠습니까? 언제든 FA시장/팀 페이지에서 시작할 수 있습니다.')) {
      document.getElementById('obSkipForm').submit();
    }
  };
})();
</script>
<?php endif; ?>

<div class="container">

  <?php /* ── 인사말 + 날씨 한 줄 ─── */
  $hour = (int)date('H');
  $greeting = $hour < 12 ? '좋은 아침이에요' : ($hour < 18 ? '좋은 오후예요' : '좋은 저녁이에요');
  $userName = displayName(me());
  $wLine = '';
  if ($weatherData):
    $cur = $weatherData['current_condition'][0] ?? null;
    if ($cur):
      $wIcon = weatherIcon($cur['weatherCode']);
      $wTemp = $cur['temp_C'];
      $goodW = (int)$wTemp >= 10 && (int)$wTemp <= 30 && (float)$cur['precipMM'] < 3;
      $wLine = "{$wIcon} {$wTemp}° " . ($goodW ? '경기하기 좋은 날!' : '우산 챙기세요 🌂');
    endif;
  endif;
  ?>
  <div style="margin-bottom:14px;padding:2px 0">
    <div style="font-size:16px;font-weight:700"><?=$greeting?>, <?=h($userName)?>님</div>
    <?php if($wLine): ?><div style="font-size:12px;color:var(--text-sub);margin-top:2px"><?=$wLine?></div><?php endif; ?>
  </div>

  
  <?php /* ── [운영 경고 배너] 캡틴/어드민 전용 ─── */
  if (me() && isCaptain() && $myTeamId):
    $alertItems = [];

    // 1. 인원 부족 경기 (7일 내, ATTEND < max_players)
    try {
      $alStmt = $pdo->prepare("
        SELECT m.id, m.title, m.max_players,
               (SELECT COUNT(*) FROM match_attendance WHERE match_id=m.id AND team_id=? AND status='ATTEND') AS att_count
        FROM matches m
        WHERE (m.home_team_id=? OR m.away_team_id=?)
          AND m.match_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND m.status NOT IN ('cancelled','completed','force_closed')
        ORDER BY m.match_date LIMIT 10
      ");
      $alStmt->execute([$myTeamId, $myTeamId, $myTeamId]);
      foreach ($alStmt->fetchAll() as $am) {
        if ((int)$am['att_count'] < (int)$am['max_players']) {
          $alertItems[] = [
            'icon' => '⚠️',
            'msg'  => h($am['title']) . ' 참석 인원이 부족합니다 (현재 '.(int)$am['att_count'].'명/필요 '.(int)$am['max_players'].'명)',
            'link' => '?page=match&id='.(int)$am['id'],
            'color'=> '#ffb400',
          ];
        }
      }
    } catch (PDOException $e) {}

    // 2. 미승인 결과
    try {
      $alStmt2 = $pdo->prepare("
        SELECT m.id, m.title FROM matches m
        WHERE (m.home_team_id=? OR m.away_team_id=?)
          AND m.status='result_pending'
        ORDER BY m.match_date DESC LIMIT 5
      ");
      $alStmt2->execute([$myTeamId, $myTeamId]);
      foreach ($alStmt2->fetchAll() as $am2) {
        $alertItems[] = [
          'icon' => '📋',
          'msg'  => h($am2['title']) . ' 결과가 아직 승인되지 않았습니다',
          'link' => '?page=match&id='.(int)$am2['id'],
          'color'=> '#ff8c00',
        ];
      }
    } catch (PDOException $e) {}

    // 3. 오래된 pending (용병 요청 + 팀 초대 3일 이상)
    try {
      $oldPendingCount = 0;
      $s3a = $pdo->prepare("SELECT COUNT(*) FROM mercenary_requests mr JOIN matches m ON m.id=mr.match_id WHERE mr.team_id=? AND mr.status='pending' AND mr.created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)");
      $s3a->execute([$myTeamId]); $oldPendingCount += (int)$s3a->fetchColumn();
      $s3b = $pdo->prepare("SELECT COUNT(*) FROM team_join_offers WHERE team_id=? AND status='pending' AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)");
      $s3b->execute([$myTeamId]); $oldPendingCount += (int)$s3b->fetchColumn();
      if ($oldPendingCount > 0) {
        $alertItems[] = [
          'icon' => '⏰',
          'msg'  => '처리 대기 중인 요청이 '.$oldPendingCount.'건 있습니다',
          'link' => '?page=mercenaries',
          'color'=> '#e67e22',
        ];
      }
    } catch (PDOException $e) {}

    // 4. 미확인 버그 리포트 (어드민만)
    if (isAdmin()) {
      try {
        $s4 = $pdo->query("SELECT COUNT(*) FROM bug_reports WHERE status='pending'");
        $bugCount = (int)$s4->fetchColumn();
        if ($bugCount > 0) {
          $alertItems[] = [
            'icon' => '🐛',
            'msg'  => '미확인 버그 리포트 '.$bugCount.'건',
            'link' => '?page=admin_dashboard&tab=overview',
            'color'=> '#e74c3c',
          ];
        }
      } catch (PDOException $e) {}
    }

    $alertItems = array_slice($alertItems, 0, 5);
  endif;
  if (!empty($alertItems)): ?>
  <div style="margin-bottom:14px">
    <div style="font-size:13px;font-weight:700;color:#ffb400;margin-bottom:8px"><i class="bi bi-exclamation-triangle-fill"></i> 운영 알림</div>
    <?php foreach($alertItems as $ai): ?>
    <a href="<?=$ai['link']?>" style="text-decoration:none;display:block;margin-bottom:6px">
      <div style="background:rgba(255,180,0,0.08);border:1px solid rgba(255,180,0,0.25);border-radius:10px;padding:10px 14px;display:flex;align-items:center;gap:10px;transition:background 0.2s" onmouseover="this.style.background='rgba(255,180,0,0.15)'" onmouseout="this.style.background='rgba(255,180,0,0.08)'">
        <span style="font-size:18px;flex-shrink:0"><?=$ai['icon']?></span>
        <span style="font-size:12px;color:<?=$ai['color']?>;line-height:1.4;font-weight:500"><?=$ai['msg']?></span>
        <i class="bi bi-chevron-right" style="margin-left:auto;color:var(--text-sub);font-size:12px;flex-shrink:0"></i>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php /* ── 홈 알림 카드 ─── */
  if ($pendingMercReq > 0 || $pendingRecruitApp > 0): ?>
  <div style="background:rgba(255,184,0,0.08);border:1px solid rgba(255,184,0,0.3);border-radius:14px;padding:12px 16px;margin-bottom:12px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
    <span style="font-weight:700;color:var(--warning);font-size:14px">🔔 처리 필요</span>
    <?php if($pendingMercReq > 0): ?>
    <a href="?page=mercenaries" style="text-decoration:none">
      <span class="badge badge-yellow">⚡ 용병 신청 <?=$pendingMercReq?>건</span>
    </a>
    <?php endif; ?>
    <?php if($pendingRecruitApp > 0): ?>
    <a href="?page=recruits" style="text-decoration:none">
      <span class="badge badge-yellow">📬 팀 가입 신청 <?=$pendingRecruitApp?>건</span>
    </a>
    <?php endif; ?>
  </div>
  <?php elseif ($myMercPending > 0 || $myRecruitPending > 0): ?>
  <div style="background:rgba(56,189,248,0.08);border:1px solid rgba(56,189,248,0.2);border-radius:14px;padding:12px 16px;margin-bottom:12px">
    <span style="font-size:13px;color:var(--info)">⏳ 처리 대기 중:
      <?php if($myMercPending>0): ?>용병 신청 <?=$myMercPending?>건<?php endif; ?>
      <?php if($myRecruitPending>0): ?> 팀 가입 신청 <?=$myRecruitPending?>건<?php endif; ?>
    </span>
  </div>
  <?php endif; ?>

  <?php /* ── 랭킹 미니 섹션 ─── */
  if ($topTeams): ?>
  <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:14px;padding:12px 14px;margin-bottom:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <span style="font-size:13px;font-weight:700;color:var(--warning)">🏆 팀 랭킹 TOP 3</span>
      <a href="?page=ranking" style="font-size:12px;color:var(--text-sub);text-decoration:none">전체 보기 →</a>
    </div>
    <?php $medals=['🥇','🥈','🥉'];
    foreach($topTeams as $i=>$t): ?>
    <div style="display:flex;align-items:center;gap:10px;padding:5px 0;<?=$i<count($topTeams)-1?'border-bottom:1px solid rgba(255,255,255,0.06)':''?>">
      <span style="font-size:18px;width:24px;text-align:center;flex-shrink:0"><?=$medals[$i]?></span>
      <div style="flex:1;min-width:0">
        <div style="font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($t['name'])?></div>
        <div style="font-size:11px;color:var(--text-sub)"><?=h($t['region']??'')?></div>
      </div>
      <div style="text-align:right;flex-shrink:0">
        <div style="font-size:14px;font-weight:800;font-family:'Space Grotesk',sans-serif;color:var(--primary)"><?=(int)$t['pts']?>pt</div>
        <div style="font-size:10px;color:var(--text-sub)"><?=$t['win']?>승 <?=$t['draw']?>무 <?=$t['loss']?>패</div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php
    // [B5] 매치 리마인더 — 팀 소속 유저의 오늘/내일 경기 강조 카드
    if ($myTeamId && !empty($matches)):
      $today    = date('Y-m-d');
      $tomorrow = date('Y-m-d', strtotime('+1 day'));
      $urgent = array_values(array_filter($matches, fn($m) => in_array($m['match_date'], [$today, $tomorrow], true)));
      if ($urgent):
  ?>
  <!-- [B5] 긴급 리마인더 카드 (오늘 or 내일 경기) -->
  <?php foreach ($urgent as $um):
    $isToday = $um['match_date'] === $today;
    $myAtt = $um['my_att_status'] ?? 'PENDING';
  ?>
  <div class="card" style="margin-bottom:12px;border:2px solid <?=$isToday?'#ff4d6d':'#ff9500'?>;background:linear-gradient(135deg, <?=$isToday?'rgba(255,77,109,0.08)':'rgba(255,149,0,0.08)'?>, transparent);animation:pulse-glow 2s infinite">
    <div class="card-body">
      <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px">
        <span style="background:<?=$isToday?'#ff4d6d':'#ff9500'?>;color:#fff;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:800"><?= $isToday ? '⏰ 오늘 경기!' : '📅 내일 경기' ?></span>
        <?= uniformDot($um['uniform_color'] ?? '', 12) ?>
        <span style="font-weight:700;font-size:14px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($um['title']?:$um['home_name'])?></span>
      </div>
      <div style="font-size:12px;color:var(--text-sub);margin-bottom:10px">
        <?=substr($um['match_time'],0,5)?> · <?=h($um['location']??'-')?>
      </div>
      <!-- 내 출석 상태 / 빠른 참석 투표 -->
      <?php if ($myAtt === 'PENDING'): ?>
      <div style="font-size:11px;color:<?=$isToday?'#ff4d6d':'#ff9500'?>;font-weight:700;margin-bottom:8px">⚠️ 참석 여부를 아직 응답하지 않았어요</div>
      <div style="display:flex;gap:6px">
        <div class="vote-wrap" style="display:flex;gap:6px;flex:1">
          <button class="btn btn-primary btn-sm" style="flex:1;font-size:12px" onclick="ajaxVote(<?=(int)$um['id']?>,'ATTEND',this)">✓ 참석</button>
          <button class="btn btn-ghost btn-sm" style="flex:1;font-size:12px" onclick="ajaxVote(<?=(int)$um['id']?>,'ABSENT',this)">✕ 불참</button>
        </div>
        <a href="?page=match&id=<?=(int)$um['id']?>" class="btn btn-outline btn-sm" style="font-size:12px;padding:6px 12px">상세</a>
      </div>
      <?php elseif ($myAtt === 'ATTEND'): ?>
      <div style="display:flex;align-items:center;gap:10px">
        <span class="badge badge-green" style="font-size:11px">✓ 참석 응답함</span>
        <?php if ($isToday): ?><span style="font-size:11px;color:var(--text-sub)">경기장에서 체크인 잊지마세요!</span><?php endif; ?>
        <a href="?page=match&id=<?=(int)$um['id']?>" class="btn btn-outline btn-sm" style="font-size:11px;padding:4px 10px;margin-left:auto">상세</a>
      </div>
      <?php else: ?>
      <div style="display:flex;align-items:center;gap:10px">
        <span class="badge badge-red" style="font-size:11px">✕ 불참 응답함</span>
        <a href="?page=match&id=<?=(int)$um['id']?>" class="btn btn-outline btn-sm" style="font-size:11px;padding:4px 10px;margin-left:auto">상세</a>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <style>
  @keyframes pulse-glow {
    0%, 100% { box-shadow: 0 0 0 0 rgba(255,77,109,0.3) }
    50% { box-shadow: 0 0 0 8px rgba(255,77,109,0) }
  }
  </style>
  <?php endif; endif; ?>

  <?php if (!$myTeamId):
    // [3단계] 큐레이션 데이터 fetch
    $myRegion = me()['region'] ?? '';
    // 1) 우리 동네 용병 모집 중인 경기
    $sosMatches = [];
    try {
      $smq = $pdo->prepare("SELECT m.id, m.title, m.location, m.match_date, m.match_time, m.region, t.name AS team_name
        FROM matches m LEFT JOIN teams t ON t.id=m.home_team_id
        WHERE m.allow_mercenary=1 AND m.is_private=0 AND m.match_date >= CURDATE()
          AND m.status IN ('open','request_pending','confirmed','checkin_open')
          ".($myRegion ? "AND m.region=?" : "")."
        ORDER BY m.match_date ASC LIMIT 3");
      $smq->execute($myRegion ? [$myRegion] : []);
      $sosMatches = $smq->fetchAll();
    } catch(PDOException) {}
    // 2) 신규 팀원 모집 공고
    $recruitPosts = [];
    try {
      $rpq = $pdo->prepare("SELECT rp.id, rp.title, rp.created_at, t.name AS team_name, t.region
        FROM recruit_posts rp JOIN teams t ON t.id=rp.team_id
        WHERE rp.status='OPEN' ORDER BY rp.created_at DESC LIMIT 3");
      $rpq->execute();
      $recruitPosts = $rpq->fetchAll();
    } catch(PDOException) {}
  ?>
  <!-- [3단계] 신규 유저 큐레이션 -->
  <div class="card" style="margin-bottom:14px;border:1px solid rgba(0,255,136,0.3);background:linear-gradient(135deg, rgba(0,255,136,0.05), transparent)">
    <div class="card-body" style="text-align:center;padding:20px 16px">
      <div style="font-size:30px;margin-bottom:6px">⚽</div>
      <div style="font-weight:800;font-size:17px;margin-bottom:4px">TRUST FOOTBALL에 오신 걸 환영해요!</div>
      <div style="font-size:12px;color:var(--text-sub);margin-bottom:14px">팀에 합류하거나 용병으로 먼저 시작해보세요</div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px">
        <a href="?page=team_join" class="btn btn-primary" style="flex-direction:column;padding:12px 4px;font-size:11px;gap:4px">
          <i class="bi bi-key-fill" style="font-size:18px"></i>초대코드 가입
        </a>
        <a href="?page=mercenaries" class="btn btn-outline" style="flex-direction:column;padding:12px 4px;font-size:11px;gap:4px">
          <i class="bi bi-lightning-charge-fill" style="font-size:18px"></i>용병으로 뛰기
        </a>
        <a href="?page=matches" class="btn btn-ghost" style="flex-direction:column;padding:12px 4px;font-size:11px;gap:4px">
          <i class="bi bi-binoculars-fill" style="font-size:18px"></i>경기 둘러보기
        </a>
      </div>
    </div>
  </div>

  <?php
    // [B3] 프로필 완성도 체크 (팀 없는 유저 전용)
    $mProfile = $pdo->prepare("SELECT position, height, weight, preferred_foot, profile_image_url, stat_pace FROM users WHERE id=?");
    $mProfile->execute([(int)me()['id']]);
    $mpf = $mProfile->fetch() ?: [];
    $profileChecks = [
      ['포지션 설정',      !empty($mpf['position']),                 '?page=mypage'],
      ['프로필 사진',      !empty($mpf['profile_image_url']),        '?page=mypage'],
      ['키/몸무게',       !empty($mpf['height']) && !empty($mpf['weight']), '?page=mypage'],
      ['주발',           !empty($mpf['preferred_foot']),            '?page=mypage'],
      ['능력치',         !empty($mpf['stat_pace']) && (int)$mpf['stat_pace'] !== 50, '?page=mypage'],
    ];
    $profileDone = count(array_filter($profileChecks, fn($c)=>$c[1]));
    $profilePct  = (int)round($profileDone / count($profileChecks) * 100);
  ?>
  <?php if ($profilePct < 100): ?>
  <!-- [B3] 프로필 완성도 카드 -->
  <div class="card" style="margin-bottom:14px;border:1px solid rgba(255,214,10,0.3)"><div class="card-body">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <div style="font-weight:700;font-size:13px">📝 프로필 완성도 <?=$profileDone?>/<?=count($profileChecks)?></div>
      <div style="font-size:13px;font-weight:800;color:#ffd60a"><?=$profilePct?>%</div>
    </div>
    <!-- 진행바 -->
    <div style="height:6px;background:rgba(255,255,255,0.06);border-radius:3px;overflow:hidden;margin-bottom:12px">
      <div style="width:<?=$profilePct?>%;height:100%;background:linear-gradient(90deg,#ffd60a,#00ff88);transition:width 0.3s"></div>
    </div>
    <div style="display:flex;flex-direction:column;gap:4px">
      <?php foreach ($profileChecks as [$label, $done, $url]): ?>
      <a href="<?=$url?>" style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:6px;text-decoration:none;color:inherit;<?=$done?'opacity:0.6':'background:rgba(255,214,10,0.06)'?>">
        <span style="font-size:14px;width:18px"><?= $done ? '✓' : '○' ?></span>
        <span style="font-size:12px;<?=$done?'text-decoration:line-through;color:var(--text-sub)':'font-weight:600'?>"><?=$label?></span>
        <?php if (!$done): ?><span style="margin-left:auto;font-size:10px;color:#ffd60a">설정하기 →</span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
    <div style="font-size:10px;color:var(--text-sub);margin-top:6px;text-align:center">💡 프로필이 자세할수록 팀 제안 받을 확률이 높아요</div>
  </div></div>
  <?php endif; ?>

  <?php
    // [B3] 내 지역 활성 팀 TOP 3 (가입 신청 유도)
    $localTeams = [];
    if ($myRegion) {
      try {
        $ltq = $pdo->prepare("
          SELECT t.id, t.name, t.region, t.district, t.win, t.draw, t.loss, t.invite_code,
                 (SELECT COUNT(*) FROM team_members WHERE team_id=t.id AND status='active' AND role != 'mercenary') AS mem_cnt
          FROM teams t
          WHERE t.status='ACTIVE' AND t.region=?
          ORDER BY (t.win*3+t.draw) DESC, t.id DESC LIMIT 3
        ");
        $ltq->execute([$myRegion]);
        $localTeams = $ltq->fetchAll();
      } catch(PDOException) {}
    }
  ?>
  <?php if ($localTeams): ?>
  <!-- [B3] 내 지역 팀 TOP 3 -->
  <p class="section-title">🛡 <?=h($myRegion)?> 활동 팀 TOP 3</p>
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:0 14px">
    <?php foreach ($localTeams as $lt):
      $tsLocal = calcTeamStrength($lt, 0, (int)$lt['mem_cnt']);
    ?>
    <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.05)">
      <div style="width:36px;height:36px;border-radius:8px;background:<?=$tsLocal['bg_hex']?>;display:flex;align-items:center;justify-content:center;color:<?=$tsLocal['color_hex']?>;font-size:16px;flex-shrink:0"><?=$tsLocal['icon']?></div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:700;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($lt['name'])?></div>
        <div style="font-size:11px;color:var(--text-sub)">
          <?=h($lt['district']??'')?> · 팀원 <?=(int)$lt['mem_cnt']?>명
          <?php if ($tsLocal['is_rated']): ?> · <?=h($tsLocal['label'])?><?php endif; ?>
          <?php if (($lt['win']??0)+($lt['draw']??0)+($lt['loss']??0) > 0): ?>
          · <?=$lt['win']?>승<?=$lt['draw']?>무<?=$lt['loss']?>패
          <?php endif; ?>
        </div>
      </div>
      <a href="?page=team_join&code=<?=h($lt['invite_code'])?>" class="btn btn-outline btn-sm" style="font-size:11px;padding:4px 10px;flex-shrink:0">가입 신청</a>
    </div>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>

  <?php if($sosMatches): ?>
  <!-- 카드1: 우리 동네 용병 구하는 경기 -->
  <p class="section-title">🔥 <?=$myRegion ? h($myRegion).' ' : ''?>용병 구하는 경기</p>
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:0 14px">
    <?php foreach($sosMatches as $sm): ?>
    <a href="?page=match&id=<?=$sm['id']?>" style="text-decoration:none;color:inherit">
      <div style="display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--border)">
        <div style="width:38px;height:38px;border-radius:8px;background:rgba(0,255,136,0.1);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:18px;flex-shrink:0">⚽</div>
        <div style="flex:1;min-width:0">
          <div style="font-weight:600;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($sm['title'] ?: $sm['location'])?></div>
          <div style="font-size:11px;color:var(--text-sub)"><?=h($sm['team_name'] ?? '')?> · <?=$sm['match_date']?> <?=dayOfWeek($sm['match_date'])?> <?=substr($sm['match_time'],0,5)?> · <?=h($sm['region'] ?? '')?></div>
        </div>
        <i class="bi bi-chevron-right" style="color:var(--text-sub);font-size:12px"></i>
      </div>
    </a>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>

  <?php if($recruitPosts): ?>
  <!-- 카드2: 신규 팀원 모집 -->
  <p class="section-title">📢 신규 팀원 모집</p>
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:0 14px">
    <?php foreach($recruitPosts as $rp): ?>
    <a href="?page=recruits" style="text-decoration:none;color:inherit">
      <div style="display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--border)">
        <div style="width:38px;height:38px;border-radius:8px;background:rgba(58,158,245,0.15);display:flex;align-items:center;justify-content:center;color:#3a9ef5;font-size:18px;flex-shrink:0">🛡️</div>
        <div style="flex:1;min-width:0">
          <div style="font-weight:600;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($rp['title'])?></div>
          <div style="font-size:11px;color:var(--text-sub)"><?=h($rp['team_name'])?> · <?=h($rp['region'] ?? '')?> · <?=date('m/d', strtotime($rp['created_at']))?></div>
        </div>
        <i class="bi bi-chevron-right" style="color:var(--text-sub);font-size:12px"></i>
      </div>
    </a>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>

  <!-- 카드3: 용병 등록 CTA -->
  <a href="?page=mercenaries" style="text-decoration:none;color:inherit">
    <div class="card" style="margin-bottom:16px;background:linear-gradient(135deg, rgba(255,149,0,0.1), rgba(255,107,107,0.05));border:1px solid rgba(255,149,0,0.3)">
      <div class="card-body" style="display:flex;align-items:center;gap:14px;padding:16px">
        <div style="font-size:36px">🚀</div>
        <div style="flex:1">
          <div style="font-weight:800;font-size:15px">지금 바로 용병 등록하기</div>
          <div style="font-size:12px;color:var(--text-sub);margin-top:2px">가까운 경기에서 빠르게 데뷔하세요</div>
        </div>
        <i class="bi bi-arrow-right-circle-fill" style="color:#ff9500;font-size:24px"></i>
      </div>
    </div>
  </a>
  <?php endif; ?>

  <!-- 기간 탭: 이번주 / 이번달 -->
  <div class="chip-row">
    <?php foreach($tabLabels as $k=>$v): ?>
    <a href="?page=home&period=<?=$k?>" class="chip <?=$period===$k?'active':''?>"><?=$v?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$matches): ?>
    <div style="text-align:center;padding:40px 0">
      <div style="font-size:40px;margin-bottom:12px">📅</div>
      <p style="color:var(--text-sub);font-size:15px;line-height:1.6"><?=$myTeamId ? '아직 '.$tabLabels[$period].' 예정된 경기가 없어요!<br>첫 경기를 만들어보세요' : '이 기간에 공개 경기가 없습니다.<br>다른 기간을 확인해보세요'?></p>
      <?php if($myTeamId && isCaptain()): ?>
      <a href="?page=matches#create" class="btn btn-primary" style="margin-top:14px;font-size:14px"><i class="bi bi-plus-lg"></i> 매치 개설하기</a>
      <?php elseif(!$myTeamId): ?>
      <div style="display:flex;gap:8px;justify-content:center;margin-top:14px">
        <a href="?page=team_create" class="btn btn-primary" style="font-size:13px"><i class="bi bi-people-fill"></i> 팀 만들기</a>
        <a href="?page=mercenaries" class="btn btn-outline" style="font-size:13px;border-color:var(--primary);color:var(--primary)"><i class="bi bi-megaphone"></i> 용병 참여</a>
      </div>
      <?php endif; ?>
    </div>
  <?php else: foreach($matches as $m):
    $dday     = ddayBadge($m['match_date']);
    $attList  = $attendanceByMatch[$m['id']] ?? [];
    $attNames = array_filter($attList, fn($a) => $a['status'] === 'ATTEND');
    $abNames  = array_filter($attList, fn($a) => $a['status'] === 'ABSENT');
    $myStatus = $m['my_att_status'] ?? 'PENDING';
    // [홈 카드 리치] 매치 타입/유니폼/참가비/경기방식
    $mt = $m['match_type'] ?? 'VENUE';
    $mtBadge = match($mt){
      'MERC_ONLY'    => '<span class="badge" style="background:rgba(255,180,0,0.15);color:#ffb400;font-size:9px">⚡ 용병만 구함</span>',
      'REQUEST'      => '<span class="badge" style="background:rgba(255,77,109,0.15);color:#ff4d6d;font-size:9px">🆘 모두 구함</span>',
      'VENUE_WANTED' => '<span class="badge" style="background:rgba(58,158,245,0.15);color:#3a9ef5;font-size:9px">🔍 구장 구함</span>',
      default        => '<span class="badge" style="background:rgba(0,255,136,0.12);color:var(--primary);font-size:9px">🏟️ 상대 구함</span>',
    };
    $feeStr = ($m['fee_type']??'') !== '' && ($m['fee_type']??'') !== '없음' && ($m['fee_type']??'') !== '무료'
      ? '💰 '.h($m['fee_type']).($m['fee_amount']>0 ? ' '.number_format($m['fee_amount']).'원':'')
      : '💰 무료';
    // 상대팀 참석 현황 (이미 쿼리에서 가져옴)
    $oppAtt = (int)($m['attend_count'] ?? 0);
    $oppAbs = (int)($m['absent_count'] ?? 0);
  ?>
  <div class="card" style="margin-bottom:12px">
    <div class="card-body" style="padding:14px 16px">
      <!-- [1행] 유니폼 + 팀 VS 팀 + D-day + 상태 -->
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px;gap:6px">
        <div style="display:flex;align-items:center;gap:6px;flex:1;min-width:0">
          <?= uniformDot($m['uniform_color'] ?? '', 14) ?>
          <a href="?page=match&id=<?=$m['id']?>" style="font-weight:700;font-size:15px;color:var(--text);text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?php $awayD = $m['away_name'] ?: ($m['away_team_name'] ?? ''); ?>
            <?=h($m['home_name'])?> <span style="color:var(--text-sub);font-weight:400;font-size:13px">vs</span> <?=$awayD?h($awayD):'<span style="color:var(--text-sub);font-size:12px">모집중</span>'?>
          </a>
        </div>
        <div style="display:flex;gap:4px;align-items:center;flex-shrink:0">
          <?=$dday?> <?=statusBadge($m['status'], $m['match_type'] ?? '')?>
        </div>
      </div>

      <!-- [2행] 배지 (타입 + 방식 + 스타일 + 용병 + 참가비) -->
      <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:8px">
        <?=$mtBadge?>
        <?php if(!empty($m['format_type'])): ?><span class="badge badge-blue" style="font-size:9px"><?=h($m['format_type'])?></span><?php endif; ?>
        <?php if(!empty($m['match_style'])): ?><span class="badge badge-green" style="font-size:9px"><?=h($m['match_style'])?></span><?php endif; ?>
        <?php if(!empty($m['allow_mercenary'])): ?><span class="badge" style="background:rgba(255,180,0,0.15);color:#ffb400;font-size:9px">⚡ 용병</span><?php endif; ?>
        <span class="badge" style="background:rgba(255,255,255,0.04);color:var(--text-sub);font-size:9px"><?=$feeStr?></span>
      </div>

      <!-- [3행] 일시/장소 -->
      <div style="font-size:12px;color:var(--text-sub);margin-bottom:10px;display:flex;gap:12px;flex-wrap:wrap">
        <?php
          $hmWeather = '';
          $hmDays = (int)floor((strtotime($m['match_date']) - strtotime(date('Y-m-d'))) / 86400);
          if ($weatherData && $hmDays >= 0 && $hmDays <= 2):
            $hmHourly = $weatherData['weather'][$hmDays]['hourly'] ?? [];
            $hmHour = (int)substr($m['match_time'] ?? '18:00', 0, 2);
            foreach ($hmHourly as $hmH) {
              $hmHH = (int)floor((int)$hmH['time'] / 100);
              if ($hmHH === $hmHour || ($hmHH <= $hmHour && $hmHH + 3 > $hmHour)) {
                $hmWeather = weatherIcon($hmH['weatherCode']) . $hmH['tempC'] . '°';
                break;
              }
            }
          elseif ($hmDays > 2):
            $hmWeather = '📅 D-' . $hmDays . ' 날씨 준비중';
          endif;
        ?>
        <span><i class="bi bi-clock" style="margin-right:3px"></i><?=$m['match_date']?> <?=dayOfWeek($m['match_date'])?> <?=matchTimeStr($m)?><?=$hmWeather?' <b>'.$hmWeather.'</b>':''?></span>
        <span><i class="bi bi-geo-alt" style="margin-right:3px"></i><?=h($m['location']??'-')?></span>
        <span><i class="bi bi-people" style="margin-right:3px"></i><?=(int)($m['attend_count'] ?? $m['current_players'] ?? 0)?>/<?=$m['max_players']?>명</span>
      </div>

      <?php if($myTeamId && $attList): ?>
      <!-- 참석 현황 요약 -->
      <div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">
        <?php if($attNames): ?>
        <div style="background:rgba(0,255,136,0.08);border:1px solid rgba(0,255,136,0.2);border-radius:8px;padding:6px 10px;flex:1;min-width:0">
          <div style="font-size:10px;color:var(--primary);font-weight:700;margin-bottom:4px">✓ 참석 <?=count($attNames)?></div>
          <div style="font-size:12px;color:var(--text-main);line-height:1.5"><?=implode(' · ', array_map(fn($a)=>h(displayName($a)), $attNames))?></div>
        </div>
        <?php endif; ?>
        <?php if($abNames): ?>
        <div style="background:rgba(255,59,48,0.06);border:1px solid rgba(255,59,48,0.15);border-radius:8px;padding:6px 10px;flex:1;min-width:0">
          <div style="font-size:10px;color:#ff6b6b;font-weight:700;margin-bottom:4px">✗ 불참 <?=count($abNames)?></div>
          <div style="font-size:12px;color:var(--text-sub);line-height:1.5"><?=implode(' · ', array_map(fn($a)=>h(displayName($a)), $abNames))?></div>
        </div>
        <?php endif; ?>
        <?php $pendCnt = (int)$m['pending_count']; if($pendCnt>0): ?>
        <div style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:6px 10px">
          <div style="font-size:10px;color:var(--text-sub);font-weight:700;margin-bottom:2px">? 미정</div>
          <div style="font-size:13px;font-weight:700;color:var(--text-sub)"><?=$pendCnt?></div>
        </div>
        <?php endif; ?>
      </div>
      <?php elseif($myTeamId): ?>
      <div style="font-size:12px;color:var(--text-sub);margin-bottom:10px">아직 투표한 팀원이 없습니다.</div>
      <?php endif; ?>

      <?php if($myTeamId): ?>
      <!-- 내 투표 버튼 — 선택된 상태 강조 -->
      <div style="display:flex;gap:6px">
        <?php foreach(['ATTEND'=>['✓ 참석','var(--primary)','rgba(0,255,136,0.12)'],'PENDING'=>['? 미정','var(--text-sub)','rgba(255,255,255,0.06)'],'ABSENT'=>['✗ 불참','#ff4d4d','rgba(255,59,48,0.1)']] as $sv=>[$sl,$sc,$sbg]): ?>
        <form method="POST" style="flex:1">
          <?=csrfInput()?>
          <input type="hidden" name="action" value="vote_attendance">
          <input type="hidden" name="match_id" value="<?=$m['id']?>">
          <input type="hidden" name="vote" value="<?=$sv?>">
          <button type="submit" class="btn btn-w" style="font-size:13px;padding:8px 0;
            background:<?=$myStatus===$sv?$sbg:'transparent'?>;
            color:<?=$myStatus===$sv?$sc:'var(--text-sub)'?>;
            border:1.5px solid <?=$myStatus===$sv?$sc:'rgba(255,255,255,0.1)'?>;
            font-weight:<?=$myStatus===$sv?'700':'400'?>">
            <?=$sl?>
          </button>
        </form>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- 상세보기 링크 -->
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;padding-top:8px;border-top:1px solid rgba(255,255,255,0.06)">
        <?php if ($myTeamId && $m['away_name']): ?>
        <div style="font-size:11px;color:var(--text-sub)">상대: <span style="font-weight:600"><?=h($m['away_name'])?></span></div>
        <?php elseif ($myTeamId && $m['home_name']): ?>
        <div style="font-size:11px;color:var(--text-sub)">홈팀: <span style="font-weight:600"><?=h($m['home_name'])?></span></div>
        <?php else: ?>
        <div></div>
        <?php endif; ?>
        <a href="?page=match&id=<?=$m['id']?>" style="font-size:11px;color:var(--primary);text-decoration:none;font-weight:600;flex-shrink:0">상세보기 →</a>
      </div>
    </div>
  </div>
  <?php endforeach;endif; ?>

  <?php if(isCaptain()): ?>
  <hr class="divider">
  <p class="section-title">캡틴 메뉴</p>
  <div class="form-row">
    <a href="?page=matches#create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> 매치 개설</a>
    <a href="?page=fees" class="btn btn-outline"><i class="bi bi-people"></i> 회원명단</a>
    <a href="?page=dues" class="btn btn-outline"><i class="bi bi-cash-coin"></i> 회비 관리</a>
  </div>
  <?php endif; ?>

  <?php if(isAdmin()): ?>
  <hr class="divider">
  <p class="section-title">관리자 메뉴</p>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
    <a href="?page=admin_reports" class="btn btn-danger"><i class="bi bi-flag-fill"></i> 신고 처리</a>
    <a href="?page=admin_deposit" class="btn btn-warning"><i class="bi bi-cash-coin"></i> 보증금</a>
    <a href="?page=leagues" class="btn btn-outline" style="grid-column:1/-1"><i class="bi bi-diagram-3"></i> 리그 관리</a>
  </div>
  <?php endif; ?>
</div>
<?php }

// ── 상대 시간 표시 (예: 3분 전, 2시간 전, 1일 전) ──
function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return '방금 전';
    if ($diff < 3600)   return (int)($diff/60).'분 전';
    if ($diff < 86400)  return (int)($diff/3600).'시간 전';
    if ($diff < 604800) return (int)($diff/86400).'일 전';
    return date('m/d', strtotime($datetime));
}

// ── 요일 표시 ──
function dayOfWeek(string $matchDate): string {
    $days = ['일','월','화','수','목','금','토'];
    return '('.$days[(int)date('w', strtotime($matchDate))].')';
}

// ── D-Day 배지 ──
function ddayBadge(string $matchDate): string {
    $diff = (int)floor((strtotime($matchDate) - strtotime(date('Y-m-d'))) / 86400);
    if ($diff < 0)  return ''; // 이미 지난 경기
    if ($diff === 0) return '<span style="background:#ff3b30;color:#fff;border-radius:6px;padding:2px 8px;font-size:10px;font-weight:800;letter-spacing:0.5px;vertical-align:middle">D-DAY</span>';
    if ($diff <= 3)  return '<span style="background:#ff9500;color:#fff;border-radius:6px;padding:2px 8px;font-size:10px;font-weight:700;vertical-align:middle">D-'.$diff.'</span>';
    if ($diff <= 7)  return '<span style="background:#ffd60a;color:#0F1117;border-radius:6px;padding:2px 8px;font-size:10px;font-weight:700;vertical-align:middle">D-'.$diff.'</span>';
    return '<span style="background:rgba(255,255,255,0.08);color:var(--text-sub);border-radius:6px;padding:2px 8px;font-size:10px;vertical-align:middle">D-'.$diff.'</span>';
}

// ── 매치 상태 한국어 라벨 ──
function matchStatusLabel($status) {
    return match($status) {
        'open' => '모집중',
        'request_pending' => '상대 확인중',
        'confirmed' => '경기 확정',
        'checkin_open' => '체크인 중',
        'in_progress' => '경기 진행중',
        'result_pending' => '결과 확인중',
        'completed' => '경기 완료',
        'force_closed' => '경기 종료',
        'disputed' => '분쟁중',
        'cancelled' => '취소',
        default => $status,
    };
}

// ── 상태 배지 ──
// $matchType 넘기면 MERC_ONLY+open → "용병 모집중"으로 표시
function statusBadge(string $s, string $matchType = ''): string {
    $colors=['open'=>'green','request_pending'=>'yellow','confirmed'=>'blue',
        'checkin_open'=>'blue','in_progress'=>'gray','result_pending'=>'yellow',
        'completed'=>'gray','disputed'=>'red','cancelled'=>'gray','force_closed'=>'red'];
    $c=$colors[$s]??'gray';
    $l=matchStatusLabel($s);
    if ($matchType === 'MERC_ONLY' && $s === 'open') { $c = 'yellow'; $l = '⚡ 용병 모집중'; }
    return "<span class=\"badge badge-{$c}\">{$l}</span>";
}

// ── 포지션별 스탯 라벨 ──
function statLabels(string $pos): array {
    return match($pos) {
        'GK' => ['반사신경','다이빙','패스','핸들링','위치선정','피지컬'],
        'DF' => ['속도','태클','패스','헤딩','수비','피지컬'],
        'MF' => ['속도','슈팅','패스','드리블','수비','피지컬'],
        default => ['속도','슈팅','패스','드리블','수비','피지컬'], // FW
    };
}

// ── 스탯 레이더 SVG ──
function statRadar(array $stats, string $pos, int $size = 180): string {
    $labels = statLabels($pos);
    $vals   = [$stats[0], $stats[1], $stats[2], $stats[3], $stats[4], $stats[5]];
    $n      = 6;
    $cx     = $size / 2;
    $cy     = $size / 2;
    $r      = $size * 0.36;
    $labelR = $size * 0.47;

    $pts = function(float $scale) use ($n, $cx, $cy, $r): string {
        $p = [];
        for ($i = 0; $i < $n; $i++) {
            $a = deg2rad(-90 + $i * 60);
            $p[] = round($cx + $r * $scale * cos($a), 2) . ',' . round($cy + $r * $scale * sin($a), 2);
        }
        return implode(' ', $p);
    };

    $svg = "<svg viewBox=\"0 0 {$size} {$size}\" xmlns=\"http://www.w3.org/2000/svg\" style=\"width:100%;max-width:{$size}px\">";

    // 배경 그리드 (25 50 75 100%)
    foreach ([0.25, 0.5, 0.75, 1.0] as $scale) {
        $opacity = $scale === 1.0 ? '0.2' : '0.1';
        $svg .= "<polygon points=\"{$pts($scale)}\" fill=\"none\" stroke=\"rgba(255,255,255,{$opacity})\" stroke-width=\"1\"/>";
    }
    // 축
    for ($i = 0; $i < $n; $i++) {
        $a = deg2rad(-90 + $i * 60);
        $x = round($cx + $r * cos($a), 2);
        $y = round($cy + $r * sin($a), 2);
        $svg .= "<line x1=\"{$cx}\" y1=\"{$cy}\" x2=\"{$x}\" y2=\"{$y}\" stroke=\"rgba(255,255,255,0.1)\" stroke-width=\"1\"/>";
    }

    // 스탯 폴리곤
    $statPts = [];
    for ($i = 0; $i < $n; $i++) {
        $a = deg2rad(-90 + $i * 60);
        $v = min(99, max(1, (int)($vals[$i]))) / 99;
        $statPts[] = round($cx + $r * $v * cos($a), 2) . ',' . round($cy + $r * $v * sin($a), 2);
    }
    $sp = implode(' ', $statPts);
    $svg .= "<polygon points=\"{$sp}\" fill=\"rgba(0,255,136,0.18)\" stroke=\"rgba(0,255,136,0.85)\" stroke-width=\"1.5\" stroke-linejoin=\"round\"/>";

    // 꼭짓점 도트
    foreach ($statPts as $pt) { [$px, $py] = explode(',', $pt); $svg .= "<circle cx=\"{$px}\" cy=\"{$py}\" r=\"2.5\" fill=\"#00ff88\"/>"; }

    // 라벨 + 수치
    for ($i = 0; $i < $n; $i++) {
        $a  = deg2rad(-90 + $i * 60);
        $lx = round($cx + $labelR * cos($a), 2);
        $ly = round($cy + $labelR * sin($a), 2);
        $cosA = cos($a);
        $ta = (abs($a) < 0.1 || abs(abs($a) - M_PI) < 0.1) ? 'middle' : ($cosA > 0.1 ? 'start' : ($cosA < -0.1 ? 'end' : 'middle'));
        $v  = (int)$vals[$i];
        $svg .= "<text x=\"{$lx}\" y=\"" . round($ly - 3, 2) . "\" text-anchor=\"{$ta}\" fill=\"rgba(255,255,255,0.55)\" font-size=\"8\" font-family=\"sans-serif\">{$labels[$i]}</text>";
        $svg .= "<text x=\"{$lx}\" y=\"" . round($ly + 8, 2) . "\" text-anchor=\"{$ta}\" fill=\"rgba(0,255,136,0.9)\" font-size=\"9\" font-weight=\"700\" font-family=\"'Space Grotesk',sans-serif\">{$v}</text>";
    }

    $svg .= '</svg>';
    return $svg;
}

// ═══════════════════════════════════════════════════════════════
// 매치 목록
// ═══════════════════════════════════════════════════════════════
function pagMatches(PDO $pdo): void {
    $weatherData = getWeatherData();
    $level   = $_GET['level'] ?? '';
    $tab     = $_GET['tab']   ?? 'find'; // find | myteam
    $sport   = $_GET['sport'] ?? '';
    $myTid   = (int)myTeamId();

    if ($tab === 'myteam' && $myTid) {
        $where = "WHERE (m.home_team_id=? OR m.away_team_id=?) AND m.status != 'cancelled'";
        $p = [$myTid, $myTid];
        $orderBy = 'm.match_date DESC, m.match_time DESC';
    } else {
        $tab = 'find';
        $where = "WHERE m.is_private=0 AND m.status IN ('open','request_pending')";
        $p = [];
        $orderBy = 'm.match_date, m.match_time';
    }
    if ($level) { $where .= " AND m.level=?"; $p[] = $level; }
    if ($sport === 'merc') { $where .= " AND m.allow_mercenary=1"; }
    elseif ($sport) { $where .= " AND m.sport_type=?"; $p[] = $sport; }
    // [팀 실력] 홈팀의 win/draw/loss + 팀원수/선출수도 함께 조회 (calcTeamStrength 계산용)
    $matches = $pdo->prepare("SELECT m.*, COALESCE(ht.name,'(팀 미지정)') AS home_name, at.name AS away_name,
        ht.win AS ht_win, ht.draw AS ht_draw, ht.loss AS ht_loss,
        (SELECT COUNT(*) FROM team_members WHERE team_id=m.home_team_id AND status='active' AND role != 'mercenary') AS ht_member_cnt,
        (SELECT COUNT(*) FROM team_members WHERE team_id=m.home_team_id AND status='active' AND is_pro=1) AS ht_pro_cnt,
        (SELECT COUNT(*) FROM mercenary_requests mr WHERE mr.match_id=m.id AND mr.status='accepted') AS merc_count,
        (SELECT COUNT(*) FROM match_attendance ma JOIN team_members tm ON tm.user_id=ma.user_id AND tm.team_id=ma.team_id WHERE ma.match_id=m.id AND tm.is_pro=1 AND ma.status='ATTEND') AS pro_count,
        (SELECT COUNT(*) FROM match_attendance WHERE match_id=m.id AND status='ATTEND') AS attend_count
        FROM matches m LEFT JOIN teams ht ON ht.id=m.home_team_id LEFT JOIN teams at ON at.id=m.away_team_id $where ORDER BY $orderBy");
    $matches->execute($p);$matches=$matches->fetchAll();
    $levels=['모든실력','입문','초급','중급','고급']; ?>
<div class="container">
  <!-- 메인 탭: 경기 찾기 / 우리팀 경기 -->
  <div style="display:flex;gap:0;margin-bottom:10px;border-radius:10px;overflow:hidden;border:1px solid var(--border)">
    <a href="?page=matches&tab=find<?=$sport?"&sport=".urlencode($sport):''?>" style="flex:1;text-align:center;padding:10px;font-size:14px;font-weight:700;text-decoration:none;
      background:<?=$tab==='find'?'var(--primary)':'var(--bg-surface-alt)'?>;color:<?=$tab==='find'?'#0F1117':'var(--text-sub)'?>">🔍 경기 찾기</a>
    <a href="?page=matches&tab=myteam<?=$sport?"&sport=".urlencode($sport):''?>" style="flex:1;text-align:center;padding:10px;font-size:14px;font-weight:700;text-decoration:none;
      background:<?=$tab==='myteam'?'var(--primary)':'var(--bg-surface-alt)'?>;color:<?=$tab==='myteam'?'#0F1117':'var(--text-sub)'?>">🛡️ 우리팀 경기</a>
  </div>
  <!-- 종목 필터 -->
  <div class="chip-row" style="margin-bottom:8px">
    <a href="?page=matches&tab=<?=$tab?>" class="chip <?=!$sport?'active':''?>">전체</a>
    <a href="?page=matches&tab=<?=$tab?>&sport=축구" class="chip <?=$sport==='축구'?'active':''?>">⚽ 축구</a>
    <a href="?page=matches&tab=<?=$tab?>&sport=풋살" class="chip <?=$sport==='풋살'?'active':''?>">🏃 풋살</a>
    <a href="?page=matches&tab=<?=$tab?>&sport=merc" class="chip <?=$sport==='merc'?'active':''?>">📢 용병경기</a>
  </div>

  <?php if(!$matches):
    $emptyMsg = $tab === 'myteam' ? '우리팀 경기가 없습니다.' : '모집 중인 경기가 없습니다.'; ?>
    <div style="text-align:center;padding:60px 20px">
      <div style="font-size:48px;margin-bottom:12px">⚽</div>
      <p style="color:var(--text-sub);font-size:15px;margin-bottom:8px;line-height:1.6">
        <?php if($tab==='myteam'): ?>
          아직 등록된 경기가 없어요!<br><span style="font-size:13px">첫 경기를 만들어보세요</span>
        <?php else: ?>
          모집 중인 경기가 없습니다.<br><span style="font-size:13px">나중에 다시 확인해보세요</span>
        <?php endif; ?>
      </p>
      <?php if(me() && myTeamId() && isCaptain()): ?>
        <a href="?page=create_match" class="btn btn-primary" style="font-size:14px;margin-top:8px"><i class="bi bi-plus-circle"></i> 새 경기 만들기</a>
      <?php elseif(me() && !myTeamId()): ?>
        <div style="display:flex;gap:8px;justify-content:center;margin-top:12px">
          <a href="?page=team_create" class="btn btn-primary" style="font-size:13px"><i class="bi bi-people-fill"></i> 팀 만들기</a>
          <a href="?page=mercenaries" class="btn btn-outline" style="font-size:13px;border-color:var(--primary);color:var(--primary)">용병 등록</a>
        </div>
      <?php endif; ?>
    </div>
  <?php else: foreach($matches as $m):
    $dday = ddayBadge($m['match_date']); ?>
  <a href="?page=match&id=<?=$m['id']?>" class="card card-link" style="margin-bottom:10px">
    <div class="card-body">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;gap:8px">
        <span style="display:flex;align-items:center;gap:6px;flex:1;min-width:0">
          <?= uniformDot($m['uniform_color'] ?? '', 14) ?>
          <span style="font-weight:700;font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($m['title']?:$m['home_name'])?></span>
        </span>
        <div style="display:flex;gap:4px;align-items:center;flex-shrink:0"><?=$dday?><?=statusBadge($m['status'], $m['match_type'] ?? '')?></div>
      </div>
      <?php
        $matchWeather = '';
        $daysUntil = (int)floor((strtotime($m['match_date']) - strtotime(date('Y-m-d'))) / 86400);
        if ($weatherData && $daysUntil >= 0 && $daysUntil <= 2):
          $dayData = $weatherData['weather'][$daysUntil]['hourly'] ?? [];
          $matchHour = (int)substr($m['match_time'] ?? '18:00', 0, 2);
          foreach ($dayData as $hd) {
            $hdHour = (int)floor((int)$hd['time'] / 100);
            if ($hdHour === $matchHour || ($hdHour <= $matchHour && $hdHour + 3 > $matchHour)) {
              $matchWeather = weatherIcon($hd['weatherCode']) . ' ' . $hd['tempC'] . '°';
              break;
            }
          }
        elseif ($daysUntil > 2):
          $matchWeather = '📅 D-' . $daysUntil;
        endif;
      ?>
      <div style="font-size:13px;color:var(--text-sub);line-height:1.8">
        <i class="bi bi-calendar3"></i> <?=$m['match_date']?> <?=dayOfWeek($m['match_date'])?> <?=matchTimeStr($m)?>
        <?php if($matchWeather): ?><span style="margin-left:4px;font-weight:600"><?=$matchWeather?></span><?php endif; ?>
        <?php
          if ($matchWeather && $daysUntil >= 0 && $daysUntil <= 2):
            foreach ($dayData as $hd2) {
              $hd2Hour = (int)floor((int)$hd2['time'] / 100);
              if ($hd2Hour === $matchHour || ($hd2Hour <= $matchHour && $hd2Hour + 3 > $matchHour)) {
                $rainChance = (int)($hd2['chanceofrain'] ?? 0);
                $rainMM = (float)($hd2['precipMM'] ?? 0);
                if ($rainChance >= 60 || $rainMM >= 3):
                  $rainMsgs = ['☔ 수중전 각오하세요!','🌧️ 미끄러움 주의! 풋살화 필수','💦 우비 챙기세요~','🌊 잔디가 미끄러울 수 있어요','⚡ 비 와도 경기는 해야죠!'];
                  echo '<br><span style="font-size:11px;color:#3a9ef5;font-weight:600">'.$rainMsgs[array_rand($rainMsgs)].'</span>';
                elseif ($rainChance >= 30):
                  echo '<br><span style="font-size:11px;color:var(--text-sub)">🌂 비 올 수도 있어요 ('.$rainChance.'%)</span>';
                endif;
                break;
              }
            }
          endif;
        ?><br>
        <i class="bi bi-geo-alt"></i> <?=h($m['location']??'-')?> &nbsp;·&nbsp;
        <i class="bi bi-people"></i> <?=(int)($m['attend_count'] ?? $m['current_players'] ?? 0)?>/<?=$m['max_players']?>명 &nbsp;·&nbsp; <?=h($m['level']??'')?>
      </div>
      <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:4px">
        <?php
          // [매치 MVP] match_type 3-way 배지
          $mt = $m['match_type'] ?? 'VENUE';
          if ($mt === 'MERC_ONLY'):
        ?><span class="badge" style="background:rgba(255,180,0,0.15);color:#ffb400">⚡ 용병만 구함</span>
        <?php elseif ($mt === 'REQUEST'): ?><span class="badge" style="background:rgba(255,77,109,0.15);color:#ff4d6d">🆘 모두 구함</span>
        <?php elseif ($mt === 'VENUE_WANTED'): ?><span class="badge" style="background:rgba(58,158,245,0.15);color:#3a9ef5">🔍 경기장 구함</span>
        <?php else: ?><span class="badge" style="background:rgba(0,255,136,0.12);color:var(--primary)">🏟️ 상대팀 구함</span><?php endif; ?>
        <?php
          // [팀 실력] 홈팀 강함 배지 (home_team 있을 때만, 경기 3회 이상이면 레벨+라벨)
          if (!empty($m['home_team_id'])):
            $htRow = ['win'=>(int)($m['ht_win']??0),'draw'=>(int)($m['ht_draw']??0),'loss'=>(int)($m['ht_loss']??0)];
            $htStr = calcTeamStrength($htRow, (int)($m['ht_pro_cnt']??0), (int)($m['ht_member_cnt']??0));
            if ($htStr['is_rated']):
        ?><span class="badge" style="background:<?=$htStr['bg_hex']?>;color:<?=$htStr['color_hex']?>"><?=$htStr['icon']?> Lv.<?=$htStr['level']?> <?=h($htStr['label'])?></span>
        <?php endif; endif; ?>
        <?php if(!empty($m['format_type'])): ?><span class="badge badge-blue"><?=h($m['format_type'])?></span><?php endif; ?>
        <?php if(!empty($m['match_style'])): ?><span class="badge badge-green"><?=h($m['match_style'])?></span><?php endif; ?>
        <?php if(!empty($m['allow_mercenary'])): ?><span class="badge" style="background:rgba(255,180,0,0.15);color:#ffb400"><i class="bi bi-lightning-charge-fill"></i> 용병모집</span><?php endif; ?>
        <?php if((int)($m['merc_count']??0)>0): ?><span class="badge" style="background:rgba(255,180,0,0.15);color:#ffb400">⚡ 용병 <?=(int)$m['merc_count']?>명</span><?php endif; ?>
        <?php if((int)($m['pro_count']??0)>0): ?><span class="badge" style="background:rgba(255,107,107,0.15);color:#ff6b6b">⭐ 선출 <?=(int)$m['pro_count']?>명</span><?php endif; ?>
        <?php if(!empty($m['sport_type']) && $m['sport_type']==='축구'): ?><span class="badge" style="background:rgba(255,255,255,0.08);color:var(--text-sub)">⚽ 축구</span>
        <?php elseif(!empty($m['sport_type']) && $m['sport_type']==='풋살'): ?><span class="badge" style="background:rgba(255,255,255,0.08);color:var(--text-sub)">🏃 풋살</span><?php endif; ?>
        <?php if(!empty($m['region'])): ?><span class="badge" style="background:rgba(255,255,255,0.06);color:var(--text-sub)"><?=h($m['region'])?><?=!empty($m['district'])?' '.h($m['district']):''?></span><?php endif; ?>
      </div>
      <?php if(!empty($m['allow_mercenary']) && in_array($m['status'],['open','request_pending','confirmed','checkin_open']) && me() && (int)($m['home_team_id']??0) !== myTeamId()): ?>
      <div style="margin-top:6px;display:flex;gap:6px">
        <a href="?page=match&id=<?=$m['id']?>#merc" onclick="event.stopPropagation()" class="btn btn-sm" style="flex:1;background:rgba(255,180,0,0.15);color:#ffb400;font-size:11px;padding:5px 0;text-align:center;border-radius:6px;text-decoration:none">
          <i class="bi bi-megaphone-fill"></i> 용병 지원
        </a>
      </div>
      <?php endif; ?>
    </div>
  </a>
  <?php endforeach;endif; ?>

  <?php if(isCaptain()): ?>
  <hr class="divider" id="create">
  <p class="section-title">매치 개설</p>
  <?php if (!myTeamActivated($pdo)):
    $memCnt = myTeamMemberCount($pdo);
    $needed = max(0, 3 - $memCnt); ?>
  <div class="card" style="border:1px solid rgba(255,184,0,0.35);background:rgba(255,184,0,0.05)"><div class="card-body" style="text-align:center;padding:28px 16px">
    <div style="font-size:34px;margin-bottom:8px">⏳</div>
    <div style="font-size:15px;font-weight:700;color:var(--warning);margin-bottom:4px">팀 활성화가 먼저 필요합니다</div>
    <div style="font-size:12px;color:var(--text-sub);margin-bottom:14px">팀원 <?=$needed?>명 더 모이면 매치 개설이 가능합니다 (현재 <?=$memCnt?>/3명)</div>
    <a href="?page=team" class="btn btn-outline btn-sm">팀으로 이동 → 초대코드 공유</a>
  </div></div>
  <?php else: ?>
  <div class="card"><div class="card-body">
    <form method="POST">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="create_match">

      <!-- 매치 타입 선택 (3-way) -->
      <!-- [매치 MVP] 팀을 구하는지 경기장을 구하는지 명확히 구별 -->
      <div class="form-group">
        <label class="form-label">어떤 매치인가요?</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
          <label style="cursor:pointer">
            <input type="radio" name="match_type" value="VENUE" checked style="display:none" class="mtype-radio">
            <div class="mtype-chip" style="border-radius:10px;padding:12px 6px;text-align:center;height:100%">
              <div style="font-size:22px;margin-bottom:3px">🏟️</div>
              <div style="font-size:12px;font-weight:700">상대팀 구함</div>
              <div style="font-size:9px;color:var(--text-sub);margin-top:2px">구장 확보됨</div>
            </div>
          </label>
          <label style="cursor:pointer">
            <input type="radio" name="match_type" value="MERC_ONLY" style="display:none" class="mtype-radio">
            <div class="mtype-chip" style="border-radius:10px;padding:12px 6px;text-align:center;height:100%">
              <div style="font-size:22px;margin-bottom:3px">⚡</div>
              <div style="font-size:12px;font-weight:700">용병만 구함</div>
              <div style="font-size:9px;color:var(--text-sub);margin-top:2px">팀+구장 확정</div>
            </div>
          </label>
          <label style="cursor:pointer">
            <input type="radio" name="match_type" value="VENUE_WANTED" style="display:none" class="mtype-radio">
            <div class="mtype-chip" style="border-radius:10px;padding:12px 6px;text-align:center;height:100%">
              <div style="font-size:22px;margin-bottom:3px">🔍</div>
              <div style="font-size:12px;font-weight:700">경기장 구함</div>
              <div style="font-size:9px;color:var(--text-sub);margin-top:2px">상대는 있음</div>
            </div>
          </label>
          <label style="cursor:pointer">
            <input type="radio" name="match_type" value="REQUEST" style="display:none" class="mtype-radio">
            <div class="mtype-chip" style="border-radius:10px;padding:12px 6px;text-align:center;height:100%">
              <div style="font-size:22px;margin-bottom:3px">🆘</div>
              <div style="font-size:12px;font-weight:700">모두 구함</div>
              <div style="font-size:9px;color:var(--text-sub);margin-top:2px">상대+구장</div>
            </div>
          </label>
        </div>
        <style>
          .mtype-radio + .mtype-chip { border:2px solid rgba(255,255,255,0.1); background:rgba(255,255,255,0.02); }
          .mtype-radio:checked + .mtype-chip { border-color: var(--primary); background: rgba(0,255,136,0.12); }
          .mtype-radio:checked + .mtype-chip > :nth-child(2) { color: var(--primary); }
        </style>
        <div style="font-size:10px;color:var(--text-sub);margin-top:6px;line-height:1.5">
          💡 <strong>상대팀 구함</strong>: 구장 잡았고 상대팀 찾을 때<br>
          💡 <strong>용병만 구함</strong>: 팀+구장 다 있고 인원만 채울 때<br>
          💡 <strong>경기장 구함</strong>: 상대 정해졌는데 구장 필요<br>
          💡 <strong>모두 구함</strong>: 상대+구장 열어둘 때
        </div>
      </div>

      <!-- 종목 선택 -->
      <div class="form-group">
        <label class="form-label">종목</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
          <label style="cursor:pointer">
            <input type="radio" name="sport_type" value="풋살" checked style="display:none" class="mtype-radio">
            <div class="mtype-chip" style="border-radius:10px;padding:10px;text-align:center">
              <div style="font-size:13px;font-weight:700">🏃 풋살</div>
            </div>
          </label>
          <label style="cursor:pointer">
            <input type="radio" name="sport_type" value="축구" style="display:none" class="mtype-radio">
            <div class="mtype-chip" style="border-radius:10px;padding:10px;text-align:center">
              <div style="font-size:13px;font-weight:700">⚽ 축구</div>
            </div>
          </label>
        </div>
      </div>
      <div class="form-group"><label class="form-label">매치 제목</label>
        <input type="text" name="title" class="form-control" placeholder="예: 강남구 풋살 5vs5" required></div>
      <div class="form-group"><label class="form-label">장소</label>
        <input type="text" name="location" class="form-control" placeholder="구장명 (미정이면 '미정')" required></div>
      <div data-tf-toggle="venueDetail" style="font-size:11px;color:var(--primary);cursor:pointer;margin:-6px 0 8px;padding-left:2px">📍 구장 상세정보 입력 (선택) ▼</div>
      <div id="venueDetail" class="tf-collapse">
        <div class="form-group"><label class="form-label" style="font-size:11px">구장 주소</label>
          <input type="text" name="venue_address" class="form-control" placeholder="서울시 강남구 역삼동 123-4" style="font-size:12px"></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label" style="font-size:11px">🚇 가까운 역</label>
            <input type="text" name="venue_subway" class="form-control" placeholder="역삼역 3번출구 5분" style="font-size:12px"></div>
          <div class="form-group"><label class="form-label" style="font-size:11px">🅿️ 주차</label>
            <input type="text" name="venue_parking" class="form-control" placeholder="무료주차 가능" style="font-size:12px"></div>
        </div>
        <div class="form-group"><label class="form-label" style="font-size:11px">📝 구장 참고사항</label>
          <input type="text" name="venue_note" class="form-control" placeholder="실내/실외, 잔디종류 등" style="font-size:12px"></div>
      </div>
      <div class="form-group"><label class="form-label">날짜</label>
        <input type="date" name="match_date" class="form-control" required></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">시작 시간</label>
          <input type="time" name="match_time" class="form-control" required></div>
        <div class="form-group"><label class="form-label">종료 시간 <span style="font-size:10px;color:var(--text-sub)">(선택)</span></label>
          <input type="time" name="match_end_time" class="form-control"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">정원</label>
          <input type="number" name="max_players" class="form-control" value="12" min="2"></div>
        <div class="form-group"><label class="form-label">실력</label>
          <select name="level" class="form-select">
            <?php foreach(['모든실력','입문','초급','중급','고급'] as $lv): ?>
            <option><?=h($lv)?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">경기 방식</label>
          <select name="format_type" class="form-select">
            <?php foreach(['축구','풋살'] as $f): ?>
            <option><?=h($f)?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-group"><label class="form-label">매치 스타일</label>
          <select name="match_style" class="form-select">
            <?php foreach(['친선','리그','토너먼트','연습'] as $s): ?>
            <option><?=h($s)?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <!-- [유니폼] 우리 팀 유니폼 색상 — 매치 카드에서 바로 식별 -->
      <div class="form-group">
        <label class="form-label">우리 팀 유니폼 색 <span style="font-size:11px;color:var(--text-sub)">(선택)</span></label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:4px">
          <?php foreach (uniformColorMap() as $uk => [$uhex, $ulbl]):
            $isEmpty = $uk === '';
          ?>
          <label style="cursor:pointer;display:inline-flex;flex-direction:column;align-items:center;gap:4px">
            <input type="radio" name="uniform_color" value="<?=h($uk)?>" <?=$isEmpty?'checked':''?> style="display:none" class="uni-radio">
            <span class="uni-chip" style="display:inline-block;width:34px;height:34px;border-radius:50%;background:<?=$isEmpty?'rgba(255,255,255,0.04)':$uhex?>;<?=$uk==='WHITE'?'border:1px solid rgba(0,0,0,0.25);':''?><?=$isEmpty?'border:1px dashed rgba(255,255,255,0.3);':''?>transition:transform 0.15s,box-shadow 0.15s"></span>
            <span style="font-size:10px;color:var(--text-sub)"><?=h($ulbl)?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <style>
          .uni-radio:checked + .uni-chip { transform:scale(1.15); box-shadow:0 0 0 2px var(--primary) }
        </style>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">지역</label>
          <input type="text" name="region" class="form-control" placeholder="서울"></div>
        <div class="form-group"><label class="form-label">구/군</label>
          <input type="text" name="district" class="form-control" placeholder="강남구"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">참가비</label>
          <select name="fee_type" class="form-select">
            <?php foreach(['없음','개인부담','팀부담','협의'] as $ft): ?>
            <option><?=h($ft)?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-group"><label class="form-label">금액(원)</label>
          <input type="number" name="fee_amount" class="form-control" value="0" min="0" step="1000"></div>
      </div>
      <!-- [상대팀 직접 입력] 미등록 팀이어도 이름만 입력 가능 -->
      <div class="form-group">
        <label class="form-label">상대팀명 <span style="font-size:10px;color:var(--text-sub)">(이미 정해졌다면 입력, 선택)</span></label>
        <input type="text" name="away_team_name" class="form-control" placeholder="예: 송파 드래곤즈 (미정이면 비워두세요)" maxlength="100">
      </div>
      <div class="form-group">
        <label class="form-label">기타 안내</label>
        <input type="text" name="note" class="form-control" placeholder="주차, 샤워시설, 유니폼 등">
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;padding:10px 0">
        <div style="display:flex;align-items:center;gap:10px">
          <input type="checkbox" name="allow_mercenary" id="allow_mercenary" value="1" style="width:18px;height:18px">
          <label for="allow_mercenary" style="margin:0;cursor:pointer"><i class="bi bi-lightning-charge-fill" style="color:var(--primary)"></i> 용병 모집 허용</label>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
          <input type="checkbox" name="is_private" id="is_private" value="1" style="width:18px;height:18px">
          <label for="is_private" style="margin:0;cursor:pointer"><i class="bi bi-lock-fill" style="color:#ff9500"></i> 비공개 (팀원만 볼 수 있음)</label>
        </div>
        <div style="font-size:10px;color:var(--text-sub);margin-left:28px">💡 비공개로 만들면 팀 내부 투표용. 인원 부족하면 나중에 용병 모집 전환 가능!</div>
      </div>
      <!-- [1-3] 매너 필터 — 신청팀 캡틴의 매너점수 제한 -->
      <div class="form-group">
        <label class="form-label">참여 최소 매너점수</label>
        <select name="min_manner_score" class="form-select">
          <option value="0">제한없음</option>
          <option value="35">35° 이상 (보통)</option>
          <option value="38">38° 이상 (좋음)</option>
          <option value="40">40° 이상 (우수)</option>
        </select>
        <div style="font-size:11px;color:var(--text-sub);margin-top:4px">신청 팀 캡틴의 매너점수 기준</div>
      </div>
      <button type="submit" class="btn btn-primary btn-w">매치 개설</button>
    </form>
  </div></div>
  <?php endif; /* myTeamActivated */ ?>
  <?php endif; /* isCaptain */ ?>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 매치 상세
// ═══════════════════════════════════════════════════════════════
function pagMatchDetail(PDO $pdo): void {
    $weatherData = getWeatherData();
    $id=(int)($_GET['id']??0);
    $match=$pdo->prepare("SELECT m.*,COALESCE(ht.name,'(팀 미지정)') AS home_name,at.name AS away_name FROM matches m LEFT JOIN teams ht ON ht.id=m.home_team_id LEFT JOIN teams at ON at.id=m.away_team_id WHERE m.id=?");
    $match->execute([$id]);$match=$match->fetch();
    if(!$match){echo '<div class="container" style="padding:60px 16px;text-align:center;color:var(--text-sub)">매치를 찾을 수 없습니다.</div>';return;}

    $myUid = (int)(me()['id'] ?? 0);
    $isMatchCreator = ((int)($match['creator_id'] ?? 0) === $myUid);

    // [2단계] 평가 미완료 캡틴에게 페이지 진입 시 자동 리마인더 (12/24시간 경과 시 1회만)
    if (in_array($match['status'], ['result_pending']) && (int)($match['evaluation_step']??0) < 1) {
        $resTime = $pdo->prepare("SELECT created_at FROM match_results WHERE match_id=?");
        $resTime->execute([$id]); $rTime = $resTime->fetchColumn();
        if ($rTime) {
            $hoursPassed = (time() - strtotime($rTime)) / 3600;
            foreach ([(int)$match['home_team_id'], (int)$match['away_team_id']] as $tid) {
                if (!$tid) continue;
                $cap = $pdo->prepare("SELECT user_id FROM team_members WHERE team_id=? AND role='captain' AND status='active' LIMIT 1");
                $cap->execute([$tid]); $cid = (int)$cap->fetchColumn();
                if (!$cid) continue;
                $reviewed = $pdo->prepare("SELECT id FROM reviews WHERE match_id=? AND reviewer_id=? AND target_type='team'");
                $reviewed->execute([$id, $cid]);
                if ($reviewed->fetch()) continue;
                foreach ([2, 12, 24] as $tier) {
                    if ($hoursPassed >= $tier) {
                        $key = "REMIND_EVAL_{$id}_{$cid}_{$tier}";
                        $exists = $pdo->prepare("SELECT id FROM notifications WHERE user_id=? AND type='EVAL' AND extra_data LIKE ? LIMIT 1");
                        $exists->execute([$cid, '%'.$key.'%']);
                        if (!$exists->fetch()) {
                            notify($pdo, $cid, 'EVAL', "[{$tier}h 리마인드] 상대팀 평가가 남아있어요!", '평가 완료해야 결과가 공식 반영됩니다.', '?page=team_eval&match_id='.$id, ['key'=>$key]);
                        }
                    }
                }
            }
        }
    }

    $result=$pdo->prepare("SELECT mr.*,u.name AS rname FROM match_results mr LEFT JOIN users u ON u.id=mr.reporter_id WHERE mr.match_id=?");
    $result->execute([$id]);$result=$result->fetch();

    $requests=[];
    if(isCaptain()&&myTeamId()==$match['home_team_id']){
        $s=$pdo->prepare("SELECT mr.*,t.name AS tname,u.name AS uname FROM match_requests mr JOIN teams t ON t.id=mr.team_id JOIN users u ON u.id=mr.requested_by WHERE mr.match_id=? AND mr.status='pending'");
        $s->execute([$id]);$requests=$s->fetchAll();
    }

    $cks=$pdo->prepare("SELECT c.*, u.name, u.nickname, t.name AS tname FROM checkins c JOIN users u ON u.id=c.user_id JOIN teams t ON t.id=c.team_id WHERE c.match_id=?");
    $cks->execute([$id]);$cks=$cks->fetchAll();
    $hci=array_filter($cks,fn($c)=>$c['team_id']==$match['home_team_id']);
    $aci=array_filter($cks,fn($c)=>$c['team_id']==$match['away_team_id']);
    $myCI=!empty(array_filter($cks,fn($c)=>$c['user_id']==me()['id']));
    $isToday=$match['match_date']===date('Y-m-d');
    $myTeam=myTeamId();
    $isMine=in_array($myTeam,[$match['home_team_id'],$match['away_team_id']]);

    // ── 출석 투표 현황 (팀별) ────────────────────────────────
    $attendanceMap = []; // user_id => status
    $oppAttendCount = null; // 상대팀 참석 인원 수
    if ($isMine) {
        $as = $pdo->prepare("
            SELECT ma.user_id, ma.status, u.name, u.nickname, ma.is_bench
            FROM match_attendance ma JOIN users u ON u.id=ma.user_id
            WHERE ma.match_id=? AND ma.team_id=?
        ");
        $as->execute([$id, $myTeam]);
        foreach ($as->fetchAll() as $a) $attendanceMap[$a['user_id']] = $a;

        // 상대팀 참석 집계 (수만 표시, 이름은 비공개)
        $oppTeam = $myTeam == $match['home_team_id'] ? $match['away_team_id'] : $match['home_team_id'];
        if ($oppTeam) {
            $oppQ = $pdo->prepare("SELECT
                (SELECT COUNT(*) FROM match_attendance WHERE match_id=? AND team_id=? AND status='ATTEND') AS attend,
                (SELECT COUNT(*) FROM match_attendance WHERE match_id=? AND team_id=? AND status='ABSENT') AS absent,
                (SELECT COUNT(*) FROM match_attendance WHERE match_id=? AND team_id=? AND status='PENDING') AS pending
            ");
            $oppQ->execute([$id,$oppTeam,$id,$oppTeam,$id,$oppTeam]);
            $oppAttendCount = $oppQ->fetch();
            $oppAttendCount['team_name'] = $myTeam == $match['home_team_id'] ? ($match['away_name']??'상대팀') : $match['home_name'];
        }
    }

    // ── 내 팀원 목록 (대리 신청용) ──────────────────────────
    $myTeamMembers = [];
    if (isCaptain() && $myTeam) {
        // [수정] 팀원만 대리 출석 대상 (용병 제외)
        $tm = $pdo->prepare("
            SELECT tm.user_id, u.name, u.nickname, u.position, u.position_prefs, u.profile_image_url, tm.role
            FROM team_members tm JOIN users u ON u.id=tm.user_id
            WHERE tm.team_id=? AND tm.status='active' AND tm.role != 'mercenary'
            ORDER BY u.name
        ");
        $tm->execute([$myTeam]); $myTeamMembers = $tm->fetchAll();
    }

    // ── 매치 신청자 리스트 (match_requests - 경기 신청한 팀들) ─
    $allRequests = [];
    if ($isMine || isAdmin()) {
        $rq = $pdo->prepare("
            SELECT mr.id, mr.status, mr.created_at, mr.message,
                   t.name AS team_name, t.region, u.name AS requester_name
            FROM match_requests mr
            JOIN teams t ON t.id=mr.team_id
            JOIN users u ON u.id=mr.requested_by
            WHERE mr.match_id=?
            ORDER BY mr.status='pending' DESC, mr.created_at DESC
        ");
        $rq->execute([$id]); $allRequests = $rq->fetchAll();
    }

    // ── 용병 신청자 리스트 ───────────────────────────────────
    $mercRequests = [];
    if ($isMine) {
        $mq = $pdo->prepare("
            SELECT mr.id, mr.user_id, mr.status, mr.message, mr.offer_type, mr.created_at,
                   u.name AS user_name, u.position, u.manner_score
            FROM mercenary_requests mr JOIN users u ON u.id=mr.user_id
            WHERE mr.match_id=? AND mr.team_id=?
            ORDER BY FIELD(mr.status,'pending','accepted','rejected'), mr.created_at DESC
        ");
        $mq->execute([$id, $myTeam]); $mercRequests = $mq->fetchAll();
    }

    // ── 쿼터 배정 데이터 ──────────────────────────────────────
    $quarterData = [1=>[], 2=>[], 3=>[], 4=>[]]; // quarter => [user_id => ['name','position']]
    if ($isMine) {
        $qs = $pdo->prepare("
            SELECT mq.quarter, mq.user_id, mq.position, u.name, u.nickname, u.profile_image_url, u.jersey_number
            FROM match_quarters mq JOIN users u ON u.id=mq.user_id
            WHERE mq.match_id=? AND mq.team_id=?
        ");
        $qs->execute([$id, $myTeam]);
        foreach ($qs->fetchAll() as $q) {
            $q['is_bench'] = (int)($attendanceMap[$q['user_id']]['is_bench'] ?? 0);
            $quarterData[$q['quarter']][$q['user_id']] = $q;
        }
    } ?>

<div class="container">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;gap:8px">
    <h2 style="font-size:18px;font-weight:700;line-height:1.3;flex:1;min-width:0"><?=h($match['title']?:$match['home_name'].' vs '.($match['away_name']??'?'))?></h2>
    <div style="display:flex;gap:6px;align-items:center;flex-shrink:0">
      <!-- [공유] 매치 정보 공유 버튼 -->
      <?php
        $shareTitle = h($match['title'] ?: $match['home_name'].' vs '.($match['away_name']??'?'));
        $shareText  = "⚽ {$shareTitle}\n📅 {$match['match_date']} ".dayOfWeek($match['match_date'])." ".matchTimeStr($match)."\n📍 ".h($match['location']??'-')."\n👥 {$match['current_players']}/{$match['max_players']}명";
        $shareUrl   = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')?'https':'http').'://'.$_SERVER['HTTP_HOST'].'/app.php?page=match&id='.$id;
      ?>
      <button type="button" onclick="shareMatch()" class="btn btn-ghost btn-sm" style="padding:4px 8px" title="매치 공유">
        <i class="bi bi-share" style="font-size:16px"></i>
      </button>
      <script>
      function shareMatch() {
        var title = <?=json_encode(strip_tags($shareTitle))?>;
        var text = <?=json_encode($shareText)?>;
        var url = <?=json_encode($shareUrl)?>;
        if (navigator.share) {
          navigator.share({title:title, text:text, url:url}).catch(function(){});
        } else {
          copyToClipboard(text + '\n' + url);
          showCopyToast('매치 정보가 복사되었습니다!');
        }
      }
      </script>
      <?=statusBadge($match['status'], $match['match_type'] ?? '')?>
    </div>
  </div>
  <div style="font-size:13px;color:var(--text-sub);line-height:2;margin-bottom:10px">
    <?php
      // [개설자 표시] creator_id → 닉네임/이름 조회
      $creatorInfo = null;
      if (!empty($match['creator_id'])) {
        $cq = $pdo->prepare("SELECT id, name, nickname FROM users WHERE id=?");
        $cq->execute([(int)$match['creator_id']]); $creatorInfo = $cq->fetch();
      }
    ?>
    <?php if ($creatorInfo): ?>
    <i class="bi bi-person-badge"></i> 개설: <span style="font-weight:600;cursor:pointer" onclick="openUserProfile(<?=(int)$creatorInfo['id']?>)"><?=h(displayName($creatorInfo))?></span>
    <?php if ((int)($creatorInfo['id'] ?? 0) !== (int)me()['id']): ?>
    <form method="POST" style="display:inline;margin-left:6px"><?=csrfInput()?><input type="hidden" name="action" value="start_chat"><input type="hidden" name="target_user_id" value="<?=(int)$creatorInfo['id']?>"><button type="submit" class="btn btn-ghost btn-sm" style="font-size:10px;padding:2px 8px;vertical-align:middle"><i class="bi bi-chat-dots"></i> 문의</button></form>
    <?php endif; ?>
    <br>
    <?php endif; ?>
    <i class="bi bi-calendar3"></i> <?=$match['match_date']?> <?=dayOfWeek($match['match_date'])?> <?=matchTimeStr($match)?><br>
    <i class="bi bi-geo-alt"></i> <?=h($match['location']??'-')?><?=!empty($match['region'])?' ('.h($match['region']).(empty($match['district'])?'':' '.h($match['district'])).')':''?><br>
    <?php $totalAttend = $pdo->prepare("SELECT COUNT(*) FROM match_attendance WHERE match_id=? AND status='ATTEND'"); $totalAttend->execute([$id]); $totalAttend=(int)$totalAttend->fetchColumn(); ?>
    <i class="bi bi-people"></i> <?=$totalAttend?>/<?=$match['max_players']?>명 &nbsp;·&nbsp; <?=h($match['level']??'')?>
    <?php if(!empty($match['format_type'])): ?>&nbsp;·&nbsp; <?=h($match['format_type'])?><?php endif; ?>
    <?php if(!empty($match['match_style'])): ?>&nbsp;·&nbsp; <?=h($match['match_style'])?><?php endif; ?>
    <?php if(!empty($match['fee_type']) && $match['fee_type']!=='없음'): ?><br>
    <i class="bi bi-cash"></i> 참가비 <?=h($match['fee_type'])?><?=($match['fee_amount']>0)?' '.number_format($match['fee_amount']).'원':''?><?php endif; ?>
    <?php if(!empty($match['note'])): ?><br>
    <i class="bi bi-info-circle"></i> <?=h($match['note'])?><?php endif; ?>
  </div>
  <div style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:4px">
    <?php if(!empty($match['allow_mercenary'])): ?><span class="badge" style="background:rgba(255,180,0,0.15);color:#ffb400"><i class="bi bi-lightning-charge-fill"></i> 용병모집</span><?php endif; ?>
  </div>

  <!-- VS 카드 + 매치 채팅 -->
  <div class="card" style="margin-bottom:12px">
    <div class="card-body" style="display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:8px;text-align:center">
      <div style="font-weight:700">
        <div style="display:flex;align-items:center;justify-content:center;gap:5px"><?= uniformDot($match['uniform_color'] ?? '', 14) ?><?=h($match['home_name'])?></div>
      </div>
      <div style="color:var(--text-sub);font-weight:700;font-family:'Space Grotesk',sans-serif">VS</div>
      <?php
        $awayDisplay = $match['away_name'] ?: ($match['away_team_name'] ?? '');
        $canEditAway = $isMatchCreator || isAdmin();
      ?>
      <div style="font-weight:700">
        <?php if ($awayDisplay): ?>
          <?=h($awayDisplay)?>
        <?php else: ?>
          <span style="color:var(--text-sub);font-size:13px">상대 미정</span>
        <?php endif; ?>
        <?php if ($canEditAway): ?>
        <!-- 상대팀 바로 수정 -->
        <form method="POST" style="margin-top:6px;display:flex;gap:4px;align-items:center;justify-content:center">
          <?=csrfInput()?>
          <input type="hidden" name="action" value="quick_set_away">
          <input type="hidden" name="match_id" value="<?=$id?>">
          <input type="text" name="away_team_name" value="<?=h($match['away_team_name']??'')?>"
            placeholder="상대팀명 입력" maxlength="100"
            style="width:120px;font-size:11px;padding:4px 8px;background:rgba(0,0,0,0.3);color:var(--text-main);border:1px solid rgba(255,255,255,0.15);border-radius:4px;text-align:center">
          <button type="submit" class="btn btn-primary btn-sm" style="font-size:10px;padding:4px 8px">저장</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php
      // [득점/어시 명단] 결과 있으면 개인 기록 요약
      $scorers = $pdo->prepare("
        SELECT mpr.user_id, COALESCE(NULLIF(TRIM(u.nickname),''), u.name) AS pname,
               mpr.goals, mpr.assists, mpr.team_id
        FROM match_player_records mpr JOIN users u ON u.id=mpr.user_id
        WHERE mpr.match_id=? AND (mpr.goals > 0 OR mpr.assists > 0)
        ORDER BY mpr.goals DESC, mpr.assists DESC
      ");
      $scorers->execute([$id]);
      $scorerList = $scorers->fetchAll();
    ?>
    <?php if ($scorerList): ?>
    <div style="padding:6px 16px 10px;border-top:1px solid rgba(255,255,255,0.06)">
      <?php foreach ($scorerList as $sc): ?>
      <div style="font-size:12px;display:flex;gap:6px;align-items:center;padding:3px 0">
        <span style="color:var(--text-main);font-weight:600"><?=h($sc['pname'])?></span>
        <?php if ((int)$sc['goals'] > 0): ?>
        <span style="color:#ffb400;font-weight:700">⚽<?=$sc['goals']?></span>
        <?php endif; ?>
        <?php if ((int)$sc['assists'] > 0): ?>
        <span style="color:#3a9ef5;font-weight:700">🎯<?=$sc['assists']?></span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if($isMine): ?>
    <div style="padding:0 16px 14px">
      <form method="POST">
        <?=csrfInput()?>
        <input type="hidden" name="action" value="start_chat">
        <input type="hidden" name="match_id" value="<?=$id?>">
        <button type="submit" class="btn btn-outline btn-w" style="font-size:13px">
          <i class="bi bi-chat-dots-fill" style="color:var(--primary)"></i> 매치 단체 채팅방
        </button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <!-- 체크인 현황 -->
  <?php
    // 체크인 현황: open/request_pending/cancelled에서는 숨김
    // 단, MERC_ONLY는 open이어도 체크인 표시 (팀+구장 확보 상태)
    $showCheckin = !in_array($match['status'], ['request_pending','cancelled']);
    if ($match['status'] === 'open' && ($match['match_type'] ?? '') !== 'MERC_ONLY') $showCheckin = false;
  ?>
  <?php if($showCheckin): ?>
  <div class="card" style="margin-bottom:12px"><div class="card-body">
    <p class="section-title" style="margin-bottom:12px"><i class="bi bi-check2-square"></i> 체크인 현황</p>
    <div class="form-row">
      <div>
        <div style="font-size:12px;color:var(--text-sub);margin-bottom:4px"><?=h($match['home_name'])?></div>
        <div class="ci-bar"><div class="ci-fill" style="width:<?=$match['max_players']>0?min(100,count($hci)/$match['max_players']*100):0?>%"></div></div>
        <div style="font-size:12px;color:var(--text-sub)"><?=count($hci)?>/<?=$match['max_players']?>명</div>
        <div style="margin-top:6px"><?php foreach($hci as $c): ?><span class="badge badge-green" style="margin:2px;cursor:pointer" onclick="openUserProfile(<?=(int)$c['user_id']?>)"><?=h(displayName($c))?></span><?php endforeach;?></div>
      </div>
      <?php if($match['away_team_id']): ?>
      <div>
        <div style="font-size:12px;color:var(--text-sub);margin-bottom:4px"><?=h($match['away_name']??'')?></div>
        <div class="ci-bar"><div class="ci-fill" style="width:<?=$match['max_players']>0?min(100,count($aci)/$match['max_players']*100):0?>%"></div></div>
        <div style="font-size:12px;color:var(--text-sub)"><?=count($aci)?>/<?=$match['max_players']?>명</div>
        <div style="margin-top:6px"><?php foreach($aci as $c): ?><span class="badge badge-blue" style="margin:2px;cursor:pointer" onclick="openUserProfile(<?=(int)$c['user_id']?>)"><?=h(displayName($c))?></span><?php endforeach;?></div>
      </div>
      <?php endif; ?>
    </div>
    <?php if($isToday&&$isMine&&!$myCI): ?>
    <form method="POST" style="margin-top:12px">
      <?=csrfInput()?><input type="hidden" name="action" value="checkin">
      <input type="hidden" name="match_id" value="<?=$id?>">
      <button type="submit" class="btn btn-primary btn-w"><i class="bi bi-check-lg"></i> 체크인하기</button>
    </form>
    <?php elseif($myCI): ?>
    <div style="margin-top:12px;text-align:center">
      <span style="color:var(--primary);font-weight:600"><i class="bi bi-check-circle-fill"></i> 체크인 완료!</span>
      <form method="POST" style="display:inline;margin-left:10px" onsubmit="return confirm('체크인을 취소하시겠습니까?')">
        <?=csrfInput()?><input type="hidden" name="action" value="cancel_checkin"><input type="hidden" name="match_id" value="<?=$id?>">
        <button type="submit" class="btn btn-ghost btn-sm" style="font-size:11px;color:var(--text-sub);padding:4px 8px">취소</button>
      </form>
    </div>
    <?php elseif($isMine&&!$isToday): ?>
    <div style="margin-top:12px;text-align:center;color:var(--warning);font-size:13px">경기 당일에만 체크인 가능합니다.</div>
    <?php endif; ?>
  </div></div>
  <?php endif; ?>


  <!-- 참석 투표 + 상대팀 집계 -->
  <?php if($isMine): ?>
  <div class="card" style="margin-bottom:12px"><div class="card-body">
    <p class="section-title" style="margin-bottom:12px"><i class="bi bi-person-check"></i> 참석 현황</p>

    <!-- 내 팀 참석 -->
    <?php
    $myAttend  = array_filter($attendanceMap, fn($a) => $a['status']==='ATTEND');
    $myAbsent  = array_filter($attendanceMap, fn($a) => $a['status']==='ABSENT');
    $myPending = array_filter($attendanceMap, fn($a) => $a['status']==='PENDING');
    $myStarters = array_filter($myAttend, fn($a) => empty($a['is_bench']));
    $myBench    = array_filter($myAttend, fn($a) => !empty($a['is_bench']));
    ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px">
      <!-- 내 팀 -->
      <div style="background:var(--bg-surface-alt);border-radius:10px;padding:10px">
        <div style="font-size:11px;color:var(--text-sub);font-weight:700;margin-bottom:6px">우리 팀</div>
        <div style="display:flex;gap:8px;align-items:baseline;margin-bottom:6px">
          <span style="font-size:22px;font-weight:900;color:var(--primary);font-family:'Space Grotesk',sans-serif"><?=count($myAttend)?></span>
          <span style="font-size:12px;color:var(--text-sub)">/ <?=count($attendanceMap)?>명 응답</span>
        </div>
        <?php if($myStarters): ?>
        <div style="margin-bottom:4px"><span style="font-size:10px;font-weight:700;color:var(--primary)">⚽ 선발 (<?=count($myStarters)?>명)</span></div>
        <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:6px">
          <?php foreach($myStarters as $a): ?>
          <span class="badge badge-green" style="font-size:10px;cursor:pointer;display:inline-flex;align-items:center;gap:3px" onclick="openUserProfile(<?=(int)$a['user_id']?>)">
            <?=h(displayName($a))?>
            <?php if(isCaptain()): ?><span onclick="event.stopPropagation();toggleBench(<?=$id?>,<?=(int)$a['user_id']?>,this)" style="cursor:pointer;margin-left:2px;font-size:9px;opacity:0.7" title="후보로 변경">↓</span><?php endif; ?>
          </span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if($myBench): ?>
        <div style="margin-bottom:4px"><span style="font-size:10px;font-weight:700;color:#ffb400">🪑 후보 (<?=count($myBench)?>명)</span></div>
        <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:6px">
          <?php foreach($myBench as $a): ?>
          <span class="badge" style="font-size:10px;cursor:pointer;background:rgba(255,180,0,0.12);color:#ffb400;display:inline-flex;align-items:center;gap:3px" onclick="openUserProfile(<?=(int)$a['user_id']?>)">
            <?=h(displayName($a))?>
            <?php if(isCaptain()): ?><span onclick="event.stopPropagation();toggleBench(<?=$id?>,<?=(int)$a['user_id']?>,this)" style="cursor:pointer;margin-left:2px;font-size:9px;opacity:0.7" title="선발로 변경">↑</span><?php endif; ?>
          </span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:4px;flex-wrap:wrap">
          <?php foreach($myAbsent as $a): ?><span class="badge badge-red" style="font-size:10px;opacity:0.7;cursor:pointer" onclick="openUserProfile(<?=(int)$a['user_id']?>)"><?=h(displayName($a))?></span><?php endforeach; ?>
          <?php if(count($myPending)>0): ?><span style="font-size:11px;color:var(--text-sub)">미정 <?=count($myPending)?>명</span><?php endif; ?>
        </div>
      </div>
      <!-- 상대팀 (숫자만) -->
      <?php if($oppAttendCount): ?>
      <div style="background:rgba(255,255,255,0.04);border-radius:10px;padding:10px;border:1px dashed rgba(255,255,255,0.1)">
        <div style="font-size:11px;color:var(--text-sub);font-weight:700;margin-bottom:6px"><?=h($oppAttendCount['team_name'])?></div>
        <div style="display:flex;gap:8px;align-items:baseline;margin-bottom:6px">
          <span style="font-size:22px;font-weight:900;color:var(--warning);font-family:'Space Grotesk',sans-serif"><?=(int)$oppAttendCount['attend']?></span>
          <span style="font-size:12px;color:var(--text-sub)">참석 예정</span>
        </div>
        <div style="font-size:11px;color:var(--text-sub)">
          불참 <?=(int)$oppAttendCount['absent']?> · 미정 <?=(int)$oppAttendCount['pending']?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- 내 투표 버튼 -->
    <?php
    $myVote = $attendanceMap[me()['id']]['status'] ?? 'PENDING';
    ?>
    <div style="font-size:12px;color:var(--text-sub);margin-bottom:8px;font-weight:600">내 출석 투표</div>
    <div class="vote-wrap" style="display:flex;gap:6px">
      <?php foreach(['ATTEND'=>['✓ 참석','var(--primary)','rgba(0,255,136,0.12)'],'PENDING'=>['? 미정','var(--text-sub)','rgba(255,255,255,0.06)'],'ABSENT'=>['✗ 불참','#ff4d4d','rgba(255,59,48,0.1)']] as $sv=>[$sl,$sc,$sbg]): ?>
      <button onclick="ajaxVoteDetail(<?=$id?>,'<?=$sv?>',this)" class="btn btn-w" style="flex:1;font-size:13px;padding:10px 0;border-radius:10px;font-weight:<?=$myVote===$sv?'700':'500'?>;background:<?=$myVote===$sv?$sbg:'transparent'?>;color:<?=$myVote===$sv?$sc:'var(--text-sub)'?>;border:1.5px solid <?=$myVote===$sv?$sc:'var(--border)'?>"><?=$sl?></button>
      <?php endforeach; ?>
    </div>
  </div></div>
  <?php endif; ?>

  <!-- 결과 -->
  <?php if($result): ?>
  <div class="card" style="margin-bottom:12px"><div class="card-body">
    <p class="section-title"><i class="bi bi-bar-chart-fill"></i> 경기 결과</p>
    <div class="score-box"><?=$result['score_home']?> <span style="color:var(--text-sub)">:</span> <?=$result['score_away']?></div>
    <div style="display:flex;justify-content:space-between;margin-top:4px;font-size:12px;color:var(--text-sub)">
      <span><?=h($match['home_name'])?></span><span><?=h($match['away_name']??'')?></span>
    </div>
    <div style="margin-top:10px;font-size:13px;color:var(--text-sub)">
      입력: <?=h($result['rname']??'-')?> &nbsp;·&nbsp;
      상태: <span style="color:<?=$result['is_approved']?'var(--primary)':'var(--warning)'?>;font-weight:600">
        <?=$result['is_approved']?'승인됨':'승인 대기'?>
      </span>
    </div>
    <?php if(!$result['is_approved']&&$isMine&&me()['id']!=$result['reporter_id']): ?>
    <div style="display:flex;gap:8px;margin-top:12px">
      <form method="POST" style="flex:1">
        <?=csrfInput()?><input type="hidden" name="action" value="approve_result">
        <input type="hidden" name="match_id" value="<?=$id?>">
        <button type="submit" class="btn btn-primary btn-w" style="min-height:44px">승인</button>
      </form>
      <button class="btn btn-outline" style="min-height:44px" data-tf-toggle="disputeForm">이의제기</button>
    </div>
    <div id="disputeForm" class="tf-collapse" style="margin-top:10px">
      <form method="POST">
        <?=csrfInput()?><input type="hidden" name="action" value="dispute_result">
        <input type="hidden" name="match_id" value="<?=$id?>">
        <textarea name="reason" class="form-control" placeholder="분쟁 사유를 입력하세요" required></textarea>
        <button type="submit" class="btn btn-danger btn-w" style="margin-top:8px">분쟁 신청</button>
      </form>
    </div>
    <?php endif; ?>
    <!-- 경기결과 공유 버튼 -->
    <div style="margin-top:12px;text-align:center">
      <button type="button" class="btn btn-outline btn-sm" onclick="shareMatchResult(<?=$id?>, '<?=addslashes(h($match['home_name']))?>', '<?=addslashes(h($match['away_name']??''))?>', <?=(int)$result['score_home']?>, <?=(int)$result['score_away']?>, '<?=h($match['match_date'])?>', '<?=addslashes(h($match['location']??''))?>', '')">
        📸 경기결과 공유
      </button>
    </div>
  </div></div>
  <?php endif; ?>

  <!-- 결과 입력 -->
  <?php
    // [결과 입력] 캡틴이면 경기 종료 전 언제든 + open(MERC_ONLY) + result_pending(수정)도 가능
    // completed 포함 → 지난 경기도 결과/개인 기록 수정 가능
    $canEnterResult = $isMine && isCaptain() &&
      in_array($match['status'], ['open','confirmed','in_progress','checkin_open','result_pending','completed']);
  ?>
  <?php if($canEnterResult): ?>
  <div class="card" style="margin-bottom:12px"><div class="card-body">
    <p class="section-title"><?=$result ? '결과 수정' : '결과 입력'?></p>
    <form method="POST">
      <?=csrfInput()?><input type="hidden" name="action" value="submit_result">
      <input type="hidden" name="match_id" value="<?=$id?>">
      <div style="display:grid;grid-template-columns:1fr 30px 1fr;align-items:end;gap:8px;margin-bottom:12px">
        <div><div style="font-size:12px;color:var(--text-sub);text-align:center;margin-bottom:4px"><?=h($match['home_name'])?></div>
          <input type="number" name="home_score" class="form-control" style="text-align:center;font-size:28px;font-family:'Space Grotesk',sans-serif;font-weight:700" value="<?=(int)($result['score_home']??0)?>" min="0" required></div>
        <div style="text-align:center;padding-bottom:14px;color:var(--text-sub);font-weight:700">:</div>
        <div><div style="font-size:12px;color:var(--text-sub);text-align:center;margin-bottom:4px"><?=h($awayDisplay ?? $match['away_name'] ?? '어웨이')?></div>
          <input type="number" name="away_score" class="form-control" style="text-align:center;font-size:28px;font-family:'Space Grotesk',sans-serif;font-weight:700" value="<?=(int)($result['score_away']??0)?>" min="0" required></div>
      </div>
      <button type="submit" class="btn btn-primary btn-w"><?=$result ? '결과 수정' : '결과 등록'?></button>
    </form>

    <!-- [개인 기록] 골/어시/경고/퇴장 — 스코어와 별도로 항상 표시 -->
    <hr class="divider" style="margin:14px 0">
    <p class="section-title" style="margin-bottom:4px">⚽ 개인 기록 (골/어시/경고/퇴장)</p>
    <div style="font-size:11px;color:var(--text-sub);margin-bottom:8px">참석 체크된 팀원의 기록을 입력하세요. 스코어와 별도로 저장됩니다.</div>
    <?php
      // 참석 확인된 내 팀원 목록
      $recordPlayers = $pdo->prepare("
        SELECT ma.user_id, COALESCE(NULLIF(TRIM(u.nickname),''), u.name) AS pname, u.position,
               mpr.goals AS cur_goals, mpr.assists AS cur_assists,
               mpr.yellow_cards AS cur_yellow, mpr.red_cards AS cur_red
        FROM match_attendance ma
        JOIN users u ON u.id=ma.user_id
        LEFT JOIN match_player_records mpr ON mpr.match_id=? AND mpr.user_id=ma.user_id
        WHERE ma.match_id=? AND ma.team_id=? AND ma.status IN ('ATTEND','PENDING')
        ORDER BY u.name
      ");
      $recordPlayers->execute([$id, $id, $myTeam]);
      $rPlayers = $recordPlayers->fetchAll();
    ?>
    <?php if ($rPlayers): ?>
    <form method="POST">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="save_player_records">
      <input type="hidden" name="match_id" value="<?=$id?>">
      <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:10px">
        <?php foreach ($rPlayers as $rp): ?>
        <div style="display:flex;align-items:center;gap:8px;padding:8px;background:rgba(255,255,255,0.03);border-radius:8px">
          <div style="flex:1;min-width:0">
            <span style="font-weight:600;font-size:13px"><?=h($rp['pname'])?></span>
            <?php if($rp['position']): ?><span style="font-size:10px;color:var(--text-sub);margin-left:4px"><?=h($rp['position'])?></span><?php endif; ?>
          </div>
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:4px;flex-shrink:0;min-width:200px">
            <div style="text-align:center">
              <div style="font-size:10px;color:#ffb400;font-weight:700;margin-bottom:2px">득점</div>
              <input type="number" name="goals[<?=(int)$rp['user_id']?>]" value="<?=(int)($rp['cur_goals']??0)?>" min="0" max="20"
                style="width:100%;text-align:center;background:rgba(0,0,0,0.3);color:#ffb400;border:1px solid rgba(255,180,0,0.3);border-radius:6px;padding:6px;font-size:16px;font-weight:700">
            </div>
            <div style="text-align:center">
              <div style="font-size:10px;color:#3a9ef5;font-weight:700;margin-bottom:2px">어시</div>
              <input type="number" name="assists[<?=(int)$rp['user_id']?>]" value="<?=(int)($rp['cur_assists']??0)?>" min="0" max="20"
                style="width:100%;text-align:center;background:rgba(0,0,0,0.3);color:#3a9ef5;border:1px solid rgba(58,158,245,0.3);border-radius:6px;padding:6px;font-size:16px;font-weight:700">
            </div>
            <div style="text-align:center">
              <div style="font-size:10px;color:#ffd60a;font-weight:700;margin-bottom:2px">경고</div>
              <input type="number" name="yellows[<?=(int)$rp['user_id']?>]" value="<?=(int)($rp['cur_yellow']??0)?>" min="0" max="5"
                style="width:100%;text-align:center;background:rgba(0,0,0,0.3);color:#ffd60a;border:1px solid rgba(255,214,10,0.3);border-radius:6px;padding:6px;font-size:16px;font-weight:700">
            </div>
            <div style="text-align:center">
              <div style="font-size:10px;color:#ff4d6d;font-weight:700;margin-bottom:2px">퇴장</div>
              <input type="number" name="reds[<?=(int)$rp['user_id']?>]" value="<?=(int)($rp['cur_red']??0)?>" min="0" max="2"
                style="width:100%;text-align:center;background:rgba(0,0,0,0.3);color:#ff4d6d;border:1px solid rgba(255,77,109,0.3);border-radius:6px;padding:6px;font-size:16px;font-weight:700">
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="btn btn-primary btn-w" style="font-size:13px">개인 기록 저장</button>
    </form>
    <?php else: ?>
    <div style="font-size:12px;color:var(--text-sub);text-align:center;padding:10px">출석 관리에서 팀원의 참석/미정을 먼저 설정해주세요.</div>
    <?php endif; ?>
  </div></div>
  <?php endif; ?>

  <!-- 전체 신청 내역 (내 팀 or 관리자에게만 표시) -->
  <?php if ($allRequests): ?>
  <div class="card" style="margin-bottom:12px"><div class="card-body">
    <p class="section-title" style="margin-bottom:12px">
      <i class="bi bi-list-ul"></i> 경기 신청 내역 <span style="font-size:13px;color:var(--text-sub)">(<?=count($allRequests)?>건)</span>
    </p>
    <?php foreach($allRequests as $r):
      $sc = match($r['status']){'accepted'=>'badge-green','rejected'=>'badge-red','cancelled'=>'badge-gray',default=>'badge-yellow'};
      $sl = match($r['status']){'accepted'=>'수락됨','rejected'=>'거절됨','cancelled'=>'취소',default=>'대기중'};
    ?>
    <div class="list-item" style="align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--border)">
      <div style="flex:1">
        <div style="font-weight:600"><?=h($r['team_name'])?> <span style="font-size:11px;color:var(--text-sub)"><?=h($r['region'])?></span></div>
        <div style="font-size:12px;color:var(--text-sub)">신청자: <?=h($r['requester_name'])?> · <?=date('m/d H:i',strtotime($r['created_at']))?></div>
        <?php if($r['message']): ?><div style="font-size:12px;color:var(--text-sub);margin-top:2px">"<?=h(mb_substr($r['message'],0,50))?>"</div><?php endif; ?>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px">
        <span class="badge <?=$sc?>"><?=$sl?></span>
        <?php if($r['status']==='pending' && isCaptain() && $myTeam==$match['home_team_id']): ?>
        <div style="display:flex;gap:4px;margin-top:4px">
          <form method="POST"><?=csrfInput()?><input type="hidden" name="action" value="accept_request"><input type="hidden" name="request_id" value="<?=$r['id']?>"><button type="submit" class="btn btn-primary btn-sm" style="min-height:32px;padding:0 10px">수락</button></form>
          <form method="POST"><?=csrfInput()?><input type="hidden" name="action" value="reject_request"><input type="hidden" name="request_id" value="<?=$r['id']?>"><button type="submit" class="btn btn-ghost btn-sm" style="min-height:32px;padding:0 10px">거절</button></form>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>

  <!-- 용병 신청/제안 내역 — 포지션별 분류 -->
  <?php if ($mercRequests): ?>
  <div class="card" style="margin-bottom:12px"><div class="card-body">
    <p class="section-title" style="margin-bottom:12px">⚡ 용병 현황
      <span style="font-size:12px;color:var(--text-sub);font-weight:400">총 <?=count($mercRequests)?>명</span>
    </p>
    <?php
    // 포지션별 그룹핑
    $posBuckets = ['GK'=>[], 'DF'=>[], 'MF'=>[], 'FW'=>[], '기타'=>[]];
    foreach($mercRequests as $mr) {
        $pos = $mr['position'] ?? '기타';
        $key = array_key_exists($pos, $posBuckets) ? $pos : '기타';
        $posBuckets[$key][] = $mr;
    }
    foreach($posBuckets as $posKey => $pList):
      if(!$pList) continue;
    ?>
    <div style="margin-bottom:12px">
      <div style="font-size:11px;font-weight:700;color:var(--text-sub);letter-spacing:0.5px;margin-bottom:6px;padding:4px 8px;background:rgba(255,255,255,0.04);border-radius:6px;display:inline-block">
        <?=$posKey?> <span style="font-size:10px">(<?=count($pList)?>)</span>
      </div>
      <?php foreach($pList as $mr):
        $mc = match($mr['status']){'accepted'=>['badge-green','✓ 확정'],'rejected'=>['badge-red','✕ 거절'],'cancelled'=>['badge-gray','취소'],default=>['badge-yellow','⏳ 대기']};
        $typeLabel = $mr['offer_type']==='offer' ? '📤 우리가 제안' : '📥 선수 신청';
      ?>
      <div style="display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid rgba(255,255,255,0.05)">
        <div style="flex:1">
          <div style="font-weight:600;font-size:13px"><?=h($mr['user_name'])?>
            <span style="font-size:10px;color:var(--text-sub);margin-left:4px">매너 <?=number_format((float)$mr['manner_score'],1)?>°</span>
          </div>
          <div style="font-size:11px;color:var(--text-sub)"><?=$typeLabel?> · <?=timeAgo($mr['created_at'])?></div>
        </div>
        <span class="badge <?=$mc[0]?>" style="font-size:10px;flex-shrink:0"><?=$mc[1]?></span>
        <?php if($mr['status']==='pending' && isCaptain() && $mr['offer_type']==='apply'): ?>
        <div style="display:flex;gap:4px">
          <form method="POST"><?=csrfInput()?><input type="hidden" name="action" value="mercenary_respond"><input type="hidden" name="req_id" value="<?=$mr['id']?>"><input type="hidden" name="status" value="accepted"><button type="submit" class="btn btn-primary btn-sm" style="padding:4px 10px;font-size:12px">✓</button></form>
          <form method="POST"><?=csrfInput()?><input type="hidden" name="action" value="mercenary_respond"><input type="hidden" name="req_id" value="<?=$mr['id']?>"><input type="hidden" name="status" value="rejected"><button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 10px;font-size:12px">✕</button></form>
        </div>
        <?php elseif($mr['status']==='pending' && $mr['offer_type']==='offer'): ?>
        <span style="font-size:10px;color:var(--text-sub)">선수 응답 대기중</span>
        <?php endif; ?>
        <?php if($mr['user_id'] && $mr['user_id'] != me()['id']): ?>
        <form method="POST">
          <?=csrfInput()?>
          <input type="hidden" name="action" value="start_chat">
          <input type="hidden" name="target_user_id" value="<?=(int)$mr['user_id']?>">
          <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px;font-size:12px" title="DM 보내기"><i class="bi bi-chat-dots"></i></button>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>

  <!-- 쿼터 선수 선발 (경기 당일 + 확정 경기, 소속팀만) -->
  <?php
  $showLineup = $isMine && in_array($match['status'],['confirmed','checkin_open','in_progress','result_pending','open']);
  $myLineupQtrs = []; // 내가 배정된 쿼터 목록
  if ($isMine && !isCaptain()) {
      foreach($quarterData as $qNum => $qPlayers) {
          if (isset($qPlayers[me()['id']])) $myLineupQtrs[$qNum] = $qPlayers[me()['id']];
      }
  }
  ?>
  <?php if($showLineup): ?>
  <!-- 구장정보 + 경기 날씨 -->
  <?php
    $hasVenueInfo = $match['venue_address'] || $match['venue_subway'] || $match['venue_parking'] || $match['venue_note'];
    $mdWeather = '';
    $mdRainMsg = '';
    $mdDays = (int)floor((strtotime($match['match_date']) - strtotime(date('Y-m-d'))) / 86400);
    if ($weatherData && $mdDays >= 0 && $mdDays <= 2):
      $mdHourly = $weatherData['weather'][$mdDays]['hourly'] ?? [];
      $mdHour = (int)substr($match['match_time'] ?? '18:00', 0, 2);
      foreach ($mdHourly as $mdH) {
        $mdHH = (int)floor((int)$mdH['time'] / 100);
        if ($mdHH === $mdHour || ($mdHH <= $mdHour && $mdHH + 3 > $mdHour)) {
          $mdWeather = weatherIcon($mdH['weatherCode']) . ' ' . $mdH['tempC'] . '° · 습도 ' . $mdH['humidity'] . '%';
          $mdRain = (int)($mdH['chanceofrain'] ?? 0);
          if ($mdRain >= 60) $mdRainMsg = '☔ 수중전 예상! 여벌 옷 챙기세요';
          elseif ($mdRain >= 30) $mdRainMsg = '🌂 비 올 수 있어요 (' . $mdRain . '%)';
          elseif ((int)$mdH['tempC'] >= 30) $mdRainMsg = '🥵 폭염 주의! 충분한 수분 섭취하세요';
          elseif ((int)$mdH['tempC'] <= 3) $mdRainMsg = '🥶 강추위! 따뜻하게 입고 오세요';
          break;
        }
      }
    elseif ($mdDays > 2):
      $mdWeather = '📅 경기 ' . $mdDays . '일 전 - 날씨는 2일 전부터 표시됩니다';
    endif;
  ?>
  <?php if ($hasVenueInfo || $mdWeather || $mdDays > 2): ?>
  <div class="card" style="margin-bottom:12px;border:1px solid rgba(58,158,245,0.15)"><div class="card-body" style="padding:12px 14px">
    <div style="font-size:13px;font-weight:700;margin-bottom:8px">📍 구장 정보<?=$mdWeather?' & 날씨':''?></div>
    <div style="display:grid;gap:6px;font-size:12px">
      <?php if($match['venue_address']): ?>
      <div style="display:flex;gap:6px"><span style="color:var(--text-sub);min-width:55px">주소</span><span style="flex:1"><?=h($match['venue_address'])?></span></div>
      <?php endif; ?>
      <?php if($match['venue_subway']): ?>
      <div style="display:flex;gap:6px"><span style="color:var(--text-sub);min-width:55px">🚇 지하철</span><span style="flex:1"><?=h($match['venue_subway'])?></span></div>
      <?php endif; ?>
      <?php if($match['venue_parking']): ?>
      <div style="display:flex;gap:6px"><span style="color:var(--text-sub);min-width:55px">🅿️ 주차</span><span style="flex:1"><?=h($match['venue_parking'])?></span></div>
      <?php endif; ?>
      <?php if($match['venue_note']): ?>
      <div style="display:flex;gap:6px"><span style="color:var(--text-sub);min-width:55px">📝 참고</span><span style="flex:1"><?=h($match['venue_note'])?></span></div>
      <?php endif; ?>
      <?php if($mdWeather): ?>
      <div style="display:flex;gap:6px;padding-top:4px;border-top:1px solid rgba(255,255,255,0.05)">
        <span style="color:var(--text-sub);min-width:55px">🌤️ 날씨</span>
        <span style="flex:1;font-weight:600"><?=$mdWeather?></span>
      </div>
      <?php if($mdRainMsg): ?>
      <div style="font-size:11px;font-weight:600;color:#3a9ef5;padding:4px 8px;background:rgba(58,158,245,0.08);border-radius:6px"><?=$mdRainMsg?></div>
      <?php endif; endif; ?>
    </div>
  </div></div>
  <?php endif; ?>

  <div class="card" id="lineup" style="margin-bottom:12px"><div class="card-body">
    <p class="section-title" style="margin-bottom:14px">🏟️ 쿼터 선수 선발
      <?php if(!isCaptain()): ?>
      <span style="font-size:12px;color:var(--text-sub);font-weight:400">내 배정 보기</span>
      <?php else: ?>
      <span style="font-size:12px;color:var(--text-sub);font-weight:400">캡틴 배정 관리</span>
      <?php endif; ?>
    </p>

    <?php if(!isCaptain() && $myLineupQtrs): ?>
    <!-- 선수 본인: 내가 배정된 쿼터만 표시 -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
      <?php for($q=1;$q<=4;$q++): ?>
      <?php if(isset($myLineupQtrs[$q])): $me_pos=$myLineupQtrs[$q]['position']; ?>
      <div style="background:rgba(0,255,136,0.08);border:1.5px solid rgba(0,255,136,0.3);border-radius:12px;padding:12px;text-align:center">
        <div style="font-size:11px;color:var(--text-sub);margin-bottom:4px"><?=$q?>쿼터</div>
        <div style="font-size:22px;font-weight:800;color:var(--primary);font-family:'Space Grotesk',sans-serif"><?=$me_pos?></div>
      </div>
      <?php else: ?>
      <div style="background:rgba(255,255,255,0.03);border:1px dashed rgba(255,255,255,0.1);border-radius:12px;padding:12px;text-align:center">
        <div style="font-size:11px;color:var(--text-sub)"><?=$q?>쿼터</div>
        <div style="font-size:13px;color:rgba(255,255,255,0.2);margin-top:4px">—</div>
      </div>
      <?php endif; ?>
      <?php endfor; ?>
    </div>
    <?php elseif(!isCaptain()): ?>
    <div style="text-align:center;color:var(--text-sub);padding:20px 0;font-size:13px">아직 배정된 쿼터가 없습니다.<br>캡틴이 배정하면 여기에 표시됩니다.</div>
    <?php endif; ?>

    <?php
      // [포메이션 뷰] 일반 선수도 쿼터별 포메이션 볼 �� 있게
      $anyQuarterHasData = !empty(array_filter($quarterData, fn($qd)=>!empty($qd)));
      if (!isCaptain() && $anyQuarterHasData):
    ?>
    <button type="button" class="btn btn-outline btn-w" style="margin-top:10px;font-size:12px" data-tf-toggle="viewFormation">
      🏟️ 포메이션 보기
    </button>
    <div id="viewFormation" class="tf-collapse" style="margin-top:8px">
      <!-- 쿼터 탭 (뷰 전용) -->
      <div style="display:flex;gap:6px;margin-bottom:8px">
        <?php for($vq=1;$vq<=4;$vq++): ?>
        <button onclick="showViewQ(<?=$vq?>)" id="vqtab-<?=$vq?>"
          class="btn btn-sm" style="flex:1;<?=$vq===1?'background:var(--primary);color:#0F1117':'background:rgba(255,255,255,0.06);color:var(--text-sub)'?>">
          <?=$vq?>Q
        </button>
        <?php endfor; ?>
      </div>
      <?php for($vq=1;$vq<=4;$vq++): ?>
      <div id="vqpanel-<?=$vq?>" style="display:<?=$vq===1?'block':'none'?>">
        <?php if($quarterData[$vq]):
          $posByTypeV = ['GK'=>[],'DF'=>[],'MF'=>[],'FW'=>[]];
          $viewStarters = array_filter($quarterData[$vq], fn($p) => empty($p['is_bench']));
          foreach ($viewStarters as $qpv) {
            $pv = $qpv['position'] ?? 'MF';
            if (!isset($posByTypeV[$pv])) $pv = 'MF';
            $posByTypeV[$pv][] = $qpv;
          }
          $posCoordsV = ['GK'=>[[50,90]],'LB'=>[[15,74]],'CB'=>[[38,78],[62,78]],'RB'=>[[85,74]],'CDM'=>[[50,62]],'LM'=>[[12,48]],'CM'=>[[38,50],[62,50]],'RM'=>[[88,48]],'CAM'=>[[50,38]],'LW'=>[[15,22]],'ST'=>[[50,16]],'RW'=>[[85,22]]];
          $posColorsV = ['GK'=>'#ff9500','LB'=>'#3a9ef5','CB'=>'#3a9ef5','RB'=>'#3a9ef5','CDM'=>'#00c87a','LM'=>'#00ff88','CM'=>'#00ff88','RM'=>'#00ff88','CAM'=>'#ffd60a','LW'=>'#ff6b6b','ST'=>'#ff6b6b','RW'=>'#ff6b6b','DF'=>'#3a9ef5','MF'=>'#00ff88','FW'=>'#ff6b6b'];
        ?>
        <div style="position:relative;width:100%;max-width:300px;margin:0 auto;aspect-ratio:300/210;background:linear-gradient(180deg,#1a5e2a,#1e6e32,#1a5e2a);border-radius:8px;border:2px solid rgba(255,255,255,0.3);overflow:hidden">
          <svg viewBox="0 0 300 210" style="position:absolute;inset:0;width:100%;height:100%">
            <rect x="8" y="8" width="284" height="194" fill="none" stroke="rgba(255,255,255,0.3)" stroke-width="1"/>
            <line x1="8" y1="105" x2="292" y2="105" stroke="rgba(255,255,255,0.2)"/>
            <circle cx="150" cy="105" r="25" fill="none" stroke="rgba(255,255,255,0.2)"/>
          </svg>
          <?php
            foreach ($posByTypeV as $pos => $players) {
              $coords = $posCoordsV[$pos] ?? $posCoordsV['CM'] ?? [[50,50]]; $total = count($players);
              if (!$total) continue;
              $useC = $total <= count($coords)
                ? array_slice($coords, (int)floor((count($coords)-$total)/2), $total)
                : array_map(fn($i)=>[15+$i/(max(1,$total-1))*70, $coords[0][1]], range(0,$total-1));
              foreach ($players as $pi => $pl) {
                $cx = ($useC[$pi][0]??50)/100*300; $cy = ($useC[$pi][1]??50)/100*210;
                $c = $posColorsV[$pos];
                $nickV = trim($pl['nickname'] ?? '');
                $dispV = $nickV !== '' ? $nickV : ($pl['name'] ?? '?');
                $circV = h(mb_substr($dispV, 0, 2, 'UTF-8'));
                $belowV = h(mb_substr($pl['name'] ?? '', 0, 3, 'UTF-8'));
                $hasPhV = !empty($pl['profile_image_url']);
                echo "<div style=\"position:absolute;left:{$cx}px;top:{$cy}px;transform:translate(-50%,-50%);text-align:center\">";
                if ($hasPhV) {
                  $imgV = h($pl['profile_image_url']);
                  echo "<div style=\"width:30px;height:30px;border-radius:50%;overflow:hidden;border:2px solid {$c};box-shadow:0 2px 6px rgba(0,0,0,0.4)\"><img src=\"{$imgV}\" style=\"width:100%;height:100%;object-fit:cover\"></div>";
                } else {
                  echo "<div style=\"width:30px;height:30px;border-radius:50%;background:{$c};color:#fff;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;border:2px solid rgba(255,255,255,0.5);box-shadow:0 2px 6px rgba(0,0,0,0.4)\">{$circV}</div>";
                }
                echo "<div style=\"font-size:7px;color:#fff;text-shadow:0 1px 2px #000;margin-top:1px\">{$belowV}</div>";
                echo "</div>";
              }
            }
          ?>
        </div>
        <?php else: ?>
        <div style="text-align:center;color:var(--text-sub);font-size:12px;padding:16px">이 쿼터 배정 없음</div>
        <?php endif; ?>
      </div>
      <?php endfor; ?>
      <script>
      function showViewQ(n){
        for(var i=1;i<=4;i++){
          document.getElementById('vqpanel-'+i).style.display=i===n?'block':'none';
          var t=document.getElementById('vqtab-'+i);
          t.style.background=i===n?'var(--primary)':'rgba(255,255,255,0.06)';
          t.style.color=i===n?'#0F1117':'var(--text-sub)';
        }
      }
      </script>
    </div>
    <?php endif; ?>

    <?php if(isCaptain()): ?>
    <!-- 캡틴: 쿼터별 선수 배정 UI -->
    <?php
    // 내 팀원 목록 (용병 confirmed 포함)
    $rosterMembers = $myTeamMembers;
    // 확정된 용병도 추가
    $confMercs = array_filter($mercRequests, fn($m)=>$m['status']==='accepted');
    ?>

    <!-- [포메이션 프리셋] 선택하면 드롭다운 자동 세팅 -->
    <div style="margin-bottom:12px">
      <div style="font-size:11px;color:var(--text-sub);font-weight:700;margin-bottom:6px">⚽ 포메이션 선택</div>
      <div style="display:flex;gap:4px;flex-wrap:wrap">
        <?php
          $formations = [
            '4-4-2' => ['GK','LB','CB','CB','RB','LM','CM','CM','RM','ST','ST'],
            '4-3-3' => ['GK','LB','CB','CB','RB','CM','CM','CM','LW','ST','RW'],
            '3-5-2' => ['GK','CB','CB','CB','LM','CDM','CM','CM','RM','ST','ST'],
            '3-4-3' => ['GK','CB','CB','CB','LM','CM','CM','RM','LW','ST','RW'],
            '4-2-3-1'=>['GK','LB','CB','CB','RB','CDM','CDM','LM','CAM','RM','ST'],
            '5-3-2' => ['GK','LB','CB','CB','CB','RB','CM','CM','CM','ST','ST'],
            '풋살2-2'=>['GK','LB','RB','LW','RW'],
            '풋살1-2-1'=>['GK','CB','LM','RM','ST'],
          ];
        ?>
        <?php foreach($formations as $fname=>$fslots): ?>
        <button type="button" onclick='applyFormation(<?=json_encode($fslots)?>,"<?=$fname?>")'
          class="btn btn-outline btn-sm" style="font-size:10px;padding:4px 8px"><?=$fname?></button>
        <?php endforeach; ?>
      </div>
      <div id="formationInfo" style="font-size:10px;color:var(--primary);margin-top:4px"></div>
    </div>

    <!-- 쿼터 탭 -->
    <div style="display:flex;gap:6px;margin-bottom:12px;overflow-x:auto">
      <?php for($q=1;$q<=4;$q++): ?>
      <button onclick="showQ(<?=$q?>)" id="qtab-<?=$q?>"
              class="btn btn-sm" style="flex-shrink:0;min-width:60px;
                <?=$q===1?'background:var(--primary);color:#0F1117':'background:rgba(255,255,255,0.06);color:var(--text-sub)'?>">
        <?=$q?>쿼터 <span style="font-size:9px">(<?=count($quarterData[$q])?>명)</span>
      </button>
      <?php endfor; ?>
    </div>

    <?php for($q=1;$q<=4;$q++): ?>
    <div id="qpanel-<?=$q?>" style="display:<?=$q===1?'block':'none'?>">
      <form method="POST">
        <?=csrfInput()?>
        <input type="hidden" name="action" value="save_quarter">
        <input type="hidden" name="match_id" value="<?=$id?>">
        <input type="hidden" name="quarter" value="<?=$q?>">
        <input type="hidden" name="bench_players" id="bench_players_<?=$q?>" value="[]">
        <input type="hidden" name="starter_players" id="starter_players_<?=$q?>" value="[]">

        <!-- [포메이션 미니맵] Canvas (realtime) + PHP fallback -->
        <div style="margin-bottom:10px">
          <div style="font-size:11px;color:var(--text-sub);margin-bottom:6px"><?=$q?>쿼터 포메이션</div>
          <div style="display:flex;gap:6px;align-items:flex-start;max-width:420px;margin:0 auto">
          <canvas id="minimapCanvas-<?=$q?>" width="340" height="480" style="width:100%;border-radius:10px;flex:1;min-width:0"></canvas>
          <div id="minimapSidebar-<?=$q?>" style="width:100px;flex-shrink:0;display:flex;flex-direction:column;gap:6px">
            <!-- JS에서 채움 -->
          </div>
        </div>
          <div id="minimapBench-<?=$q?>" style="margin-top:6px;display:none"></div>
          <!-- 범례 (canvas용) -->
          <div class="minimap-legend-<?=$q?>" style="display:flex;gap:8px;justify-content:center;margin-top:6px;font-size:9px;color:var(--text-sub);flex-wrap:wrap">
            <span><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#ff9500;vertical-align:middle;margin-right:2px"></span>GK</span>
            <span><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#3a9ef5;vertical-align:middle;margin-right:2px"></span>수비</span>
            <span><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#00ff88;vertical-align:middle;margin-right:2px"></span>미드</span>
            <span><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#ffd60a;vertical-align:middle;margin-right:2px"></span>CAM</span>
            <span><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#ff6b6b;vertical-align:middle;margin-right:2px"></span>공격</span>
          </div>
        </div>
        <?php if($quarterData[$q]): ?>
        <div style="margin-bottom:10px;display:none" id="phpMinimap-<?=$q?>">
          <div style="font-size:11px;color:var(--text-sub);margin-bottom:6px"><?=$q?>쿼터 포메이션 (PHP)</div>
          <?php
            // 포지션별 피치 좌표 (%, 기본 배치)
            $posCoords = [
              'GK'  => [[50, 92]],
              'LB'  => [[20, 75]], 'CB' => [[38, 80],[62, 80]], 'RB' => [[80, 75]],
              'CDM' => [[50, 60]],
              'LM'  => [[15, 45]], 'CM' => [[38, 48],[62, 48]], 'RM' => [[85, 45]],
              'CAM' => [[50, 35]],
              'LW'  => [[15, 22]], 'ST' => [[50, 16]], 'RW' => [[85, 22]],
            ];
            $posByType = [];
            foreach (array_keys($posCoords) as $pk) $posByType[$pk] = [];
            $startersInQ = array_filter($quarterData[$q], fn($x) => empty($x['is_bench']));
            $benchInQ = array_filter($quarterData[$q], fn($x) => !empty($x['is_bench']));
            foreach ($startersInQ as $qp) {
              $p = $qp['position'] ?? 'MF';
              if (!isset($posByType[$p])) $p = 'MF';
              $posByType[$p][] = $qp;
            }
          ?>
          <div style="position:relative;width:100%;max-width:300px;margin:0 auto;aspect-ratio:340/240;background:linear-gradient(180deg,#1a5e2a 0%,#1e6e32 50%,#1a5e2a 100%);border-radius:10px;border:2px solid rgba(255,255,255,0.3);overflow:hidden">
            <!-- 피치 라인 SVG -->
            <svg viewBox="0 0 340 240" style="position:absolute;inset:0;width:100%;height:100%">
              <!-- 외곽선 -->
              <rect x="10" y="10" width="320" height="220" fill="none" stroke="rgba(255,255,255,0.35)" stroke-width="1.5"/>
              <!-- 중앙선 -->
              <line x1="10" y1="120" x2="330" y2="120" stroke="rgba(255,255,255,0.25)" stroke-width="1"/>
              <!-- 중앙원 -->
              <circle cx="170" cy="120" r="30" fill="none" stroke="rgba(255,255,255,0.25)" stroke-width="1"/>
              <circle cx="170" cy="120" r="2" fill="rgba(255,255,255,0.4)"/>
              <!-- 상단 페널티 영역 (공격) -->
              <rect x="110" y="10" width="120" height="45" fill="none" stroke="rgba(255,255,255,0.2)" stroke-width="1"/>
              <rect x="140" y="10" width="60" height="18" fill="none" stroke="rgba(255,255,255,0.15)" stroke-width="1"/>
              <!-- 하단 페널티 영역 (수비) -->
              <rect x="110" y="185" width="120" height="45" fill="none" stroke="rgba(255,255,255,0.2)" stroke-width="1"/>
              <rect x="140" y="212" width="60" height="18" fill="none" stroke="rgba(255,255,255,0.15)" stroke-width="1"/>
            </svg>
            <!-- 선수 배치 -->
            <?php
              $posColors = ['GK'=>'#ff9500','LB'=>'#3a9ef5','CB'=>'#3a9ef5','RB'=>'#3a9ef5',
                'CDM'=>'#00c87a','LM'=>'#00ff88','CM'=>'#00ff88','RM'=>'#00ff88','CAM'=>'#ffd60a',
                'LW'=>'#ff6b6b','ST'=>'#ff6b6b','RW'=>'#ff6b6b'];
              foreach ($posByType as $pos => $players) {
                $coords = $posCoords[$pos] ?? [[50,50]];
                $total = count($players);
                if ($total === 0) continue;
                // 인원수에 맞게 좌표 배분
                if ($total === 1) {
                  $useCoords = [[$coords[0][0] ?? 50, $coords[0][1] ?? 50]];
                } elseif ($total <= count($coords)) {
                  // 센터 정렬: 인원수만큼 가운데서 배분
                  $startIdx = (int)floor((count($coords) - $total) / 2);
                  $useCoords = array_slice($coords, $startIdx, $total);
                } else {
                  // 인원이 좌표보다 많으면 균등 분배
                  $useCoords = [];
                  $baseY = $coords[0][1] ?? 50;
                  for ($ci = 0; $ci < $total; $ci++) {
                    $x = 15 + ($ci / max(1, $total - 1)) * 70;
                    $useCoords[] = [$x, $baseY];
                  }
                }
                foreach ($players as $pi => $pl) {
                  $cx = ($useCoords[$pi][0] ?? 50) / 100 * 340;
                  $cy = ($useCoords[$pi][1] ?? 50) / 100 * 240;
                  $color = $posColors[$pos] ?? '#888';
                  $nick = trim($pl['nickname'] ?? '');
                  $dispName = $nick !== '' ? $nick : ($pl['name'] ?? '?');
                  $jNum = (int)($pl['jersey_number'] ?? 0);
                  $circleLabel = $jNum > 0 ? $jNum : mb_substr($dispName, 0, 2, 'UTF-8'); // 등번호 우선
                  $belowLabel = h(mb_substr($pl['name'] ?? '', 0, 3, 'UTF-8')); // 실명 3글자
                  $hasPhoto = !empty($pl['profile_image_url']);
                  echo "<div style=\"position:absolute;left:{$cx}px;top:{$cy}px;transform:translate(-50%,-50%);text-align:center;z-index:2\">";
                  if ($hasPhoto) {
                    $imgUrl = h($pl['profile_image_url']);
                    echo "<div style=\"width:34px;height:34px;border-radius:50%;overflow:hidden;border:2px solid {$color};box-shadow:0 2px 8px rgba(0,0,0,0.4)\"><img src=\"{$imgUrl}\" style=\"width:100%;height:100%;object-fit:cover\"></div>";
                  } else {
                    echo "<div style=\"width:34px;height:34px;border-radius:50%;background:{$color};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;border:2px solid rgba(255,255,255,0.6);box-shadow:0 2px 8px rgba(0,0,0,0.4)\">{$circleLabel}</div>";
                  }
                  echo "<div style=\"font-size:8px;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,0.9);margin-top:1px;white-space:nowrap;font-weight:600\">{$belowLabel}</div>";
                  echo "</div>";
                }
              }
            ?>
          </div>
          <!-- 후보 선수 -->
          <?php if(!empty($benchInQ)): ?>
          <div style="margin-top:8px;padding:6px 10px;background:rgba(255,180,0,0.05);border:1px solid rgba(255,180,0,0.15);border-radius:8px">
            <div style="font-size:9px;font-weight:700;color:#ffb400;margin-bottom:4px">🪑 후보 (<?=count($benchInQ)?>명)</div>
            <div style="display:flex;flex-wrap:wrap;gap:4px">
              <?php foreach($benchInQ as $bp): ?>
              <span style="font-size:9px;padding:2px 6px;border-radius:4px;background:rgba(255,180,0,0.1);color:#ffb400">
                <?=h(mb_substr($bp['name']??'',0,3,'UTF-8'))?> <?=$bp['position']??''?>
              </span>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
          <!-- 범례 -->
          <div style="display:flex;gap:8px;justify-content:center;margin-top:6px;font-size:9px;color:var(--text-sub);flex-wrap:wrap">
            <span><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#ff9500;vertical-align:middle;margin-right:2px"></span>GK</span>
            <span><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#3a9ef5;vertical-align:middle;margin-right:2px"></span>수비</span>
            <span><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#00ff88;vertical-align:middle;margin-right:2px"></span>미드</span>
            <span><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#ffd60a;vertical-align:middle;margin-right:2px"></span>CAM</span>
            <span><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#ff6b6b;vertical-align:middle;margin-right:2px"></span>공격</span>
          </div>
        </div>
<!-- /phpMinimap fallback -->
        <!-- 후보/교체 명단 -->
        <?php
          $qAssigned = $quarterData[$q] ?? [];
          $qAssignedIds = array_keys($qAssigned);
          $benchPlayers = [];
          foreach($myTeamMembers as $btm) {
            $buid = (int)$btm['user_id'];
            $bAtt = $attendanceMap[$buid]['status'] ?? 'NONE';
            $bIsBench = (int)($attendanceMap[$buid]['is_bench'] ?? 0);
            if ($bAtt === 'ATTEND' && !in_array($buid, $qAssignedIds)) {
              $btm['is_bench'] = $bIsBench;
              $benchPlayers[] = $btm;
            }
          }
          foreach($confMercs as $bcm) {
            $buid = $bcm['user_id'] ?? 0;
            if (!in_array($buid, $qAssignedIds)) {
              $benchPlayers[] = ['user_id'=>$buid,'name'=>($bcm['user_name']??'용병').' ⚡','position'=>$bcm['position']??'MF'];
            }
          }
        ?>
        <?php if($benchPlayers): ?>
        <div style="margin-top:8px;padding:8px 10px;background:rgba(255,180,0,0.05);border:1px solid rgba(255,180,0,0.15);border-radius:8px">
          <div style="font-size:10px;font-weight:700;color:#ffb400;margin-bottom:5px">🔄 후보 · 교체대기 (<?=count($benchPlayers)?>명)</div>
          <div style="display:flex;flex-wrap:wrap;gap:4px">
            <?php foreach($benchPlayers as $bp):
              $bpMap = ['DF'=>'CB','MF'=>'CM','FW'=>'ST'];
              $bpPos = $bpMap[$bp['position']??''] ?? ($bp['position'] ?: 'MF');
              $bpColor = match(true) { $bpPos==='GK'=>'#ff9500', in_array($bpPos,['CB','LB','RB'])=>'#3a9ef5', in_array($bpPos,['LW','ST','RW'])=>'#ff6b6b', default=>'#00ff88' };
            ?>
            <span style="font-size:10px;padding:3px 7px;border-radius:5px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.08);display:inline-flex;align-items:center;gap:3px">
              <span style="width:6px;height:6px;border-radius:50%;background:<?=$bpColor?>"></span>
              <?=h(mb_substr($bp['name'],0,3,'UTF-8'))?>
              <span style="font-size:8px;color:var(--text-sub)"><?=$bpPos?></span>
            </span>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- 선수별 포지션 선택 -->
        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
          <?php
          // 팀원 + 확정 용병 목록 (이름 + 주포지션 + 출석 상태)
          $allRoster = [];
          $posMap = ['DF'=>'CB','MF'=>'CM','FW'=>'ST'];
          $posOrder = array_flip(['GK','LB','CB','RB','CDM','LM','CM','RM','CAM','LW','ST','RW']);
          foreach($myTeamMembers as $tm) {
              $rawP = $tm['position'] ?? 'MF';
              $mappedP = $posMap[$rawP] ?? ($rawP ?: 'CM');
              $attStatus = $attendanceMap[$tm['user_id']]['status'] ?? 'NONE';
              $allRoster[$tm['user_id']] = ['name'=>$tm['name'],'pos'=>$rawP,'mapped'=>$mappedP,'att'=>$attStatus,'merc'=>false,'prefs'=>$tm['position_prefs']??'[]','nick'=>$tm['nickname']??$tm['name'],'photo'=>$tm['profile_image_url']??''];
          }
          foreach($confMercs as $cm) {
              $uid = $cm['user_id'] ?? 0;
              $rawP = $cm['position'] ?? 'MF';
              $mappedP = $posMap[$rawP] ?? ($rawP ?: 'CM');
              $allRoster[$uid] = ['name'=>($cm['user_name']??'용병').' ⚡','pos'=>$rawP,'mapped'=>$mappedP,'att'=>'ATTEND','merc'=>true,'prefs'=>'[]'];
          }
          // 정렬: 참석 먼저 → 포지션 순서 (GK→...→RW) → 미응답 → 불참
          uasort($allRoster, function($a, $b) use ($posOrder) {
              $attPri = ['ATTEND'=>0,'PENDING'=>1,'NONE'=>2,'ABSENT'=>3];
              $aPri = $attPri[$a['att']] ?? 2;
              $bPri = $attPri[$b['att']] ?? 2;
              if ($aPri !== $bPri) return $aPri - $bPri;
              $aPo = $posOrder[$a['mapped']] ?? 99;
              $bPo = $posOrder[$b['mapped']] ?? 99;
              return $aPo - $bPo;
          });
          $lastGroup = '';
          foreach($allRoster as $uid=>$udata):
            $uname = $udata['name'];
            $defaultPos = $udata['mapped'];
            $assigned = $quarterData[$q][$uid] ?? null;
            $curPos = $assigned ? $assigned['position'] : $defaultPos;
            $attStatus = $udata['att'];
            // 그룹 구분선
            $group = match($attStatus) { 'ATTEND'=>'참석','ABSENT'=>'불참',default=>'미응답' };
            if ($group !== $lastGroup) {
              $lastGroup = $group;
              $groupColor = match($attStatus) { 'ATTEND'=>'var(--primary)','ABSENT'=>'var(--danger)',default=>'var(--text-sub)' };
              $groupIcon = match($attStatus) { 'ATTEND'=>'✓','ABSENT'=>'✕',default=>'?' };
              echo "<div style=\"font-size:11px;color:{$groupColor};font-weight:600;margin-top:".($group==='참석'?'0':'8')."px;padding:4px 0;border-bottom:1px solid rgba(255,255,255,0.06)\">{$groupIcon} {$group}</div>";
            }
          ?>
          <?php $rowId = 'qrow-'.$q.'-'.$uid; ?>
          <?php $isBench = (int)($attendanceMap[$uid]['is_bench'] ?? 0); ?>
          <div style="display:flex;align-items:center;gap:6px;padding:8px 10px;background:rgba(255,255,255,0.03);border-radius:8px;<?=$assigned?'border:1px solid rgba(0,255,136,0.2)':''?>;<?=$attStatus==='ABSENT'?'opacity:0.4':''?>">
            <input type="checkbox" id="cb-<?=$rowId?>" data-prefs="<?=htmlspecialchars($udata['prefs'] ?? '[]', ENT_QUOTES)?>" data-nick="<?=h($udata['nick']??$udata['name'])?>" data-photo="<?=h($udata['photo']??'')?>" 
                   <?=$assigned?'checked':''?>
                   onchange="toggleQRow('<?=$rowId?>')"
                   style="width:16px;height:16px;accent-color:var(--primary);flex-shrink:0;cursor:pointer">
            <input type="text" id="hidden-<?=$rowId?>"
                   name="players[<?=$uid?>]"
                   value="<?=$curPos?>"
                   style="display:none"
                   <?=$assigned?'':'disabled'?>>
            <label for="cb-<?=$rowId?>" style="flex:1;font-size:13px;cursor:pointer;font-weight:<?=$assigned?'600':'400'?>">
              <?=h($uname)?>
              <span style="font-size:10px;padding:1px 5px;border-radius:4px;margin-left:2px;
                background:<?=match($defaultPos){
                  'GK'=>'rgba(255,149,0,0.15);color:#ff9500',
                  'LB','CB','RB'=>'rgba(58,158,245,0.15);color:#3a9ef5',
                  'CDM','LM','CM','RM'=>'rgba(0,255,136,0.15);color:#00ff88',
                  'CAM'=>'rgba(255,214,10,0.15);color:#ffd60a',
                  'LW','ST','RW'=>'rgba(255,107,107,0.15);color:#ff6b6b',
                  default=>'rgba(255,255,255,0.06);color:var(--text-sub)'
                }?>"><?=h($defaultPos)?></span>
            </label>
            <select id="sel-<?=$rowId?>"
                    onchange="document.getElementById('hidden-<?=$rowId?>').value=this.value; if(typeof drawMinimap==='function') drawMinimap(<?=$q?>)"
                    style="background:var(--bg-surface-alt);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:4px 8px;font-size:12px;<?=$assigned?'':'opacity:0.4'?>">
              <?php
              $allPos = ['GK','LB','CB','RB','CDM','CM','LM','RM','CAM','LW','ST','RW'];
              $sortedPos = array_merge([$defaultPos], array_filter($allPos, fn($p) => $p !== $defaultPos));
              foreach($sortedPos as $pos): ?>
              <option value="<?=$pos?>" <?=$curPos===$pos?'selected':''?>><?=$pos?><?=$pos===$defaultPos?' ★':''?></option>
              <?php endforeach; ?>
            </select>
            <button type="button"
              class="qbench-btn"
              data-uid="<?=$uid?>" data-mid="<?=$id?>"
              onclick="qToggleBench(this)"
              style="min-width:36px;padding:2px 6px;border-radius:5px;font-size:9px;font-weight:700;border:1px solid;cursor:pointer;
                <?=$isBench ? 'background:rgba(255,180,0,0.15);color:#ffb400;border-color:#ffb400' : 'background:rgba(0,255,136,0.15);color:#00ff88;border-color:#00ff88'?>">
              <?=$isBench ? '후보' : '선발'?>
            </button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-primary btn-w"><?=$q?>쿼터 저장</button>
      </form>
      <?php if ($q === 1): ?>
      <!-- 1쿼터 저장 후 전체 복사 (form 중첩 방지 — 별도 form) -->
      <form method="POST" style="margin-top:6px">
        <?=csrfInput()?>
        <input type="hidden" name="action" value="copy_quarter_all">
        <input type="hidden" name="match_id" value="<?=$id?>">
        <input type="hidden" name="source_quarter" value="1">
        <button type="submit" class="btn btn-outline btn-w" style="font-size:12px" onclick="return confirm('1쿼터 배정을 2~4쿼터에 동일 적용합니다.')">
          <i class="bi bi-copy"></i> 1쿼터를 전체 쿼터에 동일 적용
        </button>
      </form>
      <?php endif; ?>
    </div>
    <?php endfor; ?>

    <!-- 미가입 참석자 추가 -->
    <?php if(isCaptain()): ?>
    <div style="margin-top:12px;padding:10px;background:rgba(255,180,0,0.05);border:1px solid rgba(255,180,0,0.15);border-radius:8px">
      <div onclick="document.getElementById('guestAddForm').style.display=document.getElementById('guestAddForm').style.display==='none'?'block':'none'" style="font-size:12px;color:#ffb400;cursor:pointer;font-weight:600">
        <i class="bi bi-person-plus"></i> 미가입 참석자 추가 ▼
      </div>
      <div id="guestAddForm" style="display:none;margin-top:8px">
        <form method="POST">
          <?=csrfInput()?>
          <input type="hidden" name="action" value="add_guest_player">
          <input type="hidden" name="match_id" value="<?=$id?>">
          <div style="display:flex;gap:6px;margin-bottom:6px">
            <input type="text" name="guest_name" class="form-control" style="flex:2;font-size:12px" placeholder="이름" required>
            <select name="guest_position" class="form-control" style="flex:1;font-size:12px">
              <option value="">포지션</option>
              <?php foreach(['GK','LB','CB','RB','CDM','CM','LM','RM','CAM','LW','ST','RW'] as $gp): ?>
              <option value="<?=$gp?>"><?=$gp?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm" style="font-size:11px;white-space:nowrap">추가</button>
          </div>
          <div style="font-size:10px;color:var(--text-sub)">이름+포지션만 입력 → 임시회원 (7일 후 자동 삭제, 가입 시 데이터 유지)</div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <script>
function qToggleBench(btn) {
      var uid = btn.getAttribute('data-uid');
      var mid = btn.getAttribute('data-mid');
      var fd = new FormData();
      fd.append('action','toggle_bench');
      fd.append('match_id', mid);
      fd.append('user_id', uid);
      fetch('?page=api', {method:'POST', body:fd, credentials:'same-origin'})
        .then(function(r){return r.json()})
        .then(function(d){
          if(d.ok){
            if(d.is_bench){
              btn.textContent='후보';
              btn.style.background='rgba(255,180,0,0.15)';
              btn.style.color='#ffb400';
              btn.style.borderColor='#ffb400';
            } else {
              btn.textContent='선발';
              btn.style.background='rgba(0,255,136,0.15)';
              btn.style.color='#00ff88';
              btn.style.borderColor='#00ff88';
            }
            // 미니맵에서 즉시 반영 (새로고침 없이)
            var playerNodes = document.querySelectorAll('[data-qplayer-uid="'+uid+'"]');
            playerNodes.forEach(function(node){
              if(d.is_bench) { node.style.display='none'; }
              else { node.style.display=''; }
            });
            // 후보 목록 새로고침 표시
            var benchList = document.querySelector('.bench-live-list');
            // Redraw minimap instantly
            var activeQ = 1;
            for (var qi=1; qi<=4; qi++) {
              var qp = document.getElementById('qpanel-'+qi);
              if (qp && qp.style.display !== 'none') { activeQ = qi; break; }
            }
            if(typeof drawMinimap==='function') drawMinimap(activeQ);
            // Toast
            var toast = document.createElement('div');
            toast.textContent = '\u2705 \ubcc0\uacbd\ub428';
            toast.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);background:#1e6e32;color:#fff;padding:6px 16px;border-radius:8px;font-size:13px;font-weight:700;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,0.3)';
            document.body.appendChild(toast);
            setTimeout(function(){ toast.remove(); }, 1500);
          } else {
            alert(d.msg || '변경 실패');
          }
        }).catch(function(e){ alert('오류: '+e.message); });
    }
    function calcBench(q) {
    var panel = document.getElementById('qpanel-'+q);
    var checked = []; var unchecked = [];
    panel.querySelectorAll('input[type=checkbox]').forEach(function(cb) {
      var uid = cb.id.replace('cb-qrow-'+q+'-','');
      if(cb.checked) checked.push(parseInt(uid));
      else unchecked.push(parseInt(uid));
    });
    document.getElementById('starter_players_'+q).value = JSON.stringify(checked);
    document.getElementById('bench_players_'+q).value = JSON.stringify(unchecked);
  }

  function showQ(n){
      for(var i=1;i<=4;i++){
        document.getElementById('qpanel-'+i).style.display=i===n?'block':'none';
        var t=document.getElementById('qtab-'+i);
        t.style.background=i===n?'var(--primary)':'rgba(255,255,255,0.06)';
        t.style.color=i===n?'#0F1117':'var(--text-sub)';
      }
      if(typeof drawMinimap==='function') drawMinimap(n);
    }
    // [포메이션 프리셋] 선택 시 현재 보이는 쿼터의 선수 포지션 자동 세팅
    function applyFormation(slots, name) {
      var activeQ = 1;
      for (var i=1; i<=4; i++) {
        var p = document.getElementById('qpanel-'+i);
        if (p && p.style.display !== 'none') { activeQ = i; break; }
      }
      var panel = document.getElementById('qpanel-'+activeQ);
      if (!panel) return;
      var rows = panel.querySelectorAll('[id^="cb-qrow-"]');
      var checkedRows = [];
      rows.forEach(function(cb) { if (cb.checked) checkedRows.push(cb); });
      if (checkedRows.length === 0) {
        // 체크된 선수 없으면 전체 선수 자동 체크 (불참 제외)
        var allRows = panel.querySelectorAll('[id^="cb-qrow-"]');
        allRows.forEach(function(cb) {
          if (!cb.checked) {
            var parentDiv = cb.parentElement;
            while(parentDiv && !parentDiv.style.opacity) parentDiv = parentDiv.parentElement;
            var isAbsent = parentDiv && parentDiv.style.opacity === '0.4';
            if (!isAbsent) {
              cb.checked = true;
              var rid = cb.id.replace('cb-','');
              var hi = document.getElementById('hidden-'+rid);
              var sel = document.getElementById('sel-'+rid);
              if(hi) { hi.disabled = false; hi.value = sel ? sel.value : 'CM'; }
              if(sel) sel.style.opacity = '1';
            }
          }
        });
        checkedRows = [];
        allRows.forEach(function(cb) { if (cb.checked) checkedRows.push(cb); });
        if (checkedRows.length === 0) { alert('배정 가능한 선수가 없습니다.'); return; }
        return;
      }
      // 선호 포지션 기반 스마트 배정
      var categoryMap = {
        'GK': ['GK'],
        'LB': ['LB','CB','RB','CDM'], 'CB': ['CB','LB','RB','CDM'], 'RB': ['RB','CB','LB','CDM'],
        'CDM': ['CDM','CM','CB','LM','RM'], 'CM': ['CM','CDM','CAM','LM','RM'], 'CAM': ['CAM','CM','CDM','LM','RM'],
        'LM': ['LM','CM','RM','CDM','CAM'], 'RM': ['RM','CM','LM','CDM','CAM'],
        'LW': ['LW','ST','RW','CAM'], 'ST': ['ST','LW','RW','CAM'], 'RW': ['RW','ST','LW','CAM']
      };
      var playerData = [];
      checkedRows.forEach(function(cb) {
        var rid = cb.id.replace('cb-','');
        var sel = document.getElementById('sel-'+rid);
        var hi = document.getElementById('hidden-'+rid);
        var prefs = [];
        try { prefs = JSON.parse(cb.getAttribute('data-prefs') || '[]'); } catch(e) { prefs = []; }
        if (!Array.isArray(prefs) || prefs.length === 0) prefs = [sel ? sel.value : 'CM'];
        playerData.push({rid:rid, sel:sel, hi:hi, curPos: sel ? sel.value : 'CM', prefs: prefs, assigned: false});
      });
      var remainSlots = slots.slice();
      var assigned = [];
      // 패스별 선호도 매칭: 1순위 → 2순위 → 3순위 ...
      var maxPrefs = 0;
      playerData.forEach(function(pd) { if (pd.prefs.length > maxPrefs) maxPrefs = pd.prefs.length; });
      for (var pass = 0; pass < maxPrefs; pass++) {
        playerData.forEach(function(pd) {
          if (pd.assigned) return;
          if (pass >= pd.prefs.length) return;
          var wantPos = pd.prefs[pass];
          var idx = remainSlots.indexOf(wantPos);
          if (idx !== -1) {
            assigned.push({pd:pd, pos:wantPos});
            remainSlots.splice(idx, 1);
            pd.assigned = true;
          }
        });
      }
      // 미배정 선수: 카테고리 유사도로 남은 슬롯 배정
      playerData.forEach(function(pd) {
        if (pd.assigned) return;
        if (remainSlots.length === 0) {
          assigned.push({pd:pd, pos:pd.curPos});
          pd.assigned = true;
          return;
        }
        var basePos = pd.prefs[0] || pd.curPos;
        var similar = categoryMap[basePos] || [basePos];
        var bestIdx = -1;
        for (var si = 0; si < similar.length; si++) {
          bestIdx = remainSlots.indexOf(similar[si]);
          if (bestIdx !== -1) break;
        }
        if (bestIdx === -1) bestIdx = 0;
        var pos = remainSlots.splice(bestIdx, 1)[0];
        assigned.push({pd:pd, pos:pos});
        pd.assigned = true;
      });
      // 적용
      assigned.forEach(function(a) {
        if (a.pd.hi) a.pd.hi.value = a.pos;
        if (a.pd.sel) a.pd.sel.value = a.pos;
      });
      _currentFormation = name;
      document.getElementById('formationInfo').textContent = name + ' 적용 (' + assigned.length + '명) — 저장을 눌러주세요';
      // 미니맵 즉시 업데이트
      var aq = 1; for(var i=1;i<=4;i++){var p=document.getElementById('qpanel-'+i);if(p&&p.style.display!=='none'){aq=i;break;}} drawMinimap(aq);
      if(typeof drawMinimap==='function') drawMinimap(activeQ);
    }
    function toggleQRow(rid){
      var cb=document.getElementById('cb-'+rid);
      var hi=document.getElementById('hidden-'+rid);
      var sel=document.getElementById('sel-'+rid);
      if(cb.checked){
        hi.disabled=false;
        hi.value=sel.value;
        sel.style.opacity='1';
      } else {
        hi.disabled=true;
        sel.style.opacity='0.4';
      }
      var qMatch = rid.match(/^qrow-(\d+)-/);
      if(qMatch) drawMinimap(parseInt(qMatch[1]));
    }

    // ============================================================
    // Realtime Canvas Minimap
    // ============================================================
    // 포메이션별 고정 좌표 (각 슬롯 = [포지션, x%, y%])
    var _formationMaps = {
      '4-4-2': [
        ['GK',50,88],
        ['LB',15,72],['CB',38,72],['CB',62,72],['RB',85,72],
        ['LM',15,46],['CM',38,46],['CM',62,46],['RM',85,46],
        ['ST',35,14],['ST',65,14]
      ],
      '4-3-3': [
        ['GK',50,88],
        ['LB',15,72],['CB',38,72],['CB',62,72],['RB',85,72],
        ['CM',30,46],['CM',50,46],['CM',70,46],
        ['LW',18,16],['ST',50,14],['RW',82,16]
      ],
      '3-5-2': [
        ['GK',50,88],
        ['CB',25,72],['CB',50,72],['CB',75,72],
        ['LM',12,46],['CDM',35,52],['CM',50,42],['CDM',65,52],['RM',88,46],
        ['ST',35,14],['ST',65,14]
      ],
      '3-4-3': [
        ['GK',50,88],
        ['CB',25,72],['CB',50,72],['CB',75,72],
        ['LM',15,46],['CM',38,46],['CM',62,46],['RM',85,46],
        ['LW',18,16],['ST',50,14],['RW',82,16]
      ],
      '4-2-3-1': [
        ['GK',50,88],
        ['LB',15,72],['CB',38,72],['CB',62,72],['RB',85,72],
        ['CDM',38,56],['CDM',62,56],
        ['LM',18,36],['CAM',50,36],['RM',82,36],
        ['ST',50,14]
      ],
      '5-3-2': [
        ['GK',50,88],
        ['LB',10,72],['CB',30,72],['CB',50,72],['CB',70,72],['RB',90,72],
        ['CM',30,46],['CM',50,46],['CM',70,46],
        ['ST',35,14],['ST',65,14]
      ]
    };
    // 풋살용
    _formationMaps['4-1-4-1'] = [
      ['GK',50,88],
      ['LB',15,72],['CB',38,72],['CB',62,72],['RB',85,72],
      ['CDM',50,58],
      ['LM',12,40],['CM',38,40],['CM',62,40],['RM',88,40],
      ['ST',50,14]
    ];
    _formationMaps['4-5-1'] = [
      ['GK',50,88],
      ['LB',15,72],['CB',38,72],['CB',62,72],['RB',85,72],
      ['LM',12,46],['CM',30,46],['CM',50,46],['CM',70,46],['RM',88,46],
      ['ST',50,14]
    ];
    _formationMaps['3-4-1-2'] = [
      ['GK',50,88],
      ['CB',25,72],['CB',50,72],['CB',75,72],
      ['LM',15,50],['CM',38,50],['CM',62,50],['RM',85,50],
      ['CAM',50,32],
      ['ST',35,14],['ST',65,14]
    ];
    _formationMaps['4-1-2-3'] = [
      ['GK',50,88],
      ['LB',15,72],['CB',38,72],['CB',62,72],['RB',85,72],
      ['CDM',50,56],
      ['CM',35,42],['CM',65,42],
      ['LW',18,16],['ST',50,14],['RW',82,16]
    ];
    _formationMaps['3-3-4'] = [
      ['GK',50,88],
      ['CB',25,72],['CB',50,72],['CB',75,72],
      ['LM',25,48],['CM',50,48],['RM',75,48],
      ['LW',15,18],['ST',38,14],['ST',62,14],['RW',85,18]
    ];
    _formationMaps['풋살2-2'] = [
      ['GK',50,85],['LB',30,55],['RB',70,55],['LW',30,22],['RW',70,22]
    ];
    _formationMaps['풋살1-2-1'] = [
      ['GK',50,85],['CB',50,62],['LM',25,42],['RM',75,42],['ST',50,18]
    ];

    var _minimapPosColors = {
      GK:'#ff9500',LB:'#3a9ef5',CB:'#3a9ef5',RB:'#3a9ef5',
      CDM:'#00c87a',LM:'#00ff88',CM:'#00ff88',RM:'#00ff88',
      CAM:'#ffd60a',LW:'#ff6b6b',ST:'#ff6b6b',RW:'#ff6b6b'
    };
    var _posCategoryMap = {DF:'CB',MF:'CM',FW:'ST'};
    var _currentFormation = '4-3-3';

    // 팀원 역할 + 통계 데이터
    var _newMembers = {<?php
      foreach($myTeamMembers as $_tm) {
        $joined = $pdo->prepare("SELECT created_at FROM team_members WHERE user_id=? AND team_id=? AND status='active'");
        $joined->execute([(int)$_tm['user_id'], $myTeam]);
        $joinDate = $joined->fetchColumn();
        if($joinDate && (time() - strtotime($joinDate)) < 30*86400) echo (int)$_tm['user_id'].':true,';
      }
    ?>};
    var _teamRoles = {<?php
      foreach($myTeamMembers as $_tm) {
        echo (int)$_tm['user_id'].':"'.($_tm['role']??'player').'",';
      }
    ?>};
    var _teamStats = {<?php
      $__stats = $pdo->prepare("SELECT user_id, SUM(goals) as g, SUM(assists) as a FROM match_player_records WHERE team_id=? GROUP BY user_id");
      $__stats->execute([$myTeam]);
      while($__s = $__stats->fetch()) {
        echo (int)$__s['user_id'].':{g:'.(int)$__s['g'].',a:'.(int)$__s['a'].'},';
      }
    ?>};
    var _teamMom = {<?php
      $__mom = $pdo->prepare("SELECT id, mom_count FROM users WHERE id IN (SELECT user_id FROM team_members WHERE team_id=? AND status='active')");
      $__mom->execute([$myTeam]);
      while($__m = $__mom->fetch()) {
        if((int)$__m['mom_count'] > 0) echo (int)$__m['id'].':'.(int)$__m['mom_count'].',';
      }
    ?>};

    function drawMinimap(quarter) {
      var canvas = document.getElementById('minimapCanvas-'+quarter);
      if (!canvas) return;
      var ctx = canvas.getContext('2d');
      var W = canvas.width, H = canvas.height;

      ctx.clearRect(0, 0, W, H);

      // Pitch background gradient
      var grad = ctx.createLinearGradient(0, 0, 0, H);
      grad.addColorStop(0, '#1a5e2a');
      grad.addColorStop(0.5, '#1e6e32');
      grad.addColorStop(1, '#1a5e2a');
      ctx.fillStyle = grad;
      ctx.beginPath();
      var r = 10;
      ctx.moveTo(r, 0); ctx.lineTo(W-r, 0); ctx.quadraticCurveTo(W, 0, W, r);
      ctx.lineTo(W, H-r); ctx.quadraticCurveTo(W, H, W-r, H);
      ctx.lineTo(r, H); ctx.quadraticCurveTo(0, H, 0, H-r);
      ctx.lineTo(0, r); ctx.quadraticCurveTo(0, 0, r, 0);
      ctx.fill();

      // Pitch lines
      ctx.strokeStyle = 'rgba(255,255,255,0.35)';
      ctx.lineWidth = 1.5;
      ctx.strokeRect(10, 10, W-20, H-20);
      ctx.strokeStyle = 'rgba(255,255,255,0.25)';
      ctx.lineWidth = 1;
      ctx.beginPath(); ctx.moveTo(10, H/2); ctx.lineTo(W-10, H/2); ctx.stroke();
      ctx.beginPath(); ctx.arc(W/2, H/2, 25, 0, Math.PI*2); ctx.stroke();
      ctx.fillStyle = 'rgba(255,255,255,0.4)';
      ctx.beginPath(); ctx.arc(W/2, H/2, 2, 0, Math.PI*2); ctx.fill();
      // Top penalty
      ctx.strokeStyle = 'rgba(255,255,255,0.2)';
      ctx.strokeRect(80, 10, 140, 55);
      ctx.strokeStyle = 'rgba(255,255,255,0.15)';
      ctx.strokeRect(115, 10, 70, 22);
      // Bottom penalty
      ctx.strokeStyle = 'rgba(255,255,255,0.2)';
      ctx.strokeRect(80, H-65, 140, 55);
      ctx.strokeStyle = 'rgba(255,255,255,0.15)';
      ctx.strokeRect(115, H-32, 70, 22);

      // Gather players from DOM
      var panel = document.getElementById('qpanel-'+quarter);
      if (!panel) return;

      var starters = [];
      var benchPlayers = [];

      var checkboxes = panel.querySelectorAll('input[type=checkbox][id^="cb-qrow-"]');
      checkboxes.forEach(function(cb) {
        if (!cb.checked) return;
        var rid = cb.id.replace('cb-', '');
        var sel = document.getElementById('sel-' + rid);
        if (!sel) return;
        var pos = sel.value;
        var label = panel.querySelector('label[for="' + cb.id + '"]');
        var name = label ? label.textContent.trim().split(/\s+/)[0] : '?';
        var nickname = cb.getAttribute('data-nick') || name;
        var photo = cb.getAttribute('data-photo') || '';
        var uid = rid.replace('qrow-' + quarter + '-', '');
        var benchBtn = panel.querySelector('.qbench-btn[data-uid="' + uid + '"]');
        var isBench = false;
        if (benchBtn) {
          isBench = benchBtn.textContent.trim() === '\uD6C4\uBCF4';
        }
        var mapped = _posCategoryMap[pos] || pos;
        // 포지션 매핑만 적용
        if (isBench) {
          benchPlayers.push({name: name, nick: nickname, photo: photo, pos: mapped, uid: parseInt(uid)});
        } else {
          starters.push({name: name, nick: nickname, photo: photo, pos: mapped, uid: parseInt(uid)});
        }
      });

      // 포메이션 맵에서 고정 슬롯을 가져와서 선수를 1:1 배치
      var fMap = _formationMaps[_currentFormation] || _formationMaps['4-3-3'];
      // 슬롯 복사
      var slots = fMap.map(function(s){ return {pos:s[0], x:s[1], y:s[2], taken:false}; });

      // 1단계: 선수 포지션과 정확히 일치하는 슬롯에 배치
      var placed = [];
      starters.forEach(function(pl) {
        var mapped = _posCategoryMap[pl.pos] || pl.pos;
        for(var si=0; si<slots.length; si++){
          if(!slots[si].taken && slots[si].pos === mapped){
            placed.push({name:pl.name, nick:pl.nick, photo:pl.photo, x:slots[si].x, y:slots[si].y, pos:mapped, uid:pl.uid});
            slots[si].taken = true;
            pl._placed = true;
            break;
          }
        }
      });
      // 2단계: 남은 선수는 남은 슬롯에 순서대로
      starters.forEach(function(pl) {
        if(pl._placed) return;
        for(var si=0; si<slots.length; si++){
          if(!slots[si].taken){
            placed.push({name:pl.name, nick:pl.nick, photo:pl.photo, x:slots[si].x, y:slots[si].y, pos:slots[si].pos, uid:pl.uid});
            slots[si].taken = true;
            pl._placed = true;
            break;
          }
        }
        // 슬롯 다 찼으면 센터에
        if(!pl._placed) placed.push({name:pl.name, nick:pl.nick, photo:pl.photo, x:50, y:50, pos:pl.pos, uid:pl.uid});
      });

      // 그리기
      placed.forEach(function(pl) {
        var cx = (pl.x / 100) * W;
        var cy = (pl.y / 100) * H;
        var color = _minimapPosColors[pl.pos] || '#888';

          // 프로필사진 또는 닉네임 원
          if (pl.photo) {
            // 프로필사진
            var img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function() {
              ctx.save();
              ctx.beginPath();
              ctx.arc(cx, cy, 20, 0, Math.PI * 2);
              ctx.clip();
              ctx.drawImage(img, cx-20, cy-20, 40, 40);
              ctx.restore();
              ctx.strokeStyle = color;
              ctx.lineWidth = 3;
              ctx.beginPath();
              ctx.arc(cx, cy, 20, 0, Math.PI * 2);
              ctx.stroke();
            };
            img.src = pl.photo;
          } else {
            // 닉네임 원
            ctx.save();
            ctx.shadowColor = 'rgba(0,0,0,0.4)';
            ctx.shadowBlur = 3;
            ctx.shadowOffsetY = 1;
            ctx.beginPath();
            ctx.arc(cx, cy, 20, 0, Math.PI * 2);
            ctx.fillStyle = color;
            ctx.fill();
            ctx.restore();
            ctx.strokeStyle = 'rgba(255,255,255,0.9)';
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.arc(cx, cy, 20, 0, Math.PI * 2);
            ctx.stroke();
            var circLabel = (pl.nick || pl.name).substring(0, 3);
            ctx.fillStyle = '#fff';
            ctx.font = 'bold 12px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(circLabel, cx, cy);
          }

          // 이름 (아래)
          ctx.font = 'bold 11px sans-serif';
          ctx.fillStyle = '#fff';
          ctx.textAlign = 'center';
          ctx.fillText(pl.name.substring(0, 3), cx, cy + 28);

          // 신입 표시
          if(_newMembers[pl.uid]) {
            ctx.fillStyle = '#00ff88';
            ctx.font = 'bold 7px sans-serif';
            ctx.fillText('NEW', cx, cy + 38);
          }


      });

      // 빈 슬롯도 점선 원으로 표시
      slots.forEach(function(s) {
        if(s.taken) return;
        var cx = (s.x / 100) * W;
        var cy = (s.y / 100) * H;
        ctx.beginPath();
        ctx.arc(cx, cy, 16, 0, Math.PI*2);
        ctx.strokeStyle = 'rgba(255,255,255,0.15)';
        ctx.setLineDash([3,3]);
        ctx.lineWidth = 1;
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.fillStyle = 'rgba(255,255,255,0.15)';
        ctx.font = '8px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(s.pos, cx, cy);
      });

      // 사이드바: 감독/코치 + 주요선수
      var sidebar = document.getElementById('minimapSidebar-' + quarter);
      if (sidebar) {
        var shtml = '';
        // 감독/코치 (기본) + 기타 임원 (접기)
        var mainStaff = [];
        var otherStaff = [];
        var roleLabels = {coach:'감독',manager:'매니저',director:'단장',owner:'구단주',president:'회장',vice_captain:'부주장',captain:'주장'};
        var roleColors = {coach:'#e74c3c',manager:'#9b59b6',director:'#e67e22',owner:'#f1c40f',president:'#1abc9c'};
        Object.keys(_teamRoles).forEach(function(uid) {
          var role = _teamRoles[uid];
          if(!roleLabels[role] || role==='player') return;
          var cb = panel.querySelector('[id$="-'+uid+'"]');
          var nm = '?';
          if(cb) { var l = panel.querySelector('label[for="'+cb.id+'"]'); if(l) nm = l.textContent.trim().split(/\s+/)[0]; }
          var item = {name:nm, role:role, color:roleColors[role]||'#888'};
          if(role === 'coach') mainStaff.push(item);
          else otherStaff.push(item);
        });
        if(mainStaff.length > 0) {
          shtml += '<div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:8px">';
          shtml += '<div style="font-size:11px;color:rgba(255,255,255,0.6);margin-bottom:6px;text-align:center;font-weight:800">감독/코치</div>';
          mainStaff.forEach(function(s) {
            shtml += '<div style="display:flex;align-items:center;gap:4px;margin-bottom:4px">';
            shtml += '<div style="width:28px;height:28px;border-radius:50%;background:'+s.color+';color:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0">'+s.name.substring(0,1)+'</div>';
            shtml += '<div><div style="font-size:11px;color:#fff;font-weight:700">'+s.name.substring(0,3)+'</div><div style="font-size:8px;color:rgba(255,255,255,0.4)">'+roleLabels[s.role]+'</div></div>';
            shtml += '</div>';
          });
          shtml += '</div>';
        }
        if(otherStaff.length > 0) {
          shtml += '<div style="margin-top:4px">';
          shtml += '<div onclick="var el=this.nextElementSibling;el.style.display=el.style.display===\'none\'?\'block\':\'none\'" style="font-size:9px;color:rgba(255,255,255,0.3);text-align:center;cursor:pointer;padding:4px;border:1px dashed rgba(255,255,255,0.1);border-radius:6px">임원진 '+otherStaff.length+'명 보기</div>';
          shtml += '<div style="display:none;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.05);border-radius:6px;padding:6px;margin-top:3px">';
          otherStaff.forEach(function(s) {
            shtml += '<div style="display:flex;align-items:center;gap:3px;margin-bottom:3px">';
            shtml += '<div style="width:20px;height:20px;border-radius:50%;background:'+s.color+';color:#fff;display:flex;align-items:center;justify-content:center;font-size:7px;font-weight:700;flex-shrink:0">'+s.name.substring(0,1)+'</div>';
            shtml += '<div style="font-size:9px;color:rgba(255,255,255,0.5)">'+s.name.substring(0,3)+' <span style="font-size:7px;color:rgba(255,255,255,0.3)">'+roleLabels[s.role]+'</span></div>';
            shtml += '</div>';
          });
          shtml += '</div></div>';
        }
        // 주요선수 통계
        var mvpList = [];
        Object.keys(_teamStats).forEach(function(uid) {
          var s = _teamStats[uid];
          var m = _teamMom[uid] || 0;
          if(s.g >= 2 || s.a >= 2 || m >= 1) {
            var cb = panel.querySelector('[id$="-'+uid+'"]');
            var nm = '?';
            if(cb) { var l = panel.querySelector('label[for="'+cb.id+'"]'); if(l) nm = l.textContent.trim().split(/\s+/)[0]; }
            mvpList.push({name:nm, goals:s.g, assists:s.a, mom:m});
          }
        });

        if(mvpList.length > 0) {
          mvpList.sort(function(a,b){ return (b.goals+b.assists+b.mom) - (a.goals+a.assists+a.mom); });
          shtml += '<div style="background:rgba(255,180,0,0.03);border:1px solid rgba(255,180,0,0.1);border-radius:8px;padding:8px;margin-top:6px">';
          shtml += '<div style="font-size:8px;color:rgba(255,180,0,0.6);margin-bottom:4px;text-align:center">KEY PLAYER</div>';
          mvpList.slice(0,4).forEach(function(p) {
            shtml += '<div style="margin-bottom:6px">';
            shtml += '<div style="font-size:8px;color:#fff;font-weight:600">'+p.name.substring(0,3)+'</div>';
            shtml += '<div style="display:flex;gap:3px;flex-wrap:wrap">';
            if(p.goals>0) shtml += '<span style="font-size:7px;padding:1px 3px;border-radius:3px;background:rgba(255,180,0,0.2);color:#ffb400">'+p.goals+'G</span>';
            if(p.assists>0) shtml += '<span style="font-size:7px;padding:1px 3px;border-radius:3px;background:rgba(58,158,245,0.2);color:#3a9ef5">'+p.assists+'A</span>';
            if(p.mom>0) shtml += '<span style="font-size:7px;padding:1px 3px;border-radius:3px;background:rgba(255,107,107,0.2);color:#ff6b6b">MOM'+p.mom+'</span>';
            shtml += '</div></div>';
          });
          shtml += '</div>';
        }

        sidebar.innerHTML = shtml;
      }

      // 벤치석 시각화 (미니맵 아래)
      var benchDiv = document.getElementById('minimapBench-' + quarter);
      if (benchDiv) {
        if (benchPlayers.length > 0) {
          benchDiv.style.display = 'block';
          var html = '<div style="background:rgba(255,180,0,0.05);border:1px solid rgba(255,180,0,0.2);border-radius:10px;padding:8px;margin-top:8px">';
          html += '<div style="font-size:10px;font-weight:700;color:#ffb400;margin-bottom:6px;text-align:center">\uD83E\uDE91 \uD6C4\uBCF4 (' + benchPlayers.length + '\uBA85)</div>';
          html += '<div style="display:flex;justify-content:center;gap:10px;flex-wrap:wrap">';
          benchPlayers.forEach(function(bp) {
            var bcolor = _minimapPosColors[bp.pos] || '#ffb400';
            html += '<div style="text-align:center">';
            html += '<div style="width:24px;height:24px;border-radius:50%;background:'+bcolor+';opacity:0.6;color:#fff;display:flex;align-items:center;justify-content:center;font-size:8px;font-weight:700;margin:0 auto;border:1px dashed rgba(255,255,255,0.3)">'+bp.name.substring(0,2)+'</div>';
            html += '<div style="font-size:8px;color:#ffb400;margin-top:2px">'+bp.name.substring(0,3)+'</div>';
            html += '<div style="font-size:7px;color:rgba(255,180,0,0.5)">'+bp.pos+'</div>';
            html += '</div>';
          });
          html += '</div></div>';
          benchDiv.innerHTML = html;
        } else {
          benchDiv.style.display = 'none';
          benchDiv.innerHTML = '';
        }
      }
    }

    // Draw all minimaps on page load
    function drawAllMinimaps() {
      for (var q = 1; q <= 4; q++) {
        if (document.getElementById('minimapCanvas-' + q)) {
          drawMinimap(q);
        }
      }
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', drawAllMinimaps);
    } else {
      drawAllMinimaps();
    }

    </script>
    <?php endif; ?>
  </div></div>
  <?php endif; ?>

  <!-- ── 대리 출석 + 라인업 편성 (캡틴 전용) ────────────────────── -->
  <?php if (isCaptain() && $isMine && $myTeamMembers):
    $pendingMembers = array_filter($myTeamMembers, fn($tm) => !isset($attendanceMap[$tm['user_id']]) || ($attendanceMap[$tm['user_id']]['status'] ?? '') === 'PENDING');
    $attendCount    = count(array_filter($myTeamMembers, fn($tm) => isset($attendanceMap[$tm['user_id']]) && $attendanceMap[$tm['user_id']]['status'] === 'ATTEND'));
  ?>
  <div class="card" style="margin-bottom:12px"><div class="card-body">
    <p class="section-title" style="margin-bottom:8px"><i class="bi bi-person-check-fill" style="color:var(--primary)"></i> 팀원 출석 관리</p>
    <div style="font-size:11px;color:var(--text-sub);margin-bottom:10px">참석/불참을 선택한 뒤 <b>💾 일괄 저장</b>을 누르세요.</div>

    <div id="batchAttendArea">
    <?php foreach($myTeamMembers as $tm):
      $att = $attendanceMap[$tm['user_id']] ?? null;
      $curStatus = $att['status'] ?? 'PENDING';
      $curBench = (int)($att['is_bench'] ?? 0);
    ?>
    <div class="batch-row" data-uid="<?=$tm['user_id']?>" style="display:flex;align-items:center;gap:6px;padding:8px 0;border-bottom:1px solid var(--border);flex-wrap:wrap">
      <div style="flex:1;min-width:80px">
        <span style="font-weight:600;font-size:13px"><?=h($tm['name'])?></span>
        <span style="font-size:10px;color:var(--text-sub);margin-left:4px"><?=$tm['position']??''?></span>
      </div>
      <!-- 참석 버튼 -->
      <div style="display:flex;gap:3px">
        <?php foreach(['ATTEND'=>'참석','ABSENT'=>'불참','PENDING'=>'미정'] as $v=>$l): ?>
        <button type="button" class="btn btn-sm att-btn <?=$curStatus===$v?($v==='ATTEND'?'btn-primary':($v==='ABSENT'?'':'btn-outline')):'btn-outline'?>"
          style="min-height:28px;padding:0 6px;font-size:10px;<?=$curStatus===$v&&$v==='ABSENT'?'background:rgba(255,59,48,0.15);color:#ff4d4d;border-color:#ff4d4d':''?>"
          data-vote="<?=$v?>"
          onclick="selectVote(this,'<?=$v?>')">
          <?=$l?>
        </button>
        <?php endforeach; ?>
      </div>

    </div>
    <?php endforeach; ?>
    </div>

    <!-- 일괄 저장 버튼 -->
    <div style="margin-top:14px;display:flex;gap:8px">
      <button type="button" class="btn btn-primary" style="flex:1;font-weight:700;font-size:14px;padding:12px" onclick="saveBatchAttendance(<?=$id?>)">
        💾 일괄 저장
      </button>
    </div>
    <div id="batchSaveMsg" style="font-size:12px;color:var(--primary);margin-top:8px;display:none"></div>
  </div></div>
  <?php endif; ?>

  <!-- 기존 홈팀 캡틴용 대기 신청 처리 (allRequests로 통합됐으므로 유지하되 숨김) -->  <!-- 기존 홈팀 캡틴용 대기 신청 처리 (allRequests로 통합됐으므로 유지하되 숨김) -->
  <?php if($requests): /* pending 신청만 별도 강조 (allRequests가 없을 때 폴백) */ endif; ?>

  <!-- 신청 버튼 -->
  <?php if($match['status']==='open'&&$myTeam&&$myTeam!=$match['home_team_id']): ?>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px">
    <!-- 대화하며 신청하기 (먼저 대화방 생성 → 그 안에서 신청) -->
    <?php
      // 홈팀 캡틴 ID 조회
      $homeCaptainQ = $pdo->prepare("SELECT user_id FROM team_members WHERE team_id=? AND role='captain' LIMIT 1");
      $homeCaptainQ->execute([$match['home_team_id']]); $homeCaptain = $homeCaptainQ->fetchColumn() ?: 0;
    ?>
    <form method="POST">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="start_chat">
      <input type="hidden" name="match_id" value="<?=$id?>">
      <input type="hidden" name="target_user_id" value="<?=$homeCaptain?>">
      <button type="submit" class="btn btn-outline btn-w" style="min-height:52px">
        <i class="bi bi-chat-dots-fill" style="color:var(--primary)"></i> 대화하며 신청
      </button>
    </form>
    <form method="POST">
      <?=csrfInput()?><input type="hidden" name="action" value="apply_match">
      <input type="hidden" name="match_id" value="<?=$id?>">
      <button type="submit" class="btn btn-primary btn-w"><i class="bi bi-send"></i> 바로 신청</button>
    </form>
  </div>
  <?php endif; ?>

  <?php
  // ── 용병 신청 섹션 ──
  $allowMerc = !empty($match['allow_mercenary']);
  if ($allowMerc && me() && !$isMine):
      $myMercReq = null;
      $mercReqStmt = $pdo->prepare("SELECT * FROM mercenary_requests WHERE match_id=? AND user_id=? LIMIT 1");
      $mercReqStmt->execute([$id, me()['id']]); $myMercReq = $mercReqStmt->fetch();
      $mercReqs = [];
      if (isCaptain() && in_array(myTeamId(), [$match['home_team_id'], $match['away_team_id']])) {
          $s = $pdo->prepare("SELECT mr.*,u.name AS uname,u.position,u.manner_score FROM mercenary_requests mr JOIN users u ON u.id=mr.user_id WHERE mr.match_id=? AND mr.team_id=? AND mr.status='pending'");
          $s->execute([$id, myTeamId()]); $mercReqs = $s->fetchAll();
      }
  ?>
  <div class="card" style="margin-bottom:12px"><div class="card-body">
    <p class="section-title"><i class="bi bi-lightning-charge-fill" style="color:var(--primary)"></i> 용병 모집 중</p>
    <?php if($myMercReq): ?>
      <div style="text-align:center;padding:10px 0;color:var(--text-sub)">
        <?php if($myMercReq['status']==='pending'): ?>
          <i class="bi bi-clock" style="color:var(--warning)"></i> 신청 중 (승인 대기)
        <?php elseif($myMercReq['status']==='accepted'): ?>
          <i class="bi bi-check-circle-fill" style="color:var(--primary)"></i> 용병 신청 수락됨!
        <?php else: ?>
          <i class="bi bi-x-circle" style="color:var(--danger)"></i> 신청 거절됨
        <?php endif; ?>
      </div>
    <?php else: ?>
      <button class="btn btn-primary btn-w" data-tf-toggle="mercApplyForm">
        <i class="bi bi-lightning-charge"></i> 용병 신청하기
      </button>
      <div id="mercApplyForm" class="tf-collapse" style="margin-top:10px">
        <form method="POST">
          <?=csrfInput()?>
          <input type="hidden" name="action" value="mercenary_apply">
          <input type="hidden" name="match_id" value="<?=$id?>">
          <input type="hidden" name="team_id" value="<?=$match['home_team_id']?>">
          <textarea name="message" class="form-control" placeholder="간단한 자기소개 및 포지션을 입력하세요" rows="3" required></textarea>
          <button type="submit" class="btn btn-primary btn-w" style="margin-top:8px">신청 완료</button>
        </form>
      </div>
    <?php endif; ?>
    <?php if($mercReqs): ?>
      <hr class="divider" style="margin:12px 0">
      <p style="font-size:13px;font-weight:600;margin-bottom:8px">용병 신청자 <span class="badge badge-red"><?=count($mercReqs)?></span></p>
      <?php foreach($mercReqs as $mr): ?>
      <div class="list-item">
        <div>
          <div style="font-weight:600"><?=h($mr['uname'])?> <span style="font-size:12px;color:var(--text-sub)"><?=h($mr['position']??'')?></span></div>
          <div style="font-size:12px;color:var(--text-sub)">매너 <?=$mr['manner_score']?:'-'?> · <?=h($mr['message'])?></div>
        </div>
        <div style="display:flex;gap:6px">
          <form method="POST"><?=csrfInput()?><input type="hidden" name="action" value="mercenary_respond">
            <input type="hidden" name="req_id" value="<?=$mr['id']?>"><input type="hidden" name="status" value="accepted">
            <button type="submit" class="btn btn-primary btn-sm">수락</button></form>
          <form method="POST"><?=csrfInput()?><input type="hidden" name="action" value="mercenary_respond">
            <input type="hidden" name="req_id" value="<?=$mr['id']?>"><input type="hidden" name="status" value="rejected">
            <button type="submit" class="btn btn-ghost btn-sm">거절</button></form>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div></div>
  <?php endif; ?>

  <?php
  // ── 매너 평가 섹션 ── (경기 완료 후, 참가자에게 표시)
  $isFinished = in_array($match['status'], ['finished','completed']);
  if ($isFinished && $isMine && me()):
      $myReview = $pdo->prepare("SELECT id FROM reviews WHERE match_id=? AND reviewer_id=? LIMIT 1");
      $myReview->execute([$id, me()['id']]); $myReview = $myReview->fetch();
      $oppTeamId = ($myTeam == $match['home_team_id']) ? $match['away_team_id'] : $match['home_team_id'];
      $oppTeam   = $pdo->prepare("SELECT id,name FROM teams WHERE id=?");
      $oppTeam->execute([$oppTeamId]); $oppTeam = $oppTeam->fetch();
  ?>
  <?php if(!$myReview && $oppTeam): ?>
  <div class="card" style="margin-bottom:12px"><div class="card-body">
    <p class="section-title"><i class="bi bi-star-fill" style="color:var(--warning)"></i> 매너 평가</p>
    <p style="font-size:13px;color:var(--text-sub);margin-bottom:12px"><?=h($oppTeam['name'])?> 팀을 평가해주세요</p>
    <form method="POST">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="submit_review">
      <input type="hidden" name="match_id" value="<?=$id?>">
      <input type="hidden" name="target_type" value="team">
      <input type="hidden" name="target_id" value="<?=$oppTeam['id']?>">
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px">
        <div style="text-align:center">
          <div style="font-size:12px;color:var(--text-sub);margin-bottom:6px">매너</div>
          <select name="manner_score" class="form-select" style="text-align:center">
            <?php for($s=5;$s>=1;$s--): ?><option value="<?=$s?>"><?=$s?>점</option><?php endfor; ?>
          </select>
        </div>
        <div style="text-align:center">
          <div style="font-size:12px;color:var(--text-sub);margin-bottom:6px">시간준수</div>
          <select name="attendance_score" class="form-select" style="text-align:center">
            <?php for($s=5;$s>=1;$s--): ?><option value="<?=$s?>"><?=$s?>점</option><?php endfor; ?>
          </select>
        </div>
        <div style="text-align:center">
          <div style="font-size:12px;color:var(--text-sub);margin-bottom:6px">실력수준</div>
          <select name="skill_score" class="form-select" style="text-align:center">
            <?php for($s=5;$s>=1;$s--): ?><option value="<?=$s?>"><?=$s?>점</option><?php endfor; ?>
          </select>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:8px 0">
        <input type="checkbox" name="invite_again" value="1" id="inviteAgain" style="width:18px;height:18px">
        <label for="inviteAgain" style="margin:0;cursor:pointer;font-size:14px">다시 만나고 싶은 팀이에요</label>
      </div>
      <textarea name="comment" class="form-control" placeholder="한 줄 평가 (선택사항)" rows="2" style="margin-bottom:12px"></textarea>
      <button type="submit" class="btn btn-primary btn-w"><i class="bi bi-star"></i> 평가 등록</button>
    </form>
  </div></div>
  <?php elseif($myReview): ?>
  <div style="text-align:center;padding:10px 0;color:var(--primary);font-size:14px">
    <i class="bi bi-check-circle-fill"></i> 매너 평가 완료
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- [4단계] 캡틴: 긴급 SOS 버튼 (경기 48시간 이내 + 인원 부족 시) -->
  <?php
  $hoursToMatch = (strtotime($match['match_date'].' '.$match['match_time']) - time()) / 3600;
  $needSos = isCaptain() && $isMine && $hoursToMatch >= 0 && $hoursToMatch <= 48 && in_array($match['status'],['confirmed','open','request_pending','checkin_open']);
  $myAttCount = 0;
  if ($needSos) {
    foreach ($attendanceMap as $a) if ($a['status']==='ATTEND') $myAttCount++;
  }
  if ($needSos && $myAttCount < (int)$match['max_players']):
    $activeSos = $pdo->prepare("SELECT id, needed_count, position_needed, message, created_at FROM sos_alerts WHERE match_id=? AND team_id=? AND status='ACTIVE' ORDER BY id DESC LIMIT 1");
    $activeSos->execute([$id, myTeamId()]); $activeSosRow = $activeSos->fetch();
  ?>
  <div class="card" style="margin-top:14px;border-color:#ff4d6d;background:rgba(255,77,109,0.05)"><div class="card-body">
    <p class="section-title" style="color:#ff4d6d;margin-bottom:10px">🚨 긴급 용병 호출</p>
    <?php if($activeSosRow): ?>
    <div style="background:var(--bg-surface-alt);padding:10px 12px;border-radius:8px;font-size:13px">
      <div style="font-weight:700;margin-bottom:4px"><?=$activeSosRow['needed_count']?>명 모집 중<?=$activeSosRow['position_needed']?' · '.h($activeSosRow['position_needed']):''?></div>
      <div style="color:var(--text-sub);font-size:12px"><?=h($activeSosRow['message'])?></div>
      <div style="font-size:10px;color:var(--text-sub);margin-top:4px"><?=date('m/d H:i', strtotime($activeSosRow['created_at']))?> 발송 · 지역 용병에게 알림 전송됨</div>
    </div>
    <?php else: ?>
    <div style="font-size:12px;color:var(--text-sub);margin-bottom:10px">현재 참석 <?=$myAttCount?>명 / 정원 <?=(int)$match['max_players']?>명. 지역 용병에게 긴급 알림을 보낼 수 있어요.</div>
    <a href="?page=sos_create&match_id=<?=$id?>" class="btn btn-w" style="background:#ff4d6d;color:#fff">🚨 SOS 발송하기</a>
    <?php endif; ?>
  </div></div>
  <?php endif; ?>

  <!-- [2단계] 골든타임 루프 카드 - status=result_pending|completed 일 때 -->
  <?php if($isMine && in_array($match['status'],['result_pending','completed'])):
    $evalStep = (int)$match['evaluation_step']; ?>
  <div class="card tf-eval-card" id="evalLoopCard" style="margin-top:14px;border-color:var(--primary);box-shadow:0 0 0 2px rgba(0,255,136,0.12)"><div class="card-body">
    <p class="section-title" style="margin-bottom:10px">🔄 경기 후 진행 단계</p>
    <!-- 단계 진행 표시 -->
    <div style="display:flex;gap:6px;margin-bottom:14px">
      <?php foreach([1=>'평가',2=>'MOM',3=>'완료'] as $stepN=>$lbl): ?>
      <div style="flex:1;height:6px;border-radius:3px;background:<?=$evalStep>=$stepN?'var(--primary)':'var(--bg-surface-alt)'?>"></div>
      <?php endforeach; ?>
    </div>
    <?php if($evalStep < 1): ?>
    <!-- 1단계: 평가 -->
    <div style="text-align:center">
      <div style="font-size:24px;margin-bottom:6px">⭐</div>
      <div style="font-weight:700;margin-bottom:4px">오늘 경기 어떠셨나요?</div>
      <div style="font-size:12px;color:var(--text-sub);margin-bottom:12px">상대팀 평가 완료 시 결과가 공식 반영됩니다</div>
      <?php if(isCaptain()): ?>
      <a href="?page=team_eval&match_id=<?=$id?>" class="btn btn-primary btn-w">상대팀 평가하기</a>
      <?php else: ?>
      <div style="font-size:12px;color:var(--text-sub)">캡틴이 평가를 완료하면 다음 단계로 진행됩니다.</div>
      <?php endif; ?>
    </div>
    <?php elseif($evalStep < 2): ?>
    <!-- 2단계: MOM 투표 -->
    <div style="text-align:center">
      <div style="font-size:24px;margin-bottom:6px">🏆</div>
      <div style="font-weight:700;margin-bottom:4px">오늘의 MOM을 뽑아주세요!</div>
      <div style="font-size:12px;color:var(--text-sub);margin-bottom:12px">참석자들의 투표로 베스트 플레이어를 정합니다</div>
      <a href="?page=mom_vote&match_id=<?=$id?>" class="btn btn-primary btn-w">MOM 투표하기</a>
    </div>
    <?php else: ?>
    <!-- 3단계: 다음 매치 CTA -->
    <div style="text-align:center">
      <div style="font-size:24px;margin-bottom:6px">🎯</div>
      <div style="font-weight:700;margin-bottom:4px">투표 완료!</div>
      <div style="font-size:12px;color:var(--text-sub);margin-bottom:12px">다음 매치 잡아볼까요?</div>
      <div style="display:flex;flex-direction:column;gap:6px">
        <?php if(isCaptain()): ?>
        <a href="?page=matches" class="btn btn-primary btn-w">⚽ 매치 만들기</a>
        <?php endif; ?>
        <a href="?page=mercenaries" class="btn btn-outline btn-w">🔥 용병 등록하기</a>
        <a href="?page=matches" class="btn btn-ghost btn-w">근처 매치 보기</a>
      </div>
    </div>
    <?php endif; ?>
  </div></div>

  <!-- 경기 후 단계별 팝업 (최초 1회, sessionStorage 기준) -->
  <?php if ($evalStep < 2): ?>
  <script>
  (function(){
    var key = 'tf_eval_popup_<?=$id?>_<?=$evalStep?>';
    if (sessionStorage.getItem(key)) return;
    sessionStorage.setItem(key, '1');
    var step = <?=$evalStep?>;
    var isCaptain = <?= isCaptain() ? 'true' : 'false' ?>;
    var title, desc, btnHtml;
    if (step < 1) {
      title = '⭐ 경기 평가가 남아있어요';
      desc = '상대팀을 평가해야 결과가 공식 반영됩니다.';
      btnHtml = isCaptain
        ? '<a href="?page=team_eval&match_id=<?=$id?>" class="btn btn-primary btn-w">상대팀 평가하기</a>'
        : '<div style="font-size:12px;color:var(--text-sub);text-align:center">캡틴이 평가를 완료하면 다음 단계로 진행됩니다.</div>';
    } else {
      title = '🏆 MOM 투표 시간!';
      desc = '오늘의 Man of the Match를 투표해주세요.';
      btnHtml = '<a href="?page=mom_vote&match_id=<?=$id?>" class="btn btn-primary btn-w">MOM 투표하기</a>';
    }
    var overlay = document.createElement('div');
    overlay.className = 'tf-modal';
    overlay.style.cssText = 'display:flex;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9998;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(4px)';
    overlay.onclick = function(e){ if(e.target===overlay) overlay.remove(); };
    overlay.innerHTML =
      '<div class="card" style="width:100%;max-width:380px;margin:0">' +
        '<div class="card-body" style="text-align:center">' +
          '<div style="font-size:17px;font-weight:700;margin-bottom:6px">' + title + '</div>' +
          '<div style="font-size:13px;color:var(--text-sub);margin-bottom:14px">' + desc + '</div>' +
          btnHtml +
          '<button type="button" onclick="this.closest(\'.tf-modal\').remove()" class="btn btn-ghost btn-w" style="margin-top:8px">나중에</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(overlay);
  })();
  </script>
  <?php endif; ?>

  <!-- [1-1] 캡틴: 노쇼/비매너 신고 (체크인 안한 본인 팀원) -->
  <?php if(isCaptain() && $myTeamMembers):
    $checkedInIds = array_column($cks, 'user_id');
    $noShowMembers = array_filter($myTeamMembers, function($tm) use ($checkedInIds, $attendanceMap) {
      $att = $attendanceMap[$tm['user_id']] ?? null;
      return $att && $att['status']==='ATTEND' && !in_array($tm['user_id'], $checkedInIds);
    });
  ?>
  <?php if($noShowMembers): ?>
  <div class="card" style="margin-top:14px;border-color:var(--danger)"><div class="card-body">
    <p class="section-title" style="color:var(--danger);margin-bottom:10px">⚠️ 노쇼/비매너 신고 (참석 표시 후 미체크인)</p>
    <?php foreach($noShowMembers as $nsm): ?>
    <form method="POST" style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--border)" onsubmit="return confirm('<?=h($nsm['name'])?> 노쇼/비매너 신고? 매너점수 -5점 차감됩니다.')">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="report_no_show">
      <input type="hidden" name="match_id" value="<?=$id?>">
      <input type="hidden" name="target_user_id" value="<?=$nsm['user_id']?>">
      <span style="flex:1;font-weight:600"><?=h($nsm['name'])?></span>
      <input type="text" name="reason" class="form-control" placeholder="사유 (선택)" style="flex:2">
      <button type="submit" class="btn btn-danger btn-sm">신고</button>
    </form>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>
  <?php endif; ?>
  <?php endif; ?>

  <!-- 신고 -->
  <button class="btn btn-ghost btn-w" style="margin-top:4px" data-tf-toggle="reportForm">
    <i class="bi bi-flag"></i> 신고하기
  </button>
  <div id="reportForm" class="tf-collapse" style="margin-top:10px">
    <form method="POST">
      <?=csrfInput()?><input type="hidden" name="action" value="submit_report">
      <input type="hidden" name="target_type" value="match">
      <input type="hidden" name="target_id" value="<?=$id?>">
      <textarea name="reason" class="form-control" placeholder="신고 사유를 입력하세요" required></textarea>
      <button type="submit" class="btn btn-danger btn-w" style="margin-top:8px">신고 접수</button>
    </form>
  </div>

  <!-- [매치 수정] 작성자 전용 (경기 시작 전) -->
  <?php
    $canEditMatch = isAdmin() || ($isMatchCreator && in_array($match['status'], ['open','request_pending','confirmed','checkin_open']));
  ?>
  <?php if ($canEditMatch): ?>
  <button class="btn btn-outline btn-w" style="margin-top:10px" data-tf-toggle="editMatchForm">
    <i class="bi bi-pencil-square"></i> 매치 정보 수정
  </button>
  <div id="editMatchForm" class="tf-collapse" style="margin-top:10px">
    <div class="card" style="border:1px solid var(--primary)"><div class="card-body">
      <form method="POST">
        <?=csrfInput()?>
        <input type="hidden" name="action" value="update_match">
        <input type="hidden" name="match_id" value="<?=$id?>">
        <div class="form-group"><label class="form-label">제목</label>
          <input type="text" name="title" class="form-control" value="<?=h($match['title']??'')?>" required></div>
        <div class="form-group"><label class="form-label">장소</label>
          <input type="text" name="location" class="form-control" value="<?=h($match['location']??'')?>"></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label" style="font-size:11px">🚇 가까운 역</label>
            <input type="text" name="venue_subway" class="form-control" value="<?=h($match['venue_subway']??'')?>" style="font-size:12px" placeholder="역삼역 5분"></div>
          <div class="form-group"><label class="form-label" style="font-size:11px">🅿️ 주차</label>
            <input type="text" name="venue_parking" class="form-control" value="<?=h($match['venue_parking']??'')?>" style="font-size:12px" placeholder="무료주차"></div>
        </div>
        <div class="form-group"><label class="form-label" style="font-size:11px">📍 구장 주소</label>
          <input type="text" name="venue_address" class="form-control" value="<?=h($match['venue_address']??'')?>" style="font-size:12px"></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">날짜</label>
            <input type="date" name="match_date" class="form-control" value="<?=h($match['match_date']??'')?>" required></div>
          <div class="form-group"><label class="form-label">시작</label>
            <input type="time" name="match_time" class="form-control" value="<?=h(substr($match['match_time']??'',0,5))?>" required></div>
          <div class="form-group"><label class="form-label">종료</label>
            <input type="time" name="match_end_time" class="form-control" value="<?=h(substr($match['match_end_time']??'',0,5))?>"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">종목</label>
            <select name="sport_type" class="form-select">
              <option value="풋살" <?=($match['sport_type']??'')==='풋살'?'selected':''?>>🏃 풋살</option>
              <option value="축구" <?=($match['sport_type']??'')==='축구'?'selected':''?>>⚽ 축구</option>
            </select></div>
          <div class="form-group"><label class="form-label">타입</label>
            <select name="match_type" class="form-select">
              <?php foreach(['VENUE'=>'🏟️ 상대 구함','MERC_ONLY'=>'⚡ 용병만 구함','VENUE_WANTED'=>'🔍 구장 구함','REQUEST'=>'🆘 모두 구함'] as $mk=>$ml): ?>
              <option value="<?=$mk?>" <?=($match['match_type']??'')===$mk?'selected':''?>><?=$ml?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="form-group"><label class="form-label">정원</label>
            <input type="number" name="max_players" class="form-control" value="<?=(int)($match['max_players']??12)?>" min="2"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">방식</label>
            <select name="format_type" class="form-select">
              <?php foreach(['축구','풋살'] as $f): ?>
              <option <?=($match['format_type']??'')===$f?'selected':''?>><?=h($f)?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="form-group"><label class="form-label">스타일</label>
            <select name="match_style" class="form-select">
              <?php foreach(['친선','리그','토너먼트','연습'] as $s): ?>
              <option <?=($match['match_style']??'')===$s?'selected':''?>><?=h($s)?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">실력</label>
            <select name="level" class="form-select">
              <?php foreach(['모든실력','입문','초급','중급','고급'] as $lv): ?>
              <option <?=($match['level']??'')===$lv?'selected':''?>><?=h($lv)?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="form-group"><label class="form-label">유니폼</label>
            <select name="uniform_color" class="form-select">
              <?php foreach(uniformColorMap() as $uk=>[$uh,$ul]): ?>
              <option value="<?=h($uk)?>" <?=($match['uniform_color']??'')===$uk?'selected':''?>><?=$ul?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">지역</label>
            <input type="text" name="region" class="form-control" value="<?=h($match['region']??'')?>"></div>
          <div class="form-group"><label class="form-label">구/군</label>
            <input type="text" name="district" class="form-control" value="<?=h($match['district']??'')?>"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">참가비</label>
            <select name="fee_type" class="form-select">
              <?php foreach(['없음','무료','인당','팀당','협의'] as $ft): ?>
              <option <?=($match['fee_type']??'')===$ft?'selected':''?>><?=h($ft)?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="form-group"><label class="form-label">금액</label>
            <input type="number" name="fee_amount" class="form-control" value="<?=(int)($match['fee_amount']??0)?>" min="0"></div>
        </div>
        <label style="display:flex;align-items:center;gap:8px;margin:10px 0;cursor:pointer">
          <input type="checkbox" name="allow_mercenary" value="1" <?=!empty($match['allow_mercenary'])?'checked':''?>>
          <span style="font-size:13px">⚡ 용병 모집 허용</span>
        </label>
        <div class="form-group"><label class="form-label">메모</label>
          <textarea name="note" class="form-control" rows="2"><?=h($match['note']??'')?></textarea></div>
        <div class="form-group"><label class="form-label">상대팀명 <span style="font-size:10px;color:var(--text-sub)">(직접 입력, 미등록 팀도 OK)</span></label>
          <input type="text" name="away_team_name" class="form-control" value="<?=h($match['away_team_name']??'')?>" placeholder="예: 송파 드래곤즈" maxlength="100"></div>
        <button type="submit" class="btn btn-primary btn-w">수정 저장</button>
      </form>
    </div></div>
  </div>
  <?php endif; ?>

  <!-- 매치 삭제 (개설자 creator_id == 본인 & open/request_pending, 또는 어드민) -->
  <?php
    // 작성자는 경기 시작 전(open/request_pending/confirmed/checkin_open)까지 삭제 가능
    $creatorDeletable = in_array($match['status'], ['open','request_pending','confirmed','checkin_open']);
    $canDeleteMatch = isAdmin() || ($isMatchCreator && $creatorDeletable);
    $deletableStatus = !in_array($match['status'], ['in_progress','result_pending','completed']);
  ?>
  <?php if ($canDeleteMatch && $deletableStatus): ?>
  <form method="POST" style="margin-top:10px" onsubmit="return confirm('매치를 삭제(취소)하시겠습니까?\n참여자에게 알림 없이 cancelled 상태로 변경됩니다.');">
    <?=csrfInput()?>
    <input type="hidden" name="action" value="delete_match">
    <input type="hidden" name="match_id" value="<?=$id?>">
    <button type="submit" class="btn btn-ghost btn-w" style="color:var(--danger);border-color:rgba(255,77,109,0.3)">
      <i class="bi bi-trash"></i> 매치 삭제<?= isAdmin() && !$isMatchCreator ? ' (관리자)' : '' ?>
    </button>
  </form>
  <?php endif; ?>

  <!-- SUPER_ADMIN 전용: 강제 제어 -->
  <?php if (isSuperAdmin()):
    // 팀 목록 (배정용)
    $allTeamsForAssign = $pdo->query("SELECT id, name FROM teams WHERE status='ACTIVE' ORDER BY name")->fetchAll();
  ?>
  <div style="margin-top:12px;padding:10px;border:1px dashed rgba(255,77,109,0.3);background:rgba(255,77,109,0.05);border-radius:8px">
    <div style="font-size:10px;color:#ff4d6d;font-weight:700;margin-bottom:6px">🔐 SUPER_ADMIN</div>
    <div style="display:flex;gap:6px;margin-bottom:8px">
      <form method="POST" style="flex:1" onsubmit="return confirm('매치를 강제 취소합니다.')">
        <?=csrfInput()?>
        <input type="hidden" name="action" value="admin_force_delete_match">
        <input type="hidden" name="match_id" value="<?=$id?>">
        <button type="submit" class="btn btn-sm btn-w" style="color:#ff4d6d;background:transparent;border:1px solid rgba(255,77,109,0.3)"><i class="bi bi-x-octagon"></i> 취소</button>
      </form>
      <form method="POST" style="flex:1" onsubmit="return confirm('DB에서 완전 삭제합니다! 복구 불가!')">
        <?=csrfInput()?>
        <input type="hidden" name="action" value="admin_purge_match">
        <input type="hidden" name="match_id" value="<?=$id?>">
        <button type="submit" class="btn btn-sm btn-w" style="background:#ff4d6d;color:#fff;border:none"><i class="bi bi-trash"></i> 완전삭제</button>
      </form>
    </div>
    <!-- 팀 배정 변경 -->
    <div style="display:flex;gap:4px;flex-wrap:wrap">
      <form method="POST" style="flex:1;margin:0">
        <?=csrfInput()?><input type="hidden" name="action" value="admin_assign_team"><input type="hidden" name="match_id" value="<?=$id?>"><input type="hidden" name="side" value="home">
        <select name="team_id" class="form-control" style="font-size:11px;padding:4px" onchange="this.form.submit()">
          <option value="">홈팀: <?=h($match['home_name'])?></option>
          <?php foreach($allTeamsForAssign as $at): ?><option value="<?=$at['id']?>" <?=(int)$match['home_team_id']===$at['id']?'selected':''?>><?=h($at['name'])?></option><?php endforeach; ?>
          <option value="0">— 미지정 —</option>
        </select>
      </form>
      <form method="POST" style="flex:1;margin:0">
        <?=csrfInput()?><input type="hidden" name="action" value="admin_assign_team"><input type="hidden" name="match_id" value="<?=$id?>"><input type="hidden" name="side" value="away">
        <select name="team_id" class="form-control" style="font-size:11px;padding:4px" onchange="this.form.submit()">
          <option value="">어웨이: <?=h($match['away_name'] ?? '미지정')?></option>
          <?php foreach($allTeamsForAssign as $at): ?><option value="<?=$at['id']?>" <?=(int)($match['away_team_id']??0)===$at['id']?'selected':''?>><?=h($at['name'])?></option><?php endforeach; ?>
          <option value="0">— 미지정 —</option>
        </select>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 팀
// ═══════════════════════════════════════════════════════════════
function pagTeam(PDO $pdo): void {
    $tid=myTeamId();
    if(!$tid){echo '<div class="container" style="padding:60px 16px;text-align:center;color:var(--text-sub)">소속 팀이 없습니다.</div>';return;}
    $team=$pdo->prepare("SELECT * FROM teams WHERE id=?");$team->execute([$tid]);$team=$team->fetch();
    $members=$pdo->prepare("SELECT u.name,u.nickname,u.profile_image_url,u.jersey_number,u.position,u.manner_score,u.is_player_background,u.stat_pace,u.stat_shooting,u.stat_passing,u.stat_dribbling,u.stat_defending,u.stat_physical,tm.role AS trole,tm.user_id,tm.is_pro,tm.has_manage_perm FROM team_members tm JOIN users u ON u.id=tm.user_id WHERE tm.team_id=? AND tm.status='active' AND tm.role != 'mercenary' ORDER BY FIELD(tm.role,'owner','president','director','captain','vice_captain','manager','coach','treasurer','analyst','doctor','player'),u.name");
    $members->execute([$tid]);$members=$members->fetchAll();
    // 용병으로 왔던 유저 이력 (중복 제거 — 같은 사람 여러번 온 경우 최근 1건)
    $mercHistory = $pdo->prepare("
        SELECT u.id AS user_id, u.name, u.nickname, u.profile_image_url, u.position, u.manner_score,
               MAX(joined.last_match_date) AS last_match_date,
               COUNT(DISTINCT joined.match_id) AS appearances
        FROM users u
        JOIN (
            SELECT mr.user_id, mr.match_id, m.match_date AS last_match_date
            FROM mercenary_requests mr
            JOIN matches m ON m.id=mr.match_id
            WHERE mr.team_id=? AND mr.status='accepted'
            UNION
            SELECT tm2.user_id, NULL AS match_id, NULL AS last_match_date
            FROM team_members tm2
            WHERE tm2.team_id=? AND tm2.role='mercenary'
        ) joined ON joined.user_id=u.id
        WHERE u.id NOT IN (
            SELECT user_id FROM team_members WHERE team_id=? AND status='active' AND role != 'mercenary'
        )
        GROUP BY u.id
        ORDER BY last_match_date DESC, appearances DESC
        LIMIT 20
    ");
    $mercHistory->execute([$tid, $tid, $tid]);
    $mercHistory = $mercHistory->fetchAll();
    // 포지션 분포 집계
    $posCounts = ['GK'=>0,'DF'=>0,'MF'=>0,'FW'=>0,'?'=>0];
    $proCount = 0;
    foreach($members as $mm){
        $p = $mm['position'] ?: '?';
        if (!isset($posCounts[$p])) $p = '?';
        $posCounts[$p]++;
        if (!empty($mm['is_pro'])) $proCount++;
    }
    $pReqs=[];
    $pendingMemberApps=[];
    if(isCaptain()){
        $s=$pdo->prepare("SELECT mr.*,t2.name AS tname,m.title AS mtitle,m.match_date FROM match_requests mr JOIN teams t2 ON t2.id=mr.team_id JOIN matches m ON m.id=mr.match_id WHERE m.home_team_id=? AND mr.status='pending'");
        $s->execute([$tid]);$pReqs=$s->fetchAll();
        // [팀 가입 승인] 내 팀으로 들어온 가입 신청 (pending)
        $pm=$pdo->prepare("
          SELECT u.id AS user_id, u.name, u.nickname, u.position, u.manner_score, u.region, u.district, tm.created_at AS applied_at
          FROM team_members tm JOIN users u ON u.id=tm.user_id
          WHERE tm.team_id=? AND tm.status='pending'
          ORDER BY tm.created_at DESC
        ");
        $pm->execute([$tid]); $pendingMemberApps=$pm->fetchAll();
    }
    $rl=['owner'=>'구단주','president'=>'회장','director'=>'감독','captain'=>'주장','vice_captain'=>'부주장','manager'=>'매니저','coach'=>'코치','treasurer'=>'총무','analyst'=>'전력분석','doctor'=>'팀닥터','player'=>'선수','mercenary'=>'용병']; ?>
<div class="container">
  <div style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
    <div style="flex:1;min-width:0">
      <?php if(!empty($team['emblem_url'])): ?>
      <img src="<?=h($team['emblem_url'])?>" style="width:48px;height:48px;object-fit:contain;border-radius:10px;margin-bottom:6px;border:1px solid #333" alt="앰블럼">
      <?php endif; ?>
      <h2 style="font-size:20px;font-weight:700"><?=h(teamDisplayName($team['name']))?></h2>
      <div style="font-size:13px;color:var(--text-sub);margin-top:4px">
        <?=h($team['region']??'')?> <?=h($team['district']??'')?> &nbsp;·&nbsp; 신뢰점수 <span style="color:var(--primary);font-weight:700"><?=$team['trust_score']?></span>
      </div>
      <!-- [팀 실력] 실시간 계산 배지 -->
      <?php $ts = calcTeamStrength($team, $proCount, count($members)); ?>
      <div style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;padding:4px 10px;border-radius:999px;background:<?=$ts['bg_hex']?>;border:1px solid <?=$ts['color_hex']?>55">
        <span style="font-size:12px"><?=$ts['icon']?></span>
        <span style="font-size:11px;font-weight:700;color:<?=$ts['color_hex']?>">
          <?php if ($ts['is_rated']): ?>Lv.<?=$ts['level']?> · <?=h($ts['label'])?> · <?=$ts['score']?>점<?php else: ?><?=h($ts['label'])?><?php endif; ?>
        </span>
        <span style="font-size:10px;color:var(--text-sub)">(<?=h($ts['subtitle'])?>)</span>
      </div>
    </div>
    <button type="button" onclick="tfOpenModal('teamSettingsModal')" class="btn btn-ghost btn-sm" aria-label="팀 설정" style="padding:6px 10px;flex-shrink:0">
      <i class="bi bi-gear-fill" style="font-size:18px"></i>
    </button>
  </div>
  <div class="stat-grid">
    <div class="stat-box"><div class="stat-val" style="color:var(--primary)"><?=$team['win']?></div><div class="stat-lbl">승</div></div>
    <div class="stat-box"><div class="stat-val" style="color:var(--warning)"><?=$team['draw']?></div><div class="stat-lbl">무</div></div>
    <div class="stat-box"><div class="stat-val" style="color:var(--danger)"><?=$team['loss']?></div><div class="stat-lbl">패</div></div>
  </div>
  <?php $gf = (int)($team['goals_for']??0); $ga = (int)($team['goals_against']??0); $gd = $gf - $ga; ?>
  <div class="stat-grid" style="margin-top:8px">
    <div class="stat-box"><div class="stat-val" style="color:#ffb400"><?=$gf?></div><div class="stat-lbl">득점</div></div>
    <div class="stat-box"><div class="stat-val" style="color:#3a9ef5"><?=$ga?></div><div class="stat-lbl">실점</div></div>
    <div class="stat-box"><div class="stat-val" style="color:<?=$gd>=0?'var(--primary)':'var(--danger)'?>"><?=($gd>0?'+':'').$gd?></div><div class="stat-lbl">득실차</div></div>
  </div>

  <!-- 팀 미활성 경고 배너 (status=PENDING, 3명 미만) -->
  <?php if (($team['status']??'') === 'PENDING'):
    $needed = max(0, 3 - count($members)); ?>
  <div style="margin-top:12px;padding:14px 16px;border:1px solid rgba(255,184,0,0.35);background:rgba(255,184,0,0.08);border-radius:12px;display:flex;align-items:center;gap:12px">
    <div style="font-size:26px">⏳</div>
    <div style="flex:1;min-width:0">
      <div style="font-size:13px;font-weight:700;color:var(--warning)">팀 활성화 대기 중</div>
      <div style="font-size:11px;color:var(--text-sub);margin-top:2px">팀원 <?=$needed?>명 더 모이면 활성화됩니다. 활성화 전까지 매치 개설 · 용병/가입 제안 불가.</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- 포지션별 인원 분포 (도넛) -->
  <?php $memTotal = count($members); if ($memTotal > 0): ?>
  <div class="card" style="margin-top:12px"><div class="card-body" style="display:flex;align-items:center;gap:18px">
    <?php
      // SVG 도넛: 각 포지션이 차지하는 arc
      $posOrder = ['GK','DF','MF','FW','?'];
      $posColor = ['GK'=>'#ff9500','DF'=>'#3a9ef5','MF'=>'#00ff88','FW'=>'#ff6b6b','?'=>'#666'];
      $posLabel = ['GK'=>'GK','DF'=>'DF','MF'=>'MF','FW'=>'FW','?'=>'기타'];
      $R = 42; $cx = 50; $cy = 50; $circ = 2 * M_PI * $R;
      $offset = 0;
      $arcs = '';
      foreach($posOrder as $pk){
        $n = $posCounts[$pk]; if ($n <= 0) continue;
        $len = $circ * ($n / $memTotal);
        $arcs .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$R.'" fill="transparent" stroke="'.$posColor[$pk].'" stroke-width="14" stroke-dasharray="'.round($len,2).' '.round($circ-$len,2).'" stroke-dashoffset="'.round(-$offset,2).'" transform="rotate(-90 '.$cx.' '.$cy.')"/>';
        $offset += $len;
      }
    ?>
    <svg width="100" height="100" viewBox="0 0 100 100" style="flex-shrink:0">
      <?=$arcs?>
      <text x="50" y="46" text-anchor="middle" font-size="20" font-weight="800" fill="#fff"><?=$memTotal?></text>
      <text x="50" y="62" text-anchor="middle" font-size="9" fill="#888">명</text>
    </svg>
    <div style="flex:1;display:grid;grid-template-columns:1fr 1fr;gap:6px 12px">
      <?php foreach($posOrder as $pk): if($posCounts[$pk]<=0) continue; ?>
      <div style="display:flex;align-items:center;gap:6px;font-size:12px">
        <span style="width:10px;height:10px;border-radius:50%;background:<?=$posColor[$pk]?>"></span>
        <span style="color:var(--text-sub);min-width:28px"><?=$posLabel[$pk]?></span>
        <span style="font-weight:700;margin-left:auto"><?=$posCounts[$pk]?>명</span>
      </div>
      <?php endforeach; ?>
      <?php if($proCount > 0): ?>
      <div style="grid-column:1/-1;padding-top:4px;border-top:1px solid rgba(255,255,255,0.08);font-size:12px;color:var(--primary);font-weight:700">⚽ 선출 <?=$proCount?>명 포함</div>
      <?php endif; ?>
    </div>
  </div></div>
  <?php endif; ?>

  <?php if($pReqs): ?>
  <div class="card" style="margin-bottom:16px;border-color:var(--warning)"><div class="card-body">
    <p class="section-title" style="color:var(--warning)"><i class="bi bi-bell-fill"></i> 신청 대기 <?=count($pReqs)?>건</p>
    <?php foreach($pReqs as $r): ?>
    <div class="list-item">
      <div><span style="font-weight:600"><?=h($r['tname'])?></span> → <?=h($r['mtitle']??'-')?><br>
        <span style="font-size:12px;color:var(--text-sub)"><?=$r['match_date']?></span></div>
      <div style="display:flex;gap:6px">
        <form method="POST"><?=csrfInput()?><input type="hidden" name="action" value="accept_request"><input type="hidden" name="request_id" value="<?=$r['id']?>">
          <button type="submit" class="btn btn-primary btn-sm">수락</button></form>
        <form method="POST"><?=csrfInput()?><input type="hidden" name="action" value="reject_request"><input type="hidden" name="request_id" value="<?=$r['id']?>">
          <button type="submit" class="btn btn-ghost btn-sm">거절</button></form>
      </div>
    </div>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>

  <!-- [팀 가입 승인] 캡틴에게 유저 가입 신청 표시 -->
  <?php if($pendingMemberApps): ?>
  <div class="card" style="margin-bottom:16px;border-color:#ff9500"><div class="card-body">
    <p class="section-title" style="color:#ff9500"><i class="bi bi-person-plus-fill"></i> 팀 가입 신청 <?=count($pendingMemberApps)?>명</p>
    <?php foreach($pendingMemberApps as $ap):
      $apDn = displayName($ap);
      $posColor = ['GK'=>'#ff9500','DF'=>'#3a9ef5','MF'=>'#00ff88','FW'=>'#ff6b6b'][$ap['position']??''] ?? '#888';
    ?>
    <div class="list-item" style="padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.06)">
      <div style="flex:1;min-width:0;cursor:pointer" onclick="openUserProfile(<?=(int)$ap['user_id']?>)">
        <div style="font-weight:600;font-size:13px">
          <?=h($apDn)?>
          <?php if($ap['position']): ?><span style="font-size:10px;color:<?=$posColor?>;margin-left:4px;font-weight:700"><?=h($ap['position'])?></span><?php endif; ?>
        </div>
        <div style="font-size:11px;color:var(--text-sub);margin-top:2px">
          <?=h($ap['region']??'')?> <?=h($ap['district']??'')?> · 매너 <?=number_format((float)$ap['manner_score'],1)?>°
          · 신청 <?=timeAgo($ap['applied_at'])?>
        </div>
      </div>
      <div style="display:flex;gap:4px;flex-shrink:0">
        <form method="POST" style="margin:0">
          <?=csrfInput()?><input type="hidden" name="action" value="approve_team_join"><input type="hidden" name="user_id" value="<?=(int)$ap['user_id']?>">
          <button type="submit" class="btn btn-primary btn-sm" style="font-size:11px;padding:4px 10px">수락</button>
        </form>
        <form method="POST" style="margin:0" onsubmit="return confirm('거절하시겠습니까?')">
          <?=csrfInput()?><input type="hidden" name="action" value="reject_team_join"><input type="hidden" name="user_id" value="<?=(int)$ap['user_id']?>">
          <button type="submit" class="btn btn-ghost btn-sm" style="font-size:11px;padding:4px 10px">거절</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>

  <!-- 초대코드 + 공유 링크 (캡틴) -->
  <?php if(isCaptain() && !empty($team['invite_code'])):
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '104.198.147.134';
    $inviteLink = $scheme.'://'.$host.'/app.php?page=join_team&code='.urlencode($team['invite_code']);
  ?>
  <div class="card" style="margin-bottom:14px;border:1px solid rgba(0,255,136,0.2)"><div class="card-body">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
      <div>
        <div style="font-size:12px;color:var(--text-sub);margin-bottom:2px">팀 초대코드</div>
        <div style="font-size:22px;font-weight:900;letter-spacing:4px;font-family:'Space Grotesk',sans-serif;color:var(--primary)"><?=h($team['invite_code'])?></div>
      </div>
      <button type="button" onclick="copyToClipboard('<?=h($team['invite_code'])?>')" class="btn btn-outline btn-sm">코드 복사</button>
    </div>
    <div style="padding-top:10px;border-top:1px solid rgba(255,255,255,0.06);display:flex;align-items:center;gap:8px">
      <div style="flex:1;min-width:0;overflow:hidden">
        <div style="font-size:11px;color:var(--text-sub);margin-bottom:2px">공유 링크 (카톡/문자)</div>
        <div style="font-size:12px;color:var(--text-sub);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($inviteLink)?></div>
      </div>
      <button type="button" onclick="copyToClipboard('<?=h($inviteLink)?>')" class="btn btn-primary btn-sm" style="flex-shrink:0"><i class="bi bi-link-45deg"></i> 링크 복사</button>
    </div>
    <!-- 미가입 선수 수동 추가 -->
    <div style="padding-top:10px;border-top:1px solid rgba(255,255,255,0.06)">
      <div onclick="document.getElementById('manualAddForm').style.display=document.getElementById('manualAddForm').style.display==='none'?'block':'none'" style="font-size:12px;color:var(--primary);cursor:pointer;font-weight:600">
        <i class="bi bi-person-plus"></i> 미가입 선수 직접 추가 ▼
      </div>
      <div id="manualAddForm" style="display:none;margin-top:8px">
        <form method="POST">
          <?=csrfInput()?>
          <input type="hidden" name="action" value="manual_add_member">
          <div class="form-row" style="margin-bottom:6px">
            <div class="form-group"><label class="form-label" style="font-size:11px">이름 *</label>
              <input type="text" name="member_name" class="form-control" style="font-size:12px" placeholder="홍길동" required></div>
            <div class="form-group"><label class="form-label" style="font-size:11px">전화번호</label>
              <input type="tel" name="member_phone" class="form-control phone-input" style="font-size:12px" placeholder="010-0000-0000"></div>
          </div>
          <div class="form-row" style="margin-bottom:6px">
            <div class="form-group"><label class="form-label" style="font-size:11px">포지션</label>
              <select name="member_position" class="form-control" style="font-size:12px"><option value="">선택</option><option>GK</option><option>DF</option><option>MF</option><option>FW</option></select></div>
            <div class="form-group" style="display:flex;align-items:flex-end">
              <button type="submit" class="btn btn-primary btn-sm btn-w" style="font-size:12px">추가</button></div>
          </div>
          <div style="font-size:10px;color:var(--text-sub)">전화번호 입력 시 기존 회원 자동 연결, 미가입이면 임시 계정 생성 (비번: 123456)</div>
        </form>
      </div>
    </div>
  </div></div>
  <?php endif; ?>

  <p class="section-title">팀원 (<?=count($members)?>명)</p>
  <div class="card"><div class="card-body" style="padding:0 16px">
    <?php foreach($members as $m):
      $posColor = match($m['position']??''){
        'GK'=>'rgba(255,149,0,0.15)','DF'=>'rgba(0,149,255,0.15)',
        'MF'=>'rgba(0,255,136,0.12)','FW'=>'rgba(255,59,48,0.12)',
        default=>'rgba(255,255,255,0.06)'
      };
      $posTextColor = match($m['position']??''){
        'GK'=>'#ff9500','DF'=>'#3a9ef5','MF'=>'var(--primary)','FW'=>'#ff6b6b',
        default=>'var(--text-sub)'
      };
    ?>
    <?php
      $mStats = json_encode([
        (int)($m['stat_pace']??50),(int)($m['stat_shooting']??50),
        (int)($m['stat_passing']??50),(int)($m['stat_dribbling']??50),
        (int)($m['stat_defending']??50),(int)($m['stat_physical']??50)
      ]);
    ?>
    <?php
      $mDisplay = displayName($m);
      // [직책 배지] 역할별 색상
      $roleBadge = match($m['trole']){
        'owner'        => ['#00ff88','#000','💎 구단주'],
        'president'    => ['#ff4d6d','#fff','👑 회장'],
        'director'     => ['#ffd60a','#000','🎬 감독'],
        'captain'      => ['#ff9500','#fff','⭐ 주장'],
        'vice_captain' => ['#ffb400','#000','🛡 부주장'],
        'manager'      => ['#3a9ef5','#fff','📋 매니저'],
        'coach'        => ['#c084fc','#fff','🎯 코치'],
        'treasurer'    => ['#00ff88','#000','💰 총무'],
        default        => ['rgba(255,255,255,0.08)','var(--text-sub)','선수'],
      };
    ?>
    <div class="list-item" style="padding:10px 0">
      <div style="display:flex;align-items:center;gap:10px;flex:1;cursor:pointer" onclick="openUserProfile(<?=(int)$m['user_id']?>)">
        <?= renderAvatar($m, 38) ?>
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
            <?php if(!empty($m['jersey_number'])): ?><span style="font-size:11px;font-weight:800;color:var(--text-sub);font-family:'Space Grotesk',sans-serif">#<?=(int)$m['jersey_number']?></span><?php endif; ?>
            <span style="font-weight:600;font-size:14px"><?=h($mDisplay)?></span>
            <span style="font-size:10px;padding:2px 6px;border-radius:4px;background:<?=$roleBadge[0]?>;color:<?=$roleBadge[1]?>;font-weight:700"><?=$roleBadge[2]?></span>
          </div>
          <div style="font-size:12px;color:var(--text-sub);margin-top:2px">
            <?php if($m['position']): ?><span style="color:<?=$posTextColor?>;font-weight:600"><?=h($m['position'])?></span> · <?php endif; ?>
            <?=(float)$m['manner_score']?>°
            <?php if(!empty($m['is_pro'])): ?> · <span style="color:var(--primary)">선출</span><?php endif; ?>
          </div>
        </div>
      </div>
      <?php if (isCaptain()): ?>
      <!-- [직책 + 권한] 캡틴이 변경 -->
      <div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0" onclick="event.stopPropagation()">
        <form method="POST" style="margin:0">
          <?=csrfInput()?>
          <input type="hidden" name="action" value="change_member_role">
          <input type="hidden" name="target_user_id" value="<?=(int)$m['user_id']?>">
          <select name="new_role" class="form-control" style="font-size:10px;padding:3px 6px;width:auto;min-width:110px;background:rgba(0,0,0,0.3);color:var(--text-main);border:1px solid rgba(255,255,255,0.1);border-radius:4px" onchange="if(confirm('직책을 변경하시겠습니까?')) this.form.submit()">
            <?php foreach(['player'=>'선수','owner'=>'구단주','president'=>'회장','director'=>'감독','captain'=>'주장','vice_captain'=>'부주장','manager'=>'매니저','coach'=>'코치','treasurer'=>'총무','analyst'=>'전력분석','doctor'=>'팀닥터'] as $rk=>$rv): ?>
            <option value="<?=$rk?>" <?=$m['trole']===$rk?'selected':''?>><?=$rv?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <form method="POST" style="margin:0">
          <?=csrfInput()?>
          <input type="hidden" name="action" value="toggle_manage_perm">
          <input type="hidden" name="target_user_id" value="<?=(int)$m['user_id']?>">
          <label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:10px;color:var(--text-sub)">
            <input type="checkbox" <?=!empty($m['has_manage_perm'])?'checked':''?> onchange="this.form.submit()" style="width:14px;height:14px">
            관리권한
          </label>
        </form>
      </div>
      <?php else: ?>
      <i class="bi bi-chevron-right" style="color:var(--text-sub);font-size:12px;flex-shrink:0;cursor:pointer" onclick="openUserProfile(<?=(int)$m['user_id']?>)"></i>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div></div>

  <!-- 팀원 프로필 바텀시트 -->
  <div id="memberSheet" style="display:none;position:fixed;inset:0;z-index:1000" onclick="if(event.target===this)closeMemberSheet()">
    <div style="position:absolute;inset:0;background:rgba(0,0,0,0.6);backdrop-filter:blur(4px)"></div>
    <div id="memberSheetInner" style="position:absolute;bottom:0;left:0;right:0;background:var(--bg-surface);border-radius:20px 20px 0 0;padding:24px 20px;max-height:80vh;overflow-y:auto;transform:translateY(100%);transition:transform .3s cubic-bezier(.32,.72,0,1)">
      <!-- 핸들 -->
      <div style="width:40px;height:4px;background:rgba(255,255,255,0.15);border-radius:2px;margin:0 auto 20px"></div>
      <div id="sheetContent"></div>
    </div>
  </div>
  <!-- hidden csrf form for DM -->
  <form id="dmForm" method="POST" style="display:none">
    <?=csrfInput()?>
    <input type="hidden" name="action" value="start_chat">
    <input type="hidden" name="target_user_id" id="dmTargetId">
  </form>
  <!-- hidden csrf form for kick -->
  <form id="kickForm" method="POST" style="display:none" onsubmit="return confirm('강퇴하시겠습니까?')">
    <?=csrfInput()?>
    <input type="hidden" name="action" value="kick_member">
    <input type="hidden" name="target_user_id" id="kickTargetId">
  </form>
  <script>
  const rlMap={captain:'캡틴',vice_captain:'부캡틴',manager:'매니저',coach:'코치',treasurer:'총무',player:'선수',mercenary:'용병'};
  const posColors={GK:'#ff9500',DF:'#3a9ef5',MF:'#00ff88',FW:'#ff6b6b'};
  function buildRadarSvg(stats,pos){
    const labelMap={
      GK:['반사신경','다이빙','패스','핸들링','위치선정','피지컬'],
      DF:['속도','태클','패스','헤딩','수비','피지컬'],
      MF:['속도','슈팅','패스','드리블','수비','피지컬'],
      FW:['속도','슈팅','패스','드리블','수비','피지컬'],
    };
    const labels=labelMap[pos]||labelMap['FW'];
    const n=6,size=180,cx=size/2,cy=size/2,r=size*0.36,lr=size*0.47;
    const pts=scale=>{let p=[];for(let i=0;i<n;i++){const a=(-90+i*60)*Math.PI/180;p.push(`${(cx+r*scale*Math.cos(a)).toFixed(2)},${(cy+r*scale*Math.sin(a)).toFixed(2)}`);}return p.join(' ');};
    let svg=`<svg viewBox="0 0 ${size} ${size}" xmlns="http://www.w3.org/2000/svg" style="width:100%;max-width:180px">`;
    [0.25,0.5,0.75,1.0].forEach(s=>{svg+=`<polygon points="${pts(s)}" fill="none" stroke="rgba(255,255,255,${s===1?0.2:0.1})" stroke-width="1"/>`;});
    for(let i=0;i<n;i++){const a=(-90+i*60)*Math.PI/180;svg+=`<line x1="${cx}" y1="${cy}" x2="${(cx+r*Math.cos(a)).toFixed(2)}" y2="${(cy+r*Math.sin(a)).toFixed(2)}" stroke="rgba(255,255,255,0.1)" stroke-width="1"/>`;}
    const sp=[];
    for(let i=0;i<n;i++){const a=(-90+i*60)*Math.PI/180;const v=Math.min(99,Math.max(1,stats[i]))/99;sp.push(`${(cx+r*v*Math.cos(a)).toFixed(2)},${(cy+r*v*Math.sin(a)).toFixed(2)}`);}
    svg+=`<polygon points="${sp.join(' ')}" fill="rgba(0,255,136,0.18)" stroke="rgba(0,255,136,0.85)" stroke-width="1.5" stroke-linejoin="round"/>`;
    sp.forEach(pt=>{const[px,py]=pt.split(',');svg+=`<circle cx="${px}" cy="${py}" r="2.5" fill="#00ff88"/>`;});
    for(let i=0;i<n;i++){
      const a=(-90+i*60)*Math.PI/180,lx=(cx+lr*Math.cos(a)).toFixed(2),ly=(cy+lr*Math.sin(a)).toFixed(2);
      const cosA=Math.cos(a),ta=Math.abs(a)<0.1||Math.abs(Math.abs(a)-Math.PI)<0.1?'middle':(cosA>0.1?'start':(cosA<-0.1?'end':'middle'));
      svg+=`<text x="${lx}" y="${(+ly-3).toFixed(2)}" text-anchor="${ta}" fill="rgba(255,255,255,0.55)" font-size="8" font-family="sans-serif">${labels[i]}</text>`;
      svg+=`<text x="${lx}" y="${(+ly+8).toFixed(2)}" text-anchor="${ta}" fill="rgba(0,255,136,0.9)" font-size="9" font-weight="700" font-family="'Space Grotesk',sans-serif">${stats[i]}</text>`;
    }
    svg+='</svg>';
    return svg;
  }
  function showMemberSheet(uid,name,pos,role,manner,canDm,canKick,stats){
    const posLabel=pos||'포지션 미설정';
    const posColor=posColors[pos]||'#888';
    const radarHtml=stats?`<div style="text-align:center;margin-bottom:14px">${buildRadarSvg(stats,pos||'FW')}</div>`:'';
    document.getElementById('sheetContent').innerHTML=`
      <div style="text-align:center;margin-bottom:20px">
        <div style="width:64px;height:64px;border-radius:50%;background:rgba(0,255,136,0.1);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700;color:var(--primary);margin:0 auto 12px">${name.charAt(0)}</div>
        <div style="font-size:20px;font-weight:700;margin-bottom:4px">${name}</div>
        <div style="display:flex;justify-content:center;gap:6px;flex-wrap:wrap">
          <span style="background:rgba(255,255,255,0.08);border-radius:20px;padding:3px 10px;font-size:12px;color:#ccc">${rlMap[role]||role}</span>
          ${pos?`<span style="background:rgba(${pos==='GK'?'255,149,0':pos==='DF'?'58,158,245':pos==='MF'?'0,255,136':'255,107,107'},0.15);color:${posColor};border-radius:20px;padding:3px 10px;font-size:12px;font-weight:700">주포지션 ${posLabel}</span>`:''}
        </div>
      </div>
      <div style="background:var(--bg-surface-alt);border-radius:12px;padding:14px;text-align:center;margin-bottom:16px">
        <div style="font-size:28px;font-weight:900;color:var(--primary);font-family:'Space Grotesk',sans-serif">${manner.toFixed(1)}</div>
        <div style="font-size:12px;color:var(--text-sub)">매너 점수</div>
      </div>
      ${radarHtml}
      <div style="display:flex;flex-direction:column;gap:8px">
        <button onclick="document.getElementById('memberSheet').style.display='none'; openUserProfile(${uid})" class="btn btn-outline btn-w"><i class="bi bi-bar-chart-line"></i> 상세 통계 보기 (경기/골/출석률)</button>
        ${canDm?`<button onclick="sendDm(${uid})" class="btn btn-primary btn-w"><i class="bi bi-chat-dots-fill"></i> 메시지 보내기</button>`:''}
        ${canKick?`<button onclick="kickMember(${uid})" class="btn btn-w" style="color:var(--danger);border:1px solid rgba(255,77,109,0.3);background:rgba(255,77,109,0.08)"><i class="bi bi-person-x"></i> 강퇴</button>`:''}
        <button onclick="closeMemberSheet()" class="btn btn-ghost btn-w">닫기</button>
      </div>
    `;
    const sheet=document.getElementById('memberSheet');
    sheet.style.display='block';
    requestAnimationFrame(()=>document.getElementById('memberSheetInner').style.transform='translateY(0)');
  }
  function closeMemberSheet(){
    document.getElementById('memberSheetInner').style.transform='translateY(100%)';
    setTimeout(()=>document.getElementById('memberSheet').style.display='none',300);
  }
  function sendDm(uid){
    document.getElementById('dmTargetId').value=uid;
    document.getElementById('dmForm').submit();
  }
  function kickMember(uid){
    if(confirm('정말 강퇴하시겠습니까?')){
      document.getElementById('kickTargetId').value=uid;
      document.getElementById('kickForm').submit();
    }
  }
  </script>

  <!-- 우리 팀에 용병으로 왔던 유저 이력 -->
  <?php if ($mercHistory): ?>
  <p class="section-title" style="margin-top:18px"><i class="bi bi-lightning-charge-fill" style="color:#ffb400"></i> 용병으로 왔던 선수 (<?=count($mercHistory)?>)</p>
  <div class="card"><div class="card-body" style="padding:0 16px">
    <?php foreach($mercHistory as $mh):
      $mhDn = displayName($mh);
      $posC = ['GK'=>'#ff9500','DF'=>'#3a9ef5','MF'=>'#00ff88','FW'=>'#ff6b6b'][$mh['position']??''] ?? '#888';
    ?>
    <div class="list-item" style="cursor:pointer;padding:10px 0" onclick="openUserProfile(<?=(int)$mh['user_id']?>)">
      <div style="display:flex;align-items:center;gap:10px;flex:1">
        <?= renderAvatar($mh, 34) ?>
        <div style="flex:1">
          <div style="font-weight:600;font-size:13px"><?=h($mhDn)?>
            <?php if(!empty($mh['position'])): ?><span style="font-size:10px;color:<?=$posC?>;margin-left:4px;font-weight:700"><?=h($mh['position'])?></span><?php endif; ?>
          </div>
          <div style="font-size:11px;color:var(--text-sub)">
            용병 <?=(int)$mh['appearances']?>경기 참여
            <?php if($mh['last_match_date']): ?> · 최근 <?=$mh['last_match_date']?><?php endif; ?>
            · 매너 <?=number_format((float)$mh['manner_score'],1)?>°
          </div>
        </div>
      </div>
      <?php if (isCaptain()): ?>
      <form method="POST" onclick="event.stopPropagation()" style="margin:0">
        <?=csrfInput()?>
        <input type="hidden" name="action" value="start_chat">
        <input type="hidden" name="target_user_id" value="<?=(int)$mh['user_id']?>">
        <button type="submit" class="btn btn-outline btn-sm" title="DM 보내기"><i class="bi bi-chat-dots"></i></button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>

  <!-- 팀 설정 모달 (톱니바퀴 클릭 시 노출) -->
  <div id="teamSettingsModal" class="tf-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;padding:16px" onclick="if(event.target===this) tfCloseModal('teamSettingsModal')">
    <div class="card" style="width:100%;max-width:420px;margin:0" onclick="event.stopPropagation()">
      <div class="card-body">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
          <h3 style="font-size:16px;font-weight:700;margin:0"><i class="bi bi-gear-fill"></i> 팀 설정</h3>
          <button type="button" onclick="tfCloseModal('teamSettingsModal')" class="btn btn-ghost btn-sm" aria-label="닫기" style="padding:4px 10px">✕</button>
        </div>

        <?php if(isCaptain()): ?>
        <a href="?page=team_settings" class="btn btn-outline btn-w" style="margin-bottom:10px">
          <i class="bi bi-pencil-square"></i> 팀 프로필 편집
        </a>
        <?php endif; ?>

        <hr class="divider" style="margin:10px 0">

        <?php if(isCaptain() && count($members) > 1): ?>
        <p style="font-size:12px;color:var(--text-sub);margin-bottom:8px">팀장 위임 후 나가거나, 팀원이 모두 나가면 자동 해체됩니다.</p>
        <?php endif; ?>
        <form method="POST" onsubmit="return confirm('정말 <?=isCaptain()&&count($members)===1?'팀을 해체':'팀에서 나가'?>시겠습니까?')">
          <?=csrfInput()?>
          <input type="hidden" name="action" value="leave_team">
          <button type="submit" class="btn btn-ghost btn-w" style="color:var(--danger);border-color:rgba(255,77,109,0.3)">
            <i class="bi bi-box-arrow-left"></i> <?=isCaptain()&&count($members)===1?'팀 해체':'팀 나가기'?>
          </button>
        </form>

        <?php if (isSuperAdmin()): ?>
        <hr class="divider" style="margin:14px 0">
        <div style="font-size:10px;color:#ff4d6d;font-weight:700;margin-bottom:6px">🔐 SUPER_ADMIN</div>
        <?php if (($team['status']??'') === 'PENDING'): ?>
        <form method="POST" style="margin-bottom:6px"><?=csrfInput()?><input type="hidden" name="action" value="admin_activate_team"><input type="hidden" name="team_id" value="<?=$tid?>"><button type="submit" class="btn btn-primary btn-sm btn-w">팀 강제 활성화</button></form>
        <?php endif; ?>
        <?php if (($team['status']??'') !== 'BANNED'): ?>
        <form method="POST" onsubmit="return confirm('팀을 BANNED로 변경합니다. 계속?')"><?=csrfInput()?><input type="hidden" name="action" value="admin_ban_team"><input type="hidden" name="team_id" value="<?=$tid?>"><button type="submit" class="btn btn-ghost btn-sm btn-w" style="color:#ff4d6d;border:1px solid rgba(255,77,109,0.3)">팀 강제 비활성화 (BAN)</button></form>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 마이페이지
// ═══════════════════════════════════════════════════════════════
function pagMypage(PDO $pdo): void {
    $myPoints = getUserPoints($pdo, me()['id']);
    $u   = me();
    $uid = $u['id'];
    $statTab = $_GET['stat'] ?? 'all'; // all | month | year | merc

    $dateFrom = match($statTab) {
        'month' => date('Y-m-01'),
        'year'  => date('Y-01-01'),
        default => '2000-01-01',
    };
    $mercWhere = $statTab === 'merc' ? 'AND mpr.is_mercenary=1' : '';

    $stats = $pdo->prepare("
        SELECT
            COUNT(DISTINCT mpr.match_id) AS matches_played,
            COALESCE(SUM(mpr.goals),0)   AS goals,
            COALESCE(SUM(mpr.assists),0) AS assists,
            COALESCE(SUM(mpr.is_checked_in),0) AS attended,
            COALESCE(SUM(mpr.is_mercenary),0)  AS merc_count
        FROM match_player_records mpr
        WHERE mpr.user_id=? AND mpr.match_date>=? $mercWhere
    ");
    $stats->execute([$uid, $dateFrom]); $stats = $stats->fetch();

    $totalMatches = (int)$stats['matches_played'];
    $attRate = $totalMatches > 0 ? round($stats['attended'] / $totalMatches * 100) : 0;

    $ci = $pdo->prepare("SELECT COUNT(*) FROM checkins WHERE user_id=?");
    $ci->execute([$uid]); $ci = (int)$ci->fetchColumn();

    // 지역 내 공격포인트 랭킹
    $myRegion = $u['region'] ?? null;
    $myRank = null;
    if ($myRegion) {
        $rk = $pdo->prepare("
            SELECT COUNT(*)+1 FROM (
                SELECT u2.id, COALESCE(SUM(mpr2.goals+mpr2.assists),0) AS ap
                FROM users u2
                JOIN team_members tm2 ON tm2.user_id=u2.id AND tm2.status='active'
                JOIN teams t2 ON t2.id=tm2.team_id AND t2.region=?
                LEFT JOIN match_player_records mpr2 ON mpr2.user_id=u2.id AND mpr2.match_date>=?
                GROUP BY u2.id
                HAVING ap > (SELECT COALESCE(SUM(goals+assists),0) FROM match_player_records WHERE user_id=? AND match_date>=?)
            ) sub
        ");
        $rk->execute([$myRegion, $dateFrom, $uid, $dateFrom]);
        $myRank = (int)$rk->fetchColumn();
    }

    $apps = $pdo->prepare("SELECT mr.*,m.match_date,m.title AS mtitle,m.location FROM match_requests mr JOIN matches m ON m.id=mr.match_id WHERE mr.requested_by=? ORDER BY mr.created_at DESC LIMIT 10");
    $apps->execute([$uid]); $apps = $apps->fetchAll();

    $dbUser = $pdo->prepare("SELECT name, nickname, profile_image_url, jersey_number, manner_score, goals, assists, is_player_background, position, sub_positions, height, weight, preferred_foot, mom_count, restricted_until,
        stat_pace, stat_shooting, stat_passing, stat_dribbling, stat_defending, stat_physical, position_prefs FROM users WHERE id=?");
    $dbUser->execute([$uid]); $dbUser = $dbUser->fetch();

    // 내가 배정된 쿼터 목록 (예정 경기만)
    $myQuarters = $pdo->prepare("
        SELECT mq.quarter, mq.position, m.match_date, m.match_time, m.location, m.title, m.id AS match_id,
               t.name AS team_name
        FROM match_quarters mq
        JOIN matches m ON m.id=mq.match_id
        JOIN teams t ON t.id=mq.team_id
        WHERE mq.user_id=? AND m.match_date >= CURDATE()
        ORDER BY m.match_date, m.match_time, mq.quarter
    ");
    $myQuarters->execute([$uid]); $myQuarters = $myQuarters->fetchAll(); ?>
<div class="container">
  <!-- 프로필 카드 -->
  <?php $meWithAvatar = array_merge($u, ['profile_image_url' => $dbUser['profile_image_url'] ?? null]); ?>
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="display:flex;align-items:center;gap:16px">
    <!-- [B1] 아바타 영역 — 클릭으로 업로드 트리거 -->
    <label for="avatarUpload" style="cursor:pointer;position:relative;flex-shrink:0" title="클릭하여 사진 변경">
      <?php if (!empty($dbUser['profile_image_url'])): ?>
        <span style="width:56px;height:56px;border-radius:50%;display:inline-block;overflow:hidden;background:#000" onclick="event.preventDefault();tfViewPhoto('<?=h($dbUser['profile_image_url'])?>')">
          <img src="<?=h($dbUser['profile_image_url'])?>" alt="" style="width:100%;height:100%;object-fit:cover">
        </span>
      <?php else: ?>
        <div style="width:56px;height:56px;border-radius:50%;background:var(--primary);color:#0F1117;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:900">
          <?=mb_substr($u['name'],0,1,'UTF-8')?>
        </div>
      <?php endif; ?>
      <span style="position:absolute;bottom:-2px;right:-2px;width:20px;height:20px;border-radius:50%;background:var(--primary);color:#0F1117;display:flex;align-items:center;justify-content:center;font-size:11px;border:2px solid var(--bg-surface)"><i class="bi bi-camera-fill"></i></span>
    </label>
    <form method="POST" enctype="multipart/form-data" id="avatarForm" style="display:none">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="upload_profile_image">
      <input type="file" id="avatarUpload" name="avatar" accept="image/jpeg,image/png,image/webp" onchange="document.getElementById('avatarForm').submit()">
    </form>
    <?php if (!empty($dbUser['profile_image_url'])): ?>
    <form method="POST" style="display:none" id="avatarDelForm"><?=csrfInput()?><input type="hidden" name="action" value="delete_profile_image"></form>
    <button type="button" onclick="if(confirm('프로필 사진을 삭제할까요?')) document.getElementById('avatarDelForm').submit()"
      style="position:absolute;top:-4px;left:-4px;width:20px;height:20px;border-radius:50%;background:#ff4d6d;color:#fff;border:2px solid var(--bg-surface);font-size:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:1" title="사진 삭제">✕</button>
    <?php endif; ?>
    <div style="flex:1">
      <div style="font-size:18px;font-weight:700"><?=h($u['name'])?></div>
      <div style="font-size:13px;color:var(--text-sub)"><?=h($u['phone'])?></div>
      <div style="margin-top:4px;display:flex;gap:6px;flex-wrap:wrap">
        <span class="badge <?=$u['system_role']==='admin'?'badge-red':(me()['is_captain']?'badge-yellow':'badge-gray')?>">
          <?=$u['system_role']==='admin'?'관리자':(me()['is_captain']?'캡틴':'일반')?>
        </span>
        <?php if($dbUser['is_player_background']??0): ?>
        <span class="badge badge-blue" style="font-size:10px">⚽ 선수출신</span>
        <?php endif; ?>
        <?php if($dbUser['height']??0): ?>
        <span class="badge badge-gray" style="font-size:10px">📏 <?=(int)$dbUser['height']?>cm</span>
        <?php endif; ?>
        <?php if($dbUser['weight']??0): ?>
        <span class="badge badge-gray" style="font-size:10px"><?=(int)$dbUser['weight']?>kg</span>
        <?php endif; ?>
        <?php if($dbUser['preferred_foot']??null):
          $fLbl = ['LEFT'=>'왼발','RIGHT'=>'오른발','BOTH'=>'양발'][$dbUser['preferred_foot']] ?? '';
          $fCol = ['LEFT'=>'#3a9ef5','RIGHT'=>'#ff9500','BOTH'=>'#00ff88'][$dbUser['preferred_foot']] ?? '#888'; ?>
        <span class="badge" style="font-size:10px;background:<?=$fCol?>20;color:<?=$fCol?>;border:1px solid <?=$fCol?>40">🦶 <?=$fLbl?></span>
        <?php endif; ?>
        <?php if($myRank): ?>
        <span class="badge badge-blue">지역 <?=$myRank?>위</span>
        <?php endif; ?>
        <?php if((int)($dbUser['mom_count']??0)>0): ?>
        <span class="badge" style="font-size:10px;background:rgba(255,214,10,0.2);color:#ffd60a;border:1px solid rgba(255,214,10,0.4)">🏆 MOM x<?=(int)$dbUser['mom_count']?></span>
        <?php endif; ?>
        <?php if(($dbUser['restricted_until']??null) && strtotime($dbUser['restricted_until'])>time()): ?>
        <span class="badge badge-red" style="font-size:10px">🚫 ~<?=date('m/d', strtotime($dbUser['restricted_until']))?> 제한</span>
        <?php endif; ?>
      </div>
    </div>
    <div style="text-align:center">
      <div style="font-size:22px;font-weight:900;color:var(--primary);font-family:'Space Grotesk',sans-serif"><?=number_format((float)($dbUser['manner_score']??36.5),1)?></div>
      <div style="font-size:11px;color:var(--text-sub)">매너점수</div>
    </div>
  </div></div>

  <!-- 기간 탭 -->
  <div class="chip-row">
    <?php foreach(['all'=>'전체','month'=>'이번달','year'=>'올해','merc'=>'용병'] as $k=>$v): ?>
    <a href="?page=mypage&stat=<?=$k?>" class="chip <?=$statTab===$k?'active':''?>"><?=$v?></a>
    <?php endforeach; ?>
  </div>

  <!-- 팀 바로가기 -->
  <?php if(myTeamId()):
    $myTeamInfo2 = $pdo->prepare("SELECT t.name, t.region, t.invite_code, (SELECT COUNT(*) FROM team_members WHERE team_id=t.id AND status='active' AND role!='mercenary') AS cnt FROM teams t WHERE t.id=?");
    $myTeamInfo2->execute([myTeamId()]); $mti = $myTeamInfo2->fetch();
  ?>
  <a href="?page=team" class="card" style="margin-bottom:12px;text-decoration:none;border:1.5px solid var(--primary);background:linear-gradient(135deg,rgba(0,255,136,0.08) 0%,rgba(0,255,136,0.02) 100%)">
    <div class="card-body" style="display:flex;align-items:center;gap:12px;padding:14px 16px">
      <div style="width:44px;height:44px;border-radius:12px;background:rgba(0,255,136,0.15);display:flex;align-items:center;justify-content:center;font-size:22px;border:1px solid rgba(0,255,136,0.2)">🛡️</div>
      <div style="flex:1">
        <div style="font-size:10px;color:var(--primary);font-weight:600;margin-bottom:2px">MY TEAM</div>
        <div style="font-weight:800;font-size:16px;color:var(--text)"><?=h(teamDisplayName($mti['name']??'내 팀'))?></div>
        <div style="font-size:11px;color:var(--text-sub);margin-top:1px"><?=h($mti['region']??'')?> · 팀원 <?=(int)($mti['cnt']??0)?>명</div>
      </div>
      <div style="background:var(--primary);color:#0F1117;padding:6px 10px;border-radius:8px;font-size:11px;font-weight:700">입장 →</div>
    </div>
  </a>
  <?php else: ?>
  <a href="?page=team_create" class="card" style="margin-bottom:12px;text-decoration:none;border:1px dashed rgba(0,255,136,0.3)">
    <div class="card-body" style="text-align:center;padding:14px">
      <div style="font-size:13px;color:var(--primary);font-weight:600">🛡️ 팀 만들기 / 가입하기</div>
    </div>
  </a>
  <?php endif; ?>

  <!-- 스탯 그리드 -->
  <div class="stat-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:14px">
    <div class="stat-box"><div class="stat-val"><?=$totalMatches?></div><div class="stat-lbl">경기</div></div>
    <div class="stat-box"><div class="stat-val" style="color:var(--primary)"><?=(int)$stats['goals']?></div><div class="stat-lbl">골</div></div>
    <div class="stat-box"><div class="stat-val"><?=(int)$stats['assists']?></div><div class="stat-lbl">어시</div></div>
    <div class="stat-box"><div class="stat-val"><?=$attRate?>%</div><div class="stat-lbl">출석률</div></div>
    <div class="stat-box"><div class="stat-val"><?=(int)$stats['merc_count']?></div><div class="stat-lbl">용병</div></div>
  </div>

  <!-- 능력치 레이더 차트 -->
  <?php
  $radarStats = [
    (int)($dbUser['stat_pace']     ?? 50),
    (int)($dbUser['stat_shooting'] ?? 50),
    (int)($dbUser['stat_passing']  ?? 50),
    (int)($dbUser['stat_dribbling']?? 50),
    (int)($dbUser['stat_defending']?? 50),
    (int)($dbUser['stat_physical'] ?? 50),
  ];
  $radarPos = $dbUser['position'] ?? 'FW';
  $hasStats = array_sum($radarStats) !== 300; // 모두 기본값(50)이면 미표시
  ?>
  <?php if($hasStats || true): ?>
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="text-align:center">
    <p class="section-title" style="margin-bottom:4px">능력치</p>
    <?=statRadar($radarStats, $radarPos, 200)?>
  </div></div>
  <?php endif; ?>

  <div class="form-row" style="margin-bottom:16px">
    <a href="?page=fees" class="btn btn-outline"><i class="bi bi-people"></i> 회원명단</a>
    <a href="?page=dues" class="btn btn-outline"><i class="bi bi-cash-coin"></i> 회비 관리</a>
    <a href="?page=reports" class="btn btn-outline"><i class="bi bi-flag"></i> 신고 내역</a>
  </div>

  <!-- 내 회비 납부 현황 -->
<?php if(myTeamId()):
  $myDuesYear = (int)date('Y');
  $myDuesData = $pdo->prepare("SELECT year_month, status, amount FROM team_dues_payments WHERE team_id=? AND user_id=? AND year_month LIKE ? ORDER BY year_month");
  $myDuesData->execute([myTeamId(), $uid, $myDuesYear.'%']);
  $myDues = [];
  foreach($myDuesData->fetchAll() as $d) { $myDues[$d['year_month']] = $d; }
  $myPaidCount = count(array_filter($myDues, fn($d)=>$d['status']==='paid'));
  $myTotalPaid = array_sum(array_map(fn($d)=>$d['status']==='paid'?(int)$d['amount']:0, $myDues));
?>
<div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:12px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
    <span style="font-size:14px;font-weight:700">💰 내 회비 (<?=$myDuesYear?>)</span>
    <a href="?page=dues" style="font-size:11px;color:var(--primary)">상세 보기 →</a>
  </div>
  <div style="display:flex;gap:4px;margin-bottom:8px">
    <?php for($dm=1;$dm<=12;$dm++):
      $dym = sprintf('%04d-%02d', $myDuesYear, $dm);
      $dst = $myDues[$dym]['status'] ?? 'none';
      $dcol = match($dst) { 'paid'=>'var(--primary)', 'unpaid'=>'var(--danger)', 'partial'=>'#ffd60a', 'exempt'=>'rgba(255,255,255,0.2)', default=>'rgba(255,255,255,0.08)' };
      $dlbl = match($dst) { 'paid'=>'✓', 'unpaid'=>'✕', 'partial'=>'½', 'exempt'=>'-', default=>'' };
    ?>
    <div style="flex:1;text-align:center">
      <div style="font-size:8px;color:var(--text-sub);margin-bottom:2px"><?=$dm?>월</div>
      <div style="width:100%;aspect-ratio:1;border-radius:4px;background:<?=$dcol?>;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:<?=$dst==='paid'?'#000':'#fff'?>"><?=$dlbl?></div>
    </div>
    <?php endfor; ?>
  </div>
  <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-sub)">
    <span>납부: <?=$myPaidCount?>개월</span>
    <span>총액: <?=number_format($myTotalPaid)?>원</span>
  </div>
</div></div>
<?php endif; ?>

  <!-- 내 쿼터 배정 -->
  <?php if($myQuarters): ?>
  <p class="section-title" style="margin-bottom:10px">🏟️ 내 쿼터 배정 (예정 경기)</p>
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:0 16px">
    <?php
    $qtByMatch = [];
    foreach($myQuarters as $q) $qtByMatch[$q['match_id']][] = $q;
    foreach($qtByMatch as $mid => $qs): $q0 = $qs[0];
      $daysLeft = (int)floor((strtotime($q0['match_date']) - strtotime(date('Y-m-d'))) / 86400);
      $dStr = $daysLeft === 0 ? '오늘!' : ($daysLeft < 0 ? '경기 종료' : 'D-'.$daysLeft);
    ?>
    <div style="padding:12px 0;border-bottom:1px solid var(--border)">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
        <div>
          <div style="font-weight:700;font-size:14px"><?=h($q0['title'] ?: $q0['location'])?></div>
          <div style="font-size:12px;color:var(--text-sub)"><?=$q0['match_date']?> <?=dayOfWeek($q0['match_date'])?> <?=substr($q0['match_time'],0,5)?> · <?=h($q0['location']??'')?></div>
        </div>
        <span style="font-size:11px;font-weight:700;color:<?=$daysLeft===0?'var(--danger)':($daysLeft<=3?'var(--warning)':'var(--primary)')?>;"><?=$dStr?></span>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <?php foreach($qs as $q): ?>
        <div style="background:var(--bg-surface-alt);border-radius:8px;padding:6px 12px;text-align:center;border:1px solid var(--border)">
          <div style="font-size:10px;color:var(--text-sub)">Q<?=$q['quarter']?></div>
          <div style="font-size:13px;font-weight:700;color:var(--primary)"><?=h($q['position'])?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <a href="?page=match&id=<?=$mid?>#lineup" style="font-size:12px;color:var(--text-sub);text-decoration:none;display:inline-block;margin-top:6px">경기 상세 보기 →</a>
    </div>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>

  <p class="section-title">신청 내역</p>
  <?php if(!$apps): ?>
    <p style="color:var(--text-sub);text-align:center;padding:30px 0">신청 내역이 없습니다.</p>
  <?php else: ?>
  <div class="card"><div class="card-body" style="padding:0 16px">
    <?php foreach($apps as $a):
      $daysLeft2 = (int)floor((strtotime($a['match_date']) - strtotime(date('Y-m-d'))) / 86400);
      $dStr2 = $daysLeft2 < 0 ? '' : ($daysLeft2 === 0 ? '오늘' : 'D-'.$daysLeft2);
    ?>
    <div class="list-item">
      <div><div style="font-weight:600"><?=h($a['mtitle']??'-')?></div>
        <div style="font-size:12px;color:var(--text-sub)"><?=$a['match_date']?> <?=dayOfWeek($a['match_date'])?><?=$dStr2?' · <span style="color:'.($daysLeft2<=3?'var(--warning)':'var(--text-sub)').'">'.$dStr2.'</span>':''?> · <?=h($a['location']??'')?></div></div>
      <?php $bc=['pending'=>'badge-yellow','accepted'=>'badge-green','rejected'=>'badge-red','cancelled'=>'badge-gray'];
            $bl=['pending'=>'대기','accepted'=>'수락','rejected'=>'거절','cancelled'=>'취소']; ?>
      <span class="badge <?=$bc[$a['status']]??'badge-gray'?>"><?=$bl[$a['status']]??$a['status']?></span>
    </div>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>

  <!-- [기능 소개] 앱 가이드 링크 -->
  <div style="margin-top:14px;text-align:center">
    <a href="?page=guide" style="font-size:12px;color:var(--text-sub);text-decoration:none;padding:8px 16px;border:1px dashed rgba(255,255,255,0.15);border-radius:20px;display:inline-block">
      📖 앱 기능 전체 보기
    </a>
  </div>

  <!-- [지난 경기] 최근 완료/취소된 내 경기 10건 -->
  <?php
    $historyQ = $pdo->prepare("
      SELECT mpr.match_id, mpr.is_mercenary, mpr.goals, mpr.assists, mpr.is_checked_in,
             m.title, m.match_date, m.match_time, m.location, m.status, m.uniform_color,
             ht.name AS home_name, at.name AS away_name,
             COALESCE(t.name, t2.name) AS played_team_name,
             res.score_home, res.score_away
      FROM match_attendance ma2
      JOIN matches m ON m.id = ma2.match_id
      LEFT JOIN match_player_records mpr ON mpr.match_id = ma2.match_id AND mpr.user_id = ma2.user_id
      LEFT JOIN teams ht ON ht.id = m.home_team_id
      LEFT JOIN teams at ON at.id = m.away_team_id
      LEFT JOIN teams t  ON t.id  = mpr.team_id
      LEFT JOIN teams t2 ON t2.id = ma2.team_id
      LEFT JOIN match_results res ON res.match_id = m.id
      WHERE ma2.user_id = ? AND ma2.status = 'ATTEND'
        AND (m.status IN ('completed','cancelled','force_closed') OR m.match_date < CURDATE())
      ORDER BY m.match_date DESC, m.match_time DESC
      LIMIT 10
    ");
    $historyQ->execute([$uid]);
    $myHistory = $historyQ->fetchAll();
  ?>
  <?php if ($myHistory): ?>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-top:18px">
    <p class="section-title" style="margin:0"><i class="bi bi-clock-history"></i> 내 지난 경기 <?=count($myHistory)?>건</p>
    <a href="?page=history" style="font-size:11px;color:var(--primary);text-decoration:none">전체 보기 →</a>
  </div>
  <div class="card"><div class="card-body" style="padding:4px 14px">
    <?php foreach ($myHistory as $mh):
      $scoreStr = ($mh['score_home'] !== null && $mh['score_away'] !== null) ? ((int)$mh['score_home'].' : '.(int)$mh['score_away']) : null;
      $title    = $mh['title'] ?: (($mh['home_name'] ?? '?').' vs '.($mh['away_name'] ?? '?'));
      $statusLbl = match($mh['status']){
        'cancelled'    => '취소',
        'force_closed' => '강제종료',
        'completed'    => '완료',
        default        => '지난 경기',
      };
      $statusCol = $mh['status']==='cancelled' ? 'var(--text-sub)' : ($mh['status']==='force_closed'?'#ff4d6d':'var(--primary)');
    ?>
    <a href="?page=match&id=<?=(int)$mh['match_id']?>" style="display:flex;gap:8px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.05);color:inherit;text-decoration:none">
      <?= uniformDot($mh['uniform_color'] ?? '', 14) ?>
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
          <?php if ((int)$mh['is_mercenary'] === 1): ?>
          <span class="badge" style="background:rgba(255,180,0,0.15);color:#ffb400;font-size:9px">⚡ 용병</span>
          <?php else: ?>
          <span class="badge" style="background:rgba(0,255,136,0.12);color:var(--primary);font-size:9px">🛡️ 팀</span>
          <?php endif; ?>
          <span style="font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($title)?></span>
        </div>
        <div style="font-size:10px;color:var(--text-sub);margin-top:2px">
          <?=$mh['match_date']?> · <?=h($mh['played_team_name'] ?? '-')?>
          · <span style="color:<?=$statusCol?>;font-weight:600"><?=$statusLbl?></span>
        </div>
      </div>
      <div style="text-align:right;flex-shrink:0">
        <?php if ($scoreStr): ?>
        <div style="font-size:12px;font-family:'Space Grotesk',sans-serif;font-weight:700"><?=$scoreStr?></div>
        <?php endif; ?>
        <div style="font-size:10px;color:var(--text-sub)">⚽<?=(int)$mh['goals']?> 🎯<?=(int)$mh['assists']?></div>
      </div>
    </a>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>

  <!-- 포인트 + 활동 -->
  <div class="card" style="margin-top:16px">
    <div class="card-body" style="padding:12px 16px">
      <div style="display:flex;align-items:center;justify-content:space-between">
        <div>
          <div style="font-size:12px;color:var(--text-sub)">내 포인트</div>
          <div style="font-size:24px;font-weight:800;color:var(--primary)"><?=number_format($myPoints)?> <span style="font-size:13px">P</span></div>
        </div>
        <a href="?page=point_history" style="font-size:12px;color:var(--text-sub)">적립 내역 ></a>
      </div>
    </div>
  </div>

  <!-- 메뉴 -->
  <div class="card" style="margin-top:12px"><div class="card-body" style="padding:0">
    <?php if(isCaptain()): ?>
    <a href="?page=team_points" style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.04)">
      <span style="font-size:18px">🎁</span>
      <div style="flex:1"><div style="font-size:13px;font-weight:600">팀 포인트 배분</div><div style="font-size:11px;color:var(--text-sub)">경기 포인트를 팀원에게!</div></div>
      <span style="color:var(--text-sub)">›</span>
    </a>
    <?php endif; ?>
    <a href="?page=bug_report" style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.04)">
      <span style="font-size:18px">🐛</span>
      <div style="flex:1"><div style="font-size:13px;font-weight:600">버그/오류 신고</div><div style="font-size:11px;color:var(--text-sub)">신고하면 포인트 적립!</div></div>
      <span style="color:var(--text-sub)">›</span>
    </a>
    <a href="?page=point_history" style="display:flex;align-items:center;gap:10px;padding:12px 16px">
      <span style="font-size:18px">📊</span>
      <div style="flex:1"><div style="font-size:13px;font-weight:600">포인트 내역</div><div style="font-size:11px;color:var(--text-sub)">활동별 적립 기록</div></div>
      <span style="color:var(--text-sub)">›</span>
    </a>
  </div></div>


  <!-- 선수카드 공유 -->
  <details style="margin-top:16px">
    <summary style="font-size:14px;font-weight:600;color:var(--text-sub);cursor:pointer;padding:10px 0">📸 내 선수카드</summary>
    <div class="card" style="margin-top:8px"><div class="card-body">
      <canvas id="myPlayerCardCanvas" style="width:100%;max-width:400px;border-radius:12px;display:none"></canvas>
      <div id="myCardPlaceholder" style="text-align:center;padding:20px;color:var(--text-sub);font-size:13px">
        미리보기를 눌러 카드를 생성하세요
      </div>
      <div style="display:flex;gap:8px;margin-top:12px">
        <button type="button" class="btn btn-outline" style="flex:1" onclick="previewMyCard()">미리보기</button>
        <button type="button" class="btn btn-primary" style="flex:1" onclick="downloadMyCard()">저장/공유</button>
      </div>
    </div></div>
  </details>

  <script>
  function previewMyCard() {
    var uid = document.querySelector('meta[name="user-id"]');
    var userId = uid ? uid.content : '';
    if(!userId){ alert('로그인 필요'); return; }
    fetch('?page=api&fn=user_profile&id=' + userId, {credentials:'same-origin'})
      .then(function(r){return r.json()})
      .then(function(data){
        var d = data.user || data;
        var canvas = _genPlayerCard(d);
        var target = document.getElementById('myPlayerCardCanvas');
        if(target){
          target.width = 1080; target.height = 1350;
          target.getContext('2d').drawImage(canvas,0,0);
          target.style.display = 'block';
          document.getElementById('myCardPlaceholder').style.display = 'none';
        }
      }).catch(function(e){ alert('카드 생성 실패: ' + (e.message||e)); });
  }
  function downloadMyCard() {
    var uid = document.querySelector('meta[name="user-id"]');
    var userId = uid ? uid.content : '';
    fetch('?page=api&fn=user_profile&id=' + userId, {credentials:'same-origin'})
      .then(function(r){return r.json()})
      .then(function(data){
        var d = data.user || data;
        var canvas = _genPlayerCard(d);
        canvas.toBlob(function(blob) {
          var file = new File([blob], 'trust_football_card.png', {type:'image/png'});
          if (navigator.share && navigator.canShare && navigator.canShare({files:[file]})) {
            navigator.share({title:'TRUST FOOTBALL', files:[file]}).catch(function(){});
          } else {
            var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
            a.download = file.name; a.click(); URL.revokeObjectURL(a.href);
          }
        }, 'image/png');
      }).catch(function(e){ alert('카드 저장 실패: ' + (e.message||e)); });
  }
  function _genPlayerCard(data) {
    var W = 1080, H = 1350;
    var canvas = document.createElement('canvas');
    canvas.width = W; canvas.height = H;
    var ctx = canvas.getContext('2d');

    // 배경 - 다크 그라데이션 + 패턴
    var grad = ctx.createLinearGradient(0,0,W,H);
    grad.addColorStop(0,'#0a0e1a'); grad.addColorStop(0.5,'#111827'); grad.addColorStop(1,'#0a0e1a');
    ctx.fillStyle = grad; ctx.fillRect(0,0,W,H);

    // 배경 장식 - 대각선 스트라이프
    ctx.save();
    ctx.globalAlpha = 0.03;
    for(var i=-H; i<W+H; i+=40){
      ctx.beginPath(); ctx.moveTo(i,0); ctx.lineTo(i+H,H);
      ctx.strokeStyle='#fff'; ctx.lineWidth=1; ctx.stroke();
    }
    ctx.restore();

    // 상단 네온 라인
    var neonGrad = ctx.createLinearGradient(0,0,W,0);
    neonGrad.addColorStop(0,'#00ff88'); neonGrad.addColorStop(0.5,'#00d4ff'); neonGrad.addColorStop(1,'#ff6b6b');
    ctx.fillStyle = neonGrad; ctx.fillRect(0,0,W,6);

    // 포지션 + 등번호 상단
    var pos = data.position || 'MF';
    ctx.fillStyle = '#00ff88'; ctx.fillRect(60,50,160,70);
    ctx.fillStyle = '#0a0e1a'; ctx.font = 'bold 36px sans-serif'; ctx.textAlign = 'center';
    ctx.fillText(pos, 140, 97);

    if(data.jersey_number) {
      ctx.fillStyle = '#ffb400'; ctx.font = 'bold 140px sans-serif'; ctx.textAlign = 'right';
      ctx.fillText('#' + data.jersey_number, W-60, 140);
    }

    // 이름 (크고 굵게)
    ctx.fillStyle = '#fff'; ctx.font = 'bold 72px sans-serif'; ctx.textAlign = 'center';
    ctx.fillText(data.name || '', W/2, 250);

    // 닉네임
    if (data.nickname && data.nickname !== data.name) {
      ctx.fillStyle = 'rgba(255,255,255,0.5)'; ctx.font = '32px sans-serif';
      ctx.fillText(data.nickname, W/2, 300);
    }

    // 팀명 배지
    ctx.fillStyle = 'rgba(0,255,136,0.1)';
    var teamText = data.team_name || 'FREE AGENT';
    ctx.fillRect(W/2-120, 320, 240, 50);
    ctx.fillStyle = '#00ff88'; ctx.font = 'bold 28px sans-serif';
    ctx.fillText(teamText, W/2, 353);

    // 구분선 (그라데이션)
    var lineGrad = ctx.createLinearGradient(80,0,W-80,0);
    lineGrad.addColorStop(0,'transparent'); lineGrad.addColorStop(0.5,'rgba(0,255,136,0.4)'); lineGrad.addColorStop(1,'transparent');
    ctx.fillStyle = lineGrad; ctx.fillRect(80,400,W-160,2);

    // 6각형 레이더 차트 (힙한 육각형)
    var cx = W/2, cy = 600, radius = 160;
    var stats = [
      {label:'PAC', val: data.stat_pace||50},
      {label:'SHO', val: data.stat_shooting||50},
      {label:'PAS', val: data.stat_passing||50},
      {label:'DRI', val: data.stat_dribbling||50},
      {label:'DEF', val: data.stat_defending||50},
      {label:'PHY', val: data.stat_physical||50}
    ];

    // 배경 육각형 (가이드)
    for(var ring=1; ring<=4; ring++){
      ctx.beginPath();
      var rr = radius * ring/4;
      for(var i=0;i<6;i++){
        var angle = Math.PI/2 + i*Math.PI/3;
        var px = cx + rr*Math.cos(angle), py = cy - rr*Math.sin(angle);
        if(i===0) ctx.moveTo(px,py); else ctx.lineTo(px,py);
      }
      ctx.closePath();
      ctx.strokeStyle = 'rgba(255,255,255,'+(ring===4?'0.15':'0.06')+')';
      ctx.lineWidth = 1; ctx.stroke();
    }

    // 값 육각형 (채우기)
    ctx.beginPath();
    for(var i=0;i<6;i++){
      var angle = Math.PI/2 + i*Math.PI/3;
      var val = Math.min(stats[i].val, 99) / 99;
      var px = cx + radius*val*Math.cos(angle), py = cy - radius*val*Math.sin(angle);
      if(i===0) ctx.moveTo(px,py); else ctx.lineTo(px,py);
    }
    ctx.closePath();
    ctx.fillStyle = 'rgba(0,255,136,0.15)';
    ctx.fill();
    ctx.strokeStyle = '#00ff88'; ctx.lineWidth = 3; ctx.stroke();

    // 꼭짓점 + 라벨
    for(var i=0;i<6;i++){
      var angle = Math.PI/2 + i*Math.PI/3;
      var val = Math.min(stats[i].val, 99) / 99;
      var px = cx + radius*val*Math.cos(angle), py = cy - radius*val*Math.sin(angle);
      // 점
      ctx.beginPath(); ctx.arc(px,py,6,0,Math.PI*2);
      ctx.fillStyle = '#00ff88'; ctx.fill();
      // 라벨
      var lx = cx + (radius+40)*Math.cos(angle), ly = cy - (radius+40)*Math.sin(angle);
      ctx.fillStyle = 'rgba(255,255,255,0.6)'; ctx.font = 'bold 22px sans-serif'; ctx.textAlign = 'center';
      ctx.fillText(stats[i].label, lx, ly+8);
      // 수치
      var vx = cx + (radius+70)*Math.cos(angle), vy = cy - (radius+70)*Math.sin(angle);
      ctx.fillStyle = '#fff'; ctx.font = 'bold 28px sans-serif';
      ctx.fillText(stats[i].val, vx, vy+8);
    }

    // 하단 스탯 영역
    var bottomY = 830;
    ctx.fillStyle = 'rgba(255,255,255,0.03)'; ctx.fillRect(60,bottomY,W-120,420);

    // 매너 점수 (큰 원)
    ctx.beginPath(); ctx.arc(W/2, bottomY+80, 60, 0, Math.PI*2);
    ctx.fillStyle = 'rgba(0,255,136,0.1)'; ctx.fill();
    ctx.strokeStyle = '#00ff88'; ctx.lineWidth = 4; ctx.stroke();
    ctx.fillStyle = '#fff'; ctx.font = 'bold 44px sans-serif'; ctx.textAlign = 'center';
    ctx.fillText(data.manner_score||0, W/2, bottomY+95);
    ctx.fillStyle = 'rgba(255,255,255,0.5)'; ctx.font = '18px sans-serif';
    ctx.fillText('MANNER', W/2, bottomY+130);

    // 4개 스탯 카드
    var cardData = [
      {icon:'MOM', val:data.mom_count||0, color:'#ffb400'},
      {icon:'GOAL', val:data.goals_total||0, color:'#ff6b6b'},
      {icon:'ASST', val:data.assists_total||0, color:'#3a9ef5'},
      {icon:'ATT', val:(data.attendance_rate||0)+'%', color:'#00ff88'}
    ];
    var cardW = 200, cardGap = 30, startX = (W - cardW*4 - cardGap*3)/2;
    cardData.forEach(function(cd, i){
      var x = startX + i*(cardW+cardGap), y = bottomY+180;
      ctx.fillStyle = 'rgba(255,255,255,0.04)'; ctx.fillRect(x,y,cardW,100);
      ctx.fillStyle = cd.color; ctx.font = 'bold 36px sans-serif'; ctx.textAlign = 'center';
      ctx.fillText(cd.val, x+cardW/2, y+50);
      ctx.fillStyle = 'rgba(255,255,255,0.4)'; ctx.font = '16px sans-serif';
      ctx.fillText(cd.icon, x+cardW/2, y+80);
    });

    // 경기수
    ctx.fillStyle = 'rgba(255,255,255,0.4)'; ctx.font = '24px sans-serif'; ctx.textAlign = 'center';
    ctx.fillText((data.matches_played||0) + ' MATCHES PLAYED', W/2, bottomY+340);

    // 하단 네온 라인
    ctx.fillStyle = neonGrad; ctx.fillRect(0,H-6,W,6);

    // 워터마크
    ctx.fillStyle = 'rgba(255,255,255,0.15)'; ctx.font = 'bold 20px sans-serif';
    ctx.fillText('BALLOCHA', W/2, H-30);

    return canvas;
  }
  </script>

  <!-- 비밀번호 변경 -->
  <details style="margin-top:16px">
    <summary style="font-size:14px;font-weight:600;color:var(--text-sub);cursor:pointer;padding:10px 0">🔒 비밀번호 변경</summary>
    <div class="card" style="margin-top:8px"><div class="card-body">
      <form method="POST">
        <?=csrfInput()?>
        <input type="hidden" name="action" value="change_password">
        <div class="form-group">
          <label class="form-label">현재 비밀번호</label>
          <input type="password" name="current_password" class="form-control" required>
        </div>
        <div class="form-group">
          <label class="form-label">새 비밀번호 <span style="font-size:12px;color:var(--text-sub)">(6자 이상)</span></label>
          <input type="password" name="new_password" class="form-control" required minlength="6">
        </div>
        <div class="form-group">
          <label class="form-label">새 비밀번호 확인</label>
          <input type="password" name="new_password2" class="form-control" required minlength="6">
        </div>
        <button type="submit" class="btn btn-primary btn-w">비밀번호 변경</button>
      </form>
    </div></div>
  </details>

  <!-- 프로필 편집 -->
  <details style="margin-top:16px">
    <summary style="font-size:14px;font-weight:600;color:var(--text-sub);cursor:pointer;padding:10px 0">⚙️ 프로필 편집</summary>
    <div class="card" style="margin-top:8px"><div class="card-body">
      <form method="POST">
        <?=csrfInput()?>
        <input type="hidden" name="action" value="update_profile">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">이름 (실명)</label>
            <input type="text" class="form-control" value="<?=h($dbUser['name']??'')?>" readonly disabled style="opacity:0.7;cursor:not-allowed">
            <div style="font-size:11px;color:var(--text-sub);margin-top:4px">실명은 결제·인증용이며 수정할 수 없습니다.</div>
          </div>
          <div class="form-group">
            <label class="form-label">닉네임 <span style="color:var(--primary);font-size:11px">(앱 내 공개명)</span></label>
            <input type="text" name="nickname" class="form-control" value="<?=h($dbUser['nickname'] ?? $dbUser['name'] ?? '')?>" required minlength="2" maxlength="20" placeholder="2~20자">
          </div>
          <div class="form-group">
            <label class="form-label">등번호 <span style="color:var(--primary);font-size:11px">(1~99)</span></label>
            <input type="number" name="jersey_number" class="form-control" value="<?=h($dbUser['jersey_number']??'')?>" min="1" max="99" placeholder="예: 10" style="width:100px;text-align:center;font-size:18px;font-weight:700">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">활동 지역</label>
            <input type="text" name="region" class="form-control" value="<?=h($u['region']??'')?>"></div>
          <div class="form-group"><label class="form-label">구/군</label>
            <input type="text" name="district" class="form-control" value="<?=h($u['district']??'')?>"></div>
        </div>
        <div class="form-group">
          <label class="form-label">선호 포지션 <span style="font-size:11px;color:var(--text-sub)">(최대 3개, 첫 번째 선택이 주포지션)</span></label>
          <?php
            $posInfo2=[
              'GK'=>['#ff9500','GK','골키퍼'],
              'LB'=>['#3a9ef5','LB','좌측백'],'CB'=>['#3a9ef5','CB','센터백'],'RB'=>['#3a9ef5','RB','우측백'],
              'CDM'=>['#00c87a','CDM','수비미드'],'CM'=>['#00ff88','CM','센터미드'],
              'LM'=>['#00ff88','LM','좌측미드'],'RM'=>['#00ff88','RM','우측미드'],
              'CAM'=>['#ffd60a','CAM','공격미드'],
              'LW'=>['#ff6b6b','LW','좌측윙'],'ST'=>['#ff6b6b','ST','스트라이커'],'RW'=>['#ff6b6b','RW','우측윙'],
            ];
            $curPos = $u['position'] ?? '';
            $curSub = array_filter(explode(',', $u['sub_positions'] ?? ''));
            $allSelected = $curPos ? array_merge([$curPos], $curSub) : $curSub;
          ?>
          <input type="hidden" name="positions_json" id="positionsJson" value="<?=h(json_encode($allSelected))?>">
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:4px" id="posGrid">
            <?php foreach($posInfo2 as $p=>[$pc,$pl,$pd]):
              $isSelected = in_array($p, $allSelected);
              $order = $isSelected ? array_search($p, $allSelected) + 1 : 0;
            ?>
            <div class="pos-chip-wrap" data-pos="<?=$p?>" onclick="togglePos(this)" style="cursor:pointer">
              <div class="pos-chip <?=$isSelected?'active':''?>" style="--pos-color:<?=$pc?>;position:relative">
                <div style="font-size:13px;font-weight:700"><?=$pl?></div>
                <div style="font-size:9px;opacity:0.7;margin-top:1px"><?=$pd?></div>
                <?php if($isSelected): ?>
                <span class="pos-order" style="position:absolute;top:-4px;right:-4px;background:<?=$pc?>;color:#000;width:16px;height:16px;border-radius:50%;font-size:9px;font-weight:800;display:flex;align-items:center;justify-content:center"><?=$order?></span>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div id="posCount" style="font-size:11px;color:var(--text-sub);margin-top:4px;text-align:right"><?=count($allSelected)?>/3 선택</div>
          <script>
          var selectedPos = <?=json_encode($allSelected)?>;
          function togglePos(el) {
            var pos = el.dataset.pos;
            var idx = selectedPos.indexOf(pos);
            if (idx >= 0) {
              selectedPos.splice(idx, 1);
            } else {
              if (selectedPos.length >= 3) { alert('최대 3개까지 선택할 수 있습니다.'); return; }
              selectedPos.push(pos);
            }
            document.getElementById('positionsJson').value = JSON.stringify(selectedPos);
            document.getElementById('posCount').textContent = selectedPos.length + '/3 선택';
            document.querySelectorAll('.pos-chip-wrap').forEach(function(w) {
              var p = w.dataset.pos, i = selectedPos.indexOf(p);
              var chip = w.querySelector('.pos-chip');
              var badge = w.querySelector('.pos-order');
              if (i >= 0) {
                chip.classList.add('active');
                if (!badge) {
                  badge = document.createElement('span');
                  badge.className = 'pos-order';
                  badge.style.cssText = 'position:absolute;top:-4px;right:-4px;background:' + chip.style.getPropertyValue('--pos-color') + ';color:#000;width:16px;height:16px;border-radius:50%;font-size:9px;font-weight:800;display:flex;align-items:center;justify-content:center';
                  chip.appendChild(badge);
                }
                badge.textContent = i + 1;
              } else {
                chip.classList.remove('active');
                if (badge) badge.remove();
              }
            });
          }
          </script>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">키 (cm)</label>
            <input type="number" name="height" min="140" max="220" class="form-control" value="<?=h($dbUser['height']??'')?>" placeholder="예: 175">
          </div>
          <div class="form-group">
            <label class="form-label">몸무게 (kg)</label>
            <input type="number" name="weight" min="30" max="200" class="form-control" value="<?=h($dbUser['weight']??'')?>" placeholder="예: 70">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">주발</label>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px">
            <?php
            $footOpts = ['LEFT'=>['왼발','#3a9ef5'],'RIGHT'=>['오른발','#ff9500'],'BOTH'=>['양발','#00ff88']];
            foreach($footOpts as $fk=>[$fl,$fc]): ?>
            <label style="cursor:pointer">
              <input type="radio" name="preferred_foot" value="<?=$fk?>" <?=($dbUser['preferred_foot']??'')===$fk?'checked':''?> style="display:none" class="pos-radio2">
              <div class="pos-chip" style="--pos-color:<?=$fc?>">
                <div style="font-size:13px;font-weight:700"><?=$fl?></div>
              </div>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <label style="display:flex;align-items:center;gap:10px;margin-bottom:14px;cursor:pointer;padding:10px;background:var(--bg-surface-alt);border-radius:8px">
          <input type="checkbox" name="is_player_background" value="1" <?=($dbUser['is_player_background']??0)?'checked':''?> style="width:18px;height:18px;accent-color:var(--primary)">
          <div>
            <div style="font-weight:600;font-size:13px">⚽ 선수 출신</div>
            <div style="font-size:11px;color:var(--text-sub)">아마추어 클럽팀, 학교 선수단 등 경험자</div>
          </div>
        </label>

        <!-- 능력치 슬라이더 -->
        <div style="background:var(--bg-surface-alt);border-radius:10px;padding:14px;margin-bottom:14px">
          <div style="font-weight:700;font-size:13px;margin-bottom:12px">⚡ 능력치 설정</div>
          <style>
            .stat-slider-row{display:flex;align-items:center;gap:10px;margin-bottom:10px}
            .stat-slider-row label{font-size:12px;color:var(--text-sub);width:52px;flex-shrink:0}
            .stat-slider-row input[type=range]{flex:1;accent-color:var(--primary);height:4px}
            .stat-slider-val{font-size:13px;font-weight:700;color:var(--primary);font-family:'Space Grotesk',sans-serif;width:28px;text-align:right;flex-shrink:0}
          </style>
          <?php
          $sliders = [
            ['stat_pace',      '속도',   $dbUser['stat_pace']     ?? 50],
            ['stat_shooting',  '슈팅',   $dbUser['stat_shooting'] ?? 50],
            ['stat_passing',   '패스',   $dbUser['stat_passing']  ?? 50],
            ['stat_dribbling', '드리블', $dbUser['stat_dribbling']?? 50],
            ['stat_defending', '수비',   $dbUser['stat_defending']?? 50],
            ['stat_physical',  '피지컬', $dbUser['stat_physical'] ?? 50],
          ];
          foreach($sliders as [$sName, $sLabel, $sVal]): ?>
          <div class="stat-slider-row">
            <label><?=h($sLabel)?></label>
            <input type="range" name="<?=$sName?>" min="1" max="99" value="<?=(int)$sVal?>"
              oninput="this.nextElementSibling.textContent=this.value">
            <span class="stat-slider-val"><?=(int)$sVal?></span>
          </div>
          <?php endforeach; ?>
          <div style="font-size:11px;color:var(--text-sub);margin-top:4px">* 슬라이더를 조정해 본인의 능력치를 설정하세요 (1~99)</div>
        </div>

        <button type="submit" class="btn btn-primary btn-w">저장</button>
      </form>

      <!-- 선호 포지션 설정 -->
      <div style="background:var(--bg-surface-alt);border-radius:10px;padding:14px;margin-bottom:14px;margin-top:14px">
        <div style="font-weight:700;font-size:13px;margin-bottom:6px">🎯 선호 포지션 설정</div>
        <div style="font-size:11px;color:var(--text-sub);margin-bottom:10px">체크 후 드래그하여 우선순위를 정하세요. 위에 있을수록 높은 순위입니다.</div>
        <form method="POST" id="prefsPosForm">
          <?=csrfInput()?>
          <input type="hidden" name="action" value="save_position_prefs">
          <input type="hidden" name="position_prefs" id="prefsJson" value="<?=htmlspecialchars($dbUser['position_prefs'] ?? '[]', ENT_QUOTES)?>">
          <?php
          $savedPrefs = json_decode($dbUser['position_prefs'] ?? '[]', true);
          if (!is_array($savedPrefs)) $savedPrefs = [];
          $allPos = ['GK','LB','CB','RB','CDM','LM','CM','RM','CAM','LW','ST','RW'];
          $posColorMap = ['GK'=>'#ff9500','LB'=>'#3a9ef5','CB'=>'#3a9ef5','RB'=>'#3a9ef5',
            'CDM'=>'#00c87a','LM'=>'#00ff88','CM'=>'#00ff88','RM'=>'#00ff88','CAM'=>'#ffd60a',
            'LW'=>'#ff6b6b','ST'=>'#ff6b6b','RW'=>'#ff6b6b'];
          $sortedPos = array_merge($savedPrefs, array_diff($allPos, $savedPrefs));
          ?>
          <div id="prefsList" style="display:flex;flex-direction:column;gap:4px">
            <?php foreach($sortedPos as $pos):
              $checked = in_array($pos, $savedPrefs);
              $pc = $posColorMap[$pos] ?? '#888';
            ?>
            <div class="pref-item" data-pos="<?=$pos?>" draggable="true"
                 style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:rgba(255,255,255,0.03);border:1px solid <?=$checked ? $pc : 'rgba(255,255,255,0.08)'?>;border-radius:8px;cursor:grab;user-select:none;transition:all 0.2s">
              <span style="font-size:12px;color:var(--text-sub);width:18px;text-align:center" class="pref-rank"><?=$checked ? array_search($pos, $savedPrefs)+1 : ''?></span>
              <input type="checkbox" class="pref-cb" data-pos="<?=$pos?>" <?=$checked?'checked':''?>
                     style="width:16px;height:16px;accent-color:<?=$pc?>;flex-shrink:0"
                     onchange="updatePrefsJson()">
              <span style="font-size:13px;font-weight:600;color:<?=$pc?>;min-width:36px"><?=$pos?></span>
              <span style="font-size:10px;color:var(--text-sub);flex:1"><?=match($pos){
                'GK'=>'골키퍼','LB'=>'왼쪽 풀백','CB'=>'센터백','RB'=>'오른쪽 풀백',
                'CDM'=>'수비형 미드필더','LM'=>'왼쪽 미드필더','CM'=>'중앙 미드필더','RM'=>'오른쪽 미드필더',
                'CAM'=>'공격형 미드필더','LW'=>'왼쪽 윙','ST'=>'스트라이커','RW'=>'오른쪽 윙',default=>''
              }?></span>
              <span style="font-size:14px;color:var(--text-sub);cursor:grab">☰</span>
            </div>
            <?php endforeach; ?>
          </div>
          <button type="submit" class="btn btn-primary btn-w" style="margin-top:10px">선호 포지션 저장</button>
        </form>
      </div>
      <script>
      (function(){
        var list = document.getElementById('prefsList');
        var dragItem = null;
        list.addEventListener('dragstart', function(e) {
          dragItem = e.target.closest('.pref-item');
          if (dragItem) { dragItem.style.opacity = '0.4'; e.dataTransfer.effectAllowed = 'move'; }
        });
        list.addEventListener('dragend', function(e) {
          if (dragItem) dragItem.style.opacity = '1';
          dragItem = null;
        });
        list.addEventListener('dragover', function(e) {
          e.preventDefault(); e.dataTransfer.dropEffect = 'move';
          var target = e.target.closest('.pref-item');
          if (target && target !== dragItem) {
            var rect = target.getBoundingClientRect();
            var mid = rect.top + rect.height / 2;
            if (e.clientY < mid) { list.insertBefore(dragItem, target); }
            else { list.insertBefore(dragItem, target.nextSibling); }
          }
        });
        list.addEventListener('drop', function(e) { e.preventDefault(); updatePrefsJson(); });
        var touchItem = null;
        list.addEventListener('touchstart', function(e) {
          var item = e.target.closest('.pref-item');
          if (!item || e.target.tagName === 'INPUT') return;
          touchItem = item;
          touchItem.style.opacity = '0.4';
        }, {passive: true});
        list.addEventListener('touchmove', function(e) {
          if (!touchItem) return;
          e.preventDefault();
          var y = e.touches[0].clientY;
          var items = list.querySelectorAll('.pref-item');
          for (var i = 0; i < items.length; i++) {
            if (items[i] === touchItem) continue;
            var rect = items[i].getBoundingClientRect();
            if (y > rect.top && y < rect.bottom) {
              var mid = rect.top + rect.height / 2;
              if (y < mid) list.insertBefore(touchItem, items[i]);
              else list.insertBefore(touchItem, items[i].nextSibling);
              break;
            }
          }
        }, {passive: false});
        list.addEventListener('touchend', function(e) {
          if (touchItem) { touchItem.style.opacity = '1'; touchItem = null; updatePrefsJson(); }
        });
      })();
      function updatePrefsJson() {
        var items = document.querySelectorAll('#prefsList .pref-item');
        var prefs = [];
        var rank = 1;
        items.forEach(function(el) {
          var cb = el.querySelector('.pref-cb');
          var rankEl = el.querySelector('.pref-rank');
          var pos = cb.getAttribute('data-pos');
          var pc = {'GK':'#ff9500','LB':'#3a9ef5','CB':'#3a9ef5','RB':'#3a9ef5','CDM':'#00c87a','LM':'#00ff88','CM':'#00ff88','RM':'#00ff88','CAM':'#ffd60a','LW':'#ff6b6b','ST':'#ff6b6b','RW':'#ff6b6b'};
          if (cb.checked) {
            prefs.push(pos);
            rankEl.textContent = rank++;
            el.style.borderColor = pc[pos] || '#888';
          } else {
            rankEl.textContent = '';
            el.style.borderColor = 'rgba(255,255,255,0.08)';
          }
        });
        document.getElementById('prefsJson').value = JSON.stringify(prefs);
      }
      </script>
    </div></div>
  </details>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 랭킹
// ═══════════════════════════════════════════════════════════════
function pagRanking(PDO $pdo): void {
    $tab=$_GET['tab']??'team'; $region=$_GET['region']??''; $district=$_GET['district']??'';
    $regions=$pdo->query("SELECT DISTINCT region FROM teams WHERE region IS NOT NULL ORDER BY region")->fetchAll(PDO::FETCH_COLUMN); ?>
<div class="container">
  <div class="chip-row">
    <?php foreach(['team'=>'팀','player'=>'선수','league'=>'리그'] as $k=>$v): ?>
    <a href="?page=ranking&tab=<?=$k?><?=$region?'&region='.urlencode($region):''?>" class="chip <?=$tab===$k?'active':''?>"><?=$v?></a>
    <?php endforeach; ?>
  </div>
  <div class="chip-row">
    <a href="?page=ranking&tab=<?=$tab?>" class="chip <?=!$region?'active':''?>">전체</a>
    <?php foreach($regions as $r): ?>
    <a href="?page=ranking&tab=<?=$tab?>&region=<?=urlencode($r)?>" class="chip <?=$region===$r?'active':''?>"><?=h($r)?></a>
    <?php endforeach; ?>
  </div>
  <?php if($region):
    $dists=$pdo->prepare("SELECT DISTINCT district FROM teams WHERE region=? AND district IS NOT NULL ORDER BY district");
    $dists->execute([$region]);$dists=$dists->fetchAll(PDO::FETCH_COLUMN);
    if($dists): ?>
  <div class="chip-row">
    <a href="?page=ranking&tab=<?=$tab?>&region=<?=urlencode($region)?>" class="chip <?=!$district?'active':''?>">전체 구</a>
    <?php foreach($dists as $d): ?>
    <a href="?page=ranking&tab=<?=$tab?>&region=<?=urlencode($region)?>&district=<?=urlencode($d)?>" class="chip <?=$district===$d?'active':''?>"><?=h($d)?></a>
    <?php endforeach; ?>
  </div>
  <?php endif;endif;

  if($tab==='team'):
    $w="1=1";$p=[];
    if($region){$w.=" AND t.region=?";$p[]=$region;}
    if($district){$w.=" AND t.district=?";$p[]=$district;}
    // [팀 실력] 랭킹용으로 팀원수/선출수 서브쿼리 추가
    $s=$pdo->prepare("SELECT t.*,
        (SELECT COUNT(*) FROM team_members WHERE team_id=t.id AND status='active' AND role != 'mercenary') AS mem_cnt,
        (SELECT COUNT(*) FROM team_members WHERE team_id=t.id AND status='active' AND is_pro=1) AS pro_cnt
        FROM teams t WHERE $w ORDER BY (t.win*3+t.draw) DESC,t.win DESC LIMIT 50");
    $s->execute($p);$teams=$s->fetchAll(); ?>
  <div class="card" style="overflow-x:auto"><table class="tf-table">
    <thead><tr><th>#</th><th>팀명</th><th>실력</th><th>승</th><th>무</th><th>패</th><th>득</th><th>실</th><th>득실</th><th>승점</th></tr></thead>
    <tbody>
    <?php foreach($teams as $i=>$t):
      $gf=(int)($t['goals_for']??0); $ga=(int)($t['goals_against']??0); $gd=$gf-$ga;
      $tsR = calcTeamStrength($t, (int)$t['pro_cnt'], (int)$t['mem_cnt']);
    ?>
    <tr class="<?=$i===0?'rank-1':($i===1?'rank-2':'')?>">
      <td style="font-family:'Space Grotesk',sans-serif;font-weight:700;color:<?=$i===0?'var(--warning)':($i===1?'var(--text-sub)':'var(--text-sub)')?>"><?=$i+1?></td>
      <td><div style="font-weight:600"><?=h($t['name'])?></div><div style="font-size:11px;color:var(--text-sub)"><?=h($t['region']??'')?></div></td>
      <td><span title="<?=h($tsR['subtitle'])?>" style="display:inline-flex;align-items:center;gap:3px;padding:2px 6px;border-radius:999px;background:<?=$tsR['bg_hex']?>;color:<?=$tsR['color_hex']?>;font-size:10px;font-weight:700;white-space:nowrap"><?=$tsR['icon']?> <?=$tsR['is_rated']?'Lv.'.$tsR['level']:''?></span></td>
      <td style="color:var(--primary)"><?=$t['win']?></td><td><?=$t['draw']?></td><td style="color:var(--danger)"><?=$t['loss']?></td>
      <td style="color:#ffb400"><?=$gf?></td>
      <td style="color:#3a9ef5"><?=$ga?></td>
      <td style="color:<?=$gd>=0?'var(--primary)':'var(--danger)'?>;font-weight:700"><?=($gd>0?'+':'').$gd?></td>
      <td style="font-family:'Space Grotesk',sans-serif;font-weight:700"><?=$t['win']*3+$t['draw']?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <?php elseif($tab==='player'):
    $sort = $_GET['sort'] ?? 'manner';
    $posF = $_GET['pos']  ?? '';
    $sortMap = [
      'manner'     => ['매너',  'u.manner_score DESC, u.goals DESC'],
      'matches'    => ['경기수','matches_played DESC, u.manner_score DESC'],
      'goals'      => ['골',    'goals_total DESC, u.manner_score DESC'],
      'assists'    => ['어시',  'assists_total DESC, u.manner_score DESC'],
      'gpm'        => ['득점율','gpm DESC, matches_played DESC'],
      'attend'     => ['출석률','attend_rate DESC, matches_played DESC'],
      'mom'        => ['MOM',   'u.mom_count DESC, u.manner_score DESC'],
    ];
    if (!isset($sortMap[$sort])) $sort = 'manner';
    $orderBy = $sortMap[$sort][1];
    $wp = "1=1"; $pp = [];
    if ($posF && in_array($posF,['GK','DF','MF','FW'])) { $wp .= " AND u.position=?"; $pp[] = $posF; }
    if ($region) { $wp .= " AND u.region=?"; $pp[] = $region; }
    if ($district) { $wp .= " AND u.district=?"; $pp[] = $district; }
    $plStmt = $pdo->prepare("
      SELECT u.*, t.name AS tname,
        COALESCE(agg.played, 0) AS matches_played,
        COALESCE(agg.attended, 0) AS attended,
        COALESCE(agg.goals_total, 0) AS goals_total,
        COALESCE(agg.assists_total, 0) AS assists_total,
        CASE WHEN COALESCE(agg.played,0) > 0 THEN ROUND(agg.goals_total / agg.played, 2) ELSE 0 END AS gpm,
        CASE WHEN COALESCE(agg.played,0) > 0 THEN ROUND(agg.attended * 100 / agg.played) ELSE 0 END AS attend_rate
      FROM users u
      LEFT JOIN team_members tm ON tm.user_id=u.id AND tm.status='active'
      LEFT JOIN teams t ON t.id=tm.team_id
      LEFT JOIN (
        SELECT user_id,
          COUNT(DISTINCT match_id) AS played,
          SUM(is_checked_in) AS attended,
          SUM(goals) AS goals_total,
          SUM(assists) AS assists_total
        FROM match_player_records GROUP BY user_id
      ) agg ON agg.user_id=u.id
      WHERE $wp
      ORDER BY $orderBy
      LIMIT 50
    ");
    $plStmt->execute($pp);
    $pl = $plStmt->fetchAll();
    // 정렬 chip-row
    $qs = function($extra) use ($region,$district,$posF) {
      $q = ['page'=>'ranking','tab'=>'player'];
      if ($region) $q['region']=$region;
      if ($district) $q['district']=$district;
      if ($posF) $q['pos']=$posF;
      return http_build_query(array_merge($q, $extra));
    };
    $posChips = [''=>'전체','GK'=>'GK','DF'=>'DF','MF'=>'MF','FW'=>'FW'];
    $qsPos = function($p) use ($region,$district,$sort) {
      $q = ['page'=>'ranking','tab'=>'player','sort'=>$sort];
      if ($region) $q['region']=$region;
      if ($district) $q['district']=$district;
      if ($p) $q['pos']=$p;
      return http_build_query($q);
    };
  ?>
  <!-- 포지션 필터 -->
  <div class="chip-row" style="margin-bottom:6px">
    <?php foreach($posChips as $pk=>$pv): ?>
    <a href="?<?=$qsPos($pk)?>" class="chip <?=$posF===$pk?'active':''?>"><?=$pv?></a>
    <?php endforeach; ?>
  </div>
  <!-- 정렬 탭 -->
  <div class="chip-row">
    <?php foreach($sortMap as $sk=>$sv): ?>
    <a href="?<?=$qs(['sort'=>$sk])?>" class="chip <?=$sort===$sk?'active':''?>"><?=$sv[0]?></a>
    <?php endforeach; ?>
  </div>
  <div class="card" style="overflow-x:auto;margin-top:8px"><table class="tf-table">
    <thead><tr><th>#</th><th>선수</th><th>팀</th>
      <?php if($sort==='manner'): ?><th>매너</th><th>골</th>
      <?php elseif($sort==='matches'): ?><th>경기</th><th>매너</th>
      <?php elseif($sort==='goals'): ?><th>골</th><th>경기</th>
      <?php elseif($sort==='assists'): ?><th>어시</th><th>경기</th>
      <?php elseif($sort==='gpm'): ?><th>득점율</th><th>골/경기</th>
      <?php elseif($sort==='attend'): ?><th>출석률</th><th>경기</th>
      <?php elseif($sort==='mom'): ?><th>MOM</th><th>매너</th>
      <?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach($pl as $i=>$p): ?>
    <tr class="<?=$i===0?'rank-1':($i===1?'rank-2':'')?>" style="cursor:pointer" onclick="openUserProfile(<?=(int)$p['id']?>)">
      <td style="font-family:'Space Grotesk',sans-serif;font-weight:700;color:<?=$i===0?'var(--warning)':'var(--text-sub)'?>"><?=$i+1?></td>
      <td style="font-weight:600"><?=h(displayName($p))?><?php if(!empty($p['position'])): ?> <span style="font-size:10px;color:var(--text-sub)">· <?=h($p['position'])?></span><?php endif; ?></td>
      <td style="color:var(--text-sub)"><?=h($p['tname']??'-')?></td>
      <?php if($sort==='manner'): ?>
        <td style="color:var(--primary);font-weight:700"><?=$p['manner_score']??36?></td><td><?=$p['goals']??0?></td>
      <?php elseif($sort==='matches'): ?>
        <td style="color:var(--primary);font-weight:700"><?=$p['matches_played']?></td><td><?=$p['manner_score']??36?></td>
      <?php elseif($sort==='goals'): ?>
        <td style="color:#ffb400;font-weight:700"><?=$p['goals_total']?></td><td><?=$p['matches_played']?></td>
      <?php elseif($sort==='assists'): ?>
        <td style="color:#3a9ef5;font-weight:700"><?=$p['assists_total']?></td><td><?=$p['matches_played']?></td>
      <?php elseif($sort==='gpm'): ?>
        <td style="color:#ffb400;font-weight:700"><?=number_format((float)$p['gpm'],2)?></td><td><?=$p['goals_total']?>/<?=$p['matches_played']?></td>
      <?php elseif($sort==='attend'): ?>
        <td style="color:var(--primary);font-weight:700"><?=(int)$p['attend_rate']?>%</td><td><?=$p['matches_played']?></td>
      <?php elseif($sort==='mom'): ?>
        <td style="color:#ffd60a;font-weight:700">🏆 <?=(int)($p['mom_count']??0)?></td><td><?=$p['manner_score']??36?></td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <?php elseif($tab==='league'):
    $ls=$pdo->query("SELECT * FROM leagues ORDER BY FIELD(status,'진행중','모집중','종료'),name")->fetchAll();
    foreach($ls as $l): ?>
  <a href="?page=league&id=<?=$l['id']?>" class="card card-link" style="margin-bottom:10px"><div class="card-body">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <span style="font-weight:700"><?=h($l['name'])?></span>
      <span class="badge <?=$l['status']==='진행중'?'badge-green':($l['status']==='모집중'?'badge-yellow':'badge-gray')?>"><?=h($l['status'])?></span>
    </div>
    <div style="font-size:12px;color:var(--text-sub);margin-top:4px"><?=h($l['region']??'')?> <?=h($l['district']??'')?><?=$l['season']?' · '.h($l['season']):''?></div>
  </div></a>
  <?php endforeach;endif; ?>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 회원명단 + 회비
// ═══════════════════════════════════════════════════════════════
function pagFees(PDO $pdo): void {
    requireLogin(); $tid=myTeamId();
    if(!$tid){echo '<div class="container" style="padding:60px 16px;text-align:center;color:var(--text-sub)">팀 소속 후 이용 가능합니다.</div>';return;}
    $tab = $_GET['tab'] ?? 'members';
    // 회원 목록 (용병 제외)
    $mbs=$pdo->prepare("
        SELECT u.id, u.name, u.nickname, u.position, u.profile_image_url, u.jersey_number,
               tm.role, tm.joined_at,
               DATEDIFF(NOW(), tm.joined_at) AS days_since_join
        FROM users u JOIN team_members tm ON tm.user_id=u.id
        WHERE tm.team_id=? AND tm.status='active' AND tm.role != 'mercenary'
        ORDER BY FIELD(tm.role,'owner','president','director','captain','vice_captain','manager','coach','treasurer','analyst','doctor','player'), u.name
    ");
    $mbs->execute([$tid]);$mbs=$mbs->fetchAll();
    $roleLabels = ['owner'=>'구단주','captain'=>'주장','vice_captain'=>'부주장','manager'=>'매니저','coach'=>'코치','treasurer'=>'총무','analyst'=>'전력분석','doctor'=>'팀닥터','player'=>'선수','president'=>'회장','director'=>'감독'];
    $roleColors = ['owner'=>'#00ff88','captain'=>'#ffd60a','vice_captain'=>'#ff9500','manager'=>'#3a9ef5','coach'=>'#9b59b6','treasurer'=>'#e67e22','analyst'=>'#1abc9c','doctor'=>'#e84393','player'=>'rgba(255,255,255,0.4)','president'=>'#00ff88','director'=>'#e74c3c'];
    $posShort = ['GK'=>'GK','DF'=>'DF','MF'=>'MF','FW'=>'FW','CB'=>'CB','LB'=>'LB','RB'=>'RB','CDM'=>'CDM','CM'=>'CM','LM'=>'LM','RM'=>'RM','CAM'=>'CAM','LW'=>'LW','ST'=>'ST','RW'=>'RW'];

    // 회비 데이터
    $month = $_GET['month'] ?? date('Y-m');
    $fees=$pdo->prepare("SELECT f.*,u.name AS uname,u.nickname AS unick FROM fees f JOIN users u ON u.id=f.user_id WHERE f.team_id=? ORDER BY f.created_at DESC");
    $fees->execute([$tid]);$fees=$fees->fetchAll();
    // 월별 정기회비 (해당 월)
    $monthFees = array_filter($fees, fn($f) => $f['type']==='회비' && str_starts_with($f['created_at']??'', $month));
    $paidIds = array_map(fn($f)=>(int)$f['user_id'], array_filter($monthFees, fn($f)=>$f['status']==='납부'));
    $unpaidIds = array_map(fn($f)=>(int)$f['user_id'], array_filter($monthFees, fn($f)=>$f['status']==='미납'));
    // 특별 항목 (회비 외)
    $specialFees = array_filter($fees, fn($f) => $f['type']!=='회비');
    ?>
<div class="container">
  <div style="margin-bottom:12px">
    <h2 style="font-size:18px;font-weight:700;margin-bottom:10px">회원명단</h2>
    <div style="display:flex;gap:6px">
      <a href="?page=fees&tab=members" class="chip <?=$tab==='members'?'active':''?>" style="height:28px;font-size:11px">👥 명단 (<?=count($mbs)?>)</a>
      <?php if(isCaptain()): ?>
      <a href="?page=fees&tab=fees" class="chip <?=$tab==='fees'?'active':''?>" style="height:28px;font-size:11px">💰 회비</a>
      <a href="?page=fees&tab=special" class="chip <?=$tab==='special'?'active':''?>" style="height:28px;font-size:11px">📋 특별항목</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($tab === 'members'): ?>
  <!-- ═══ 회원명단 탭 ═══ -->
  <?php
    $newbies = array_filter($mbs, fn($m) => ($m['days_since_join'] ?? 999) <= 30);
    $veterans = array_filter($mbs, fn($m) => ($m['days_since_join'] ?? 999) > 30);
  ?>
  <?php if($newbies): ?>
  <div style="margin-bottom:12px">
    <div style="font-size:12px;font-weight:700;color:#00ff88;margin-bottom:6px;display:flex;align-items:center;gap:4px">
      🆕 신입 회원 <span style="font-size:10px;color:var(--text-sub);font-weight:400">(가입 1개월 이내, <?=count($newbies)?>명)</span>
    </div>
    <div class="card" style="margin-bottom:0"><div class="card-body" style="padding:0">
    <?php foreach($newbies as $mi => $m):
      $role = $m['role'] ?? 'player';
      $pos = $posShort[$m['position']??''] ?? '';
      $rc = $roleColors[$role] ?? 'rgba(255,255,255,0.4)';
      $daysJoined = (int)($m['days_since_join'] ?? 0);
    ?>
    <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;<?=$mi?'border-top:1px solid rgba(255,255,255,0.04)':''?>;cursor:pointer;background:rgba(0,255,136,0.03)" onclick="openUserProfile(<?=(int)$m['id']?>)">
      <?php if(!empty($m['profile_image_url'])): ?>
        <img src="<?=h($m['profile_image_url'])?>" style="width:30px;height:30px;border-radius:50%;object-fit:cover;border:1.5px solid <?=$rc?>">
      <?php else: ?>
        <div style="width:30px;height:30px;border-radius:50%;background:var(--bg-surface-alt);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:<?=$rc?>;border:1.5px solid <?=$rc?>"><?=mb_substr($m['name'],0,1,'UTF-8')?></div>
      <?php endif; ?>
      <div style="flex:1;min-width:0;display:flex;align-items:center;gap:5px">
        <span style="font-weight:600;font-size:13px"><?=h(displayName($m))?></span>
        <span style="font-size:8px;padding:1px 4px;border-radius:3px;background:rgba(0,255,136,0.15);color:#00ff88;font-weight:700">NEW</span>
        <?php if($m['jersey_number']): ?><span style="font-size:10px;color:var(--text-sub)">#<?=(int)$m['jersey_number']?></span><?php endif; ?>
      </div>
      <?php if($pos): ?><span style="font-size:9px;color:var(--text-sub)"><?=$pos?></span><?php endif; ?>
      <span style="font-size:8px;color:var(--text-sub)"><?=$daysJoined?>일</span>
      <span style="font-size:9px;padding:1px 5px;border-radius:3px;font-weight:600;background:<?=$rc?>18;color:<?=$rc?>"><?=$roleLabels[$role]??'선수'?></span>
    </div>
    <?php endforeach; ?>
    </div></div>
  </div>
  <?php endif; ?>

  <div class="card" style="margin-bottom:16px"><div class="card-body" style="padding:0">
    <?php foreach($veterans as $mi => $m):
      $role = $m['role'] ?? 'player';
      $pos = $posShort[$m['position']??''] ?? '';
      $rc = $roleColors[$role] ?? 'rgba(255,255,255,0.4)';
    ?>
    <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;<?=$mi?'border-top:1px solid rgba(255,255,255,0.04)':''?>;cursor:pointer" onclick="openUserProfile(<?=(int)$m['id']?>)">
      <?php if(!empty($m['profile_image_url'])): ?>
        <img src="<?=h($m['profile_image_url'])?>" style="width:30px;height:30px;border-radius:50%;object-fit:cover;border:1.5px solid <?=$rc?>">
      <?php else: ?>
        <div style="width:30px;height:30px;border-radius:50%;background:var(--bg-surface-alt);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:<?=$rc?>;border:1.5px solid <?=$rc?>"><?=mb_substr($m['name'],0,1,'UTF-8')?></div>
      <?php endif; ?>
      <div style="flex:1;min-width:0;display:flex;align-items:center;gap:5px">
        <span style="font-weight:600;font-size:13px"><?=h(displayName($m))?></span>
        <?php if($m['jersey_number']): ?><span style="font-size:10px;color:var(--text-sub)">#<?=(int)$m['jersey_number']?></span><?php endif; ?>
      </div>
      <?php if($pos): ?><span style="font-size:9px;color:var(--text-sub)"><?=$pos?></span><?php endif; ?>
      <span style="font-size:9px;padding:1px 5px;border-radius:3px;font-weight:600;background:<?=$rc?>18;color:<?=$rc?>"><?=$roleLabels[$role]??'선수'?></span>
    </div>
    <?php endforeach; ?>
  </div></div>

  <?php elseif ($tab === 'fees'): ?>
  <!-- ═══ 회비 탭 — 연간 1~12월 테이블 ═══ -->
  <?php if (!isCaptain()): ?>
    <p style="color:var(--text-sub);text-align:center;padding:30px 0;font-size:13px">회비 관리는 캡틴/관리자만 볼 수 있습니다.</p>
  <?php else:
    $year = (int)($_GET['year'] ?? date('Y'));
    $prevY = $year - 1; $nextY = $year + 1;
    // 연간 회비 데이터: user_id → [1~12 => paid/unpaid/none]
    $yearFees = $pdo->prepare("SELECT user_id, status, created_at FROM fees WHERE team_id=? AND type='회비' AND created_at >= ? AND created_at < ?");
    $yearFees->execute([$tid, "$year-01-01", ($year+1)."-01-01"]);
    $feeMap = []; // user_id => [month => 'paid'|'unpaid']
    foreach ($yearFees->fetchAll() as $yf) {
        $m = (int)date('n', strtotime($yf['created_at']));
        $feeMap[(int)$yf['user_id']][$m] = ($yf['status'] === '납부') ? 'paid' : 'unpaid';
    }
  ?>
  <div style="display:flex;align-items:center;justify-content:center;gap:16px;margin-bottom:14px">
    <a href="?page=fees&tab=fees&year=<?=$prevY?>" style="color:var(--text-sub);font-size:18px">‹</a>
    <span style="font-size:16px;font-weight:700"><?=$year?>년</span>
    <a href="?page=fees&tab=fees&year=<?=$nextY?>" style="color:var(--text-sub);font-size:18px">›</a>
  </div>

  <div style="overflow-x:auto;margin-bottom:14px;-webkit-overflow-scrolling:touch">
  <table style="width:100%;min-width:500px;border-collapse:collapse;font-size:11px">
    <thead>
      <tr style="border-bottom:1px solid var(--border)">
        <th style="text-align:left;padding:6px 8px;font-weight:700;position:sticky;left:0;background:var(--bg-main);z-index:1;min-width:110px">이름</th>
        <?php for($mo=1;$mo<=12;$mo++): ?>
        <th style="text-align:center;padding:6px 2px;font-weight:600;color:<?=$mo==(int)date('n')&&$year==(int)date('Y')?'var(--primary)':'var(--text-sub)'?>;min-width:28px"><?=$mo?>월</th>
        <?php endfor; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach($mbs as $m):
      $uid = (int)$m['id'];
    ?>
      <tr style="border-bottom:1px solid rgba(255,255,255,0.03)">
        <td style="padding:5px 8px;font-weight:600;font-size:12px;position:sticky;left:0;background:var(--bg-main);z-index:1;white-space:nowrap"><?=h(displayName($m))?></td>
        <?php for($mo=1;$mo<=12;$mo++):
          $st = $feeMap[$uid][$mo] ?? 'none';
          $monthStr = sprintf('%04d-%02d', $year, $mo);
        ?>
        <td style="text-align:center;padding:4px 2px">
          <form method="POST" style="margin:0;display:inline">
            <?=csrfInput()?>
            <input type="hidden" name="action" value="toggle_fee">
            <input type="hidden" name="user_id" value="<?=$uid?>">
            <input type="hidden" name="month" value="<?=$monthStr?>">
            <input type="hidden" name="current" value="<?=$st==='paid'?'paid':'unpaid'?>">
            <input type="hidden" name="year" value="<?=$year?>">
            <button type="submit" style="background:none;border:none;cursor:pointer;padding:2px;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center">
              <?php if($st === 'paid'): ?>
                <div style="width:18px;height:18px;border-radius:3px;background:var(--primary);display:flex;align-items:center;justify-content:center">
                  <span style="color:#000;font-size:10px;font-weight:800">✓</span>
                </div>
              <?php elseif($st === 'unpaid'): ?>
                <div style="width:18px;height:18px;border-radius:3px;background:var(--danger);display:flex;align-items:center;justify-content:center">
                  <span style="color:#fff;font-size:10px;font-weight:800">✕</span>
                </div>
              <?php else: ?>
                <div style="width:18px;height:18px;border-radius:3px;border:1.5px solid rgba(255,255,255,0.15)"></div>
              <?php endif; ?>
            </button>
          </form>
        </td>
        <?php endfor; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <div style="display:flex;gap:12px;font-size:10px;color:var(--text-sub);margin-bottom:12px;justify-content:center">
    <span><span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:var(--primary);vertical-align:middle;margin-right:3px"></span>납부</span>
    <span><span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:var(--danger);vertical-align:middle;margin-right:3px"></span>미납</span>
    <span><span style="display:inline-block;width:12px;height:12px;border-radius:2px;border:1.5px solid rgba(255,255,255,0.15);vertical-align:middle;margin-right:3px"></span>미등록</span>
  </div>

  <form method="POST" style="margin-bottom:10px"><?=csrfInput()?><input type="hidden" name="action" value="remind_fee_all">
    <button type="submit" class="btn btn-outline btn-w" style="font-size:12px" onclick="return confirm('미납자에게 독촉 알림을 보냅니다.')">📢 미납자 일괄 독촉</button>
  </form>
  <?php endif; ?>

  <?php else: ?>
  <!-- ═══ 특별항목 탭 (후원, 연회비, 참가비, 보증금 등) ═══ -->
  <?php if(!$specialFees): ?>
    <p style="color:var(--text-sub);text-align:center;padding:30px 0">특별 항목이 없습니다.</p>
  <?php else: ?>
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:0 12px">
    <?php foreach($specialFees as $f): ?>
    <div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.04)">
      <div style="flex:1">
        <span style="font-size:13px;font-weight:600"><?=h(displayName($f))?></span>
        <span class="badge badge-gray" style="font-size:9px;margin-left:4px"><?=h($f['type'])?></span>
        <span style="font-size:12px;color:var(--text-sub);margin-left:4px"><?=number_format($f['amount'])?>원</span>
        <?=$f['memo']?'<div style="font-size:11px;color:var(--text-sub)">'.h($f['memo']).'</div>':''?>
      </div>
      <span class="badge <?=$f['status']==='납부'?'badge-green':'badge-red'?>" style="font-size:9px"><?=$f['status']?></span>
      <?php if($f['status']==='미납'&&isCaptain()): ?>
      <form method="POST" style="margin:0"><?=csrfInput()?><input type="hidden" name="action" value="pay_fee"><input type="hidden" name="fee_id" value="<?=$f['id']?>">
        <button type="submit" class="btn btn-ghost btn-sm" style="font-size:10px;padding:2px 6px">✓</button></form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>

  <?php if(isCaptain()): ?>
  <div class="card"><div class="card-body" style="padding:12px">
    <div style="font-size:13px;font-weight:600;margin-bottom:8px">특별 항목 추가</div>
    <form method="POST">
      <?=csrfInput()?><input type="hidden" name="action" value="add_fee">
      <div style="display:flex;gap:6px;margin-bottom:6px">
        <select name="user_id" class="form-select" style="flex:1;font-size:12px;padding:6px" required>
          <?php foreach($mbs as $m): ?><option value="<?=$m['id']?>"><?=h(displayName($m))?></option><?php endforeach; ?>
        </select>
        <select name="type" class="form-select" style="width:80px;font-size:12px;padding:6px">
          <option>참가비</option><option>보증금</option><option>후원금</option><option>연회비</option><option>벌금</option><option>기타</option>
        </select>
      </div>
      <div style="display:flex;gap:6px">
        <input type="number" name="amount" class="form-control" style="flex:1;font-size:12px;padding:6px" placeholder="금액(원)" required min="1">
        <input type="text" name="memo" class="form-control" style="flex:2;font-size:12px;padding:6px" placeholder="메모 (선택)">
        <button type="submit" class="btn btn-primary btn-sm" style="font-size:12px;padding:6px 12px;white-space:nowrap">추가</button>
      </div>
    </form>
  </div></div>
  <?php endif; ?>
  <?php endif; /* tab */ ?>
</div>
<?php }


// ═══════════════════════════════════════════════════════════════
// 회비 관리 (Team Dues Management) ?page=dues
// ═══════════════════════════════════════════════════════════════
function pagDues(PDO $pdo): void {
    requireLogin();
    $tid = myTeamId();
    if (!$tid) {
        echo '<div class="container" style="padding:60px 16px;text-align:center;color:var(--text-sub)">팀 소속 후 이용 가능합니다.</div>';
        return;
    }

    $uid = (int)me()['id'];
    $captain = isCaptain();
    $year = (int)($_GET['year'] ?? date('Y'));

    // Team info
    $teamStmt = $pdo->prepare("SELECT t.name, t.membership_fee, ds.monthly_fee AS dues_fee, ds.description AS dues_desc
                                FROM teams t LEFT JOIN team_dues_settings ds ON ds.team_id=t.id WHERE t.id=?");
    $teamStmt->execute([$tid]);
    $teamInfo = $teamStmt->fetch();
    $monthlyFee = (int)($teamInfo['dues_fee'] ?? $teamInfo['membership_fee'] ?? 0);
    $teamName = $teamInfo['name'] ?? '팀';

    // Members (exclude mercenary)
    $mbStmt = $pdo->prepare("SELECT u.id, u.name, u.nickname, u.profile_image_url, tm.role
        FROM users u JOIN team_members tm ON tm.user_id=u.id
        WHERE tm.team_id=? AND tm.status='active' AND tm.role != 'mercenary'
        ORDER BY FIELD(tm.role,'owner','president','director','captain','vice_captain','manager','coach','treasurer','analyst','doctor','player'), u.name");
    $mbStmt->execute([$tid]);
    $members = $mbStmt->fetchAll();

    // Dues payment data for the year
    $duesStmt = $pdo->prepare("SELECT user_id, year_month, status, amount, note FROM team_dues_payments WHERE team_id=? AND year_month LIKE ?");
    $duesStmt->execute([$tid, $year.'-%']);
    $duesMap = []; // [user_id][month] => row
    foreach ($duesStmt->fetchAll() as $d) {
        $mo = (int)substr($d['year_month'], 5, 2);
        $duesMap[(int)$d['user_id']][$mo] = $d;
    }

    // Calculate stats
    $curMonth = (int)date('n');
    $curYM = sprintf('%04d-%02d', $year, $curMonth);
    $totalMembers = count($members);
    $paidThisMonth = 0;
    $unpaidNames = [];
    $totalPaidAmount = 0;
    $monthlyTotals = array_fill(1, 12, 0);

    foreach ($members as $m) {
        $muid = (int)$m['id'];
        for ($mo = 1; $mo <= 12; $mo++) {
            $st = $duesMap[$muid][$mo]['status'] ?? 'none';
            if ($st === 'paid' || $st === 'partial') {
                $amt = (int)($duesMap[$muid][$mo]['amount'] ?? $monthlyFee);
                $totalPaidAmount += $amt;
                $monthlyTotals[$mo] += $amt;
            }
        }
        // Current month stats
        if ($year == (int)date('Y')) {
            $curSt = $duesMap[$muid][$curMonth]['status'] ?? 'none';
            if ($curSt === 'paid') { $paidThisMonth++; }
            elseif ($curSt !== 'exempt') { $unpaidNames[] = displayName($m); }
        }
    }
    $payRate = $totalMembers > 0 ? round($paidThisMonth / $totalMembers * 100) : 0;
    $monthsWithData = count(array_filter($monthlyTotals));
    $monthlyAvg = $monthsWithData > 0 ? round($totalPaidAmount / $monthsWithData) : 0;

    $prevY = $year - 1;
    $nextY = $year + 1;
    ?>
<div class="container">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <div>
      <h2 style="font-size:18px;font-weight:700;margin-bottom:2px">💰 회비 관리</h2>
      <div style="font-size:11px;color:var(--text-sub)"><?=h(teamDisplayName($teamName))?> · 월 <?=number_format($monthlyFee)?>원</div>
    </div>
    <?php if($captain): ?>
    <button class="btn btn-ghost btn-sm" style="font-size:11px" data-tf-toggle="duesSetting">⚙️ 설정</button>
    <?php endif; ?>
  </div>

  <?php if($captain): ?>
  <!-- 회비 설정 패널 -->
  <div id="duesSetting" class="tf-collapse" style="margin-bottom:14px">
    <div class="card"><div class="card-body" style="padding:12px">
      <div style="font-size:13px;font-weight:700;margin-bottom:8px">회비 설정</div>
      <form method="POST">
        <?=csrfInput()?>
        <input type="hidden" name="action" value="save_dues_setting">
        <div class="form-group" style="margin-bottom:8px">
          <label class="form-label" style="font-size:12px">월 회비 금액</label>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="number" name="monthly_fee" class="form-control" value="<?=$monthlyFee?>" min="0" step="1000" style="flex:1;font-size:13px">
            <span style="font-size:12px;color:var(--text-sub)">원</span>
          </div>
        </div>
        <div class="form-group" style="margin-bottom:8px">
          <label class="form-label" style="font-size:12px">설명 (선택)</label>
          <input type="text" name="dues_description" class="form-control" value="<?=h($teamInfo['dues_desc']??'')?>" placeholder="예: 구장 대여비 + 음료" style="font-size:12px">
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="font-size:12px">저장</button>
      </form>
    </div></div>
  </div>
  <?php endif; ?>

  <!-- 연도 네비게이션 -->
  <div style="display:flex;align-items:center;justify-content:center;gap:16px;margin-bottom:14px">
    <a href="?page=dues&year=<?=$prevY?>" style="color:var(--text-sub);font-size:20px;text-decoration:none">‹</a>
    <span style="font-size:16px;font-weight:700"><?=$year?>년</span>
    <a href="?page=dues&year=<?=$nextY?>" style="color:var(--text-sub);font-size:20px;text-decoration:none">›</a>
  </div>

  <!-- 요약 통계 -->
  <?php if($year == (int)date('Y')): ?>
  <div class="card" style="margin-bottom:14px;border:1px solid rgba(0,255,136,0.15)"><div class="card-body" style="padding:12px">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
      <div>
        <div style="font-size:10px;color:var(--text-sub)">이번 달 납부율</div>
        <div style="font-size:18px;font-weight:800;color:<?=$payRate>=80?'var(--primary)':($payRate>=50?'#ffd60a':'var(--danger)')?>"><?=$paidThisMonth?>/<?=$totalMembers?>명 <span style="font-size:13px">(<?=$payRate?>%)</span></div>
      </div>
      <div>
        <div style="font-size:10px;color:var(--text-sub)">누적 총 수입</div>
        <div style="font-size:18px;font-weight:800;color:var(--primary)"><?=number_format($totalPaidAmount)?>원</div>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <div>
        <div style="font-size:10px;color:var(--text-sub)">월평균 수입</div>
        <div style="font-size:14px;font-weight:700"><?=number_format($monthlyAvg)?>원</div>
      </div>
      <div>
        <div style="font-size:10px;color:var(--text-sub)">미납자</div>
        <div style="font-size:12px;color:var(--danger);font-weight:600"><?=$unpaidNames ? implode(', ', array_slice($unpaidNames, 0, 5)) . (count($unpaidNames)>5?' 외 '.(count($unpaidNames)-5).'명':'') : '<span style="color:var(--primary)">전원 납부 ✓</span>'?></div>
      </div>
    </div>
  </div></div>
  <?php endif; ?>

  <?php if($captain): ?>
  <!-- 일괄 납부 버튼 -->
  <div style="display:flex;gap:8px;margin-bottom:10px">
    <form method="POST" style="margin:0">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="bulk_dues_paid">
      <input type="hidden" name="year_month" value="<?=date('Y-m')?>">
      <button type="submit" class="btn btn-outline btn-sm" style="font-size:11px" onclick="return confirm('<?=date('n')?>월 전체 납부 처리합니다. 면제자는 제외됩니다.')">✅ 이번달 전체 납부</button>
    </form>
    <a href="?page=fees&tab=members" class="btn btn-ghost btn-sm" style="font-size:11px">👥 회원명단</a>
    <a href="?page=fees&tab=special" class="btn btn-ghost btn-sm" style="font-size:11px">📋 특별항목</a>
  </div>
  <?php endif; ?>

  <!-- 연간 테이블 -->
  <div style="overflow-x:auto;margin-bottom:14px;-webkit-overflow-scrolling:touch">
  <table style="width:100%;min-width:540px;border-collapse:collapse;font-size:11px" id="duesTable">
    <thead>
      <tr style="border-bottom:1px solid var(--border)">
        <th style="text-align:left;padding:6px 8px;font-weight:700;position:sticky;left:0;background:var(--bg-main);z-index:2;min-width:80px">이름</th>
        <?php for($mo=1;$mo<=12;$mo++): ?>
        <th style="text-align:center;padding:6px 2px;font-weight:600;color:<?=$mo==$curMonth&&$year==(int)date('Y')?'var(--primary)':'var(--text-sub)'?>;min-width:30px"><?=$mo?>월</th>
        <?php endfor; ?>
        <th style="text-align:center;padding:6px 4px;font-weight:700;min-width:50px">합계</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($members as $m):
      $muid = (int)$m['id'];
      $memberTotal = 0;
      for($mo=1;$mo<=12;$mo++) {
          $st = $duesMap[$muid][$mo]['status'] ?? 'none';
          if ($st === 'paid') $memberTotal += (int)($duesMap[$muid][$mo]['amount'] ?? $monthlyFee);
          elseif ($st === 'partial') $memberTotal += (int)($duesMap[$muid][$mo]['amount'] ?? 0);
      }
    ?>
      <tr style="border-bottom:1px solid rgba(255,255,255,0.03)">
        <td style="padding:5px 8px;font-weight:600;font-size:11px;position:sticky;left:0;background:var(--bg-main);z-index:1;white-space:nowrap"><?=h(displayName($m))?></td>
        <?php for($mo=1;$mo<=12;$mo++):
          $st = $duesMap[$muid][$mo]['status'] ?? 'none';
          $ym = sprintf('%04d-%02d', $year, $mo);
          $note = h($duesMap[$muid][$mo]['note'] ?? '');
          $cellId = "cell_{$muid}_{$mo}";
        ?>
        <td style="text-align:center;padding:3px 1px" id="<?=$cellId?>">
          <?php if($captain): ?>
          <div onclick="toggleDues(<?=$muid?>,'<?=$ym?>','<?=$st?>',this)" style="cursor:pointer;width:24px;height:24px;margin:0 auto;display:flex;align-items:center;justify-content:center;border-radius:4px;transition:all 0.2s"
               title="<?=$note?>"
               data-status="<?=$st?>" data-uid="<?=$muid?>" data-ym="<?=$ym?>">
            <?php if($st === 'paid'): ?>
              <div style="width:20px;height:20px;border-radius:4px;background:var(--primary);display:flex;align-items:center;justify-content:center"><span style="color:#000;font-size:10px;font-weight:800">✓</span></div>
            <?php elseif($st === 'unpaid'): ?>
              <div style="width:20px;height:20px;border-radius:4px;background:var(--danger);display:flex;align-items:center;justify-content:center"><span style="color:#fff;font-size:10px;font-weight:800">✕</span></div>
            <?php elseif($st === 'partial'): ?>
              <div style="width:20px;height:20px;border-radius:4px;background:#ffd60a;display:flex;align-items:center;justify-content:center"><span style="color:#000;font-size:10px;font-weight:800">½</span></div>
            <?php elseif($st === 'exempt'): ?>
              <div style="width:20px;height:20px;border-radius:4px;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center"><span style="color:var(--text-sub);font-size:10px;font-weight:800">—</span></div>
            <?php else: ?>
              <div style="width:20px;height:20px;border-radius:4px;border:1.5px solid rgba(255,255,255,0.15)"></div>
            <?php endif; ?>
          </div>
          <?php else: ?>
            <?php if($st === 'paid'): ?>
              <div style="width:20px;height:20px;border-radius:4px;background:var(--primary);display:flex;align-items:center;justify-content:center;margin:0 auto"><span style="color:#000;font-size:10px;font-weight:800">✓</span></div>
            <?php elseif($st === 'unpaid'): ?>
              <div style="width:20px;height:20px;border-radius:4px;background:var(--danger);display:flex;align-items:center;justify-content:center;margin:0 auto"><span style="color:#fff;font-size:10px;font-weight:800">✕</span></div>
            <?php elseif($st === 'partial'): ?>
              <div style="width:20px;height:20px;border-radius:4px;background:#ffd60a;display:flex;align-items:center;justify-content:center;margin:0 auto"><span style="color:#000;font-size:10px;font-weight:800">½</span></div>
            <?php elseif($st === 'exempt'): ?>
              <div style="width:20px;height:20px;border-radius:4px;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;margin:0 auto"><span style="color:var(--text-sub);font-size:10px;font-weight:800">—</span></div>
            <?php else: ?>
              <div style="width:20px;height:20px;border-radius:4px;border:1.5px solid rgba(255,255,255,0.15);margin:0 auto"></div>
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <?php endfor; ?>
        <td style="text-align:center;padding:5px 4px;font-weight:700;font-size:11px;color:<?=$memberTotal>0?'var(--primary)':'var(--text-sub)'?>"><?=$memberTotal>0?number_format($memberTotal):'-'?></td>
      </tr>
    <?php endforeach; ?>
    <!-- 월합계 행 -->
    <tr style="border-top:2px solid var(--primary);font-weight:700">
      <td style="padding:6px 8px;font-size:11px;position:sticky;left:0;background:var(--bg-main);z-index:1;color:var(--primary)">월합계</td>
      <?php for($mo=1;$mo<=12;$mo++): ?>
      <td style="text-align:center;padding:5px 2px;font-size:9px;color:<?=$monthlyTotals[$mo]>0?'var(--primary)':'var(--text-sub)'?>">
        <?=$monthlyTotals[$mo]>0?number_format($monthlyTotals[$mo]/10000,0).'만':'-'?>
      </td>
      <?php endfor; ?>
      <td style="text-align:center;padding:5px 4px;font-size:11px;color:var(--primary)"><?=number_format($totalPaidAmount)?>원</td>
    </tr>
    </tbody>
  </table>
  </div>

  <!-- 범례 -->
  <div style="display:flex;gap:12px;font-size:10px;color:var(--text-sub);margin-bottom:14px;justify-content:center;flex-wrap:wrap">
    <span><span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:var(--primary);vertical-align:middle;margin-right:3px"></span>납부</span>
    <span><span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:var(--danger);vertical-align:middle;margin-right:3px"></span>미납</span>
    <span><span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:#ffd60a;vertical-align:middle;margin-right:3px"></span>일부</span>
    <span><span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:rgba(255,255,255,0.15);vertical-align:middle;margin-right:3px"></span>면제</span>
    <span><span style="display:inline-block;width:12px;height:12px;border-radius:2px;border:1.5px solid rgba(255,255,255,0.15);vertical-align:middle;margin-right:3px"></span>미등록</span>
  </div>

  <?php if(!$captain): ?>
  <!-- 일반 멤버: 내 회비 상세 -->
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:12px">
    <div style="font-size:14px;font-weight:700;margin-bottom:10px">📋 내 납부 내역</div>
    <?php
    $myPaidMonths = 0; $myTotal = 0;
    for($mo=1;$mo<=12;$mo++):
      $st = $duesMap[$uid][$mo]['status'] ?? 'none';
      $amt = (int)($duesMap[$uid][$mo]['amount'] ?? $monthlyFee);
      $nt = $duesMap[$uid][$mo]['note'] ?? '';
      if($st === 'paid') { $myPaidMonths++; $myTotal += $amt; }
      if($st === 'none') continue;
    ?>
    <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.04)">
      <span style="font-size:12px;font-weight:600;min-width:40px"><?=$mo?>월</span>
      <span class="badge <?=match($st){'paid'=>'badge-green','unpaid'=>'badge-red','partial'=>'badge-yellow','exempt'=>'badge-gray',default=>'badge-gray'}?>" style="font-size:9px">
        <?=match($st){'paid'=>'납부','unpaid'=>'미납','partial'=>'일부','exempt'=>'면제',default=>$st}?>
      </span>
      <span style="font-size:11px;color:var(--text-sub);flex:1"><?=number_format($amt)?>원</span>
      <?php if($nt): ?><span style="font-size:10px;color:var(--text-sub)"><?=h($nt)?></span><?php endif; ?>
    </div>
    <?php endfor; ?>
    <div style="margin-top:10px;padding-top:8px;border-top:1px solid rgba(255,255,255,0.08);display:flex;justify-content:space-between">
      <span style="font-size:12px;font-weight:600">납부 <?=$myPaidMonths?>개월</span>
      <span style="font-size:12px;font-weight:700;color:var(--primary)"><?=number_format($myTotal)?>원</span>
    </div>
  </div></div>
  <?php endif; ?>
</div>

<!-- 회비 토글 AJAX -->
<script>
function toggleDues(uid, ym, currentStatus, el) {
  // Cycle: none -> paid, paid -> unpaid, unpaid -> exempt, exempt -> none(delete via unpaid)
  var nextMap = {'none':'paid', 'paid':'unpaid', 'unpaid':'exempt', 'partial':'paid', 'exempt':'paid'};
  var newStatus = nextMap[currentStatus] || 'paid';

  // Long press for note
  var note = '';

  var fd = new FormData();
  fd.append('action', 'toggle_dues_payment');
  fd.append('user_id', uid);
  fd.append('year_month', ym);
  fd.append('new_status', newStatus);
  fd.append('note', note);

  // Optimistic UI update
  var inner = el.querySelector('div');
  var statusStyles = {
    'paid':   {bg:'var(--primary)', color:'#000', text:'✓'},
    'unpaid': {bg:'var(--danger)',  color:'#fff', text:'✕'},
    'partial':{bg:'#ffd60a',       color:'#000', text:'½'},
    'exempt': {bg:'rgba(255,255,255,0.15)', color:'var(--text-sub)', text:'—'}
  };
  var style = statusStyles[newStatus];
  if (style && inner) {
    inner.style.background = style.bg;
    inner.style.border = 'none';
    inner.innerHTML = '<span style="color:'+style.color+';font-size:10px;font-weight:800">'+style.text+'</span>';
  }
  el.setAttribute('data-status', newStatus);
  // Update onclick
  el.setAttribute('onclick', "toggleDues("+uid+",'"+ym+"','"+newStatus+"',this)");

  fetch('?page=api', {method:'POST', body: fd, credentials:'same-origin'})
    .then(function(r){return r.json()})
    .then(function(d){
      if(!d.ok) { alert(d.msg||'오류'); location.reload(); }
    })
    .catch(function(e){ alert('네트워크 오류'); location.reload(); });
}

// Long press for note/exempt
var duesLongPress = null;
document.querySelectorAll('#duesTable [data-uid]').forEach(function(el){
  el.addEventListener('contextmenu', function(e){
    e.preventDefault();
    var uid = el.getAttribute('data-uid');
    var ym = el.getAttribute('data-ym');
    var st = el.getAttribute('data-status');
    var choice = prompt('상태 선택:\\n1: 납부  2: 미납  3: 면제  4: 일부\\n\\n메모가 있으면 "번호 메모" 형식으로 입력\\n예: 3 부상으로 면제', '1');
    if (!choice) return;
    var parts = choice.trim().split(/\s+/);
    var num = parseInt(parts[0]);
    var note = parts.slice(1).join(' ');
    var statusMap = {1:'paid', 2:'unpaid', 3:'exempt', 4:'partial'};
    var newSt = statusMap[num];
    if (!newSt) { alert('1~4 중 선택해주세요.'); return; }

    var fd = new FormData();
    fd.append('action', 'toggle_dues_payment');
    fd.append('user_id', uid);
    fd.append('year_month', ym);
    fd.append('new_status', newSt);
    fd.append('note', note);

    fetch('?page=api', {method:'POST', body: fd, credentials:'same-origin'})
      .then(function(r){return r.json()})
      .then(function(d){ if(d.ok) location.reload(); else alert(d.msg||'오류'); })
      .catch(function(){ alert('네트워크 오류'); });
  });
});
</script>
<?php }

// ═══════════════════════════════════════════════════════════════
// 리그 목록
// ═══════════════════════════════════════════════════════════════
function pagLeagues(PDO $pdo): void {
    $region=$_GET['region']??''; $w="1=1"; $p=[];
    if($region){$w.=" AND region=?";$p[]=$region;}
    $ls=$pdo->prepare("SELECT * FROM leagues WHERE $w ORDER BY FIELD(status,'진행중','모집중','종료'),name");
    $ls->execute($p);$ls=$ls->fetchAll();
    $regions=$pdo->query("SELECT DISTINCT region FROM leagues WHERE region IS NOT NULL AND region!='' ORDER BY region")->fetchAll(PDO::FETCH_COLUMN); ?>
<div class="container">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <h2 style="font-size:18px;font-weight:700">리그</h2>
    <?php if(isAdmin()): ?><button class="btn btn-primary btn-sm" data-tf-toggle="createLeague"><i class="bi bi-plus-lg"></i> 리그 생성</button><?php endif; ?>
  </div>
  <?php if(isAdmin()): ?>
  <div id="createLeague" class="tf-collapse" style="margin-bottom:16px">
    <div class="card"><div class="card-body">
      <form method="POST">
        <?=csrfInput()?><input type="hidden" name="action" value="create_league">
        <div class="form-group"><label class="form-label">리그명</label><input type="text" name="name" class="form-control" required></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">시/지역</label><input type="text" name="region" class="form-control" placeholder="서울"></div>
          <div class="form-group"><label class="form-label">구</label><input type="text" name="district" class="form-control" placeholder="강남구"></div>
        </div>
        <div class="form-group"><label class="form-label">시즌</label><input type="text" name="season" class="form-control" placeholder="2025 Spring"></div>
        <button type="submit" class="btn btn-primary btn-w">생성</button>
      </form>
    </div></div>
  </div>
  <?php endif; ?>
  <div class="chip-row">
    <a href="?page=leagues" class="chip <?=!$region?'active':''?>">전체</a>
    <?php foreach($regions as $r): ?><a href="?page=leagues&region=<?=urlencode($r)?>" class="chip <?=$region===$r?'active':''?>"><?=h($r)?></a><?php endforeach; ?>
  </div>
  <?php foreach($ls as $l): ?>
  <a href="?page=league&id=<?=$l['id']?>" class="card card-link" style="margin-bottom:10px"><div class="card-body">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <span style="font-weight:700"><?=h($l['name'])?></span>
      <span class="badge <?=$l['status']==='진행중'?'badge-green':($l['status']==='모집중'?'badge-yellow':'badge-gray')?>"><?=h($l['status'])?></span>
    </div>
    <div style="font-size:12px;color:var(--text-sub);margin-top:4px"><?=h($l['region']??'')?> <?=h($l['district']??'')?><?=$l['season']?' · '.h($l['season']):''?></div>
  </div></a>
  <?php endforeach; ?>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 리그 상세
// ═══════════════════════════════════════════════════════════════
function pagLeagueDetail(PDO $pdo): void {
    $id=(int)($_GET['id']??0);
    $l=$pdo->prepare("SELECT * FROM leagues WHERE id=?");$l->execute([$id]);$l=$l->fetch();
    if(!$l){echo '<div class="container" style="padding:60px 16px;text-align:center;color:var(--text-sub)">리그를 찾을 수 없습니다.</div>';return;}
    $st=$pdo->prepare("SELECT lt.*,t.name AS tname FROM league_teams lt JOIN teams t ON t.id=lt.team_id WHERE lt.league_id=? ORDER BY lt.points DESC,lt.win DESC,lt.goals_for DESC");
    $st->execute([$id]);$st=$st->fetchAll();
    $joined=false;
    if(myTeamId()){$s=$pdo->prepare("SELECT id FROM league_teams WHERE league_id=? AND team_id=?");$s->execute([$id,myTeamId()]);$joined=(bool)$s->fetch();} ?>
<div class="container">
  <h2 style="font-size:18px;font-weight:700;margin-bottom:4px"><?=h($l['name'])?></h2>
  <div style="font-size:13px;color:var(--text-sub);margin-bottom:16px">
    <?=h($l['region']??'')?> <?=h($l['district']??'')?><?=$l['season']?' · '.h($l['season']):''?>
    &nbsp; <span class="badge <?=$l['status']==='진행중'?'badge-green':($l['status']==='모집중'?'badge-yellow':'badge-gray')?>"><?=h($l['status'])?></span>
  </div>
  <?php if(isCaptain()&&!$joined&&$l['status']!=='종료'): ?>
  <form method="POST" style="margin-bottom:16px">
    <?=csrfInput()?><input type="hidden" name="action" value="join_league"><input type="hidden" name="league_id" value="<?=$id?>">
    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> 리그 참가 신청</button>
  </form>
  <?php elseif($joined): ?>
  <div style="color:var(--primary);font-size:13px;margin-bottom:16px"><i class="bi bi-check-circle-fill"></i> 참가 중인 리그입니다.</div>
  <?php endif; ?>

  <p class="section-title">순위표</p>
  <?php if(!$st): ?>
    <p style="color:var(--text-sub);text-align:center;padding:30px 0">등록된 팀이 없습니다.</p>
  <?php else: ?>
  <div class="card"><table class="tf-table">
    <thead><tr><th>#</th><th>팀</th><th>승</th><th>무</th><th>패</th><th>득점</th><th>승점</th></tr></thead>
    <tbody>
    <?php foreach($st as $i=>$s): ?>
    <tr class="<?=$i===0?'rank-1':($i===1?'rank-2':'')?>">
      <td style="font-family:'Space Grotesk',sans-serif;font-weight:700;color:<?=$i===0?'var(--warning)':'var(--text-sub)'?>"><?=$i+1?></td>
      <td style="font-weight:600"><?=h($s['tname'])?></td>
      <td style="color:var(--primary)"><?=$s['win']?></td><td><?=$s['draw']?></td><td style="color:var(--danger)"><?=$s['loss']?></td>
      <td><?=$s['goals_for']??0?></td>
      <td style="font-family:'Space Grotesk',sans-serif;font-weight:700"><?=$s['points']?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 신고 내역 (유저)
// ═══════════════════════════════════════════════════════════════
function pagReports(PDO $pdo): void {
    requireLogin();
    $my=$pdo->prepare("SELECT * FROM reports WHERE reporter_id=? ORDER BY created_at DESC LIMIT 20");
    $my->execute([me()['id']]);$my=$my->fetchAll(); ?>
<div class="container">
  <h2 style="font-size:18px;font-weight:700;margin-bottom:16px">신고/분쟁</h2>
  <div class="card" style="margin-bottom:16px"><div class="card-body">
    <p class="section-title">새 신고 접수</p>
    <form method="POST">
      <?=csrfInput()?><input type="hidden" name="action" value="submit_report">
      <div class="form-group"><label class="form-label">신고 유형</label>
        <select name="target_type" class="form-select"><option value="user">유저</option><option value="team">팀</option><option value="match">매치</option></select></div>
      <div class="form-group"><label class="form-label">대상 ID</label>
        <input type="number" name="target_id" class="form-control" required min="1"></div>
      <div class="form-group"><label class="form-label">신고 사유</label>
        <textarea name="reason" class="form-control" required></textarea></div>
      <button type="submit" class="btn btn-danger btn-w">신고 접수</button>
    </form>
  </div></div>

  <p class="section-title">내 신고 내역</p>
  <?php if(!$my): ?>
    <p style="color:var(--text-sub);text-align:center;padding:30px 0">신고 내역이 없습니다.</p>
  <?php else: ?>
  <div class="card"><div class="card-body" style="padding:0 16px">
    <?php foreach($my as $r): ?>
    <div class="list-item">
      <div>
        <span class="badge badge-gray"><?=$r['target_type']?> #<?=$r['target_id']?></span>
        <div style="font-size:13px;margin-top:4px"><?=h($r['reason'])?></div>
        <?=$r['admin_note']?'<div style="font-size:12px;color:var(--text-sub);margin-top:2px"><strong>관리자:</strong> '.h($r['admin_note']).'</div>':''?>
      </div>
      <?php $bc=['pending'=>'badge-yellow','resolved'=>'badge-green','dismissed'=>'badge-gray'];
            $bl=['pending'=>'검토중','resolved'=>'처리완료','dismissed'=>'기각']; ?>
      <span class="badge <?=$bc[$r['status']]??'badge-gray'?>"><?=$bl[$r['status']]??$r['status']?></span>
    </div>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 어드민: 신고 처리
// ═══════════════════════════════════════════════════════════════
function pagAdminReports(PDO $pdo): void {
    requireLogin();if(!isAdmin()){flash('권한 없음','error');redirect('?page=home');}
    $status=$_GET['status']??'pending';
    $rpts=$pdo->prepare("SELECT r.*,u.name AS rname FROM reports r JOIN users u ON u.id=r.reporter_id WHERE r.status=? ORDER BY r.created_at DESC");
    $rpts->execute([$status]);$rpts=$rpts->fetchAll(); ?>
<div class="container">
  <h2 style="font-size:18px;font-weight:700;margin-bottom:16px">신고 관리</h2>
  <div class="chip-row">
    <?php foreach(['pending'=>'검토중','resolved'=>'처리완료','dismissed'=>'기각'] as $k=>$v): ?>
    <a href="?page=admin_reports&status=<?=$k?>" class="chip <?=$status===$k?'active':''?>"><?=$v?></a>
    <?php endforeach; ?>
  </div>
  <?php if(!$rpts): ?>
    <p style="color:var(--text-sub);text-align:center;padding:30px 0">해당 신고가 없습니다.</p>
  <?php else: foreach($rpts as $r): ?>
  <div class="card" style="margin-bottom:12px"><div class="card-body">
    <div style="display:flex;justify-content:space-between;margin-bottom:8px">
      <span style="font-weight:600"><?=h($r['rname'])?></span>
      <span class="badge badge-gray"><?=$r['target_type']?> #<?=$r['target_id']?></span>
    </div>
    <p style="font-size:14px;margin-bottom:6px"><?=h($r['reason'])?></p>
    <p style="font-size:12px;color:var(--text-sub)"><?=$r['created_at']?></p>
    <?php if($r['status']==='pending'): ?>
    <form method="POST" style="margin-top:10px">
      <?=csrfInput()?><input type="hidden" name="action" value="resolve_report"><input type="hidden" name="report_id" value="<?=$r['id']?>">
      <input type="text" name="admin_note" class="form-control" style="margin-bottom:8px;min-height:44px" placeholder="처리 메모">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <button type="submit" name="status" value="resolved" class="btn btn-primary">처리완료</button>
        <button type="submit" name="status" value="dismissed" class="btn btn-ghost">기각</button>
      </div>
    </form>
    <?php else: ?>
    <p style="font-size:12px;color:var(--text-sub);margin-top:8px"><strong>처리 메모:</strong> <?=h($r['admin_note']??'-')?></p>
    <?php endif; ?>
  </div></div>
  <?php endforeach;endif; ?>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 어드민: 보증금 관리
// ═══════════════════════════════════════════════════════════════
function pagAdminDeposit(PDO $pdo): void {
    requireLogin();if(!isAdmin()){flash('권한 없음','error');redirect('?page=home');}
    $users=$pdo->query("SELECT u.id,u.name,u.phone,u.system_role, COALESCE(SUM(CASE WHEN f.type='보증금' AND f.status='납부' THEN f.amount ELSE 0 END),0) AS dep FROM users u LEFT JOIN fees f ON f.user_id=u.id GROUP BY u.id ORDER BY u.name")->fetchAll(); ?>
<div class="container">
  <h2 style="font-size:18px;font-weight:700;margin-bottom:16px">보증금 관리</h2>
  <div class="card" style="margin-bottom:16px"><div class="card-body">
    <p class="section-title">보증금 추가</p>
    <form method="POST">
      <?=csrfInput()?><input type="hidden" name="action" value="admin_deposit_add">
      <div class="form-group"><label class="form-label">대상 유저</label>
        <select name="user_id" class="form-select" required>
          <?php foreach($users as $u): ?><option value="<?=$u['id']?>"><?=h($u['name'])?> (현재: <?=number_format($u['dep'])?>원)</option><?php endforeach; ?>
        </select></div>
      <div class="form-group"><label class="form-label">금액(원)</label><input type="number" name="amount" class="form-control" required min="1"></div>
      <div class="form-group"><label class="form-label">메모</label><input type="text" name="memo" class="form-control" value="보증금 납부"></div>
      <button type="submit" class="btn btn-warning btn-w">추가</button>
    </form>
  </div></div>

  <p class="section-title">유저 보증금 현황</p>
  <div class="card"><table class="tf-table">
    <thead><tr><th>이름</th><th>전화번호</th><th>보증금</th><th>등급</th></tr></thead>
    <tbody>
    <?php foreach($users as $u): ?>
    <tr>
      <td style="font-weight:600"><?=h($u['name'])?></td>
      <td style="color:var(--text-sub)"><?=h($u['phone'])?></td>
      <td style="font-family:'Space Grotesk',sans-serif;font-weight:700;color:<?=$u['dep']<10000?'var(--danger)':'var(--primary)'?>"><?=number_format($u['dep'])?>원</td>
      <td><span class="badge <?=$u['system_role']==='admin'?'badge-red':'badge-gray'?>"><?=$u['system_role']==='admin'?'관리자':'일반'?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 용병 게시판
// ═══════════════════════════════════════════════════════════════
function pagMercenaries(PDO $pdo): void {
    $myProfile = null;
    if (me()) {
        $s = $pdo->prepare("SELECT * FROM mercenaries WHERE user_id=?");
        $s->execute([me()['id']]);
        $myProfile = $s->fetch();
    }

    // [4단계] 활성 SOS 알림 — 내 지역 우선
    $myRegion = me()['region'] ?? '';
    $sosList = [];
    try {
        $sq = $pdo->prepare("
            SELECT s.id, s.match_id, s.needed_count, s.position_needed, s.message, s.created_at, s.expires_at,
                   t.name AS team_name, m.title AS match_title, m.location, m.match_date, m.match_time, m.region
            FROM sos_alerts s
            JOIN matches m ON m.id=s.match_id
            JOIN teams t ON t.id=s.team_id
            WHERE s.status='ACTIVE' AND (s.expires_at IS NULL OR s.expires_at > NOW())
              AND m.match_date >= CURDATE() AND m.is_private=0
            ORDER BY (m.region=?) DESC, s.created_at DESC LIMIT 10
        ");
        $sq->execute([$myRegion]);
        $sosList = $sq->fetchAll();
    } catch(PDOException) {}

    // [용병 제안/지원 분리]
    // - incomingRequests: 선수가 우리 팀에 지원한 것 (offer_type='apply') — 캡틴이 수락/거절
    // - outgoingOffers  : 우리가 선수에게 제안한 것 (offer_type='offer') — 선수 응답 대기
    $incomingRequests = [];
    $outgoingOffers   = [];
    if (isCaptain() && myTeamId()) {
        $s = $pdo->prepare("
            SELECT mr.id, mr.status, mr.message, mr.created_at, mr.match_id, mr.offer_type,
                   u.id AS applicant_user_id, COALESCE(NULLIF(TRIM(u.nickname),''), u.name) AS applicant_name,
                   u.manner_score, u.position,
                   m.match_date, m.match_time, m.location
            FROM mercenary_requests mr
            JOIN users u ON u.id = mr.user_id
            JOIN matches m ON m.id = mr.match_id
            WHERE mr.team_id = ? AND mr.status = 'pending'
            ORDER BY mr.created_at DESC
        ");
        $s->execute([myTeamId()]);
        foreach ($s->fetchAll() as $row) {
            if (($row['offer_type'] ?? 'apply') === 'offer') $outgoingOffers[] = $row;
            else $incomingRequests[] = $row;
        }
    }

    // ── 본인: 내가 보낸 용병 신청 현황 ───────────────────────────
    // [용병 제안/지원 분리] 내가 '지원(apply)'한 것만 — '내가 보낸' 것만 보이도록
    $mySentRequests = [];
    if (me()) {
        $s = $pdo->prepare("
            SELECT mr.id, mr.status, mr.message, mr.created_at,
                   m.match_date, m.match_time, m.location,
                   t.name AS team_name
            FROM mercenary_requests mr
            JOIN matches m ON m.id = mr.match_id
            JOIN teams t ON t.id = mr.team_id
            WHERE mr.user_id = ? AND (mr.offer_type IS NULL OR mr.offer_type='apply')
            ORDER BY mr.created_at DESC LIMIT 10
        ");
        $s->execute([me()['id']]); $mySentRequests = $s->fetchAll();
    }

    // ── 받은 용병 제안 (선수 입장) ─────────────────────────────
    $receivedMercOffers = [];
    if (me()) {
        $s = $pdo->prepare("
            SELECT mr.id, mr.message, mr.created_at, mr.match_id,
                   t.name AS team_name, t.region AS team_region,
                   m.match_date, m.match_time, m.location
            FROM mercenary_requests mr
            JOIN teams t ON t.id = mr.team_id
            JOIN matches m ON m.id = mr.match_id
            WHERE mr.user_id=? AND mr.offer_type='offer' AND mr.status='pending'
            ORDER BY mr.created_at DESC
        ");
        $s->execute([me()['id']]); $receivedMercOffers = $s->fetchAll();
    }

    // ── 받은 팀 가입 제안 (선수 입장) ─────────────────────────
    // [TF-16] 만료된 대기 중 제안 자동 정리
    $pdo->exec("UPDATE team_join_offers SET status='rejected' WHERE status='pending' AND expires_at IS NOT NULL AND expires_at <= NOW()");

    $receivedJoinOffers = [];
    if (me()) {
        $s = $pdo->prepare("
            SELECT tjo.id, tjo.message, tjo.created_at, tjo.expires_at,
                   t.name AS team_name, t.region, t.district,
                   u.name AS offered_by_name
            FROM team_join_offers tjo
            JOIN teams t ON t.id = tjo.team_id
            JOIN users u ON u.id = tjo.offered_by
            WHERE tjo.user_id=? AND tjo.status='pending'
              AND (tjo.expires_at IS NULL OR tjo.expires_at > NOW())
            ORDER BY tjo.created_at DESC
        ");
        $s->execute([me()['id']]); $receivedJoinOffers = $s->fetchAll();
    }

    // ── 내 팀 예정 경기 목록 (용병 제안용) ────────────────────
    $myUpcomingMatches = [];
    if (isCaptain() && myTeamId()) {
        $s = $pdo->prepare("
            SELECT id, title, match_date, match_time, location
            FROM matches
            WHERE (home_team_id=? OR away_team_id=?)
              AND status NOT IN ('completed','cancelled')
              AND match_date >= CURDATE()
            ORDER BY match_date ASC LIMIT 10
        ");
        $s->execute([myTeamId(), myTeamId()]); $myUpcomingMatches = $s->fetchAll();
    }

    $filterRegion  = $_GET['region']   ?? '';
    $filterLevel   = $_GET['level']    ?? '';
    $filterPos     = $_GET['position'] ?? '';
    $filterMatchId = (int)($_GET['filter_match'] ?? 0); // 특정 경기 기준 필터
    $filterTeam    = $_GET['has_team'] ?? ''; // '' | 'no' | 'yes'

    $where  = 'WHERE merc.is_active=1';
    $params = [];
    if ($filterRegion) { $where .= ' AND merc.region=?';  $params[] = $filterRegion; }
    if ($filterLevel)  { $where .= ' AND merc.level=?';   $params[] = $filterLevel; }
    if ($filterPos) {
        $posGroups = ['GK'=>['GK'],'DF'=>['DF','CB','LB','RB'],'MF'=>['MF','CM','CDM','LM','RM','CAM'],'FW'=>['FW','ST','LW','RW']];
        $posKeys = $posGroups[$filterPos] ?? [$filterPos];
        $posOr = implode(' OR ', array_map(fn($pk) => "merc.positions LIKE ?", $posKeys));
        $where .= " AND ($posOr)";
        foreach ($posKeys as $pk) $params[] = '%'.$pk.'%';
    }
    if ($filterTeam === 'no') {
        $where .= ' AND tm.id IS NULL';
    } elseif ($filterTeam === 'yes') {
        $where .= ' AND tm.id IS NOT NULL';
    } elseif ($filterTeam === 'looking') {
        $where .= ' AND merc.looking_for_team=1';
    }
    if ($filterMatchId && isCaptain()) {
        // 해당 경기와 날짜·시간이 겹치는 확정 용병은 제외
        $where .= " AND u.id NOT IN (
            SELECT mr2.user_id FROM mercenary_requests mr2
            JOIN matches mx ON mx.id = mr2.match_id
            WHERE mr2.status IN ('accepted','confirmed')
              AND mx.match_date = (SELECT match_date FROM matches WHERE id=?)
        )";
        $params[] = $filterMatchId;
    }

    // 소속팀 정보 + 이미 해당 캡틴팀에 신청했는지 여부 함께 조회
    $alreadySentTo = []; // user_id 목록 — 이미 내 팀이 제안 보낸 사람
    if (isCaptain() && myTeamId() && $filterMatchId) {
        $as = $pdo->prepare("SELECT user_id FROM mercenary_requests WHERE team_id=? AND match_id=? AND status IN ('pending','accepted')");
        $as->execute([myTeamId(), $filterMatchId]);
        $alreadySentTo = array_column($as->fetchAll(), 'user_id');
    }

    $list = $pdo->prepare("
        SELECT merc.*, u.name, u.nickname, u.manner_score, u.goals, u.assists, u.profile_image_url,
               u.stat_pace, u.stat_shooting, u.stat_passing, u.stat_dribbling, u.stat_defending, u.stat_physical,
               t.id AS current_team_id, t.name AS current_team_name, t.region AS current_team_region,
               COALESCE((SELECT SUM(mpr.goals) FROM match_player_records mpr WHERE mpr.user_id=u.id AND mpr.is_mercenary=0),0) AS team_goals,
               COALESCE((SELECT SUM(mpr.assists) FROM match_player_records mpr WHERE mpr.user_id=u.id AND mpr.is_mercenary=0),0) AS team_assists,
               COALESCE((SELECT COUNT(DISTINCT mpr.match_id) FROM match_player_records mpr WHERE mpr.user_id=u.id AND mpr.is_mercenary=0),0) AS team_matches,
               COALESCE((SELECT SUM(mpr.goals) FROM match_player_records mpr WHERE mpr.user_id=u.id AND mpr.is_mercenary=1),0) AS merc_goals,
               COALESCE((SELECT SUM(mpr.assists) FROM match_player_records mpr WHERE mpr.user_id=u.id AND mpr.is_mercenary=1),0) AS merc_assists,
               COALESCE((SELECT COUNT(DISTINCT mpr.match_id) FROM match_player_records mpr WHERE mpr.user_id=u.id AND mpr.is_mercenary=1),0) AS merc_matches
        FROM mercenaries merc
        JOIN users u ON u.id = merc.user_id
        LEFT JOIN team_members tm ON tm.user_id = merc.user_id AND tm.status='active'
        LEFT JOIN teams t ON t.id = tm.team_id
        $where
        ORDER BY merc.updated_at DESC LIMIT 50
    ");
    $list->execute($params);
    $mercs = $list->fetchAll();
?>
<?php $faTab = $_GET['fa_tab'] ?? 'merc'; ?>
<div class="container py-3">
  <div style="display:flex;gap:0;margin-bottom:12px;border-radius:10px;overflow:hidden;border:1px solid var(--border)">
    <a href="?page=mercenaries&fa_tab=merc" style="flex:1;text-align:center;padding:10px;font-size:13px;font-weight:700;text-decoration:none;
      background:<?=$faTab==='merc'?'var(--primary)':'var(--bg-surface-alt)'?>;color:<?=$faTab==='merc'?'#0F1117':'var(--text-sub)'?>">📢 용병·팀구함</a>
    <a href="?page=recruits&fa_tab=recruit" style="flex:1;text-align:center;padding:10px;font-size:13px;font-weight:700;text-decoration:none;
      background:<?=$faTab==='recruit'?'var(--primary)':'var(--bg-surface-alt)'?>;color:<?=$faTab==='recruit'?'#0F1117':'var(--text-sub)'?>">🛡️ 팀원모집</a>
  </div>
  <?php if($sosList): ?>
  <!-- [4단계] 긴급 SOS 호출 섹션 (FA시장 상단 노출) -->
  <div style="margin-bottom:14px">
    <p class="section-title" style="color:#ff4d6d;margin-bottom:10px">🚨 긴급 용병 호출 (<?=count($sosList)?>건)</p>
    <div style="display:flex;gap:10px;overflow-x:auto;padding-bottom:6px">
      <?php foreach($sosList as $sos):
        $isMyRegion = $sos['region'] === $myRegion;
      ?>
      <a href="?page=match&id=<?=$sos['match_id']?>" style="text-decoration:none;color:inherit;flex-shrink:0;width:240px">
        <div class="card" style="border-color:<?=$isMyRegion?'#ff4d6d':'rgba(255,77,109,0.3)'?>;background:rgba(255,77,109,0.05)">
          <div class="card-body" style="padding:12px">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px">
              <span style="font-size:18px">🚨</span>
              <span style="font-weight:800;color:#ff4d6d;font-size:13px"><?=(int)$sos['needed_count']?>명 부족<?=$sos['position_needed']?' · '.h($sos['position_needed']):''?></span>
              <?php if($isMyRegion): ?><span style="background:#ff4d6d;color:#fff;font-size:9px;padding:1px 5px;border-radius:4px;font-weight:700">우리동네</span><?php endif; ?>
            </div>
            <div style="font-weight:700;font-size:13px;margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($sos['team_name'])?></div>
            <div style="font-size:11px;color:var(--text-sub);margin-bottom:6px">
              <?=$sos['match_date']?> <?=dayOfWeek($sos['match_date'])?> <?=substr($sos['match_time'],0,5)?> · <?=h($sos['region'] ?? '')?>
            </div>
            <div style="font-size:11px;color:var(--text-sub);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">"<?=h(mb_substr($sos['message'],0,40))?>"</div>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="card mb-3">
    <div class="card-body">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 style="font-size:18px;font-weight:700">⚡ 용병 시스템</h2>
        <button onclick="document.getElementById('merc-form').classList.toggle('open')" class="btn btn-primary btn-sm"><?= $myProfile ? '프로필 수정' : '+ 용병 등록' ?></button>
      </div>
      <?php if ($myProfile): ?>
      <div style="background:var(--bg-surface-alt);border-radius:12px;padding:14px">
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">
          <span class="badge badge-green"><?=h($myProfile['level'])?></span>
          <span class="badge badge-gray"><?=h($myProfile['positions'])?></span>
          <span class="badge badge-gray"><?=h($myProfile['region'])?> <?=h($myProfile['district'])?></span>
        </div>
        <p style="font-size:13px;color:var(--text-sub)"><?=h($myProfile['intro'] ?: '자기소개가 없습니다.')?></p>
      </div>
      <?php else: ?>
      <p style="color:var(--text-sub);font-size:13px">용병 프로필을 등록하면 팀에서 매치 용병으로 초대받을 수 있습니다.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php /* ── [수신/발신] 접이식 알림 바 ── */
  $totalPending = count($incomingRequests) + count($outgoingOffers) + count($receivedMercOffers) + count($receivedJoinOffers);
  if ($totalPending > 0): ?>
  <div class="card mb-3" style="border:1px solid rgba(255,180,0,0.3);cursor:pointer" onclick="document.getElementById('faPendingDetail').classList.toggle('open')">
    <div class="card-body" style="padding:10px 14px;display:flex;align-items:center;justify-content:space-between">
      <span style="font-size:13px;font-weight:600">📬 대기 중인 요청 <span class="badge badge-red"><?=$totalPending?></span></span>
      <i class="bi bi-chevron-down" style="color:var(--text-sub);font-size:12px"></i>
    </div>
  </div>
  <div id="faPendingDetail" class="tf-collapse">
  <?php endif; ?>

  <?php if ($incomingRequests): ?>
  <div class="card mb-3" style="border:1px solid var(--warning)">
    <div class="card-body">
      <h3 style="font-size:15px;font-weight:700;margin-bottom:4px;color:var(--warning)">
        📥 선수가 우리 팀에 지원했어요 <span class="badge badge-red"><?=count($incomingRequests)?></span>
      </h3>
      <div style="font-size:11px;color:var(--text-sub);margin-bottom:10px">수락 시 해당 경기에 용병으로 확정됩니다.</div>
      <?php foreach($incomingRequests as $r): ?>
      <div style="background:var(--bg-surface-alt);border-radius:10px;padding:12px;margin-bottom:10px;border-left:3px solid var(--warning)">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px">
          <div style="cursor:pointer;flex:1" onclick="openUserProfile(<?=(int)$r['applicant_user_id']?>)">
            <div style="font-weight:700"><?=h($r['applicant_name'])?> <i class="bi bi-info-circle" style="font-size:11px;color:var(--text-sub)"></i></div>
            <div style="font-size:12px;color:var(--text-sub)">
              포지션: <?=h($r['position']??'-')?> &nbsp;·&nbsp; 매너 <?=number_format((float)$r['manner_score'],1)?>°
            </div>
          </div>
          <div style="text-align:right;font-size:12px;color:var(--text-sub)">
            <?=$r['match_date']?> <?=dayOfWeek($r['match_date'])?> <?=substr($r['match_time']??'',0,5)?><br>
            <?=h($r['location']??'')?>
          </div>
        </div>
        <?php if($r['message']): ?>
        <p style="font-size:13px;margin-bottom:10px;color:var(--text-sub);padding:8px;background:rgba(0,0,0,0.2);border-radius:6px">"<?=h(mb_substr($r['message'],0,100))?>"</p>
        <?php endif; ?>
        <div style="display:flex;gap:6px">
          <form method="POST" style="flex:1">
            <?=csrfInput()?>
            <input type="hidden" name="action" value="mercenary_respond">
            <input type="hidden" name="req_id" value="<?=$r['id']?>">
            <input type="hidden" name="status" value="accepted">
            <button type="submit" class="btn btn-primary btn-w btn-sm">✓ 수락</button>
          </form>
          <form method="POST" style="flex:1">
            <?=csrfInput()?>
            <input type="hidden" name="action" value="mercenary_respond">
            <input type="hidden" name="req_id" value="<?=$r['id']?>">
            <input type="hidden" name="status" value="rejected">
            <button type="submit" class="btn btn-outline btn-w btn-sm">✕ 거절</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php /* ── [발신] 캡틴: 우리가 선수에게 '제안(offer)' 보낸 것 — 선수 응답 대기 ── */
  if ($outgoingOffers): ?>
  <div class="card mb-3" style="border:1px solid var(--info)">
    <div class="card-body">
      <h3 style="font-size:15px;font-weight:700;margin-bottom:4px;color:var(--info)">
        📤 우리가 보낸 제안 <span class="badge" style="background:rgba(58,158,245,0.2);color:#3a9ef5"><?=count($outgoingOffers)?></span>
      </h3>
      <div style="font-size:11px;color:var(--text-sub);margin-bottom:10px">선수가 응답할 때까지 대기 중입니다. 캡틴은 취소만 가능해요.</div>
      <?php foreach($outgoingOffers as $o): ?>
      <div style="background:var(--bg-surface-alt);border-radius:10px;padding:12px;margin-bottom:10px;border-left:3px solid var(--info)">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px">
          <div style="cursor:pointer;flex:1" onclick="openUserProfile(<?=(int)$o['applicant_user_id']?>)">
            <div style="font-weight:700"><?=h($o['applicant_name'])?> <i class="bi bi-info-circle" style="font-size:11px;color:var(--text-sub)"></i></div>
            <div style="font-size:12px;color:var(--text-sub)">포지션: <?=h($o['position']??'-')?> &nbsp;·&nbsp; 매너 <?=number_format((float)$o['manner_score'],1)?>°</div>
          </div>
          <div style="text-align:right;font-size:12px;color:var(--text-sub)">
            <?=$o['match_date']?> <?=dayOfWeek($o['match_date'])?> <?=substr($o['match_time']??'',0,5)?><br>
            <?=h($o['location']??'')?>
          </div>
        </div>
        <?php if($o['message']): ?>
        <p style="font-size:13px;margin-bottom:10px;color:var(--text-sub);padding:8px;background:rgba(0,0,0,0.2);border-radius:6px">"<?=h(mb_substr($o['message'],0,100))?>"</p>
        <?php endif; ?>
        <div style="display:flex;align-items:center;gap:10px">
          <div style="flex:1;padding:6px 0;font-size:12px;color:var(--info);display:flex;align-items:center;gap:6px">
            <span style="width:6px;height:6px;border-radius:50%;background:var(--info);display:inline-block;animation:pulse 1.5s infinite"></span>
            선수의 응답을 기다리는 중 · <?=timeAgo($o['created_at'])?> 전 보냄
          </div>
          <form method="POST" style="margin:0" onsubmit="return confirm('제안을 취소하시겠습니까?')">
            <?=csrfInput()?>
            <input type="hidden" name="action" value="mercenary_respond">
            <input type="hidden" name="req_id" value="<?=$o['id']?>">
            <input type="hidden" name="status" value="cancelled">
            <button type="submit" class="btn btn-ghost btn-sm" style="font-size:11px;color:var(--text-sub)">취소</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php /* ── 본인: 내가 보낸 용병 신청 현황 ─────────────────── */
  if ($mySentRequests): ?>
  <div class="card mb-3">
    <div class="card-body">
      <h3 style="font-size:15px;font-weight:700;margin-bottom:12px">📋 내 용병 신청 현황</h3>
      <?php foreach($mySentRequests as $r):
        $sc = match($r['status']){'accepted'=>'badge-green','rejected'=>'badge-red',default=>'badge-yellow'};
        $sl = match($r['status']){'accepted'=>'✓ 수락됨','rejected'=>'✕ 거절됨',default=>'⏳ 대기중'};
        $sentAgo = timeAgo($r['created_at']);
      ?>
      <div style="padding:10px 0;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
        <div style="flex:1;min-width:0">
          <div style="font-weight:600"><?=h($r['team_name'])?></div>
          <div style="font-size:12px;color:var(--text-sub)">
            📅 <?=$r['match_date']?> <?=dayOfWeek($r['match_date'])?> <?=substr($r['match_time']??'',0,5)?>
            &nbsp;·&nbsp;<i class="bi bi-geo-alt"></i> <?=h(mb_substr($r['location']??'',0,14))?>
          </div>
          <div style="font-size:11px;color:var(--text-sub);margin-top:2px">신청: <?=$sentAgo?></div>
        </div>
        <span class="badge <?=$sc?>" style="flex-shrink:0"><?=$sl?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php /* ── 받은 용병 제안 (선수 입장) ─── */
  if ($receivedMercOffers): ?>
  <div class="card mb-3" style="border:1px solid var(--primary)">
    <div class="card-body">
      <h3 style="font-size:15px;font-weight:700;margin-bottom:12px;color:var(--primary)">
        ⚡ 받은 용병 제안 <span class="badge badge-green"><?=count($receivedMercOffers)?></span>
      </h3>
      <?php foreach($receivedMercOffers as $r): ?>
      <div style="background:var(--bg-surface-alt);border-radius:12px;padding:14px;margin-bottom:10px;border-left:3px solid var(--primary)">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
          <div>
            <div style="font-weight:700;font-size:15px"><?=h($r['team_name'])?></div>
            <div style="font-size:12px;color:var(--text-sub)"><?=h($r['team_region'])?> · <?=timeAgo($r['created_at'])?> 제안</div>
          </div>
          <span class="badge badge-green" style="font-size:10px">용병 제안</span>
        </div>
        <!-- 경기 정보 카드 -->
        <div style="background:rgba(0,255,136,0.06);border:1px solid rgba(0,255,136,0.15);border-radius:8px;padding:8px 12px;margin-bottom:10px">
          <div style="font-size:12px;color:var(--primary);font-weight:600">📅 <?=$r['match_date']?> <?=dayOfWeek($r['match_date'])?> <?=substr($r['match_time']??'',0,5)?></div>
          <div style="font-size:12px;color:var(--text-sub)">📍 <?=h($r['location']??'-')?></div>
        </div>
        <?php if($r['message']): ?>
        <p style="font-size:13px;margin-bottom:10px;color:var(--text-sub);padding:8px 12px;background:rgba(0,0,0,0.15);border-radius:8px;border-left:2px solid rgba(255,255,255,0.1)">"<?=h(mb_substr($r['message'],0,100))?>"</p>
        <?php endif; ?>
        <div style="display:flex;gap:8px">
          <form method="POST" style="flex:1"><?=csrfInput()?><input type="hidden" name="action" value="respond_mercenary_offer"><input type="hidden" name="req_id" value="<?=$r['id']?>"><input type="hidden" name="answer" value="accept"><button type="submit" class="btn btn-primary btn-w">✓ 수락하기</button></form>
          <form method="POST" style="flex:1"><?=csrfInput()?><input type="hidden" name="action" value="respond_mercenary_offer"><input type="hidden" name="req_id" value="<?=$r['id']?>"><input type="hidden" name="answer" value="reject"><button type="submit" class="btn btn-outline btn-w">✕ 거절</button></form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php /* ── 받은 팀 가입 제안 (선수 입장) ─── */
  if ($receivedJoinOffers): ?>
  <div class="card mb-3" style="border:1px solid var(--info)">
    <div class="card-body">
      <h3 style="font-size:15px;font-weight:700;margin-bottom:12px;color:var(--info)">
        🛡️ 받은 팀 가입 제안 <span class="badge badge-blue"><?=count($receivedJoinOffers)?></span>
      </h3>
      <?php foreach($receivedJoinOffers as $o): ?>
      <div style="background:var(--bg-surface-alt);border-radius:12px;padding:14px;margin-bottom:10px;border-left:3px solid var(--info)">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
          <div>
            <div style="font-weight:700;font-size:15px"><?=h($o['team_name'])?></div>
            <div style="font-size:12px;color:var(--text-sub)"><?=h($o['region'])?> <?=h($o['district']??'')?> · <?=timeAgo($o['created_at'])?> 제안</div>
          </div>
          <span class="badge badge-blue" style="font-size:10px">팀 가입 제안</span>
        </div>
        <div style="font-size:12px;color:var(--info);margin-bottom:8px">
          <i class="bi bi-person-check"></i> <?=h($o['offered_by_name'])?>님이 초대했습니다
        </div>
        <?php if($o['message']): ?>
        <p style="font-size:13px;margin-bottom:10px;color:var(--text-sub);padding:8px 12px;background:rgba(0,0,0,0.15);border-radius:8px;border-left:2px solid rgba(56,189,248,0.3)">"<?=h(mb_substr($o['message'],0,150))?>"</p>
        <?php endif; ?>
        <div style="display:flex;gap:8px">
          <form method="POST" style="flex:1"><?=csrfInput()?><input type="hidden" name="action" value="respond_team_join_offer"><input type="hidden" name="offer_id" value="<?=$o['id']?>"><input type="hidden" name="answer" value="accept"><button type="submit" class="btn btn-primary btn-w">🛡️ 팀 합류하기</button></form>
          <form method="POST" style="flex:1"><?=csrfInput()?><input type="hidden" name="action" value="respond_team_join_offer"><input type="hidden" name="offer_id" value="<?=$o['id']?>"><input type="hidden" name="answer" value="reject"><button type="submit" class="btn btn-outline btn-w">✕ 거절</button></form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($totalPending > 0): ?>
  </div><!-- /faPendingDetail -->
  <?php endif; ?>

  <div id="merc-form" class="card mb-3 tf-collapse">
    <div class="card-body">
      <h3 style="font-size:16px;font-weight:700;margin-bottom:10px">용병 프로필 등록/수정</h3>
      <?php
        $meUser = me();
        $dbMe = $pdo->prepare("SELECT height, weight, preferred_foot, is_player_background, position, sub_positions FROM users WHERE id=?");
        $dbMe->execute([$meUser['id']]); $dbMe = $dbMe->fetch();
      ?>
      <div style="font-size:11px;color:var(--text-sub);margin-bottom:12px;padding:8px 10px;background:rgba(0,255,136,0.05);border-radius:8px;border:1px solid rgba(0,255,136,0.1)">
        💡 프로필 정보가 자동으로 채워집니다. 용병 활동 시 다른 포지션/지역을 원하면 여기서 변경하세요.
      </div>
      <form method="POST">
        <?= csrfInput() ?>
        <input type="hidden" name="action" value="save_mercenary">
        <!-- 선호 포지션 (최대 3개) -->
        <div class="form-group">
          <label class="form-label">선호 포지션 <span style="font-size:11px;color:var(--text-sub)">(최대 3개, 첫 번째가 주포지션)</span></label>
          <?php
            $mercPosInfo = [
              'GK'=>['#ff9500','GK','골키퍼'],
              'LB'=>['#3a9ef5','LB','좌측백'],'CB'=>['#3a9ef5','CB','센터백'],'RB'=>['#3a9ef5','RB','우측백'],
              'CDM'=>['#00c87a','CDM','수비미드'],'CM'=>['#00ff88','CM','센터미드'],
              'LM'=>['#00ff88','LM','좌측미드'],'RM'=>['#00ff88','RM','우측미드'],
              'CAM'=>['#ffd60a','CAM','공격미드'],
              'LW'=>['#ff6b6b','LW','좌측윙'],'ST'=>['#ff6b6b','ST','스트라이커'],'RW'=>['#ff6b6b','RW','우측윙'],
            ];
            $pos12 = ['GK','LB','CB','RB','CDM','CM','LM','RM','CAM','LW','ST','RW'];
            $posMap4to12 = ['DF'=>'CB','MF'=>'CM','FW'=>'ST'];
            $curMercPos = $myProfile ? array_filter(explode(',', $myProfile['positions'] ?? '')) : [];
            // 4포지션 → 12포지션 매핑
            $curMercPos = array_map(fn($p) => $posMap4to12[$p] ?? $p, $curMercPos);
            // 12포지션에 없는 값 제거 (멀티 등)
            $curMercPos = array_values(array_filter($curMercPos, fn($p) => in_array($p, $pos12)));
            if (empty($curMercPos) && me()['position']) {
              $raw = me()['position'];
              $curMercPos = [$posMap4to12[$raw] ?? $raw];
              $subP = me()['sub_positions'] ?? '';
              if ($subP) {
                foreach (explode(',', $subP) as $sp) {
                  $mapped = $posMap4to12[$sp] ?? $sp;
                  if (in_array($mapped, $pos12) && !in_array($mapped, $curMercPos)) $curMercPos[] = $mapped;
                }
              }
              $curMercPos = array_values(array_filter($curMercPos, fn($p) => in_array($p, $pos12)));
            }
          ?>
          <input type="hidden" name="positions" id="mercPosJson" value="<?=h(implode(',', $curMercPos))?>">
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:4px" id="mercPosGrid">
            <?php foreach($mercPosInfo as $p=>[$pc,$pl,$pd]):
              $isSelected = in_array($p, $curMercPos);
              $order = $isSelected ? array_search($p, $curMercPos) + 1 : 0;
            ?>
            <div class="mpos-wrap" data-pos="<?=$p?>" onclick="toggleMercPos(this)" style="cursor:pointer">
              <div class="pos-chip <?=$isSelected?'active':''?>" style="--pos-color:<?=$pc?>;position:relative">
                <div style="font-size:12px;font-weight:700"><?=$pl?></div>
                <div style="font-size:8px;opacity:0.7"><?=$pd?></div>
                <?php if($isSelected): ?>
                <span class="mpos-order" style="position:absolute;top:-4px;right:-4px;background:<?=$pc?>;color:#000;width:14px;height:14px;border-radius:50%;font-size:8px;font-weight:800;display:flex;align-items:center;justify-content:center"><?=$order?></span>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div id="mercPosCount" style="font-size:10px;color:var(--text-sub);margin-top:3px;text-align:right"><?=count($curMercPos)?>/3</div>
          <script>
          var mercSelPos = <?=json_encode(array_values($curMercPos))?>;
          function toggleMercPos(el) {
            var pos = el.dataset.pos, idx = mercSelPos.indexOf(pos);
            if (idx >= 0) { mercSelPos.splice(idx, 1); }
            else { if (mercSelPos.length >= 3) { alert('최대 3개까지 선택 가능'); return; } mercSelPos.push(pos); }
            document.getElementById('mercPosJson').value = mercSelPos.join(',');
            document.getElementById('mercPosCount').textContent = mercSelPos.length + '/3';
            document.querySelectorAll('.mpos-wrap').forEach(function(w) {
              var p = w.dataset.pos, i = mercSelPos.indexOf(p), chip = w.querySelector('.pos-chip'), badge = w.querySelector('.mpos-order');
              if (i >= 0) { chip.classList.add('active'); if (!badge) { badge = document.createElement('span'); badge.className='mpos-order'; badge.style.cssText='position:absolute;top:-4px;right:-4px;background:'+chip.style.getPropertyValue('--pos-color')+';color:#000;width:14px;height:14px;border-radius:50%;font-size:8px;font-weight:800;display:flex;align-items:center;justify-content:center'; chip.appendChild(badge); } badge.textContent=i+1; }
              else { chip.classList.remove('active'); if (badge) badge.remove(); }
            });
          }
          </script>
        </div>
        <?php
          $defaultLevel = $myProfile['level'] ?? (($dbMe['is_player_background'] ?? 0) ? '세미프로' : '아마');
          $defaultFoot = $myProfile['preferred_foot'] ?? $dbMe['preferred_foot'] ?? '';
          $defaultRegion = $myProfile['region'] ?? $meUser['region'] ?? '서울';
          $defaultDistrict = $myProfile['district'] ?? $meUser['district'] ?? '';
          $defaultHeight = $dbMe['height'] ?? '';
          $defaultWeight = $dbMe['weight'] ?? '';
        ?>
        <!-- 키/몸무게 (나란히) -->
        <div class="form-row">
          <div class="form-group"><label class="form-label">키 (cm)</label>
            <input type="number" name="height" min="140" max="220" class="form-control" value="<?=h($myProfile['height'] ?? $defaultHeight)?>" placeholder="175"></div>
          <div class="form-group"><label class="form-label">몸무게 (kg)</label>
            <input type="number" name="weight" min="30" max="200" class="form-control" value="<?=h($myProfile['weight'] ?? $defaultWeight)?>" placeholder="70"></div>
        </div>
        <!-- 주발 (칩 3개) -->
        <div class="form-group">
          <label class="form-label">주발</label>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px">
            <?php $footOpts2 = ['LEFT'=>['왼발','#3a9ef5'],'RIGHT'=>['오른발','#ff9500'],'BOTH'=>['양발','#00ff88']];
            foreach($footOpts2 as $fk=>[$fl,$fc]): ?>
            <label style="cursor:pointer">
              <input type="radio" name="preferred_foot" value="<?=$fk?>" <?=$defaultFoot===$fk?'checked':''?> style="display:none" class="pos-radio2">
              <div class="pos-chip" style="--pos-color:<?=$fc?>">
                <div style="font-size:13px;font-weight:700"><?=$fl?></div>
              </div>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <!-- 레벨 (칩 3개) -->
        <div class="form-group">
          <label class="form-label">레벨</label>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px">
            <?php $lvlOpts = ['입문'=>['🟢','#00ff88'],'아마'=>['🔵','#3a9ef5'],'세미프로'=>['🔴','#ff6b6b']];
            foreach($lvlOpts as $lk=>[$li,$lc]): ?>
            <label style="cursor:pointer">
              <input type="radio" name="level" value="<?=$lk?>" <?=$defaultLevel===$lk?'checked':''?> style="display:none" class="pos-radio2">
              <div class="pos-chip" style="--pos-color:<?=$lc?>">
                <div style="font-size:13px;font-weight:700"><?=$li?> <?=$lk?></div>
              </div>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <!-- 선수 출신 + 팀 구함 -->
        <div style="display:flex;gap:6px;margin-bottom:14px">
          <label style="flex:1;display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px;background:var(--bg-surface-alt);border-radius:8px">
            <input type="checkbox" name="is_player_background" value="1" <?=($myProfile['is_player_background'] ?? $dbMe['is_player_background'] ?? 0)?'checked':''?> style="width:16px;height:16px;accent-color:var(--primary)">
            <div>
              <div style="font-weight:600;font-size:12px">⚽ 선수출신</div>
            </div>
          </label>
          <label style="flex:1;display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px;background:rgba(58,158,245,0.06);border-radius:8px;border:1px solid rgba(58,158,245,0.15)">
            <input type="checkbox" name="looking_for_team" value="1" <?=($myProfile['looking_for_team'] ?? 0)?'checked':''?> style="width:16px;height:16px;accent-color:#3a9ef5">
            <div>
              <div style="font-weight:600;font-size:12px;color:#3a9ef5">🔍 팀 구함</div>
            </div>
          </label>
        </div>
        <!-- 지역 (나란히) -->
        <div class="form-row">
          <div class="form-group"><label class="form-label">활동 지역</label>
            <input type="text" name="region" class="form-control" placeholder="서울" value="<?=h($defaultRegion)?>"></div>
          <div class="form-group"><label class="form-label">구/군</label>
            <input type="text" name="district" class="form-control" placeholder="강남구" value="<?=h($defaultDistrict)?>"></div>
        </div>
        <!-- 가능 시간/포맷 (나란히) -->
        <div class="form-row">
          <div class="form-group"><label class="form-label">가능 시간</label>
            <input type="text" name="available_time" class="form-control" placeholder="평일 저녁, 주말" value="<?=h($myProfile['available_time']??'')?>"></div>
          <div class="form-group"><label class="form-label">가능 포맷</label>
            <input type="text" name="format_types" class="form-control" placeholder="풋살, 11vs11" value="<?=h($myProfile['format_types']??'')?>"></div>
        </div>
        <!-- 참가비 -->
        <div class="form-group">
          <label class="form-label">참가비 조건</label>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px">
            <?php foreach(['무료만'=>'#00ff88','유료 가능'=>'#ffd60a','상관없음'=>'#3a9ef5'] as $f=>$fcc): ?>
            <label style="cursor:pointer">
              <input type="radio" name="fee_preference" value="<?=$f?>" <?=($myProfile['fee_preference']??'상관없음')===$f?'checked':''?> style="display:none" class="pos-radio2">
              <div class="pos-chip" style="--pos-color:<?=$fcc?>">
                <div style="font-size:12px;font-weight:700"><?=$f?></div>
              </div>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <!-- 자기소개 -->
        <div class="form-group">
          <label class="form-label">자기소개</label>
          <textarea name="intro" class="form-control" rows="2" placeholder="플레이 스타일, 장점, 매너 등"><?=h($myProfile['intro']??'')?></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-w">저장</button>
      </form>
    </div>
  </div>

  <form method="GET" style="margin-bottom:14px">
    <input type="hidden" name="page" value="mercenaries">
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
      <select name="region" class="form-control" style="flex:1;min-width:100px">
        <option value="">전체 지역</option>
        <?php foreach(['서울','경기','인천','부산','대구','광주'] as $r): ?><option value="<?=$r?>" <?=$filterRegion===$r?'selected':''?>><?=$r?></option><?php endforeach; ?>
      </select>
      <select name="level" class="form-control" style="flex:1;min-width:100px">
        <option value="">전체 레벨</option>
        <?php foreach(['입문','아마','세미프로'] as $l): ?><option value="<?=$l?>" <?=$filterLevel===$l?'selected':''?>><?=$l?></option><?php endforeach; ?>
      </select>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php /* 캡틴: 경기 기준 필터 + 팀 유무 필터 */
      if (isCaptain() && $myUpcomingMatches): ?>
      <select name="filter_match" class="form-control" style="flex:2;min-width:160px">
        <option value="">경기 선택 안 함 (전체)</option>
        <?php foreach($myUpcomingMatches as $um): ?>
        <option value="<?=$um['id']?>" <?=$filterMatchId===$um['id']?'selected':''?>>
          <?=$um['match_date']?> <?=substr($um['match_time']??'',0,5)?> <?=h(mb_substr($um['title']?:$um['location'],0,14))?>
        </option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <select name="position" class="form-control" style="flex:1;min-width:80px">
        <option value="">포지션</option>
        <?php foreach(['GK','DF','MF','FW'] as $fp): ?>
        <option value="<?=$fp?>" <?=($filterPos??'')===$fp?'selected':''?>><?=$fp?></option>
        <?php endforeach; ?>
      </select>
      <select name="has_team" class="form-control" style="flex:1;min-width:80px">
        <option value="">팀유무</option>
        <option value="no" <?=$filterTeam==='no'?'selected':''?>>팀없음</option>
        <option value="yes" <?=$filterTeam==='yes'?'selected':''?>>팀있음</option>
        <option value="looking" <?=$filterTeam==='looking'?'selected':''?>>팀구함</option>
      </select>
      <button type="submit" class="btn btn-primary" style="flex-shrink:0">🔍</button>
    </div>
    <?php if ($filterMatchId): ?>
    <div style="font-size:12px;color:var(--info);margin-top:8px;padding:6px 10px;background:rgba(56,189,248,0.08);border-radius:8px">
      ✅ 해당 경기 시간에 이미 다른 팀 경기가 확정된 선수는 목록에서 제외되었습니다.
    </div>
    <?php endif; ?>
  </form>

  <!-- 용병 목록 (서든어택 영입 스타일) -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
    <span style="font-size:14px;font-weight:700">📢 용병 <?=count($mercs)?>명</span>
  </div>

  <?php if (empty($mercs)): ?>
  <div style="text-align:center;padding:60px 20px;color:var(--text-sub)">
    <div style="font-size:48px;margin-bottom:12px">🏃</div>
    <p style="font-size:15px;margin-bottom:6px;color:var(--text-main);font-weight:700">현재 용병 모집이 없습니다</p>
    <p style="font-size:13px;margin-bottom:16px;line-height:1.6">용병이 필요하면 프로필을 등록해보세요!<br>경기 초대를 받을 수 있어요</p>
    <?php if(me() && !$myProfile): ?>
      <button onclick="document.getElementById('merc-form').classList.toggle('open')" class="btn btn-primary" style="font-size:14px"><i class="bi bi-plus-circle"></i> 용병 등록하기</button>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:14px">
  <?php foreach ($mercs as $mi => $m):
    $hasTeam      = !empty($m['current_team_id']);
    $alreadySent  = in_array((int)$m['user_id'], $alreadySentTo);
    $posMap4 = ['DF'=>'CB','MF'=>'CM','FW'=>'ST'];
    $mPos = $posMap4[$m['positions']??''] ?? ($m['positions'] ?: '-');
    $lvlColor = match($m['level']??'') { '세미프로'=>'#ff6b6b', '아마'=>'#3a9ef5', default=>'#00ff88' };
    $posColor = match(true) { str_contains($mPos,'GK')=>'#ff9500', in_array($mPos,['CB','LB','RB'])=>'#3a9ef5', in_array($mPos,['LW','ST','RW'])=>'#ff6b6b', default=>'#00ff88' };
    $manner = (float)$m['manner_score'];
    $mannerColor = $manner >= 40 ? '#00ff88' : ($manner >= 35 ? '#ffd60a' : '#ff4d6d');
  ?>
  <div style="background:linear-gradient(135deg, rgba(255,255,255,0.03) 0%, rgba(255,255,255,0.01) 100%);border:1px solid rgba(255,255,255,0.08);border-radius:12px;overflow:hidden;position:relative">
    <!-- 레벨 띠 -->
    <div style="background:<?=$lvlColor?>15;padding:3px 0;text-align:center">
      <span style="font-size:9px;font-weight:800;color:<?=$lvlColor?>;letter-spacing:1px"><?=strtoupper(h($m['level']??'아마'))?></span>
    </div>
    <!-- 프로필 -->
    <div style="padding:6px 6px 0;text-align:center">
      <div onclick="openUserProfile(<?=(int)$m['user_id']?>)" style="cursor:pointer">
        <?php if(!empty($m['profile_image_url'])): ?>
          <img src="<?=h($m['profile_image_url'])?>" style="width:54px;height:54px;border-radius:50%;object-fit:cover;border:2.5px solid <?=$posColor?>;margin-bottom:5px" onclick="tfViewPhoto('<?=h($m['profile_image_url'])?>')">
        <?php else: ?>
          <div style="width:54px;height:54px;border-radius:50%;background:<?=$posColor?>15;border:2.5px solid <?=$posColor?>;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:800;color:<?=$posColor?>;margin:0 auto 5px"><?=mb_substr($m['name']??'?',0,1,'UTF-8')?></div>
        <?php endif; ?>
        <div style="font-weight:700;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=h(displayName($m))?></div>
      </div>
      <!-- 포지션 + 팀 -->
      <div style="margin-top:4px;display:flex;justify-content:center;gap:3px;flex-wrap:wrap">
        <span style="font-size:10px;padding:2px 7px;border-radius:4px;background:<?=$posColor?>20;color:<?=$posColor?>;font-weight:700"><?=h($mPos)?></span>
        <?php if($hasTeam): ?><span style="font-size:10px;padding:2px 7px;border-radius:4px;background:rgba(58,158,245,0.1);color:#3a9ef5"><?=h(mb_substr($m['current_team_name'],0,5,'UTF-8'))?></span>
        <?php else: ?><span style="font-size:10px;padding:2px 7px;border-radius:4px;background:rgba(0,255,136,0.1);color:var(--primary)">FA</span><?php endif; ?>
        <?php if(!empty($m['looking_for_team'])): ?><span style="font-size:9px;padding:2px 6px;border-radius:4px;background:rgba(58,158,245,0.15);color:#3a9ef5;font-weight:700">🔍 팀구함</span><?php endif; ?>
      </div>
      <!-- 선호 포지션 -->
      <?php
        $allMercPos = array_filter(explode(',', $m['positions'] ?? ''));
        $posMap4r = ['DF'=>'CB','MF'=>'CM','FW'=>'ST'];
        $allMercPos = array_map(fn($p) => $posMap4r[$p] ?? $p, $allMercPos);
        if (count($allMercPos) > 1):
      ?>
      <div style="margin-top:3px;font-size:9px;color:var(--text-sub);text-align:center">
        선호 <?php foreach($allMercPos as $ap): ?><span style="margin:0 1px"><?=h($ap)?></span><?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <!-- 스탯 바 (팀/용병 분리) -->
    <div style="margin:5px 5px 0;border-top:1px solid rgba(255,255,255,0.06);padding-top:5px">
      <div style="display:flex;align-items:center;padding:3px 5px;background:rgba(0,255,136,0.05);border-radius:5px;margin-bottom:3px">
        <span style="font-size:9px;color:var(--primary);font-weight:700;width:28px">팀</span>
        <span style="flex:1;font-size:11px;font-weight:700;color:#ffb400"><?=(int)$m['team_goals']?>골</span>
        <span style="flex:1;font-size:11px;font-weight:700;color:#3a9ef5"><?=(int)$m['team_assists']?>도움</span>
        <span style="font-size:9px;color:var(--text-sub)"><?=(int)$m['team_matches']?>경기</span>
      </div>
      <div style="display:flex;align-items:center;padding:3px 5px;background:rgba(255,180,0,0.05);border-radius:5px">
        <span style="font-size:9px;color:#ffb400;font-weight:700;width:28px">용병</span>
        <span style="flex:1;font-size:11px;font-weight:700;color:#ffb400"><?=(int)$m['merc_goals']?>골</span>
        <span style="flex:1;font-size:11px;font-weight:700;color:#3a9ef5"><?=(int)$m['merc_assists']?>도움</span>
        <span style="font-size:9px;color:var(--text-sub)"><?=(int)$m['merc_matches']?>경기</span>
      </div>
      <?php
        $avgStat = round(((int)($m['stat_pace']??50) + (int)($m['stat_shooting']??50) + (int)($m['stat_passing']??50) + (int)($m['stat_dribbling']??50) + (int)($m['stat_defending']??50) + (int)($m['stat_physical']??50)) / 6);
        $skillLabel = $avgStat >= 75 ? '상' : ($avgStat >= 50 ? '중' : '하');
        $skillColor = $avgStat >= 75 ? '#ff6b6b' : ($avgStat >= 50 ? '#ffd60a' : '#3a9ef5');
        $skillBar = min(100, $avgStat);
      ?>
      <div style="display:flex;align-items:center;gap:6px;margin-top:4px;padding:0 5px">
        <span style="font-size:9px;color:var(--text-sub);min-width:26px">실력</span>
        <div style="flex:1;height:6px;background:rgba(255,255,255,0.06);border-radius:3px;overflow:hidden">
          <div style="width:<?=$skillBar?>%;height:100%;background:<?=$skillColor?>;border-radius:3px"></div>
        </div>
        <span style="font-size:10px;font-weight:800;color:<?=$skillColor?>"><?=$skillLabel?></span>
      </div>
      <div style="text-align:center;margin-top:3px">
        <span style="font-size:12px;font-weight:800;color:<?=$mannerColor?>;font-family:'Space Grotesk',sans-serif">🌡<?=number_format($manner,1)?>°</span>
      </div>
    </div>
    <!-- 지역 + 정보 -->
    <div style="display:flex;align-items:center;padding:3px 5px 0;font-size:9px;color:var(--text-sub);justify-content:space-between">
      <span>📍<?=h($m['region'])?><?=$m['available_time']?' · ⏰'.h(mb_substr($m['available_time'],0,6,'UTF-8')):''?></span>
      <span onclick="openUserProfile(<?=(int)$m['user_id']?>)" style="color:var(--primary);cursor:pointer;font-weight:600">상세 ›</span>
    </div>
    <!-- 액션 버튼 -->
    <?php if(me()['id'] !== (int)$m['user_id']): ?>
    <div style="padding:4px 5px 6px;display:flex;flex-direction:column;gap:3px">
      <?php if(isCaptain() && $myUpcomingMatches && !$alreadySent): ?>
      <button onclick="document.getElementById('mercOffer<?=$m['user_id']?>').style.display='flex'"
        class="btn btn-primary btn-sm" style="width:100%;font-size:10px;padding:5px 0;border-radius:6px">
        ⚡ 영입제안
      </button>
      <?php if(!$hasTeam): ?>
      <button onclick="document.getElementById('mercOffer<?=$m['user_id']?>').style.display='flex';document.getElementById('mercOffer<?=$m['user_id']?>').dataset.mode='join'"
        class="btn btn-outline btn-sm" style="width:100%;font-size:10px;padding:5px 0;border-radius:6px">
        🛡️ 팀원영입
      </button>
      <?php endif; ?>
      <?php elseif($alreadySent): ?>
      <span style="font-size:9px;color:var(--text-sub);text-align:center;padding:5px 0">✓ 제안완료</span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <!-- 영입 폼 (카드 밖 팝업) -->
  <div id="mercOffer<?=$m['user_id']?>" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;display:none;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:var(--bg-surface);border-radius:14px;padding:20px;max-width:300px;width:100%">
      <div style="font-weight:700;font-size:15px;margin-bottom:12px">⚡ <?=h(displayName($m))?> 영입</div>
      <form method="POST" style="margin-bottom:8px"><?=csrfInput()?><input type="hidden" name="action" value="start_chat"><input type="hidden" name="target_user_id" value="<?=(int)$m['user_id']?>">
        <button type="submit" class="btn btn-outline btn-w" style="font-size:12px"><i class="bi bi-chat-dots"></i> 메시지 보내기</button></form>
      <?php if (isCaptain() && $myUpcomingMatches): ?>
      <form method="POST" style="margin-bottom:6px"><?=csrfInput()?><input type="hidden" name="action" value="offer_mercenary"><input type="hidden" name="target_user_id" value="<?=$m['user_id']?>">
        <select name="match_id" class="form-control" style="font-size:12px;margin-bottom:6px" required>
          <option value="">경기 선택</option>
          <?php foreach($myUpcomingMatches as $um): ?><option value="<?=$um['id']?>"><?=$um['match_date']?> <?=substr($um['match_time'],0,5)?> <?=h(mb_substr($um['title']??$um['location']??'',0,10,'UTF-8'))?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-w" style="font-size:12px">⚡ 용병 제안</button></form>
      <?php if (!$hasTeam): ?>
      <form method="POST"><?=csrfInput()?><input type="hidden" name="action" value="offer_team_join"><input type="hidden" name="target_user_id" value="<?=$m['user_id']?>">
        <button type="submit" class="btn btn-outline btn-w" style="font-size:12px;margin-top:4px">🛡️ 팀 가입 제안</button></form>
      <?php endif; endif; ?>
      <button onclick="this.closest('[id^=mercOffer]').style.display='none'" class="btn btn-ghost btn-w" style="margin-top:8px;font-size:12px">닫기</button>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 팀원 모집 게시판
// ═══════════════════════════════════════════════════════════════
function pagRecruits(PDO $pdo): void {
    $posts = $pdo->prepare("
        SELECT rp.*, t.name AS team_name, t.region AS t_region, t.district AS t_district,
               t.win, t.draw, t.loss, COALESCE(t.manner_score,36.5) AS manner_score,
               (SELECT COUNT(*) FROM recruit_applications ra WHERE ra.post_id=rp.id AND ra.status='pending') AS apply_count
        FROM recruit_posts rp JOIN teams t ON t.id=rp.team_id
        WHERE rp.status='open' ORDER BY rp.created_at DESC LIMIT 30
    ");
    $posts->execute(); $posts = $posts->fetchAll();

    // ── 본인: 내가 보낸 팀 가입 신청 현황 ────────────────────────
    $mySentApps = [];
    if (me() && !myTeamId()) {
        $s = $pdo->prepare("
            SELECT ra.id, ra.status, ra.created_at, ra.message,
                   rp.positions_needed, t.name AS team_name, t.region
            FROM recruit_applications ra
            JOIN recruit_posts rp ON rp.id = ra.post_id
            JOIN teams t ON t.id = rp.team_id
            WHERE ra.user_id = ?
            ORDER BY ra.created_at DESC LIMIT 10
        ");
        $s->execute([me()['id']]); $mySentApps = $s->fetchAll();
    }

    $applicants = [];
    if (isCaptain() && myTeamId()) {
        $s = $pdo->prepare("
            SELECT ra.*, u.name AS uname, COALESCE(u.manner_score,36.5) AS u_manner, u.goals, u.assists, rp.positions_needed
            FROM recruit_applications ra JOIN users u ON u.id=ra.user_id JOIN recruit_posts rp ON rp.id=ra.post_id
            WHERE rp.team_id=? AND ra.status='pending' ORDER BY ra.created_at DESC
        ");
        $s->execute([myTeamId()]); $applicants = $s->fetchAll();
    }
?>
<div class="container py-3">
  <div style="display:flex;gap:0;margin-bottom:12px;border-radius:10px;overflow:hidden;border:1px solid var(--border)">
    <a href="?page=mercenaries" style="flex:1;text-align:center;padding:10px;font-size:13px;font-weight:700;text-decoration:none;
      background:var(--bg-surface-alt);color:var(--text-sub)">📢 용병·팀구함</a>
    <a href="?page=recruits" style="flex:1;text-align:center;padding:10px;font-size:13px;font-weight:700;text-decoration:none;
      background:var(--primary);color:#0F1117">🛡️ 팀원모집</a>
  </div>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
    <h2 style="font-size:18px;font-weight:700">📢 팀원 모집</h2>
    <?php if(isCaptain()): ?><button onclick="document.getElementById('recruit-form').classList.toggle('open')" class="btn btn-primary btn-sm">+ 모집글 작성</button><?php endif; ?>
  </div>

  <?php if(isCaptain()): ?>
  <div id="recruit-form" class="card mb-3 tf-collapse">
    <div class="card-body">
      <h3 style="font-size:16px;font-weight:700;margin-bottom:14px">팀원 모집 게시글 작성</h3>
      <form method="POST">
        <?= csrfInput() ?>
        <input type="hidden" name="action" value="create_recruit">
        <div class="form-grid-2">
          <div class="form-group"><label class="form-label">모집 포지션</label><select name="positions_needed" class="form-control"><?php foreach(['무관','GK','DF','MF','FW'] as $p): ?><option><?=$p?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label class="form-label">모집 인원</label><select name="recruit_count" class="form-control"><?php for($i=1;$i<=5;$i++): ?><option><?=$i?></option><?php endfor; ?></select></div>
          <div class="form-group"><label class="form-label">요구 레벨</label><select name="level_required" class="form-control"><?php foreach(['무관','입문','아마','세미프로'] as $l): ?><option><?=$l?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label class="form-label">팀 성향</label><select name="team_style" class="form-control"><?php foreach(['친선','빡겜','초보환영','매너중시'] as $s): ?><option><?=$s?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label class="form-label">지역</label><input type="text" name="region" class="form-control" placeholder="서울"></div>
          <div class="form-group"><label class="form-label">구/군</label><input type="text" name="district" class="form-control" placeholder="강남구"></div>
          <div class="form-group"><label class="form-label">주 활동 시간</label><input type="text" name="play_time" class="form-control" placeholder="주말 오전"></div>
          <div class="form-group"><label class="form-label">회비</label><select name="membership_fee" class="form-control"><?php foreach(['없음','있음','협의'] as $f): ?><option><?=$f?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-group"><label class="form-label">팀 소개</label><textarea name="intro" class="form-control" rows="3" placeholder="팀 분위기, 목표 등"></textarea></div>
        <button type="submit" class="btn btn-primary btn-w">게시글 등록</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php /* ── 본인: 내 지원 현황 ─── */
  if ($mySentApps): ?>
  <div class="card mb-3">
    <div class="card-body">
      <h3 style="font-size:15px;font-weight:700;margin-bottom:12px">📋 내 팀 가입 신청 현황</h3>
      <?php foreach($mySentApps as $a):
        $sc = match($a['status']){'accepted'=>'badge-green','rejected'=>'badge-red',default=>'badge-yellow'};
        $sl = match($a['status']){'accepted'=>'수락됨','rejected'=>'거절됨',default=>'대기중'};
      ?>
      <div class="list-item" style="padding:10px 0;border-bottom:1px solid var(--border)">
        <div>
          <div style="font-weight:600"><?=h($a['team_name'])?> <span style="font-size:12px;color:var(--text-sub)"><?=h($a['region'])?></span></div>
          <div style="font-size:12px;color:var(--text-sub)">모집 포지션: <?=h($a['positions_needed'])?></div>
        </div>
        <span class="badge <?=$sc?>"><?=$sl?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($applicants): ?>
  <div class="card mb-3" style="border:1px solid var(--warning)">
    <div class="card-body">
      <h3 style="font-size:15px;font-weight:700;margin-bottom:12px;color:var(--warning)">📬 지원자 목록 (<?=count($applicants)?>명)</h3>
      <?php foreach($applicants as $app): ?>
      <div style="background:var(--bg-surface-alt);border-radius:10px;padding:12px;margin-bottom:10px">
        <div style="display:flex;justify-content:space-between;margin-bottom:6px">
          <div><div style="font-weight:700"><?=h($app['uname'])?></div><div style="font-size:12px;color:var(--text-sub)">🌡 <?=number_format($app['u_manner'],1)?>° · ⚽<?=$app['goals']?>골</div></div>
          <span class="badge badge-gray"><?=h($app['positions_needed'])?></span>
        </div>
        <p style="font-size:13px;margin-bottom:10px;color:var(--text-sub)"><?=h(mb_substr($app['message'],0,80))?></p>
        <div style="display:flex;gap:6px">
          <form method="POST" style="flex:1"><?= csrfInput() ?><input type="hidden" name="action" value="recruit_respond"><input type="hidden" name="app_id" value="<?=$app['id']?>"><input type="hidden" name="status" value="accepted"><button type="submit" class="btn btn-primary btn-w btn-sm">수락</button></form>
          <form method="POST" style="flex:1"><?= csrfInput() ?><input type="hidden" name="action" value="recruit_respond"><input type="hidden" name="app_id" value="<?=$app['id']?>"><input type="hidden" name="status" value="rejected"><button type="submit" class="btn btn-outline btn-w btn-sm">거절</button></form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (empty($posts)): ?>
  <div style="text-align:center;padding:60px 20px;color:var(--text-sub)">
    <div style="font-size:48px;margin-bottom:12px">🛡️</div>
    <p style="font-size:15px;margin-bottom:6px;color:var(--text-main);font-weight:700">팀원 모집 중인 팀이 없어요</p>
    <p style="font-size:13px;margin-bottom:16px;line-height:1.6">팀원이 필요하면 모집글을 올려보세요</p>
    <?php if(me() && myTeamId() && isCaptain()): ?>
      <button onclick="document.getElementById('recruit-form')?.classList.toggle('open')" class="btn btn-primary" style="font-size:14px"><i class="bi bi-plus-circle"></i> 모집글 작성</button>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:0">
  <?php foreach ($posts as $pi => $p): ?>
  <div style="<?=$pi?'border-top:1px solid rgba(255,255,255,0.04)':''?>">
    <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;cursor:pointer" onclick="var d=document.getElementById('recruitD<?=$p['id']?>');d.style.display=d.style.display==='none'?'block':'none'">
      <div style="width:36px;height:36px;border-radius:10px;background:rgba(0,255,136,0.1);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0">🛡️</div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:700;font-size:13px"><?=h(teamDisplayName($p['team_name']))?></div>
        <div style="font-size:10px;color:var(--text-sub)"><?=h($p['t_region'])?> · <?=h($p['positions_needed'])?> <?=$p['recruit_count']?>명 · <?=$p['win']?>승<?=$p['draw']?>무<?=$p['loss']?>패</div>
      </div>
      <div style="text-align:right">
        <div style="font-size:11px;font-weight:700;color:var(--warning)">🌡<?=number_format($p['manner_score'],1)?>°</div>
        <div style="font-size:9px;color:var(--text-sub)">지원 <?=$p['apply_count']?>명</div>
      </div>
    </div>
    <div id="recruitD<?=$p['id']?>" style="display:none;padding:0 12px 10px;background:rgba(255,255,255,0.02)">
      <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:8px">
        <span class="badge badge-green" style="font-size:9px"><?=h($p['level_required'])?></span>
        <span class="badge badge-gray" style="font-size:9px"><?=h($p['team_style'])?></span>
        <?php if($p['play_time']): ?><span class="badge badge-gray" style="font-size:9px">⏰ <?=h($p['play_time'])?></span><?php endif; ?>
        <span class="badge badge-gray" style="font-size:9px">💰 <?=h($p['membership_fee'])?></span>
        <?php if($p['age_range']&&$p['age_range']!=='무관'): ?><span class="badge badge-gray" style="font-size:9px">👤 <?=h($p['age_range'])?></span><?php endif; ?>
      </div>
      <?php if($p['intro']): ?>
      <div style="font-size:12px;color:var(--text);line-height:1.7;margin-bottom:10px;padding:10px;background:rgba(0,255,136,0.03);border-radius:8px;border-left:3px solid rgba(0,255,136,0.2);white-space:pre-line"><?=h($p['intro'])?></div>
      <?php else: ?>
      <p style="font-size:12px;color:var(--text-sub);margin-bottom:8px;font-style:italic">팀 소개가 없습니다.</p>
      <?php endif; ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <?php if(me() && !myTeamId()): ?>
        <button onclick="document.getElementById('apply-<?=$p['id']?>').classList.toggle('open')" class="btn btn-primary btn-sm" style="flex:1">지원하기</button>
        <?php endif; ?>
        <?php if(isCaptain() && (int)$p['team_id'] === myTeamId()): ?>
        <button onclick="document.getElementById('editRecruit<?=$p['id']?>').style.display=document.getElementById('editRecruit<?=$p['id']?>').style.display==='none'?'block':'none'" class="btn btn-outline btn-sm" style="flex:1"><i class="bi bi-pencil"></i> 수정</button>
        <?php endif; ?>
        <?php if(isAnyAdmin()): ?>
        <button onclick="document.getElementById('editRecruit<?=$p['id']?>').style.display=document.getElementById('editRecruit<?=$p['id']?>').style.display==='none'?'block':'none'" class="btn btn-outline btn-sm" style="font-size:10px"><i class="bi bi-gear"></i> 관리</button>
        <?php endif; ?>
      </div>
      <?php if(me() && !myTeamId()): ?>
      <div id="apply-<?=$p['id']?>" class="tf-collapse" style="margin-top:8px">
        <form method="POST"><?=csrfInput()?><input type="hidden" name="action" value="recruit_apply"><input type="hidden" name="post_id" value="<?=$p['id']?>">
          <textarea name="message" class="form-control" rows="2" placeholder="자기소개 (선택사항)" style="margin-bottom:6px;font-size:12px"></textarea>
          <button type="submit" class="btn btn-primary btn-w btn-sm">지원서 제출</button>
        </form>
      </div>
      <?php endif; ?>
      <!-- 수정 폼 (캡틴/관리자) -->
      <?php if((isCaptain() && (int)$p['team_id'] === myTeamId()) || isAnyAdmin()): ?>
      <div id="editRecruit<?=$p['id']?>" style="display:none;margin-top:8px;padding:10px;background:rgba(255,255,255,0.03);border-radius:8px">
        <form method="POST">
          <?=csrfInput()?>
          <input type="hidden" name="action" value="update_recruit_post">
          <input type="hidden" name="post_id" value="<?=$p['id']?>">
          <div class="form-row" style="margin-bottom:6px">
            <div class="form-group"><label class="form-label" style="font-size:11px">모집 포지션</label>
              <select name="positions_needed" class="form-control" style="font-size:12px"><?php foreach(['무관','GK','DF','MF','FW'] as $rp): ?><option <?=$p['positions_needed']===$rp?'selected':''?>><?=$rp?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label" style="font-size:11px">모집 인원</label>
              <input type="number" name="recruit_count" class="form-control" style="font-size:12px" value="<?=(int)$p['recruit_count']?>" min="1"></div>
          </div>
          <div class="form-group" style="margin-bottom:6px"><label class="form-label" style="font-size:11px">팀 소개</label>
            <textarea name="intro" class="form-control" rows="3" style="font-size:12px"><?=h($p['intro']??'')?></textarea></div>
          <div class="form-row" style="margin-bottom:6px">
            <div class="form-group"><label class="form-label" style="font-size:11px">레벨</label>
              <select name="level_required" class="form-control" style="font-size:12px"><?php foreach(['무관','입문','초급','중급','고급'] as $rl): ?><option <?=$p['level_required']===$rl?'selected':''?>><?=$rl?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label" style="font-size:11px">상태</label>
              <select name="status" class="form-control" style="font-size:12px"><option value="open" <?=$p['status']==='open'?'selected':''?>>모집중</option><option value="closed" <?=$p['status']==='closed'?'selected':''?>>마감</option></select></div>
          </div>
          <button type="submit" class="btn btn-primary btn-w btn-sm">저장</button>
        </form>
      </div>
      <?php endif; ?>
      <div style="font-size:10px;color:var(--text-sub);margin-top:6px"><?=date('m/d',strtotime($p['created_at']))?> 등록</div>
    </div>
  </div>
  <?php endforeach; ?>
  </div></div>
  <?php endif; ?>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 팀 생성
// ═══════════════════════════════════════════════════════════════
function pagTeamCreate(PDO $pdo): void {
    if (myTeamId()) { redirect('?page=team'); } ?>
<div class="container">
  <p class="section-title" style="margin-top:4px"><i class="bi bi-shield-plus"></i> 새 팀 만들기</p>
  <div class="card"><div class="card-body">
    <form method="POST">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="create_team">
      <div class="form-group"><label class="form-label">팀 이름 <span style="color:var(--danger)">*</span></label>
        <input type="text" name="name" class="form-control" placeholder="예: 강남 낭만 FC" required maxlength="30"></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">지역 <span style="color:var(--danger)">*</span></label>
          <input type="text" name="region" class="form-control" placeholder="서울" required></div>
        <div class="form-group"><label class="form-label">구/군</label>
          <input type="text" name="district" class="form-control" placeholder="강남구"></div>
      </div>
      <div class="form-group"><label class="form-label">팀 소개</label>
        <textarea name="intro" class="form-control" rows="3" placeholder="팀 스타일, 활동 시간 등"></textarea></div>
      <button type="submit" class="btn btn-primary btn-w" style="margin-top:4px"><i class="bi bi-shield-check"></i> 팀 생성하기</button>
    </form>
  </div></div>
  <div class="card" style="margin-top:12px;border:1px solid rgba(255,184,0,0.2)"><div class="card-body">
    <p style="font-size:13px;color:var(--warning);font-weight:600;margin-bottom:8px"><i class="bi bi-info-circle"></i> 팀 활성화 안내</p>
    <p style="font-size:13px;color:var(--text-sub);line-height:1.7">팀 생성 후 <strong style="color:var(--text)">초대코드</strong>를 팀원에게 공유하세요.<br>
    팀원이 <strong style="color:var(--text)">3명 이상</strong> 모이면 팀이 자동으로 <span style="color:var(--primary)">ACTIVE</span> 상태로 전환됩니다.</p>
  </div></div>
  <div style="text-align:center;margin-top:16px">
    <a href="?page=team_join" style="color:var(--text-sub);font-size:14px">이미 초대코드가 있나요? <span style="color:var(--primary)">팀 가입하기</span></a>
  </div>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 팀 가입 (초대코드)
// ═══════════════════════════════════════════════════════════════
function pagTeamJoin(PDO $pdo): void {
    // [버그 수정] redirect()는 renderHeader 이후라 header() 실패함 → JS로 이동
    if (myTeamId()) {
        echo '<div class="container" style="text-align:center;padding:60px 20px"><p style="color:var(--text-sub)">이미 팀에 소속되어 있습니다.</p><a href="?page=team" class="btn btn-primary" style="margin-top:10px">내 팀으로 이동</a></div>';
        echo '<script>setTimeout(function(){location.href="?page=team"},1500)</script>';
        return;
    }
    // [팀 가입 승인] 내가 이미 신청한 팀이 있으면 상단에 표시
    $myPending = null;
    if (me()) {
        $pq = $pdo->prepare("
          SELECT tm.team_id, tm.created_at, t.name, t.invite_code
          FROM team_members tm JOIN teams t ON t.id=tm.team_id
          WHERE tm.user_id=? AND tm.status='pending'
          ORDER BY tm.created_at DESC LIMIT 1
        ");
        $pq->execute([me()['id']]);
        $myPending = $pq->fetch() ?: null;
    }
    $code    = strtoupper(trim($_GET['code'] ?? ''));
    $preview = null;
    if ($code) {
        $stmt = $pdo->prepare("
            SELECT t.id, t.name, t.region, t.district, t.status, t.trust_score,
                   u.name AS leader_name,
                   (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id=t.id AND tm.status='active') AS member_count
            FROM teams t LEFT JOIN users u ON u.id=t.leader_id
            WHERE t.invite_code=?
        ");
        $stmt->execute([$code]); $preview = $stmt->fetch();
    } ?>
<div class="container">
  <?php if ($myPending): ?>
  <div class="card" style="margin-bottom:14px;border:1px solid rgba(255,149,0,0.35);background:rgba(255,149,0,0.05)">
    <div class="card-body" style="display:flex;align-items:center;gap:12px">
      <div style="font-size:26px">⏳</div>
      <div style="flex:1;min-width:0">
        <div style="font-size:13px;font-weight:700;color:#ff9500"><?=h($myPending['name'])?> 팀 가입 승인 대기 중</div>
        <div style="font-size:11px;color:var(--text-sub);margin-top:2px"><?=timeAgo($myPending['created_at'])?> 신청 · 캡틴이 수락하면 자동으로 팀원이 됩니다</div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <p class="section-title" style="margin-top:4px"><i class="bi bi-key"></i> 초대코드로 팀 가입</p>
  <div class="card"><div class="card-body">
    <form method="GET" action="">
      <input type="hidden" name="page" value="team_join">
      <div class="form-group"><label class="form-label">초대코드 6자리</label>
        <input type="text" name="code" class="form-control" placeholder="예: AB12CD" value="<?=h($code)?>"
               maxlength="6" style="text-transform:uppercase;letter-spacing:4px;font-size:20px;font-weight:700;text-align:center" required></div>
      <button type="submit" class="btn btn-outline btn-w">팀 미리보기</button>
    </form>
  </div></div>

  <?php if ($code && !$preview): ?>
  <div style="text-align:center;padding:20px;color:var(--danger)"><i class="bi bi-x-circle"></i> 유효하지 않은 초대코드입니다.</div>
  <?php elseif ($preview): ?>
  <div class="card" style="margin-top:12px;border:1px solid rgba(0,255,136,0.2)"><div class="card-body">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px">
      <div>
        <div style="font-size:18px;font-weight:700"><?=h($preview['name'])?></div>
        <div style="font-size:13px;color:var(--text-sub)"><?=h($preview['region'])?> <?=h($preview['district']??'')?> · 리더: <?=h($preview['leader_name']??'-')?></div>
      </div>
      <span class="badge <?=$preview['status']==='ACTIVE'?'badge-green':'badge-yellow'?>"><?=$preview['status']?></span>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
      <div style="text-align:center"><div style="font-size:22px;font-weight:700"><?=$preview['member_count']?></div><div style="font-size:12px;color:var(--text-sub)">현재 팀원</div></div>
      <div style="text-align:center"><div style="font-size:22px;font-weight:700;color:var(--primary)"><?=$preview['trust_score']?></div><div style="font-size:12px;color:var(--text-sub)">트러스트 점수</div></div>
    </div>
    <?php if ($preview['member_count'] < 3 && $preview['status'] === 'PENDING'): ?>
    <div style="padding:10px;background:rgba(255,184,0,0.1);border-radius:10px;font-size:13px;color:var(--warning);margin-bottom:12px">
      <i class="bi bi-people"></i> 팀원 <?=3-$preview['member_count']?>명 더 모이면 팀이 활성화됩니다!
    </div>
    <?php endif; ?>
    <form method="POST">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="join_team_code">
      <input type="hidden" name="invite_code" value="<?=h($code)?>">
      <button type="submit" class="btn btn-primary btn-w"><i class="bi bi-person-plus"></i> 이 팀에 가입 신청</button>
      <div style="font-size:11px;color:var(--text-sub);text-align:center;margin-top:6px">⏳ 캡틴 수락 후 팀원이 됩니다</div>
    </form>
  </div></div>
  <?php endif; ?>

  <div style="text-align:center;margin-top:16px">
    <a href="?page=team_create" style="color:var(--text-sub);font-size:14px">초대코드가 없으신가요? <span style="color:var(--primary)">새 팀 만들기</span></a>
  </div>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 경기장 인증
// ═══════════════════════════════════════════════════════════════
function pagVenueVerify(PDO $pdo): void {
    $matchId = (int)($_GET['match_id'] ?? 0);
    $match   = null;
    if ($matchId) {
        $s = $pdo->prepare("SELECT id,title,location,match_date FROM matches WHERE id=?");
        $s->execute([$matchId]); $match = $s->fetch();
    }
    // 이 경기의 기존 인증 요청
    $existing = null;
    if ($matchId) {
        $s = $pdo->prepare("SELECT * FROM venue_verifications WHERE match_id=? AND submitted_by=? LIMIT 1");
        $s->execute([$matchId,me()['id']]); $existing = $s->fetch();
    } ?>
<div class="container">
  <p class="section-title" style="margin-top:4px"><i class="bi bi-patch-check"></i> 경기장 대관 인증</p>
  <?php if ($existing): ?>
  <div class="card" style="margin-bottom:12px;border:1px solid rgba(0,255,136,0.2)"><div class="card-body" style="text-align:center;padding:24px">
    <div style="font-size:36px;margin-bottom:8px"><?=$existing['status']==='VERIFIED'?'✅':($existing['status']==='REJECTED'?'❌':'⏳')?></div>
    <div style="font-weight:700;font-size:16px;margin-bottom:4px">
      <?=$existing['status']==='VERIFIED'?'인증 완료!':($existing['status']==='REJECTED'?'인증 거절됨':'관리자 검토 중')?>
    </div>
    <div style="font-size:13px;color:var(--text-sub)"><?=date('Y-m-d H:i',strtotime($existing['created_at']??' '))?> 제출</div>
    <?php if(!empty($existing['receipt_image_url'])): ?>
    <div style="margin-top:12px">
      <img src="/<?=h($existing['receipt_image_url'])?>" style="max-width:100%;border-radius:10px;border:1px solid var(--border)" alt="제출된 영수증">
    </div>
    <?php endif; ?>
  </div></div>
  <?php else: ?>
  <div class="card"><div class="card-body">
    <?php if ($match): ?>
    <div style="margin-bottom:12px;padding:10px;background:var(--bg-surface-alt);border-radius:10px;font-size:14px">
      <i class="bi bi-calendar3"></i> <?=h($match['title']?:'매치')?> · <?=$match['match_date']?><br>
      <i class="bi bi-geo-alt"></i> <?=h($match['location']??'-')?>
    </div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="submit_venue">
      <?php if (!$matchId): ?>
      <div class="form-group"><label class="form-label">매치 ID</label>
        <input type="number" name="match_id" class="form-control" required></div>
      <?php else: ?>
      <input type="hidden" name="match_id" value="<?=$matchId?>">
      <?php endif; ?>
      <div class="form-group">
        <label class="form-label">영수증 이미지 <span style="color:var(--text-sub);font-size:12px">(JPG/PNG, 최대 5MB)</span></label>
        <label for="receiptFile" style="display:flex;flex-direction:column;align-items:center;gap:8px;padding:24px;border:2px dashed var(--border);border-radius:12px;cursor:pointer;transition:.2s" id="receiptLabel">
          <i class="bi bi-cloud-upload" style="font-size:32px;color:var(--text-sub)"></i>
          <span style="font-size:14px;color:var(--text-sub)" id="receiptText">사진을 탭하여 선택하세요</span>
          <input type="file" id="receiptFile" name="receipt_image" accept="image/jpeg,image/png" required style="display:none">
        </label>
      </div>
      <button type="submit" class="btn btn-primary btn-w"><i class="bi bi-upload"></i> 인증 요청</button>
    </form>
    <script>
    document.getElementById('receiptFile').addEventListener('change',function(){
      const label=document.getElementById('receiptLabel');
      const text=document.getElementById('receiptText');
      if(this.files[0]){
        text.textContent=this.files[0].name+' ('+Math.round(this.files[0].size/1024)+'KB)';
        label.style.borderColor='var(--primary)';
        label.style.background='rgba(0,255,136,0.05)';
      }
    });
    </script>
  </div></div>
  <?php endif; ?>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 약관 동의 (재동의 포함)
// ═══════════════════════════════════════════════════════════════
function pagAgreements(PDO $pdo): void {
    $typeLabels = ['TOS'=>'이용약관','PRIVACY'=>'개인정보처리방침','LOCATION'=>'위치기반서비스','MARKETING'=>'마케팅 정보 수신(선택)'];
    $actives    = $pdo->query("SELECT agreement_type,version,is_required,content_url FROM agreement_versions WHERE is_active=1 ORDER BY FIELD(agreement_type,'TOS','PRIVACY','LOCATION','MARKETING')")->fetchAll();
    $agreed     = $pdo->prepare("SELECT agreement_type,version FROM user_agreements WHERE user_id=?");
    $agreed->execute([me()['id']]);
    $agreedMap  = [];
    foreach ($agreed->fetchAll() as $a) $agreedMap[$a['agreement_type']] = $a['version'];
    $isReagree  = $_SESSION['requires_reagreement'] ?? false; ?>
<div class="container">
  <div style="text-align:center;padding:24px 0 16px">
    <div style="font-size:36px;margin-bottom:8px">📋</div>
    <div style="font-size:18px;font-weight:700;margin-bottom:4px"><?=$isReagree?'약관이 업데이트되었습니다':'서비스 이용 약관'?></div>
    <div style="font-size:13px;color:var(--text-sub)"><?=$isReagree?'변경된 약관에 동의 후 계속 이용하실 수 있습니다':'계속하려면 아래 약관에 동의해주세요'?></div>
  </div>
  <div class="card"><div class="card-body">
    <form method="POST">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="save_agreements">
      <label style="display:flex;align-items:center;gap:10px;margin-bottom:12px;cursor:pointer;font-weight:700;padding:10px;background:var(--bg-surface-alt);border-radius:10px">
        <input type="checkbox" id="agreeAll2" style="width:18px;height:18px">
        <span>전체 동의하기</span>
      </label>
      <div style="height:1px;background:var(--border);margin-bottom:12px"></div>
      <?php foreach ($actives as $ag):
        $label    = $typeLabels[$ag['agreement_type']] ?? $ag['agreement_type'];
        $isReq    = $ag['is_required'];
        $alreadyOk= ($agreedMap[$ag['agreement_type']] ?? '') === $ag['version']; ?>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
        <input type="checkbox" name="agree_<?=h($ag['agreement_type'])?>" value="1"
               class="agree-check2" id="ag_<?=$ag['agreement_type']?>"
               style="width:16px;height:16px" <?=$alreadyOk?'checked':''?>>
        <label for="ag_<?=$ag['agreement_type']?>" style="flex:1;cursor:pointer;font-size:14px">
          <?=h($label)?>
          <?=$isReq?'<span style="color:var(--danger);font-size:11px"> 필수</span>':'<span style="color:var(--text-sub);font-size:11px"> 선택</span>'?>
          <?php if(!$alreadyOk): ?><span class="badge badge-yellow" style="font-size:10px;margin-left:4px">업데이트</span><?php endif; ?>
        </label>
        <a href="<?=h($ag['content_url'])?>" target="_blank" style="font-size:12px;color:var(--text-sub)">전문</a>
      </div>
      <?php endforeach; ?>
      <button type="submit" class="btn btn-primary btn-w" style="margin-top:8px">동의하고 계속하기</button>
    </form>
  </div></div>
</div>
<script>
const agA=document.getElementById('agreeAll2');
const cksA=document.querySelectorAll('.agree-check2');
agA.addEventListener('change',()=>cksA.forEach(c=>c.checked=agA.checked));
cksA.forEach(c=>c.addEventListener('change',()=>{agA.checked=[...cksA].every(x=>x.checked);}));
</script>
<?php }

// ═══════════════════════════════════════════════════════════════
// 비밀번호 찾기
// ═══════════════════════════════════════════════════════════════
function pagForgotPassword(): void {
    $step = (int)($_GET['step'] ?? 1);
    if ($step === 2 && empty($_SESSION['reset_user_id'])) { $step = 1; }
    if ($step === 3 && empty($_SESSION['otp_verified']))  { $step = 1; }
    $stepLabels = [1=>'가입 시 이름과 전화번호를 입력하세요', 2=>'인증번호를 입력하세요', 3=>'새 비밀번호를 설정하세요'];
    ?>
<div class="login-wrap">
  <div class="login-logo">🔑 비밀번호 찾기</div>
  <div class="login-slogan"><?=$stepLabels[$step] ?? $stepLabels[1]?></div>
  <!-- 단계 표시 -->
  <div style="display:flex;justify-content:center;gap:8px;margin-bottom:16px">
    <?php for($i=1;$i<=3;$i++): $active=$i<=$step; ?>
    <div style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;
      background:<?=$active?'var(--primary)':'var(--bg-surface-alt)'?>;color:<?=$active?'#000':'var(--text-sub)'?>">
      <?=$i?>
    </div>
    <?php if($i<3): ?><div style="flex:0 0 20px;height:2px;margin-top:13px;background:<?=$i<$step?'var(--primary)':'var(--border)'?>"></div><?php endif; ?>
    <?php endfor; ?>
  </div>
  <div class="card login-card"><div class="card-body">

  <?php if ($step === 1): ?>
    <form method="POST" action="?page=forgot_password">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="verify_reset">
      <div class="form-group">
        <label class="form-label">이름</label>
        <input type="text" name="name" class="form-control" required autofocus>
      </div>
      <div class="form-group">
        <label class="form-label">전화번호</label>
        <input type="tel" name="phone" class="form-control phone-input" placeholder="010-0000-0000" maxlength="13" required>
      </div>
      <button type="submit" class="btn btn-primary btn-w">📱 인증번호 받기</button>
    </form>

  <?php elseif ($step === 2): ?>
    <form method="POST" action="?page=forgot_password&step=2">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="verify_otp">
      <div style="text-align:center;margin-bottom:16px">
        <div style="font-size:13px;color:var(--text-sub)">
          <?= h($_SESSION['reset_phone'] ?? '') ?> 으로 발송된<br>6자리 인증번호를 입력하세요
        </div>
        <div style="font-size:11px;color:var(--danger);margin-top:4px">⏱ 5분 내 입력해주세요</div>
      </div>
      <div class="form-group">
        <input type="text" name="otp" class="form-control" placeholder="000000" maxlength="6"
               style="text-align:center;font-size:24px;letter-spacing:8px;font-weight:700" required autofocus
               inputmode="numeric" pattern="[0-9]{6}">
      </div>
      <button type="submit" class="btn btn-primary btn-w">인증번호 확인</button>
    </form>
    <form method="POST" action="?page=forgot_password" style="margin-top:8px">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="verify_reset">
      <input type="hidden" name="name" value="">
      <input type="hidden" name="phone" value="<?=h($_SESSION['reset_phone'] ?? '')?>">
      <button type="button" class="btn btn-outline btn-w" style="font-size:12px"
              onclick="flash('처음부터 다시 시도해주세요.','info');location.href='?page=forgot_password'">인증번호 재발송</button>
    </form>

  <?php else: ?>
    <form method="POST" action="?page=forgot_password&step=3">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="reset_password">
      <div style="text-align:center;margin-bottom:12px">
        <span style="background:var(--primary);color:#000;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700">✓ 인증 완료</span>
      </div>
      <div class="form-group">
        <label class="form-label">새 비밀번호 <span style="font-size:12px;color:var(--text-sub)">(6자 이상)</span></label>
        <input type="password" name="password" id="pw1" class="form-control" required minlength="6" autofocus>
      </div>
      <div class="form-group">
        <label class="form-label">새 비밀번호 확인</label>
        <input type="password" name="password2" id="pw2" class="form-control" required minlength="6">
        <div id="pwMatch" style="font-size:12px;margin-top:4px;display:none"></div>
      </div>
      <button type="submit" class="btn btn-primary btn-w">비밀번호 변경</button>
    </form>
    <script>
    const pw1=document.getElementById('pw1'),pw2=document.getElementById('pw2'),msg=document.getElementById('pwMatch');
    [pw1,pw2].forEach(el=>el.addEventListener('input',()=>{
      if(!pw2.value)return;
      msg.style.display='block';
      if(pw1.value===pw2.value){msg.textContent='✓ 비밀번호가 일치합니다';msg.style.color='var(--primary)';}
      else{msg.textContent='✗ 비밀번호가 일치하지 않습니다';msg.style.color='var(--danger)';}
    }));
    </script>
  <?php endif; ?>

  <hr class="divider">
  <a href="?page=login" class="btn btn-outline btn-w">로그인으로 돌아가기</a>
  </div></div>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 카카오 OAuth 콜백
// ═══════════════════════════════════════════════════════════════
function pagKakaoCallback(PDO $pdo): void {
    $code  = $_GET['code'] ?? '';
    $error = $_GET['error'] ?? '';
    if ($error || !$code) {
        flash('카카오 로그인이 취소되었습니다.', 'error');
        redirect('?page=login');
    }

    // 1) Authorization Code → Access Token
    $ch = curl_init('https://kauth.kakao.com/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type'   => 'authorization_code',
            'client_id'    => KAKAO_REST_KEY,
            'redirect_uri' => KAKAO_REDIRECT,
            'code'         => $code,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $tokenRes = json_decode(curl_exec($ch), true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $accessToken = $tokenRes['access_token'] ?? null;
    if (!$accessToken) {
        flash('카카오 인증 토큰 교환에 실패했습니다. 다시 시도해주세요.', 'error');
        redirect('?page=login');
    }

    // 2) Access Token → 카카오 유저 정보
    $ch = curl_init('https://kapi.kakao.com/v2/user/me');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $userRes = json_decode(curl_exec($ch), true);
    curl_close($ch);
    $kakaoId    = (string)($userRes['id'] ?? '');
    $nickname   = $userRes['kakao_account']['profile']['nickname'] ?? ('카카오' . substr($kakaoId, -4));
    $profileImg = $userRes['kakao_account']['profile']['profile_image_url'] ?? null;
    $email      = $userRes['kakao_account']['email'] ?? null;
    if (!$kakaoId) {
        flash('카카오 유저 정보를 가져올 수 없습니다.', 'error');
        redirect('?page=login');
    }

    // 3) 기존 유저 확인 (kakao_id 매칭)
    $stmt = $pdo->prepare("SELECT * FROM users WHERE kakao_id = ?");
    $stmt->execute([$kakaoId]);
    $user = $stmt->fetch();

    if ($user) {
        // 기존 유저 → 바로 로그인
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        $pdo->prepare("UPDATE users SET last_login_at=NOW() WHERE id=?")->execute([$user['id']]);
        flash('카카오로 로그인 성공!');
        redirect($_SESSION['post_login_url'] ?? '?page=home');
    }

    // 4) 신규 유저 → 세션에 카카오 정보 저장 후 회원가입 페이지로
    $_SESSION['pending_kakao'] = [
        'kakao_id'          => $kakaoId,
        'nickname'          => $nickname,
        'email'             => $email,
        'profile_image_url' => $profileImg,
    ];
    flash('카카오 계정이 확인되었습니다! 전화번호를 등록해주세요.', 'info');
    redirect('?page=register&source=kakao');
}

function pagMessages(PDO $pdo): void {
    $me = me()['id'];
    // 같은 팀 구성원 (DM 빠른 시작용)
    $tmStmt = $pdo->prepare("
      SELECT u.id, u.name, u.nickname, u.profile_image_url, u.position FROM team_members tm
      JOIN users u ON u.id=tm.user_id
      WHERE tm.team_id=(SELECT team_id FROM team_members WHERE user_id=? AND status='active' LIMIT 1)
        AND tm.status='active' AND u.id!=?
      ORDER BY u.name LIMIT 20
    ");
    $tmStmt->execute([$me, $me]);
    $teamMatesForDm = $tmStmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT c.id, c.type, c.match_id, c.team_id,
               (SELECT m.message FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_msg,
               (SELECT m.created_at FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_at,
               (SELECT COUNT(*) FROM messages m2
                WHERE m2.conversation_id=c.id
                  AND m2.created_at > COALESCE(cp.last_read_at,'2000-01-01')
                  AND m2.sender_id != ?
               ) AS unread,
               GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM(u.nickname),''), u.name) ORDER BY u.id SEPARATOR ', ') AS other_names
        FROM conversations c
        JOIN conversation_participants cp  ON cp.conversation_id=c.id AND cp.user_id=?
        JOIN conversation_participants cp2 ON cp2.conversation_id=c.id
        JOIN users u ON u.id=cp2.user_id AND u.id != ?
        GROUP BY c.id, cp.last_read_at
        ORDER BY last_at DESC
    ");
    $stmt->execute([$me, $me, $me]);
    $convs = $stmt->fetchAll(); ?>
<div class="container">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <h2 style="font-size:18px;font-weight:700">메시지</h2>
    <a href="?page=friends" class="btn btn-outline btn-sm"><i class="bi bi-people"></i> 친구</a>
  </div>

  <?php if($teamMatesForDm): ?>
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:12px 14px">
    <div style="font-size:12px;color:var(--text-sub);margin-bottom:8px;font-weight:600">팀원에게 빠른 DM</div>
    <div style="display:flex;gap:8px;overflow-x:auto;padding-bottom:4px">
      <?php foreach($teamMatesForDm as $tmU): ?>
      <form method="POST" style="flex-shrink:0">
        <?=csrfInput()?>
        <input type="hidden" name="action" value="start_chat">
        <input type="hidden" name="target_user_id" value="<?=(int)$tmU['id']?>">
        <button type="submit" style="background:var(--bg-surface-alt);border:1px solid var(--border);border-radius:20px;padding:8px 12px;cursor:pointer;display:flex;align-items:center;gap:6px;color:var(--text);white-space:nowrap">
          <?php $tmDn = displayName($tmU); ?>
          <?= renderAvatar($tmU, 24) ?>
          <span style="font-size:13px;font-weight:600"><?=h($tmDn)?></span>
        </button>
      </form>
      <?php endforeach; ?>
    </div>
  </div></div>
  <?php endif; ?>

  <?php if (!$convs): ?>
    <div style="text-align:center;padding:40px 0;color:var(--text-sub)">
      <div style="font-size:40px;margin-bottom:12px">💬</div>
      <div>아직 대화방이 없습니다.</div>
      <div style="font-size:13px;margin-top:8px">위의 팀원을 클릭하거나 매치 상세에서 "대화하며 신청하기"를 눌러보세요.</div>
    </div>
  <?php else: foreach($convs as $c):
    $unread = (int)$c['unread'];
    $typeLabel = match($c['type']) { 'MATCH' => '매치', 'TEAM' => '팀', default => '' };
    $title = $c['other_names'] ?: '대화방';
    $lastAt = $c['last_at'] ? date('m/d H:i', strtotime($c['last_at'])) : '';
  ?>
  <a href="?page=chat&conv_id=<?=$c['id']?>" style="text-decoration:none">
    <div class="card card-link" style="margin-bottom:8px">
      <div class="card-body" style="display:flex;align-items:center;gap:12px">
        <div style="width:44px;height:44px;border-radius:50%;background:var(--primary-glow);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">
          <?=match($c['type']){'MATCH'=>'⚽','TEAM'=>'🛡️',default=>'💬'}?>
        </div>
        <div style="flex:1;min-width:0">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
            <div style="font-weight:600;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px">
              <?php if($typeLabel): ?><span class="badge badge-blue" style="font-size:10px;padding:2px 6px;margin-right:4px"><?=$typeLabel?></span><?php endif; ?>
              <?=h($title)?>
            </div>
            <div style="font-size:11px;color:var(--text-sub);flex-shrink:0"><?=$lastAt?></div>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div style="font-size:13px;color:var(--text-sub);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%">
              <?=h(mb_substr($c['last_msg'] ?? '메시지가 없습니다.', 0, 40))?>
            </div>
            <?php if($unread > 0): ?>
            <div style="background:var(--danger);color:#fff;border-radius:999px;min-width:20px;height:20px;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 5px;flex-shrink:0">
              <?=$unread?>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </a>
  <?php endforeach; endif; ?>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 채팅방 페이지
// ═══════════════════════════════════════════════════════════════
function pagChat(PDO $pdo): void {
    $convId = (int)($_GET['conv_id'] ?? 0);
    $me = me()['id'];
    if (!$convId) { redirect('?page=messages'); }

    // 참가자 확인
    $mem = $pdo->prepare("SELECT id FROM conversation_participants WHERE conversation_id=? AND user_id=?");
    $mem->execute([$convId, $me]);
    if (!$mem->fetch()) { flash('접근 권한이 없습니다.', 'error'); redirect('?page=messages'); }

    // 대화방 정보
    $conv = $pdo->prepare("SELECT c.*, ma.match_date, ma.title AS match_title FROM conversations c LEFT JOIN matches ma ON ma.id=c.match_id WHERE c.id=?");
    $conv->execute([$convId]); $conv = $conv->fetch();

    // 상대방 정보 (DIRECT)
    $other = null;
    if ($conv['type'] === 'DIRECT') {
        $o = $pdo->prepare("SELECT u.id, u.name, u.nickname, u.position, u.manner_score FROM conversation_participants cp JOIN users u ON u.id=cp.user_id WHERE cp.conversation_id=? AND cp.user_id!=?");
        $o->execute([$convId, $me]); $other = $o->fetch();
    }

    // 메시지 조회
    $stmt = $pdo->prepare("
        SELECT m.id, m.sender_id, COALESCE(NULLIF(TRIM(u.nickname),''), u.name) AS sender_name, m.message, m.msg_type, m.extra_data, m.created_at
        FROM messages m LEFT JOIN users u ON u.id=m.sender_id
        WHERE m.conversation_id=?
        ORDER BY m.id ASC LIMIT 100
    ");
    $stmt->execute([$convId]); $msgs = $stmt->fetchAll();

    // [5단계] 채팅방 상단 고정 매치 카드 (MATCH 타입일 때)
    $stickyMatch = null;
    $stickyAtt   = null;
    $myStickyStatus = null;
    if ($conv['type']==='MATCH' && $conv['match_id']) {
        $sm = $pdo->prepare("SELECT id, title, location, match_date, match_time, max_players, status, home_team_id, away_team_id FROM matches WHERE id=?");
        $sm->execute([$conv['match_id']]); $stickyMatch = $sm->fetch();
        if ($stickyMatch) {
            $att = $pdo->prepare("SELECT
                SUM(status='ATTEND') AS att,
                SUM(status='ABSENT') AS abs,
                SUM(status='PENDING') AS pend
                FROM match_attendance WHERE match_id=?");
            $att->execute([$conv['match_id']]); $stickyAtt = $att->fetch();
            $mine = $pdo->prepare("SELECT status FROM match_attendance WHERE match_id=? AND user_id=? LIMIT 1");
            $mine->execute([$conv['match_id'], $me]); $myStickyStatus = $mine->fetchColumn() ?: 'PENDING';
        }
    }

    // 읽음 처리
    $pdo->prepare("UPDATE conversation_participants SET last_read_at=NOW() WHERE conversation_id=? AND user_id=?")
        ->execute([$convId, $me]);

    // 매치 대화방 - 매치 신청 가능 여부
    $canApply = false;
    if ($conv['type'] === 'MATCH' && $conv['match_id'] && myTeamId()) {
        $m = $pdo->prepare("SELECT status, home_team_id, away_team_id FROM matches WHERE id=?");
        $m->execute([$conv['match_id']]); $m = $m->fetch();
        $canApply = $m && $m['status'] === 'open' && myTeamId() != $m['home_team_id'] && myTeamId() != $m['away_team_id'];
    }
    ?>
<div style="display:flex;flex-direction:column;height:calc(100vh - 60px - 80px)">
  <!-- 채팅 헤더 -->
  <div style="background:var(--bg-surface);border-bottom:1px solid var(--border);padding:12px 16px;display:flex;align-items:center;gap:10px">
    <?php $backUrl = ($conv['type']==='MATCH' && !empty($conv['match_id'])) ? '?page=match&id='.(int)$conv['match_id'] : '?page=messages'; ?>
    <a href="<?=$backUrl?>" onclick="if(history.length>1){history.back();return false;}" style="color:var(--text-sub);text-decoration:none"><i class="bi bi-arrow-left" style="font-size:18px"></i></a>
    <div style="flex:1">
      <?php if($other): ?>
        <div style="font-weight:700"><?=h(displayName($other))?></div>
        <div style="font-size:11px;color:var(--text-sub)"><?=h($other['position'] ?? '')?>
          &nbsp;· 매너 <?=number_format((float)$other['manner_score'],1)?>
        </div>
      <?php elseif($conv['type']==='MATCH'): ?>
        <div style="font-weight:700"><i class="bi bi-lightning-fill" style="color:var(--primary)"></i> 매치 대화방</div>
        <div style="font-size:11px;color:var(--text-sub)"><?=h($conv['match_title'] ?? '매치 #'.$conv['match_id'])?></div>
      <?php else: ?>
        <div style="font-weight:700">팀 채팅</div>
      <?php endif; ?>
    </div>
    <?php if($other): ?>
    <a href="?page=mypage&user_id=<?=$other['id']?>" style="color:var(--text-sub);text-decoration:none"><i class="bi bi-person-circle" style="font-size:20px"></i></a>
    <?php endif; ?>
  </div>

  <?php if($canApply): ?>
  <!-- 매치 신청 배너 -->
  <div style="background:rgba(0,255,136,0.08);border-bottom:1px solid rgba(0,255,136,0.2);padding:10px 16px;display:flex;justify-content:space-between;align-items:center">
    <div style="font-size:13px;color:var(--primary)"><i class="bi bi-lightning-fill"></i> 대화 후 매치를 신청하세요!</div>
    <form method="POST">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="apply_match">
      <input type="hidden" name="match_id" value="<?=$conv['match_id']?>">
      <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send"></i> 매치 신청</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if($stickyMatch):
    $totalAtt = (int)$stickyAtt['att'] + (int)$stickyAtt['abs'] + (int)$stickyAtt['pend'];
    $maxP = (int)$stickyMatch['max_players'];
    $progress = $maxP > 0 ? min(100, (int)$stickyAtt['att'] / $maxP * 100) : 0;
  ?>
  <!-- [5단계] 채팅방 상단 고정 매치 정보 + 인라인 출석 버튼 -->
  <div style="background:linear-gradient(135deg, rgba(0,255,136,0.08), rgba(0,200,100,0.04));border-bottom:1px solid rgba(0,255,136,0.2);padding:12px 16px">
    <a href="?page=match&id=<?=$stickyMatch['id']?>" style="text-decoration:none;color:inherit;display:block">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;gap:8px">
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($stickyMatch['title'] ?: $stickyMatch['location'])?></div>
          <div style="font-size:11px;color:var(--text-sub);margin-top:2px">
            <?=$stickyMatch['match_date']?> <?=dayOfWeek($stickyMatch['match_date'])?> <?=substr($stickyMatch['match_time'],0,5)?> · <?=h($stickyMatch['location'])?>
          </div>
        </div>
        <i class="bi bi-chevron-right" style="color:var(--text-sub);font-size:12px;flex-shrink:0;margin-top:4px"></i>
      </div>
    </a>
    <div style="display:flex;gap:10px;font-size:11px;margin-bottom:6px">
      <span style="color:var(--primary);font-weight:700">✓ 참석 <?=(int)$stickyAtt['att']?></span>
      <span style="color:#ff6b6b">✗ 불참 <?=(int)$stickyAtt['abs']?></span>
      <span style="color:var(--text-sub)">? 미정 <?=(int)$stickyAtt['pend']?></span>
      <span style="color:var(--text-sub);margin-left:auto">정원 <?=(int)$stickyAtt['att']?>/<?=$maxP?></span>
    </div>
    <div style="height:4px;background:var(--bg-surface-alt);border-radius:2px;margin-bottom:8px;overflow:hidden">
      <div style="height:100%;width:<?=$progress?>%;background:linear-gradient(90deg,var(--primary),#00c87a)"></div>
    </div>
    <!-- 인라인 출석 버튼 -->
    <div style="display:flex;gap:4px">
      <?php foreach (['ATTEND'=>['참석','#00c87a','✓'],'ABSENT'=>['불참','#ff4d6d','✗'],'PENDING'=>['미정','#888','?']] as $statKey=>[$lbl,$col,$ico]):
        $sel = $myStickyStatus === $statKey;
      ?>
      <form method="POST" style="flex:1">
        <?=csrfInput()?>
        <input type="hidden" name="action" value="vote_attendance">
        <input type="hidden" name="match_id" value="<?=$stickyMatch['id']?>">
        <input type="hidden" name="vote" value="<?=$statKey?>">
        <button type="submit" class="btn btn-w" style="font-size:12px;padding:6px;background:<?=$sel?$col:'transparent'?>;color:<?=$sel?'#fff':$col?>;border:1px solid <?=$col?>;font-weight:700">
          <?=$ico?> <?=$lbl?>
        </button>
      </form>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- 메시지 영역 -->
  <div id="chatArea" style="flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px">
    <?php foreach($msgs as $msg):
      $isMe = ($msg['sender_id'] == $me);
      $isSystem = ($msg['msg_type'] === 'SYSTEM');
      $isResult = in_array($msg['msg_type'], ['RESULT_PROPOSE','RESULT_CONFIRM']);
      if($isSystem): ?>
    <div style="text-align:center;margin:4px 0">
      <span style="background:var(--bg-surface-alt);color:var(--text-sub);font-size:12px;padding:4px 12px;border-radius:999px"><?=h($msg['message'])?></span>
    </div>
    <?php elseif($isResult):
      $extra = $msg['extra_data'] ? json_decode($msg['extra_data'], true) : []; ?>
    <div style="text-align:center;margin:8px 0">
      <div style="background:var(--bg-surface);border:1px solid var(--primary);border-radius:12px;padding:12px 16px;display:inline-block;max-width:260px">
        <div style="font-size:12px;color:var(--text-sub);margin-bottom:6px">
          <?=$msg['msg_type']==='RESULT_PROPOSE'?'결과 제안':'결과 확정'?>
        </div>
        <div style="font-size:24px;font-weight:700;font-family:'Space Grotesk',sans-serif;color:var(--primary)">
          <?=h($extra['home_score'] ?? 0)?> : <?=h($extra['away_score'] ?? 0)?>
        </div>
        <?php if($msg['msg_type']==='RESULT_PROPOSE' && !$isMe): ?>
        <form method="POST" style="margin-top:8px">
          <?=csrfInput()?>
          <input type="hidden" name="action" value="approve_result">
          <input type="hidden" name="match_id" value="<?=$extra['match_id'] ?? $conv['match_id']?>">
          <button type="submit" class="btn btn-primary btn-sm btn-w">수락</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php else:
      // 내 메시지: 초록 배경 흰 글씨 / 상대방: 밝은 회색 배경 짙은 글씨
      $bubbleBg   = $isMe ? '#00c87a' : '#2a2d38';
      $bubbleColor= $isMe ? '#ffffff'  : '#e8eaf0';
      $radius     = $isMe ? '18px 18px 4px 18px' : '18px 18px 18px 4px';
    ?>
    <div data-mid="<?=(int)$msg['id']?>" style="display:flex;flex-direction:<?=$isMe?'row-reverse':'row'?>;align-items:flex-end;gap:8px;margin-bottom:2px">
      <?php if(!$isMe): ?>
      <div style="width:34px;height:34px;border-radius:50%;background:#3a3d4a;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;flex-shrink:0;color:#c0c4d6">
        <?=mb_substr($msg['sender_name'] ?? '?', 0, 1)?>
      </div>
      <?php endif; ?>
      <div style="max-width:72%">
        <?php if(!$isMe): ?><div style="font-size:11px;color:#8b90a0;margin-bottom:4px;font-weight:600"><?=h($msg['sender_name'] ?? '')?></div><?php endif; ?>
        <div style="background:<?=$bubbleBg?>;color:<?=$bubbleColor?>;
             padding:10px 14px;border-radius:<?=$radius?>;
             font-size:14px;line-height:1.6;word-break:break-word;
             box-shadow:0 1px 3px rgba(0,0,0,0.3)">
          <?=nl2br(h($msg['message']))?>
        </div>
        <div style="font-size:10px;color:#6b7080;margin-top:4px;text-align:<?=$isMe?'right':'left'?>">
          <?=date('H:i', strtotime($msg['created_at']))?>
        </div>
      </div>
    </div>
    <?php endif; endforeach; ?>
  </div>

  <!-- 입력창 -->
  <div style="background:var(--bg-surface);border-top:1px solid var(--border);padding:10px 16px;padding-bottom:calc(10px + env(safe-area-inset-bottom))">
    <form method="POST" style="display:flex;gap:8px;align-items:flex-end">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="send_message">
      <input type="hidden" name="conv_id" value="<?=$convId?>">
      <textarea name="message" class="form-control" rows="1" placeholder="메시지를 입력하세요..."
        style="flex:1;resize:none;min-height:44px;max-height:120px;padding:10px 14px;border-radius:12px"
        onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();this.form.submit();}"
        required></textarea>
      <button type="submit" class="btn btn-primary" style="min-height:44px;padding:0 16px;flex-shrink:0">
        <i class="bi bi-send-fill"></i>
      </button>
    </form>
  </div>
</div>
<script>
// 채팅창 스크롤 맨 아래로
const ca = document.getElementById('chatArea');
if(ca) ca.scrollTop = ca.scrollHeight;

// [채팅 폴링] 3초마다 새 메시지 자동 로드 (페이지 새로고침 없이)
(function(){
  var convId = <?=$convId?>;
  var meId   = <?=$me?>;
  // 현재 마지막 메시지 ID
  var lastMsgId = 0;
  var allMsgs = document.querySelectorAll('#chatArea > div[data-mid]');
  if (allMsgs.length > 0) lastMsgId = +allMsgs[allMsgs.length - 1].dataset.mid;
  // 없으면 PHP에서 초기값 설정
  if (!lastMsgId) lastMsgId = <?= !empty($msgs) ? (int)end($msgs)['id'] : 0 ?>;

  function escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  function renderMsg(m) {
    var isMe = +m.sender_id === meId;
    var isSystem = m.msg_type === 'SYSTEM';
    if (isSystem) {
      return '<div style="text-align:center;margin:4px 0"><span style="background:var(--bg-surface-alt);color:var(--text-sub);font-size:12px;padding:4px 12px;border-radius:999px">' + escHtml(m.message) + '</span></div>';
    }
    var bg = isMe ? '#00c87a' : '#2a2d38';
    var clr = isMe ? '#ffffff' : '#e8eaf0';
    var rad = isMe ? '18px 18px 4px 18px' : '18px 18px 18px 4px';
    var initChar = (m.sender_name || '?').charAt(0);
    var avatar = '';
    if (!isMe) {
      if (m.profile_image_url) {
        avatar = '<span style="width:34px;height:34px;border-radius:50%;overflow:hidden;flex-shrink:0;display:inline-flex;background:#000"><img src="'+escHtml(m.profile_image_url)+'" style="width:100%;height:100%;object-fit:cover"></span>';
      } else {
        avatar = '<div style="width:34px;height:34px;border-radius:50%;background:#3a3d4a;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;flex-shrink:0;color:#c0c4d6">'+escHtml(initChar)+'</div>';
      }
    }
    var time = m.created_at ? m.created_at.substring(11,16) : '';
    var nameHtml = !isMe ? '<div style="font-size:11px;color:#8b90a0;margin-bottom:4px;font-weight:600">' + escHtml(m.sender_name||'') + '</div>' : '';
    return '<div data-mid="'+m.id+'" style="display:flex;flex-direction:'+(isMe?'row-reverse':'row')+';align-items:flex-end;gap:8px;margin-bottom:2px">' +
      avatar +
      '<div style="max-width:72%">' + nameHtml +
        '<div style="background:'+bg+';color:'+clr+';padding:10px 14px;border-radius:'+rad+';font-size:14px;line-height:1.6;word-break:break-word;box-shadow:0 1px 3px rgba(0,0,0,0.3)">' +
          escHtml(m.message).replace(/\n/g, '<br>') +
        '</div>' +
        '<div style="font-size:10px;color:#6b7080;margin-top:4px;text-align:'+(isMe?'right':'left')+'">'+time+'</div>' +
      '</div>' +
    '</div>';
  }

  function poll() {
    fetch('?page=api&fn=chat_poll&conv_id='+convId+'&after_id='+lastMsgId, {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok || !j.messages || j.messages.length === 0) return;
        var area = document.getElementById('chatArea');
        if (!area) return;
        var wasBottom = (area.scrollHeight - area.scrollTop - area.clientHeight) < 60;
        j.messages.forEach(function(m){
          if (+m.id > lastMsgId) {
            area.insertAdjacentHTML('beforeend', renderMsg(m));
            lastMsgId = +m.id;
          }
        });
        if (wasBottom) area.scrollTop = area.scrollHeight;
      })
      .catch(function(){});
  }
  setInterval(poll, 3000);
})();
</script>
<?php }

// ═══════════════════════════════════════════════════════════════
// 친구 페이지
// ═══════════════════════════════════════════════════════════════
function pagFriends(PDO $pdo): void {
    $me  = me()['id'];
    $tab = $_GET['tab'] ?? 'list'; // list | requests

    $friends = [];
    $requests = [];

    $fStmt = $pdo->prepare("
        SELECT f.id, f.conv_id,
               CASE WHEN f.requester_id=? THEN f.addressee_id ELSE f.requester_id END AS friend_id,
               COALESCE(NULLIF(TRIM(u.nickname),''), u.name) AS friend_name, u.position, u.manner_score, u.region
        FROM friendships f
        JOIN users u ON u.id = CASE WHEN f.requester_id=? THEN f.addressee_id ELSE f.requester_id END
        WHERE (f.requester_id=? OR f.addressee_id=?) AND f.status='ACCEPTED'
        ORDER BY f.updated_at DESC
    ");
    $fStmt->execute([$me,$me,$me,$me]);
    $friends = $fStmt->fetchAll();

    $rStmt = $pdo->prepare("
        SELECT f.id, f.requester_id, f.created_at,
               COALESCE(NULLIF(TRIM(u.nickname),''), u.name) AS requester_name, u.position, u.manner_score
        FROM friendships f
        JOIN users u ON u.id=f.requester_id
        WHERE f.addressee_id=? AND f.status='PENDING'
        ORDER BY f.created_at DESC
    ");
    $rStmt->execute([$me]);
    $requests = $rStmt->fetchAll();
    $reqCount = count($requests);
    ?>
<div class="container">
  <h2 style="font-size:18px;font-weight:700;margin-bottom:16px">친구</h2>

  <!-- 탭 -->
  <div class="chip-row" style="margin-bottom:16px">
    <a href="?page=friends&tab=list" class="chip <?=$tab==='list'?'active':''?>">친구 목록 (<?=count($friends)?>)</a>
    <a href="?page=friends&tab=requests" class="chip <?=$tab==='requests'?'active':''?>">
      받은 요청 <?php if($reqCount>0): ?><span style="background:var(--danger);color:#fff;border-radius:999px;font-size:10px;padding:1px 5px;margin-left:4px"><?=$reqCount?></span><?php endif; ?>
    </a>
  </div>

  <?php if($tab === 'list'): ?>
    <?php if(!$friends): ?>
    <div style="text-align:center;padding:60px 0;color:var(--text-sub)">
      <div style="font-size:36px;margin-bottom:12px">👥</div>
      <div>아직 친구가 없습니다.</div>
      <div style="font-size:13px;margin-top:8px">매치 참여자나 용병 카드에서 친구를 추가해보세요.</div>
    </div>
    <?php else: foreach($friends as $f): ?>
    <div class="card" style="margin-bottom:8px">
      <div class="card-body" style="display:flex;align-items:center;gap:12px">
        <div style="width:42px;height:42px;border-radius:50%;background:var(--primary-glow);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;flex-shrink:0">
          <?=mb_substr(h($f['friend_name']),0,1)?>
        </div>
        <div style="flex:1">
          <div style="font-weight:600"><?=h($f['friend_name'])?></div>
          <div style="font-size:12px;color:var(--text-sub)"><?=h($f['position'] ?? '-')?> · <?=h($f['region'] ?? '-')?> · 매너 <?=number_format((float)$f['manner_score'],1)?></div>
        </div>
        <div style="display:flex;gap:6px">
          <?php if($f['conv_id']): ?>
          <a href="?page=chat&conv_id=<?=(int)$f['conv_id']?>" class="btn btn-primary btn-sm"><i class="bi bi-chat-fill"></i></a>
          <?php else: ?>
          <form method="POST">
            <?=csrfInput()?><input type="hidden" name="action" value="start_chat">
            <input type="hidden" name="target_user_id" value="<?=(int)$f['friend_id']?>">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-chat-fill"></i></button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; endif; ?>

  <?php else: /* requests 탭 */ ?>
    <?php if(!$requests): ?>
    <div style="text-align:center;padding:60px 0;color:var(--text-sub)">받은 친구 요청이 없습니다.</div>
    <?php else: foreach($requests as $r): ?>
    <div class="card" style="margin-bottom:8px">
      <div class="card-body" style="display:flex;align-items:center;gap:12px">
        <div style="width:42px;height:42px;border-radius:50%;background:rgba(0,255,136,0.1);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;flex-shrink:0">
          <?=mb_substr(h($r['requester_name']),0,1)?>
        </div>
        <div style="flex:1">
          <div style="font-weight:600"><?=h($r['requester_name'])?></div>
          <div style="font-size:12px;color:var(--text-sub)"><?=h($r['position'] ?? '-')?> · 매너 <?=number_format((float)$r['manner_score'],1)?></div>
        </div>
        <div style="display:flex;gap:6px">
          <form method="POST">
            <?=csrfInput()?>
            <input type="hidden" name="action" value="respond_friend">
            <input type="hidden" name="friendship_id" value="<?=$r['id']?>">
            <input type="hidden" name="answer" value="accept">
            <button type="submit" class="btn btn-primary btn-sm">수락</button>
          </form>
          <form method="POST">
            <?=csrfInput()?>
            <input type="hidden" name="action" value="respond_friend">
            <input type="hidden" name="friendship_id" value="<?=$r['id']?>">
            <input type="hidden" name="answer" value="reject">
            <button type="submit" class="btn btn-ghost btn-sm">거절</button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  <?php endif; ?>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 팀 프로필 공개 페이지
// ═══════════════════════════════════════════════════════════════
function pagTeamProfile(PDO $pdo): void {
    $tid = (int)($_GET['team_id'] ?? 0);
    if (!$tid) { echo '<div class="container" style="padding:40px 16px;text-align:center;color:var(--text-sub)">팀 ID가 필요합니다.</div>'; return; }

    $team = $pdo->prepare("SELECT t.*, u.name AS leader_name FROM teams t JOIN users u ON u.id=t.leader_id WHERE t.id=?");
    $team->execute([$tid]); $team = $team->fetch();
    if (!$team) { echo '<div class="container" style="padding:40px;text-align:center;color:var(--text-sub)">팀을 찾을 수 없습니다.</div>'; return; }

    // 시즌 통계
    $stats = $pdo->prepare("SELECT * FROM team_season_stats WHERE team_id=?");
    $stats->execute([$tid]); $stats = $stats->fetch() ?: [];

    // 최근 5경기
    $recentMatches = $pdo->prepare("
        SELECT m.id, m.match_date, m.status,
               ht.name AS home_name, at.name AS away_name,
               mr.score_home, mr.score_away, m.home_team_id, m.away_team_id
        FROM matches m
        LEFT JOIN teams ht ON ht.id=m.home_team_id
        LEFT JOIN teams at ON at.id=m.away_team_id
        LEFT JOIN match_results mr ON mr.match_id=m.id AND mr.is_approved=1
        WHERE (m.home_team_id=? OR m.away_team_id=?) AND m.status='completed'
        ORDER BY m.match_date DESC LIMIT 5
    ");
    $recentMatches->execute([$tid,$tid]); $recentMatches = $recentMatches->fetchAll();

    // 팀원 목록
    $members = $pdo->prepare("
        SELECT tm.*, u.name, u.nickname, u.position, u.manner_score, tm.is_pro
        FROM team_members tm JOIN users u ON u.id=tm.user_id
        WHERE tm.team_id=? AND tm.status='active'
        ORDER BY FIELD(tm.role,'captain','player','mercenary')
    ");
    $members->execute([$tid]); $members = $members->fetchAll();

    // 나와 친구 관계 확인
    $friendStatus = null;
    $friendId = null;
    if (me()) {
        $fs = $pdo->prepare("
            SELECT id, status, requester_id FROM friendships
            WHERE (requester_id=? AND addressee_id=?) OR (requester_id=? AND addressee_id=?)
        ");
        // 팀 리더와의 관계 (대표적으로)
    }
    ?>
<div class="container">
  <!-- 팀 헤더 -->
  <div class="card" style="margin-bottom:12px">
    <div class="card-body" style="text-align:center;padding:24px 16px">
      <div style="font-size:48px;margin-bottom:8px">🛡️</div>
      <h2 style="font-size:22px;font-weight:900;margin-bottom:4px"><?=h(teamDisplayName($team['name']))?></h2>
      <div style="font-size:13px;color:var(--text-sub)"><?=h($team['region'])?><?=!empty($team['district'])?' '.h($team['district']):''?></div>
      <div style="margin-top:10px;display:flex;flex-wrap:wrap;justify-content:center;gap:6px">
        <?php if(!empty($team['style'])): ?><span class="badge badge-green"><?=h($team['style'])?></span><?php endif; ?>
        <?php if(!empty($team['activity_day'])): ?><span class="badge badge-blue"><?=h($team['activity_day'])?></span><?php endif; ?>
        <?php if(!empty($team['avg_age_range'])): ?><span class="badge" style="background:rgba(255,255,255,0.06);color:var(--text-sub)"><?=h($team['avg_age_range'])?></span><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 전적 카드 -->
  <div class="card" style="margin-bottom:12px">
    <div class="card-body">
      <p class="section-title" style="margin-bottom:12px">시즌 전적</p>
      <div style="display:grid;grid-template-columns:repeat(4,1fr);text-align:center;gap:8px">
        <div><div style="font-size:22px;font-weight:700;color:var(--primary)"><?=h($stats['matches_played']??0)?></div><div style="font-size:11px;color:var(--text-sub)">경기</div></div>
        <div><div style="font-size:22px;font-weight:700"><?=h($stats['wins']??0)?></div><div style="font-size:11px;color:var(--text-sub)">승</div></div>
        <div><div style="font-size:22px;font-weight:700"><?=h($stats['draws']??0)?></div><div style="font-size:11px;color:var(--text-sub)">무</div></div>
        <div><div style="font-size:22px;font-weight:700;color:var(--danger)"><?=h($stats['losses']??0)?></div><div style="font-size:11px;color:var(--text-sub)">패</div></div>
      </div>
      <div style="margin-top:10px;text-align:center;font-size:13px;color:var(--text-sub)">
        득점 <?=h($stats['goals_for']??0)?> &nbsp;·&nbsp; 실점 <?=h($stats['goals_against']??0)?>
        &nbsp;·&nbsp; <?=($stats['points']??0)?>pts
      </div>
    </div>
  </div>

  <?php if(!empty($team['intro'])): ?>
  <!-- 팀 소개 -->
  <div class="card" style="margin-bottom:12px">
    <div class="card-body">
      <p class="section-title" style="margin-bottom:8px">팀 소개</p>
      <div style="font-size:14px;color:var(--text-sub);line-height:1.7"><?=nl2br(h($team['intro']))?></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- 최근 5경기 -->
  <?php if($recentMatches): ?>
  <div class="card" style="margin-bottom:12px">
    <div class="card-body">
      <p class="section-title" style="margin-bottom:10px">최근 경기</p>
      <?php foreach($recentMatches as $rm):
        $isHome = ($rm['home_team_id'] == $tid);
        $myScore = $isHome ? $rm['score_home'] : $rm['score_away'];
        $opScore = $isHome ? $rm['score_away'] : $rm['score_home'];
        $opName  = $isHome ? ($rm['away_name'] ?? '?') : $rm['home_name'];
        $result = $myScore === null ? null : ($myScore > $opScore ? 'W' : ($myScore < $opScore ? 'L' : 'D'));
        $rColor = $result === 'W' ? 'var(--primary)' : ($result === 'L' ? 'var(--danger)' : 'var(--text-sub)');
      ?>
      <div class="list-item" style="padding:8px 0">
        <div>
          <span style="font-weight:700;color:<?=$rColor?>;font-size:15px;margin-right:8px"><?=$result ?? '-'?></span>
          vs <?=h($opName)?>
        </div>
        <div style="font-size:12px;color:var(--text-sub)">
          <?=$rm['match_date']?>
          <?php if($myScore !== null): ?>&nbsp; <?=$myScore?>:<?=$opScore?><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- 팀원 목록 -->
  <div class="card" style="margin-bottom:12px">
    <div class="card-body">
      <p class="section-title" style="margin-bottom:10px">팀원 (<?=count($members)?>명)</p>
      <?php foreach($members as $m): ?>
      <div class="list-item">
        <div style="display:flex;align-items:center;gap:10px">
          <?php $mDn = displayName($m); ?>
          <div style="width:36px;height:36px;border-radius:50%;background:var(--bg-surface-alt);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700">
            <?=mb_substr(h($mDn),0,1)?>
          </div>
          <div>
            <div style="font-weight:600;font-size:14px">
              <?=h($mDn)?>
              <?php if($m['role']==='captain'): ?><span class="badge badge-green" style="font-size:10px">C</span><?php endif; ?>
              <?php if($m['is_pro']): ?><span class="badge badge-blue" style="font-size:10px">선출</span><?php endif; ?>
            </div>
            <div style="font-size:11px;color:var(--text-sub)"><?=h($m['position'] ?? '-')?></div>
          </div>
        </div>
        <?php if(me() && me()['id'] !== $m['user_id']): ?>
        <form method="POST">
          <?=csrfInput()?>
          <input type="hidden" name="action" value="send_friend_request">
          <input type="hidden" name="target_user_id" value="<?=(int)$m['user_id']?>">
          <button type="submit" class="btn btn-outline btn-sm"><i class="bi bi-person-plus"></i></button>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 팀 설정 페이지 (캡틴 전용)
// ═══════════════════════════════════════════════════════════════
function pagTeamSettings(PDO $pdo): void {
    requireLogin(); if (!isCaptain()) { flash('캡틴만 접근 가능합니다.', 'error'); redirect('?page=team'); }
    $tid  = myTeamId();
    $ts = $pdo->prepare("SELECT * FROM teams WHERE id=?"); $ts->execute([$tid]); $team = $ts->fetch();
    if (!$team) { redirect('?page=team'); }
    $team = $pdo->prepare("SELECT * FROM teams WHERE id=?");
    $team->execute([$tid]); $team = $team->fetch();
    ?>
<div class="container">
  <h2 style="font-size:18px;font-weight:700;margin-bottom:16px">팀 프로필 설정</h2>
  <div class="card"><div class="card-body">
    <form method="POST">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="save_team_settings">
      <div class="form-group">
        <label class="form-label">팀 소개글</label>
        <textarea name="intro" class="form-control" rows="4" placeholder="팀을 소개해주세요..."><?=h($team['intro'] ?? '')?></textarea>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">팀 성향</label>
          <select name="style" class="form-select">
            <?php foreach(['친선','빡겜','초보환영','매너중시'] as $s): ?>
            <option <?=($team['style']??'')===$s?'selected':''?>><?=h($s)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">활동 요일</label>
          <select name="activity_day" class="form-select">
            <?php foreach(['평일','주말','상관없음'] as $d): ?>
            <option <?=($team['activity_day']??'')===$d?'selected':''?>><?=h($d)?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">평균 연령대</label>
        <select name="avg_age_range" class="form-select">
          <?php foreach(['20대','30대','40대','무관'] as $a): ?>
          <option <?=($team['avg_age_range']??'')===$a?'selected':''?>><?=h($a)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">토스 모임통장 링크 <span style="font-size:12px;color:var(--text-sub)">(팀원에게만 노출)</span></label>
        <input type="url" name="toss_link" class="form-control" value="<?=h($team['toss_link'] ?? '')?>" placeholder="https://toss.me/...">
      </div>
      <div class="form-group">
        <label class="form-label">카카오페이 링크 <span style="font-size:12px;color:var(--text-sub)">(팀원에게만 노출)</span></label>
        <input type="url" name="kakao_pay_link" class="form-control" value="<?=h($team['kakao_pay_link'] ?? '')?>" placeholder="https://qr.kakaopay.com/...">
      </div>
      <div class="form-group">
        <label class="form-label">월 회비 금액</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="number" name="membership_fee_amount" class="form-control" style="flex:1" value="<?=(int)($team['membership_fee']??0)?>" min="0" step="1000" placeholder="30000">
          <span style="font-size:13px;color:var(--text-sub)">원/월</span>
        </div>
        <div style="font-size:10px;color:var(--text-sub);margin-top:4px">회비 관리 페이지에서 사용됩니다. 0 = 회비 없음</div>
      </div>
      <div class="form-group">
        <label class="form-label">팀 앰블럼 URL <span style="font-size:12px;color:var(--text-sub)">(정사각형 이미지 권장)</span></label>
        <input type="url" name="emblem_url" class="form-control" value="<?=h($team['emblem_url'] ?? '')?>" placeholder="https://example.com/emblem.png">
        <?php if(!empty($team['emblem_url'])): ?>
        <img src="<?=h($team['emblem_url'])?>" style="width:48px;height:48px;object-fit:contain;margin-top:6px;border-radius:8px;border:1px solid #333" alt="앰블럼">
        <?php endif; ?>
      </div>
      <button type="submit" class="btn btn-primary btn-w">저장</button>
    </form>
  </div></div>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════
// [앱 기능 소개] ?page=guide — 신규 유저 / 시연자 / 투자자 대상
// ═══════════════════════════════════════════════════════════════
function pagGuide(PDO $pdo): void {
    requireLogin();
?>
<div class="container">
  <div style="margin-bottom:20px">
    <h2 style="font-size:22px;font-weight:800;margin-bottom:4px">⚽ TRUST FOOTBALL 기능 소개</h2>
    <div style="font-size:12px;color:var(--text-sub)">동호회 축구를 쉽고 공정하게 — 2분 안에 파악하세요</div>
  </div>

  <!-- 핵심 가치 3가지 -->
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:20px">
    <div class="card" style="text-align:center"><div class="card-body" style="padding:14px 8px">
      <div style="font-size:28px;margin-bottom:4px">🎯</div>
      <div style="font-size:12px;font-weight:700;color:var(--primary)">공정한 매칭</div>
      <div style="font-size:10px;color:var(--text-sub);margin-top:2px">팀 실력 자동 산정</div>
    </div></div>
    <div class="card" style="text-align:center"><div class="card-body" style="padding:14px 8px">
      <div style="font-size:28px;margin-bottom:4px">🛡️</div>
      <div style="font-size:12px;font-weight:700;color:#3a9ef5">매너 시스템</div>
      <div style="font-size:10px;color:var(--text-sub);margin-top:2px">노쇼 자동 제재</div>
    </div></div>
    <div class="card" style="text-align:center"><div class="card-body" style="padding:14px 8px">
      <div style="font-size:28px;margin-bottom:4px">⚡</div>
      <div style="font-size:12px;font-weight:700;color:#ffb400">용병 시스템</div>
      <div style="font-size:10px;color:var(--text-sub);margin-top:2px">양방향 매칭</div>
    </div></div>
  </div>

  <!-- 기능 카테고리 -->
  <p class="section-title">🏠 홈</p>
  <div class="card" style="margin-bottom:12px"><div class="card-body">
    <ul style="font-size:13px;line-height:1.8;padding-left:18px;margin:0">
      <li><b>다가오는 매치</b> — 이번주 우리 팀 경기가 카드로</li>
      <li><b>오늘/내일 리마인더</b> — 빨간 강조 카드 + 빠른 참석 투표</li>
      <li><b>팀 랭킹 TOP 3</b> — 즉시 확인</li>
      <li><b>팀 없는 유저</b> — 프로필 완성도 + 지역 팀 TOP 3 + 용병 구하는 경기</li>
    </ul>
  </div></div>

  <p class="section-title">⚽ 매치</p>
  <div class="card" style="margin-bottom:12px"><div class="card-body">
    <ul style="font-size:13px;line-height:1.8;padding-left:18px;margin:0">
      <li><b>3가지 타입</b> — 🏟️ 상대팀 구함 / 🔍 경기장 구함 / 🆘 모두 구함</li>
      <li><b>필터 탭</b> — 모집중 / 성사·진행 / 완료·취소</li>
      <li><b>카드에 한 눈에</b> — 유니폼 색상, 팀 실력 레벨, 용병·선출 인원, D-day</li>
      <li><b>체크인/출석/쿼터 배정</b> — 매치 상세에서 전부</li>
    </ul>
  </div></div>

  <p class="section-title">⚡ FA 시장 (용병)</p>
  <div class="card" style="margin-bottom:12px"><div class="card-body">
    <ul style="font-size:13px;line-height:1.8;padding-left:18px;margin:0">
      <li><b>선수가 지원</b> — 매치에 "용병으로 뛸게요" 신청</li>
      <li><b>팀이 제안</b> — 프로필 보고 "우리 경기에 와주세요"</li>
      <li><b>수락 방향 분리</b> — 제안은 선수가, 지원은 캡틴이 수락</li>
      <li><b>용병 이력</b> — 같은 팀에 여러 번 온 선수 자동 추적</li>
    </ul>
  </div></div>

  <p class="section-title">👤 프로필</p>
  <div class="card" style="margin-bottom:12px"><div class="card-body">
    <ul style="font-size:13px;line-height:1.8;padding-left:18px;margin:0">
      <li><b>6각 레이더</b> — 속도/슈팅/패스/드리블/수비/피지컬</li>
      <li><b>닉네임 vs 실명</b> — 앱 내 공개는 닉네임, 회비/신고는 실명</li>
      <li><b>프로필 사진</b> — 업로드 지원 (3MB 제한)</li>
      <li><b>캡틴 비공개 메모</b> — 선수별 본인만 보는 메모</li>
      <li><b>경기 이력</b> — 팀/용병 분리, 최근 10~200건 히스토리</li>
    </ul>
  </div></div>

  <p class="section-title">🛡️ 팀</p>
  <div class="card" style="margin-bottom:12px"><div class="card-body">
    <ul style="font-size:13px;line-height:1.8;padding-left:18px;margin:0">
      <li><b>팀 활성화 게이트</b> — 3명 이상 모여야 매치 개설 가능</li>
      <li><b>가입 승인 플로우</b> — 초대코드로 신청 → 캡틴 수락</li>
      <li><b>실력 4단계 자동</b> — 🟢 입문 / 🔵 밸런스 / 🟠 경쟁 / 🔴 강팀</li>
      <li><b>포지션 도넛차트</b> — 팀원 GK/DF/MF/FW 분포</li>
      <li><b>카톡 공유 딥링크</b> — 비로그인 유저도 링크 클릭 후 자동 연결</li>
    </ul>
  </div></div>

  <p class="section-title">🎖 매너 시스템</p>
  <div class="card" style="margin-bottom:12px"><div class="card-body">
    <ul style="font-size:13px;line-height:1.8;padding-left:18px;margin:0">
      <li><b>매너점수 0~100°</b> — 기본 36.5°</li>
      <li><b>노쇼 자동 -5점</b> — 30° 이하면 7일 활동 제한</li>
      <li><b>경기 후 평가</b> — 매너(+0.5/-1) / 시간(0/-0.5) / 전반(+0.3/-0.5)</li>
      <li><b>MOM 투표</b> — 참석자 전원 투표, 배지 표시</li>
      <li><b>신고 → 어드민 처리</b> — 신고 유형별 관리자 수동 승인</li>
    </ul>
  </div></div>

  <p class="section-title">🔐 운영 (SUPER_ADMIN)</p>
  <div class="card" style="margin-bottom:12px"><div class="card-body">
    <ul style="font-size:13px;line-height:1.8;padding-left:18px;margin:0">
      <li><b>대시보드</b> — 통계 + 대기 건수 + 최근 액션</li>
      <li><b>인증 탭</b> — 경기장 영수증 승인/보류/거절</li>
      <li><b>신고 탭</b> — 검토중/처리/기각 + 유저 제재 연동</li>
      <li><b>매치 강제</b> — 분쟁 / 취소 / 결과 강제 입력</li>
      <li><b>유저 관리</b> — 7일/30일/영구 제재, 매너 조정, BLACKLIST</li>
      <li><b>피드백 탭</b> — 유저 VOC 수집 + 상태 관리</li>
      <li><b>감사 로그</b> — 모든 액션 admin_logs 자동 기록</li>
    </ul>
  </div></div>

  <!-- 기술 하이라이트 -->
  <p class="section-title">🛠️ 기술 특이사항</p>
  <div class="card" style="margin-bottom:12px;border:1px solid rgba(0,255,136,0.2)"><div class="card-body">
    <div style="font-size:12px;line-height:1.7">
      <div><b>단일 파일 PHP</b> — 외부 의존성 최소화 (jQuery/React 없음)</div>
      <div><b>PWA</b> — 홈 화면 설치 가능, 오프라인 기본 캐시</div>
      <div><b>SVG 차트 전부 인라인</b> — Chart.js 등 미사용</div>
      <div><b>CSRF + XSS + SQL Injection</b> — 3중 방어</div>
      <div><b>세션 2시간, HttpOnly + SameSite=Lax</b></div>
      <div><b>40+ DB 테이블, 60+ POST 액션</b></div>
      <div><b>감사 로그 (admin_logs)</b> — 되돌릴 수 없는 액션 전부 추적</div>
    </div>
  </div></div>

  <!-- 접근 링크 -->
  <p class="section-title">🔗 주요 페이지 바로가기</p>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:20px">
    <a href="?page=home"         class="btn btn-outline btn-sm" style="font-size:11px;padding:8px">🏠 홈</a>
    <a href="?page=matches"      class="btn btn-outline btn-sm" style="font-size:11px;padding:8px">⚽ 매치 리스트</a>
    <a href="?page=mercenaries"  class="btn btn-outline btn-sm" style="font-size:11px;padding:8px">⚡ FA 시장</a>
    <a href="?page=recruits"     class="btn btn-outline btn-sm" style="font-size:11px;padding:8px">🛡️ 팀원 모집</a>
    <a href="?page=team"         class="btn btn-outline btn-sm" style="font-size:11px;padding:8px">🛡️ 내 팀</a>
    <a href="?page=mypage"       class="btn btn-outline btn-sm" style="font-size:11px;padding:8px">👤 마이페이지</a>
    <a href="?page=history"      class="btn btn-outline btn-sm" style="font-size:11px;padding:8px">📅 지난 경기</a>
    <a href="?page=ranking"      class="btn btn-outline btn-sm" style="font-size:11px;padding:8px">🏆 랭킹</a>
    <a href="?page=messages"     class="btn btn-outline btn-sm" style="font-size:11px;padding:8px">💬 메시지</a>
    <a href="?page=friends"      class="btn btn-outline btn-sm" style="font-size:11px;padding:8px">👥 친구</a>
    <a href="?page=fees"         class="btn btn-outline btn-sm" style="font-size:11px;padding:8px">👥 회원명단</a>
    <a href="?page=notifications" class="btn btn-outline btn-sm" style="font-size:11px;padding:8px">🔔 알림</a>
    <?php if (isAnyAdmin()): ?>
    <a href="?page=admin" class="btn btn-sm" style="font-size:11px;padding:8px;background:#ff4d6d;color:#fff;grid-column:1/-1">🔐 관리 (어드민 전용)</a>
    <?php endif; ?>
  </div>

  <div style="text-align:center;font-size:10px;color:var(--text-sub);margin:20px 0">
    버전 정보 · PHP 8.1 + MySQL · 2026-04-19 기준
  </div>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 사용설명서 (비로그인 접근 가능)
// ═══════════════════════════════════════════════════════════════
function pagManual(): void { ?>
<div class="container" style="max-width:460px">
  <div style="text-align:center;margin-bottom:24px">
    <div style="font-size:40px;margin-bottom:8px">⚽</div>
    <h1 style="font-size:22px;font-weight:800;margin:0">TRUST FOOTBALL</h1>
    <div style="font-size:13px;color:var(--text-sub);margin-top:4px">사용설명서</div>
  </div>

  <!-- 목차 -->
  <div class="card" style="margin-bottom:16px"><div class="card-body" style="padding:12px 16px">
    <div style="font-size:12px;font-weight:700;margin-bottom:8px;color:var(--primary)">📋 목차</div>
    <div style="display:flex;flex-direction:column;gap:4px;font-size:12px">
      <a href="#m-start" style="color:var(--text)">1. 시작하기 (가입 → 팀 합류)</a>
      <a href="#m-match" style="color:var(--text)">2. 매치 참여하기</a>
      <a href="#m-team" style="color:var(--text)">3. 팀 관리 (캡틴용)</a>
      <a href="#m-merc" style="color:var(--text)">4. 용병 시스템</a>
      <a href="#m-manner" style="color:var(--text)">5. 매너점수 & 평가</a>
      <a href="#m-profile" style="color:var(--text)">6. 프로필 설정</a>
      <a href="#m-member" style="color:var(--text)">7. 회원명단 & 회비</a>
      <a href="#m-chat" style="color:var(--text)">8. 메시지 & 알림</a>
      <a href="#m-pwa" style="color:var(--text)">9. 홈 화면에 설치 (PWA)</a>
      <a href="#m-faq" style="color:var(--text)">10. 자주 묻는 질문</a>
    </div>
  </div></div>

  <!-- 1. 시작하기 -->
  <div id="m-start" style="scroll-margin-top:60px">
  <p class="section-title" style="color:var(--primary)">1. 시작하기</p>
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:14px">
    <div style="font-size:13px;line-height:1.9">
      <b>회원가입</b>
      <div style="padding-left:12px;color:var(--text-sub);font-size:12px;margin-bottom:8px">
        이름 · 전화번호 · 비밀번호 입력 → 약관 동의 → 가입 완료<br>
        포지션·지역은 나중에 마이페이지에서 설정 가능
      </div>
      <b>팀 합류하기</b>
      <div style="padding-left:12px;color:var(--text-sub);font-size:12px;margin-bottom:8px">
        방법 1) 회원가입 시 <b>팀 초대코드</b> 입력<br>
        방법 2) 가입 후 팀원 모집 게시판에서 신청<br>
        → 캡틴이 수락하면 팀 소속 확정!
      </div>
      <b>팀 만들기</b>
      <div style="padding-left:12px;color:var(--text-sub);font-size:12px">
        팀 페이지 → "팀 만들기" → 이름·지역 입력<br>
        <span style="color:#ff9500">⚠️ 3명 이상 모여야 매치 개설 가능</span> (팀 활성화 조건)
      </div>
    </div>
  </div></div></div>

  <!-- 2. 매치 -->
  <div id="m-match" style="scroll-margin-top:60px">
  <p class="section-title" style="color:var(--primary)">2. 매치 참여하기</p>
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:14px">
    <div style="font-size:13px;line-height:1.9">
      <b>매치 유형</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub);margin-bottom:8px">
        🏟️ <b>상대팀 구함</b> — 경기장 확보됨, 상대만 찾는 중<br>
        🔍 <b>경기장 구함</b> — 상대 있음, 장소만 필요<br>
        🆘 <b>모두 구함</b> — 상대·장소 모두 구하는 중
      </div>
      <b>참석 투표</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub);margin-bottom:8px">
        매치 카드에서 <span style="color:var(--primary)">✓ 참석</span> 또는 ✕ 불참 클릭<br>
        참석하면 쿼터 선발에 자동 배정됩니다
      </div>
      <b>경기 진행</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub)">
        모집중 → 성사(confirmed) → 체크인 → 경기중 → 결과입력 → 평가 → 완료<br>
        체크인은 경기 당일 캡틴이 오픈합니다
      </div>
    </div>
  </div></div></div>

  <!-- 3. 팀 관리 -->
  <div id="m-team" style="scroll-margin-top:60px">
  <p class="section-title" style="color:var(--primary)">3. 팀 관리 (캡틴/관리자용)</p>
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:14px">
    <div style="font-size:13px;line-height:1.9">
      <b>직책</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub);margin-bottom:8px">
        <span style="color:#00ff88">구단주</span> · <span style="color:#ffd60a">주장</span> · <span style="color:#ff9500">부주장</span> · <span style="color:#3a9ef5">매니저</span> · <span style="color:#9b59b6">코치</span> · <span style="color:#e67e22">총무</span> · 선수
      </div>
      <b>캡틴이 할 수 있는 것</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub);margin-bottom:8px">
        매치 개설 · 가입 승인/거절 · 팀원 강퇴 · 직책 변경<br>
        쿼터 선발 · 포메이션 설정 · 경기 결과 입력<br>
        회비 관리 · 대리 출석 · 노쇼/비매너 신고
      </div>
      <b>초대코드 공유</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub)">
        팀 페이지 → 초대코드 복사 → 카톡/문자로 전달<br>
        상대방이 코드 입력하면 가입 신청 자동 도착
      </div>
    </div>
  </div></div></div>

  <!-- 4. 용병 -->
  <div id="m-merc" style="scroll-margin-top:60px">
  <p class="section-title" style="color:var(--primary)">4. 용병 시스템</p>
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:14px">
    <div style="font-size:13px;line-height:1.9">
      <b>용병으로 뛰고 싶을 때</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub);margin-bottom:8px">
        FA 시장 → 용병 프로필 등록 → 매치에 "용병 지원"<br>
        또는 팀이 먼저 제안하면 수락/거절
      </div>
      <b>용병이 필요할 때 (캡틴)</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub)">
        매치 상세 → 용병 모집 활성화<br>
        FA 시장에서 선수 프로필 보고 직접 제안 가능
      </div>
    </div>
  </div></div></div>

  <!-- 5. 매너 -->
  <div id="m-manner" style="scroll-margin-top:60px">
  <p class="section-title" style="color:var(--primary)">5. 매너점수 & 평가</p>
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:14px">
    <div style="font-size:13px;line-height:1.9">
      <b>매너점수</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub);margin-bottom:8px">
        기본 36.5° · 범위 0~100°<br>
        <span style="color:var(--danger)">30° 이하 → 7일 활동 제한</span> (매치 신청/용병 지원 불가)
      </div>
      <b>점수 변동</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub);margin-bottom:8px">
        노쇼/비매너 신고 승인: <span style="color:var(--danger)">-5점</span><br>
        경기 후 상대팀 평가: +0.5 ~ -1점<br>
        MOM 선정: 랭킹 반영
      </div>
      <b>경기 후 평가 흐름</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub)">
        경기 종료 → 캡틴이 결과 입력 → 상대팀 평가 → MOM 투표 → 완료
      </div>
    </div>
  </div></div></div>

  <!-- 6. 프로필 -->
  <div id="m-profile" style="scroll-margin-top:60px">
  <p class="section-title" style="color:var(--primary)">6. 프로필 설정</p>
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:14px">
    <div style="font-size:13px;line-height:1.9">
      <b>마이페이지에서 설정</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub);margin-bottom:8px">
        닉네임 · 프로필 사진 · 등번호 · 키/몸무게<br>
        선호 포지션 (최대 3개) · 주발 · 선수 출신 여부<br>
        능력치 6종 (속도/슈팅/패스/드리블/수비/피지컬)
      </div>
      <b>닉네임 vs 실명</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub)">
        앱 내 모든 곳에서 닉네임으로 표시<br>
        회비·신고·인증 화면에서만 실명 사용
      </div>
    </div>
  </div></div></div>

  <!-- 7. 회원명단 & 회비 -->
  <div id="m-member" style="scroll-margin-top:60px">
  <p class="section-title" style="color:var(--primary)">7. 회원명단 & 회비</p>
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:14px">
    <div style="font-size:13px;line-height:1.9">
      <b>회원명단</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub);margin-bottom:8px">
        팀 회원 전체 리스트 · 직책/포지션 표시<br>
        클릭하면 선수 프로필 상세 확인
      </div>
      <b>회비 (캡틴용)</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub);margin-bottom:8px">
        월별 납부/미납 한눈에 확인<br>
        "이달 회비 일괄 부과" → 전체 회원에게 자동<br>
        미납자 일괄 독촉 알림
      </div>
      <b>특별항목</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub)">
        후원금 · 연회비 · 참가비 · 보증금 · 벌금 등<br>
        개별 추가 + 납부 처리
      </div>
    </div>
  </div></div></div>

  <!-- 8. 메시지 -->
  <div id="m-chat" style="scroll-margin-top:60px">
  <p class="section-title" style="color:var(--primary)">8. 메시지 & 알림</p>
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:14px">
    <div style="font-size:13px;line-height:1.9">
      <b>1:1 메시지</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub);margin-bottom:8px">
        선수 프로필 → "메시지 보내기" 또는 메시지 탭에서<br>
        같은 팀원은 빠른 시작 버튼으로 즉시 대화
      </div>
      <b>알림</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub)">
        가입 승인 · 매치 변경 · 용병 신청/수락 · 회비 독촉 등<br>
        알림 탭에서 유형별 필터 가능
      </div>
    </div>
  </div></div></div>

  <!-- 9. PWA -->
  <div id="m-pwa" style="scroll-margin-top:60px">
  <p class="section-title" style="color:var(--primary)">9. 홈 화면에 설치</p>
  <div class="card" style="margin-bottom:14px"><div class="card-body" style="padding:14px">
    <div style="font-size:13px;line-height:1.9">
      <b>아이폰 (Safari)</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub);margin-bottom:8px">
        하단 공유 버튼 (□↑) → "홈 화면에 추가" → 추가
      </div>
      <b>안드로이드 (Chrome)</b>
      <div style="padding-left:12px;font-size:12px;color:var(--text-sub)">
        주소창 아래 "앱 설치" 배너 클릭<br>
        또는 메뉴(⋮) → "홈 화면에 추가"
      </div>
    </div>
  </div></div></div>

  <!-- 10. FAQ -->
  <div id="m-faq" style="scroll-margin-top:60px">
  <p class="section-title" style="color:var(--primary)">10. 자주 묻는 질문</p>
  <?php
  $faqs = [
    ['비밀번호를 잊었어요', '로그인 화면 → "비밀번호를 잊으셨나요?" → 이름·전화번호 입력 → 인증번호 확인 → 새 비밀번호 설정'],
    ['팀을 옮기고 싶어요', '현재 팀에서 나가기 (팀 페이지 하단) → 새 팀 초대코드로 가입 신청'],
    ['용병으로 뛰면 기록이 남나요?', '네! 프로필에서 팀 기록과 용병 기록이 분리되어 표시됩니다.'],
    ['매너점수가 너무 낮아졌어요', '30° 이하면 7일간 활동 제한. 이후 자동 해제되며, 좋은 평가를 받으면 점수가 올라갑니다.'],
    ['매치를 취소하고 싶어요', '매치 상세 → 하단 "매치 삭제" (경기 시작 전까지만 가능)'],
    ['캡틴을 바꾸고 싶어요', '현재 캡틴이 팀 설정에서 다른 팀원에게 양도할 수 있습니다.'],
    ['앱이 느려요', '브라우저 캐시를 삭제하거나, PWA를 삭제 후 다시 설치해보세요.'],
  ];
  foreach($faqs as $i => $faq): ?>
  <div class="card" style="margin-bottom:6px"><div class="card-body" style="padding:10px 14px;cursor:pointer" onclick="this.querySelector('.faq-a').style.display=this.querySelector('.faq-a').style.display==='none'?'block':'none'">
    <div style="display:flex;align-items:center;gap:8px">
      <span style="color:var(--primary);font-weight:700;font-size:12px">Q</span>
      <span style="font-size:13px;font-weight:600;flex:1"><?=$faq[0]?></span>
      <i class="bi bi-chevron-down" style="font-size:10px;color:var(--text-sub)"></i>
    </div>
    <div class="faq-a" style="display:none;margin-top:8px;padding-left:22px;font-size:12px;color:var(--text-sub);line-height:1.7"><?=$faq[1]?></div>
  </div></div>
  <?php endforeach; ?>
  </div>

  <div style="text-align:center;margin:24px 0 40px">
    <div style="font-size:11px;color:var(--text-sub);margin-bottom:8px">문의 · 피드백은 앱 내 📣 버튼으로</div>
    <?php if(me()): ?>
    <a href="?page=home" class="btn btn-primary" style="font-size:13px;padding:10px 24px">홈으로 가기</a>
    <?php else: ?>
    <a href="?page=login" class="btn btn-primary" style="font-size:13px;padding:10px 24px">로그인 / 회원가입</a>
    <?php endif; ?>
    <div style="margin-top:12px">
      <a href="?page=terms" style="font-size:11px;color:var(--text-sub)">📄 이용약관 · 개인정보처리방침 · 위치기반서비스</a>
    </div>
  </div>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 이용약관 / 개인정보처리방침 / 위치기반서비스 이용약관  ?page=terms
// 비로그인 접근 가능 — 3탭 구조, 전문 내장
// ═══════════════════════════════════════════════════════════════
function pagTerms(): void {
    $tab = $_GET['tab'] ?? 'tos';
    $company = 'TRUST FOOTBALL 팀';
    $effectiveDate = '2026-05-01';

    // ── 이용약관 ──
    $tosTxt = <<<EOTXT
<h3 style="color:var(--primary);margin-bottom:12px">이용약관</h3>
<p style="font-size:12px;color:var(--text-sub);margin-bottom:16px">시행일: {$effectiveDate} | 운영: {$company}</p>

<h4>제1조 (목적)</h4>
<p>이 약관은 {$company}(이하 "회사")가 제공하는 TRUST FOOTBALL 서비스(이하 "서비스")의 이용과 관련하여 회사와 회원 간의 권리·의무 및 책임사항, 기타 필요한 사항을 규정함을 목적으로 합니다.</p>

<h4>제2조 (정의)</h4>
<p>이 약관에서 사용하는 주요 용어의 정의는 다음과 같습니다.</p>
<ol>
<li><b>서비스</b>: 회사가 제공하는 풋살/축구 동호회 매칭, 팀 관리, 용병 매칭, 매너점수 운영 등 관련 제반 서비스를 말합니다.</li>
<li><b>회원</b>: 서비스에 가입하여 이용 계약을 체결한 자를 말합니다.</li>
<li><b>계정</b>: 회원이 서비스에 로그인하기 위해 설정한 전화번호 및 비밀번호 조합을 말합니다.</li>
<li><b>팀</b>: 서비스 내에서 회원들이 함께 활동하기 위해 생성·가입한 그룹 단위를 말합니다.</li>
<li><b>매치</b>: 팀 간 또는 개인 간에 예약·진행되는 축구/풋살 경기를 말합니다.</li>
<li><b>용병</b>: 특정 매치에 소속 팀 없이 또는 다른 팀의 경기에 임시로 참여하는 회원을 말합니다.</li>
<li><b>매너점수</b>: 회원의 스포츠맨십, 매너, 신뢰도 등을 수치로 표현한 점수를 말합니다.</li>
</ol>

<h4>제3조 (약관의 효력 및 변경)</h4>
<ol>
<li>이 약관은 서비스 화면에 게시하거나 기타의 방법으로 회원에게 공지함으로써 효력을 발생합니다.</li>
<li>회사는 관련 법령을 위배하지 않는 범위에서 이 약관을 개정할 수 있으며, 약관을 개정할 경우 적용일자 및 개정사유를 명시하여 현행 약관과 함께 서비스 내 공지합니다.</li>
<li>변경된 약관에 동의하지 않는 회원은 탈퇴를 요청할 수 있으며, 변경된 약관의 효력 발생일 이후에도 서비스를 계속 이용할 경우 변경된 약관에 동의한 것으로 봅니다.</li>
</ol>

<h4>제4조 (서비스 내용)</h4>
<p>회사가 제공하는 서비스의 주요 내용은 다음과 같습니다.</p>
<ol>
<li>팀 생성·관리 (초대코드, 멤버 관리, 직책 부여 등)</li>
<li>매치 생성·참여·취소 (일정, 장소, 참석 관리)</li>
<li>용병 모집·신청·매칭 (SOS 긴급 모집 포함)</li>
<li>매너점수 및 상호 평가 시스템</li>
<li>리그/시즌 운영 및 순위 관리</li>
<li>회비 관리 기능</li>
<li>메시지·알림·프로필 관리</li>
<li>기타 회사가 추가 개발하여 제공하는 일체의 서비스</li>
</ol>

<h4>제5조 (회원가입 및 계정관리)</h4>
<ol>
<li>이용자는 회사가 정한 가입 양식에 따라 회원정보를 기입한 후 이 약관에 동의한다는 의사표시를 함으로써 회원가입을 신청합니다.</li>
<li>회사는 다음 각 호에 해당하지 않는 한 회원가입을 승낙합니다.
  <ul>
  <li>가입 신청자가 이 약관에 의하여 이전에 회원자격을 상실한 적이 있는 경우</li>
  <li>등록 내용에 허위, 기재누락, 오기가 있는 경우</li>
  <li>기타 회원으로 등록하는 것이 서비스 운영에 현저히 지장이 있다고 판단되는 경우</li>
  </ul>
</li>
<li>회원은 자신의 계정 정보(전화번호, 비밀번호)를 안전하게 관리해야 하며, 이를 제3자에게 이용하게 해서는 안 됩니다.</li>
<li>회원은 계정이 도용되거나 제3자가 사용하고 있음을 인지한 경우 즉시 회사에 통지하여야 합니다.</li>
</ol>

<h4>제6조 (회원정보의 제공 및 변경)</h4>
<ol>
<li>회원은 서비스 이용 시 요구되는 정보를 정확하게 제공하여야 합니다.</li>
<li>회원정보에 변경이 있는 경우 즉시 마이페이지에서 수정하여야 하며, 미수정으로 인한 불이익은 회원에게 귀속됩니다.</li>
</ol>

<h4>제7조 (이용제한 및 계정정지)</h4>
<ol>
<li>회사는 회원이 이 약관의 의무를 위반하거나 서비스의 정상적인 운영을 방해한 경우, 경고·일시정지·영구이용정지 등으로 서비스 이용을 단계적으로 제한할 수 있습니다.</li>
<li>매너점수가 30° 이하로 하락한 회원은 7일간 활동(매치 참여, 용병 신청 등)이 제한될 수 있습니다.</li>
<li>회사는 이용제한 조치 시 그 사유, 제한 기간 등을 회원에게 알림으로 통지합니다.</li>
</ol>

<h4>제8조 (회원탈퇴 및 계정삭제)</h4>
<ol>
<li>회원은 언제든지 서비스 내 마이페이지를 통해 탈퇴를 요청할 수 있으며, 회사는 즉시 처리합니다.</li>
<li>탈퇴 시 회원의 개인정보는 관련 법령에 따른 보유기간이 경과한 후 파기합니다.</li>
<li>탈퇴 후에도 게시물(매치 기록, 평가 등)은 비식별화 처리되어 서비스 통계 목적으로 보존될 수 있습니다.</li>
</ol>

<h4>제9조 (매치/팀/용병 서비스 이용 규칙)</h4>
<ol>
<li>매치 생성 시 캡틴(또는 권한 있는 멤버)은 날짜, 시간, 장소, 참석 인원 등을 정확히 입력하여야 합니다.</li>
<li>매치에 참석 표시 후 무단으로 불참하는 행위(노쇼)는 매너점수 감점 사유에 해당합니다.</li>
<li>용병 신청 시 해당 매치의 조건(시간, 장소, 레벨 등)을 확인한 후 신청하여야 하며, 수락된 후 무단 불참 시 매너점수가 감점됩니다.</li>
<li>팀의 캡틴은 팀원 관리, 매치 관리, 회비 관리 등의 권한을 가지며, 권한을 타 팀원에게 양도할 수 있습니다.</li>
</ol>

<h4>제10조 (매너점수 및 신뢰도 운영)</h4>
<ol>
<li>매너점수는 매치 후 상호 평가, 노쇼 여부, 캡틴 평가 등을 종합하여 자동 산출됩니다.</li>
<li>매너점수는 서비스 내에서 회원의 신뢰도 지표로 활용되며, 매칭 우선순위, 용병 매칭 등에 영향을 미칠 수 있습니다.</li>
<li>허위 평가, 담합 평가 등 평가 시스템을 악용하는 행위는 금지되며, 적발 시 제재 대상이 됩니다.</li>
</ol>

<h4>제11조 (게시물/프로필사진/메시지)</h4>
<ol>
<li>회원이 서비스 내에 게시한 게시물(프로필 사진, 메시지, 후기 등)의 저작권은 해당 회원에게 귀속됩니다.</li>
<li>회사는 서비스 운영·개선·홍보 목적으로 회원의 게시물을 사용할 수 있으며, 이 경우 비식별화 처리합니다.</li>
<li>다음 각 호에 해당하는 게시물은 사전 통지 없이 삭제 또는 비공개 처리될 수 있습니다.
  <ul>
  <li>타인을 비방·모욕하는 내용</li>
  <li>음란·폭력적 내용</li>
  <li>광고·스팸성 내용</li>
  <li>개인정보를 무단으로 포함하는 내용</li>
  <li>기타 관련 법령에 위반되는 내용</li>
  </ul>
</li>
</ol>

<h4>제12조 (금지행위)</h4>
<p>회원은 서비스 이용 시 다음 각 호의 행위를 하여서는 안 됩니다.</p>
<ol>
<li>타인의 정보를 도용하거나 허위 정보를 등록하는 행위</li>
<li>서비스의 운영을 고의로 방해하는 행위</li>
<li>다른 회원을 괴롭히거나, 위협·비방·차별하는 행위</li>
<li>서비스를 통해 얻은 정보를 회사의 사전 승낙 없이 서비스 외 목적으로 이용하는 행위</li>
<li>회사의 지적재산권 또는 제3자의 권리를 침해하는 행위</li>
<li>경기 중 고의적인 폭력·위험 행위</li>
<li>매너점수 조작을 위한 담합·허위 평가 행위</li>
<li>상업적 광고·스팸을 게시하는 행위</li>
<li>기타 관련 법령에 위반되거나 공서양속에 반하는 행위</li>
</ol>

<h4>제13조 (유료기능 및 결제정책의 도입)</h4>
<ol>
<li>회사는 향후 프리미엄 기능, 광고 제거, 추가 통계 등 유료 서비스를 도입할 수 있습니다.</li>
<li>유료 서비스 도입 시 이용요금, 결제방법, 환불정책 등을 사전에 공지합니다.</li>
<li>유료 서비스 이용 중 결제 취소·환불은 관련 법령 및 회사 환불정책에 따릅니다.</li>
</ol>

<h4>제14조 (서비스 제공의 변경·중단)</h4>
<ol>
<li>회사는 서비스의 내용을 변경하거나 중단할 수 있으며, 이 경우 변경·중단 사유를 사전에 공지합니다.</li>
<li>천재지변, 시스템 장애 등 불가항력으로 인한 서비스 중단에 대해 회사는 책임을 지지 않습니다.</li>
</ol>

<h4>제15조 (회사의 면책)</h4>
<ol>
<li>회사는 회원 간 또는 회원과 제3자 간에 서비스를 매개로 발생한 분쟁에 대해 개입할 의무가 없으며, 이로 인한 손해를 배상할 책임을 지지 않습니다.</li>
<li>회사는 무료로 제공되는 서비스 이용과 관련하여 관련 법령에 특별한 규정이 없는 한 책임을 지지 않습니다.</li>
<li>회사는 회원이 서비스에 게재한 정보, 자료의 신뢰도, 정확성 등에 대해서는 책임을 지지 않습니다.</li>
<li>경기 중 발생한 부상, 재산 피해 등에 대해 회사는 책임을 지지 않습니다.</li>
</ol>

<h4>제16조 (손해배상)</h4>
<ol>
<li>회사 또는 회원이 이 약관을 위반하여 상대방에게 손해를 입힌 경우, 귀책사유 있는 당사자가 배상 책임을 부담합니다.</li>
<li>다만, 회사는 무료 서비스의 경우 법령에 특별한 규정이 없는 한 손해배상 의무를 부담하지 않습니다.</li>
</ol>

<h4>제17조 (분쟁 해결 및 관할)</h4>
<ol>
<li>서비스 이용과 관련하여 분쟁이 발생한 경우 회사와 회원은 분쟁의 해결을 위해 성실히 협의합니다.</li>
<li>협의가 이루어지지 않는 경우, 양 당사자는 민사소송법상의 관할법원에 소를 제기할 수 있습니다.</li>
<li>서비스 이용과 관련된 분쟁에 대해서는 대한민국 법령을 적용합니다.</li>
</ol>

<h4>부칙</h4>
<p>이 약관은 {$effectiveDate}부터 시행합니다.</p>
EOTXT;

    // ── 개인정보처리방침 ──
    $privacyTxt = <<<EOTXT
<h3 style="color:var(--primary);margin-bottom:12px">개인정보처리방침</h3>
<p style="font-size:12px;color:var(--text-sub);margin-bottom:16px">시행일: {$effectiveDate} | 운영: {$company}</p>

<p>{$company}(이하 "회사")는 개인정보보호법 등 관련 법령에 따라 이용자의 개인정보를 보호하고, 이와 관련한 고충을 신속하고 원활하게 처리하기 위하여 다음과 같은 개인정보 처리방침을 수립·공개합니다.</p>

<h4>1. 개인정보의 처리 목적</h4>
<p>회사는 다음의 목적을 위하여 개인정보를 처리합니다. 처리하고 있는 개인정보는 다음의 목적 이외의 용도로는 이용되지 않으며, 이용 목적이 변경되는 경우에는 별도의 동의를 받는 등 필요한 조치를 이행할 예정입니다.</p>
<ol>
<li><b>회원가입 및 관리</b>: 회원제 서비스 제공에 따른 본인 식별·인증, 회원자격 유지·관리, 서비스 부정이용 방지</li>
<li><b>서비스 제공</b>: 매치 매칭, 팀 관리, 용병 매칭, 매너점수 운영, 리그 운영, 회비 관리 등 핵심 서비스 제공</li>
<li><b>통계·분석</b>: 서비스 이용 통계 분석, 서비스 개선, 신규 서비스 개발</li>
<li><b>고충처리</b>: 민원사항 확인, 사실 확인을 위한 연락·통지, 처리결과 통보</li>
<li><b>마케팅(선택)</b>: 이벤트·프로모션 정보 제공, 서비스 관련 안내 (동의한 회원에 한함)</li>
</ol>

<h4>2. 처리하는 개인정보 항목</h4>
<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:12px">
<tr style="background:var(--bg-surface-alt)"><th style="border:1px solid var(--border);padding:6px">구분</th><th style="border:1px solid var(--border);padding:6px">항목</th></tr>
<tr><td style="border:1px solid var(--border);padding:6px"><b>필수</b></td><td style="border:1px solid var(--border);padding:6px">이름, 전화번호, 비밀번호(암호화), 가입일시</td></tr>
<tr><td style="border:1px solid var(--border);padding:6px"><b>선택</b></td><td style="border:1px solid var(--border);padding:6px">포지션, 활동지역(시/구), 프로필사진, 선수출신 여부, 자기소개, 소속팀 정보</td></tr>
<tr><td style="border:1px solid var(--border);padding:6px"><b>자동수집</b></td><td style="border:1px solid var(--border);padding:6px">접속 IP, 기기정보, 브라우저 종류, 접속 일시, 서비스 이용 기록, 쿠키</td></tr>
<tr><td style="border:1px solid var(--border);padding:6px"><b>향후 수집 가능</b></td><td style="border:1px solid var(--border);padding:6px">위치정보(위치기반서비스 제공 시), 결제정보(유료서비스 도입 시)</td></tr>
</table>

<h4>3. 처리 및 보유기간</h4>
<p>회사는 법령에 따른 개인정보 보유·이용기간 또는 정보주체로부터 개인정보를 수집 시 동의 받은 개인정보 보유·이용기간 내에서 개인정보를 처리·보유합니다.</p>
<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:12px">
<tr style="background:var(--bg-surface-alt)"><th style="border:1px solid var(--border);padding:6px">구분</th><th style="border:1px solid var(--border);padding:6px">보유기간</th><th style="border:1px solid var(--border);padding:6px">근거</th></tr>
<tr><td style="border:1px solid var(--border);padding:6px">회원 정보</td><td style="border:1px solid var(--border);padding:6px">회원탈퇴 시까지</td><td style="border:1px solid var(--border);padding:6px">서비스 이용계약</td></tr>
<tr><td style="border:1px solid var(--border);padding:6px">서비스 이용기록</td><td style="border:1px solid var(--border);padding:6px">3년</td><td style="border:1px solid var(--border);padding:6px">전자상거래법</td></tr>
<tr><td style="border:1px solid var(--border);padding:6px">접속 로그</td><td style="border:1px solid var(--border);padding:6px">3개월</td><td style="border:1px solid var(--border);padding:6px">통신비밀보호법</td></tr>
<tr><td style="border:1px solid var(--border);padding:6px">부정이용 기록</td><td style="border:1px solid var(--border);padding:6px">1년</td><td style="border:1px solid var(--border);padding:6px">서비스 운영정책</td></tr>
</table>

<h4>4. 제3자 제공</h4>
<p>회사는 원칙적으로 이용자의 개인정보를 제3자에게 제공하지 않습니다. 다만, 다음의 경우에는 예외로 합니다.</p>
<ol>
<li>이용자가 사전에 동의한 경우</li>
<li>법령의 규정에 의거하거나, 수사 목적으로 법령에 정해진 절차와 방법에 따라 수사기관의 요구가 있는 경우</li>
<li>매치 매칭 시 상대팀 캡틴에게 제공되는 최소 정보 (이름, 포지션, 매너점수 등 — 서비스 운영에 필수적인 범위)</li>
</ol>

<h4>5. 처리 위탁</h4>
<p>회사는 현재 개인정보 처리를 외부에 위탁하고 있지 않습니다. 향후 위탁이 필요한 경우, 위탁 대상자 및 위탁 업무 내용을 이 방침에 공개하고, 필요한 경우 사전 동의를 받겠습니다.</p>

<h4>6. 국외 이전</h4>
<p>회사는 이용자의 개인정보를 국외로 이전하지 않습니다. 향후 클라우드 서비스 등의 이유로 국외 이전이 필요한 경우, 관련 법령에 따라 이전 국가, 이전 일시, 이전되는 개인정보 항목 등을 사전에 고지하고 동의를 받겠습니다.</p>

<h4>7. 정보주체의 권리·의무</h4>
<ol>
<li>정보주체(회원)는 회사에 대해 언제든지 다음 각 호의 개인정보 보호 관련 권리를 행사할 수 있습니다.
  <ul>
  <li>개인정보 열람 요구</li>
  <li>개인정보 정정·삭제 요구</li>
  <li>개인정보 처리정지 요구</li>
  </ul>
</li>
<li>권리 행사는 서비스 내 마이페이지를 통해 직접 수행하거나, 회사 문의를 통해 서면·전자우편으로 할 수 있습니다.</li>
<li>만 14세 미만 아동의 개인정보를 처리하는 경우 법정대리인의 동의를 받아야 합니다. 회사는 만 14세 미만 아동의 가입을 제한하고 있습니다.</li>
</ol>

<h4>8. 파기 절차 및 방법</h4>
<ol>
<li>회사는 개인정보 보유기간의 경과, 처리 목적 달성 등 개인정보가 불필요하게 되었을 때에는 지체없이 해당 개인정보를 파기합니다.</li>
<li>파기 절차: 회원탈퇴 또는 보유기간 만료 → 별도 DB로 이동(일정 기간 보관 후) → 파기</li>
<li>파기 방법: 전자적 파일은 복구 불가능한 방법으로 영구 삭제하며, 종이에 출력된 정보는 분쇄기로 분쇄하거나 소각합니다.</li>
</ol>

<h4>9. 안전성 확보조치</h4>
<p>회사는 개인정보의 안전성 확보를 위해 다음과 같은 조치를 취합니다.</p>
<ol>
<li>비밀번호의 암호화 저장 (bcrypt 해시)</li>
<li>해킹 등에 대비한 접근 제한 및 접근 로그 관리</li>
<li>HTTPS(SSL/TLS) 암호화 통신</li>
<li>CSRF 토큰을 통한 요청 위변조 방지</li>
<li>개인정보 접근 권한의 최소화</li>
<li>정기적 보안 점검</li>
</ol>

<h4>10. 쿠키/로그/기기정보</h4>
<ol>
<li>회사는 서비스 이용 과정에서 쿠키(Cookie)를 사용하여 세션 관리를 수행합니다.</li>
<li>이용자는 브라우저 설정을 통해 쿠키 저장을 거부할 수 있으나, 이 경우 로그인이 필요한 서비스 이용에 제한이 있을 수 있습니다.</li>
<li>접속 로그, IP주소, 기기정보, 브라우저 정보는 서비스 안정성 확보 및 부정이용 방지를 위해 자동으로 수집·보관됩니다.</li>
</ol>

<h4>11. 프로필사진/메시지/후기</h4>
<ol>
<li>회원이 업로드한 프로필사진은 서비스 내에서 다른 회원에게 공개될 수 있습니다.</li>
<li>메시지는 회원 간 1:1로 전송되며, 회사는 서비스 운영 목적 외에는 메시지 내용을 열람하지 않습니다.</li>
<li>매치 후기 및 평가 내용은 매치 참여자들에게 공개되며, 비식별화하여 통계 목적으로 활용할 수 있습니다.</li>
</ol>

<h4>12. 개인정보 보호책임자 및 문의처</h4>
<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:12px">
<tr><td style="border:1px solid var(--border);padding:6px"><b>개인정보 보호책임자</b></td><td style="border:1px solid var(--border);padding:6px">{$company} 운영팀</td></tr>
<tr><td style="border:1px solid var(--border);padding:6px"><b>문의</b></td><td style="border:1px solid var(--border);padding:6px">앱 내 문의 기능 또는 서비스 공식 채널</td></tr>
</table>

<h4>13. 권익침해 구제방법</h4>
<p>이용자는 개인정보 침해에 대한 피해구제, 상담 등을 아래 기관에 문의할 수 있습니다.</p>
<ul>
<li>개인정보침해신고센터 (한국인터넷진흥원): privacy.kisa.or.kr / 118</li>
<li>개인정보 분쟁조정위원회: kopico.go.kr / 1833-6972</li>
<li>대검찰청 사이버수사과: spo.go.kr / 1301</li>
<li>경찰청 사이버안전국: cyberbureau.police.go.kr / 182</li>
</ul>

<h4>14. 방침 변경</h4>
<ol>
<li>이 개인정보처리방침은 법령·정책 또는 보안기술의 변경에 따라 내용의 추가·삭제 및 수정이 있을 시에는 변경사항의 시행 7일 전부터 서비스 공지사항을 통해 고지합니다.</li>
<li>이 방침은 {$effectiveDate}부터 적용됩니다.</li>
</ol>
EOTXT;

    // ── 위치기반서비스 이용약관 ──
    $locationTxt = <<<EOTXT
<h3 style="color:var(--primary);margin-bottom:12px">위치기반서비스 이용약관</h3>
<p style="font-size:12px;color:var(--text-sub);margin-bottom:16px">시행일: {$effectiveDate} | 운영: {$company}</p>

<h4>제1조 (목적)</h4>
<p>이 약관은 {$company}(이하 "회사")가 제공하는 위치기반서비스(이하 "위치서비스")에 대해 회사와 이용자의 권리·의무 및 책임사항을 규정함을 목적으로 합니다.</p>

<h4>제2조 (정의)</h4>
<ol>
<li><b>위치정보</b>: 이동성이 있는 물건 또는 개인이 특정한 시간에 존재하거나 존재하였던 장소에 관한 정보를 말합니다.</li>
<li><b>개인위치정보</b>: 특정 개인의 위치정보(위치정보만으로는 특정 개인의 위치를 알 수 없는 경우에도 다른 정보와 용이하게 결합하여 특정 개인의 위치를 알 수 있는 것을 포함)를 말합니다.</li>
<li><b>위치기반서비스</b>: 개인위치정보를 이용한 서비스를 말합니다.</li>
<li><b>개인위치정보주체</b>: 개인위치정보에 의하여 식별되는 자를 말합니다.</li>
</ol>

<h4>제3조 (약관 외 준칙)</h4>
<p>이 약관에 명시되지 않은 사항에 대해서는 위치정보의 보호 및 이용 등에 관한 법률, 정보통신망 이용촉진 및 정보보호 등에 관한 법률, 개인정보보호법 등 관련 법령의 규정에 따릅니다.</p>

<h4>제4조 (서비스의 내용)</h4>
<p>회사가 제공하는 위치기반서비스는 다음과 같습니다.</p>
<ol>
<li><b>주변 매치/구장 검색</b>: 이용자의 현재 위치를 기반으로 주변 매치 일정, 구장 정보를 안내하는 서비스</li>
<li><b>경기장 위치 확인</b>: 매치가 예정된 경기장의 위치를 지도에 표시하고 길찾기를 제공하는 서비스</li>
<li><b>지역 기반 매칭</b>: 이용자의 위치를 기반으로 가까운 지역의 팀·용병을 우선 추천하는 서비스</li>
<li><b>경기장 인증</b>: GPS를 통해 실제 경기장 도착 여부를 확인하는 서비스</li>
</ol>

<h4>제5조 (서비스 이용요금)</h4>
<p>회사가 제공하는 위치기반서비스는 현재 무료이며, 향후 유료 전환 시 사전에 고지합니다.</p>

<h4>제6조 (개인위치정보의 이용 또는 제공)</h4>
<ol>
<li>회사는 개인위치정보를 이용하여 제4조의 서비스를 제공하며, 해당 서비스 제공에 필수적인 범위 내에서만 개인위치정보를 이용합니다.</li>
<li>회사는 이용자의 동의 없이 개인위치정보를 제3자에게 제공하지 않습니다. 다만, 매치 매칭 시 상대팀에게 경기장 위치 정보를 공유하는 것은 서비스 제공에 필수적인 범위에 해당합니다.</li>
<li>회사는 개인위치정보를 이용자가 지정하는 제3자에게 제공하는 경우, 매회 개인위치정보주체에게 제공받는 자, 제공 일시 및 제공 목적을 즉시 통지합니다.</li>
</ol>

<h4>제7조 (개인위치정보의 보유 및 이용기간)</h4>
<ol>
<li>회사는 위치기반서비스를 제공하는 동안에만 이용자의 개인위치정보를 보유하며, 서비스 제공 후 지체 없이 파기합니다.</li>
<li>다만, 관련 법령(통신비밀보호법 등)에 따라 일정 기간 보관이 필요한 경우에는 해당 기간 동안 보관합니다.</li>
<li>이용자가 동의를 철회하면 회사는 지체 없이 수집된 개인위치정보를 파기합니다.</li>
</ol>

<h4>제8조 (개인위치정보주체의 권리)</h4>
<ol>
<li>이용자는 언제든지 개인위치정보의 수집·이용·제공에 대한 동의를 전부 또는 일부 철회할 수 있습니다.</li>
<li>이용자는 언제든지 개인위치정보의 수집·이용·제공의 일시적 중지를 요구할 수 있습니다.</li>
<li>이용자는 회사에 대하여 다음 자료의 열람 또는 고지를 요구할 수 있으며, 해당 자료에 오류가 있는 경우 정정을 요구할 수 있습니다.
  <ul>
  <li>이용자에 대한 위치정보 수집·이용·제공 사실 확인 자료</li>
  <li>이용자의 개인위치정보가 제3자에게 제공된 경우, 그 이유 및 내용</li>
  </ul>
</li>
</ol>

<h4>제9조 (법정대리인의 권리)</h4>
<ol>
<li>회사는 만 14세 미만의 아동에 대해서는 보호의무자의 동의를 얻지 않고 개인위치정보를 수집·이용·제공하지 않습니다.</li>
<li>보호의무자는 만 14세 미만 아동의 개인위치정보에 대한 동의 철회, 열람·고지 요구 등의 권리를 행사할 수 있습니다.</li>
</ol>

<h4>제10조 (위치정보관리책임자)</h4>
<p>회사는 위치정보를 적절히 관리·보호하고, 이용자의 불만을 원활히 처리할 수 있도록 위치정보관리책임자를 다음과 같이 지정합니다.</p>
<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:12px">
<tr><td style="border:1px solid var(--border);padding:6px"><b>위치정보관리책임자</b></td><td style="border:1px solid var(--border);padding:6px">{$company} 운영팀</td></tr>
<tr><td style="border:1px solid var(--border);padding:6px"><b>문의</b></td><td style="border:1px solid var(--border);padding:6px">앱 내 문의 기능 또는 서비스 공식 채널</td></tr>
</table>

<h4>제11조 (손해배상)</h4>
<p>회사의 위치정보의 보호 및 이용 등에 관한 법률 제15조 내지 제26조의 규정을 위반한 행위로 손해를 입은 경우에 이용자는 회사에 대하여 손해배상을 청구할 수 있습니다. 이 경우 회사는 고의 또는 과실이 없음을 입증하지 아니하면 책임을 면할 수 없습니다.</p>

<h4>제12조 (분쟁의 조정 및 기타)</h4>
<ol>
<li>회사 또는 이용자는 위치정보와 관련된 분쟁에 대해 당사자 간 협의가 이루어지지 아니하거나 협의를 할 수 없는 경우에는 방송통신위원회에 재정을 신청하거나 전기통신분쟁조정위원회에 조정을 신청할 수 있습니다.</li>
<li>회사 또는 이용자는 위치정보와 관련된 분쟁에 대해 관할법원에 소를 제기할 수 있습니다.</li>
</ol>

<h4>부칙</h4>
<ol>
<li>이 약관은 {$effectiveDate}부터 시행합니다.</li>
<li>위치기반서비스는 향후 서비스 업데이트 시 순차적으로 제공될 예정이며, 제공 시점은 서비스 내 공지를 통해 안내합니다.</li>
</ol>
EOTXT;

    $tabs = [
        'tos'      => ['label' => '이용약관',        'content' => $tosTxt],
        'privacy'  => ['label' => '개인정보처리방침', 'content' => $privacyTxt],
        'location' => ['label' => '위치기반서비스',   'content' => $locationTxt],
    ];
?>
<div class="container" style="max-width:520px">
  <div style="text-align:center;padding:20px 0 16px">
    <div style="font-size:36px;margin-bottom:6px">&#x1F4DC;</div>
    <div style="font-size:18px;font-weight:700">법적 고지</div>
    <div style="font-size:12px;color:var(--text-sub);margin-top:4px">TRUST FOOTBALL 서비스 이용 관련 약관</div>
  </div>

  <!-- 탭 버튼 -->
  <div style="display:flex;gap:4px;margin-bottom:14px;overflow-x:auto;-webkit-overflow-scrolling:touch">
    <?php foreach ($tabs as $key => $t): ?>
    <a href="?page=terms&tab=<?=$key?>"
       style="flex:1;min-width:0;text-align:center;padding:10px 6px;border-radius:10px;font-size:12px;font-weight:600;text-decoration:none;white-space:nowrap;
              <?=$tab===$key ? 'background:var(--primary);color:#000' : 'background:var(--bg-surface-alt);color:var(--text-sub);border:1px solid var(--border)'?>">
      <?=h($t['label'])?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- 본문 -->
  <div class="card"><div class="card-body" style="padding:16px;max-height:70vh;overflow-y:auto;-webkit-overflow-scrolling:touch">
    <div class="terms-content">
      <?= $tabs[$tab]['content'] ?? $tabs['tos']['content'] ?>
    </div>
  </div></div>

  <div style="text-align:center;margin:16px 0 40px">
    <?php if(me()): ?>
    <a href="?page=home" class="btn btn-outline" style="font-size:13px;padding:10px 24px">홈으로</a>
    <?php else: ?>
    <a href="?page=login" class="btn btn-outline" style="font-size:13px;padding:10px 24px">로그인으로</a>
    <?php endif; ?>
  </div>
</div>
<style>
.terms-content h4 { color:var(--primary);font-size:14px;font-weight:700;margin:18px 0 8px;padding-top:8px;border-top:1px solid var(--border) }
.terms-content h4:first-of-type { border-top:none;margin-top:8px }
.terms-content p  { font-size:13px;line-height:1.8;color:var(--text);margin-bottom:10px }
.terms-content ol, .terms-content ul { font-size:13px;line-height:1.8;color:var(--text);padding-left:20px;margin-bottom:10px }
.terms-content li { margin-bottom:4px }
.terms-content table { margin-top:4px }
.terms-content b { color:var(--text) }
</style>
<?php }

// ═══════════════════════════════════════════════════════════════
// [지난 경기 히스토리] ?page=history
// 팀 소속으로 뛴 경기 + 용병으로 참여한 경기 통합 + 월별 그룹핑
// 기본: 최근 6개월 · 모든 상태 / 필터로 기간/구분 변경 가능
// ═══════════════════════════════════════════════════════════════
function pagHistory(PDO $pdo): void {
    requireLogin();
    $uid = (int)me()['id'];
    // 필터: period(6m 기본 / 1y / all) / kind(all/team/merc) / result(all/completed/cancelled)
    $period = $_GET['period'] ?? '6m';
    $kind   = $_GET['kind']   ?? 'all';
    $result = $_GET['result'] ?? 'all';

    $dateFrom = match($period) {
        '1m'  => date('Y-m-d', strtotime('-1 month')),
        '3m'  => date('Y-m-d', strtotime('-3 months')),
        '1y'  => date('Y-m-d', strtotime('-1 year')),
        'all' => '2000-01-01',
        default => date('Y-m-d', strtotime('-6 months')),
    };

    // kind 필터 — match_player_records.is_mercenary 기준
    $kindWhere = '';
    if     ($kind === 'team') $kindWhere = ' AND mpr.is_mercenary = 0';
    elseif ($kind === 'merc') $kindWhere = ' AND mpr.is_mercenary = 1';

    // result 필터 — matches.status 기준
    $resultWhere = ' AND (m.status IN (\'completed\',\'cancelled\',\'force_closed\') OR m.match_date < CURDATE())';
    if     ($result === 'completed') $resultWhere = ' AND m.status = \'completed\'';
    elseif ($result === 'cancelled') $resultWhere = ' AND m.status IN (\'cancelled\',\'force_closed\')';

    // [쿼리] match_attendance + match_player_records UNION — 참석 투표만 한 경기도 보이도록
    $kindWhereAtt = '';
    if ($kind === 'team') $kindWhereAtt = " AND COALESCE(mpr.is_mercenary,0) = 0";
    elseif ($kind === 'merc') $kindWhereAtt = " AND mpr.is_mercenary = 1";
    $sql = "
        SELECT DISTINCT m.id AS match_id,
               COALESCE(mpr.is_mercenary, 0) AS is_mercenary,
               COALESCE(mpr.goals, 0) AS goals,
               COALESCE(mpr.assists, 0) AS assists,
               COALESCE(mpr.is_checked_in, 0) AS is_checked_in,
               m.title, m.match_date, m.match_time, m.location, m.status, m.uniform_color,
               m.home_team_id, m.away_team_id,
               ht.name AS home_name, at.name AS away_name,
               COALESCE(t.name, t2.name) AS played_team_name,
               COALESCE(mpr.team_id, ma.team_id) AS played_team_id,
               res.score_home, res.score_away
        FROM match_attendance ma
        JOIN matches m ON m.id = ma.match_id
        LEFT JOIN match_player_records mpr ON mpr.match_id = ma.match_id AND mpr.user_id = ma.user_id
        LEFT JOIN teams ht ON ht.id = m.home_team_id
        LEFT JOIN teams at ON at.id = m.away_team_id
        LEFT JOIN teams t  ON t.id = mpr.team_id
        LEFT JOIN teams t2 ON t2.id = ma.team_id
        LEFT JOIN match_results res ON res.match_id = m.id
        WHERE ma.user_id = ? AND ma.status = 'ATTEND'
          AND m.match_date >= ?
          $kindWhereAtt
          $resultWhere
        ORDER BY m.match_date DESC, m.match_time DESC
        LIMIT 200
    ";
    $q = $pdo->prepare($sql);
    $q->execute([$uid, $dateFrom]);
    $rows = $q->fetchAll();

    // [월별 그룹핑] YYYY-MM 키로 묶음
    $grouped = [];
    $totalGoals = 0; $totalAssists = 0; $totalMatches = 0;
    foreach ($rows as $r) {
        $monthKey = substr($r['match_date'], 0, 7);
        $grouped[$monthKey][] = $r;
        $totalMatches++;
        $totalGoals   += (int)$r['goals'];
        $totalAssists += (int)$r['assists'];
    }

    $periodLabels = ['1m'=>'1개월','3m'=>'3개월','6m'=>'6개월','1y'=>'1년','all'=>'전체'];
    $kindLabels   = ['all'=>'전체','team'=>'🛡 팀','merc'=>'⚡ 용병'];
    $resultLabels = ['all'=>'전체','completed'=>'완료','cancelled'=>'취소'];
?>
<div class="container">
  <!-- 헤더 + 뒤로가기 -->
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
    <a href="?page=mypage" style="color:var(--text-sub);text-decoration:none"><i class="bi bi-arrow-left" style="font-size:18px"></i></a>
    <h2 style="font-size:20px;font-weight:700;margin:0"><i class="bi bi-clock-history"></i> 내 경기 기록</h2>
  </div>

  <!-- 요약 카드 -->
  <div class="stat-grid" style="margin-bottom:12px">
    <div class="stat-box"><div class="stat-val"><?=$totalMatches?></div><div class="stat-lbl">총 경기</div></div>
    <div class="stat-box"><div class="stat-val" style="color:#ffb400"><?=$totalGoals?></div><div class="stat-lbl">득점</div></div>
    <div class="stat-box"><div class="stat-val" style="color:#3a9ef5"><?=$totalAssists?></div><div class="stat-lbl">도움</div></div>
  </div>

  <!-- 기간 필터 -->
  <div class="chip-row" style="margin-bottom:6px">
    <?php foreach ($periodLabels as $pk => $pl): ?>
    <a href="?page=history&period=<?=$pk?>&kind=<?=h($kind)?>&result=<?=h($result)?>" class="chip <?=$period===$pk?'active':''?>" style="font-size:11px"><?=$pl?></a>
    <?php endforeach; ?>
  </div>
  <!-- 구분 필터 -->
  <div class="chip-row" style="margin-bottom:6px">
    <?php foreach ($kindLabels as $kk => $kl): ?>
    <a href="?page=history&period=<?=h($period)?>&kind=<?=$kk?>&result=<?=h($result)?>" class="chip <?=$kind===$kk?'active':''?>" style="font-size:11px"><?=$kl?></a>
    <?php endforeach; ?>
  </div>
  <!-- 결과 필터 -->
  <div class="chip-row" style="margin-bottom:14px">
    <?php foreach ($resultLabels as $rk => $rl): ?>
    <a href="?page=history&period=<?=h($period)?>&kind=<?=h($kind)?>&result=<?=$rk?>" class="chip <?=$result===$rk?'active':''?>" style="font-size:11px"><?=$rl?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$grouped): ?>
  <div class="card"><div class="card-body" style="text-align:center;padding:40px 20px;color:var(--text-sub)">
    <div style="font-size:40px;margin-bottom:10px">📭</div>
    <p>해당 조건의 경기 기록이 없습니다.</p>
  </div></div>
  <?php else: ?>
  <?php foreach ($grouped as $ym => $items):
    [$y, $m] = explode('-', $ym);
  ?>
  <!-- [월별 그룹] -->
  <div style="position:sticky;top:56px;background:var(--bg);z-index:10;padding:8px 2px;margin-top:10px">
    <div style="display:flex;align-items:center;gap:8px">
      <div style="font-size:14px;font-weight:700"><?=$y?>년 <?=(int)$m?>월</div>
      <span style="font-size:11px;color:var(--text-sub)"><?=count($items)?>경기</span>
    </div>
  </div>
  <div class="card"><div class="card-body" style="padding:0 14px">
    <?php foreach ($items as $mh):
      $scoreStr = ($mh['score_home'] !== null && $mh['score_away'] !== null)
        ? ((int)$mh['score_home'].' : '.(int)$mh['score_away']) : null;
      $title    = $mh['title'] ?: (($mh['home_name'] ?? '?').' vs '.($mh['away_name'] ?? '?'));
      $isCancel = in_array($mh['status'], ['cancelled','force_closed'], true);
      $statusLbl = match($mh['status']){
        'cancelled'    => '취소',
        'force_closed' => '강제종료',
        'completed'    => '완료',
        default        => '과거',
      };
      $statusCol = $isCancel ? '#ff4d6d' : ($mh['status']==='completed' ? 'var(--primary)' : 'var(--text-sub)');
      // 내가 뛴 팀이 이긴/진/무 결과
      $wdl = null;
      if (!$isCancel && $scoreStr && $mh['played_team_id']) {
        $homeIsMine = ((int)$mh['home_team_id'] === (int)$mh['played_team_id']);
        $myScore    = $homeIsMine ? (int)$mh['score_home'] : (int)$mh['score_away'];
        $oppScore   = $homeIsMine ? (int)$mh['score_away'] : (int)$mh['score_home'];
        if     ($myScore > $oppScore) $wdl = ['승','#00ff88'];
        elseif ($myScore < $oppScore) $wdl = ['패','#ff4d6d'];
        else                          $wdl = ['무','#ffb400'];
      }
    ?>
    <a href="?page=match&id=<?=(int)$mh['match_id']?>" style="display:flex;gap:8px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.05);color:inherit;text-decoration:none">
      <?= uniformDot($mh['uniform_color'] ?? '', 14) ?>
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:2px">
          <?php if ((int)$mh['is_mercenary'] === 1): ?>
          <span class="badge" style="background:rgba(255,180,0,0.15);color:#ffb400;font-size:9px">⚡ 용병</span>
          <?php else: ?>
          <span class="badge" style="background:rgba(0,255,136,0.12);color:var(--primary);font-size:9px">🛡 팀</span>
          <?php endif; ?>
          <?php if ($wdl): ?>
          <span class="badge" style="background:rgba(0,0,0,0.3);color:<?=$wdl[1]?>;font-size:9px;font-weight:700"><?=$wdl[0]?></span>
          <?php endif; ?>
          <span style="color:<?=$statusCol?>;font-size:9px;font-weight:600"><?=$statusLbl?></span>
          <span style="font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($title)?></span>
        </div>
        <div style="font-size:10px;color:var(--text-sub)">
          <?=$mh['match_date']?> · <?=h($mh['played_team_name'] ?? '-')?>
          <?php if($mh['location']): ?> · <?=h(mb_substr($mh['location'],0,20,'UTF-8'))?><?php endif; ?>
        </div>
      </div>
      <div style="text-align:right;flex-shrink:0">
        <?php if ($scoreStr): ?>
        <div style="font-size:13px;font-family:'Space Grotesk',sans-serif;font-weight:700"><?=$scoreStr?></div>
        <?php endif; ?>
        <div style="font-size:10px;color:var(--text-sub)">⚽<?=(int)$mh['goals']?> 🎯<?=(int)$mh['assists']?></div>
      </div>
    </a>
    <?php endforeach; ?>
  </div></div>
  <?php endforeach; endif; ?>

  <div style="font-size:11px;color:var(--text-sub);text-align:center;padding:16px 0">최대 200건 표시</div>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// [어드민 MVP] 인앱 관리 페이지 — 서브탭 구조
// ?page=admin&tab=dashboard|verify|reports|matches|users
// 접근: ADMIN | SUPER_ADMIN (isAnyAdmin)
// 일부 액션은 SUPER_ADMIN 전용 (매치 강제 / 영구 정지 / 대폭 매너 조정)
// ═══════════════════════════════════════════════════════════════
function pagAdmin(PDO $pdo): void {
    // [가드] 권한 체크 — 일반 유저는 홈으로 리다이렉트
    if (!isAnyAdmin()) {
        flash('관리자만 접근 가능합니다.', 'error');
        redirect('?page=home');
    }

    // [라우팅] 서브탭 파라미터 (기본: dashboard)
    $allowedTabs = ['dashboard','verify','reports','matches','users','feedback','bugs'];
    $tab = $_GET['tab'] ?? 'dashboard';
    if (!in_array($tab, $allowedTabs, true)) $tab = 'dashboard';

    // [공통] 각 섹션의 대기 건수 (탭 네비 배지용 — 한 번에 조회)
    $pendingCounts = [
        'verify'   => (int)$pdo->query("SELECT COUNT(*) FROM venue_verifications WHERE status='PENDING'")->fetchColumn(),
        'reports'  => (int)$pdo->query("SELECT COUNT(*) FROM reports WHERE status='PENDING'")->fetchColumn(),
        'matches'  => (int)$pdo->query("SELECT COUNT(*) FROM matches WHERE status IN ('disputed','result_pending')")->fetchColumn(),
        'feedback' => (int)$pdo->query("SELECT COUNT(*) FROM feedback WHERE status='NEW'")->fetchColumn(),
        'bugs'     => (int)$pdo->query("SELECT COUNT(*) FROM bug_reports WHERE status='pending'")->fetchColumn(),
    ];
?>
<div class="container">
  <!-- 헤더 -->
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
    <i class="bi bi-shield-lock-fill" style="color:#ff4d6d;font-size:22px"></i>
    <h2 style="font-size:20px;font-weight:700;margin:0">관리</h2>
    <?php if (isSuperAdmin()): ?>
    <span class="badge" style="background:#ff4d6d;color:#fff;font-size:10px;margin-left:4px">SUPER</span>
    <?php else: ?>
    <span class="badge" style="background:#3a9ef5;color:#fff;font-size:10px;margin-left:4px">ADMIN</span>
    <?php endif; ?>
  </div>

  <!-- 서브탭 네비 -->
  <div class="chip-row" style="margin-bottom:14px">
    <?php
      $tabInfo = [
        'dashboard' => ['대시보드', null],
        'verify'    => ['인증', $pendingCounts['verify']],
        'reports'   => ['신고', $pendingCounts['reports']],
        'matches'   => ['매치', $pendingCounts['matches']],
        'users'     => ['유저', null],
        'feedback'  => ['피드백', $pendingCounts['feedback']],
        'bugs'      => ['버그', $pendingCounts['bugs']],
      ];
      foreach ($tabInfo as $tk => [$label, $badgeNum]):
    ?>
    <a href="?page=admin&tab=<?=$tk?>" class="chip <?=$tab===$tk?'active':''?>" style="position:relative">
      <?=$label?>
      <?php if ($badgeNum > 0): ?>
      <span class="badge badge-red" style="font-size:9px;margin-left:4px;padding:1px 5px"><?=$badgeNum?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if ($tab === 'dashboard'):
    // ── 대시보드 탭 ───────────────────────────────
    // 통계 카드 8종 (실시간 COUNT)
    $s = [
      'users'         => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
      'teams'         => (int)$pdo->query("SELECT COUNT(*) FROM teams WHERE status != 'BANNED'")->fetchColumn(),
      'matches'       => (int)$pdo->query("SELECT COUNT(*) FROM matches WHERE status NOT IN ('cancelled','force_closed')")->fetchColumn(),
      'today_matches' => (int)$pdo->query("SELECT COUNT(*) FROM matches WHERE match_date = CURDATE() AND status NOT IN ('cancelled','force_closed')")->fetchColumn(),
      'pending_report'=> $pendingCounts['reports'],
      'pending_verify'=> $pendingCounts['verify'],
      'result_pending'=> (int)$pdo->query("SELECT COUNT(*) FROM matches WHERE status='result_pending'")->fetchColumn(),
      'restricted'    => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE restricted_until IS NOT NULL AND restricted_until > NOW()")->fetchColumn(),
    ];
    // 대기 건수가 있는 항목은 빨간색 + 해당 탭으로 링크
    $cards = [
      ['전체 유저',       $s['users'],         'var(--primary)', null],
      ['전체 팀',         $s['teams'],         '#3a9ef5',        null],
      ['전체 매치',       $s['matches'],       '#ffb400',        null],
      ['오늘 경기',       $s['today_matches'], '#00ff88',        null],
      ['신고 대기',       $s['pending_report'],'#ff4d6d',        $s['pending_report']>0 ? 'reports' : null],
      ['인증 대기',       $s['pending_verify'],'#ff4d6d',        $s['pending_verify']>0 ? 'verify'  : null],
      ['결과 승인 대기',   $s['result_pending'],'#ff9500',        $s['result_pending']>0 ? 'matches' : null],
      ['제한 유저',       $s['restricted'],    '#ff9500',        $s['restricted']>0    ? 'users'   : null],
    ];
  ?>
  <div class="stat-grid" style="grid-template-columns:1fr 1fr">
    <?php foreach ($cards as [$label, $val, $color, $linkTab]):
      $clickable = $linkTab !== null;
    ?>
    <?php if ($clickable): ?>
    <a href="?page=admin&tab=<?=$linkTab?>" class="stat-box" style="text-decoration:none;display:block;cursor:pointer">
    <?php else: ?>
    <div class="stat-box">
    <?php endif; ?>
      <div class="stat-val" style="color:<?=$color?>;"><?=$val?></div>
      <div class="stat-lbl" style="display:flex;align-items:center;justify-content:center;gap:4px">
        <?=$label?>
        <?php if ($clickable): ?><i class="bi bi-arrow-right-short" style="color:<?=$color?>"></i><?php endif; ?>
      </div>
    <?=$clickable ? '</a>' : '</div>'?>
    <?php endforeach; ?>
  </div>

  <!-- 최근 어드민 로그 (최신 5건) -->
  <p class="section-title" style="margin-top:20px"><i class="bi bi-clock-history"></i> 최근 어드민 액션</p>
  <?php
    $recentLogs = $pdo->query("
      SELECT al.*, COALESCE(NULLIF(TRIM(u.nickname),''), u.name) AS admin_name
      FROM admin_logs al JOIN users u ON u.id=al.admin_id
      ORDER BY al.created_at DESC LIMIT 5
    ")->fetchAll();
  ?>
  <?php if (!$recentLogs): ?>
  <div class="card"><div class="card-body" style="color:var(--text-sub);text-align:center;padding:16px;font-size:12px">아직 기록된 액션 없음</div></div>
  <?php else: ?>
  <div class="card"><div class="card-body" style="padding:0 14px">
    <?php foreach ($recentLogs as $log): ?>
    <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.06);font-size:12px">
      <div>
        <span style="color:var(--primary);font-weight:600"><?=h($log['admin_name'])?></span>
        · <span style="color:var(--text-sub)"><?=h($log['action_type'])?></span>
        <?php if ($log['target_type']): ?>
        <span style="color:var(--text-sub)">→ <?=h($log['target_type'])?>#<?=$log['target_id']?></span>
        <?php endif; ?>
      </div>
      <div style="color:var(--text-sub);font-size:11px;flex-shrink:0"><?=timeAgo($log['created_at'])?></div>
    </div>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>

  <?php elseif ($tab === 'verify'):
    // ── 인증(verify) 탭 ───────────────────────────────
    // 필터: all | pending(기본) | hold | done(VERIFIED+REJECTED)
    $vFilter = $_GET['vf'] ?? 'pending';
    $vFilterMap = [
      'pending' => ['PENDING'],
      'hold'    => ['HOLD'],
      'done'    => ['VERIFIED','REJECTED'],
      'all'     => ['PENDING','HOLD','VERIFIED','REJECTED'],
    ];
    $statusList = $vFilterMap[$vFilter] ?? $vFilterMap['pending'];
    $ph = implode(',', array_fill(0, count($statusList), '?'));
    $vq = $pdo->prepare("
      SELECT vv.id AS verify_id, vv.receipt_image_url, vv.status AS vstatus, vv.created_at AS submitted_at,
             u.id AS submitter_id, u.name AS submitter_name, u.nickname AS submitter_nick,
             m.id AS match_id, m.title AS match_title, m.match_date, m.location,
             m.venue_verified
      FROM venue_verifications vv
      JOIN users u ON u.id=vv.submitted_by
      LEFT JOIN matches m ON m.id=vv.match_id
      WHERE vv.status IN ($ph)
      ORDER BY FIELD(vv.status,'PENDING','HOLD','VERIFIED','REJECTED'), vv.created_at DESC
      LIMIT 50
    ");
    $vq->execute($statusList);
    $verifyList = $vq->fetchAll();
    $filterLabels = ['pending'=>'대기중','hold'=>'보류','done'=>'처리완료','all'=>'전체'];
  ?>
  <!-- 필터 칩 -->
  <div class="chip-row" style="margin-bottom:10px">
    <?php foreach ($filterLabels as $fk => $fl): ?>
    <a href="?page=admin&tab=verify&vf=<?=$fk?>" class="chip <?=$vFilter===$fk?'active':''?>" style="font-size:11px"><?=$fl?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$verifyList): ?>
  <div class="card"><div class="card-body" style="color:var(--text-sub);text-align:center;padding:20px;font-size:12px">
    <?=h($filterLabels[$vFilter])?> 항목이 없습니다.
  </div></div>
  <?php else:
    $statusBadgeColor = [
      'PENDING'  => ['background:rgba(255,184,0,0.15);color:#ffb400', '대기'],
      'HOLD'     => ['background:rgba(255,149,0,0.15);color:#ff9500', '보류'],
      'VERIFIED' => ['background:rgba(0,255,136,0.15);color:var(--primary)', '승인'],
      'REJECTED' => ['background:rgba(255,77,109,0.15);color:#ff4d6d', '거절'],
    ];
    foreach ($verifyList as $v):
      $bc = $statusBadgeColor[$v['vstatus']] ?? ['background:rgba(255,255,255,0.06);color:var(--text-sub)', $v['vstatus']];
  ?>
  <div class="card" style="margin-bottom:10px"><div class="card-body">
    <div style="display:flex;gap:10px;align-items:flex-start">
      <!-- 영수증 이미지 썸네일 (클릭하면 새 탭에서 원본) -->
      <?php if (!empty($v['receipt_image_url'])): ?>
      <a href="<?=h($v['receipt_image_url'])?>" target="_blank" rel="noopener" style="flex-shrink:0">
        <img src="<?=h($v['receipt_image_url'])?>" alt="영수증"
             style="width:76px;height:76px;object-fit:cover;border-radius:8px;background:#000;border:1px solid rgba(255,255,255,0.08)">
      </a>
      <?php else: ?>
      <div style="width:76px;height:76px;border-radius:8px;background:rgba(0,0,0,0.3);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--text-sub);font-size:10px">이미지 없음</div>
      <?php endif; ?>

      <!-- 본문 -->
      <div style="flex:1;min-width:0">
        <div style="display:flex;gap:6px;align-items:center;margin-bottom:4px">
          <span class="badge" style="<?=$bc[0]?>;font-size:10px"><?=$bc[1]?></span>
          <?php if ($v['venue_verified']): ?>
          <span class="badge" style="background:rgba(0,255,136,0.12);color:var(--primary);font-size:10px">✓ 구장 인증됨</span>
          <?php endif; ?>
        </div>
        <div style="font-weight:700;font-size:13px;line-height:1.4"><?=h($v['match_title'] ?? '(매치 삭제됨)')?></div>
        <div style="font-size:11px;color:var(--text-sub);margin-top:2px;line-height:1.5">
          <?php if ($v['match_date']): ?><?=$v['match_date']?> · <?php endif; ?>
          <?=h($v['location'] ?? '-')?><br>
          제출: <?=h($v['submitter_nick'] ?: $v['submitter_name'])?> · <?=timeAgo($v['submitted_at'])?>
        </div>

        <!-- 액션 버튼 (PENDING/HOLD일 때만 처리 가능) -->
        <?php if (in_array($v['vstatus'], ['PENDING','HOLD'], true)): ?>
        <div style="display:flex;gap:4px;margin-top:8px;flex-wrap:wrap">
          <form method="POST" style="margin:0">
            <?=csrfInput()?>
            <input type="hidden" name="action" value="admin_approve_venue">
            <input type="hidden" name="verify_id" value="<?=(int)$v['verify_id']?>">
            <button type="submit" class="btn btn-primary btn-sm" style="font-size:11px;padding:4px 10px">✓ 승인</button>
          </form>
          <?php if ($v['vstatus'] !== 'HOLD'): ?>
          <form method="POST" style="margin:0">
            <?=csrfInput()?>
            <input type="hidden" name="action" value="admin_hold_venue">
            <input type="hidden" name="verify_id" value="<?=(int)$v['verify_id']?>">
            <button type="submit" class="btn btn-sm" style="font-size:11px;padding:4px 10px;background:#ff9500;color:#fff;border:none">⏸ 보류</button>
          </form>
          <?php endif; ?>
          <form method="POST" style="margin:0" onsubmit="return confirm('이 인증을 거절하시겠습니까?')">
            <?=csrfInput()?>
            <input type="hidden" name="action" value="admin_reject_venue">
            <input type="hidden" name="verify_id" value="<?=(int)$v['verify_id']?>">
            <button type="submit" class="btn btn-ghost btn-sm" style="font-size:11px;padding:4px 10px;color:#ff4d6d;border:1px solid rgba(255,77,109,0.3)">✕ 거절</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div></div>
  <?php endforeach; endif; ?>

  <?php elseif ($tab === 'reports'):
    // ── 신고(reports) 탭 ───────────────────────────────
    // 필터: all | pending(기본) | reviewing | resolved | dismissed
    $rFilter = $_GET['rf'] ?? 'pending';
    $rFilterMap = [
      'pending'    => ['PENDING'],
      'reviewing'  => ['REVIEWING'],
      'resolved'   => ['RESOLVED'],
      'dismissed'  => ['DISMISSED'],
      'all'        => ['PENDING','REVIEWING','RESOLVED','DISMISSED'],
    ];
    $rStatusList = $rFilterMap[$rFilter] ?? $rFilterMap['pending'];
    $rPh = implode(',', array_fill(0, count($rStatusList), '?'));
    // reports JOIN: reporter + (target_type별 대상) + admin_note
    $rq = $pdo->prepare("
      SELECT r.id, r.target_type, r.target_id, r.reason, r.status, r.admin_note,
             r.created_at, r.resolved_at, r.reported_user_id,
             COALESCE(NULLIF(TRIM(rep.nickname),''), rep.name) AS reporter_name,
             rep.id AS reporter_id
      FROM reports r
      JOIN users rep ON rep.id=r.reporter_id
      WHERE r.status IN ($rPh)
      ORDER BY FIELD(r.status,'PENDING','REVIEWING','RESOLVED','DISMISSED'), r.created_at DESC
      LIMIT 50
    ");
    $rq->execute($rStatusList);
    $reports = $rq->fetchAll();

    // target_type별 대상 정보 일괄 조회 (N+1 방지)
    $userTargets = [];
    $teamTargets = [];
    $matchTargets = [];
    foreach ($reports as $r) {
      // reported_user_id가 있으면 그걸로 유저 조회
      if ($r['reported_user_id']) $userTargets[(int)$r['reported_user_id']] = true;
      if ($r['target_type']==='user' && $r['target_id']) $userTargets[(int)$r['target_id']] = true;
      if ($r['target_type']==='team' && $r['target_id']) $teamTargets[(int)$r['target_id']] = true;
      if ($r['target_type']==='match' && $r['target_id']) $matchTargets[(int)$r['target_id']] = true;
    }
    $usersMap = [];
    if ($userTargets) {
      $ids = array_keys($userTargets);
      $phU = implode(',', array_fill(0, count($ids), '?'));
      $q = $pdo->prepare("SELECT id, name, nickname FROM users WHERE id IN ($phU)");
      $q->execute($ids);
      foreach ($q->fetchAll() as $u) $usersMap[(int)$u['id']] = $u;
    }
    $teamsMap = [];
    if ($teamTargets) {
      $ids = array_keys($teamTargets);
      $phT = implode(',', array_fill(0, count($ids), '?'));
      $q = $pdo->prepare("SELECT id, name FROM teams WHERE id IN ($phT)");
      $q->execute($ids);
      foreach ($q->fetchAll() as $t) $teamsMap[(int)$t['id']] = $t;
    }
    $matchesMap = [];
    if ($matchTargets) {
      $ids = array_keys($matchTargets);
      $phM = implode(',', array_fill(0, count($ids), '?'));
      $q = $pdo->prepare("SELECT id, title, match_date FROM matches WHERE id IN ($phM)");
      $q->execute($ids);
      foreach ($q->fetchAll() as $m) $matchesMap[(int)$m['id']] = $m;
    }

    $rFilterLabels = ['pending'=>'대기','reviewing'=>'검토중','resolved'=>'처리완료','dismissed'=>'기각','all'=>'전체'];
    $rStatusBadge = [
      'PENDING'   => ['background:rgba(255,184,0,0.15);color:#ffb400', '대기'],
      'REVIEWING' => ['background:rgba(58,158,245,0.15);color:#3a9ef5', '검토중'],
      'RESOLVED'  => ['background:rgba(0,255,136,0.15);color:var(--primary)', '처리완료'],
      'DISMISSED' => ['background:rgba(255,255,255,0.06);color:var(--text-sub)', '기각'],
    ];
    $typeLabel = ['user'=>'👤 유저','team'=>'🛡 팀','match'=>'⚽ 매치'];
  ?>
  <!-- 필터 칩 -->
  <div class="chip-row" style="margin-bottom:10px">
    <?php foreach ($rFilterLabels as $fk => $fl): ?>
    <a href="?page=admin&tab=reports&rf=<?=$fk?>" class="chip <?=$rFilter===$fk?'active':''?>" style="font-size:11px"><?=$fl?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$reports): ?>
  <div class="card"><div class="card-body" style="color:var(--text-sub);text-align:center;padding:20px;font-size:12px">
    <?=h($rFilterLabels[$rFilter])?> 신고가 없습니다.
  </div></div>
  <?php else: foreach ($reports as $r):
    $bc = $rStatusBadge[$r['status']] ?? ['background:rgba(255,255,255,0.06);color:var(--text-sub)', $r['status']];
    // 피신고 대상 표시 정보 구성
    $targetLabel = $typeLabel[$r['target_type']] ?? $r['target_type'];
    $targetName = '(삭제됨)';
    $targetClick = null;
    $reportedUid = (int)($r['reported_user_id'] ?? 0);
    if ($r['target_type']==='user') {
      $uid = (int)$r['target_id'];
      if (isset($usersMap[$uid])) {
        $targetName = displayName($usersMap[$uid]);
        $targetClick = $uid;
      }
    } elseif ($r['target_type']==='team') {
      $tid = (int)$r['target_id'];
      if (isset($teamsMap[$tid])) $targetName = $teamsMap[$tid]['name'];
    } elseif ($r['target_type']==='match') {
      $mid = (int)$r['target_id'];
      if (isset($matchesMap[$mid])) $targetName = $matchesMap[$mid]['title'].' ('.$matchesMap[$mid]['match_date'].')';
    }
    // reported_user_id가 별도로 있고 target이 user가 아닌 경우 함께 노출
    $extraReportedUser = null;
    if ($reportedUid && $r['target_type'] !== 'user' && isset($usersMap[$reportedUid])) {
      $extraReportedUser = $usersMap[$reportedUid];
    }
  ?>
  <div class="card" style="margin-bottom:10px"><div class="card-body">
    <!-- 헤더: 상태배지 + 유형 + 시간 -->
    <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;flex-wrap:wrap">
      <span class="badge" style="<?=$bc[0]?>;font-size:10px"><?=$bc[1]?></span>
      <span style="font-size:11px;color:var(--text-sub)"><?=$targetLabel?></span>
      <span style="font-size:11px;color:var(--text-sub);margin-left:auto"><?=timeAgo($r['created_at'])?></span>
    </div>
    <!-- 신고자 → 피신고 대상 -->
    <div style="font-size:12px;margin-bottom:4px">
      <span style="color:var(--primary)"><?=h($r['reporter_name'])?></span>
      <span style="color:var(--text-sub)">→</span>
      <?php if ($targetClick): ?>
      <span style="color:#ff6b6b;cursor:pointer;text-decoration:underline;text-underline-offset:3px" onclick="openUserProfile(<?=$targetClick?>)"><?=h($targetName)?></span>
      <?php else: ?>
      <span style="color:#ff6b6b"><?=h($targetName)?></span>
      <?php endif; ?>
      <?php if ($extraReportedUser): ?>
      <span style="color:var(--text-sub);font-size:10px">(관련 유저: <span style="cursor:pointer;color:#ff6b6b" onclick="openUserProfile(<?=$reportedUid?>)"><?=h(displayName($extraReportedUser))?></span>)</span>
      <?php endif; ?>
    </div>
    <!-- 신고 사유 -->
    <div style="font-size:12px;padding:8px;background:rgba(0,0,0,0.2);border-radius:6px;margin-bottom:8px;white-space:pre-wrap;word-break:break-word"><?=h($r['reason'])?></div>
    <!-- 기존 관리자 메모(있으면) -->
    <?php if (!empty($r['admin_note'])): ?>
    <div style="font-size:11px;padding:6px 8px;background:rgba(255,214,10,0.06);border-left:2px solid rgba(255,214,10,0.4);border-radius:4px;margin-bottom:8px;color:var(--text-main)">
      <strong style="color:#ffd60a">메모:</strong> <?=h($r['admin_note'])?>
    </div>
    <?php endif; ?>

    <!-- 액션 폼 (PENDING/REVIEWING일 때만) -->
    <?php if (in_array($r['status'], ['PENDING','REVIEWING'], true)): ?>
    <form method="POST" style="margin:0">
      <?=csrfInput()?>
      <input type="hidden" name="report_id" value="<?=(int)$r['id']?>">
      <textarea name="admin_note" rows="2" maxlength="500" placeholder="관리자 메모 (처리완료 시 저장, 선택)"
        style="width:100%;background:rgba(0,0,0,0.3);color:var(--text-main);border:1px solid rgba(255,255,255,0.08);border-radius:6px;padding:6px;font-size:12px;resize:vertical;margin-bottom:6px"><?=h($r['admin_note'] ?? '')?></textarea>
      <div style="display:flex;gap:4px;flex-wrap:wrap">
        <?php if ($r['status'] === 'PENDING'): ?>
        <button type="submit" formaction="?page=admin&tab=reports" name="action" value="admin_mark_reviewing" class="btn btn-sm" style="font-size:11px;padding:4px 10px;background:#3a9ef5;color:#fff;border:none">검토중</button>
        <?php endif; ?>
        <button type="submit" formaction="?page=admin&tab=reports" name="action" value="admin_resolve_report" class="btn btn-primary btn-sm" style="font-size:11px;padding:4px 10px">처리완료</button>
        <button type="submit" formaction="?page=admin&tab=reports" name="action" value="admin_dismiss_report" class="btn btn-ghost btn-sm" style="font-size:11px;padding:4px 10px">기각</button>
        <?php if ($reportedUid || $r['target_type']==='user'):
          $penUid = $reportedUid ?: (int)$r['target_id']; ?>
        <button type="button" onclick="return adminRestrictFromReport(<?=$penUid?>, <?=(int)$r['id']?>)" class="btn btn-sm" style="font-size:11px;padding:4px 10px;background:#ff9500;color:#fff;border:none">유저 제재</button>
        <?php endif; ?>
      </div>
    </form>
    <?php endif; ?>
  </div></div>
  <?php endforeach; endif; ?>

  <!-- 유저 제재 Prompt JS (7일/30일/영구 선택) -->
  <script>
  window.adminRestrictFromReport = function(uid, reportId) {
    var choice = prompt('제재 기간을 입력하세요:\n7  = 7일\n30 = 30일\n0  = 영구정지\n(취소하려면 빈 값)', '7');
    if (choice === null) return false;
    var days;
    if (choice === '0') days = '36500'; // 약 100년 = 영구
    else if (choice === '7' || choice === '30') days = choice;
    else { alert('7, 30, 또는 0만 입력 가능합니다.'); return false; }
    if (!confirm('정말 '+(days==='36500'?'영구':days+'일')+' 제재하시겠습니까?')) return false;
    // CSRF form 제출
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '?page=admin&tab=reports';
    form.innerHTML =
      '<input type="hidden" name="csrf_token" value="<?=csrfToken()?>">' +
      '<input type="hidden" name="action" value="admin_restrict_user">' +
      '<input type="hidden" name="user_id" value="'+uid+'">' +
      '<input type="hidden" name="days" value="'+days+'">' +
      '<input type="hidden" name="reason" value="신고 처리 (report#'+reportId+')">' +
      '<input type="hidden" name="from_report_id" value="'+reportId+'">';
    document.body.appendChild(form);
    form.submit();
    return false;
  };
  </script>

  <?php elseif ($tab === 'matches'):
    // ── 매치(matches) 탭 ───────────────────────────────
    // 필터: problems (기본, 분쟁/결과대기/강제종료) | recent (최근 30일) | all
    $mFilter = $_GET['mf'] ?? 'problems';
    if ($mFilter === 'problems') {
      $mq = $pdo->query("
        SELECT m.*, COALESCE(ht.name,'(미지정)') AS home_name, at.name AS away_name,
               (SELECT COUNT(*) FROM match_attendance WHERE match_id=m.id AND status='ATTEND') AS att_cnt,
               (SELECT COUNT(*) FROM checkins WHERE match_id=m.id) AS ci_cnt
        FROM matches m
        LEFT JOIN teams ht ON ht.id=m.home_team_id
        LEFT JOIN teams at ON at.id=m.away_team_id
        WHERE m.status IN ('disputed','result_pending','force_closed')
        ORDER BY FIELD(m.status,'disputed','result_pending','force_closed'), m.match_date DESC
        LIMIT 50
      ");
    } elseif ($mFilter === 'recent') {
      $mq = $pdo->query("
        SELECT m.*, COALESCE(ht.name,'(미지정)') AS home_name, at.name AS away_name,
               (SELECT COUNT(*) FROM match_attendance WHERE match_id=m.id AND status='ATTEND') AS att_cnt,
               (SELECT COUNT(*) FROM checkins WHERE match_id=m.id) AS ci_cnt
        FROM matches m
        LEFT JOIN teams ht ON ht.id=m.home_team_id
        LEFT JOIN teams at ON at.id=m.away_team_id
        WHERE m.match_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY m.match_date DESC, m.match_time DESC
        LIMIT 50
      ");
    } else { // all
      $mq = $pdo->query("
        SELECT m.*, COALESCE(ht.name,'(미지정)') AS home_name, at.name AS away_name,
               (SELECT COUNT(*) FROM match_attendance WHERE match_id=m.id AND status='ATTEND') AS att_cnt,
               (SELECT COUNT(*) FROM checkins WHERE match_id=m.id) AS ci_cnt
        FROM matches m
        LEFT JOIN teams ht ON ht.id=m.home_team_id
        LEFT JOIN teams at ON at.id=m.away_team_id
        ORDER BY m.id DESC
        LIMIT 50
      ");
    }
    $adminMatches = $mq->fetchAll();
    $mFilterLabels = ['problems'=>'문제 매치','recent'=>'최근 30일','all'=>'전체'];

    // 매치별 기존 결과(match_results) 일괄 조회
    $resultsMap = [];
    if ($adminMatches) {
      $midArr = array_map(fn($m)=>(int)$m['id'], $adminMatches);
      $phR = implode(',', array_fill(0, count($midArr), '?'));
      $rq = $pdo->prepare("SELECT match_id, score_home, score_away, is_approved FROM match_results WHERE match_id IN ($phR)");
      $rq->execute($midArr);
      foreach ($rq->fetchAll() as $res) $resultsMap[(int)$res['match_id']] = $res;
    }

    $mStatusColor = [
      'open'=>'var(--text-sub)', 'request_pending'=>'var(--warning)', 'confirmed'=>'var(--primary)',
      'checkin_open'=>'var(--primary)', 'in_progress'=>'#ff9500',
      'result_pending'=>'#ff9500', 'completed'=>'var(--primary)',
      'disputed'=>'#ff4d6d', 'cancelled'=>'var(--text-sub)', 'force_closed'=>'#ff4d6d',
    ];
  ?>
  <!-- 필터 칩 -->
  <div class="chip-row" style="margin-bottom:10px">
    <?php foreach ($mFilterLabels as $fk => $fl): ?>
    <a href="?page=admin&tab=matches&mf=<?=$fk?>" class="chip <?=$mFilter===$fk?'active':''?>" style="font-size:11px"><?=$fl?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!isSuperAdmin()): ?>
  <div class="card" style="margin-bottom:10px;border:1px dashed rgba(255,184,0,0.3);background:rgba(255,184,0,0.05)"><div class="card-body" style="font-size:12px;color:var(--warning);padding:10px;text-align:center">
    ⚠️ 매치 강제 처리 액션은 <strong>SUPER_ADMIN</strong> 전용입니다. 열람만 가능.
  </div></div>
  <?php endif; ?>

  <?php if (!$adminMatches): ?>
  <div class="card"><div class="card-body" style="color:var(--text-sub);text-align:center;padding:20px;font-size:12px">
    해당 조건의 매치가 없습니다.
  </div></div>
  <?php else: foreach ($adminMatches as $am):
    $res = $resultsMap[(int)$am['id']] ?? null;
    $statusCol = $mStatusColor[$am['status']] ?? 'var(--text-sub)';
  ?>
  <div class="card" style="margin-bottom:10px"><div class="card-body">
    <!-- 헤더 -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:6px">
      <div style="flex:1;min-width:0">
        <a href="?page=match&id=<?=(int)$am['id']?>" style="color:var(--text-main);text-decoration:none;font-weight:700;font-size:13px"><?=h($am['title'] ?: '매치 #'.$am['id'])?></a>
        <div style="font-size:11px;color:var(--text-sub);margin-top:2px">
          <?=$am['match_date']?> <?=substr($am['match_time']??'',0,5)?> · <?=h($am['location']??'-')?>
        </div>
      </div>
      <span class="badge" style="background:rgba(255,255,255,0.06);color:<?=$statusCol?>;font-size:10px;flex-shrink:0"><?=h($am['status'])?></span>
    </div>

    <!-- 대결 구도 + 참여 인원 -->
    <div style="display:flex;justify-content:space-between;align-items:center;padding:8px;background:rgba(0,0,0,0.2);border-radius:6px;margin-bottom:8px;font-size:12px">
      <div style="text-align:center;flex:1">
        <div style="font-weight:600"><?=h($am['home_name'])?></div>
        <?php if ($res): ?><div style="font-family:'Space Grotesk',sans-serif;font-size:18px;font-weight:800;color:var(--primary)"><?=(int)$res['score_home']?></div><?php endif; ?>
      </div>
      <div style="color:var(--text-sub);font-weight:700;padding:0 10px">VS</div>
      <div style="text-align:center;flex:1">
        <div style="font-weight:600"><?=h($am['away_name'] ?? '(없음)')?></div>
        <?php if ($res): ?><div style="font-family:'Space Grotesk',sans-serif;font-size:18px;font-weight:800;color:var(--primary)"><?=(int)$res['score_away']?></div><?php endif; ?>
      </div>
    </div>
    <div style="font-size:11px;color:var(--text-sub);margin-bottom:8px">
      참석 <?=$am['att_cnt']?> · 체크인 <?=$am['ci_cnt']?> · 정원 <?=$am['max_players']?>
      <?php if ($res && $res['is_approved']): ?> · <span style="color:var(--primary)">결과 승인됨</span><?php endif; ?>
    </div>

    <!-- 액션 (SUPER_ADMIN만) -->
    <?php if (isSuperAdmin()): ?>
    <div style="display:flex;gap:4px;flex-wrap:wrap">
      <?php if (!in_array($am['status'], ['cancelled','completed','force_closed'], true)): ?>
      <form method="POST" style="margin:0" onsubmit="return confirm('매치를 취소(cancelled)하시겠습니까?')">
        <?=csrfInput()?><input type="hidden" name="action" value="admin_force_delete_match"><input type="hidden" name="match_id" value="<?=(int)$am['id']?>">
        <button type="submit" class="btn btn-ghost btn-sm" style="font-size:10px;padding:4px 8px;color:var(--text-sub)">취소</button>
      </form>
      <form method="POST" style="margin:0" onsubmit="return confirm('강제 종료(force_closed)하시겠습니까?')">
        <?=csrfInput()?><input type="hidden" name="action" value="admin_force_close_match"><input type="hidden" name="match_id" value="<?=(int)$am['id']?>">
        <button type="submit" class="btn btn-sm" style="font-size:10px;padding:4px 8px;background:#ff4d6d;color:#fff;border:none">강제종료</button>
      </form>
      <?php if ($am['status'] !== 'disputed'): ?>
      <form method="POST" style="margin:0" onsubmit="return confirm('분쟁 처리(disputed)로 변경하시겠습니까?')">
        <?=csrfInput()?><input type="hidden" name="action" value="admin_dispute_match"><input type="hidden" name="match_id" value="<?=(int)$am['id']?>">
        <button type="submit" class="btn btn-sm" style="font-size:10px;padding:4px 8px;background:#ff9500;color:#fff;border:none">분쟁</button>
      </form>
      <?php endif; ?>
      <?php endif; ?>
      <button type="button" onclick="document.getElementById('mForceRes-<?=(int)$am['id']?>').classList.toggle('open')" class="btn btn-primary btn-sm" style="font-size:10px;padding:4px 8px">결과 강제</button>
      <form method="POST" style="margin:0" onsubmit="return confirm('매치 #<?=(int)$am['id']?>을(를) DB에서 완전 삭제합니다. 복구 불가! 계속?')">
        <?=csrfInput()?><input type="hidden" name="action" value="admin_purge_match"><input type="hidden" name="match_id" value="<?=(int)$am['id']?>">
        <button type="submit" class="btn btn-sm" style="font-size:10px;padding:4px 8px;background:#ff4d6d;color:#fff;border:none">🗑 삭제</button>
      </form>
    </div>

    <!-- 결과 강제 입력 collapse -->
    <div id="mForceRes-<?=(int)$am['id']?>" class="tf-collapse" style="margin-top:8px">
      <form method="POST" style="display:flex;gap:4px;align-items:center;background:rgba(255,77,109,0.06);border:1px dashed rgba(255,77,109,0.2);border-radius:6px;padding:8px"
            onsubmit="return confirm('스코어를 강제 입력/덮어쓰기합니다. 계속?')">
        <?=csrfInput()?>
        <input type="hidden" name="action" value="admin_force_result">
        <input type="hidden" name="match_id" value="<?=(int)$am['id']?>">
        <span style="font-size:10px;color:var(--text-sub);white-space:nowrap"><?=h(mb_substr($am['home_name'],0,6,'UTF-8'))?></span>
        <input type="number" name="score_home" min="0" max="99" value="<?=(int)($res['score_home']??0)?>" required
               style="width:44px;text-align:center;background:rgba(0,0,0,0.3);color:var(--text-main);border:1px solid rgba(255,255,255,0.08);border-radius:4px;padding:4px;font-size:12px">
        <span style="color:var(--text-sub);font-size:12px">:</span>
        <input type="number" name="score_away" min="0" max="99" value="<?=(int)($res['score_away']??0)?>" required
               style="width:44px;text-align:center;background:rgba(0,0,0,0.3);color:var(--text-main);border:1px solid rgba(255,255,255,0.08);border-radius:4px;padding:4px;font-size:12px">
        <span style="font-size:10px;color:var(--text-sub);white-space:nowrap"><?=h(mb_substr($am['away_name']??'?',0,6,'UTF-8'))?></span>
        <button type="submit" class="btn btn-primary btn-sm" style="font-size:10px;padding:4px 8px;margin-left:auto">저장</button>
      </form>
    </div>
    <?php endif; ?>
  </div></div>
  <?php endforeach; endif; ?>

  <?php elseif ($tab === 'feedback'):
    // ── 피드백(feedback) 탭 ───────────────────────────────
    $fFilter = $_GET['ff'] ?? 'new';
    $fMap = ['new'=>['NEW'], 'reviewing'=>['REVIEWING'], 'resolved'=>['RESOLVED'], 'archived'=>['ARCHIVED'], 'all'=>['NEW','REVIEWING','RESOLVED','ARCHIVED']];
    $fList = $fMap[$fFilter] ?? $fMap['new'];
    $fPh = implode(',', array_fill(0, count($fList), '?'));
    $fq = $pdo->prepare("
      SELECT f.*, COALESCE(NULLIF(TRIM(u.nickname),''), u.name) AS user_name, u.id AS uid
      FROM feedback f JOIN users u ON u.id=f.user_id
      WHERE f.status IN ($fPh)
      ORDER BY FIELD(f.status,'NEW','REVIEWING','RESOLVED','ARCHIVED'), f.created_at DESC
      LIMIT 100
    ");
    $fq->execute($fList);
    $feedbacks = $fq->fetchAll();
    $fLabels = ['new'=>'신규','reviewing'=>'검토중','resolved'=>'해결됨','archived'=>'보관','all'=>'전체'];
    $typeEmoji = ['BUG'=>'🐛 버그','FEATURE'=>'💡 건의','COMPLIMENT'=>'💖 칭찬','OTHER'=>'📝 기타'];
    $fStatusColor = [
      'NEW'       => ['background:rgba(255,77,109,0.15);color:#ff4d6d','신규'],
      'REVIEWING' => ['background:rgba(58,158,245,0.15);color:#3a9ef5','검토중'],
      'RESOLVED'  => ['background:rgba(0,255,136,0.15);color:var(--primary)','해결'],
      'ARCHIVED'  => ['background:rgba(255,255,255,0.06);color:var(--text-sub)','보관'],
    ];
  ?>
  <!-- 필터 -->
  <div class="chip-row" style="margin-bottom:10px">
    <?php foreach ($fLabels as $fk => $fl): ?>
    <a href="?page=admin&tab=feedback&ff=<?=$fk?>" class="chip <?=$fFilter===$fk?'active':''?>" style="font-size:11px"><?=$fl?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$feedbacks): ?>
  <div class="card"><div class="card-body" style="color:var(--text-sub);text-align:center;padding:20px;font-size:12px"><?=h($fLabels[$fFilter])?> 피드백 없음</div></div>
  <?php else: foreach ($feedbacks as $f):
    $bc = $fStatusColor[$f['status']] ?? ['background:rgba(255,255,255,0.06);color:var(--text-sub)', $f['status']];
  ?>
  <div class="card" style="margin-bottom:10px"><div class="card-body">
    <div style="display:flex;gap:6px;align-items:center;margin-bottom:6px;flex-wrap:wrap">
      <span class="badge" style="<?=$bc[0]?>;font-size:10px"><?=$bc[1]?></span>
      <span style="font-size:11px;color:var(--text-sub)"><?=$typeEmoji[$f['type']] ?? $f['type']?></span>
      <span style="font-size:11px;cursor:pointer;color:var(--primary)" onclick="openUserProfile(<?=(int)$f['uid']?>)">@<?=h($f['user_name'])?></span>
      <span style="font-size:11px;color:var(--text-sub);margin-left:auto"><?=timeAgo($f['created_at'])?></span>
    </div>
    <div style="font-size:12px;padding:8px;background:rgba(0,0,0,0.2);border-radius:6px;margin-bottom:6px;white-space:pre-wrap;word-break:break-word"><?=h($f['message'])?></div>
    <?php if ($f['page_url']): ?>
    <div style="font-size:10px;color:var(--text-sub);margin-bottom:6px">📍 <?=h($f['page_url'])?></div>
    <?php endif; ?>
    <?php if ($f['admin_note']): ?>
    <div style="font-size:11px;padding:6px 8px;background:rgba(255,214,10,0.06);border-left:2px solid rgba(255,214,10,0.4);border-radius:4px;margin-bottom:6px"><strong style="color:#ffd60a">메모:</strong> <?=h($f['admin_note'])?></div>
    <?php endif; ?>
    <?php if ($f['status'] !== 'ARCHIVED'): ?>
    <form method="POST" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;margin:0">
      <?=csrfInput()?>
      <input type="hidden" name="feedback_id" value="<?=(int)$f['id']?>">
      <input type="text" name="admin_note" placeholder="메모(선택)" maxlength="500"
        style="flex:1;min-width:120px;background:rgba(0,0,0,0.3);color:var(--text-main);border:1px solid rgba(255,255,255,0.08);border-radius:4px;padding:4px 6px;font-size:11px"
        value="<?=h($f['admin_note']??'')?>">
      <?php if ($f['status']==='NEW'): ?>
      <button type="submit" name="action" value="admin_feedback_reviewing" class="btn btn-sm" style="font-size:10px;padding:4px 8px;background:#3a9ef5;color:#fff;border:none">검토중</button>
      <?php endif; ?>
      <button type="submit" name="action" value="admin_feedback_resolved" class="btn btn-primary btn-sm" style="font-size:10px;padding:4px 8px">해결</button>
      <button type="submit" name="action" value="admin_feedback_archive" class="btn btn-ghost btn-sm" style="font-size:10px;padding:4px 8px">보관</button>
    </form>
    <?php endif; ?>
  </div></div>
  <?php endforeach; endif; ?>

  <?php elseif ($tab === 'bugs'):
    // ── 버그 리포트(bugs) 탭 ───────────────────────────────
    $bFilter = $_GET['bf'] ?? 'pending';
    $bMap = ['pending'=>['pending'], 'reviewing'=>['reviewing'], 'fixed'=>['fixed'], 'closed'=>['wontfix','duplicate'], 'all'=>['pending','reviewing','fixed','wontfix','duplicate']];
    $bList = $bMap[$bFilter] ?? $bMap['pending'];
    $bPh = implode(',', array_fill(0, count($bList), '?'));
    $bq = $pdo->prepare("
      SELECT br.*, COALESCE(NULLIF(TRIM(u.nickname),''), u.name) AS user_name, u.id AS uid
      FROM bug_reports br JOIN users u ON u.id=br.user_id
      WHERE br.status IN ($bPh)
      ORDER BY FIELD(br.status,'pending','reviewing','fixed','wontfix','duplicate'), br.created_at DESC
      LIMIT 100
    ");
    $bq->execute($bList);
    $bugList = $bq->fetchAll();
    $bFilterLabels = ['pending'=>'접수','reviewing'=>'검토중','fixed'=>'해결','closed'=>'보류/중복','all'=>'전체'];
    $bStatusColor = [
      'pending'   => ['background:rgba(255,149,0,0.15);color:#ff9500','접수'],
      'reviewing' => ['background:rgba(58,158,245,0.15);color:#3a9ef5','검토중'],
      'fixed'     => ['background:rgba(0,255,136,0.15);color:var(--primary)','해결'],
      'wontfix'   => ['background:rgba(255,255,255,0.06);color:var(--text-sub)','보류'],
      'duplicate' => ['background:rgba(255,255,255,0.06);color:var(--text-sub)','중복'],
    ];
    $bCatLabels = ['bug'=>'🐛 버그','ui'=>'🎨 UI','feature'=>'💡 기능','other'=>'📝 기타'];
    $bSeverityBadge = ['low'=>['#888','낮음'],'medium'=>['#ff9500','보통'],'high'=>['#ff4d6d','높음'],'critical'=>['#ff0000','심각']];
  ?>
  <!-- 필터 -->
  <div class="chip-row" style="margin-bottom:10px">
    <?php foreach ($bFilterLabels as $bk => $bl): ?>
    <a href="?page=admin&tab=bugs&bf=<?=$bk?>" class="chip <?=$bFilter===$bk?'active':''?>" style="font-size:11px"><?=$bl?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$bugList): ?>
  <div class="card"><div class="card-body" style="color:var(--text-sub);text-align:center;padding:20px;font-size:12px"><?=h($bFilterLabels[$bFilter] ?? '전체')?> 버그 리포트 없음</div></div>
  <?php else: foreach ($bugList as $b):
    $bsc = $bStatusColor[$b['status']] ?? ['background:rgba(255,255,255,0.06);color:var(--text-sub)', $b['status']];
    $bsev = $bSeverityBadge[$b['severity']] ?? ['#888','?'];
  ?>
  <div class="card" style="margin-bottom:10px"><div class="card-body">
    <div style="display:flex;gap:6px;align-items:center;margin-bottom:6px;flex-wrap:wrap">
      <span class="badge" style="<?=$bsc[0]?>;font-size:10px"><?=$bsc[1]?></span>
      <span class="badge" style="background:<?=$bsev[0]?>18;color:<?=$bsev[0]?>;font-size:10px"><?=$bsev[1]?></span>
      <span style="font-size:11px;color:var(--text-sub)"><?=$bCatLabels[$b['category']] ?? $b['category']?></span>
      <span style="font-size:11px;cursor:pointer;color:var(--primary)" onclick="openUserProfile(<?=(int)$b['uid']?>)">@<?=h($b['user_name'])?></span>
      <span style="font-size:11px;color:var(--text-sub);margin-left:auto"><?=timeAgo($b['created_at'])?></span>
    </div>
    <div style="font-size:13px;font-weight:600;margin-bottom:4px"><?=h($b['title'])?></div>
    <?php if($b['description']): ?>
    <div style="font-size:12px;color:var(--text-sub);margin-bottom:6px;padding:8px;background:rgba(0,0,0,0.2);border-radius:6px;white-space:pre-wrap;word-break:break-word"><?=h(mb_substr($b['description'],0,200,'UTF-8'))?></div>
    <?php endif; ?>
    <?php if($b['page_url']): ?>
    <div style="font-size:10px;color:var(--text-sub);margin-bottom:4px">📍 <?=h($b['page_url'])?></div>
    <?php endif; ?>
    <?php if($b['points_awarded']): ?>
    <div style="font-size:11px;color:var(--primary);margin-bottom:4px">+<?=$b['points_awarded']?>P 지급됨</div>
    <?php endif; ?>
    <?php if($b['admin_note']): ?>
    <div style="font-size:11px;padding:6px 8px;background:rgba(255,214,10,0.06);border-left:2px solid rgba(255,214,10,0.4);border-radius:4px;margin-bottom:6px"><strong style="color:#ffd60a">메모:</strong> <?=h($b['admin_note'])?></div>
    <?php endif; ?>
    <?php if (!in_array($b['status'], ['fixed','wontfix','duplicate'])): ?>
    <form method="POST" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;margin:0">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="update_bug_status">
      <input type="hidden" name="bug_id" value="<?=(int)$b['id']?>">
      <input type="text" name="admin_note" placeholder="메모(선택)" maxlength="500"
        style="flex:1;min-width:120px;background:rgba(0,0,0,0.3);color:var(--text-main);border:1px solid rgba(255,255,255,0.08);border-radius:4px;padding:4px 6px;font-size:11px"
        value="<?=h($b['admin_note']??'')?>">
      <?php if ($b['status']==='pending'): ?>
      <button type="submit" name="bug_status" value="reviewing" class="btn btn-sm" style="font-size:10px;padding:4px 8px;background:#3a9ef5;color:#fff;border:none">검토중</button>
      <?php endif; ?>
      <button type="submit" name="bug_status" value="fixed" class="btn btn-primary btn-sm" style="font-size:10px;padding:4px 8px">해결</button>
      <button type="submit" name="bug_status" value="wontfix" class="btn btn-ghost btn-sm" style="font-size:10px;padding:4px 8px">보류</button>
      <button type="submit" name="bug_status" value="duplicate" class="btn btn-ghost btn-sm" style="font-size:10px;padding:4px 8px">중복</button>
    </form>
    <?php else: ?>
    <div style="font-size:11px;color:var(--text-sub)">
      <?php if($b['resolved_at']): ?>처리일: <?=date('Y-m-d H:i', strtotime($b['resolved_at']))?><?php endif; ?>
    </div>
    <?php endif; ?>
  </div></div>
  <?php endforeach; endif; ?>

  <?php elseif ($tab === 'users'):
    // ── 유저(users) 탭 ───────────────────────────────
    $uQuery  = trim((string)($_GET['q'] ?? ''));
    $uFilter = $_GET['uf'] ?? 'recent'; // 기본을 '최근'으로 (제재중이 비어있으면 빈 화면 방지)

    $where = "1=1"; $params = [];
    if ($uQuery !== '') {
      $where .= " AND (u.name LIKE ? OR u.phone LIKE ? OR u.nickname LIKE ?)";
      $qLike = '%'.$uQuery.'%';
      $params[] = $qLike; $params[] = $qLike; $params[] = $qLike;
    }
    if ($uFilter === 'restricted') {
      $where .= " AND u.restricted_until IS NOT NULL AND u.restricted_until > NOW()";
      $orderBy = 'u.restricted_until DESC';
    } elseif ($uFilter === 'blacklist') {
      $where .= " AND EXISTS (SELECT 1 FROM user_penalties WHERE user_id=u.id AND penalty_type='BLACKLIST' AND (expires_at IS NULL OR expires_at > NOW()))";
      $orderBy = 'u.id DESC';
    } elseif ($uFilter === 'admin') {
      $where .= " AND u.global_role IN ('ADMIN','SUPER_ADMIN')";
      $orderBy = "FIELD(u.global_role,'SUPER_ADMIN','ADMIN'), u.id";
    } else { // recent
      $orderBy = 'u.id DESC';
    }

    $uq = $pdo->prepare("
      SELECT u.id, u.name, u.nickname, u.phone, u.manner_score, u.global_role,
             u.restricted_until, u.ban_reason, u.created_at,
             (SELECT COUNT(*) FROM reports WHERE reported_user_id=u.id) AS report_count,
             (SELECT COUNT(*) FROM user_penalties WHERE user_id=u.id AND penalty_type='BLACKLIST' AND (expires_at IS NULL OR expires_at > NOW())) AS is_blacklisted
      FROM users u
      WHERE $where
      ORDER BY $orderBy
      LIMIT 50
    ");
    $uq->execute($params);
    $adminUsers = $uq->fetchAll();

    $uFilterLabels = ['restricted'=>'제재중','blacklist'=>'BLACKLIST','admin'=>'관리자','recent'=>'최근'];
  ?>

  <!-- 검색 + 필터 -->
  <form method="GET" action="" style="margin-bottom:8px">
    <input type="hidden" name="page" value="admin">
    <input type="hidden" name="tab" value="users">
    <input type="hidden" name="uf" value="<?=h($uFilter)?>">
    <div style="display:flex;gap:6px">
      <input type="search" name="q" value="<?=h($uQuery)?>" placeholder="이름 / 닉네임 / 전화번호 검색"
        style="flex:1;background:rgba(0,0,0,0.3);color:var(--text-main);border:1px solid rgba(255,255,255,0.08);border-radius:6px;padding:6px 10px;font-size:12px">
      <button type="submit" class="btn btn-outline btn-sm" style="font-size:11px">검색</button>
      <?php if ($uQuery !== ''): ?>
      <a href="?page=admin&tab=users&uf=<?=h($uFilter)?>" class="btn btn-ghost btn-sm" style="font-size:11px">초기화</a>
      <?php endif; ?>
    </div>
  </form>
  <div class="chip-row" style="margin-bottom:10px">
    <?php foreach ($uFilterLabels as $fk => $fl): ?>
    <a href="?page=admin&tab=users&uf=<?=$fk?><?=$uQuery!==''?'&q='.urlencode($uQuery):''?>" class="chip <?=$uFilter===$fk?'active':''?>" style="font-size:11px"><?=$fl?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$adminUsers): ?>
  <div class="card"><div class="card-body" style="color:var(--text-sub);text-align:center;padding:20px;font-size:12px">
    검색 결과 없음
  </div></div>
  <?php else:
    $selfId = (int)me()['id'];
    foreach ($adminUsers as $u):
      $uDn = displayName($u);
      $isRestrict = $u['restricted_until'] && strtotime($u['restricted_until']) > time();
      $isBlack    = (int)$u['is_blacklisted'] > 0;
      $isSelf     = ((int)$u['id']) === $selfId;
      $role       = $u['global_role'] ?? 'USER';
  ?>
  <div class="card" style="margin-bottom:10px"><div class="card-body">
    <!-- 헤더: 이름 + 뱃지들 -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px;margin-bottom:4px">
      <div style="flex:1;min-width:0;cursor:pointer" onclick="openUserProfile(<?=(int)$u['id']?>)">
        <div style="font-weight:700;font-size:13px">
          <?=h($uDn)?>
          <?php if ($role === 'SUPER_ADMIN'): ?><span class="badge" style="background:#ff4d6d;color:#fff;font-size:9px">SUPER</span><?php endif; ?>
          <?php if ($role === 'ADMIN'): ?><span class="badge" style="background:#3a9ef5;color:#fff;font-size:9px">ADMIN</span><?php endif; ?>
          <?php if ($isBlack): ?><span class="badge badge-red" style="font-size:9px">BLACK</span><?php endif; ?>
          <?php if ($isRestrict): ?><span class="badge" style="background:#ff9500;color:#fff;font-size:9px">~<?=date('m/d',strtotime($u['restricted_until']))?></span><?php endif; ?>
          <?php if ($isSelf): ?><span class="badge" style="background:rgba(0,255,136,0.15);color:var(--primary);font-size:9px">본인</span><?php endif; ?>
        </div>
        <div style="font-size:11px;color:var(--text-sub);margin-top:2px">
          <?=h($u['phone'])?> · 가입 <?=date('Y-m-d',strtotime($u['created_at']))?> · 매너 <?=number_format((float)$u['manner_score'],1)?>°
          <?php if ((int)$u['report_count'] > 0): ?>
          <span style="color:#ff6b6b;font-weight:600"> · 신고 <?=(int)$u['report_count']?>회</span>
          <?php endif; ?>
        </div>
        <?php if ($isRestrict && !empty($u['ban_reason'])): ?>
        <div style="font-size:11px;color:var(--warning);margin-top:4px">🚫 <?=h($u['ban_reason'])?></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$isSelf): ?>
    <!-- 액션: 매너점수 조정 (인라인) -->
    <form method="POST" style="display:flex;gap:4px;align-items:center;margin-bottom:6px;margin-top:8px">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="admin_adjust_manner">
      <input type="hidden" name="user_id" value="<?=(int)$u['id']?>">
      <span style="font-size:10px;color:var(--text-sub);white-space:nowrap">매너 ±</span>
      <input type="number" name="delta" step="0.5" min="-50" max="50" placeholder="예: +5 / -3" required
        style="width:80px;background:rgba(0,0,0,0.3);color:var(--text-main);border:1px solid rgba(255,255,255,0.08);border-radius:4px;padding:4px;font-size:11px;text-align:center">
      <?php if (!isSuperAdmin()): ?>
      <span style="font-size:9px;color:var(--text-sub)">(±5 이내)</span>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary btn-sm" style="font-size:10px;padding:4px 8px;margin-left:auto">조정</button>
    </form>

    <!-- 제재 버튼 -->
    <div style="display:flex;gap:4px;flex-wrap:wrap">
      <?php if (!$isRestrict): ?>
      <form method="POST" style="margin:0" onsubmit="return confirm('7일 제한하시겠습니까?')">
        <?=csrfInput()?><input type="hidden" name="action" value="admin_restrict_user"><input type="hidden" name="user_id" value="<?=(int)$u['id']?>"><input type="hidden" name="days" value="7">
        <button type="submit" class="btn btn-sm" style="font-size:10px;padding:4px 8px;background:#ff9500;color:#fff;border:none">7일</button>
      </form>
      <?php if (isSuperAdmin()): ?>
      <form method="POST" style="margin:0" onsubmit="return confirm('30일 제한하시겠습니까?')">
        <?=csrfInput()?><input type="hidden" name="action" value="admin_restrict_user"><input type="hidden" name="user_id" value="<?=(int)$u['id']?>"><input type="hidden" name="days" value="30">
        <button type="submit" class="btn btn-sm" style="font-size:10px;padding:4px 8px;background:#ff6b3b;color:#fff;border:none">30일</button>
      </form>
      <button type="button" onclick="return adminPermanentBan(<?=(int)$u['id']?>, '<?=h(addslashes($uDn))?>')" class="btn btn-sm" style="font-size:10px;padding:4px 8px;background:#ff4d6d;color:#fff;border:none">영구정지</button>
      <?php endif; ?>
      <?php else: ?>
      <form method="POST" style="margin:0" onsubmit="return confirm('제한을 해제하시겠습니까?')">
        <?=csrfInput()?><input type="hidden" name="action" value="admin_unrestrict_user"><input type="hidden" name="user_id" value="<?=(int)$u['id']?>">
        <button type="submit" class="btn btn-primary btn-sm" style="font-size:10px;padding:4px 8px">제한 해제</button>
      </form>
      <?php endif; ?>

      <?php if (isSuperAdmin() && !$isBlack): ?>
      <form method="POST" style="margin:0" onsubmit="return confirm('BLACKLIST 등록 시 로그인이 차단됩니다. 계속?')">
        <?=csrfInput()?><input type="hidden" name="action" value="admin_blacklist_user"><input type="hidden" name="user_id" value="<?=(int)$u['id']?>">
        <button type="submit" class="btn btn-ghost btn-sm" style="font-size:10px;padding:4px 8px;color:#ff4d6d;border:1px solid rgba(255,77,109,0.3)">BLACK</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div></div>
  <?php endforeach; endif; ?>

  <!-- 영구 정지 prompt (ban_reason 입력) -->
  <script>
  window.adminPermanentBan = function(uid, name) {
    var reason = prompt('영구 정지 사유를 입력하세요 (유저에게 표시될 수 있음):\n대상: ' + name, '');
    if (reason === null || reason.trim() === '') return false;
    if (!confirm('정말 \"' + name + '\"을(를) 영구 정지하시겠습니까?')) return false;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '?page=admin&tab=users';
    form.innerHTML =
      '<input type="hidden" name="csrf_token" value="<?=csrfToken()?>">' +
      '<input type="hidden" name="action" value="admin_restrict_user">' +
      '<input type="hidden" name="user_id" value="'+uid+'">' +
      '<input type="hidden" name="days" value="36500">' +
      '<input type="hidden" name="reason" value="'+reason.replace(/"/g,'&quot;')+'">';
    document.body.appendChild(form);
    form.submit();
    return false;
  };

  // roundRect 폴리필 (구형 브라우저 호환)
  if (!CanvasRenderingContext2D.prototype.roundRect) {
    CanvasRenderingContext2D.prototype.roundRect = function(x, y, w, h, r) {
      if (typeof r === 'number') r = [r,r,r,r];
      this.moveTo(x+r[0], y);
      this.lineTo(x+w-r[1], y);
      this.arcTo(x+w, y, x+w, y+r[1], r[1]);
      this.lineTo(x+w, y+h-r[2]);
      this.arcTo(x+w, y+h, x+w-r[2], y+h, r[2]);
      this.lineTo(x+r[3], y+h);
      this.arcTo(x, y+h, x, y+h-r[3], r[3]);
      this.lineTo(x, y+r[0]);
      this.arcTo(x, y, x+r[0], y, r[0]);
      this.closePath();
      return this;
    };
  }

  // === 선수카드 / 경기카드 Canvas 생성 ===
  function generatePlayerCard(data) {
    var canvas = document.createElement('canvas');
    canvas.width = 400; canvas.height = 560;
    var ctx = canvas.getContext('2d');
    // 배경 그라데이션
    var grad = ctx.createLinearGradient(0,0,0,560);
    grad.addColorStop(0,'#1a1a2e'); grad.addColorStop(1,'#0f3460');
    ctx.fillStyle = grad; ctx.fillRect(0,0,400,560);
    // 상단 장식선
    ctx.fillStyle = '#00d26a'; ctx.fillRect(0,0,400,4);
    // 포지션 배지
    ctx.fillStyle = '#00d26a';
    ctx.beginPath(); ctx.roundRect(20,20,60,30,6); ctx.fill();
    ctx.fillStyle = '#fff'; ctx.font = 'bold 14px sans-serif'; ctx.textAlign = 'center';
    ctx.fillText(data.position || 'MF', 50, 41);
    // 등번호
    ctx.fillStyle = '#ffb400'; ctx.font = 'bold 64px "Space Grotesk",sans-serif'; ctx.textAlign = 'right';
    ctx.fillText('#' + (data.jersey_number || ''), 380, 70);
    // 이름
    ctx.fillStyle = '#fff'; ctx.font = 'bold 32px sans-serif'; ctx.textAlign = 'center';
    ctx.fillText(data.name || '', 200, 130);
    // 닉네임
    if (data.nickname) {
      ctx.fillStyle = '#aaa'; ctx.font = '16px sans-serif';
      ctx.fillText(data.nickname, 200, 156);
    }
    // 팀명
    ctx.fillStyle = '#00d26a'; ctx.font = 'bold 16px sans-serif';
    ctx.fillText(data.team_name || 'FREE', 200, 185);
    // 구분선
    ctx.strokeStyle = 'rgba(255,255,255,0.13)'; ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(30,200); ctx.lineTo(370,200); ctx.stroke();
    // 6개 능력치 바
    var stats = [
      {label:'PAC', val: data.stat_pace||50},
      {label:'SHO', val: data.stat_shooting||50},
      {label:'PAS', val: data.stat_passing||50},
      {label:'DRI', val: data.stat_dribbling||50},
      {label:'DEF', val: data.stat_defending||50},
      {label:'PHY', val: data.stat_physical||50}
    ];
    stats.forEach(function(s, i) {
      var y = 220 + i * 36;
      ctx.fillStyle = '#aaa'; ctx.font = '13px sans-serif'; ctx.textAlign = 'left';
      ctx.fillText(s.label, 30, y + 14);
      // 바 배경
      ctx.fillStyle = 'rgba(255,255,255,0.08)'; ctx.beginPath(); ctx.roundRect(80, y, 240, 18, 4); ctx.fill();
      // 바 채우기
      var w = Math.min(s.val, 99) / 99 * 240;
      var barColor = s.val >= 80 ? '#00d26a' : s.val >= 60 ? '#ffb400' : '#ff4757';
      ctx.fillStyle = barColor; ctx.beginPath(); ctx.roundRect(80, y, w, 18, 4); ctx.fill();
      // 수치
      ctx.fillStyle = '#fff'; ctx.font = 'bold 14px sans-serif'; ctx.textAlign = 'right';
      ctx.fillText(s.val, 370, y + 14);
    });
    // MOM + 매너
    ctx.fillStyle = '#ffb400'; ctx.font = 'bold 14px sans-serif'; ctx.textAlign = 'left';
    ctx.fillText('MOM ' + (data.mom_count||0) + '회', 30, 460);
    ctx.fillStyle = '#00d26a';
    ctx.fillText('매너 ' + (data.manner_score||0) + '점', 180, 460);
    // 골/어시스트
    ctx.fillStyle = '#fff'; ctx.font = '13px sans-serif';
    ctx.fillText('골 ' + (data.goals_total||0) + '  |  어시 ' + (data.assists_total||0), 30, 488);
    // 출석률
    ctx.fillText('출석률 ' + (data.attendance_rate||0) + '%  |  경기 ' + (data.matches_played||0) + '회', 30, 510);
    // 워터마크
    ctx.fillStyle = 'rgba(255,255,255,0.2)'; ctx.font = 'bold 12px sans-serif'; ctx.textAlign = 'center';
    ctx.fillText('TRUST FOOTBALL', 200, 548);
    return canvas;
  }

  function generateMatchCard(data) {
    var canvas = document.createElement('canvas');
    canvas.width = 400; canvas.height = 300;
    var ctx = canvas.getContext('2d');
    var grad = ctx.createLinearGradient(0,0,0,300);
    grad.addColorStop(0,'#1a1a2e'); grad.addColorStop(1,'#0f3460');
    ctx.fillStyle = grad; ctx.fillRect(0,0,400,300);
    ctx.fillStyle = '#00d26a'; ctx.fillRect(0,0,400,4);
    // 날짜
    ctx.fillStyle = '#aaa'; ctx.font = '14px sans-serif'; ctx.textAlign = 'center';
    ctx.fillText(data.match_date || '', 200, 35);
    // 팀명
    ctx.fillStyle = '#fff'; ctx.font = 'bold 20px sans-serif';
    ctx.textAlign = 'right'; ctx.fillText(data.home_name || '', 160, 80);
    ctx.textAlign = 'left'; ctx.fillText(data.away_name || '', 240, 80);
    ctx.fillStyle = '#aaa'; ctx.font = '16px sans-serif'; ctx.textAlign = 'center';
    ctx.fillText('vs', 200, 80);
    // 스코어
    ctx.fillStyle = '#00d26a'; ctx.font = 'bold 56px "Space Grotesk",sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText((data.score_home||0) + ' : ' + (data.score_away||0), 200, 155);
    // 장소
    ctx.fillStyle = '#aaa'; ctx.font = '13px sans-serif';
    ctx.fillText(data.location || '', 200, 195);
    // MOM
    if (data.mom_name) {
      ctx.fillStyle = '#ffb400'; ctx.font = 'bold 14px sans-serif';
      ctx.fillText('MOM: ' + data.mom_name, 200, 225);
    }
    // 워터마크
    ctx.fillStyle = 'rgba(255,255,255,0.2)'; ctx.font = 'bold 12px sans-serif';
    ctx.fillText('TRUST FOOTBALL', 200, 285);
    return canvas;
  }

  function shareCard(type, canvas) {
    canvas.toBlob(function(blob) {
      var file = new File([blob], 'trust_football_' + type + '.png', {type:'image/png'});
      if (navigator.share && navigator.canShare && navigator.canShare({files:[file]})) {
        navigator.share({title:'TRUST FOOTBALL', files:[file]}).catch(function(){});
      } else {
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = file.name;
        a.click();
        URL.revokeObjectURL(a.href);
      }
    }, 'image/png');
  }

  function previewMyCard() {
    var uid = document.querySelector('meta[name="user-id"]');
    var userId = uid ? uid.content : '';
    fetch('?page=api&fn=user_profile&id=' + userId, {credentials:'same-origin'})
      .then(function(r){return r.json()})
      .then(function(data){
        var d = data.user || data;
        try {
        var canvas = generatePlayerCard(d);
        } catch(genErr) { alert('generatePlayerCard 에러: ' + genErr.message); return; }
        var target = document.getElementById('myPlayerCardCanvas');
        if(target){
          target.width = 1080; target.height = 1350;
          target.getContext('2d').drawImage(canvas,0,0);
          target.style.display = 'block';
          document.getElementById('myCardPlaceholder').style.display = 'none';
        }
      }).catch(function(e){
        alert('카드 에러: ' + (e.message||e));
        var ph = document.getElementById('myCardPlaceholder');
        if(ph) { ph.textContent = '카드 생성 실패: ' + (e.message||'알 수 없는 오류'); ph.style.color = '#ff4757'; }
      });
  }

  function downloadMyCard() {
    var uid = document.querySelector('meta[name="user-id"]');
    var userId = uid ? uid.content : '';
    fetch('?page=api&fn=user_profile&id=' + userId, {credentials:'same-origin'})
      .then(function(r){return r.json()})
      .then(function(data){
        var d = data.user || data;
        var canvas = generatePlayerCard(d);
        shareCard('player_card', canvas);
      }).catch(function(e){ alert('카드 생성 실패: ' + e.message); });
  }

  function shareMatchResult(mid){fetch('?page=api&fn=match_share_data&id='+mid,{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(data){if(!data.ok){alert('데이터 없음');return;}var d=data.match,W=1080,H=1080,cv=document.createElement('canvas');cv.width=W;cv.height=H;var ctx=cv.getContext('2d');var g=ctx.createLinearGradient(0,0,W,H);g.addColorStop(0,'#0a0e1a');g.addColorStop(1,'#111827');ctx.fillStyle=g;ctx.fillRect(0,0,W,H);ctx.fillStyle='#00ff88';ctx.fillRect(0,0,W,6);ctx.fillRect(0,H-6,W,6);ctx.fillStyle='rgba(255,255,255,0.4)';ctx.font='28px sans-serif';ctx.textAlign='center';ctx.fillText(d.match_date+' '+(d.match_time||''),W/2,80);ctx.fillStyle='#fff';ctx.font='bold 48px sans-serif';ctx.textAlign='right';ctx.fillText(d.home_name||'',W/2-80,220);ctx.textAlign='left';ctx.fillText(d.away_name||'',W/2+80,220);ctx.textAlign='center';ctx.fillStyle='rgba(255,255,255,0.3)';ctx.font='28px sans-serif';ctx.fillText('vs',W/2,220);ctx.fillStyle='#00ff88';ctx.font='bold 160px sans-serif';ctx.fillText(d.score_home+' : '+d.score_away,W/2,430);ctx.fillStyle='rgba(255,255,255,0.4)';ctx.font='24px sans-serif';ctx.fillText(d.location||'',W/2,510);if(d.scorers&&d.scorers.length>0){ctx.fillStyle='rgba(255,255,255,0.6)';ctx.font='22px sans-serif';ctx.fillText('GOAL: '+d.scorers.join(', '),W/2,610);}if(d.mom_name){ctx.fillStyle='#ffb400';ctx.font='bold 32px sans-serif';ctx.fillText('MOM: '+d.mom_name,W/2,710);}ctx.fillStyle='rgba(255,255,255,0.15)';ctx.font='bold 24px sans-serif';ctx.fillText('BALLOCHA',W/2,H-40);_shareImg(cv,'result');}).catch(function(e){alert(e.message);});}
  function shareLineup(mid){fetch('?page=api&fn=match_share_data&id='+mid,{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(data){if(!data.ok){alert('데이터 없음');return;}var d=data.match,W=1080,H=1350,cv=document.createElement('canvas');cv.width=W;cv.height=H;var ctx=cv.getContext('2d');var g=ctx.createLinearGradient(0,0,0,H);g.addColorStop(0,'#1a5e2a');g.addColorStop(0.5,'#1e6e32');g.addColorStop(1,'#1a5e2a');ctx.fillStyle=g;ctx.fillRect(0,0,W,H);ctx.strokeStyle='rgba(255,255,255,0.2)';ctx.lineWidth=3;ctx.strokeRect(60,200,W-120,H-300);ctx.beginPath();ctx.moveTo(60,H/2);ctx.lineTo(W-60,H/2);ctx.stroke();ctx.beginPath();ctx.arc(W/2,H/2,100,0,Math.PI*2);ctx.stroke();ctx.fillStyle='rgba(0,0,0,0.6)';ctx.fillRect(0,0,W,180);ctx.fillStyle='#00ff88';ctx.font='bold 24px sans-serif';ctx.textAlign='center';ctx.fillText('STARTING LINEUP',W/2,50);ctx.fillStyle='#fff';ctx.font='bold 44px sans-serif';ctx.fillText(d.home_name||'',W/2,110);ctx.fillStyle='rgba(255,255,255,0.5)';ctx.font='22px sans-serif';ctx.fillText(d.match_date,W/2,155);var pc={'GK':[[540,1100]],'LB':[[180,920]],'CB':[[400,950],[680,950]],'RB':[[900,920]],'CDM':[[540,760]],'LM':[[140,620]],'CM':[[400,650],[680,650]],'RM':[[940,620]],'CAM':[[540,490]],'LW':[[180,350]],'ST':[[540,300]],'RW':[[900,350]]};var colors={'GK':'#ff9500','LB':'#3a9ef5','CB':'#3a9ef5','RB':'#3a9ef5','CDM':'#00c87a','LM':'#00ff88','CM':'#00ff88','RM':'#00ff88','CAM':'#ffd60a','LW':'#ff6b6b','ST':'#ff6b6b','RW':'#ff6b6b'};if(d.lineup&&d.lineup.length>0){d.lineup.forEach(function(p){var pos=p.position||'CM';var coords=pc[pos]||[[540,650]];var co=coords.shift()||[540,650];ctx.beginPath();ctx.arc(co[0],co[1],35,0,Math.PI*2);ctx.fillStyle=colors[pos]||'#00ff88';ctx.fill();ctx.strokeStyle='rgba(255,255,255,0.8)';ctx.lineWidth=3;ctx.stroke();ctx.fillStyle='#fff';ctx.font='bold 18px sans-serif';ctx.textAlign='center';ctx.fillText((p.name||'').substring(0,3),co[0],co[1]+7);});}ctx.fillStyle='rgba(255,255,255,0.15)';ctx.font='bold 24px sans-serif';ctx.textAlign='center';ctx.fillText('BALLOCHA',W/2,H-30);_shareImg(cv,'lineup');}).catch(function(e){alert(e.message);});}
  function shareMOM(mid){fetch('?page=api&fn=match_share_data&id='+mid,{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(data){if(!data.ok||!data.match.mom_name){alert('MOM 없음');return;}var d=data.match,W=1080,H=1080,cv=document.createElement('canvas');cv.width=W;cv.height=H;var ctx=cv.getContext('2d');var g=ctx.createLinearGradient(0,0,W,H);g.addColorStop(0,'#1a0a00');g.addColorStop(0.5,'#2d1800');g.addColorStop(1,'#1a0a00');ctx.fillStyle=g;ctx.fillRect(0,0,W,H);ctx.fillStyle='#ffb400';ctx.fillRect(0,0,W,6);ctx.fillRect(0,H-6,W,6);ctx.fillStyle='#ffd700';ctx.font='bold 36px sans-serif';ctx.textAlign='center';ctx.fillText('MAN OF THE MATCH',W/2,200);ctx.fillStyle='#fff';ctx.font='bold 80px sans-serif';ctx.fillText(d.mom_name,W/2,350);ctx.fillStyle='rgba(255,255,255,0.5)';ctx.font='28px sans-serif';ctx.fillText(d.home_name+' vs '+d.away_name,W/2,450);ctx.fillText(d.match_date,W/2,500);ctx.fillStyle='#00ff88';ctx.font='bold 60px sans-serif';ctx.fillText(d.score_home+' : '+d.score_away,W/2,650);ctx.fillStyle='rgba(255,255,255,0.15)';ctx.font='bold 24px sans-serif';ctx.fillText('BALLOCHA',W/2,H-40);_shareImg(cv,'mom');}).catch(function(e){alert(e.message);});}
  function _shareImg(cv,type){cv.toBlob(function(blob){var file=new File([blob],'ballocha_'+type+'.png',{type:'image/png'});if(navigator.share&&navigator.canShare&&navigator.canShare({files:[file]})){navigator.share({title:'BALLOCHA',files:[file]}).catch(function(){});}else{var a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=file.name;a.click();URL.revokeObjectURL(a.href);}},'image/png');}

  // 기존 shareMatchResult 제거됨 - 위 새 버전 사용
  function _old_shareMatchResult(matchId, homeN, awayN, scoreH, scoreA, matchDate, location, momName) {
    var data = {
      match_date: matchDate, home_name: homeN, away_name: awayN,
      score_home: scoreH, score_away: scoreA, location: location, mom_name: momName||''
    };
    var canvas = generateMatchCard(data);
    shareCard('match_result', canvas);
  }

  </script>

  <?php endif; ?>

  <div style="margin-top:20px;padding:10px;background:rgba(255,77,109,0.05);border:1px dashed rgba(255,77,109,0.2);border-radius:10px;font-size:11px;color:var(--text-sub);text-align:center">
    ⚠️ 모든 액션은 CSRF + 권한 이중 체크 후 실행되며 admin_logs에 기록됩니다.
  </div>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 마스터 어드민 (SUPER_ADMIN 전용 인앱 통합 대시보드)
// ═══════════════════════════════════════════════════════════════
function pagAdminMaster(PDO $pdo): void {
    if (!isSuperAdmin()) { flash('SUPER_ADMIN만 접근 가능합니다.', 'error'); redirect('?page=home'); }

    // === 통계 ===
    $stat = [
        'users' => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'teams' => (int)$pdo->query("SELECT COUNT(*) FROM teams WHERE status != 'BANNED'")->fetchColumn(),
        'matches' => (int)$pdo->query("SELECT COUNT(*) FROM matches WHERE status NOT IN ('cancelled')")->fetchColumn(),
        'today_signup' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
    ];

    // === 경기장 인증 대기 ===
    $venueQ = $pdo->query("
        SELECT vv.id, vv.receipt_image_url, vv.created_at AS submitted_at,
               u.name AS submitter_name, u.nickname AS submitter_nick,
               m.id AS match_id, m.title AS match_title, m.match_date, m.location
        FROM venue_verifications vv
        JOIN users u ON u.id=vv.submitted_by
        LEFT JOIN matches m ON m.id=vv.match_id
        WHERE vv.status='PENDING'
        ORDER BY vv.created_at DESC LIMIT 20
    ");
    $pendingVenues = $venueQ->fetchAll();

    // === 신고 대기 ===
    $reportQ = $pdo->query("
        SELECT r.id, r.reason, r.created_at, r.match_id,
               COALESCE(NULLIF(TRIM(rep.nickname),''), rep.name) AS reporter_name,
               COALESCE(NULLIF(TRIM(tgt.nickname),''), tgt.name) AS target_name,
               tgt.id AS target_user_id, rep.id AS reporter_id
        FROM reports r
        JOIN users rep ON rep.id=r.reporter_id
        JOIN users tgt ON tgt.id=r.reported_user_id
        WHERE r.status='PENDING' OR r.status IS NULL
        ORDER BY r.created_at DESC LIMIT 20
    ");
    $pendingReports = $reportQ->fetchAll();

    // === 팀 목록 (PENDING 우선) ===
    $teamQ = $pdo->query("
        SELECT t.*, (SELECT COUNT(*) FROM team_members WHERE team_id=t.id AND status='active' AND role != 'mercenary') AS member_count,
               COALESCE(NULLIF(TRIM(u.nickname),''), u.name) AS leader_name
        FROM teams t LEFT JOIN users u ON u.id=t.leader_id
        ORDER BY FIELD(t.status, 'PENDING','ACTIVE','BANNED'), t.id DESC LIMIT 50
    ");
    $teams = $teamQ->fetchAll();

    // === 최근 가입 유저 20명 ===
    $userQ = $pdo->query("
        SELECT u.id, u.name, u.nickname, u.phone, u.manner_score, u.global_role, u.restricted_until, u.created_at,
               (SELECT COUNT(*) FROM user_penalties WHERE user_id=u.id AND penalty_type='BLACKLIST' AND (expires_at IS NULL OR expires_at > NOW())) AS is_blacklisted
        FROM users u
        ORDER BY u.id DESC LIMIT 20
    ");
    $recentUsers = $userQ->fetchAll();
?>
<div class="container">
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
    <i class="bi bi-shield-lock-fill" style="color:#ff4d6d;font-size:22px"></i>
    <h2 style="font-size:20px;font-weight:700;margin:0">마스터 어드민</h2>
  </div>

  <!-- 통계 카드 4종 -->
  <div class="stat-grid">
    <div class="stat-box"><div class="stat-val" style="color:var(--primary)"><?=$stat['users']?></div><div class="stat-lbl">전체 유저</div></div>
    <div class="stat-box"><div class="stat-val" style="color:#3a9ef5"><?=$stat['teams']?></div><div class="stat-lbl">전체 팀</div></div>
    <div class="stat-box"><div class="stat-val" style="color:#ffb400"><?=$stat['matches']?></div><div class="stat-lbl">전체 매치</div></div>
    <div class="stat-box"><div class="stat-val" style="color:#ff6b6b"><?=$stat['today_signup']?></div><div class="stat-lbl">오늘 가입</div></div>
  </div>

  <!-- 경기장 인증 대기 -->
  <p class="section-title" style="margin-top:20px">
    <i class="bi bi-geo-alt-fill"></i> 경기장 인증 대기
    <?php if ($pendingVenues): ?><span class="badge badge-red" style="font-size:10px;margin-left:4px"><?=count($pendingVenues)?></span><?php endif; ?>
  </p>
  <?php if (!$pendingVenues): ?>
  <div class="card"><div class="card-body" style="color:var(--text-sub);text-align:center;padding:20px">대기 중 없음</div></div>
  <?php else: ?>
    <?php foreach ($pendingVenues as $pv): ?>
    <div class="card" style="margin-bottom:10px"><div class="card-body">
      <div style="display:flex;gap:10px;align-items:flex-start">
        <?php if (!empty($pv['receipt_image_url'])): ?>
        <a href="<?=h($pv['receipt_image_url'])?>" target="_blank" style="flex-shrink:0">
          <img src="<?=h($pv['receipt_image_url'])?>" style="width:72px;height:72px;object-fit:cover;border-radius:8px;background:#000">
        </a>
        <?php endif; ?>
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:13px"><?=h($pv['match_title']??'(매치 없음)')?></div>
          <div style="font-size:11px;color:var(--text-sub);margin-top:2px">
            <?=$pv['match_date']??'?'?> · <?=h($pv['location']??'')?><br>
            제출: <?=h($pv['submitter_nick'] ?: $pv['submitter_name'])?> · <?=timeAgo($pv['submitted_at'])?>
          </div>
          <div style="display:flex;gap:6px;margin-top:8px">
            <form method="POST"><?=csrfInput()?><input type="hidden" name="action" value="admin_approve_venue"><input type="hidden" name="verify_id" value="<?=$pv['id']?>"><button type="submit" class="btn btn-primary btn-sm">✓ 승인</button></form>
            <form method="POST"><?=csrfInput()?><input type="hidden" name="action" value="admin_reject_venue"><input type="hidden" name="verify_id" value="<?=$pv['id']?>"><button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)">✕ 거절</button></form>
          </div>
        </div>
      </div>
    </div></div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- 신고 대기 -->
  <p class="section-title" style="margin-top:20px">
    <i class="bi bi-flag-fill"></i> 신고 대기
    <?php if ($pendingReports): ?><span class="badge badge-red" style="font-size:10px;margin-left:4px"><?=count($pendingReports)?></span><?php endif; ?>
  </p>
  <?php if (!$pendingReports): ?>
  <div class="card"><div class="card-body" style="color:var(--text-sub);text-align:center;padding:20px">대기 중 없음</div></div>
  <?php else: ?>
    <?php foreach ($pendingReports as $r): ?>
    <div class="card" style="margin-bottom:10px"><div class="card-body">
      <div style="font-size:12px;color:var(--text-sub);margin-bottom:6px">
        <span style="color:var(--primary)"><?=h($r['reporter_name'])?></span> → <span style="color:#ff6b6b;cursor:pointer" onclick="openUserProfile(<?=(int)$r['target_user_id']?>)"><?=h($r['target_name'])?></span>
        · <?=timeAgo($r['created_at'])?>
      </div>
      <div style="font-size:13px;margin-bottom:10px;padding:8px;background:rgba(0,0,0,0.2);border-radius:6px;white-space:pre-wrap"><?=h(mb_substr($r['reason']??'',0,300))?></div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <form method="POST" onsubmit="return confirm('이 유저를 7일 제한하시겠습니까?')">
          <?=csrfInput()?><input type="hidden" name="action" value="admin_restrict_user">
          <input type="hidden" name="user_id" value="<?=(int)$r['target_user_id']?>">
          <input type="hidden" name="days" value="7">
          <input type="hidden" name="reason" value="신고 처리: <?=h(mb_substr($r['reason']??'',0,100))?>">
          <button type="submit" class="btn btn-sm" style="background:#ff9500;color:#fff">7일 제한</button>
        </form>
        <form method="POST"><?=csrfInput()?><input type="hidden" name="action" value="admin_resolve_report"><input type="hidden" name="report_id" value="<?=$r['id']?>"><button type="submit" class="btn btn-primary btn-sm">처리완료</button></form>
        <form method="POST"><?=csrfInput()?><input type="hidden" name="action" value="admin_dismiss_report"><input type="hidden" name="report_id" value="<?=$r['id']?>"><button type="submit" class="btn btn-ghost btn-sm">무시</button></form>
      </div>
    </div></div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- 팀 관리 -->
  <p class="section-title" style="margin-top:20px"><i class="bi bi-people-fill"></i> 팀 관리 (<?=count($teams)?>)</p>
  <div class="card" style="overflow-x:auto"><table class="tf-table" style="min-width:100%">
    <thead><tr><th>#</th><th>팀</th><th>인원</th><th>상태</th><th>관리</th></tr></thead>
    <tbody>
    <?php foreach ($teams as $t):
      $statColor = ['PENDING'=>'var(--warning)','ACTIVE'=>'var(--primary)','BANNED'=>'var(--danger)'][$t['status']] ?? 'var(--text-sub)'; ?>
    <tr>
      <td style="color:var(--text-sub)"><?=$t['id']?></td>
      <td><div style="font-weight:600"><?=h($t['name'])?></div><div style="font-size:10px;color:var(--text-sub)">리더 <?=h($t['leader_name']??'-')?></div></td>
      <td><?=$t['member_count']?>명</td>
      <td style="color:<?=$statColor?>;font-weight:700;font-size:11px"><?=h($t['status'])?></td>
      <td>
        <div style="display:flex;gap:4px;flex-wrap:wrap">
          <?php if ($t['status']==='PENDING'): ?>
          <form method="POST"><?=csrfInput()?><input type="hidden" name="action" value="admin_activate_team"><input type="hidden" name="team_id" value="<?=$t['id']?>"><button type="submit" class="btn btn-primary btn-sm" style="font-size:10px;padding:2px 6px">활성화</button></form>
          <?php endif; ?>
          <?php if ($t['status']!=='BANNED'): ?>
          <form method="POST" onsubmit="return confirm('팀을 BANNED로 변경하시겠습니까?')"><?=csrfInput()?><input type="hidden" name="action" value="admin_ban_team"><input type="hidden" name="team_id" value="<?=$t['id']?>"><button type="submit" class="btn btn-ghost btn-sm" style="font-size:10px;padding:2px 6px;color:var(--danger)">BAN</button></form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <!-- 최근 유저 -->
  <p class="section-title" style="margin-top:20px"><i class="bi bi-person-lines-fill"></i> 최근 가입 유저 <?=count($recentUsers)?></p>
  <div class="card"><div class="card-body" style="padding:0 16px">
    <?php foreach ($recentUsers as $u):
      $uDn = displayName($u);
      $isBlack = (int)$u['is_blacklisted'] > 0;
      $isRestrict = $u['restricted_until'] && strtotime($u['restricted_until']) > time();
      $isSuper = ($u['global_role']??'') === 'SUPER_ADMIN';
    ?>
    <div class="list-item" style="padding:10px 0">
      <div style="flex:1;min-width:0;cursor:pointer" onclick="openUserProfile(<?=(int)$u['id']?>)">
        <div style="font-weight:600;font-size:13px">
          <?=h($uDn)?>
          <?php if ($isSuper): ?><span class="badge" style="background:#ff4d6d;color:#fff;font-size:9px">SUPER</span><?php endif; ?>
          <?php if ($isBlack): ?><span class="badge badge-red" style="font-size:9px">BLACK</span><?php endif; ?>
          <?php if ($isRestrict): ?><span class="badge" style="background:#ff9500;color:#fff;font-size:9px">~<?=date('m/d',strtotime($u['restricted_until']))?></span><?php endif; ?>
        </div>
        <div style="font-size:10px;color:var(--text-sub)">매너 <?=number_format((float)$u['manner_score'],1)?>° · <?=h($u['phone'])?></div>
      </div>
      <div style="display:flex;gap:4px;flex-wrap:wrap">
        <?php if ((int)$u['id'] !== (int)me()['id']): ?>
        <form method="POST" onsubmit="return confirm('7일 제한하시겠습니까?')"><?=csrfInput()?><input type="hidden" name="action" value="admin_restrict_user"><input type="hidden" name="user_id" value="<?=$u['id']?>"><input type="hidden" name="days" value="7"><button type="submit" class="btn btn-ghost btn-sm" style="font-size:10px;padding:2px 6px;color:#ff9500">7일제한</button></form>
        <?php if (!$isBlack): ?>
        <form method="POST" onsubmit="return confirm('블랙리스트에 등록하시겠습니까? (로그인 차단)')"><?=csrfInput()?><input type="hidden" name="action" value="admin_blacklist_user"><input type="hidden" name="user_id" value="<?=$u['id']?>"><button type="submit" class="btn btn-ghost btn-sm" style="font-size:10px;padding:2px 6px;color:var(--danger)">BLACK</button></form>
        <?php endif; ?>
        <?php if (!$isSuper): ?>
        <form method="POST" onsubmit="return confirm('SUPER_ADMIN으로 승급하시겠습니까?')"><?=csrfInput()?><input type="hidden" name="action" value="admin_promote_super"><input type="hidden" name="user_id" value="<?=$u['id']?>"><button type="submit" class="btn btn-ghost btn-sm" style="font-size:10px;padding:2px 6px;color:#ff4d6d">SUPER</button></form>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div></div>

  <div style="margin-top:20px;font-size:11px;color:var(--text-sub);text-align:center;padding:12px;background:rgba(255,77,109,0.06);border:1px dashed rgba(255,77,109,0.2);border-radius:10px">
    ⚠️ 모든 액션은 CSRF + SUPER_ADMIN 권한 체크 후 실행됩니다. 되돌릴 수 없는 작업은 확인창이 뜹니다.
  </div>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 관리자 대시보드
// ═══════════════════════════════════════════════════════════════
function pagAdminDashboard(PDO $pdo): void {
    requireLogin();
    if (!isAdmin()) { flash('관리자만 접근 가능합니다.', 'error'); redirect('?page=home'); }

    $tab = $_GET['tab'] ?? 'overview';

    // Overview 통계
    $stats = [];
    if ($tab === 'overview') {
        $queries = [
            'total_users'       => "SELECT COUNT(*) FROM users",
            'today_users'       => "SELECT COUNT(*) FROM users WHERE DATE(created_at)=CURDATE()",
            'total_teams'       => "SELECT COUNT(*) FROM teams",
            'active_teams'      => "SELECT COUNT(*) FROM teams WHERE status='ACTIVE'",
            'pending_teams'     => "SELECT COUNT(*) FROM teams WHERE status='PENDING'",
            'total_matches'     => "SELECT COUNT(*) FROM matches",
            'today_matches'     => "SELECT COUNT(*) FROM matches WHERE match_date=CURDATE()",
            'pending_results'   => "SELECT COUNT(*) FROM matches WHERE status='result_pending'",
            'pending_reports'   => "SELECT COUNT(*) FROM reports WHERE status='PENDING' OR status IS NULL",
            'pending_venues'    => "SELECT COUNT(*) FROM venue_verifications WHERE status='pending'",
            'pending_appeals'   => "SELECT COUNT(*) FROM user_appeals WHERE status='pending'",
        ];
        foreach ($queries as $k => $q) {
            try { $stats[$k] = (int)$pdo->query($q)->fetchColumn(); }
            catch (PDOException) { $stats[$k] = 0; }
        }
    }

    // 탭별 데이터
    $data = [];
    switch ($tab) {
        case 'users':
            $search = trim($_GET['q'] ?? '');
            $where  = $search ? "WHERE u.name LIKE ? OR u.phone LIKE ?" : '';
            $stmt   = $pdo->prepare("
                SELECT u.id, u.name, u.phone, u.position, u.system_role, u.manner_score, u.created_at,
                       u.is_player_background,
                       t.name AS team_name, t.id AS team_id, tm.role AS team_role
                FROM users u
                LEFT JOIN team_members tm ON tm.user_id=u.id AND tm.status='active'
                LEFT JOIN teams t ON t.id=tm.team_id
                $where ORDER BY u.id DESC LIMIT 100
            ");
            $search ? $stmt->execute(["%$search%","%$search%"]) : $stmt->execute();
            $data = $stmt->fetchAll();
            break;
        case 'teams':
            $stmt = $pdo->prepare("SELECT t.*,u.name AS leader_name,(SELECT COUNT(*) FROM team_members tm WHERE tm.team_id=t.id AND tm.status='active') AS member_count FROM teams t JOIN users u ON u.id=t.leader_id ORDER BY t.created_at DESC LIMIT 50");
            $stmt->execute(); $data = $stmt->fetchAll();
            break;
        case 'matches':
            $stmt = $pdo->prepare("SELECT m.*,ht.name AS home_name,at.name AS away_name FROM matches m LEFT JOIN teams ht ON ht.id=m.home_team_id LEFT JOIN teams at ON at.id=m.away_team_id ORDER BY m.match_date DESC LIMIT 50");
            $stmt->execute(); $data = $stmt->fetchAll();
            break;
        case 'reports':
            $stmt = $pdo->prepare("SELECT r.*,u.name AS reporter_name FROM reports r JOIN users u ON u.id=r.reporter_id WHERE r.status='PENDING' OR r.status IS NULL ORDER BY r.created_at DESC LIMIT 50");
            $stmt->execute(); $data = $stmt->fetchAll();
            break;
        case 'venues':
            $stmt = $pdo->prepare("SELECT vv.*,u.name AS submitter_name,m.title AS match_title FROM venue_verifications vv JOIN users u ON u.id=vv.submitted_by JOIN matches m ON m.id=vv.match_id WHERE vv.status='pending' ORDER BY vv.submitted_at DESC");
            $stmt->execute(); $data = $stmt->fetchAll();
            break;
        case 'appeals':
            $stmt = $pdo->prepare("SELECT a.*, u.name AS user_name, u.phone AS user_phone, u.restricted_until, u.ban_reason FROM user_appeals a JOIN users u ON u.id=a.user_id ORDER BY FIELD(a.status,'pending','rejected','approved'), a.created_at DESC LIMIT 50");
            $stmt->execute(); $data = $stmt->fetchAll();
            break;
    }
    ?>
<div class="container">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <h2 style="font-size:18px;font-weight:700"><i class="bi bi-shield-fill" style="color:var(--primary)"></i> 관리자 대시보드</h2>
  </div>

  <!-- 탭 -->
  <div class="chip-row" style="margin-bottom:16px;overflow-x:auto">
    <?php foreach(['overview'=>'개요','users'=>'유저','teams'=>'팀','matches'=>'경기','reports'=>'신고','venues'=>'인증','appeals'=>'이의제기'] as $k=>$v): ?>
    <a href="?page=admin_dashboard&tab=<?=$k?>" class="chip <?=$tab===$k?'active':''?>"><?=$v?></a>
    <?php endforeach; ?>
  </div>

  <?php if($tab === 'overview'): ?>
  <!-- 개요 카드 -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
    <?php $cards = [
      ['👤','전체 유저',$stats['total_users'],'오늘 +'.$stats['today_users'],'green'],
      ['🛡️','전체 팀',$stats['total_teams'],'활성 '.$stats['active_teams'].' / 대기 '.$stats['pending_teams'],'blue'],
      ['⚽','전체 경기',$stats['total_matches'],'오늘 '.$stats['today_matches'].'경기','yellow'],
      ['⏳','결과 대기',$stats['pending_results'],'승인 필요','warning'],
      ['🚨','미처리 신고',$stats['pending_reports'],'처리 필요','red'],
      ['📍','인증 대기',$stats['pending_venues'],'경기장 인증','blue'],
      ['📋','이의제기',$stats['pending_appeals'] ?? 0,'처리 필요','warning'],
    ];
    foreach($cards as [$ico,$label,$val,$sub,$color]): ?>
    <a href="?page=admin_dashboard&tab=<?=match($label){'전체 유저'=>'users','전체 팀'=>'teams','전체 경기'=>'matches',default=>'overview'}?>" style="text-decoration:none">
    <div class="card" style="text-align:center">
      <div class="card-body" style="padding:16px 12px">
        <div style="font-size:28px;margin-bottom:4px"><?=$ico?></div>
        <div style="font-size:11px;color:var(--text-sub);margin-bottom:4px"><?=$label?></div>
        <div style="font-size:26px;font-weight:900;font-family:'Space Grotesk',sans-serif;color:var(--<?=$color==='red'?'danger':($color==='warning'?'warning':($color==='green'?'primary':($color==='yellow'?'warning':'info')))?>)"><?=$val?></div>
        <div style="font-size:11px;color:var(--text-sub);margin-top:4px"><?=$sub?></div>
      </div>
    </div>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- 관리 도구 링크 -->
  <div style="margin-bottom:16px">
    <a href="?page=state_diagram" class="btn btn-outline btn-w" style="font-size:13px"><i class="bi bi-diagram-3"></i> 상태전이 다이어그램</a>
  </div>

  <?php elseif($tab === 'users'): ?>
  <form method="GET" style="display:flex;gap:8px;margin-bottom:12px">
    <input type="hidden" name="page" value="admin_dashboard">
    <input type="hidden" name="tab" value="users">
    <input type="text" name="q" class="form-control" placeholder="이름 또는 전화번호 검색" value="<?=h($_GET['q']??'')?>">
    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
  </form>
  <?php foreach($data as $u): ?>
  <div class="card" style="margin-bottom:8px">
    <div class="card-body">
      <div style="display:flex;align-items:flex-start;gap:10px">
        <div style="flex:1">
          <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:3px">
            <span style="font-weight:700"><?=h($u['name'])?></span>
            <span style="font-size:11px;color:var(--text-sub)">#<?=$u['id']?></span>
            <?php if($u['system_role']==='admin'): ?><span class="badge badge-red" style="font-size:9px">관리자</span><?php endif; ?>
            <?php if($u['is_player_background']??0): ?><span class="badge badge-blue" style="font-size:9px">⚽ 선수출신</span><?php endif; ?>
          </div>
          <div style="font-size:12px;color:var(--text-sub)"><?=h($u['phone'])?> · 매너<?=number_format((float)$u['manner_score'],1)?>°</div>
          <?php if($u['team_name']): ?>
          <div style="font-size:11px;color:var(--text-sub);margin-top:2px">
            🛡️ <?=h($u['team_name'])?> (<?=h($u['team_role']??'')?>)
          </div>
          <?php else: ?>
          <div style="font-size:11px;color:var(--text-sub);margin-top:2px">팀 없음</div>
          <?php endif; ?>
        </div>
        <div style="display:flex;flex-direction:column;gap:4px">
          <!-- 역할 변경 -->
          <?php if($u['system_role']!=='admin'): ?>
          <form method="POST">
            <?=csrfInput()?><input type="hidden" name="action" value="admin_user_role">
            <input type="hidden" name="user_id" value="<?=$u['id']?>">
            <input type="hidden" name="system_role" value="admin">
            <button type="submit" class="btn btn-outline btn-sm" style="font-size:11px;padding:4px 8px">관리자 지정</button>
          </form>
          <?php else: ?>
          <form method="POST">
            <?=csrfInput()?><input type="hidden" name="action" value="admin_user_role">
            <input type="hidden" name="user_id" value="<?=$u['id']?>">
            <input type="hidden" name="system_role" value="user">
            <button type="submit" class="btn btn-ghost btn-sm" style="font-size:11px;padding:4px 8px">권한 해제</button>
          </form>
          <?php endif; ?>
          <!-- 팀 제거 -->
          <?php if($u['team_id']): ?>
          <form method="POST" onsubmit="return confirm('팀에서 제거하시겠습니까?')">
            <?=csrfInput()?><input type="hidden" name="action" value="admin_user_team_remove">
            <input type="hidden" name="user_id" value="<?=$u['id']?>">
            <input type="hidden" name="team_id" value="<?=$u['team_id']?>">
            <button type="submit" class="btn btn-danger btn-sm" style="font-size:11px;padding:4px 8px">팀 제거</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php elseif($tab === 'teams'): ?>
  <?php foreach($data as $t): ?>
  <div class="card" style="margin-bottom:8px">
    <div class="card-body" style="display:flex;align-items:center;gap:10px">
      <div style="flex:1">
        <div style="font-weight:600"><?=h($t['name'])?> <span class="badge badge-<?=match($t['status']){'ACTIVE'=>'green','BANNED'=>'red',default=>'yellow'}?>" style="font-size:10px"><?=h($t['status'])?></span></div>
        <div style="font-size:12px;color:var(--text-sub)"><?=h($t['region'])?> · 팀장: <?=h($t['leader_name'])?> · <?=$t['member_count']?>명</div>
      </div>
      <div style="display:flex;gap:4px">
        <?php if($t['status']==='PENDING'): ?>
        <form method="POST">
          <?=csrfInput()?><input type="hidden" name="action" value="admin_team_status">
          <input type="hidden" name="team_id" value="<?=$t['id']?>">
          <input type="hidden" name="status" value="ACTIVE">
          <button type="submit" class="btn btn-primary btn-sm">활성화</button>
        </form>
        <?php endif; ?>
        <?php if($t['status']!=='BANNED'): ?>
        <form method="POST">
          <?=csrfInput()?><input type="hidden" name="action" value="admin_team_status">
          <input type="hidden" name="team_id" value="<?=$t['id']?>">
          <input type="hidden" name="status" value="BANNED">
          <button type="submit" class="btn btn-danger btn-sm">제재</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php elseif($tab === 'reports'): ?>
  <?php foreach($data as $r): ?>
  <div class="card" style="margin-bottom:8px">
    <div class="card-body">
      <div style="display:flex;justify-content:space-between;margin-bottom:6px">
        <div style="font-weight:600"><?=h($r['reason'])?></div>
        <span class="badge badge-yellow">PENDING</span>
      </div>
      <div style="font-size:12px;color:var(--text-sub)">신고자: <?=h($r['reporter_name'])?> · <?=h($r['target_type'])?> #<?=h($r['target_id'])?></div>
      <div style="display:flex;gap:6px;margin-top:8px">
        <form method="POST" style="flex:1">
          <?=csrfInput()?><input type="hidden" name="action" value="resolve_report">
          <input type="hidden" name="report_id" value="<?=$r['id']?>"><input type="hidden" name="status" value="resolved">
          <button type="submit" class="btn btn-primary btn-w btn-sm">처리완료</button>
        </form>
        <form method="POST" style="flex:1">
          <?=csrfInput()?><input type="hidden" name="action" value="resolve_report">
          <input type="hidden" name="report_id" value="<?=$r['id']?>"><input type="hidden" name="status" value="dismissed">
          <button type="submit" class="btn btn-ghost btn-w btn-sm">기각</button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php elseif($tab === 'matches'): ?>
  <?php foreach($data as $m): ?>
  <div class="card" style="margin-bottom:8px">
    <div class="card-body">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px">
        <div style="font-weight:600;font-size:14px;flex:1"><?=h($m['home_name']??'?')?> vs <?=h($m['away_name']??'모집중')?></div>
        <?=statusBadge($m['status'], $m['match_type'] ?? '')?>
      </div>
      <div style="font-size:12px;color:var(--text-sub);margin-bottom:8px"><?=$m['match_date']?> <?=dayOfWeek($m['match_date'])?> <?=matchTimeStr($m)?> · <?=h($m['location']??'')?></div>
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <a href="?page=match&id=<?=$m['id']?>" class="btn btn-outline btn-sm">상세보기</a>
        <form method="POST" style="display:flex;gap:4px;align-items:center">
          <?=csrfInput()?><input type="hidden" name="action" value="admin_match_status">
          <input type="hidden" name="match_id" value="<?=$m['id']?>">
          <select name="status" class="form-control" style="height:32px;padding:0 8px;font-size:12px;width:auto">
            <?php foreach(['open'=>'모집중','confirmed'=>'확정','in_progress'=>'진행중','result_pending'=>'결과대기','completed'=>'완료','cancelled'=>'취소'] as $sv=>$sl): ?>
            <option value="<?=$sv?>" <?=$m['status']===$sv?'selected':''?>><?=$sl?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-primary btn-sm">변경</button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php elseif($tab === 'venues'): ?>
  <?php foreach($data as $v): ?>
  <div class="card" style="margin-bottom:8px">
    <div class="card-body">
      <div style="font-weight:600;margin-bottom:4px"><?=h($v['match_title'])?></div>
      <div style="font-size:12px;color:var(--text-sub);margin-bottom:8px">제출: <?=h($v['submitter_name'])?> · <?=$v['submitted_at']??''?></div>
      <?php if($v['receipt_image_url']): ?>
      <img src="/<?=h($v['receipt_image_url'])?>" style="width:100%;max-height:200px;object-fit:cover;border-radius:8px;margin-bottom:8px" alt="인증 이미지">
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  
  <?php elseif($tab === 'appeals'): ?>
  <!-- [TF-11] 이의제기 관리 -->
  <div style="font-size:14px;font-weight:700;margin-bottom:12px"><i class="bi bi-chat-left-text"></i> 제재 이의제기 목록</div>
  <?php if(empty($data)): ?>
  <div style="text-align:center;padding:40px;color:var(--text-sub)">
    <div style="font-size:32px;margin-bottom:8px">&#x1F4CB;</div>
    <div>이의제기 내역이 없습니다</div>
  </div>
  <?php else: ?>
  <?php foreach($data as $ap):
    $stColor = match($ap['status']){'pending'=>'badge-yellow','approved'=>'badge-green','rejected'=>'badge-red',default=>'badge-gray'};
    $stLabel = match($ap['status']){'pending'=>'대기중','approved'=>'승인','rejected'=>'기각',default=>$ap['status']};
  ?>
  <div class="card" style="margin-bottom:10px">
    <div class="card-body">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div>
          <span style="font-weight:700"><?=h($ap['user_name'])?></span>
          <span style="font-size:11px;color:var(--text-sub);margin-left:6px"><?=h($ap['user_phone'])?></span>
        </div>
        <span class="badge <?=$stColor?>"><?=$stLabel?></span>
      </div>
      <?php if($ap['restricted_until']): ?>
      <div style="font-size:11px;color:var(--danger);margin-bottom:6px">제재 만료: <?=h($ap['restricted_until'])?> <?php if($ap['ban_reason']): ?>| 사유: <?=h($ap['ban_reason'])?><?php endif; ?></div>
      <?php endif; ?>
      <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:10px;font-size:13px;margin-bottom:8px"><?=nl2br(h($ap['reason']))?></div>
      <div style="font-size:10px;color:var(--text-sub)">접수: <?=date('Y-m-d H:i', strtotime($ap['created_at']))?></div>
      <?php if($ap['admin_note']): ?>
      <div style="font-size:11px;color:var(--info);margin-top:4px">관리자 메모: <?=h($ap['admin_note'])?></div>
      <?php endif; ?>
      <?php if($ap['reviewed_at']): ?>
      <div style="font-size:10px;color:var(--text-sub);margin-top:2px">처리: <?=date('Y-m-d H:i', strtotime($ap['reviewed_at']))?></div>
      <?php endif; ?>
      <?php if($ap['status'] === 'pending'): ?>
      <div style="display:flex;gap:6px;margin-top:10px">
        <form method="POST" style="flex:1">
          <?=csrfInput()?><input type="hidden" name="action" value="review_appeal">
          <input type="hidden" name="appeal_id" value="<?=$ap['id']?>">
          <input type="hidden" name="decision" value="approved">
          <input type="text" name="admin_note" class="form-control" placeholder="관리자 메모 (선택)" style="margin-bottom:6px;font-size:12px">
          <button type="submit" class="btn btn-primary btn-sm btn-w" onclick="return confirm('제재를 해제하시겠습니까?')">승인 (제재 해제)</button>
        </form>
        <form method="POST" style="flex:1">
          <?=csrfInput()?><input type="hidden" name="action" value="review_appeal">
          <input type="hidden" name="appeal_id" value="<?=$ap['id']?>">
          <input type="hidden" name="decision" value="rejected">
          <input type="text" name="admin_note" class="form-control" placeholder="기각 사유 (선택)" style="margin-bottom:6px;font-size:12px">
          <button type="submit" class="btn btn-outline btn-sm btn-w" style="color:var(--danger);border-color:var(--danger)" onclick="return confirm('이의제기를 기각하시겠습니까?')">기각</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php endif; ?>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// [1-4] MOM 투표 페이지
// ═══════════════════════════════════════════════════════════════
function pagMomVote(PDO $pdo): void {
    $matchId = (int)($_GET['match_id'] ?? 0);
    if (!$matchId) { redirect('?page=matches'); }
    $m = $pdo->prepare("SELECT m.*, ht.name AS home_name, at.name AS away_name FROM matches m
        LEFT JOIN teams ht ON ht.id=m.home_team_id LEFT JOIN teams at ON at.id=m.away_team_id WHERE m.id=?");
    $m->execute([$matchId]); $match = $m->fetch();
    if (!$match) { flash('매치를 찾을 수 없습니다.', 'error'); redirect('?page=matches'); }

    // 참석자 (양 팀 모두)
    $att = $pdo->prepare("SELECT u.id, u.name, u.nickname, u.profile_image_url, u.position, u.mom_count, ma.team_id, t.name AS tname
        FROM match_attendance ma JOIN users u ON u.id=ma.user_id LEFT JOIN teams t ON t.id=ma.team_id
        WHERE ma.match_id=? AND ma.status='ATTEND' ORDER BY ma.team_id, u.name");
    $att->execute([$matchId]); $attendees = $att->fetchAll();

    // 내 투표 여부
    $myVote = $pdo->prepare("SELECT voted_user_id FROM mom_votes WHERE match_id=? AND voter_id=?");
    $myVote->execute([$matchId, me()['id']]); $voted = $myVote->fetchColumn();

    // 현재 득표 집계
    $stat = $pdo->prepare("SELECT voted_user_id, COUNT(*) AS cnt FROM mom_votes WHERE match_id=? GROUP BY voted_user_id ORDER BY cnt DESC");
    $stat->execute([$matchId]); $voteStats = [];
    foreach ($stat->fetchAll() as $vr) $voteStats[(int)$vr['voted_user_id']] = (int)$vr['cnt'];
    $totalVotes = array_sum($voteStats);
    $maxVotes = $voteStats ? max($voteStats) : 0;
?>
<div class="container">
  <div style="margin-bottom:16px">
    <a href="?page=match&id=<?=$matchId?>" style="color:var(--text-sub);text-decoration:none;font-size:13px"><i class="bi bi-arrow-left"></i> 매치로</a>
    <h2 style="font-size:20px;font-weight:700;margin-top:8px">🏆 MOM 투표</h2>
    <div style="font-size:13px;color:var(--text-sub);margin-top:4px"><?=h($match['title'] ?: $match['location'])?> · <?=$match['match_date']?></div>
  </div>

  <?php if ($voted): ?>
  <div class="tf-alert tf-alert-ok">투표 완료! 현재 득표 현황입니다.</div>
  <?php endif; ?>

  <?php if (!$attendees): ?>
  <div style="text-align:center;padding:60px 0;color:var(--text-sub)">참석자 정보가 없습니다.</div>
  <?php else: foreach ($attendees as $a):
    $cnt = $voteStats[(int)$a['id']] ?? 0;
    $isWinner = $maxVotes > 0 && $cnt === $maxVotes;
    $isVotedByMe = $voted == $a['id'];
  ?>
  <div class="card" style="margin-bottom:8px;<?=$isWinner?'border-color:#ffd60a':''?>">
    <div class="card-body" style="display:flex;align-items:center;gap:12px">
      <?php $aDn = displayName($a); ?>
      <?php if (!empty($a['profile_image_url'])): ?>
        <?= renderAvatar($a, 42, $isWinner ? 'border:3px solid #ffd60a' : 'border:2px solid var(--primary)') ?>
      <?php else: ?>
      <div style="width:42px;height:42px;border-radius:50%;background:<?=$isWinner?'#ffd60a':'var(--primary-glow)'?>;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;color:<?=$isWinner?'#000':'var(--primary)'?>;flex-shrink:0">
        <?=mb_substr($aDn,0,1,'UTF-8')?>
      </div>
      <?php endif; ?>
      <div style="flex:1">
        <div style="font-weight:700"><?=h($aDn)?>
          <?php if($isWinner): ?><span style="font-size:14px">🏆</span><?php endif; ?>
          <?php if((int)$a['mom_count']>0): ?><span style="font-size:11px;color:var(--text-sub)">(통산 MOM <?=(int)$a['mom_count']?>회)</span><?php endif; ?>
        </div>
        <div style="font-size:12px;color:var(--text-sub)"><?=h($a['tname'] ?? '')?> · <?=h($a['position'] ?? '-')?></div>
        <?php if($totalVotes>0): ?>
        <div style="height:6px;background:var(--bg-surface-alt);border-radius:3px;margin-top:6px;overflow:hidden">
          <div style="height:100%;width:<?=$cnt/max(1,$totalVotes)*100?>%;background:<?=$isWinner?'#ffd60a':'var(--primary)'?>"></div>
        </div>
        <?php endif; ?>
      </div>
      <div style="text-align:right">
        <div style="font-size:18px;font-weight:900;color:<?=$isWinner?'#ffd60a':'var(--primary)'?>;font-family:'Space Grotesk',sans-serif"><?=$cnt?></div>
        <?php if (!$voted && $a['id'] != me()['id']): ?>
        <form method="POST" style="margin-top:4px">
          <?=csrfInput()?>
          <input type="hidden" name="action" value="vote_mom">
          <input type="hidden" name="match_id" value="<?=$matchId?>">
          <input type="hidden" name="voted_user_id" value="<?=(int)$a['id']?>">
          <button type="submit" class="btn btn-primary btn-sm">투표</button>
        </form>
        <?php elseif($isVotedByMe): ?>
        <span style="font-size:11px;color:var(--primary);font-weight:700">내 투표</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; endif; ?>

  <?php if($voted): ?>
  <form method="POST" style="margin-top:16px">
    <?=csrfInput()?>
    <input type="hidden" name="action" value="finish_loop">
    <input type="hidden" name="match_id" value="<?=$matchId?>">
    <button type="submit" class="btn btn-primary btn-w">🎯 다음 매치 잡으러 가기</button>
  </form>
  <?php endif; ?>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// [1-2] 캡틴이 상대팀 평가하는 페이지
// ═══════════════════════════════════════════════════════════════
function pagTeamEval(PDO $pdo): void {
    $matchId = (int)($_GET['match_id'] ?? 0);
    if (!$matchId || !isCaptain()) { redirect('?page=match&id='.$matchId); }
    $m = $pdo->prepare("SELECT m.*, ht.name AS home_name, at.name AS away_name FROM matches m
        LEFT JOIN teams ht ON ht.id=m.home_team_id LEFT JOIN teams at ON at.id=m.away_team_id WHERE m.id=?");
    $m->execute([$matchId]); $match = $m->fetch();
    if (!$match) { flash('매치 없음','error'); redirect('?page=matches'); }
    $myTid = myTeamId();
    $targetTid = $myTid == $match['home_team_id'] ? (int)$match['away_team_id'] : (int)$match['home_team_id'];
    $targetName = $myTid == $match['home_team_id'] ? $match['away_name'] : $match['home_name'];
    // 이미 평가했는지
    $done = $pdo->prepare("SELECT id FROM reviews WHERE match_id=? AND reviewer_id=? AND target_type='team' AND target_id=?");
    $done->execute([$matchId, me()['id'], $targetTid]);
?>
<div class="container">
  <div style="margin-bottom:16px">
    <a href="?page=match&id=<?=$matchId?>" style="color:var(--text-sub);text-decoration:none;font-size:13px"><i class="bi bi-arrow-left"></i> 매치로</a>
    <h2 style="font-size:20px;font-weight:700;margin-top:8px">상대팀 평가</h2>
    <div style="font-size:13px;color:var(--text-sub);margin-top:4px">vs <strong style="color:var(--primary)"><?=h($targetName)?></strong></div>
  </div>
  <?php if($done->fetch()): ?>
  <div class="tf-alert tf-alert-ok">이미 평가하셨습니다. 양 팀 캡틴 모두 평가하면 결과가 공식 반영됩니다.</div>
  <?php else: ?>
  <form method="POST">
    <?=csrfInput()?>
    <input type="hidden" name="action" value="submit_team_eval">
    <input type="hidden" name="match_id" value="<?=$matchId?>">
    <input type="hidden" name="target_team_id" value="<?=$targetTid?>">

    <div class="card" style="margin-bottom:14px"><div class="card-body">
      <p class="section-title">매너 (캡틴 매너점수에 반영)</p>
      <?php
      $opts = [
        'manner' => [['good','매너굿','+0.5°','#00ff88'],['normal','보통','0','#888'],['rough','거칠어요','-1°','#ff4d6d']],
        'time'   => [['ontime','정시','0','#00ff88'],['late','지각','-0.5°','#ff9500']],
        'overall'=> [['recommend','추천','+0.3°','#00ff88'],['not','비추','-0.5°','#ff4d6d']],
      ];
      $titles = ['manner'=>'매너','time'=>'시간','overall'=>'전반적']; ?>
      <?php foreach ($opts as $k => $list): ?>
      <div style="margin-bottom:14px">
        <div style="font-size:12px;font-weight:600;color:var(--text-sub);margin-bottom:6px"><?=$titles[$k]?></div>
        <div style="display:grid;grid-template-columns:repeat(<?=count($list)?>,1fr);gap:6px">
          <?php foreach ($list as $i=>[$v,$lbl,$delta,$col]): ?>
          <label style="cursor:pointer">
            <input type="radio" name="<?=$k?>" value="<?=$v?>" <?=$i===0?'checked':''?> style="display:none" class="pos-radio2">
            <div class="pos-chip" style="--pos-color:<?=$col?>">
              <div style="font-size:13px;font-weight:700"><?=$lbl?></div>
              <div style="font-size:10px;opacity:0.7"><?=$delta?></div>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div></div>
    <button type="submit" class="btn btn-primary btn-w">평가 완료</button>
  </form>
  <?php endif; ?>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// [4단계] 용병 SOS 생성 페이지 (캡틴)
// ═══════════════════════════════════════════════════════════════
function pagSosCreate(PDO $pdo): void {
    $matchId = (int)($_GET['match_id'] ?? 0);
    if (!$matchId || !isCaptain()) { redirect('?page=match&id='.$matchId); }
?>
<div class="container">
  <h2 style="font-size:20px;font-weight:700;margin-bottom:16px">🚨 긴급 용병 호출</h2>
  <form method="POST">
    <?=csrfInput()?>
    <input type="hidden" name="action" value="create_sos">
    <input type="hidden" name="match_id" value="<?=$matchId?>">
    <div class="card"><div class="card-body">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">부족 인원</label>
          <select name="needed_count" class="form-select" required>
            <?php for($i=1;$i<=5;$i++) echo "<option value=\"$i\">{$i}명</option>"; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">필요 포지션</label>
          <select name="position_needed" class="form-select">
            <option value="">무관</option>
            <?php foreach(['GK','DF','MF','FW'] as $p) echo "<option value=\"$p\">$p</option>"; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">한줄 메시지</label>
        <textarea name="message" class="form-control" rows="3" placeholder="긴급해요! 매너 좋으신 분 환영" required></textarea>
      </div>
    </div></div>
    <button type="submit" class="btn btn-primary btn-w" style="margin-top:14px">🚨 SOS 발송</button>
  </form>
</div>
<?php }

// ═══════════════════════════════════════════════════════════════
// 알림 목록 페이지
// ═══════════════════════════════════════════════════════════════
// [TF-25] 제재 이의제기 페이지
function pagAppeal(PDO $pdo): void {
    $penInfo = $_SESSION['penalty_info'] ?? null;
    ?>
<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>이의제기 - TRUST FOOTBALL</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{--bg-main:#0F1117;--bg-surface:#1A1D27;--bg-surface-alt:#242837;--primary:#00ff88;--danger:#ff4d6d;--warning:#ffd60a;--text-main:#E8E8EC;--text-sub:#9095A4;--border:rgba(255,255,255,0.06)}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg-main);color:var(--text-main);min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px}
.appeal-card{background:var(--bg-surface);border-radius:16px;border:1px solid var(--border);padding:32px 24px;max-width:420px;width:100%;text-align:center}
.btn-primary{background:var(--primary);color:#0F1117;border:none;padding:12px 24px;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;width:100%}
.btn-outline{background:transparent;color:var(--text-sub);border:1px solid var(--border);padding:10px 20px;border-radius:10px;font-size:13px;cursor:pointer;text-decoration:none;display:inline-block}
textarea{width:100%;background:var(--bg-surface-alt);border:1px solid var(--border);border-radius:10px;color:var(--text-main);padding:12px;font-size:14px;min-height:120px;resize:vertical;margin:12px 0}
</style></head><body>
<div class="appeal-card">
  <div style="font-size:48px;margin-bottom:16px">&#9878;</div>
  <h2 style="font-size:20px;font-weight:800;margin-bottom:8px">제재 이의제기</h2>
  <?php if (!$penInfo): ?>
    <p style="color:var(--text-sub);font-size:14px;margin-bottom:20px">이의제기를 하려면 먼저 로그인을 시도해주세요.<br>제재 정보가 확인된 후 이의제기를 할 수 있습니다.</p>
    <a href="?page=login" class="btn-outline">로그인 페이지로</a>
  <?php else: ?>
    <div style="background:rgba(255,77,109,0.1);border:1px solid rgba(255,77,109,0.3);border-radius:10px;padding:14px;margin-bottom:16px;text-align:left">
      <div style="font-size:12px;color:var(--danger);font-weight:700;margin-bottom:6px">현재 제재 정보</div>
      <div style="font-size:13px;color:var(--text-sub)">
        대상: <?=htmlspecialchars($penInfo['user_name'])?><br>
        유형: <?=$penInfo['penalty_type'] === 'BLACKLIST' ? '영구정지' : '이용제한'?><br>
        사유: <?=htmlspecialchars($penInfo['reason'])?><br>
        <?php if($penInfo['expires_at']): ?>만료: <?=$penInfo['expires_at']?><?php endif; ?>
      </div>
    </div>
    <form method="POST" style="text-align:left">
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'] ?? '')?>">
      <input type="hidden" name="action" value="submit_appeal">
      <label style="font-size:13px;font-weight:700;display:block;margin-bottom:4px">이의제기 사유</label>
      <textarea name="appeal_reason" placeholder="제재에 대한 이의제기 사유를 상세히 작성해주세요. (10자 이상)" required minlength="10"></textarea>
      <button type="submit" class="btn-primary" style="margin-top:8px">이의제기 제출</button>
    </form>
    <a href="?page=login" class="btn-outline" style="margin-top:12px">돌아가기</a>
  <?php endif; ?>
</div>
</body></html>
<?php }

function pagNotifications(PDO $pdo): void {
    $filter = $_GET['f'] ?? 'all'; // all | unread
    $unreadOnly = $filter === 'unread' ? " AND is_read=0" : '';
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? $unreadOnly ORDER BY created_at DESC LIMIT 100");
    $stmt->execute([me()['id']]);
    $notifs = $stmt->fetchAll();
    // 미읽음 카운트 (필터와 무관하게 배지용)
    $unreadCnt = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=".(int)me()['id']." AND is_read=0")->fetchColumn();
    // 일괄 읽음 처리 — 페이지 진입 시 자동 (기존 동작 유지)
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0")->execute([me()['id']]);

    // [그룹핑] 오늘 / 이번주 / 이전
    $today = date('Y-m-d');
    $weekAgo = date('Y-m-d', strtotime('-7 days'));
    $groups = ['today'=>[], 'week'=>[], 'older'=>[]];
    foreach ($notifs as $n) {
        $d = substr($n['created_at'], 0, 10);
        if ($d === $today) $groups['today'][] = $n;
        elseif ($d >= $weekAgo) $groups['week'][] = $n;
        else $groups['older'][] = $n;
    }
    $groupLabels = ['today'=>'오늘','week'=>'이번주','older'=>'이전'];

    $iconMap = [
      'NO_SHOW'=>['⚠️','#ff4d6d'],
      'SOS'=>['🚨','#ff4d6d'],
      'MOM'=>['🏆','#ffd60a'],
      'EVAL'=>['⭐','#ffd60a'],
      'FEE'=>['💰','#ff9500'],
      'MATCH'=>['⚽','var(--primary)'],
      'TEAM_JOIN'=>['🚪','#3a9ef5'],
      'MERCENARY'=>['⚡','#ffb400'],
    ];
?>
<div class="container">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h2 style="font-size:20px;font-weight:700;margin:0">🔔 알림<?php if($unreadCnt>0): ?> <span style="color:var(--primary);font-size:13px">·<?=$unreadCnt?>개 안 읽음</span><?php endif; ?></h2>
  </div>
  <!-- 필터 -->
  <div class="chip-row" style="margin-bottom:12px">
    <a href="?page=notifications&f=all" class="chip <?=$filter==='all'?'active':''?>" style="font-size:11px">전체 <?=count($notifs)?></a>
    <a href="?page=notifications&f=unread" class="chip <?=$filter==='unread'?'active':''?>" style="font-size:11px">
      안읽음 <?=$unreadCnt?>
    </a>
  </div>

  <?php if (!$notifs): ?>
  <div style="text-align:center;padding:60px 20px;color:var(--text-sub)">
    <div style="font-size:48px;margin-bottom:12px">🔔</div>
    <p style="font-size:15px;margin-bottom:6px;color:var(--text-main);font-weight:700">새로운 알림이 없어요</p>
    <p style="font-size:13px;line-height:1.6">
      <?= $filter==='unread' ? '모든 알림을 확인했어요!' : '경기 참여, 팀 활동을 시작하면<br>알림이 여기에 표시됩니다' ?>
    </p>
  </div>
  <?php else:
    foreach ($groups as $gk => $glist):
      if (!$glist) continue; ?>
  <div style="font-size:11px;color:var(--text-sub);font-weight:700;padding:8px 2px;margin-top:4px"><?=$groupLabels[$gk]?> · <?=count($glist)?></div>
  <?php foreach ($glist as $n):
    [$icon, $col] = $iconMap[$n['type']] ?? ['📢', 'var(--text-sub)'];
  ?>
  <a href="<?=h($n['link'] ?: '#')?>" style="text-decoration:none;color:inherit">
    <div class="card card-link" style="margin-bottom:6px;<?=!$n['is_read']?'border-color:var(--primary);box-shadow:0 0 0 1px rgba(0,255,136,0.2)':''?>">
      <div class="card-body" style="display:flex;gap:12px;align-items:flex-start;padding:12px 14px">
        <div style="font-size:22px;flex-shrink:0;color:<?=$col?>"><?=$icon?></div>
        <div style="flex:1;min-width:0">
          <div style="font-weight:600;font-size:13px;display:flex;gap:6px;align-items:center">
            <?php if(!$n['is_read']): ?><span style="width:6px;height:6px;border-radius:50%;background:var(--primary);flex-shrink:0"></span><?php endif; ?>
            <?=h($n['title'])?>
          </div>
          <?php if($n['body']): ?>
          <div style="font-size:12px;color:var(--text-sub);margin-top:2px;line-height:1.4"><?=h($n['body'])?></div>
          <?php endif; ?>
          <div style="font-size:10px;color:var(--text-sub);margin-top:4px"><?=timeAgo($n['created_at'])?> · <?=date('m/d H:i', strtotime($n['created_at']))?></div>
        </div>
      </div>
    </div>
  </a>
  <?php endforeach; endforeach; endif; ?>
</div>
<?php }

// ══════════════════════════════════════════════════════════
// 팀 포인트 배분 페이지
// ══════════════════════════════════════════════════════════
function pagTeamPoints(PDO $pdo): void {
    requireLogin();
    if (!isCaptain()) { echo '<div class="container" style="padding:60px 16px;text-align:center;color:var(--text-sub)">관리자만 접근 가능합니다.</div>'; return; }
    $tid = myTeamId();

    // 배분 가능한 포인트 풀 (remaining > 0)
    $pools = $pdo->prepare("
        SELECT pp.*, m.match_date, m.match_time, m.location,
               COALESCE(ht.name,'?') AS home_name, COALESCE(at.name,'?') AS away_name
        FROM team_point_pool pp
        LEFT JOIN matches m ON m.id=pp.match_id
        LEFT JOIN teams ht ON ht.id=m.home_team_id
        LEFT JOIN teams at ON at.id=m.away_team_id
        WHERE pp.team_id=?
        ORDER BY pp.created_at DESC
    ");
    $pools->execute([$tid]); $pools = $pools->fetchAll();

    // 팀원 목록
    $members = $pdo->prepare("
        SELECT u.id, u.name, u.nickname, u.profile_image_url
        FROM users u JOIN team_members tm ON tm.user_id=u.id
        WHERE tm.team_id=? AND tm.status='active' AND tm.role != 'mercenary'
        ORDER BY u.name
    ");
    $members->execute([$tid]); $members = $members->fetchAll();

    // 최근 배분 내역
    $recent = $pdo->prepare("
        SELECT d.*, u.name AS to_name
        FROM team_point_distribute d
        JOIN users u ON u.id=d.to_user_id
        WHERE d.team_id=?
        ORDER BY d.created_at DESC LIMIT 20
    ");
    $recent->execute([$tid]); $recent = $recent->fetchAll();

    $reasons = ['봉사','준비/세팅','청소/정리','일찍 도착','분위기 메이커','응원','기타'];
?>
<div class="container">
  <h2 style="font-size:18px;font-weight:700;margin-bottom:16px">🎁 팀 포인트 배분</h2>

  <?php if(!$pools || !array_filter($pools, fn($p)=>$p['remaining']>0)): ?>
  <div class="card"><div class="card-body" style="text-align:center;padding:30px;color:var(--text-sub)">
    <div style="font-size:32px;margin-bottom:8px">📭</div>
    <div>배분할 포인트 풀이 없습니다</div>
    <div style="font-size:12px;margin-top:4px">경기 완료 후 자동으로 100P가 생성됩니다</div>
  </div></div>
  <?php else: ?>

  <?php foreach($pools as $pool): if($pool['remaining'] <= 0) continue; ?>
  <div class="card" style="margin-bottom:16px"><div class="card-body">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <div>
        <div style="font-size:13px;font-weight:600"><?=h($pool['home_name'])?> vs <?=h($pool['away_name'])?></div>
        <div style="font-size:11px;color:var(--text-sub)"><?=$pool['match_date'] ?? '경기'?></div>
      </div>
      <div style="text-align:right">
        <div style="font-size:20px;font-weight:800;color:var(--primary)"><?=$pool['remaining']?>P</div>
        <div style="font-size:10px;color:var(--text-sub)">남은 포인트</div>
      </div>
    </div>
    <div style="height:4px;background:rgba(255,255,255,0.06);border-radius:2px;margin-bottom:12px">
      <div style="height:100%;width:<?=($pool['distributed']/$pool['total_points'])*100?>%;background:var(--primary);border-radius:2px"></div>
    </div>

    <form method="POST" style="display:flex;flex-direction:column;gap:8px">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="distribute_team_points">
      <input type="hidden" name="pool_id" value="<?=$pool['id']?>">
      <div style="display:flex;gap:6px">
        <select name="to_user_id" class="form-control" style="flex:2;font-size:12px" required>
          <option value="">팀원 선택</option>
          <?php foreach($members as $mb): ?>
          <option value="<?=$mb['id']?>"><?=h(displayName($mb))?></option>
          <?php endforeach; ?>
        </select>
        <input type="number" name="points" class="form-control" style="flex:1;font-size:12px" placeholder="P" min="1" max="<?=$pool['remaining']?>" required>
      </div>
      <div style="display:flex;gap:6px">
        <select name="reason" class="form-control" style="flex:1;font-size:12px">
          <?php foreach($reasons as $r): ?><option><?=$r?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm" style="white-space:nowrap">지급</button>
      </div>
    </form>
  </div></div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if($recent): ?>
  <h3 style="font-size:14px;font-weight:600;margin-bottom:8px">최근 배분 내역</h3>
  <div class="card"><div class="card-body" style="padding:0">
    <?php foreach($recent as $ri => $r): ?>
    <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;<?=$ri?'border-top:1px solid rgba(255,255,255,0.04)':''?>">
      <span style="font-size:16px">🎁</span>
      <div style="flex:1">
        <div style="font-size:13px;font-weight:600"><?=h($r['to_name'])?> <span style="color:var(--primary)">+<?=$r['points']?>P</span></div>
        <div style="font-size:11px;color:var(--text-sub)"><?=h($r['reason'])?></div>
      </div>
      <div style="font-size:10px;color:var(--text-sub)"><?=date('m/d', strtotime($r['created_at']))?></div>
    </div>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>
</div>
<?php
}

// ══════════════════════════════════════════════════════════
// 버그 신고 페이지
// ══════════════════════════════════════════════════════════
function pagBugReport(PDO $pdo): void {
    requireLogin();
    $myReports = $pdo->prepare("SELECT * FROM bug_reports WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
    $myReports->execute([me()['id']]); $myReports = $myReports->fetchAll();
    $statusLabels = ['pending'=>'접수','reviewing'=>'검토중','fixed'=>'해결','wontfix'=>'보류','duplicate'=>'중복'];
    $statusColors = ['pending'=>'#ff9500','reviewing'=>'#3a9ef5','fixed'=>'#00ff88','wontfix'=>'#888','duplicate'=>'#888'];
    $catLabels = ['bug'=>'버그','ui'=>'UI/디자인','feature'=>'기능 제안','other'=>'기타'];
?>
<div class="container">
  <h2 style="font-size:18px;font-weight:700;margin-bottom:16px">🐛 버그/오류 신고</h2>
  <div class="card" style="margin-bottom:16px">
    <div class="card-body" style="padding:14px 16px;background:rgba(0,255,136,0.05);border-radius:8px">
      <div style="font-size:13px;color:#00ff88;font-weight:600;margin-bottom:4px">💰 신고하면 포인트!</div>
      <div style="font-size:12px;color:var(--text-sub);line-height:1.6">
        버그 신고: 100P | 심각한 버그: 최대 200P<br>
        기능 제안도 환영합니다!
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:16px"><div class="card-body">
    <form method="POST">
      <?=csrfInput()?>
      <input type="hidden" name="action" value="submit_bug_report">
      <div class="form-group">
        <label class="form-label">카테고리</label>
        <select name="bug_category" class="form-control">
          <option value="bug">🐛 버그/오류</option>
          <option value="ui">🎨 UI/디자인</option>
          <option value="feature">💡 기능 제안</option>
          <option value="other">📝 기타</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">제목</label>
        <input type="text" name="bug_title" class="form-control" placeholder="어떤 문제인지 한 줄로" required minlength="5">
      </div>
      <div class="form-group">
        <label class="form-label">상세 설명</label>
        <textarea name="bug_description" class="form-control" rows="4" placeholder="어떤 상황에서 발생했는지 자세히 적어주세요"></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">발생 페이지 <span style="font-size:11px;color:var(--text-sub)">(선택)</span></label>
        <input type="text" name="bug_page" class="form-control" placeholder="예: 경기 상세, 팀원모집, 마이페이지">
      </div>
      <button type="submit" class="btn btn-primary btn-w">신고하기</button>
    </form>
  </div></div>

  <?php if($myReports): ?>
  <h3 style="font-size:14px;font-weight:600;margin-bottom:8px">내 신고 내역</h3>
  <?php foreach($myReports as $r):
    $sc = $statusColors[$r['status']] ?? '#888';
    $sl = $statusLabels[$r['status']] ?? $r['status'];
    $cl = $catLabels[$r['category']] ?? $r['category'];
  ?>
  <div class="card" style="margin-bottom:8px"><div class="card-body" style="padding:10px 14px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
      <span style="font-size:10px;padding:2px 6px;border-radius:4px;background:<?=$sc?>18;color:<?=$sc?>;font-weight:600"><?=$sl?></span>
      <span style="font-size:10px;color:var(--text-sub)"><?=date('m/d H:i', strtotime($r['created_at']))?></span>
    </div>
    <div style="font-size:13px;font-weight:600"><?=h($r['title'])?></div>
    <?php if($r['description']): ?><div style="font-size:12px;color:var(--text-sub);margin-top:4px;line-height:1.5"><?=h(mb_substr($r['description'],0,100,'UTF-8'))?></div><?php endif; ?>
    <?php if($r['points_awarded']): ?><div style="font-size:11px;color:var(--primary);margin-top:4px">+<?=$r['points_awarded']?>P 적립</div><?php endif; ?>
    <?php if($r['admin_note']): ?><div style="font-size:11px;color:#3a9ef5;margin-top:4px;padding:6px;background:rgba(58,158,245,0.05);border-radius:4px">관리자: <?=h($r['admin_note'])?></div><?php endif; ?>
  </div></div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php
}

// ══════════════════════════════════════════════════════════
// 포인트 내역 페이지
// ══════════════════════════════════════════════════════════
function pagPointHistory(PDO $pdo): void {
    requireLogin();
    $total = getUserPoints($pdo, me()['id']);
    $history = $pdo->prepare("SELECT * FROM user_points WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
    $history->execute([me()['id']]); $history = $history->fetchAll();
    $actionLabels = [
        'attendance'=>'경기 참석','checkin'=>'체크인','mom_vote'=>'MOM 투표',
        'manner_review'=>'매너 평가','bug_report'=>'버그 신고','invite'=>'팀원 초대',
        'first_match'=>'첫 경기','recruit_apply'=>'팀원모집 지원','team_reward'=>'팀 포인트',
    ];
    $actionIcons = [
        'attendance'=>'⚽','checkin'=>'📍','mom_vote'=>'🏆',
        'manner_review'=>'🤝','bug_report'=>'🐛','invite'=>'👋',
        'first_match'=>'🎉','recruit_apply'=>'📝','team_reward'=>'🎁',
    ];
?>
<div class="container">
  <h2 style="font-size:18px;font-weight:700;margin-bottom:16px">📊 포인트 내역</h2>
  <div class="card" style="margin-bottom:16px">
    <div class="card-body" style="padding:16px;text-align:center">
      <div style="font-size:12px;color:var(--text-sub)">총 포인트</div>
      <div style="font-size:32px;font-weight:800;color:var(--primary)"><?=number_format($total)?> <span style="font-size:16px">P</span></div>
    </div>
  </div>

  <div style="font-size:11px;color:var(--text-sub);margin-bottom:12px;padding:8px 12px;background:rgba(255,255,255,0.03);border-radius:8px;line-height:1.7">
    ⚽ 경기참석 <?=PT_ATTENDANCE?>P · 📍 체크인 <?=PT_CHECKIN?>P · 🏆 MOM투표 <?=PT_MOM_VOTE?>P · 🤝 매너평가 <?=PT_MANNER_REVIEW?>P<br>
    🐛 버그신고 50~200P · 👋 팀원초대 <?=PT_INVITE?>P · 🎉 첫경기 <?=PT_FIRST_MATCH?>P
  </div>

  <?php if($history): ?>
  <div class="card"><div class="card-body" style="padding:0">
    <?php foreach($history as $hi => $h):
      $icon = $actionIcons[$h['action']] ?? '🔹';
      $label = $actionLabels[$h['action']] ?? $h['action'];
    ?>
    <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;<?=$hi?'border-top:1px solid rgba(255,255,255,0.04)':''?>">
      <span style="font-size:18px"><?=$icon?></span>
      <div style="flex:1;min-width:0">
        <div style="font-size:13px;font-weight:600"><?=$label?></div>
        <?php if($h['description']): ?><div style="font-size:11px;color:var(--text-sub)"><?=h(mb_substr($h['description'],0,40,'UTF-8'))?></div><?php endif; ?>
      </div>
      <div style="text-align:right">
        <div style="font-size:14px;font-weight:700;color:var(--primary)">+<?=$h['points']?>P</div>
        <div style="font-size:9px;color:var(--text-sub)"><?=date('m/d', strtotime($h['created_at']))?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div></div>
  <?php else: ?>
  <div style="text-align:center;padding:40px;color:var(--text-sub)">
    <div style="font-size:32px;margin-bottom:8px">📭</div>
    <div style="font-size:13px">아직 적립 내역이 없습니다</div>
    <div style="font-size:12px;margin-top:4px">경기에 참석하거나 버그를 신고해보세요!</div>
  </div>
  <?php endif; ?>
</div>
<?php
}


// ─────────────────────────────────────────────
// [상태전이 시각화] State Transition Diagram
// ─────────────────────────────────────────────
function pagStateDiagram(PDO $pdo): void {
    requireLogin();
    if (!isCaptain()) { flash('캡틴/관리자만 접근 가능합니다.', 'error'); redirect('?page=home'); }

    $stateMachines = [
        [
            'title' => '⚽ 경기 상태',
            'desc' => '경기 생성부터 완료까지의 흐름',
            'transitions' => [
                ['from'=>'recruiting', 'to'=>'confirmed', 'label'=>''],
                ['from'=>'confirmed', 'to'=>'in_progress', 'label'=>''],
                ['from'=>'in_progress', 'to'=>'completed', 'label'=>''],
                ['from'=>'completed', 'to'=>'result_pending', 'label'=>''],
                ['from'=>'result_pending', 'to'=>'approved', 'label'=>''],
                ['from'=>'recruiting', 'to'=>'cancelled', 'label'=>'취소'],
            ],
        ],
        [
            'title' => '⚡ 용병 요청',
            'desc' => '용병 신청/제안 처리 흐름',
            'transitions' => [
                ['from'=>'pending', 'to'=>'accepted', 'label'=>'수락'],
                ['from'=>'accepted', 'to'=>'checked_in', 'label'=>'체크인'],
                ['from'=>'pending', 'to'=>'rejected', 'label'=>'거절'],
                ['from'=>'pending', 'to'=>'expired', 'label'=>'경기 종료 시'],
            ],
        ],
        [
            'title' => '📬 팀 초대',
            'desc' => '팀 가입 초대 처리 흐름',
            'transitions' => [
                ['from'=>'pending', 'to'=>'accepted', 'label'=>'7일 내 수락'],
                ['from'=>'pending', 'to'=>'rejected', 'label'=>'거절'],
                ['from'=>'pending', 'to'=>'expired', 'label'=>'7일 경과'],
            ],
        ],
        [
            'title' => '🚨 제재',
            'desc' => '유저 제재 및 이의제기 흐름',
            'transitions' => [
                ['from'=>'active', 'to'=>'warned', 'label'=>'경고'],
                ['from'=>'warned', 'to'=>'suspended', 'label'=>'정지'],
                ['from'=>'suspended', 'to'=>'banned', 'label'=>'영구차단'],
                ['from'=>'banned', 'to'=>'appeal_pending', 'label'=>'이의제기'],
                ['from'=>'appeal_pending', 'to'=>'restored', 'label'=>'승인'],
                ['from'=>'appeal_pending', 'to'=>'banned', 'label'=>'거부'],
            ],
        ],
        [
            'title' => '📋 참석',
            'desc' => '경기 참석 상태 및 패널티 기준',
            'transitions' => [
                ['from'=>'미정', 'to'=>'ATTEND', 'label'=>'참석'],
                ['from'=>'ATTEND', 'to'=>'checked_in', 'label'=>'체크인'],
                ['from'=>'미정', 'to'=>'ABSENT', 'label'=>'불참'],
                ['from'=>'ATTEND', 'to'=>'ABSENT (무패널티)', 'label'=>'>24h 전 취소'],
                ['from'=>'ATTEND', 'to'=>'ABSENT (늦은취소)', 'label'=>'1-24h 전 취소'],
                ['from'=>'ATTEND', 'to'=>'ABSENT (노쇼)', 'label'=>'<1h 전 취소'],
            ],
        ],
    ];

    $stateColors = [
        'approved'=>'#00ff88', 'accepted'=>'#00ff88', 'checked_in'=>'#00ff88', 'restored'=>'#00ff88', 'completed'=>'#00ff88', 'ATTEND'=>'#00ff88', 'active'=>'#00ff88',
        'cancelled'=>'#ff4757', 'rejected'=>'#ff4757', 'banned'=>'#ff4757', 'ABSENT'=>'#ff4757', 'ABSENT (무패널티)'=>'#ff4757', 'ABSENT (늦은취소)'=>'#ff4757', 'ABSENT (노쇼)'=>'#ff4757',
        'pending'=>'#ffb400', 'result_pending'=>'#ffb400', 'appeal_pending'=>'#ffb400', '미정'=>'#ffb400', 'expired'=>'#ffb400',
        'recruiting'=>'#38bdf8', 'confirmed'=>'#38bdf8', 'in_progress'=>'#38bdf8', 'warned'=>'#e67e22', 'suspended'=>'#e74c3c',
    ];
    ?>
<div class="container">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <h2 style="font-size:18px;font-weight:700"><i class="bi bi-diagram-3" style="color:var(--primary)"></i> 상태전이 다이어그램</h2>
    <a href="?page=admin_dashboard" class="btn btn-ghost btn-sm" style="font-size:12px"><i class="bi bi-arrow-left"></i> 대시보드</a>
  </div>
  <div style="font-size:12px;color:var(--text-sub);margin-bottom:20px">각 기능의 상태 전환 흐름을 시각적으로 표현합니다.</div>

  <?php foreach($stateMachines as $sm): ?>
  <div class="card" style="margin-bottom:16px;border:1px solid rgba(255,255,255,0.06)">
    <div class="card-body" style="padding:16px">
      <div style="font-size:15px;font-weight:700;margin-bottom:4px"><?=$sm['title']?></div>
      <div style="font-size:11px;color:var(--text-sub);margin-bottom:14px"><?=$sm['desc']?></div>

      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:12px">
          <thead>
            <tr style="border-bottom:1px solid rgba(255,255,255,0.1)">
              <th style="padding:8px 10px;text-align:left;color:var(--text-sub);font-weight:600;font-size:11px">FROM</th>
              <th style="padding:8px 10px;text-align:center;color:var(--text-sub);font-weight:600;font-size:11px"></th>
              <th style="padding:8px 10px;text-align:left;color:var(--text-sub);font-weight:600;font-size:11px">TO</th>
              <th style="padding:8px 10px;text-align:left;color:var(--text-sub);font-weight:600;font-size:11px">조건</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($sm['transitions'] as $tr):
              $fc = $stateColors[$tr['from']] ?? '#94a3b8';
              $tc = $stateColors[$tr['to']] ?? '#94a3b8';
            ?>
            <tr style="border-bottom:1px solid rgba(255,255,255,0.04)">
              <td style="padding:8px 10px">
                <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:<?=$fc?>20;color:<?=$fc?>;border:1px solid <?=$fc?>40"><?=h($tr['from'])?></span>
              </td>
              <td style="padding:8px 6px;text-align:center;color:var(--text-sub);font-size:16px">→</td>
              <td style="padding:8px 10px">
                <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:<?=$tc?>20;color:<?=$tc?>;border:1px solid <?=$tc?>40"><?=h($tr['to'])?></span>
              </td>
              <td style="padding:8px 10px;color:var(--text-sub);font-size:11px"><?=h($tr['label'])?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- 범례 -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:14px 16px">
      <div style="font-size:13px;font-weight:700;margin-bottom:10px">🎨 범례</div>
      <div style="display:flex;flex-wrap:wrap;gap:8px">
        <span style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:#00ff8820;color:#00ff88;border:1px solid #00ff8840">완료/긍정</span>
        <span style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:#ff475720;color:#ff4757;border:1px solid #ff475740">종료/부정</span>
        <span style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:#ffb40020;color:#ffb400;border:1px solid #ffb40040">대기/보류</span>
        <span style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:#38bdf820;color:#38bdf8;border:1px solid #38bdf840">진행/활성</span>
      </div>
    </div>
  </div>
</div>
<?php
}

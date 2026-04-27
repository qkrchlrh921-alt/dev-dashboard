<?php
/**
 * Patch script: Add 회비 관리 (Team Dues Management) to app.php
 */

$file = '/var/www/html/app.php';
$code = file_get_contents($file);
if (!$code) { echo "ERROR: Cannot read app.php\n"; exit(1); }

$original = $code;
$changes = 0;

// ═══════════════════════════════════════════════════════════════
// 1. Add 'toggle_dues_payment' to CSRF exempt list and API actions
// ═══════════════════════════════════════════════════════════════
$old = "\$apiActions = ['toggle_bench','set_bench','batch_proxy_attendance'];";
$new = "\$apiActions = ['toggle_bench','set_bench','batch_proxy_attendance','toggle_dues_payment'];";
if (strpos($code, $old) !== false) {
    $code = str_replace($old, $new, $code);
    $changes++;
    echo "  [1a] Added toggle_dues_payment to apiActions\n";
}

$old = "\$csrfExempt = ['logout','login','register','admin_login','toggle_bench','set_bench','batch_proxy_attendance'];";
$new = "\$csrfExempt = ['logout','login','register','admin_login','toggle_bench','set_bench','batch_proxy_attendance','toggle_dues_payment'];";
if (strpos($code, $old) !== false) {
    $code = str_replace($old, $new, $code);
    $changes++;
    echo "  [1b] Added toggle_dues_payment to csrfExempt\n";
}

// ═══════════════════════════════════════════════════════════════
// 2. Add toggle_dues_payment AJAX handler in the POST API section
//    (after batch_proxy_attendance block)
// ═══════════════════════════════════════════════════════════════
$marker = "echo json_encode(['ok'=>true,'msg'=>\$updated.'명 출석 저장 완료','count'=>\$updated]);\n            exit;\n        }";
$insertAfter = <<<'PHPCODE'

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
PHPCODE;

if (strpos($code, $marker) !== false) {
    $code = str_replace($marker, $marker . "\n" . $insertAfter, $code);
    $changes++;
    echo "  [2] Added toggle_dues_payment AJAX handler\n";
}

// ═══════════════════════════════════════════════════════════════
// 3. Add 'save_dues_setting' and 'bulk_dues_paid' actions to handleAction switch
//    (before the closing } of handleAction)
// ═══════════════════════════════════════════════════════════════
$handleActionEnd = "            flash('버그 리포트를 ['.(\$statusLabelsMap[\$bugStatus] ?? \$bugStatus).'] 상태로 변경했습니다.');\n            redirect(\$_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=bugs');\n            break;\n    }\n}";
$newActions = <<<'PHPCODE'

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
PHPCODE;

if (strpos($code, $handleActionEnd) !== false) {
    $code = str_replace($handleActionEnd,
        "            flash('버그 리포트를 ['.(\$statusLabelsMap[\$bugStatus] ?? \$bugStatus).'] 상태로 변경했습니다.');\n            redirect(\$_SERVER['HTTP_REFERER'] ?? '?page=admin&tab=bugs');\n            break;\n" . $newActions . "\n    }\n}", $code);
    $changes++;
    echo "  [3] Added save_dues_setting and bulk_dues_paid actions\n";
}

// ═══════════════════════════════════════════════════════════════
// 4. Add 'dues' to the auth pages list
// ═══════════════════════════════════════════════════════════════
$old = "'team_points'];";
$new = "'team_points','dues'];";
if (strpos($code, $old) !== false) {
    $code = str_replace($old, $new, $code);
    $changes++;
    echo "  [4] Added 'dues' to auth pages\n";
}

// ═══════════════════════════════════════════════════════════════
// 5. Add 'dues' to the match($page) router
// ═══════════════════════════════════════════════════════════════
$old = "'fees'          => pagFees(\$pdo),";
$new = "'fees'          => pagFees(\$pdo),\n        'dues'          => pagDues(\$pdo),";
if (strpos($code, $old) !== false) {
    $code = str_replace($old, $new, $code);
    $changes++;
    echo "  [5] Added 'dues' to page router\n";
}

// ═══════════════════════════════════════════════════════════════
// 6. Add 💰 회비 link to bottom nav (before MY link)
// ═══════════════════════════════════════════════════════════════
$old = '<a href="?page=mypage" class="<?= in_array($page,[\'mypage\',\'team\',\'team_settings\',\'fees\'])?\'active\':\'\' ?>"';
$new = '<?php if(myTeamId()): ?><a href="?page=dues" class="<?= in_array($page,[\'dues\',\'fees\'])?\'active\':\'\' ?>"><i class="bi bi-cash-coin"></i>회비</a><?php endif; ?>' . "\n  " . '<a href="?page=mypage" class="<?= in_array($page,[\'mypage\',\'team\',\'team_settings\'])?\'active\':\'\' ?>"';
if (strpos($code, $old) !== false) {
    $code = str_replace($old, $new, $code);
    $changes++;
    echo "  [6] Added 회비 to bottom nav\n";
}

// ═══════════════════════════════════════════════════════════════
// 7. Add "내 회비" section to mypage (after 회원명단 link)
// ═══════════════════════════════════════════════════════════════
$old = '<a href="?page=fees" class="btn btn-outline"><i class="bi bi-people"></i> 회원명단</a>';
$new = '<a href="?page=fees" class="btn btn-outline"><i class="bi bi-people"></i> 회원명단</a>' . "\n" . '    <a href="?page=dues" class="btn btn-outline"><i class="bi bi-cash-coin"></i> 회비 관리</a>';
if (strpos($code, $old) !== false) {
    $code = str_replace($old, $new, $code);
    $changes++;
    echo "  [7a] Added 회비 관리 link to mypage\n";
}

// Add 내 회비 section after stat grid in mypage
$mypageMarker = "<!-- 내 쿼터 배정 -->";
$myDuesSection = <<<'PHPCODE'
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

  PHPCODE;

if (strpos($code, $mypageMarker) !== false) {
    $code = str_replace($mypageMarker, $myDuesSection . "\n  " . $mypageMarker, $code);
    $changes++;
    echo "  [7b] Added 내 회비 section to mypage\n";
}

// ═══════════════════════════════════════════════════════════════
// 8. Add monthly fee input to team_settings page
// ═══════════════════════════════════════════════════════════════
$old = '<label class="form-label">팀 앰블럼 URL';
$new = '<label class="form-label">월 회비 금액</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="number" name="membership_fee_amount" class="form-control" style="flex:1" value="<?=(int)($team[\'membership_fee\']??0)?>" min="0" step="1000" placeholder="30000">
          <span style="font-size:13px;color:var(--text-sub)">원/월</span>
        </div>
        <div style="font-size:10px;color:var(--text-sub);margin-top:4px">회비 관리 페이지에서 사용됩니다. 0 = 회비 없음</div>
      </div>
      <div class="form-group">
        <label class="form-label">팀 앰블럼 URL';
if (strpos($code, $old) !== false) {
    $code = str_replace($old, $new, $code);
    $changes++;
    echo "  [8a] Added monthly fee input to team_settings\n";
}

// Update save_team_settings handler to include membership_fee
$old = "in_array(\$_POST['avg_age_range']??'',['20대','30대','40대','무관'])?\$_POST['avg_age_range']:'무관',";
$new = "in_array(\$_POST['avg_age_range']??'',['20대','30대','40대','무관'])?\$_POST['avg_age_range']:'무관',";
// Need a different approach - add fee update after the main UPDATE
$old2 = "flash('팀 프로필이 수정되었습니다.');\n            redirect('?page=team_settings');";
$new2 = "// Update membership fee if provided\n            if (isset(\$_POST['membership_fee_amount'])) {\n                \$newFee = max(0, (int)\$_POST['membership_fee_amount']);\n                \$pdo->prepare(\"UPDATE teams SET membership_fee=? WHERE id=\".myTeamId())->execute([\$newFee]);\n                \$pdo->prepare(\"INSERT INTO team_dues_settings (team_id, monthly_fee, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE monthly_fee=VALUES(monthly_fee), updated_by=VALUES(updated_by)\")\n                    ->execute([myTeamId(), \$newFee, (int)me()['id']]);\n            }\n            flash('팀 프로필이 수정되었습니다.');\n            redirect('?page=team_settings');";
if (strpos($code, $old2) !== false) {
    $code = str_replace($old2, $new2, $code);
    $changes++;
    echo "  [8b] Updated save_team_settings to save membership fee\n";
}

// ═══════════════════════════════════════════════════════════════
// 9. Add pagDues function (before pagLeagues function)
// ═══════════════════════════════════════════════════════════════
$pagDuesFunction = <<<'PHPCODE'

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

PHPCODE;

// Insert before pagLeagues
$leagueMarker = "// ═══════════════════════════════════════════════════════════════\n// 리그 목록\n// ═══════════════════════════════════════════════════════════════\nfunction pagLeagues";
if (strpos($code, $leagueMarker) !== false) {
    $code = str_replace($leagueMarker, $pagDuesFunction . "\n" . $leagueMarker, $code);
    $changes++;
    echo "  [9] Added pagDues function\n";
}

// ═══════════════════════════════════════════════════════════════
// WRITE
// ═══════════════════════════════════════════════════════════════
if ($changes === 0) {
    echo "ERROR: No changes applied! Something went wrong with pattern matching.\n";
    exit(1);
}

echo "\nTotal changes: $changes\n";
file_put_contents($file, $code);
echo "File written: $file\n";
echo "File size: " . filesize($file) . " bytes\n";

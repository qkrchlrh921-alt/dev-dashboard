<?php
// Patch script for TRUST FOOTBALL app - 4 features

$file = '/var/www/html/app.php';
$content = file_get_contents($file);
if (!$content) { echo "ERROR: Cannot read file\n"; exit(1); }

// ============================================================
// FEATURE 1: Fix role labels in member list pages to match sidebar
// ============================================================

// Fix line ~3100: roleLabels in team_settings
$content = str_replace(
    "\$roleLabels = ['player'=>'선수','owner'=>'구단주','president'=>'회장','director'=>'감독','captain'=>'주장','vice_captain'=>'부주장','manager'=>'매니저','coach'=>'코치','treasurer'=>'총무','analyst'=>'전력분석','doctor'=>'팀닥터'];",
    "\$roleLabels = ['player'=>'선수','owner'=>'구단주','president'=>'회장','director'=>'단장','captain'=>'주장','vice_captain'=>'부주장','manager'=>'매니저','coach'=>'감독','treasurer'=>'총무','analyst'=>'전력분석','doctor'=>'팀닥터'];",
    $content
);

// Fix line ~10242: roleLabels in team member list
$content = str_replace(
    "\$roleLabels = ['owner'=>'구단주','captain'=>'주장','vice_captain'=>'부주장','manager'=>'매니저','coach'=>'코치','treasurer'=>'총무','analyst'=>'전력분석','doctor'=>'팀닥터','player'=>'선수','president'=>'회장','director'=>'감독'];",
    "\$roleLabels = ['owner'=>'구단주','captain'=>'주장','vice_captain'=>'부주장','manager'=>'매니저','coach'=>'감독','treasurer'=>'총무','analyst'=>'전력분석','doctor'=>'팀닥터','player'=>'선수','president'=>'회장','director'=>'단장'];",
    $content
);

echo "Feature 1: Role labels fixed\n";

// ============================================================
// FEATURE 2: 운영알림 접기/펼치기 with localStorage
// ============================================================

$oldBannerTitle = '<div style="font-size:13px;font-weight:700;color:#ffb400;margin-bottom:8px"><i class="bi bi-exclamation-triangle-fill"></i> 운영 알림</div>';

$newBannerBlock = '<div id="tf-ops-alert-collapsed" onclick="toggleOpsAlert()" style="display:none;cursor:pointer;background:rgba(255,180,0,0.08);border:1px solid rgba(255,180,0,0.25);border-radius:10px;padding:10px 14px;align-items:center;gap:8px;margin-bottom:8px">
      <span style="font-size:14px">⚠️</span>
      <span style="font-size:12px;font-weight:600;color:#ffb400">운영 알림 <?=count($alertItems)?>건</span>
      <i class="bi bi-chevron-down" style="margin-left:auto;color:var(--text-sub);font-size:12px"></i>
    </div>
    <div id="tf-ops-alert-expanded">
    <div style="font-size:13px;font-weight:700;color:#ffb400;margin-bottom:8px;display:flex;align-items:center;gap:6px">
      <span><i class="bi bi-exclamation-triangle-fill"></i> 운영 알림</span>
      <span onclick="toggleOpsAlert()" style="cursor:pointer;margin-left:auto;font-size:11px;color:var(--text-sub);font-weight:400;padding:2px 8px;border:1px solid rgba(255,255,255,0.1);border-radius:12px">접기 <i class="bi bi-chevron-up"></i></span>
    </div>';

$content = str_replace($oldBannerTitle, $newBannerBlock, $content);

// Close the expanded div and add toggle script before closing the banner container
$oldBannerEnd = '    <?php endforeach; ?>
  </div>';
// This is ambiguous, so let's target the specific one near the alert section
// We need to find the endforeach + </div> that closes the alert banner
$alertEndPattern = '    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php /* ── 홈 알림 카드 ─── */';

$alertEndReplace = '    <?php endforeach; ?>
  </div><!-- /tf-ops-alert-expanded -->
  <script>
  function toggleOpsAlert(){var exp=document.getElementById(\'tf-ops-alert-expanded\');var col=document.getElementById(\'tf-ops-alert-collapsed\');if(exp.style.display===\'none\'){exp.style.display=\'\';col.style.display=\'none\';localStorage.removeItem(\'tf_ops_alert_collapsed\');}else{exp.style.display=\'none\';col.style.display=\'flex\';localStorage.setItem(\'tf_ops_alert_collapsed\',\'1\');}}
  (function(){if(localStorage.getItem(\'tf_ops_alert_collapsed\')===\'1\'){var exp=document.getElementById(\'tf-ops-alert-expanded\');var col=document.getElementById(\'tf-ops-alert-collapsed\');if(exp&&col){exp.style.display=\'none\';col.style.display=\'flex\';}}})();
  </script>
  </div>
  <?php endif; ?>

  <?php /* ── 홈 알림 카드 ─── */';

$content = str_replace($alertEndPattern, $alertEndReplace, $content);

echo "Feature 2: Warning banner toggle added\n";

// ============================================================
// FEATURE 3: 양측 경기 결과 입력 + 자동 승인
// ============================================================

// Replace submit_result action
$oldSubmitResult = "        case 'submit_result':
            requireLogin();
            \$matchId = (int)(\$_POST['match_id'] ?? 0);
            \$hs = max(0,(int)(\$_POST['home_score']??0));
            \$as = max(0,(int)(\$_POST['away_score']??0));
            \$match = \$pdo->prepare(\"SELECT * FROM matches WHERE id=?\");
            \$match->execute([\$matchId]); \$match = \$match->fetch();
            if (!\$match) { flash('매치를 찾을 수 없습니다.','error'); redirect('?page=matches'); }
            if (!in_array(myTeamId(),[\$match['home_team_id'],\$match['away_team_id']])) {
                flash('해당 매치 팀만 결과를 입력할 수 있습니다.', 'error');
                redirect('?page=match&id='.\$matchId);
            }
            \$ex = \$pdo->prepare(\"SELECT id FROM match_results WHERE match_id=?\");
            \$ex->execute([\$matchId]);
            if (\$ex->fetch()) {
                \$pdo->prepare(\"UPDATE match_results SET score_home=?,score_away=?,reporter_id=?,is_approved=0 WHERE match_id=?\")
                    ->execute([\$hs,\$as,me()['id'],\$matchId]);
            } else {
                \$pdo->prepare(\"INSERT INTO match_results (match_id,score_home,score_away,reporter_id) VALUES (?,?,?,?)\")
                    ->execute([\$matchId,\$hs,\$as,me()['id']]);
            }
            \$pdo->prepare(\"UPDATE matches SET status='result_pending' WHERE id=?\")->execute([\$matchId]);";

$newSubmitResult = "        case 'submit_result':
            requireLogin();
            \$matchId = (int)(\$_POST['match_id'] ?? 0);
            \$hs = max(0,(int)(\$_POST['home_score']??0));
            \$as = max(0,(int)(\$_POST['away_score']??0));
            \$scorersJson = \$_POST['scorers_json'] ?? null;
            \$match = \$pdo->prepare(\"SELECT * FROM matches WHERE id=?\");
            \$match->execute([\$matchId]); \$match = \$match->fetch();
            if (!\$match) { flash('매치를 찾을 수 없습니다.','error'); redirect('?page=matches'); }
            if (!in_array(myTeamId(),[\$match['home_team_id'],\$match['away_team_id']])) {
                flash('해당 매치 팀만 결과를 입력할 수 있습니다.', 'error');
                redirect('?page=match&id='.\$matchId);
            }
            \$myTid = myTeamId();
            // Save to match_result_submissions (per-team)
            try {
                \$pdo->prepare(\"INSERT INTO match_result_submissions (match_id, team_id, submitted_by, score_home, score_away, scorers_json)
                    VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE score_home=VALUES(score_home), score_away=VALUES(score_away), scorers_json=VALUES(scorers_json), submitted_by=VALUES(submitted_by), created_at=NOW()\")
                    ->execute([\$matchId, \$myTid, me()['id'], \$hs, \$as, \$scorersJson]);
            } catch(PDOException \$e) {}

            // Check if both teams submitted
            \$subs = \$pdo->prepare(\"SELECT team_id, score_home, score_away FROM match_result_submissions WHERE match_id=?\");
            \$subs->execute([\$matchId]); \$allSubs = \$subs->fetchAll();
            \$homeTeamId = (int)\$match['home_team_id'];
            \$awayTeamId = (int)\$match['away_team_id'];
            \$homeSub = \$awaySub = null;
            foreach(\$allSubs as \$sub) {
                if ((int)\$sub['team_id'] === \$homeTeamId) \$homeSub = \$sub;
                if ((int)\$sub['team_id'] === \$awayTeamId) \$awaySub = \$sub;
            }

            if (\$homeSub && \$awaySub) {
                if ((int)\$homeSub['score_home'] === (int)\$awaySub['score_home'] && (int)\$homeSub['score_away'] === (int)\$awaySub['score_away']) {
                    // Scores match - auto-approve
                    \$finalH = (int)\$homeSub['score_home'];
                    \$finalA = (int)\$homeSub['score_away'];
                    \$ex = \$pdo->prepare(\"SELECT id FROM match_results WHERE match_id=?\");
                    \$ex->execute([\$matchId]);
                    if (\$ex->fetch()) {
                        \$pdo->prepare(\"UPDATE match_results SET score_home=?,score_away=?,reporter_id=?,is_approved=1 WHERE match_id=?\")
                            ->execute([\$finalH,\$finalA,me()['id'],\$matchId]);
                    } else {
                        \$pdo->prepare(\"INSERT INTO match_results (match_id,score_home,score_away,reporter_id,is_approved) VALUES (?,?,?,?,1)\")
                            ->execute([\$matchId,\$finalH,\$finalA,me()['id']]);
                    }
                    \$pdo->prepare(\"UPDATE matches SET status='result_pending' WHERE id=? AND status NOT IN ('completed')\")->execute([\$matchId]);
                    foreach([\$homeTeamId, \$awayTeamId] as \$tid) {
                        \$capQ = \$pdo->prepare(\"SELECT user_id FROM team_members WHERE team_id=? AND role='captain' AND status='active' LIMIT 1\");
                        \$capQ->execute([\$tid]); \$cid = (int)\$capQ->fetchColumn();
                        if (\$cid) notify(\$pdo, \$cid, 'MATCH', '경기 결과 자동 승인', \"양팀 결과가 일치하여 자동 승인되었습니다. ({\$finalH}:{\$finalA})\", '?page=match&id='.\$matchId);
                    }
                    flash('양팀 결과가 일치하여 자동 승인되었습니다!');
                } else {
                    // Scores differ - flag for admin
                    \$pdo->prepare(\"UPDATE matches SET status='result_pending' WHERE id=? AND status NOT IN ('completed')\")->execute([\$matchId]);
                    \$ex = \$pdo->prepare(\"SELECT id FROM match_results WHERE match_id=?\");
                    \$ex->execute([\$matchId]);
                    if (!\$ex->fetch()) {
                        \$pdo->prepare(\"INSERT INTO match_results (match_id,score_home,score_away,reporter_id,is_approved) VALUES (?,?,?,?,0)\")
                            ->execute([\$matchId,\$hs,\$as,me()['id']]);
                    }
                    try {
                        \$admins = \$pdo->query(\"SELECT id FROM users WHERE is_admin=1\");
                        foreach(\$admins->fetchAll() as \$adm) {
                            notify(\$pdo, (int)\$adm['id'], 'ADMIN', '결과 불일치 - 관리자 확인 필요', \"매치 #{\$matchId} 양팀 입력 결과가 다릅니다.\", '?page=match&id='.\$matchId);
                        }
                    } catch(PDOException \$e) {}
                    flash('양팀 입력 결과가 다릅니다. 관리자 검토 후 확정됩니다.', 'warning');
                }
            } else {
                // Only one team submitted
                \$ex = \$pdo->prepare(\"SELECT id FROM match_results WHERE match_id=?\");
                \$ex->execute([\$matchId]);
                if (\$ex->fetch()) {
                    \$pdo->prepare(\"UPDATE match_results SET score_home=?,score_away=?,reporter_id=?,is_approved=0 WHERE match_id=?\")
                        ->execute([\$hs,\$as,me()['id'],\$matchId]);
                } else {
                    \$pdo->prepare(\"INSERT INTO match_results (match_id,score_home,score_away,reporter_id) VALUES (?,?,?,?)\")
                        ->execute([\$matchId,\$hs,\$as,me()['id']]);
                }
                \$pdo->prepare(\"UPDATE matches SET status='result_pending' WHERE id=?\")->execute([\$matchId]);
                \$otherTid = (\$myTid === \$homeTeamId) ? \$awayTeamId : \$homeTeamId;
                if (\$otherTid) {
                    \$capQ = \$pdo->prepare(\"SELECT user_id FROM team_members WHERE team_id=? AND role='captain' AND status='active' LIMIT 1\");
                    \$capQ->execute([\$otherTid]); \$oppCap = (int)\$capQ->fetchColumn();
                    if (\$oppCap) notify(\$pdo, \$oppCap, 'MATCH', '상대팀이 경기 결과를 입력했습니다', '우리 팀도 결과를 입력해주세요.', '?page=match&id='.\$matchId);
                }
                flash('결과 입력 완료! 상대팀도 결과를 입력하면 자동 승인됩니다.');
            }";

if (strpos($content, $oldSubmitResult) !== false) {
    $content = str_replace($oldSubmitResult, $newSubmitResult, $content);
    echo "Feature 3: submit_result action replaced\n";
} else {
    echo "Feature 3: ERROR - could not find submit_result block\n";
}

// Add submission status on match detail result form
$oldBtn = "<button type=\"submit\" class=\"btn btn-primary btn-w\"><?=\$result ? '결과 수정' : '결과 등록'?></button>
    </form>";

$newBtn = "<button type=\"submit\" class=\"btn btn-primary btn-w\"><?=\$result ? '결과 수정' : '결과 등록'?></button>
    <?php
    // Show dual-submission status
    try {
      \$subSt = \$pdo->prepare(\"SELECT team_id FROM match_result_submissions WHERE match_id=?\");
      \$subSt->execute([\$id]); \$submittedTeams = array_column(\$subSt->fetchAll(), 'team_id');
      \$mySubmitted = in_array(\$myTeam, \$submittedTeams);
      \$oppTid = (\$myTeam == \$match['home_team_id']) ? \$match['away_team_id'] : \$match['home_team_id'];
      \$oppSubmitted = in_array(\$oppTid, \$submittedTeams);
    } catch(PDOException \$e) { \$mySubmitted = false; \$oppSubmitted = false; }
    if (\$mySubmitted || \$oppSubmitted): ?>
    <div style=\"margin-top:10px;padding:8px 12px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:8px;font-size:12px;display:flex;gap:12px;justify-content:center\">
      <span style=\"color:<?=\$mySubmitted?'#00ff88':'#ffb400'?>\"><?=\$mySubmitted?'우리팀 ✅ 입력완료':'우리팀 ⏳ 미입력'?></span>
      <span style=\"color:<?=\$oppSubmitted?'#00ff88':'#ffb400'?>\"><?=\$oppSubmitted?'상대팀 ✅ 입력완료':'상대팀 ⏳ 대기중'?></span>
    </div>
    <?php endif; ?>
    </form>";

if (strpos($content, $oldBtn) !== false) {
    $content = str_replace($oldBtn, $newBtn, $content);
    echo "Feature 3: Submission status display added\n";
} else {
    echo "Feature 3: WARNING - could not find result form close\n";
}

// Add "결과 대기" to warning banner alerts
$alertSlice = '    $alertItems = array_slice($alertItems, 0, 5);';
$alertAddition = '
    // 5. 결과 대기 (양팀 입력 대기 중)
    try {
      $pendRes = $pdo->prepare("
        SELECT COUNT(*) FROM matches m
        WHERE (m.home_team_id=? OR m.away_team_id=?)
          AND m.status IN (\'in_progress\',\'checkin_open\',\'confirmed\',\'open\',\'result_pending\')
          AND m.match_date <= CURDATE()
          AND NOT EXISTS (SELECT 1 FROM match_result_submissions mrs WHERE mrs.match_id=m.id AND mrs.team_id=?)
      ");
      $pendRes->execute([$myTeamId, $myTeamId, $myTeamId]);
      $pendResCount = (int)$pendRes->fetchColumn();
      if ($pendResCount > 0) {
        $alertItems[] = [
          \'icon\' => \'📊\',
          \'msg\'  => \'결과 대기 \'.$pendResCount.\'건 — 결과를 입력해주세요\',
          \'link\' => \'?page=matches\',
          \'color\'=> \'#38bdf8\',
        ];
      }
    } catch (PDOException $e) {}

    ' . $alertSlice;

$content = str_replace($alertSlice, $alertAddition, $content);
echo "Feature 3: Alert banner integration added\n";

// 24h auto-approve check on home page load
$alertStart = '  if (me() && isCaptain() && $myTeamId):
    $alertItems = [];';

$autoApprove = '  if (me() && isCaptain() && $myTeamId):
    // [자동승인] 24시간 경과 시 한쪽만 입력한 결과 자동 승인
    try {
      $autoApproveQ = $pdo->query("
        SELECT mrs.match_id, mrs.team_id, mrs.score_home, mrs.score_away, mrs.submitted_by
        FROM match_result_submissions mrs
        JOIN matches m ON m.id=mrs.match_id
        WHERE m.status=\'result_pending\'
          AND mrs.created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
          AND (SELECT COUNT(*) FROM match_result_submissions mrs2 WHERE mrs2.match_id=mrs.match_id) = 1
        LIMIT 5
      ");
      foreach ($autoApproveQ->fetchAll() as $autoR) {
        $ex24 = $pdo->prepare("SELECT id FROM match_results WHERE match_id=? AND is_approved=1");
        $ex24->execute([$autoR[\'match_id\']]);
        if (!$ex24->fetch()) {
          $pdo->prepare("UPDATE match_results SET score_home=?, score_away=?, is_approved=1 WHERE match_id=?")
              ->execute([(int)$autoR[\'score_home\'], (int)$autoR[\'score_away\'], $autoR[\'match_id\']]);
          $mInfo24 = $pdo->prepare("SELECT home_team_id, away_team_id FROM matches WHERE id=?");
          $mInfo24->execute([$autoR[\'match_id\']]); $mInfo24 = $mInfo24->fetch();
          if ($mInfo24) {
            foreach([(int)$mInfo24[\'home_team_id\'], (int)$mInfo24[\'away_team_id\']] as $tid24) {
              $capQ24 = $pdo->prepare("SELECT user_id FROM team_members WHERE team_id=? AND role=\'captain\' AND status=\'active\' LIMIT 1");
              $capQ24->execute([$tid24]); $cid24 = (int)$capQ24->fetchColumn();
              if ($cid24) notify($pdo, $cid24, \'MATCH\', \'경기 결과 자동 승인 (24시간 경과)\', \'상대팀 미입력으로 자동 승인되었습니다.\', \'?page=match&id=\'.$autoR[\'match_id\']);
            }
          }
        }
      }
    } catch(PDOException $e) {}

    $alertItems = [];';

$content = str_replace($alertStart, $autoApprove, $content);
echo "Feature 3: 24h auto-approve added\n";

// ============================================================
// FEATURE 4: 상대팀 평가 방명록
// ============================================================

// Add submit_team_review action handler
// Find the end of submit_team_eval case
$evalRedirect = "            flash('상대팀 평가가 반영되었습니다. 고마워요!');
            redirect('?page=match&id='.\$matchId);
            break;";

if (strpos($content, $evalRedirect) === false) {
    // Try to find the last redirect in submit_team_eval
    // Search for the break after submit_team_eval more carefully
    $pos = strpos($content, "case 'submit_team_eval':");
    if ($pos !== false) {
        // Find "break;" lines after this position
        $searchFrom = $pos + 100;
        // look for "redirect('?page=match" then "break;"
        $redirectPos = strpos($content, "redirect('?page=match&id='.\$matchId);", $searchFrom);
        if ($redirectPos !== false) {
            $breakPos = strpos($content, "break;", $redirectPos);
            if ($breakPos !== false) {
                $endOfBreak = $breakPos + strlen("break;");
                $evalRedirect = substr($content, $redirectPos, $endOfBreak - $redirectPos);
                echo "Feature 4: Found eval break at position $breakPos\n";
            }
        }
    }
}

$reviewAction = '

        case \'submit_team_review\':
            requireLogin();
            if (!isCaptain()) { flash(\'캡틴/매니저만 작성 가능합니다.\', \'error\'); redirect(\'?page=home\'); }
            $matchId = (int)($_POST[\'match_id\'] ?? 0);
            $targetTeamId = (int)($_POST[\'target_team_id\'] ?? 0);
            $rating = max(1, min(5, (int)($_POST[\'rating\'] ?? 3)));
            $comment = trim(mb_substr($_POST[\'review_comment\'] ?? \'\', 0, 500));
            $myTid = myTeamId();
            if (!$matchId || !$targetTeamId || !$myTid) { flash(\'잘못된 요청입니다.\', \'error\'); redirect(\'?page=home\'); }
            try {
                $pdo->prepare("INSERT INTO team_match_reviews (match_id, reviewer_team_id, target_team_id, rating, comment, submitted_by)
                    VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment=VALUES(comment), submitted_by=VALUES(submitted_by)")
                    ->execute([$matchId, $myTid, $targetTeamId, $rating, $comment, me()[\'id\']]);
                $delta = ($rating - 3) * 0.3;
                $oppCap = $pdo->prepare("SELECT user_id FROM team_members WHERE team_id=? AND role=\'captain\' AND status=\'active\' LIMIT 1");
                $oppCap->execute([$targetTeamId]); $oppCapId = (int)$oppCap->fetchColumn();
                if ($oppCapId && abs($delta) > 0) {
                    applyMannerDelta($pdo, $oppCapId, $delta, $matchId, "팀 방명록 평점: {$rating}/5");
                }
                flash(\'방명록이 등록되었습니다.\');
            } catch(PDOException $e) {
                flash(\'이미 작성된 방명록이 있거나 오류가 발생했습니다.\', \'error\');
            }
            redirect(\'?page=team_eval&match_id=\'.$matchId);
            break;';

// Find the break; after submit_team_eval's redirect
$pos = strpos($content, "case 'submit_team_eval':");
if ($pos !== false) {
    // Find all "break;" after this position, take the first one that's within 200 lines
    $searchArea = substr($content, $pos, 3000);
    // Find the last redirect in this case block then the break after it
    $lastRedirect = strrpos($searchArea, "redirect('?page=match&id='.\$matchId);");
    if ($lastRedirect !== false) {
        $breakInArea = strpos($searchArea, "break;", $lastRedirect);
        if ($breakInArea !== false) {
            $absoluteBreakPos = $pos + $breakInArea + strlen("break;");
            $content = substr($content, 0, $absoluteBreakPos) . $reviewAction . substr($content, $absoluteBreakPos);
            echo "Feature 4: submit_team_review action added\n";
        } else {
            echo "Feature 4: ERROR - no break found after redirect\n";
        }
    } else {
        echo "Feature 4: ERROR - no redirect found in submit_team_eval\n";
    }
} else {
    echo "Feature 4: ERROR - submit_team_eval case not found\n";
}

// Enhance pagTeamEval with guestbook section
$oldEvalEnd = '  <div class="tf-alert tf-alert-ok">이미 평가하셨습니다. 양 팀 캡틴 모두 평가하면 결과가 공식 반영됩니다.</div>
  <?php else: ?>';

$newEvalEnd = '  <div class="tf-alert tf-alert-ok">이미 평가하셨습니다. 양 팀 캡틴 모두 평가하면 결과가 공식 반영됩니다.</div>
  <?php
    // Show existing review if any
    $existingReview = null;
    try { $revQ = $pdo->prepare("SELECT * FROM team_match_reviews WHERE match_id=? AND reviewer_team_id=?"); $revQ->execute([$matchId, $myTid]); $existingReview = $revQ->fetch(); } catch(PDOException $e) {}
    if ($existingReview): ?>
    <div class="card" style="margin-top:12px"><div class="card-body">
      <p class="section-title">📝 작성한 방명록</p>
      <div style="margin-bottom:6px"><?php for($i=1;$i<=5;$i++): ?><span style="color:<?=$i<=(int)$existingReview[\'rating\']?\'#ffb400\':\'#444\'?>;font-size:16px">★</span><?php endfor; ?></div>
      <?php if($existingReview[\'comment\']): ?><div style="font-size:12px;color:var(--text-main);line-height:1.5"><?=h($existingReview[\'comment\'])?></div><?php endif; ?>
    </div></div>
    <?php endif; ?>
  <?php else: ?>';

$content = str_replace($oldEvalEnd, $newEvalEnd, $content);

// Add guestbook form before the closing of team_eval page
$oldEvalFormEnd = "    <button type=\"submit\" class=\"btn btn-primary btn-w\">평가 완료</button>
  </form>
  <?php endif; ?>
</div>
<?php }";

$newEvalFormEnd = "    <button type=\"submit\" class=\"btn btn-primary btn-w\">평가 완료</button>
  </form>
  <?php endif; ?>

  <!-- 상대팀 평가 방명록 (별점 + 코멘트) -->
  <?php
    \$existingReview2 = null;
    try { \$revQ2 = \$pdo->prepare(\"SELECT * FROM team_match_reviews WHERE match_id=? AND reviewer_team_id=?\"); \$revQ2->execute([\$matchId, \$myTid]); \$existingReview2 = \$revQ2->fetch(); } catch(PDOException \$e) {}
  ?>
  <div class=\"card\" style=\"margin-top:16px\"><div class=\"card-body\">
    <p class=\"section-title\">📝 상대팀 방명록 (별점 + 코멘트)</p>
    <div style=\"font-size:11px;color:var(--text-sub);margin-bottom:10px\">이 리뷰는 우리 팀과 관리자만 볼 수 있습니다. 관리자가 공개 전환 시 상대팀에도 표시됩니다.</div>
    <?php if(\$existingReview2): ?>
    <div style=\"background:rgba(0,255,136,0.05);border:1px solid rgba(0,255,136,0.2);border-radius:10px;padding:12px;margin-bottom:8px\">
      <div style=\"margin-bottom:6px\">
        <?php for(\$i=1;\$i<=5;\$i++): ?>
        <span style=\"color:<?=\$i<=(int)\$existingReview2['rating']?'#ffb400':'#444'?>;font-size:16px\">★</span>
        <?php endfor; ?>
      </div>
      <?php if(\$existingReview2['comment']): ?>
      <div style=\"font-size:12px;color:var(--text-main);line-height:1.5\"><?=h(\$existingReview2['comment'])?></div>
      <?php endif; ?>
      <div style=\"font-size:10px;color:var(--text-sub);margin-top:6px\">작성일: <?=date('Y.m.d', strtotime(\$existingReview2['created_at']))?></div>
    </div>
    <?php else: ?>
    <form method=\"POST\">
      <?=csrfInput()?>
      <input type=\"hidden\" name=\"action\" value=\"submit_team_review\">
      <input type=\"hidden\" name=\"match_id\" value=\"<?=\$matchId?>\">
      <input type=\"hidden\" name=\"target_team_id\" value=\"<?=\$targetTid?>\">
      <div style=\"margin-bottom:12px\">
        <div style=\"font-size:12px;font-weight:600;color:var(--text-sub);margin-bottom:6px\">별점</div>
        <div id=\"tf-star-rating\" style=\"display:flex;gap:4px;font-size:24px;cursor:pointer\">
          <?php for(\$i=1;\$i<=5;\$i++): ?>
          <span data-val=\"<?=\$i?>\" onclick=\"setRating(<?=\$i?>)\" style=\"color:#444;transition:color 0.2s\">★</span>
          <?php endfor; ?>
        </div>
        <input type=\"hidden\" name=\"rating\" id=\"tf-rating-input\" value=\"3\">
      </div>
      <div style=\"margin-bottom:12px\">
        <div style=\"font-size:12px;font-weight:600;color:var(--text-sub);margin-bottom:6px\">코멘트 (선택)</div>
        <textarea name=\"review_comment\" class=\"form-control\" rows=\"3\" maxlength=\"500\" placeholder=\"상대팀에 대한 인상을 남겨주세요...\"></textarea>
      </div>
      <button type=\"submit\" class=\"btn btn-primary btn-w\">방명록 작성</button>
    </form>
    <script>
    function setRating(n){document.getElementById('tf-rating-input').value=n;var stars=document.querySelectorAll('#tf-star-rating span');stars.forEach(function(s){s.style.color=parseInt(s.dataset.val)<=n?'#ffb400':'#444';});}
    setRating(3);
    </script>
    <?php endif; ?>
  </div></div>
</div>
<?php }";

if (strpos($content, $oldEvalFormEnd) !== false) {
    $content = str_replace($oldEvalFormEnd, $newEvalFormEnd, $content);
    echo "Feature 4: Team eval page enhanced with guestbook\n";
} else {
    echo "Feature 4: WARNING - could not find eval form end\n";
}

// Write the file
file_put_contents($file, $content);
echo "\nAll patches applied. File written.\n";
echo "File size: " . strlen($content) . " bytes (" . substr_count($content, "\n") . " lines)\n";

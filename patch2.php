<?php
$file = '/var/www/html/app.php';
$content = file_get_contents($file);

$anchor = "        // ── [1-1] 노쇼/비매너 신고 ──
        case 'report_no_show':";

$insert = "        case 'submit_team_review':
            requireLogin();
            if (!isCaptain()) { flash('캡틴/매니저만 작성 가능합니다.', 'error'); redirect('?page=home'); }
            \$matchId = (int)(\$_POST['match_id'] ?? 0);
            \$targetTeamId = (int)(\$_POST['target_team_id'] ?? 0);
            \$rating = max(1, min(5, (int)(\$_POST['rating'] ?? 3)));
            \$comment = trim(mb_substr(\$_POST['review_comment'] ?? '', 0, 500));
            \$myTid = myTeamId();
            if (!\$matchId || !\$targetTeamId || !\$myTid) { flash('잘못된 요청입니다.', 'error'); redirect('?page=home'); }
            try {
                \$pdo->prepare(\"INSERT INTO team_match_reviews (match_id, reviewer_team_id, target_team_id, rating, comment, submitted_by)
                    VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment=VALUES(comment), submitted_by=VALUES(submitted_by)\")
                    ->execute([\$matchId, \$myTid, \$targetTeamId, \$rating, \$comment, me()['id']]);
                \$delta = (\$rating - 3) * 0.3;
                \$oppCap = \$pdo->prepare(\"SELECT user_id FROM team_members WHERE team_id=? AND role='captain' AND status='active' LIMIT 1\");
                \$oppCap->execute([\$targetTeamId]); \$oppCapId = (int)\$oppCap->fetchColumn();
                if (\$oppCapId && abs(\$delta) > 0) {
                    applyMannerDelta(\$pdo, \$oppCapId, \$delta, \$matchId, \"팀 방명록 평점: {\$rating}/5\");
                }
                flash('방명록이 등록되었습니다.');
            } catch(PDOException \$e) {
                flash('이미 작성된 방명록이 있거나 오류가 발생했습니다.', 'error');
            }
            redirect('?page=team_eval&match_id='.\$matchId);
            break;

        // ── [1-1] 노쇼/비매너 신고 ──
        case 'report_no_show':";

if (strpos($content, $anchor) !== false) {
    $content = str_replace($anchor, $insert, $content);
    file_put_contents($file, $content);
    echo "submit_team_review action handler inserted successfully\n";
} else {
    echo "ERROR: anchor not found\n";
}

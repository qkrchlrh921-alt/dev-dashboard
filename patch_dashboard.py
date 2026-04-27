#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import sys

with open("/var/www/html/dev-status.html", "r", encoding="utf-8") as f:
    content = f.read()

# 1. Add release tab button after roadmap button
old_btn = 'onclick="showTab(\'roadmap\',this)">&#128640; \ub85c\ub4dc\ub9f5</button>'
new_btn = old_btn + '\n  <button class="tab-btn" onclick="showTab(\'release\',this)">&#128203; \ub9b4\ub9ac\uc988</button>'
if old_btn not in content:
    print("ERROR: Could not find roadmap tab button")
    sys.exit(1)
content = content.replace(old_btn, new_btn)
print("OK: Added release tab button")

# 2. Add changelog at bottom of overview tab
changelog_html = """
  <!-- \ucd5c\uadfc \ubcc0\uacbd \ub85c\uadf8 -->
  <h2 style="margin-top:28px">&#128221; \ucd5c\uadfc \ubcc0\uacbd</h2>
  <div style="font-size:11px;color:#94a3b8;line-height:1.9">
    <div style="color:#3a9ef5;font-weight:700;font-size:12px;margin-bottom:4px">2026-04-27</div>
    <div style="padding-left:10px;margin-bottom:12px">
      &bull; Refresh Token \ud558\ub4dc\ub2dd (DB\uae30\ubc18, \ub9ac\ud50c\ub808\uc774 \uac10\uc9c0, logout/logout-all)<br>
      &bull; authFetch \uacf5\ud1b5 \ub798\ud37c + \uc790\ub3d9 \ud1a0\ud070 \uac31\uc2e0<br>
      &bull; Admin JWT Bearer \uc804\ud658 (X-Admin-Secret \uc81c\uac70)<br>
      &bull; Biz API \uc815\ud569\uc131 \uc218\uc815 (9\uac1c \uc5d4\ub4dc\ud3ec\uc778\ud2b8)<br>
      &bull; \ucd95\uad6c\uc571: \ub178\uc1fc/\ub2a6\uc740\ucde8\uc18c \uad6c\ubd84, \uc774\uc758\uc81c\uae30 \ud50c\ub85c\uc6b0, unread \uce74\uc6b4\ud2b8<br>
      &bull; \ucd95\uad6c\uc571: \ube48 \ud398\uc774\uc9c0 CTA, \ubc84\uadf8\ub9ac\ud3ec\ud2b8 \uad00\ub9ac\uc790\ud0ed<br>
      &bull; \ucd95\uad6c\uc571: \uc885\ub8cc\uacbd\uae30 pending \uc815\ub9ac, \ucc28\ub2e8 \uad00\uacc4 \uc815\ub9ac<br>
      &bull; \ub9e4\ub9e4\ubd07: \uae34\uae09 \ud0ac\uc2a4\uc704\uce58 (/kill)<br>
      &bull; \uc790\ub3d9 QA \uc2dc\uc2a4\ud15c (27\uac1c \ud14c\uc2a4\ud2b8, 30\ubd84 \ud06c\ub860)<br>
      &bull; \ub300\uc2dc\ubcf4\ub4dc GitHub Pages \uacf5\uac1c
    </div>
    <div style="color:#3a9ef5;font-weight:700;font-size:12px;margin-bottom:4px">2026-04-26</div>
    <div style="padding-left:10px">
      &bull; IR \ud380\ub529 API 5\uac1c \uc5d4\ub4dc\ud3ec\uc778\ud2b8<br>
      &bull; Sentry \uc900\ube44 \ucf54\ub4dc<br>
      &bull; matchStatusLabel() \uc0c1\ud0dc \ud55c\uad6d\uc5b4\ud654<br>
      &bull; BB \ud130\uce58 \uc775\uc808 ($500/20%)
    </div>
  </div>"""

# Find overview tab closing </div> before football tab
marker = '</div>\n\n<!-- ========== \ucd95\uad6c\uc571'
if marker not in content:
    print("ERROR: Could not find overview tab end marker")
    sys.exit(1)
content = content.replace(marker, changelog_html + '\n</div>\n\n<!-- ========== \ucd95\uad6c\uc571', 1)
print("OK: Added changelog to overview tab")

# 3. Add release tab content before footer
release_content = """
<!-- ========== \ub9b4\ub9ac\uc988 \ud0ed ========== -->
<div id="tab-release" class="tab-content">
  <h2>&#128203; \ub9b4\ub9ac\uc988 \uccb4\ud06c\ub9ac\uc2a4\ud2b8</h2>
  <p style="font-size:13px;color:#94a3b8;margin-bottom:16px">\ubc30\ud3ec \uc900\ube44 \uc0c1\ud0dc \uc810\uac80</p>

  <div class="cat-header">&#9917; TRUST FOOTBALL \ubc30\ud3ec \uccb4\ud06c\ub9ac\uc2a4\ud2b8</div>
  <table><thead><tr><th>\ud56d\ubaa9</th><th>\uc0c1\ud0dc</th></tr></thead><tbody>
  <tr><td>PHP \ubb38\ubc95 \uccb4\ud06c (php -l)</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>SQL Injection \ubc29\uc5b4 (PDO)</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>XSS \ubc29\uc5b4 (htmlspecialchars)</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>CSRF \ud1a0\ud070</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>\uc138\uc158 \uad00\ub9ac</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>DB \ubc31\uc5c5 \uccb4\uacc4</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>\uac10\uc0ac\ub85c\uadf8</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>\uc81c\uc7ac \uc2dc\uc2a4\ud15c</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>HTTPS (\ub3c4\uba54\uc778 \ud544\uc694)</td><td><span class="badge badge-partial">&#128993; \ub300\uae30</span></td></tr>
  <tr><td>Secure Cookie (HTTPS \uc774\ud6c4)</td><td><span class="badge badge-partial">&#128993; \ub300\uae30</span></td></tr>
  <tr><td>.env \ubd84\ub9ac (localhost)</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>\uc5d0\ub7ec \ub85c\uadf8 \ubaa8\ub2c8\ud130\ub9c1</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  </tbody></table>

  <div class="cat-header">&#128200; RE:CORD \ubc30\ud3ec \uccb4\ud06c\ub9ac\uc2a4\ud2b8</div>
  <table><thead><tr><th>\ud56d\ubaa9</th><th>\uc0c1\ud0dc</th></tr></thead><tbody>
  <tr><td>JWT \uc778\uc99d (access + refresh)</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>\ud1a0\ud070 \ub85c\ud14c\uc774\uc158 + \ub9ac\ud50c\ub808\uc774 \uac10\uc9c0</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>bcrypt \ud574\uc2f1</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>CORS \uc81c\ud55c</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>Rate Limit (60/min)</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>Admin \ubbf8\ub4e4\uc6e8\uc5b4</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>IDOR \uc218\uc815</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>/docs \ube44\ub178\ucd9c (production)</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>Biz API \uc815\ud569\uc131</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>HTTPS (\ub3c4\uba54\uc778 \ud544\uc694)</td><td><span class="badge badge-partial">&#128993; \ub300\uae30</span></td></tr>
  <tr><td>Sentry (DSN \ub300\uae30)</td><td><span class="badge badge-partial">&#128993; \ub300\uae30</span></td></tr>
  <tr><td>systemd \uc11c\ube44\uc2a4</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  </tbody></table>

  <div class="cat-header">&#127760; \uacf5\ud1b5</div>
  <table><thead><tr><th>\ud56d\ubaa9</th><th>\uc0c1\ud0dc</th></tr></thead><tbody>
  <tr><td>Git \ube0c\ub79c\uce58 (main/dev)</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>\uc790\ub3d9 QA (27\uac1c \ud14c\uc2a4\ud2b8, 30\ubd84 \ud06c\ub860)</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>\ud154\ub808\uadf8\ub7a8 \uc54c\ub9bc</td><td><span class="badge badge-done">&#9989; \uc644\ub8cc</span></td></tr>
  <tr><td>\ub3c4\uba54\uc778 \ubbf8\ud655\ubcf4</td><td><span class="badge badge-partial">&#128993; \ub300\uae30</span></td></tr>
  <tr><td>\uc571\uc2a4\ud1a0\uc5b4 \ubbf8\ub4f1\ub85d</td><td><span class="badge badge-todo">&#10060; \ubbf8\uc644</span></td></tr>
  </tbody></table>

  <div class="summary" style="margin-top:16px">
    <div class="stat"><div class="num green">24</div><div class="label">\uc644\ub8cc</div></div>
    <div class="stat"><div class="num yellow">5</div><div class="label">\ub300\uae30</div></div>
    <div class="stat"><div class="num red">1</div><div class="label">\ubbf8\uc644</div></div>
    <div class="stat"><div class="num blue">80%</div><div class="label">\ubc30\ud3ec \uc900\ube44\uc728</div></div>
  </div>
</div>

"""

footer = '<!-- \ud558\ub2e8 \uc5c5\ub370\uc774\ud2b8 \uc2dc\uac04 -->'
if footer not in content:
    print("ERROR: Could not find footer marker")
    sys.exit(1)
content = content.replace(footer, release_content + footer)
print("OK: Added release tab content")

with open("/var/www/html/dev-status.html", "w", encoding="utf-8") as f:
    f.write(content)

print("ALL DONE")

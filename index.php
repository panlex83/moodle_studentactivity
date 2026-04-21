<?php
// local/studentactivity/index.php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/studentactivity:view', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/studentactivity/index.php'));
$PAGE->set_title(get_string('studentactivity', 'local_studentactivity'));
$PAGE->set_heading(get_string('studentactivity', 'local_studentactivity'));
$PAGE->set_pagelayout('standard');

// ── Period ──────────────────────────────────────────────────────────────────
$period = optional_param('period', 'week', PARAM_ALPHA);
switch ($period) {
    case 'month':
        $from = mktime(0, 0, 0, (int)date('n'), 1);
        break;
    case 'semester':
        $m = (int)date('n');
        $y = (int)date('Y');
        $from = $m >= 8 ? mktime(0,0,0,8,1,$y) : mktime(0,0,0,2,1,$y);
        break;
    default:
        $period = 'week';
        $from = strtotime('monday this week');
        break;
}
$to = time();

// ── Optional student filter: ?userids=1,2,3 ────────────────────────────────
$raw_ids = optional_param('userids', '', PARAM_TEXT);
$filter_ids = [];
if ($raw_ids !== '') {
    foreach (explode(',', $raw_ids) as $id) {
        $id = (int)trim($id);
        if ($id > 0) $filter_ids[] = $id;
    }
}

// ── Load students ───────────────────────────────────────────────────────────
$students_list = sad_get_students($filter_ids);

if (empty($students_list)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        'Ученики не найдены. Убедитесь что в системе есть пользователи с ролью «student», ' .
        'или передайте список ID через ?userids=1,2,3',
        'warning'
    );
    echo $OUTPUT->footer();
    exit;
}

// ── Build data per student ─────────────────────────────────────────────────
$students_data = [];
foreach ($students_list as $s) {
    $students_data[] = sad_student_data($s, $from, $to);
}

$students_json = json_encode($students_data, JSON_UNESCAPED_UNICODE);
$period_json   = json_encode($period);

echo $OUTPUT->header();
?>
<style>
#sad-root *,#sad-root *::before,#sad-root *::after{box-sizing:border-box}
#sad-root{font-family:inherit;padding:0 0 2rem}
.sad-tabbar{display:flex;align-items:center;gap:0;border-bottom:1px solid #dee2e6;margin-bottom:1.5rem;flex-wrap:wrap}
.sad-tab{padding:10px 18px;font-size:14px;border:none;border-bottom:2px solid transparent;background:transparent;cursor:pointer;color:#6c757d;font-family:inherit;margin-bottom:-1px;transition:color .15s,border-color .15s;white-space:nowrap}
.sad-tab:hover{color:#343a40}
.sad-tab.active{color:#0f6e56;border-bottom-color:#0f6e56;font-weight:500}
.sad-tab-alert{display:inline-block;width:7px;height:7px;border-radius:50%;background:#dc3545;margin-left:5px;vertical-align:middle;position:relative;top:-1px}
.sad-psel{font-size:13px;border:1px solid #ced4da;border-radius:6px;padding:5px 10px;background:#fff;font-family:inherit;color:#343a40;margin-left:auto;margin-top:4px}
.sad-mgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:1.5rem}
.sad-mc{background:#f8f9fa;border-radius:8px;padding:12px 14px}
.sad-mc-l{font-size:12px;color:#6c757d;margin-bottom:4px}
.sad-mc-v{font-size:22px;font-weight:600;color:#212529;line-height:1}
.sad-mc-s{font-size:11px;color:#adb5bd;margin-top:3px}
.sad-badge{display:inline-block;font-size:11px;padding:2px 8px;border-radius:20px;font-weight:500;line-height:1.6}
.sad-ok{background:#d1e7dd;color:#0a3622}
.sad-wn{background:#fff3cd;color:#664d03}
.sad-er{background:#f8d7da;color:#58151c}
.sad-card{background:#fff;border:1px solid #dee2e6;border-radius:10px;padding:1rem 1.25rem}
.sad-card-title{font-size:13px;font-weight:600;color:#212529;margin-bottom:12px}
.sad-two{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:1.5rem}
@media(max-width:640px){.sad-two{grid-template-columns:1fr}}
.sad-chart-wrap{position:relative;width:100%;height:160px}
.sad-dots{display:flex;gap:3px;flex-wrap:wrap;margin-top:8px}
.sad-dot{width:13px;height:13px;border-radius:3px}
.sad-sr{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.sad-sn{font-size:12px;color:#6c757d;width:100px;flex-shrink:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sad-pt{flex:1;height:5px;background:#e9ecef;border-radius:3px;overflow:hidden}
.sad-pf{height:100%;border-radius:3px}
.sad-pp{font-size:11px;color:#adb5bd;width:28px;text-align:right;flex-shrink:0}
.sad-gt{width:100%;font-size:12px;border-collapse:collapse}
.sad-gt th{color:#adb5bd;font-weight:500;padding:4px 5px;text-align:left;border-bottom:1px solid #f0f0f0}
.sad-gt td{padding:5px 5px;border-bottom:1px solid #f0f0f0;color:#6c757d}
.sad-gt tr:last-child td{border-bottom:none}
.sad-gp{display:inline-block;width:24px;text-align:center;padding:2px 0;border-radius:4px;font-weight:600;font-size:11px}
.sad-gA{background:#d1e7dd;color:#0a3622}.sad-gB{background:#cfe2ff;color:#084298}
.sad-gC{background:#fff3cd;color:#664d03}.sad-gD{background:#f8d7da;color:#58151c}
.sad-gN{background:#f8f9fa;color:#adb5bd}
.sad-dl-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid #f0f0f0;font-size:12px;gap:8px}
.sad-dl-row:last-child{border-bottom:none}
.sad-dl-sub{font-size:11px;color:#adb5bd;margin-top:1px}
.sad-no-access{background:#fff5f5;border:1px solid #f5c2c7;border-radius:8px;padding:12px 16px;margin-bottom:1.5rem;font-size:13px;color:#842029}
.sad-slbl{font-size:11px;font-weight:600;color:#adb5bd;letter-spacing:.07em;text-transform:uppercase;margin-bottom:10px}
</style>

<div id="sad-root">
  <div class="sad-tabbar" id="sad-tabs"></div>
  <div id="sad-content"></div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<script>
(function(){
'use strict';
const S = <?php echo $students_json; ?>;
const INIT_PERIOD = <?php echo $period_json; ?>;
const WEEK_LABELS = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];

let cur = 0;
let chartInst = null;
let currentPeriod = INIT_PERIOD;

function pc(p){if(p>=80)return'#639922';if(p>=55)return'#378ADD';if(p>=30)return'#BA7517';return'#E24B4A';}
function gc(g){if(g==='A')return'sad-gA';if(g==='B')return'sad-gB';if(g==='C')return'sad-gC';if(g==='D')return'sad-gD';return'sad-gN';}
function trendCol(t){if(t&&t[0]==='+')return'#3B6D11';if(t&&t[0]==='-')return'#A32D2D';return'#adb5bd';}
function hasAlert(s){return !s.access||(s.dl||[]).some(d=>d.late);}

function getAct(s){return currentPeriod==='week'?(s.actW||[]):(s.actM||[]);}
function getLabels(s){
    if(currentPeriod==='week') return WEEK_LABELS.slice(0,(s.actW||[]).length);
    return (s.actM||[]).map((_,i)=>String(i+1));
}

function changePeriod(val){
    const url=new URL(window.location.href);
    url.searchParams.set('period',val);
    window.location.href=url.toString();
}

function renderTabs(){
    const bar=document.getElementById('sad-tabs');
    bar.innerHTML=S.map((s,i)=>{
        const dot=hasAlert(s)?'<span class="sad-tab-alert"></span>':'';
        const parts=s.name.split(' ');
        return`<button class="sad-tab${i===cur?' active':''}" onclick="sadSwitch(${i})">${parts.slice(0,2).join(' ')}${dot}</button>`;
    }).join('')+
    `<select class="sad-psel" onchange="changePeriod(this.value)">
       <option value="week"${currentPeriod==='week'?' selected':''}>Текущая неделя</option>
       <option value="month"${currentPeriod==='month'?' selected':''}>Текущий месяц</option>
       <option value="semester"${currentPeriod==='semester'?' selected':''}>Семестр</option>
     </select>`;
}

function render(s){
    const actD=getAct(s);
    const lbls=getLabels(s);
    const tl=(s.subs||[]).reduce((a,x)=>a+(x.l||0),0);
    const th=(s.subs||[]).reduce((a,x)=>a+(x.h||0),0);
    const ts=(s.subs||[]).reduce((a,x)=>a+(x.s||0),0);
    const late=(s.dl||[]).filter(d=>d.late);
    const pct=s.pct||0;

    const ab=s.access
        ?'<span class="sad-badge sad-ok">Доступ открыт</span>'
        :'<span class="sad-badge sad-er">Нет доступа</span>';
    const sb=s.streak>0
        ?`<span class="sad-badge sad-ok">${s.streak} дн. подряд</span>`
        :'<span class="sad-badge sad-er">Нет активности</span>';
    const lateBadge=late.length
        ?`<span class="sad-badge sad-er">${late.length} просрочено</span>`:'';

    const banner=!s.access
        ?`<div class="sad-no-access"><strong>${s.name}</strong> не имеет доступа ни к одному курсу — активность не фиксируется. Проверьте записи на курсы.</div>`
        :'';

    const dots=actD.map(v=>{
        const op=v>0?Math.min(.25+v/180*.75,1):.1;
        const col=v>0?'#1D9E75':'#B4B2A9';
        return`<div class="sad-dot" style="background:${col};opacity:${op}" title="${v} мин"></div>`;
    }).join('');

    const dlHtml=(s.dl||[]).length===0
        ?'<div style="font-size:13px;color:#adb5bd;text-align:center;padding:14px 0">Нет ближайших дедлайнов и просрочек</div>'
        :(s.dl||[]).map(d=>{
            const b=d.late
                ?'<span class="sad-badge sad-er">Просрочено</span>'
                :d.days<=2
                    ?`<span class="sad-badge sad-wn">${d.days} дн.</span>`
                    :`<span class="sad-badge sad-ok">${d.days} дн.</span>`;
            return`<div class="sad-dl-row">
              <div>
                <div style="color:#212529;font-size:12px">${d.n}</div>
                <div class="sad-dl-sub">${d.sub} · ${d.d}</div>
              </div>${b}
            </div>`;
        }).join('');

    const pctLbl=pct>=70?'топ класса':pct>=40?'средний уровень':pct>0?'ниже среднего':'нет данных';

    document.getElementById('sad-content').innerHTML=`
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:1.5rem">
        <div style="width:46px;height:46px;border-radius:50%;background:${s.access?'#e8f4ff':'#f8d7da'};display:flex;align-items:center;justify-content:center;font-weight:600;font-size:15px;color:${s.access?'#185FA5':'#842029'};flex-shrink:0">${s.av}</div>
        <div>
          <div style="font-size:17px;font-weight:600;color:#212529">${s.name}</div>
          <div style="font-size:12px;color:#6c757d;margin-top:4px;display:flex;gap:7px;flex-wrap:wrap;align-items:center">
            <span>Класс / когорта: ${s.cl}</span><span>·</span>
            <span>${s.tot} курсов</span><span>·</span>
            ${ab}<span>·</span>${sb}${lateBadge?'<span>·</span>'+lateBadge:''}
          </div>
        </div>
      </div>

      ${banner}

      <div class="sad-slbl">Сводка за период</div>
      <div class="sad-mgrid">
        <div class="sad-mc"><div class="sad-mc-l">Лекций просмотрено</div><div class="sad-mc-v">${tl}</div><div class="sad-mc-s">ресурсов за период</div></div>
        <div class="sad-mc"><div class="sad-mc-l">ДЗ сдано</div><div class="sad-mc-v">${th}</div><div class="sad-mc-s">заданий за период</div></div>
        <div class="sad-mc"><div class="sad-mc-l">Синхр. уроков</div><div class="sad-mc-v">${ts}</div><div class="sad-mc-s">отмечен присутствующим</div></div>
        <div class="sad-mc"><div class="sad-mc-l">Ср. время/день</div><div class="sad-mc-v">${s.avg>0?s.avg+' мин':'—'}</div><div class="sad-mc-s">активных дней</div></div>
        <div class="sad-mc"><div class="sad-mc-l">Место в классе</div><div class="sad-mc-v">${pct>0?pct+'%':'—'}</div><div class="sad-mc-s">${pctLbl}</div></div>
        <div class="sad-mc"><div class="sad-mc-l">Streak</div><div class="sad-mc-v">${s.streak}</div><div class="sad-mc-s">${s.streak>0?'дней подряд':'активность прервана'}</div></div>
      </div>

      <div class="sad-slbl">Активность по дням</div>
      <div class="sad-card" style="margin-bottom:1.5rem">
        <div class="sad-card-title">Примерное время на платформе (мин/день)</div>
        <div class="sad-chart-wrap"><canvas id="sad-act-chart" role="img" aria-label="Активность по дням">Данные активности.</canvas></div>
        <div style="margin-top:10px">
          <div style="font-size:11px;color:#adb5bd;margin-bottom:6px">Дни присутствия (насыщенность цвета = длительность)</div>
          <div class="sad-dots">${dots}</div>
        </div>
      </div>

      <div class="sad-two">
        <div class="sad-card">
          <div class="sad-card-title">Прогресс по курсам</div>
          ${(s.subs||[]).length===0
            ?'<div style="color:#adb5bd;font-size:13px;text-align:center;padding:12px 0">Нет данных о курсах</div>'
            :(s.subs||[]).map(x=>`<div class="sad-sr">
                <span class="sad-sn" title="${x.n}">${x.n}</span>
                <div class="sad-pt"><div class="sad-pf" style="width:${x.p}%;background:${pc(x.p)}"></div></div>
                <span class="sad-pp">${x.p}%</span>
              </div>`).join('')}
        </div>
        <div class="sad-card">
          <div class="sad-card-title">Оценки и детали</div>
          ${(s.subs||[]).length===0
            ?'<div style="color:#adb5bd;font-size:13px;text-align:center;padding:12px 0">Нет данных об оценках</div>'
            :`<table class="sad-gt">
                <thead><tr><th>Курс</th><th>Оц</th><th>↕</th><th>Л/ДЗ/С</th></tr></thead>
                <tbody>${(s.subs||[]).map(x=>`<tr>
                  <td style="color:#343a40">${x.n}</td>
                  <td><span class="sad-gp ${gc(x.g)}">${x.g}</span></td>
                  <td style="color:${trendCol(x.t)}">${x.t}</td>
                  <td>${x.l}/${x.h}/${x.s}</td>
                </tr>`).join('')}</tbody>
              </table>`}
        </div>
      </div>

      <div class="sad-slbl">Дедлайны и просрочки</div>
      <div class="sad-card" style="margin-bottom:1.5rem">
        <div class="sad-card-title">Задания: следующие 14 дней + просрочки за 7 дней</div>
        ${dlHtml}
      </div>
    `;

    if(chartInst){chartInst.destroy();chartInst=null;}
    const ctx=document.getElementById('sad-act-chart');
    if(ctx&&actD.length){
        chartInst=new Chart(ctx,{
            type:'bar',
            data:{
                labels:lbls,
                datasets:[{label:'мин',data:actD,
                    backgroundColor:actD.map(v=>v>0?'#1D9E75':'rgba(180,178,169,.3)'),
                    borderRadius:3,borderSkipped:false}]
            },
            options:{
                responsive:true,maintainAspectRatio:false,
                plugins:{legend:{display:false}},
                scales:{
                    x:{grid:{display:false},ticks:{font:{size:10},color:'#adb5bd',maxRotation:0,autoSkip:true,maxTicksLimit:15}},
                    y:{grid:{color:'rgba(0,0,0,.05)'},ticks:{font:{size:10},color:'#adb5bd'},beginAtZero:true}
                }
            }
        });
    }
}

window.sadSwitch=function(i){cur=i;renderTabs();render(S[i]);};
renderTabs();
if(S.length) render(S[0]);
})();
</script>
<?php echo $OUTPUT->footer();

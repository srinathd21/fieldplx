<?php
$token=isset($_GET['token'])?trim((string)$_GET['token']):'';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Service Review - FieldPlx</title>
    <style>
        :root{
            --navy:#001131;
            --navy2:#071f49;
            --green:#74b824;
            --green-dark:#5d971b;
            --green-soft:#f0f8e5;
            --text:#0b1933;
            --muted:#6f7b90;
            --border:#e5eaf1;
            --bg:#f6f8fb;
            --red:#e45b66;
        }
        *{box-sizing:border-box}
        body{
            margin:0;
            min-height:100vh;
            background:
                radial-gradient(circle at 10% 0,rgba(116,184,36,.12),transparent 31%),
                var(--bg);
            color:var(--text);
            font-family:Arial,Helvetica,sans-serif;
        }
        .cr-top{
            min-height:72px;
            padding:0 22px;
            display:flex;
            align-items:center;
            justify-content:center;
            color:#fff;
            background:linear-gradient(135deg,var(--navy2),var(--navy));
            box-shadow:0 8px 26px rgba(0,17,49,.12);
        }
        .cr-brand{
            width:min(980px,100%);
            display:flex;
            align-items:center;
            gap:11px;
            font-weight:700;
        }
        .cr-logo{
            width:40px;height:40px;display:grid;place-items:center;
            border-radius:11px;background:linear-gradient(135deg,#8fd236,#68aa1d);
            font-size:18px;
        }
        .cr-wrap{width:min(980px,calc(100% - 28px));margin:28px auto 44px}
        .cr-card{
            overflow:hidden;
            margin-bottom:16px;
            border:1px solid var(--border);
            border-radius:14px;
            background:#fff;
            box-shadow:0 5px 20px rgba(24,45,76,.05);
        }
        .cr-head{padding:24px 25px;border-bottom:1px solid var(--border)}
        .cr-head h1{margin:0;font-size:24px;line-height:1.2}
        .cr-head p{margin:8px 0 0;color:var(--muted);font-size:13px;line-height:1.55}
        .cr-body{padding:22px 25px}
        .cr-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
        .cr-info{
            min-height:83px;padding:12px 13px;border:1px solid #e8edf2;
            border-radius:10px;background:#fbfcfd;
        }
        .cr-info span,.cr-info strong{display:block}
        .cr-info span{margin-bottom:7px;color:#8793a5;font-size:10px;font-weight:700;text-transform:uppercase}
        .cr-info strong{font-size:13px;line-height:1.45;overflow-wrap:anywhere}
        .cr-info.total{background:var(--green-soft);border-color:#dbe9c9}
        .cr-info.total strong{color:var(--green-dark);font-size:17px}
        .cr-section-title{margin:0 0 5px;font-size:15px}
        .cr-section-sub{margin:0 0 17px;color:var(--muted);font-size:12px}
        .cr-rating-row{
            display:grid;grid-template-columns:minmax(180px,1fr) 260px;
            gap:14px;align-items:center;padding:13px 0;border-bottom:1px solid #eef2f5;
        }
        .cr-rating-row:last-child{border-bottom:0}
        .cr-rating-copy strong{display:block;font-size:13px}
        .cr-rating-copy small{display:block;margin-top:3px;color:var(--muted);font-size:11px}
        .cr-stars{display:flex;justify-content:flex-end;gap:6px;direction:rtl}
        .cr-stars input{position:absolute;opacity:0;pointer-events:none}
        .cr-stars label{
            width:38px;height:38px;display:grid;place-items:center;cursor:pointer;
            border:1px solid #dfe5ec;border-radius:9px;background:#fff;
            color:#c6ced8;font-size:22px;transition:.15s;
        }
        .cr-stars label:hover,
        .cr-stars label:hover~label,
        .cr-stars input:checked~label{
            border-color:#b8d88d;background:var(--green-soft);color:var(--green-dark);
        }
        .cr-worker{
            margin-top:11px;padding:14px;border:1px solid #e7ecf2;
            border-radius:10px;background:#fbfcfd;
        }
        .cr-worker-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
        .cr-worker-name strong{display:block;font-size:13px}
        .cr-worker-name small{display:block;margin-top:3px;color:var(--muted);font-size:10px}
        .cr-worker .cr-stars label{width:34px;height:34px;font-size:19px}
        textarea{
            width:100%;min-height:105px;margin-top:12px;padding:12px;
            border:1px solid #dfe5ec;border-radius:9px;outline:0;
            color:var(--text);background:#fff;font:inherit;resize:vertical;
        }
        textarea:focus{border-color:#b8d88d;box-shadow:0 0 0 3px rgba(116,184,36,.11)}
        .cr-actions{padding:0 25px 24px;display:flex;justify-content:flex-end}
        .cr-btn{
            min-height:44px;padding:0 20px;border:0;border-radius:9px;cursor:pointer;
            color:#fff;background:linear-gradient(90deg,#7fc92d,#68aa1d);
            font-size:13px;font-weight:700;box-shadow:0 8px 18px rgba(104,170,29,.2);
        }
        .cr-btn:disabled{opacity:.6;cursor:not-allowed}
        .cr-state{padding:45px 24px;text-align:center}
        .cr-state h2{margin:12px 0 7px;font-size:21px}
        .cr-state p{margin:0;color:var(--muted);font-size:13px;line-height:1.6}
        .cr-state-icon{
            width:58px;height:58px;margin:auto;display:grid;place-items:center;
            border-radius:50%;background:var(--green-soft);color:var(--green-dark);
            font-size:28px;font-weight:700;
        }
        .cr-error .cr-state-icon{background:#fff0f1;color:var(--red)}
        .cr-toast{
            width:min(380px,calc(100vw - 24px));position:fixed;right:16px;top:16px;
            z-index:20;padding:12px 14px;border-radius:9px;color:#fff;background:var(--navy);
            box-shadow:0 12px 30px rgba(0,17,49,.18);opacity:0;transform:translateY(-8px);
            pointer-events:none;transition:.18s;font-size:12px;font-weight:700;
        }
        .cr-toast.show{opacity:1;transform:translateY(0)}
        .cr-toast.error{background:var(--red)}
        .cr-toast.success{background:var(--green-dark)}
        @media(max-width:760px){
            .cr-grid{grid-template-columns:1fr 1fr}
            .cr-rating-row{grid-template-columns:1fr}
            .cr-stars{justify-content:flex-start}
            .cr-worker-head{align-items:flex-start;flex-direction:column}
        }
        @media(max-width:520px){
            .cr-wrap{margin-top:16px}
            .cr-grid{grid-template-columns:1fr}
            .cr-head,.cr-body{padding:18px}
            .cr-actions{padding:0 18px 18px}
            .cr-btn{width:100%}
        }
    </style>
</head>
<body>
    <header class="cr-top">
        <div class="cr-brand"><span class="cr-logo">F</span><span>FieldPlx</span></div>
    </header>

    <main class="cr-wrap">
        <div class="cr-card" id="loadingCard">
            <div class="cr-state">
                <div class="cr-state-icon">…</div>
                <h2>Loading your service review</h2>
                <p>Please wait while we load the completed job details.</p>
            </div>
        </div>

        <div id="reviewContent" style="display:none">
            <section class="cr-card">
                <div class="cr-head">
                    <h1>How was your service?</h1>
                    <p id="reviewSubtitle">Your feedback helps us improve our service and recognise great service professionals.</p>
                </div>
                <div class="cr-body">
                    <div class="cr-grid">
                        <div class="cr-info"><span>Job</span><strong id="jobNo">-</strong></div>
                        <div class="cr-info"><span>Service</span><strong id="serviceName">-</strong></div>
                        <div class="cr-info"><span>Completed</span><strong id="completedAt">-</strong></div>
                        <div class="cr-info"><span>Subtotal</span><strong id="subtotal">-</strong></div>
                        <div class="cr-info"><span>Tax</span><strong id="taxTotal">-</strong></div>
                        <div class="cr-info total"><span>Job Total</span><strong id="jobTotal">-</strong></div>
                    </div>
                </div>
            </section>

            <form id="reviewForm">
                <section class="cr-card">
                    <div class="cr-body">
                        <h2 class="cr-section-title">Service Review</h2>
                        <p class="cr-section-sub">Please rate your completed service.</p>

                        <div id="mainRatings"></div>

                        <textarea id="reviewText" name="review_text" maxlength="3000" placeholder="Tell us more about your experience (optional)"></textarea>
                    </div>
                </section>

                <section class="cr-card" id="workersCard">
                    <div class="cr-body">
                        <h2 class="cr-section-title">Review Your Service Professional(s)</h2>
                        <p class="cr-section-sub">Rate the employee or employees who attended this job.</p>
                        <div id="workerReviews"></div>
                    </div>
                    <div class="cr-actions">
                        <button type="submit" class="cr-btn" id="submitButton">Submit Review</button>
                    </div>
                </section>
            </form>
        </div>

        <div class="cr-card cr-error" id="errorCard" style="display:none">
            <div class="cr-state">
                <div class="cr-state-icon">!</div>
                <h2>Review link unavailable</h2>
                <p id="errorMessage">This review link is invalid or has expired.</p>
            </div>
        </div>

        <div class="cr-card" id="thanksCard" style="display:none">
            <div class="cr-state">
                <div class="cr-state-icon">✓</div>
                <h2>Thank you for your review</h2>
                <p>Your feedback has been submitted successfully.</p>
            </div>
        </div>
    </main>

    <div class="cr-toast" id="toast">Notification</div>

<script>
(function(){
    'use strict';

    var token=<?= json_encode($token) ?>;
    var apiUrl='api/customer-review.php';
    var state={workers:[],currency:{}};
    var toastTimer=null;

    function el(id){return document.getElementById(id)}
    function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
    function notify(type,message){
        var t=el('toast');
        if(toastTimer)clearTimeout(toastTimer);
        t.className='cr-toast '+(type||'')+' show';
        t.textContent=message||'Notification';
        toastTimer=setTimeout(function(){t.classList.remove('show')},3500);
    }
    function parse(response){
        return response.text().then(function(raw){
            var data,text=String(raw||'').trim();
            try{data=text?JSON.parse(text):{}}
            catch(e){throw new Error(text.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}
            if(!response.ok||!data.success)throw new Error(data.message||'Request failed.');
            return data;
        });
    }
    function request(fd){
        fd.append('token',token);
        return fetch(apiUrl,{
            method:'POST',
            body:fd,
            cache:'no-store',
            headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
        }).then(parse);
    }
    function money(value){
        var c=state.currency||{};
        var places=parseInt(c.decimal_places,10);
        if(isNaN(places))places=2;
        var n=Number(value||0).toFixed(places);
        var symbol=c.symbol||'';
        return c.symbol_position==='after'?n+(symbol?' '+symbol:''):(symbol||'')+n;
    }
    function formatDateTime(value){
        if(!value)return '-';
        var d=new Date(String(value).replace(' ','T'));
        if(isNaN(d.getTime()))return String(value);
        return d.toLocaleString(undefined,{
            year:'numeric',month:'short',day:'2-digit',
            hour:'2-digit',minute:'2-digit'
        });
    }
    function starInput(name,label,description){
        var html='<div class="cr-rating-row"><div class="cr-rating-copy"><strong>'+esc(label)+'</strong><small>'+esc(description)+'</small></div><div class="cr-stars">';
        for(var i=5;i>=1;i--){
            html+='<input type="radio" id="'+esc(name)+'_'+i+'" name="'+esc(name)+'" value="'+i+'" required>'
                +'<label for="'+esc(name)+'_'+i+'" title="'+i+' out of 5">★</label>';
        }
        html+='</div></div>';
        return html;
    }
    function renderWorkers(workers){
        state.workers=workers||[];
        var box=el('workerReviews');

        if(!state.workers.length){
            box.innerHTML='<div class="cr-worker">No individual service professional is attached to this job.</div>';
            return;
        }

        var html='';
        state.workers.forEach(function(worker,index){
            var name='worker_'+Number(worker.id);
            html+='<div class="cr-worker" data-worker-id="'+Number(worker.id)+'">'
                +'<div class="cr-worker-head">'
                    +'<div class="cr-worker-name"><strong>'+esc(worker.name||'Service Professional')+'</strong><small>'+esc(worker.job_title||worker.assignment_role||'Assigned Service Professional')+'</small></div>'
                    +'<div class="cr-stars">';
            for(var i=5;i>=1;i--){
                html+='<input type="radio" id="'+name+'_'+i+'" name="'+name+'" value="'+i+'">'
                    +'<label for="'+name+'_'+i+'" title="'+i+' out of 5">★</label>';
            }
            html+='</div></div>'
                +'<textarea class="worker-comment" maxlength="1000" placeholder="Comment about '+esc(worker.name||'this service professional')+' (optional)"></textarea>'
                +'</div>';
        });

        box.innerHTML=html;
    }
    function showError(message){
        el('loadingCard').style.display='none';
        el('reviewContent').style.display='none';
        el('thanksCard').style.display='none';
        el('errorCard').style.display='block';
        el('errorMessage').textContent=message||'This review link is unavailable.';
    }
    function load(){
        if(!token){
            showError('This review link is missing its secure token.');
            return;
        }

        var fd=new FormData();
        fd.append('action','load');

        request(fd).then(function(data){
            el('loadingCard').style.display='none';

            if(Number(data.completed||0)===1){
                el('thanksCard').style.display='block';
                return;
            }

            state.currency=data.currency||{};
            var job=data.job||{};
            var client=data.client||{};

            el('reviewSubtitle').textContent='Hello '+(client.name||'Customer')+'. Please review '+(job.job_no||'your completed job')+'.';
            el('jobNo').textContent=job.job_no||'-';
            el('serviceName').textContent=job.service_name||job.title||'Service Job';
            el('completedAt').textContent=formatDateTime(job.completed_at);
            el('subtotal').textContent=money(job.subtotal);
            el('taxTotal').textContent=money(job.tax_total);
            el('jobTotal').textContent=money(job.total);

            el('mainRatings').innerHTML=
                starInput('service_rating','Service Quality','How satisfied are you with the completed service?')+
                starInput('timeliness_rating','Timeliness','How satisfied are you with the timing and attendance?')+
                starInput('overall_rating','Overall Experience','How would you rate the overall job experience?');

            renderWorkers(data.workers||[]);
            el('reviewContent').style.display='block';

        }).catch(function(error){
            showError(error.message);
        });
    }

    el('reviewForm').addEventListener('submit',function(e){
        e.preventDefault();

        if(!this.reportValidity()){
            notify('error','Please complete all required service ratings.');
            return;
        }

        var button=el('submitButton');
        button.disabled=true;
        button.textContent='Submitting...';

        var fd=new FormData();
        fd.append('action','save');
        fd.append('service_rating',document.querySelector('input[name="service_rating"]:checked').value);
        fd.append('timeliness_rating',document.querySelector('input[name="timeliness_rating"]:checked').value);
        fd.append('overall_rating',document.querySelector('input[name="overall_rating"]:checked').value);
        fd.append('review_text',el('reviewText').value.trim());

        var workerRatings=[];
        document.querySelectorAll('.cr-worker[data-worker-id]').forEach(function(card){
            var userId=Number(card.getAttribute('data-worker-id'));
            var rating=card.querySelector('input[name="worker_'+userId+'"]:checked');
            var comment=card.querySelector('.worker-comment');
            workerRatings.push({
                user_id:userId,
                overall_rating:rating?Number(rating.value):'',
                comments:comment?comment.value.trim():''
            });
        });

        fd.append('worker_ratings_json',JSON.stringify(workerRatings));

        request(fd).then(function(data){
            el('reviewContent').style.display='none';
            el('thanksCard').style.display='block';
            notify('success',data.message||'Thank you for your review.');
        }).catch(function(error){
            notify('error',error.message);
        }).finally(function(){
            button.disabled=false;
            button.textContent='Submit Review';
        });
    });

    load();
})();
</script>
</body>
</html>

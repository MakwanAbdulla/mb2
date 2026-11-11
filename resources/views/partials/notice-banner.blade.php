@php
    $typeIcons = [
        'info'    => 'info-circle-fill',
        'success' => 'check-circle-fill',
        'warning' => 'exclamation-triangle-fill',
        'error'   => 'x-circle-fill',
    ];
    $iconInner = [];
    foreach ($typeIcons as $type => $name) {
        $svgPath = public_path("images/icons/bootstrap/{$name}.svg");
        if (file_exists($svgPath)) {
            preg_match('/<svg[^>]*>(.*)<\/svg>/s', file_get_contents($svgPath), $m);
            $iconInner[$type] = $m[1] ?? '';
        } else {
            $iconInner[$type] = '';
        }
    }
    $typeColors = [
        'info'    => ['bg'=>'#172554','border'=>'#1e3a8a','barBg'=>'#1e3a8a','barFill'=>'#60a5fa','text'=>'#dbeafe','title'=>'#93c5fd','dotOn'=>'#60a5fa','dotOff'=>'#1e3a8a','icon'=>$typeIcons['info'],'iconInner'=>$iconInner['info']],
        'success' => ['bg'=>'#052e16','border'=>'#14532d','barBg'=>'#14532d','barFill'=>'#4ade80','text'=>'#dcfce7','title'=>'#86efac','dotOn'=>'#4ade80','dotOff'=>'#14532d','icon'=>$typeIcons['success'],'iconInner'=>$iconInner['success']],
        'warning' => ['bg'=>'#451a03','border'=>'#78350f','barBg'=>'#78350f','barFill'=>'#fbbf24','text'=>'#fef3c7','title'=>'#fcd34d','dotOn'=>'#fbbf24','dotOff'=>'#78350f','icon'=>$typeIcons['warning'],'iconInner'=>$iconInner['warning']],
        'error'   => ['bg'=>'#450a0a','border'=>'#7f1d1d','barBg'=>'#7f1d1d','barFill'=>'#f87171','text'=>'#fee2e2','title'=>'#fca5a5','dotOn'=>'#f87171','dotOff'=>'#7f1d1d','icon'=>$typeIcons['error'],'iconInner'=>$iconInner['error']],
    ];
    $notices = \App\Models\Content\Notice::active()->orderBy('starts_at')->get();
    if (! \Illuminate\Support\Facades\Auth::check()) {
        $notices = $notices->where('is_public', true)->values();
    }
    $first  = $notices->first();
    $firstC = $first ? ($typeColors[$first->type] ?? $typeColors['info']) : $typeColors['info'];
@endphp
@if ($notices->isNotEmpty())
<div id="layout-notice-card" class="relative overflow-hidden border-b"
     style="background-color:{{ $firstC['bg'] }};border-color:{{ $firstC['border'] }}">
    <div class="mx-auto max-w-7xl">
        <div id="layout-notice-content" class="flex items-center gap-2.5 px-4 py-2 sm:px-6 lg:px-8" style="transition:opacity 0.25s ease">
            <x-bootstrap-icon name="{{ $firstC['icon'] }}" class="h-5 w-5 shrink-0" style="color:{{ $firstC['barFill'] }}" />
            <span id="layout-notice-text" class="min-w-0 truncate text-sm" style="color:{{ $firstC['text'] }}">
                @if ($first->title)<span id="layout-notice-title" style="font-weight:600;color:{{ $firstC['title'] }}">{{ $first->title }}</span><span style="color:{{ $firstC['text'] }};opacity:.7"> — </span>@endif{{ $first->message }}
            </span>
            @if ($notices->count() > 1)
            <div class="ml-auto flex shrink-0 items-center gap-1.5">
                @foreach ($notices as $i => $n)
                    @php $nc = $typeColors[$n->type] ?? $typeColors['info']; @endphp
                    <svg class="layout-notice-dot shrink-0" width="14" height="14" viewBox="0 0 14 14">
                        <circle class="dot-bg" cx="7" cy="7" r="7" fill="{{ $i === 0 ? $nc['dotOn'] : $nc['dotOff'] }}" />
                        <circle class="dot-pie" cx="7" cy="7" r="3.5" fill="none"
                            stroke="{{ $nc['dotOff'] }}" stroke-width="7"
                            stroke-dasharray="0 21.991" transform="rotate(-90 7 7)" />
                    </svg>
                @endforeach
            </div>
            @endif
        </div>
    </div>
</div>

@if ($notices->count() > 1)
@php $noticesJson = $notices->map(fn($n) => ['title' => $n->title, 'message' => $n->message, 'duration' => $n->duration, 'type' => $n->type]); @endphp
<script>
(function () {
    const typeColors = @json($typeColors);
    const notices = @json($noticesJson);
    const CIRC    = 21.991;
    const card    = document.getElementById('layout-notice-card');
    const content = document.getElementById('layout-notice-content');
    const text    = document.getElementById('layout-notice-text');
    const dots    = Array.from(document.querySelectorAll('.layout-notice-dot'));
    const icon    = content ? content.querySelector('svg:not(.layout-notice-dot)') : null;
    const STORE_KEY = 'notice_state';
    function loadState() {
        try {
            const s = JSON.parse(sessionStorage.getItem(STORE_KEY));
            if (s && s.index < notices.length) return s;
        } catch {}
        return null;
    }
    function saveState(index, startedAt) {
        try { sessionStorage.setItem(STORE_KEY, JSON.stringify({ index, startedAt })); } catch {}
    }

    const saved = loadState();
    let current = saved ? saved.index : 0;
    let initialElapsed = 0;
    if (saved) {
        const sinceStart = (Date.now() - saved.startedAt) / 1000;
        const dur = notices[current].duration;
        if (sinceStart >= dur) {
            current = (saved.index + 1) % notices.length;
            initialElapsed = 0;
        } else {
            initialElapsed = sinceStart * 1000;
        }
    }

    let raf = null, startTime = null, duration = 0;
    let paused = false, elapsed = initialElapsed;

    function esc(s) {
        return (s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function applyColors(c) {
        if (card) { card.style.backgroundColor = c.bg; card.style.borderColor = c.border; }
        if (icon) {
            icon.style.color = c.barFill;
            if (c.iconInner) icon.innerHTML = c.iconInner;
        }
        if (text) text.style.color = c.text;
    }
    function renderText(n) {
        const c = typeColors[n.type] ?? typeColors['info'];
        let html = '';
        if (n.title) html += `<span style="font-weight:600;color:${c.title}">${esc(n.title)}</span><span style="color:${c.text};opacity:.7"> — </span>`;
        html += esc(n.message);
        text.innerHTML = html;
        applyColors(c);
    }
    function setPie(dot, pct) {
        const pie = dot.querySelector('.dot-pie');
        if (pie) pie.setAttribute('stroke-dasharray', (pct / 100 * CIRC) + ' ' + CIRC);
    }
    function setDot(i) {
        dots.forEach((dot, j) => {
            const nc = typeColors[notices[j].type] ?? typeColors['info'];
            const bg = dot.querySelector('.dot-bg');
            const pie = dot.querySelector('.dot-pie');
            if (j === i) {
                if (bg)  bg.setAttribute('fill', nc.dotOn);
                if (pie) { pie.setAttribute('stroke', nc.dotOff); pie.setAttribute('stroke-dasharray', '0 ' + CIRC); }
            } else {
                if (bg)  bg.setAttribute('fill', nc.dotOff);
                if (pie) pie.setAttribute('stroke-dasharray', '0 ' + CIRC);
            }
        });
    }
    function tick(ts) {
        if (!startTime) startTime = ts;
        const pct = Math.min((elapsed + (ts - startTime)) / (duration * 1000) * 100, 100);
        setPie(dots[current], pct);
        if (pct < 100) { raf = requestAnimationFrame(tick); } else { advance(); }
    }
    function advance() {
        content.style.opacity = '0';
        setTimeout(() => {
            current = (current + 1) % notices.length;
            renderText(notices[current]);
            setDot(current);
            content.style.opacity = '1';
            startTimer();
        }, 250);
    }
    function startTimer(preserveElapsed) {
        if (raf) cancelAnimationFrame(raf);
        duration = notices[current].duration;
        startTime = null;
        if (!preserveElapsed) elapsed = 0;
        setPie(dots[current], elapsed / (duration * 1000) * 100);
        saveState(current, Date.now() - elapsed);
        raf = requestAnimationFrame(tick);
    }
    if (card) {
        card.addEventListener('mouseenter', () => {
            if (paused) return;
            paused = true;
            if (raf) cancelAnimationFrame(raf);
            if (startTime !== null) elapsed += performance.now() - startTime;
            startTime = null;
            if (text) { text.style.whiteSpace = 'normal'; text.style.overflow = 'visible'; }
        });
        card.addEventListener('mouseleave', () => {
            if (!paused) return;
            paused = false;
            startTime = null;
            raf = requestAnimationFrame(tick);
            if (text) { text.style.whiteSpace = ''; text.style.overflow = ''; }
        });
    }
    if (current !== 0 || initialElapsed > 0) {
        renderText(notices[current]);
        setDot(current);
    }
    startTimer(initialElapsed > 0);
})();
</script>
@endif
@endif

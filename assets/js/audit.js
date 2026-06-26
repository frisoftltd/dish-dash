/* Dish Dash — Audit Dashboard */
(function () {
    'use strict';

    const STATUS_ICONS = { green: '🟢', yellow: '🟡', orange: '🟠', red: '🔴' };

    // ── Init ──────────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('dd-run-audit').addEventListener('click', runAudit);
        document.getElementById('dd-copy-report').addEventListener('click', copyReport);
        document.getElementById('dd-export-text').addEventListener('click', exportText);
        loadManualChecks();
        bindManualChecks();
    });

    // ── Run audit ─────────────────────────────────────────────────────────────

    let lastResults = null;

    function runAudit() {
        const btn     = document.getElementById('dd-run-audit');
        const spinner = document.getElementById('dd-audit-spinner');
        btn.disabled  = true;
        spinner.style.display = 'inline-block';

        const fd = new FormData();
        fd.append('action', 'dd_run_audit');
        fd.append('nonce', DD_Audit.nonce);

        fetch(DD_Audit.ajax_url, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.success) { alert('Audit failed: ' + (data.data || 'Unknown error')); return; }
                lastResults = data.data;
                renderResults(data.data);
                document.getElementById('dd-copy-report').style.display = '';
                document.getElementById('dd-export-text').style.display = '';
            })
            .catch(err => alert('Request failed: ' + err))
            .finally(() => {
                btn.disabled = false;
                spinner.style.display = 'none';
            });
    }

    // ── Render ────────────────────────────────────────────────────────────────

    function renderResults(data) {
        renderSummary(data);
        const container = document.getElementById('dd-audit-results');
        container.innerHTML = '';
        Object.values(data.pillars).forEach(pillar => {
            container.appendChild(buildPillarCard(pillar));
        });
    }

    function renderSummary(data) {
        const summaryEl = document.getElementById('dd-audit-summary');
        const pillars   = Object.values(data.pillars);
        const totalPass = pillars.reduce((a, p) => a + p.passed, 0);
        const totalAll  = pillars.reduce((a, p) => a + p.total, 0);
        const overallPct = totalAll > 0 ? Math.round((totalPass / totalAll) * 100) : 0;

        let html = `<div class="dd-summary-badge">
            <span class="badge-icon">${overallPct >= 90 ? '🟢' : overallPct >= 70 ? '🟡' : overallPct >= 50 ? '🟠' : '🔴'}</span>
            <span class="badge-score">${overallPct}%</span>
            <span class="badge-label">Overall</span>
        </div>`;

        pillars.forEach(p => {
            html += `<div class="dd-summary-badge">
                <span class="badge-icon">${STATUS_ICONS[p.status]}</span>
                <span class="badge-score">${p.score}%</span>
                <span class="badge-label">${p.id}</span>
            </div>`;
        });

        html += `<div style="margin-left:auto;align-self:center;font-size:0.8rem;color:#888;">
            Ran at ${data.ran_at} · v${data.version}
        </div>`;

        summaryEl.innerHTML = html;
        summaryEl.style.display = 'flex';
    }

    function buildPillarCard(pillar) {
        const card = document.createElement('div');
        card.className = 'dd-pillar-card';
        card.dataset.status = pillar.status;

        const icon   = STATUS_ICONS[pillar.status];
        const header = document.createElement('div');
        header.className = 'dd-pillar-header';
        header.innerHTML = `
            <span class="dd-pillar-icon">${icon}</span>
            <span class="dd-pillar-name">${pillar.id}: ${pillar.name}</span>
            <span class="dd-pillar-score-text">${pillar.passed}/${pillar.total} checks passed</span>
            <span class="dd-pillar-arrow">▼</span>`;

        const body = document.createElement('div');
        body.className = 'dd-pillar-body';

        const ul = document.createElement('ul');
        ul.className = 'dd-check-list';

        pillar.checks.forEach(check => {
            const li = document.createElement('li');
            li.className = 'dd-check-item';
            li.innerHTML = `<div class="dd-check-label">
                <span class="dd-check-mark">${check.pass ? '✅' : '❌'}</span>
                <span>${escHtml(check.label)}</span>
            </div>`;
            if (check.detail) {
                const detail = document.createElement('div');
                detail.className = 'dd-check-detail';
                detail.textContent = check.detail;
                li.appendChild(detail);
            }
            ul.appendChild(li);
        });

        body.appendChild(ul);

        // Auto-expand failing pillars
        if (pillar.status !== 'green') {
            header.classList.add('is-open');
            body.classList.add('is-open');
        }

        header.addEventListener('click', () => {
            header.classList.toggle('is-open');
            body.classList.toggle('is-open');
        });

        card.appendChild(header);
        card.appendChild(body);
        return card;
    }

    // ── Copy / Export ─────────────────────────────────────────────────────────

    function buildTextReport(data) {
        const lines = [`Dish Dash Audit Report — v${data.version} — ${data.ran_at}`, ''];
        Object.values(data.pillars).forEach(p => {
            const icon = STATUS_ICONS[p.status];
            lines.push(`${icon} ${p.id}: ${p.name} — ${p.score}% (${p.passed}/${p.total})`);
            p.checks.forEach(c => {
                lines.push(`  ${c.pass ? '✅' : '❌'} ${c.label}`);
                if (!c.pass && c.detail) lines.push(`      ↳ ${c.detail}`);
            });
            lines.push('');
        });
        return lines.join('\n');
    }

    function copyReport() {
        if (!lastResults) return;
        navigator.clipboard.writeText(buildTextReport(lastResults))
            .then(() => {
                const btn = document.getElementById('dd-copy-report');
                btn.textContent = 'Copied!';
                setTimeout(() => { btn.textContent = 'Copy Report for Claude'; }, 2000);
            })
            .catch(() => alert('Copy failed — try Export to Text instead.'));
    }

    function exportText() {
        if (!lastResults) return;
        const text = buildTextReport(lastResults);
        const blob = new Blob([text], { type: 'text/plain' });
        const a    = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `dishdash-audit-v${lastResults.version}.txt`;
        a.click();
    }

    // ── Manual checks (localStorage) ─────────────────────────────────────────

    const MANUAL_KEY = 'dd_audit_manual_checks';

    function loadManualChecks() {
        let saved = {};
        try { saved = JSON.parse(localStorage.getItem(MANUAL_KEY) || '{}'); } catch (_) {}
        document.querySelectorAll('.dd-manual-checks input[type=checkbox]').forEach(cb => {
            if (saved[cb.dataset.key]) cb.checked = true;
        });
    }

    function bindManualChecks() {
        document.querySelectorAll('.dd-manual-checks input[type=checkbox]').forEach(cb => {
            cb.addEventListener('change', () => {
                let saved = {};
                try { saved = JSON.parse(localStorage.getItem(MANUAL_KEY) || '{}'); } catch (_) {}
                saved[cb.dataset.key] = cb.checked;
                localStorage.setItem(MANUAL_KEY, JSON.stringify(saved));
            });
        });
    }

    function escHtml(str) {
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
}());

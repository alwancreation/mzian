// Mzian.net — progressive enhancements. Every page works without JavaScript;
// these behaviours only make it faster and nicer (mobile menu, dialogs, chat, live status).

const ready = (fn) => (document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', fn) : fn());

function initToggles() {
    document.querySelectorAll('[data-toggle]').forEach((button) => {
        const target = document.getElementById(button.dataset.toggle);
        if (!target) return;
        button.addEventListener('click', () => {
            const hidden = target.classList.toggle('hidden');
            button.setAttribute('aria-expanded', String(!hidden));
        });
    });
}

function initDialogs() {
    document.querySelectorAll('[data-dialog-open]').forEach((button) => {
        button.addEventListener('click', () => document.getElementById(button.dataset.dialogOpen)?.showModal());
    });
    document.querySelectorAll('dialog [data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => button.closest('dialog')?.close());
    });
    document.querySelectorAll('dialog.modal').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
    });
}

function initConfirmations() {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) event.preventDefault();
        });
    });
}

function initAutoSubmit() {
    document.querySelectorAll('[data-autosubmit] input[type="radio"], select[data-autosubmit-select]').forEach((input) => {
        input.addEventListener('change', () => input.form?.requestSubmit());
    });
}

function initCopy() {
    document.querySelectorAll('[data-copy]').forEach((button) => {
        button.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(button.dataset.copy);
                const label = button.textContent;
                button.textContent = button.dataset.copiedLabel || '✓';
                setTimeout(() => (button.textContent = label), 1500);
            } catch {
                /* clipboard not available: ignore */
            }
        });
    });
}

function initPrint() {
    document.querySelectorAll('[data-print]').forEach((button) => button.addEventListener('click', () => window.print()));
}

function initFlashes() {
    document.querySelectorAll('[data-flash]').forEach((flash) => {
        setTimeout(() => flash.remove(), 8000);
    });
}

function appendChatMessage(container, role, content) {
    const bubble = document.createElement('div');
    bubble.className = role === 'user' ? 'chat-bubble-user' : 'chat-bubble-assistant';
    bubble.textContent = content; // textContent: never inject HTML coming from the network
    container.appendChild(bubble);
    container.scrollTop = container.scrollHeight;
}

function initChat() {
    const form = document.querySelector('form[data-chat-form]');
    if (!form) return;
    const container = document.getElementById(form.dataset.chatMessages);
    const textarea = form.querySelector('textarea');
    const submit = form.querySelector('button[type="submit"]');
    container.scrollTop = container.scrollHeight;

    textarea?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const content = textarea.value.trim();
        if (!content) return;
        appendChatMessage(container, 'user', content);
        const body = new FormData(form);
        textarea.value = '';
        submit.disabled = true;
        const typing = document.createElement('div');
        typing.className = 'chat-bubble-assistant opacity-60';
        typing.textContent = '…';
        container.appendChild(typing);
        try {
            const response = await fetch(form.action, { method: 'POST', body, headers: { Accept: 'application/json' } });
            const data = await response.json();
            typing.remove();
            if (!response.ok) throw new Error(data.error || 'error');
            appendChatMessage(container, 'assistant', data.reply);
            if (data.csrf) form.querySelector('input[name="_token"]').value = data.csrf;
            const summary = document.getElementById('chat-summary');
            if (summary && data.summaryHtmlUrl) {
                const html = await (await fetch(data.summaryHtmlUrl, { headers: { Accept: 'text/html' } })).text();
                summary.innerHTML = html; // server-rendered Twig fragment (auto-escaped)
            }
            document.querySelectorAll('[data-chat-ready]').forEach((el) => el.classList.toggle('hidden', !data.ready));
        } catch {
            typing.remove();
            appendChatMessage(container, 'assistant', form.dataset.errorMessage || 'Error');
        } finally {
            submit.disabled = false;
            textarea.focus();
        }
    });
}

function initStatusPolling() {
    document.querySelectorAll('[data-poll-url]').forEach((element) => {
        const url = element.dataset.pollUrl;
        const interval = Number(element.dataset.pollInterval || 4000);
        let current = element.dataset.pollStatus;
        const tick = async () => {
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!response.ok) return;
                const data = await response.json();
                if (data.status !== current || String(data.progress) !== element.dataset.pollProgress) {
                    current = data.status;
                    window.location.reload();
                    return;
                }
                if (!data.terminal) setTimeout(tick, interval);
            } catch {
                setTimeout(tick, interval * 2);
            }
        };
        setTimeout(tick, interval);
    });
}

ready(() => {
    initToggles();
    initDialogs();
    initConfirmations();
    initAutoSubmit();
    initCopy();
    initPrint();
    initFlashes();
    initChat();
    initStatusPolling();
});
